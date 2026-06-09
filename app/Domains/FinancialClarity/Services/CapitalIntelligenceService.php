<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\FinancialSnapshot;

class CapitalIntelligenceService
{
    public function __construct(private readonly CashIntelligenceService $cashIntelligence, private readonly BusinessHealthSnapshotService $snapshots)
    {
    }

    public function forCurrentMonth(Business $business): array
    {
        $cash = $this->cashIntelligence->forCurrentMonth($business);
        $snapshot = $this->snapshots->currentMonthSummary($business);

        $materialEntries = MaterialLedgerEntry::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        $materialSpend = (float) (clone $materialEntries)->where('entry_type', 'purchase')->sum('total_cost');
        $materialConsumption = (float) (clone $materialEntries)->where('entry_type', 'consumption')->sum('total_cost');
        $materialWaste = (float) (clone $materialEntries)->where('entry_type', 'waste')->sum('total_cost');
        $materialAdjustments = (float) (clone $materialEntries)->where('entry_type', 'adjustment')->sum('total_cost');
        $materialCount = (clone $materialEntries)->count();

        $productionEntries = ProductionEntry::query()
            ->where('business_id', $business->id)
            ->whereBetween('produced_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        $productionCommitment = (float) (clone $productionEntries)->sum('estimated_total_cost');
        $productionPendingPay = (float) (clone $productionEntries)
            ->where('payment_status', 'pending')
            ->sum('net_payable');
        $productionCount = (clone $productionEntries)->count();

        $cashAfterObligations = (float) ($cash['net_movement'] ?? 0) - (float) ($cash['total_obligations'] ?? 0);
        $capitalCommitted = (float) ($cash['total_obligations'] ?? 0) + $materialSpend + $productionCommitment;
        $valueSignal = (float) ($snapshot['estimated_profit'] ?? 0) + (float) ($cash['net_movement'] ?? 0) - $capitalCommitted;
        $flowPressure = $materialSpend + $productionCommitment > 0
            ? (($materialConsumption + $materialWaste) / max($materialSpend + $productionCommitment, 1)) * 100
            : 0.0;

        $headline = match (true) {
            ! $business->supportsCapitalIntelligence() => 'Capital intelligence is not activated for this business yet.',
            $capitalCommitted > 0 && $cashAfterObligations < 0 => 'Capital is committed faster than cash is returning.',
            $capitalCommitted > 0 => 'Capital is moving through obligations, material spend, and production.',
            default => 'Capital pressure is light in the current month.',
        };

        return [
            'period_label' => now()->format('F Y'),
            'activated' => $business->supportsCapitalIntelligence(),
            'headline' => $headline,
            'confidence' => $materialCount > 0 || $productionCount > 0 ? 'High' : 'Medium',
            'cash_after_obligations_proxy' => $cashAfterObligations,
            'capital_committed' => $capitalCommitted,
            'material_spend' => $materialSpend,
            'material_consumption' => $materialConsumption,
            'material_waste' => $materialWaste,
            'material_adjustments' => $materialAdjustments,
            'production_commitment' => $productionCommitment,
            'production_pending_pay' => $productionPendingPay,
            'value_signal' => $valueSignal,
            'flow_pressure_percent' => round($flowPressure, 2),
            'signals' => [
                [
                    'label' => 'Material spend',
                    'value' => $materialSpend,
                ],
                [
                    'label' => 'Production commitment',
                    'value' => $productionCommitment,
                ],
                [
                    'label' => 'Cash after obligations proxy',
                    'value' => $cashAfterObligations,
                ],
                [
                    'label' => 'Value signal',
                    'value' => $valueSignal,
                ],
            ],
            'actions' => array_values(array_filter([
                $capitalCommitted > 0 ? 'Review unsettled obligations before adding more spend.' : null,
                $materialSpend > $materialConsumption ? 'Slow material purchases until consumption catches up.' : null,
                $productionPendingPay > 0 ? 'Clear pending production pay to keep labor pressure visible.' : null,
                $flowPressure > 60 ? 'Check whether production and material flow are keeping up with demand.' : null,
                $cashAfterObligations < 0 ? 'Protect cash until the committed capital settles down.' : null,
            ])),
        ];
    }
}

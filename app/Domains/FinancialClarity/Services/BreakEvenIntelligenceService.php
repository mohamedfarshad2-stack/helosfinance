<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CostAssumption;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use Illuminate\Support\Collection;

class BreakEvenIntelligenceService
{
    public function __construct(
        private readonly BusinessHealthSnapshotService $snapshots,
        private readonly CashIntelligenceService $cashIntelligence,
        private readonly CapitalIntelligenceService $capitalIntelligence,
        private readonly InventoryIntelligenceService $inventoryIntelligence,
    ) {
    }

    public function forCurrentMonth(Business $business): array
    {
        $summary = $this->snapshots->currentMonthSummary($business);
        $metrics = $summary['metrics'] ?? [];
        $events = $this->currentMonthEvents($business);

        $fixedCosts = $this->buildFixedCosts($business);
        $variableCosts = $this->buildVariableCosts($business, $events);
        $contribution = (float) ($summary['revenue_total'] ?? 0) - (float) ($variableCosts['total'] ?? 0);
        $deliveredOrders = (int) ($metrics['order_counts']['delivered'] ?? 0);
        $deliveredUnits = max((int) $events
            ->where('event_type', OperationalEvent::ORDER_DELIVERED)
            ->sum(fn (OperationalEvent $event): int => max((int) ($event->quantity ?? 1), 1)), 0);

        $contributionMargin = (float) ($summary['revenue_total'] ?? 0) > 0
            ? $contribution / max((float) ($summary['revenue_total'] ?? 0), 1)
            : 0.0;
        $averageContributionPerOrder = $deliveredOrders > 0 ? $contribution / $deliveredOrders : 0.0;
        $averageContributionPerUnit = $deliveredUnits > 0 ? $contribution / $deliveredUnits : 0.0;

        $breakEvenRevenue = $fixedCosts['total'] > 0 && $contributionMargin > 0
            ? $fixedCosts['total'] / $contributionMargin
            : ($fixedCosts['total'] <= 0 ? 0.0 : null);

        $breakEvenDeliveries = $fixedCosts['total'] > 0 && $averageContributionPerOrder > 0
            ? $fixedCosts['total'] / $averageContributionPerOrder
            : ($fixedCosts['total'] <= 0 ? 0.0 : null);

        $breakEvenOrders = $breakEvenDeliveries;
        $breakEvenUnits = $fixedCosts['total'] > 0 && $averageContributionPerUnit > 0
            ? $fixedCosts['total'] / $averageContributionPerUnit
            : ($fixedCosts['total'] <= 0 ? 0.0 : null);

        $contributionCoverage = $fixedCosts['total'] > 0
            ? ($contribution / $fixedCosts['total']) * 100
            : 100.0;

        $revenueProgress = $breakEvenRevenue > 0
            ? (((float) ($summary['revenue_total'] ?? 0) / $breakEvenRevenue) * 100)
            : ($fixedCosts['total'] <= 0 ? 100.0 : 0.0);

        $deliveryProgress = $breakEvenDeliveries > 0
            ? ($deliveredOrders / $breakEvenDeliveries) * 100
            : ($fixedCosts['total'] <= 0 ? 100.0 : 0.0);

        $unitProgress = $breakEvenUnits > 0
            ? ($deliveredUnits / $breakEvenUnits) * 100
            : ($fixedCosts['total'] <= 0 ? 100.0 : 0.0);

        $remainingRevenue = $breakEvenRevenue !== null ? max($breakEvenRevenue - (float) ($summary['revenue_total'] ?? 0), 0) : null;
        $remainingDeliveries = $breakEvenDeliveries !== null ? max((int) ceil($breakEvenDeliveries - $deliveredOrders), 0) : null;
        $remainingOrders = $breakEvenOrders !== null ? max((int) ceil($breakEvenOrders - $deliveredOrders), 0) : null;
        $remainingUnits = $breakEvenUnits !== null ? max((int) ceil($breakEvenUnits - $deliveredUnits), 0) : null;

        $skuAndStreamBreakdowns = $this->buildGroupBreakdowns($business, $events, (float) ($variableCosts['shared_variable_expenses'] ?? 0));

        $pressure = $this->buildPressureAnalysis($business, $fixedCosts, $variableCosts, $skuAndStreamBreakdowns);
        $firstObstacle = $pressure['top_obstacles'][0] ?? null;
        $remainingDeliveryText = $remainingDeliveries === null
            ? 'Not enough data yet'
            : ($remainingDeliveries === 0 ? 'Covered' : $remainingDeliveries.' more deliveries');
        $remainingRevenueText = $remainingRevenue === null
            ? 'Not enough data yet'
            : 'LKR '.number_format($remainingRevenue, 2);

        $headline = match (true) {
            ($breakEvenRevenue !== null && $breakEvenDeliveries !== null && $remainingDeliveries === 0) => 'The business is already covering the month at the current mix.',
            ($breakEvenRevenue !== null && $remainingDeliveries !== null && $remainingDeliveries > 0) => 'The business still needs '.$remainingDeliveries.' more deliveries to cover the month.',
            default => 'Current contribution is not yet strong enough to calculate a clean break-even path.',
        };

        return [
            'activated' => true,
            'headline' => $headline,
            'summary' => array_values(array_filter([
                'Monthly fixed costs: LKR '.number_format($fixedCosts['total'], 2),
                'Money still needed on the current mix: '.$remainingRevenueText,
                'Deliveries still needed on the current mix: '.$remainingDeliveryText,
                'Current contribution coverage: '.number_format($contributionCoverage, 2).'% of fixed costs.',
            ])),
            'confidence' => $events->isNotEmpty() || ($fixedCosts['total'] > 0) ? 'High' : 'Medium',
            'fixed_costs' => $fixedCosts,
            'variable_costs' => $variableCosts,
            'cards' => [
                [
                    'title' => 'Money Needed To Cover The Month',
                    'value' => 'LKR '.number_format($fixedCosts['total'], 2),
                    'status' => $fixedCosts['total'] > 0 ? 'Spoken for' : 'Light',
                    'tone' => $fixedCosts['total'] > 0 ? 'warning' : 'success',
                    'note' => 'Salaries, rent, utilities, and other fixed obligations set the bar.',
                ],
                [
                    'title' => 'Deliveries Needed To Cover The Month',
                    'value' => $breakEvenDeliveries === null ? 'N/A' : number_format($breakEvenDeliveries, 2),
                    'status' => $remainingDeliveries === 0 ? 'Covered' : ($remainingDeliveries === null ? 'Not ready' : 'Still needed'),
                    'tone' => $remainingDeliveries === 0 ? 'success' : 'warning',
                    'note' => $remainingDeliveries === null ? 'Not enough contribution data yet.' : ($remainingDeliveries === 0 ? 'No more deliveries needed at the current mix.' : $remainingDeliveryText),
                ],
                [
                    'title' => 'Revenue Needed To Cover The Month',
                    'value' => $breakEvenRevenue === null ? 'N/A' : 'LKR '.number_format($breakEvenRevenue, 2),
                    'status' => $remainingRevenue === null ? 'Not ready' : ($remainingRevenue <= 0 ? 'Covered' : 'Still needed'),
                    'tone' => $remainingRevenue === null ? 'warning' : ($remainingRevenue <= 0 ? 'success' : 'info'),
                    'note' => $remainingRevenue === null ? 'Break-even revenue is not clear yet.' : ($remainingRevenue <= 0 ? 'The month is already covered.' : $remainingRevenueText.' still needed.'),
                ],
                [
                    'title' => 'Current Progress',
                    'value' => number_format(max($contributionCoverage, 0), 2).'%',
                    'status' => $contributionCoverage >= 100 ? 'Covered' : 'In progress',
                    'tone' => $contributionCoverage >= 100 ? 'success' : 'info',
                    'note' => 'Current contribution compared with fixed costs.',
                ],
                [
                    'title' => 'Still Needed',
                    'value' => $remainingDeliveries === null ? 'N/A' : ($remainingDeliveries <= 0 ? '0 deliveries' : $remainingDeliveries.' deliveries'),
                    'status' => $remainingDeliveries === null ? 'Not ready' : ($remainingDeliveries <= 0 ? 'Covered' : 'Pending'),
                    'tone' => $remainingDeliveries === null ? 'warning' : ($remainingDeliveries <= 0 ? 'success' : 'warning'),
                    'note' => $remainingRevenue === null ? 'Break-even path is not ready yet.' : 'Revenue still needed: '.($remainingRevenue <= 0 ? 'LKR 0.00' : 'LKR '.number_format($remainingRevenue, 2)),
                ],
                [
                    'title' => 'What Is Making It Harder',
                    'value' => $firstObstacle['label'] ?? 'Nothing major',
                    'status' => $firstObstacle ? 'Watch' : 'Calm',
                    'tone' => $firstObstacle ? 'warning' : 'success',
                    'note' => $firstObstacle ? 'LKR '.number_format((float) ($firstObstacle['amount'] ?? 0), 2) : 'No major break-even pressure is showing yet.',
                ],
            ],
            'contribution' => [
                'total' => round($contribution, 2),
                'margin_percent' => round($contributionMargin * 100, 2),
                'per_delivered_order' => round($averageContributionPerOrder, 2),
                'per_unit' => round($averageContributionPerUnit, 2),
                'top_helping_skus' => $skuAndStreamBreakdowns['top_helping_skus'],
                'top_slowing_skus' => $skuAndStreamBreakdowns['top_slowing_skus'],
                'revenue_streams' => $skuAndStreamBreakdowns['revenue_streams'],
            ],
            'break_even' => [
                'revenue' => $breakEvenRevenue === null ? null : round($breakEvenRevenue, 2),
                'deliveries' => $breakEvenDeliveries === null ? null : round($breakEvenDeliveries, 2),
                'orders' => $breakEvenOrders === null ? null : round($breakEvenOrders, 2),
                'units' => $breakEvenUnits === null ? null : round($breakEvenUnits, 2),
                'reachable' => $breakEvenRevenue !== null && $breakEvenDeliveries !== null && $breakEvenUnits !== null,
            ],
            'progress' => [
                'current_revenue' => round((float) ($summary['revenue_total'] ?? 0), 2),
                'current_deliveries' => $deliveredOrders,
                'current_units' => $deliveredUnits,
                'coverage_percent' => round($contributionCoverage, 2),
                'revenue_percent' => round($revenueProgress, 2),
                'deliveries_percent' => round($deliveryProgress, 2),
                'orders_percent' => round($deliveryProgress, 2),
                'units_percent' => round($unitProgress, 2),
                'remaining_revenue' => $remainingRevenue === null ? null : round($remainingRevenue, 2),
                'remaining_deliveries' => $remainingDeliveries,
                'remaining_orders' => $remainingOrders,
                'remaining_units' => $remainingUnits,
            ],
            'pressure' => $pressure,
            'actions' => array_values(array_filter([
                ($pressure['top_obstacles'][0]['label'] ?? null) ? 'Start with '.$pressure['top_obstacles'][0]['label'].'.' : null,
                ($pressure['top_obstacles'][1]['label'] ?? null) ? 'Then reduce '.$pressure['top_obstacles'][1]['label'].'.' : null,
                ! empty($skuAndStreamBreakdowns['top_helping_skus'][0]['name'] ?? null) ? 'Keep the strongest product mix moving first.' : null,
            ])),
        ];
    }

    private function currentMonthEvents(Business $business): Collection
    {
        return OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->get();
    }

    private function buildFixedCosts(Business $business): array
    {
        $salaryPressure = (float) Employee::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->sum('monthly_salary');

        $expenseQuery = Expense::query()
            ->where('business_id', $business->id)
            ->where(function ($query): void {
                $query->whereBetween('spent_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                    ->orWhere(function ($query): void {
                        $query->where('recurring', true)
                            ->where('expense_type', 'fixed')
                            ->whereDate('spent_on', '<=', now()->endOfMonth()->toDateString());
                    });
            })
            ->where('expense_type', 'fixed');

        $fixedExpenses = $expenseQuery->get();
        $expenseTotal = (float) $fixedExpenses->sum('amount');

        $bucketTotals = [
            'rent' => 0.0,
            'utilities' => 0.0,
            'recurring' => 0.0,
            'other_fixed_obligations' => 0.0,
        ];

        $topRows = $fixedExpenses
            ->sortByDesc('amount')
            ->take(5)
            ->map(fn (Expense $expense): array => [
                'label' => $expense->suggested_key ?: $expense->category ?: 'Fixed cost',
                'amount' => (float) $expense->amount,
                'note' => trim(($expense->category ?: 'Fixed cost').' '.($expense->description ?: '')),
            ])
            ->values()
            ->all();

        foreach ($fixedExpenses as $expense) {
            $bucket = $this->fixedExpenseBucket($expense);
            $bucketTotals[$bucket] = ($bucketTotals[$bucket] ?? 0) + (float) $expense->amount;
        }

        $total = $salaryPressure + $expenseTotal;

        return [
            'total' => round($total, 2),
            'salary' => round($salaryPressure, 2),
            'rent' => round($bucketTotals['rent'], 2),
            'utilities' => round($bucketTotals['utilities'], 2),
            'recurring' => round($bucketTotals['recurring'], 2),
            'other_fixed_obligations' => round($bucketTotals['other_fixed_obligations'], 2),
            'top_rows' => $topRows,
        ];
    }

    private function buildVariableCosts(Business $business, Collection $events): array
    {
        $sharedVariableExpenses = (float) Expense::query()
            ->where('business_id', $business->id)
            ->whereBetween('spent_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->where('expense_type', 'variable')
            ->sum('amount');

        $totals = [
            'product_cost' => 0.0,
            'production_cost' => 0.0,
            'packaging_cost' => 0.0,
            'courier_cost' => 0.0,
            'return_cost' => 0.0,
            'resend_cost' => 0.0,
            'other_order_costs' => 0.0,
            'recovery' => 0.0,
        ];

        foreach ($events as $event) {
            $components = $this->eventVariableComponents($business, $event);

            foreach ($totals as $key => $value) {
                $totals[$key] += (float) ($components[$key] ?? 0);
            }
        }

        $eventVariableCosts = array_sum($totals) - $totals['recovery'];

        return [
            'product_cost' => round($totals['product_cost'], 2),
            'production_cost' => round($totals['production_cost'], 2),
            'packaging_cost' => round($totals['packaging_cost'], 2),
            'courier_cost' => round($totals['courier_cost'], 2),
            'return_cost' => round($totals['return_cost'], 2),
            'resend_cost' => round($totals['resend_cost'], 2),
            'other_order_costs' => round($totals['other_order_costs'], 2),
            'recovery' => round($totals['recovery'], 2),
            'shared_variable_expenses' => round($sharedVariableExpenses, 2),
            'event_variable_costs' => round($eventVariableCosts, 2),
            'total' => round($eventVariableCosts + $sharedVariableExpenses, 2),
        ];
    }

    private function buildGroupBreakdowns(Business $business, Collection $events, float $sharedVariableExpenses): array
    {
        $skuGroups = [];
        $streamGroups = [];
        $revenueTotal = max((float) $events->sum('revenue_amount'), 1.0);

        foreach ($events as $event) {
            $revenue = (float) $event->revenue_amount;
            $components = $this->eventVariableComponents($business, $event);
            $variableCost = array_sum($components) - (float) ($components['recovery'] ?? 0);
            $skuKey = $event->sku_id ? 'sku_'.$event->sku_id : 'sku_unknown';
            $skuName = $event->sku?->name ?? $event->sku?->code ?? 'Unknown SKU';
            $streamKey = $this->normalizeChannel((string) ($event->channel ?? $event->payload['channel'] ?? 'cod'));
            $streamLabel = $this->streamLabel($streamKey);

            $skuGroups[$skuKey] ??= [
                'name' => $skuName,
                'revenue' => 0.0,
                'variable_cost' => 0.0,
                'recovery' => 0.0,
                'deliveries' => 0,
            ];

            $streamGroups[$streamKey] ??= [
                'name' => $streamLabel,
                'revenue' => 0.0,
                'variable_cost' => 0.0,
                'recovery' => 0.0,
                'deliveries' => 0,
            ];

            $skuGroups[$skuKey]['revenue'] += $revenue;
            $skuGroups[$skuKey]['variable_cost'] += $variableCost;
            $skuGroups[$skuKey]['recovery'] += (float) ($components['recovery'] ?? 0);
            $skuGroups[$skuKey]['deliveries'] += $event->event_type === OperationalEvent::ORDER_DELIVERED ? max((int) ($event->quantity ?? 1), 1) : 0;

            $streamGroups[$streamKey]['revenue'] += $revenue;
            $streamGroups[$streamKey]['variable_cost'] += $variableCost;
            $streamGroups[$streamKey]['recovery'] += (float) ($components['recovery'] ?? 0);
            $streamGroups[$streamKey]['deliveries'] += $event->event_type === OperationalEvent::ORDER_DELIVERED ? max((int) ($event->quantity ?? 1), 1) : 0;
        }

        foreach ($skuGroups as &$group) {
            $share = $sharedVariableExpenses * (($group['revenue'] / $revenueTotal) ?? 0);
            $group['shared_variable_expenses'] = round($share, 2);
            $group['contribution'] = round($group['revenue'] - $group['variable_cost'] - $share, 2);
            $group['margin_percent'] = $group['revenue'] > 0 ? round((($group['contribution'] / $group['revenue']) * 100), 2) : 0.0;
        }
        unset($group);

        foreach ($streamGroups as &$group) {
            $share = $sharedVariableExpenses * (($group['revenue'] / $revenueTotal) ?? 0);
            $group['shared_variable_expenses'] = round($share, 2);
            $group['contribution'] = round($group['revenue'] - $group['variable_cost'] - $share, 2);
            $group['margin_percent'] = $group['revenue'] > 0 ? round((($group['contribution'] / $group['revenue']) * 100), 2) : 0.0;
        }
        unset($group);

        $skuList = collect($skuGroups)->values();
        $streamList = collect($streamGroups)->values();

        $topHelpingSkus = $skuList
            ->sortByDesc('contribution')
            ->take(3)
            ->map(fn (array $group): array => [
                'name' => $group['name'],
                'revenue' => round($group['revenue'], 2),
                'variable_cost' => round($group['variable_cost'] + $group['shared_variable_expenses'], 2),
                'contribution' => $group['contribution'],
                'margin_percent' => $group['margin_percent'],
                'deliveries' => (int) $group['deliveries'],
            ])
            ->values()
            ->all();

        $topSlowingSkus = $skuList
            ->sortBy('contribution')
            ->take(3)
            ->map(fn (array $group): array => [
                'name' => $group['name'],
                'revenue' => round($group['revenue'], 2),
                'variable_cost' => round($group['variable_cost'] + $group['shared_variable_expenses'], 2),
                'contribution' => $group['contribution'],
                'margin_percent' => $group['margin_percent'],
                'deliveries' => (int) $group['deliveries'],
            ])
            ->values()
            ->all();

        $revenueStreams = $streamList
            ->sortByDesc('contribution')
            ->map(fn (array $group): array => [
                'name' => $group['name'],
                'revenue' => round($group['revenue'], 2),
                'variable_cost' => round($group['variable_cost'] + $group['shared_variable_expenses'], 2),
                'contribution' => $group['contribution'],
                'margin_percent' => $group['margin_percent'],
                'deliveries' => (int) $group['deliveries'],
            ])
            ->values()
            ->all();

        return [
            'top_helping_skus' => $topHelpingSkus,
            'top_slowing_skus' => $topSlowingSkus,
            'revenue_streams' => $revenueStreams,
        ];
    }

    private function buildPressureAnalysis(Business $business, array $fixedCosts, array $variableCosts, array $breakdowns): array
    {
        $events = $this->currentMonthEvents($business);
        $cash = $this->cashIntelligence->forCurrentMonth($business);
        $capital = $this->capitalIntelligence->forCurrentMonth($business);
        $inventory = $this->inventoryIntelligence->forCurrentMonth($business);

        $obstacles = [];

        if ($fixedCosts['total'] > 0) {
            $obstacles[] = [
                'label' => 'Fixed monthly costs',
                'amount' => round($fixedCosts['total'], 2),
                'note' => 'Salaries, rent, utilities, and other fixed obligations set the month\'s starting line.',
            ];
        }

        if ($fixedCosts['salary'] > 0) {
            $obstacles[] = [
                'label' => 'Salary pressure',
                'amount' => round($fixedCosts['salary'], 2),
                'note' => 'Payroll is part of the month whether volume is strong or weak.',
            ];
        }

        $worstSku = collect($breakdowns['top_slowing_skus'] ?? [])
            ->sortBy('contribution')
            ->first();

        if ($worstSku && (float) ($worstSku['contribution'] ?? 0) < 0) {
            $obstacles[] = [
                'label' => 'Low contribution product: '.($worstSku['name'] ?? 'Unknown SKU'),
                'amount' => abs((float) $worstSku['contribution']),
                'note' => 'This SKU is pulling the average down instead of helping cover the month.',
            ];
        }

        if ($variableCosts['courier_cost'] > 0 || $variableCosts['return_cost'] > 0 || $variableCosts['resend_cost'] > 0) {
            $obstacles[] = [
                'label' => 'Courier and return pressure',
                'amount' => round($variableCosts['courier_cost'] + $variableCosts['return_cost'] + $variableCosts['resend_cost'], 2),
                'note' => 'Delivery, return, and resend costs all make break-even harder.',
            ];
        }

        if ($variableCosts['shared_variable_expenses'] > 0) {
            $obstacles[] = [
                'label' => 'Variable expense load',
                'amount' => round($variableCosts['shared_variable_expenses'], 2),
                'note' => 'Daily spending outside the order line still has to be covered.',
            ];
        }

        $stockPressure = max((float) ($inventory['flow_gap'] ?? 0), 0) + max((float) ($inventory['material_waste_total'] ?? 0), 0);

        if ($stockPressure > 0) {
            $obstacles[] = [
                'label' => 'Stock pressure',
                'amount' => round($stockPressure, 2),
                'note' => 'Money trapped in material flow and waste keeps the month from closing faster.',
            ];
        }

        $salaryObligation = (float) ($cash['salary_obligation'] ?? 0);
        if ($salaryObligation > 0 && ! collect($obstacles)->pluck('label')->contains('Salary pressure')) {
            $obstacles[] = [
                'label' => 'Salary obligation',
                'amount' => round($salaryObligation, 2),
                'note' => 'Payroll still needs to be covered before the month can feel safe.',
            ];
        }

        $obstacles = collect($obstacles)
            ->sortByDesc('amount')
            ->take(3)
            ->values()
            ->all();

        $positiveDrivers = collect(array_merge(
            $breakdowns['top_helping_skus'] ?? [],
            $breakdowns['revenue_streams'] ?? [],
        ))
            ->filter(fn (array $item): bool => (float) ($item['contribution'] ?? 0) > 0)
            ->sortByDesc('contribution')
            ->take(3)
            ->map(fn (array $item): array => [
                'label' => $item['name'] ?? 'Unknown',
                'amount' => round((float) ($item['contribution'] ?? 0), 2),
                'note' => 'This is helping the month move toward break-even.',
            ])
            ->values()
            ->all();

        $headline = match (true) {
            ! empty($obstacles) && ($obstacles[0]['label'] ?? '') === 'Fixed monthly costs' => 'Fixed monthly costs are setting the bar for break-even.',
            ! empty($obstacles) && str_contains((string) ($obstacles[0]['label'] ?? ''), 'Low contribution product') => 'One product is dragging the month down faster than the others are helping.',
            ! empty($obstacles) => 'Break-even is being held back by '.$obstacles[0]['label'].'.',
            default => 'Break-even pressure is light in the current month.',
        };

        return [
            'headline' => $headline,
            'top_obstacles' => $obstacles,
            'what_helps_most' => $positiveDrivers,
        ];
    }

    private function eventVariableComponents(Business $business, OperationalEvent $event): array
    {
        $quantity = max((int) ($event->quantity ?? 1), 1);
        $payloadEconomics = is_array($event->payload['economics'] ?? null) ? $event->payload['economics'] : [];
        $sku = $event->sku;

        return match ($event->event_type) {
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT => [
                'product_cost' => $sku ? (float) $sku->materialCostPerUnit() * $quantity : (float) ($event->direct_cost_amount ?? 0),
                'production_cost' => $sku ? ((float) $sku->laborCostPerUnit() + (float) $sku->finishing_cost) * $quantity : 0.0,
                'packaging_cost' => $sku ? (float) $sku->packaging_cost * $quantity : 0.0,
                'courier_cost' => (float) ($payloadEconomics['courier_amount'] ?? $this->assumption($business, ['delivery_fee'], 'Delivery cost', 0)) * $quantity,
                'return_cost' => 0.0,
                'resend_cost' => 0.0,
                'other_order_costs' => 0.0,
                'recovery' => 0.0,
            ],
            OperationalEvent::ORDER_RETURNED => [
                'product_cost' => 0.0,
                'production_cost' => 0.0,
                'packaging_cost' => 0.0,
                'courier_cost' => 0.0,
                'return_cost' => (
                    (float) ($payloadEconomics['return_courier_amount'] ?? $this->assumption($business, ['return_courier_fee', 'return_fee'], 'Return courier cost', 0))
                    + (float) ($payloadEconomics['return_packaging_amount'] ?? $this->assumption($business, ['return_packaging_fee'], 'Return packaging cost', 0))
                    + (float) ($payloadEconomics['damage_amount'] ?? $event->payload['damage_cost'] ?? 0)
                ) * $quantity,
                'resend_cost' => 0.0,
                'other_order_costs' => 0.0,
                'recovery' => (float) ($payloadEconomics['recovery_amount'] ?? $event->payload['recovery_amount'] ?? $this->restockRecovery($sku, $event->payload, $quantity)),
            ],
            OperationalEvent::ORDER_RESENT => [
                'product_cost' => 0.0,
                'production_cost' => 0.0,
                'packaging_cost' => 0.0,
                'courier_cost' => 0.0,
                'return_cost' => 0.0,
                'resend_cost' => (
                    (float) ($payloadEconomics['resend_courier_amount'] ?? $this->assumption($business, ['resend_courier_fee', 'resend_fee'], 'Resend courier cost', 0))
                    + (float) ($payloadEconomics['resend_packaging_amount'] ?? $this->assumption($business, ['resend_packaging_fee'], 'Resend packaging cost', 0))
                ) * $quantity,
                'other_order_costs' => 0.0,
                'recovery' => 0.0,
            ],
            OperationalEvent::FAKE_ORDER_DETECTED => [
                'product_cost' => 0.0,
                'production_cost' => 0.0,
                'packaging_cost' => 0.0,
                'courier_cost' => (
                    (float) ($payloadEconomics['forward_courier_amount'] ?? $this->assumption($business, ['delivery_fee'], 'Delivery cost', 0))
                    + (float) ($payloadEconomics['return_courier_amount'] ?? $this->assumption($business, ['return_courier_fee', 'return_fee'], 'Return courier cost', 0))
                    + (float) ($payloadEconomics['return_packaging_amount'] ?? $this->assumption($business, ['return_packaging_fee'], 'Return packaging cost', 0))
                ) * $quantity,
                'return_cost' => 0.0,
                'resend_cost' => 0.0,
                'other_order_costs' => (float) ($payloadEconomics['verification_amount'] ?? $this->assumption($business, 'verification_cost', 'Verification cost', 0))
                    + (float) ($payloadEconomics['handling_amount'] ?? $event->payload['handling_cost'] ?? 0),
                'recovery' => 0.0,
            ],
            OperationalEvent::ORDER_CREATED,
            OperationalEvent::ORDER_CONFIRMED => [
                'product_cost' => 0.0,
                'production_cost' => 0.0,
                'packaging_cost' => 0.0,
                'courier_cost' => 0.0,
                'return_cost' => 0.0,
                'resend_cost' => 0.0,
                'other_order_costs' => (float) ($payloadEconomics['verification_amount'] ?? $this->assumption($business, 'verification_cost', 'Verification cost', 0)),
                'recovery' => 0.0,
            ],
            default => [
                'product_cost' => 0.0,
                'production_cost' => 0.0,
                'packaging_cost' => 0.0,
                'courier_cost' => 0.0,
                'return_cost' => 0.0,
                'resend_cost' => 0.0,
                'other_order_costs' => (float) ($event->direct_cost_amount ?? 0) + (float) ($event->leakage_amount ?? 0),
                'recovery' => (float) ($event->recovery_amount ?? 0),
            ],
        };
    }

    private function fixedExpenseBucket(Expense $expense): string
    {
        $key = strtolower(trim((string) $expense->suggested_key));
        $category = strtolower(trim((string) $expense->category));
        $description = strtolower(trim((string) $expense->description));
        $haystack = trim($key.' '.$category.' '.$description);

        if ($this->containsAny($haystack, ['rent', 'warehouse', 'office', 'shop', 'premises'])) {
            return 'rent';
        }

        if ($this->containsAny($haystack, ['electric', 'internet', 'phone', 'utility', 'water'])) {
            return 'utilities';
        }

        if ($expense->recurring) {
            return 'recurring';
        }

        return 'other_fixed_obligations';
    }

    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeChannel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            'wholesale', 'cheque', 'credit' => 'wholesale',
            'service' => 'service',
            default => 'cod',
        };
    }

    private function streamLabel(string $channel): string
    {
        return match ($channel) {
            'wholesale' => 'Wholesale',
            'service' => 'Service',
            default => 'COD',
        };
    }

    private function assumption(Business $business, array|string $keys, string $label, float $fallback): float
    {
        $keys = (array) $keys;

        foreach ($keys as $key) {
            $assumption = CostAssumption::query()
                ->where('business_id', $business->id)
                ->where('key', $key)
                ->first();

            if ($assumption instanceof CostAssumption) {
                return (float) $assumption->amount;
            }
        }

        $primaryKey = $keys[0];

        $assumption = CostAssumption::query()->firstOrCreate(
            ['business_id' => $business->id, 'key' => $primaryKey],
            ['label' => $label, 'amount' => $fallback, 'behavior' => 'per_event']
        );

        return (float) $assumption->amount;
    }

    private function restockRecovery(?Sku $sku, array $payload, int $quantity): float
    {
        $restockable = in_array(strtolower((string) ($payload['restockable'] ?? $payload['return_stock'] ?? $payload['restock'] ?? 'false')), ['1', 'true', 'yes', 'restockable', 'stock'], true);

        if (! $restockable || ! $sku instanceof Sku) {
            return 0.0;
        }

        return (float) $sku->productionCostPerUnit() * $quantity;
    }
}

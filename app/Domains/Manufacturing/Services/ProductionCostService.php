<?php

namespace App\Domains\Manufacturing\Services;

use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\Sku;

class ProductionCostService
{
    public function record(Sku $sku, array $data): ProductionEntry
    {
        $quantity = max((int) $data['quantity_produced'], 1);
        $waste = max((int) ($data['waste_quantity'] ?? 0), 0);
        $payout = (float) ($data['employee_payout'] ?? ($sku->laborCostPerUnit() * $quantity));
        $advance = (float) ($data['advance_amount'] ?? 0);
        $deduction = (float) ($data['deduction_amount'] ?? 0);
        $netPayable = max($payout - $advance - $deduction, 0);
        $materialPerUnit = $sku->materialCostPerUnit();
        $estimatedTotal = ($sku->productionCostPerUnit() * $quantity) + ($materialPerUnit * $waste);

        $entry = ProductionEntry::query()->create([
            'business_id' => $sku->business_id,
            'sku_id' => $sku->id,
            'employee_name' => $data['employee_name'] ?? null,
            'quantity_produced' => $quantity,
            'waste_quantity' => $waste,
            'employee_payout' => $payout,
            'advance_amount' => $advance,
            'deduction_amount' => $deduction,
            'net_payable' => $netPayable,
            'payment_status' => $data['payment_status'] ?? 'pending',
            'paid_on' => $data['paid_on'] ?? null,
            'estimated_total_cost' => $estimatedTotal,
            'produced_on' => $data['produced_on'] ?? now()->toDateString(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $sku->business_id,
            'sku_id' => $sku->id,
            'source' => 'manufacturing',
            'event_type' => OperationalEvent::SKU_PRODUCED,
            'department' => 'Manufacturing',
            'quantity' => $quantity,
            'direct_cost_amount' => $estimatedTotal,
            'leakage_amount' => $materialPerUnit * $waste,
            'payload' => ['production_entry_id' => $entry->id],
            'occurred_at' => now(),
        ]);

        return $entry;
    }
}

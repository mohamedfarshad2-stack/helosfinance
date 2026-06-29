<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CostAssumption;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;

class OperationalImpactCalculator
{
    /**
     * Convert stock-app style payloads into HELOS money behavior.
     */
    public function calculate(Business $business, array $payload): array
    {
        $eventType = $payload['event_type'] ?? OperationalEvent::ORDER_CREATED;
        $quantity = max((int) ($payload['quantity'] ?? 1), 1);
        $sku = $this->findSku($business, $payload);
        $saleAmount = (float) ($payload['sale_amount'] ?? $payload['revenue_amount'] ?? 0);
        $skipProductCost = $this->bool($payload['skip_product_cost'] ?? $payload['resend_from_stock'] ?? false);
        $productCost = $skipProductCost ? 0.0 : ($sku ? $sku->productionCostPerUnit() * $quantity : (float) ($payload['cogs_amount'] ?? 0));

        $delivery = (float) ($payload['transport_cost_amount'] ?? $payload['delivery_amount'] ?? $payload['courier_amount'] ?? $this->assumption($business, ['delivery_fee'], 'Delivery cost', 0));
        $returnCourier = (float) ($payload['return_courier_amount'] ?? $payload['return_charge'] ?? $this->assumption($business, ['return_courier_fee', 'return_fee'], 'Return courier cost', 0));
        $returnPackaging = $this->assumption($business, ['return_packaging_fee'], 'Return packaging cost', 0);
        $resendCourier = (float) ($payload['resend_courier_amount'] ?? $payload['resend_charge'] ?? $this->assumption($business, ['resend_courier_fee', 'resend_fee'], 'Resend courier cost', 0));
        $resendPackaging = $this->assumption($business, ['resend_packaging_fee'], 'Resend packaging cost', 0);
        $verification = $this->assumption($business, 'verification_cost', 'Verification cost', 0);
        $restockable = $this->bool($payload['restockable'] ?? $payload['return_stock'] ?? $payload['restock'] ?? false);
        $restockRecovery = $restockable && $sku ? $productCost : 0.0;

        return match ($eventType) {
            OperationalEvent::ORDER_CREATED,
            OperationalEvent::ORDER_CONFIRMED => [
                'sku_id' => $sku?->id,
                'revenue_amount' => 0,
                'direct_cost_amount' => 0,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'economics' => [
                    'verification_amount' => $verification,
                ],
            ],
            OperationalEvent::TRACKING_NUMBER_ADDED => [
                'sku_id' => $sku?->id,
                'revenue_amount' => 0,
                'direct_cost_amount' => $productCost,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'economics' => [
                    'product_cost_amount' => $productCost,
                    'product_cost_skipped' => $skipProductCost,
                    'courier_amount' => 0.0,
                    'delivery_charge_pending' => $delivery,
                    'sale_amount' => $saleAmount,
                ],
            ],
            OperationalEvent::WHOLESALE_PARCEL_SENT => [
                'sku_id' => $sku?->id,
                'revenue_amount' => 0,
                'direct_cost_amount' => $productCost + $delivery,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'economics' => [
                    'product_cost_amount' => $productCost,
                    'product_cost_skipped' => $skipProductCost,
                    'courier_amount' => $delivery,
                    'sale_amount' => $saleAmount,
                ],
            ],
            OperationalEvent::ORDER_DELIVERED => [
                'sku_id' => $sku?->id,
                'revenue_amount' => $saleAmount,
                'direct_cost_amount' => $delivery,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'economics' => [
                    'sale_amount' => $saleAmount,
                    'courier_amount' => $delivery,
                ],
            ],
            OperationalEvent::ORDER_RETURNED => [
                'sku_id' => $sku?->id,
                'revenue_amount' => 0,
                'direct_cost_amount' => 0,
                'leakage_amount' => $returnCourier + $returnPackaging + (float) ($payload['damage_cost'] ?? 0),
                'recovery_amount' => (float) ($payload['recovery_amount'] ?? $restockRecovery),
                'economics' => [
                    'return_courier_amount' => $returnCourier,
                    'return_packaging_amount' => $returnPackaging,
                    'damage_amount' => (float) ($payload['damage_cost'] ?? 0),
                    'recovery_amount' => (float) ($payload['recovery_amount'] ?? $restockRecovery),
                ],
            ],
            OperationalEvent::ORDER_RESENT => [
                'sku_id' => $sku?->id,
                'revenue_amount' => 0,
                'direct_cost_amount' => $resendCourier + $resendPackaging,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'economics' => [
                    'resend_courier_amount' => $resendCourier,
                    'resend_packaging_amount' => $resendPackaging,
                ],
            ],
            OperationalEvent::FAKE_ORDER_DETECTED => [
                'sku_id' => $sku?->id,
                'revenue_amount' => 0,
                'direct_cost_amount' => $verification,
                'leakage_amount' => $delivery + $returnCourier + $returnPackaging + (float) ($payload['handling_cost'] ?? 0),
                'recovery_amount' => 0,
                'economics' => [
                    'verification_amount' => $verification,
                    'forward_courier_amount' => $delivery,
                    'return_courier_amount' => $returnCourier,
                    'return_packaging_amount' => $returnPackaging,
                    'handling_amount' => (float) ($payload['handling_cost'] ?? 0),
                ],
            ],
            default => [
                'sku_id' => $sku?->id,
                'revenue_amount' => $saleAmount,
                'direct_cost_amount' => (float) ($payload['direct_cost_amount'] ?? 0),
                'leakage_amount' => (float) ($payload['leakage_amount'] ?? 0),
                'recovery_amount' => (float) ($payload['recovery_amount'] ?? 0),
                'economics' => [],
            ],
        };
    }

    private function findSku(Business $business, array $payload): ?Sku
    {
        $code = $payload['sku_code'] ?? null;

        if (! $code) {
            return null;
        }

        return Sku::query()
            ->where('business_id', $business->id)
            ->where('code', $code)
            ->first();
    }

    /**
     * @param  array<int, string>|string  $keys
     */
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

    private function bool(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'restockable', 'stock'], true);
    }
}

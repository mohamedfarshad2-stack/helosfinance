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
        $selling = $this->sellingBreakdown($payload);
        $saleAmount = $selling['gross_customer_amount'];
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
                    'actual_courier_cost_amount' => 0.0,
                    'delivery_charge_pending' => $delivery,
                    ...$selling,
                    'delivery_charge_margin_amount' => round($selling['customer_delivery_charge_amount'] - $delivery, 2),
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
                    'actual_courier_cost_amount' => $delivery,
                    ...$selling,
                    'delivery_charge_margin_amount' => round($selling['customer_delivery_charge_amount'] - $delivery, 2),
                ],
            ],
            OperationalEvent::ORDER_DELIVERED => [
                'sku_id' => $sku?->id,
                'revenue_amount' => $saleAmount,
                'direct_cost_amount' => $delivery,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'economics' => [
                    'courier_amount' => $delivery,
                    'actual_courier_cost_amount' => $delivery,
                    ...$selling,
                    'delivery_charge_margin_amount' => round($selling['customer_delivery_charge_amount'] - $delivery, 2),
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
                    ...$selling,
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
                'economics' => $selling,
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

    /**
     * @return array{gross_customer_amount:float,product_selling_amount:float,customer_delivery_charge_amount:float,sale_amount:float}
     */
    private function sellingBreakdown(array $payload): array
    {
        $grossCustomerAmount = (float) (
            $payload['customer_total_amount']
            ?? $payload['total_customer_amount']
            ?? $payload['total_amount']
            ?? $payload['sale_amount']
            ?? $payload['amount']
            ?? $payload['revenue_amount']
            ?? 0
        );

        $productSellingAmount = (float) (
            $payload['product_sale_amount']
            ?? $payload['product_selling_amount']
            ?? $payload['marked_price']
            ?? $payload['item_amount']
            ?? 0
        );

        $customerDeliveryCharge = (float) (
            $payload['customer_delivery_charge']
            ?? $payload['customer_delivery_amount']
            ?? $payload['delivery_charge_collected']
            ?? 0
        );

        if ($grossCustomerAmount <= 0 && ($productSellingAmount > 0 || $customerDeliveryCharge > 0)) {
            $grossCustomerAmount = $productSellingAmount + $customerDeliveryCharge;
        }

        if ($productSellingAmount <= 0 && $grossCustomerAmount > 0 && $customerDeliveryCharge > 0) {
            $productSellingAmount = max($grossCustomerAmount - $customerDeliveryCharge, 0);
        }

        if ($customerDeliveryCharge <= 0 && $grossCustomerAmount > 0 && $productSellingAmount > 0) {
            $customerDeliveryCharge = max($grossCustomerAmount - $productSellingAmount, 0);
        }

        if ($productSellingAmount <= 0) {
            $productSellingAmount = $grossCustomerAmount;
        }

        return [
            'gross_customer_amount' => round($grossCustomerAmount, 2),
            'product_selling_amount' => round($productSellingAmount, 2),
            'customer_delivery_charge_amount' => round($customerDeliveryCharge, 2),
            'sale_amount' => round($grossCustomerAmount, 2),
        ];
    }
}

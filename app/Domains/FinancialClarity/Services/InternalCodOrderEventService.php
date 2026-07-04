<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\CodOrder;
use App\Domains\Shared\Models\OperationalEvent;

class InternalCodOrderEventService
{
    public function sync(CodOrder $order): void
    {
        if (! $order->business?->usesInternalCodOrders()) {
            return;
        }

        $eventType = $this->eventTypeForStatus($order->status);

        if ($eventType === null) {
            return;
        }

        $business = $order->business;
        $sku = $order->sku;
        $payload = [
            'manual_internal_cod' => true,
            'cod_order_id' => $order->id,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_alt_phone' => $order->customer_alt_phone,
            'address' => $order->address,
            'city' => $order->city,
            'district' => $order->district,
            'size' => $order->size,
            'order_source' => $order->orderSource?->name,
            'csr_employee' => $order->csrEmployee?->name,
            'call_attempts' => (int) $order->call_attempts,
            'confirmation_remark' => $order->confirmation_remark,
            'confirmation_reason' => $order->confirmation_reason,
            'delivery_instruction' => $order->delivery_instruction,
            'preferred_delivery_at' => $order->preferred_delivery_at?->toIso8601String(),
            'courier_name' => $order->courier_name,
            'tracking_number' => $order->tracking_number,
            'resend_from_stock' => (bool) $order->resend_from_stock,
            'skip_product_cost' => (bool) $order->resend_from_stock,
            'sale_amount' => (float) $order->sale_amount,
            'product_sale_amount' => (float) $order->sale_amount,
            'customer_delivery_charge' => (float) $order->delivery_charge,
            'customer_total_amount' => (float) $order->totalPrice(),
            'customer_paid_amount' => (float) $order->collected_amount,
            'courier_amount' => (float) $order->delivery_charge,
            'delivery_amount' => (float) $order->delivery_charge,
            'return_courier_amount' => (float) $order->return_charge,
            'resend_courier_amount' => (float) $order->resend_charge,
            'return_reason' => $order->return_reason,
            'resend_reason' => $order->resend_reason,
            'channel' => 'cod',
        ];

        $impact = app(OperationalImpactCalculator::class)->calculate($business, array_merge($payload, [
            'event_type' => $eventType,
            'sku_code' => $sku?->code,
            'quantity' => $order->quantity,
        ]));

        OperationalEvent::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'external_id' => $order->externalId(),
                'event_type' => $eventType,
            ],
            [
                'sku_id' => $impact['sku_id'] ?? $order->sku_id,
                'source' => 'helos_internal_cod',
                'channel' => 'cod',
                'department' => 'COD',
                'quantity' => max((int) $order->quantity, 1),
                'revenue_amount' => (float) ($impact['revenue_amount'] ?? 0),
                'direct_cost_amount' => (float) ($impact['direct_cost_amount'] ?? 0),
                'leakage_amount' => (float) ($impact['leakage_amount'] ?? 0),
                'recovery_amount' => (float) ($impact['recovery_amount'] ?? 0),
                'payload' => array_merge($payload, ['economics' => $impact['economics'] ?? []]),
                'occurred_at' => $this->occurredAt($order, $eventType),
            ]
        );
    }

    private function eventTypeForStatus(string $status): ?string
    {
        return match ($status) {
            CodOrder::STATUS_NEW => OperationalEvent::ORDER_CREATED,
            CodOrder::STATUS_CONFIRMED => OperationalEvent::ORDER_CONFIRMED,
            CodOrder::STATUS_DISPATCHED => OperationalEvent::TRACKING_NUMBER_ADDED,
            CodOrder::STATUS_COURIER_PENDING => null,
            CodOrder::STATUS_DELIVERED => OperationalEvent::ORDER_DELIVERED,
            CodOrder::STATUS_RETURNED => OperationalEvent::ORDER_RETURNED,
            CodOrder::STATUS_RESENT => OperationalEvent::ORDER_RESENT,
            CodOrder::STATUS_NO_ANSWER => null,
            CodOrder::STATUS_CANCELLED => null,
            default => null,
        };
    }

    private function occurredAt(CodOrder $order, string $eventType): string
    {
        $date = match ($eventType) {
            OperationalEvent::TRACKING_NUMBER_ADDED => $order->dispatched_on,
            OperationalEvent::ORDER_DELIVERED => $order->delivered_on,
            OperationalEvent::ORDER_RETURNED => $order->returned_on,
            default => $order->order_date,
        };

        return ($date ?? today())->toDateString().' '.now()->format('H:i:s');
    }
}

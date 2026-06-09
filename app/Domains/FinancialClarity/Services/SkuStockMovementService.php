<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\SkuStockMovement;

class SkuStockMovementService
{
    public function record(Business $business, OperationalEvent $event, array $payload): ?SkuStockMovement
    {
        if (! filled($event->sku_id)) {
            return null;
        }

        $movement = $this->movementForEvent($event->event_type, $payload);

        if ($movement === null) {
            return null;
        }

        return SkuStockMovement::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'operational_event_id' => $event->id,
                'movement_type' => $movement['movement_type'],
            ],
            [
                'sku_id' => $event->sku_id,
                'order_external_id' => $event->external_id ?? ($payload['external_id'] ?? null),
                'quantity' => (int) ($payload['quantity'] ?? 1),
                'quantity_delta' => $movement['quantity_delta'],
                'is_restockable' => $movement['is_restockable'],
                'note' => $movement['note'],
                'payload' => $payload,
                'occurred_at' => $event->occurred_at ?? now(),
            ]
        );
    }

    private function movementForEvent(string $eventType, array $payload): ?array
    {
        return match ($eventType) {
            OperationalEvent::TRACKING_NUMBER_ADDED => [
                'movement_type' => 'dispatch',
                'quantity_delta' => -1,
                'is_restockable' => false,
                'note' => 'Parcel left the stock room for delivery.',
            ],
            OperationalEvent::ORDER_RESENT => [
                'movement_type' => 'resend_dispatch',
                'quantity_delta' => -1,
                'is_restockable' => false,
                'note' => 'Replacement parcel left the stock room.',
            ],
            OperationalEvent::ORDER_RETURNED => $this->returnMovement($payload),
            default => null,
        };
    }

    private function returnMovement(array $payload): array
    {
        $restockable = $this->bool($payload['restockable'] ?? $payload['return_stock'] ?? $payload['restock'] ?? false);
        $quantity = max((int) ($payload['restocked_quantity'] ?? $payload['quantity'] ?? 1), 1);
        $movementType = $restockable ? 'return_restocked' : 'return_damaged';

        return [
            'movement_type' => $movementType,
            'quantity_delta' => $restockable ? $quantity : 0,
            'is_restockable' => $restockable,
            'note' => $restockable
                ? 'Returned parcel was received back into sellable stock.'
                : 'Returned parcel was not restocked and stays out of sellable stock.',
        ];
    }

    private function bool(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'restockable', 'stock'], true);
    }
}

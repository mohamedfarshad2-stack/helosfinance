<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OperationalEventRecalculator
{
    public function __construct(
        private readonly OperationalImpactCalculator $calculator,
        private readonly SkuStockMovementService $stockMovements,
    ) {
    }

    public function recalculateForSku(Sku $sku): int
    {
        $processed = 0;

        $this->baseQuery()
            ->where('business_id', $sku->business_id)
            ->where('sku_id', $sku->id)
            ->orderBy('id')
            ->chunkById(200, function (Collection $events) use ($sku, &$processed): void {
                foreach ($events as $event) {
                    $this->recalculateEvent($event, $sku);
                    $processed++;
                }
            });

        return $processed;
    }

    public function recalculateStaleProductCostEvents(): int
    {
        $processed = 0;

        $this->baseQuery()
            ->whereNotNull('sku_id')
            ->with('sku', 'business')
            ->orderBy('id')
            ->chunkById(200, function (Collection $events) use (&$processed): void {
                foreach ($events as $event) {
                    $sku = $event->sku;
                    $business = $event->business;

                    if (! $sku || ! $business) {
                        continue;
                    }

                    if (! $this->isStaleForSku($event, $sku)) {
                        continue;
                    }

                    $this->recalculateEvent($event, $sku, $business);
                    $processed++;
                }
            });

        return $processed;
    }

    public function recalculateSingleEvent(OperationalEvent $event, ?Sku $sku = null): void
    {
        $sku ??= $event->sku;

        if (! $sku) {
            return;
        }

        $this->recalculateEvent($event, $sku);
    }

    private function recalculateEvent(OperationalEvent $event, Sku $sku, ?Business $business = null): void
    {
        $business ??= $event->business ?: Business::query()->findOrFail($event->business_id);
        $payload = $this->payloadForRecalculation($event, $sku);
        $impact = $this->calculator->calculate($business, $payload);

        $event->update([
            'sku_id' => $impact['sku_id'] ?: $sku->id,
            'channel' => $payload['channel'] ?? $event->channel,
            'quantity' => $payload['quantity'] ?? $event->quantity,
            'payload' => array_merge($payload, ['economics' => $impact['economics'] ?? []]),
            'revenue_amount' => $impact['revenue_amount'] ?? 0,
            'direct_cost_amount' => $impact['direct_cost_amount'] ?? 0,
            'leakage_amount' => $impact['leakage_amount'] ?? 0,
            'recovery_amount' => $impact['recovery_amount'] ?? 0,
        ]);

        $event->refresh();
        $this->stockMovements->record($business, $event, $event->payload ?? []);
    }

    private function payloadForRecalculation(OperationalEvent $event, Sku $sku): array
    {
        $payload = $event->payload ?? [];
        $economics = is_array($payload['economics'] ?? null) ? $payload['economics'] : [];

        $payload = array_merge($payload, [
            'event_type' => $event->event_type,
            'external_id' => $event->external_id ?: ($payload['external_id'] ?? null),
            'sku_code' => $sku->code,
            'sku_name' => $sku->name,
            'quantity' => max((int) ($event->quantity ?: ($payload['quantity'] ?? 1)), 1),
            'channel' => $event->channel ?: ($payload['channel'] ?? 'cod'),
        ]);

        $payload = $this->preserveExistingAmounts($payload, $economics);

        return $payload;
    }

    private function preserveExistingAmounts(array $payload, array $economics): array
    {
        $preservedMap = [
            'transport_cost_amount' => 'actual_courier_cost_amount',
            'return_courier_amount' => 'return_courier_amount',
            'return_packaging_amount' => 'return_packaging_amount',
            'resend_courier_amount' => 'resend_courier_amount',
            'resend_packaging_amount' => 'resend_packaging_amount',
            'verification_amount' => 'verification_amount',
        ];

        foreach ($preservedMap as $payloadKey => $economicsKey) {
            if (filled($payload[$payloadKey] ?? null)) {
                continue;
            }

            if (! array_key_exists($economicsKey, $economics)) {
                continue;
            }

            $payload[$payloadKey] = (float) $economics[$economicsKey];
        }

        return $payload;
    }

    private function isStaleForSku(OperationalEvent $event, Sku $sku): bool
    {
        $productCost = $sku->productionCostPerUnit() * max((int) ($event->quantity ?? 1), 1);
        $payload = $event->payload ?? [];
        $economics = is_array($payload['economics'] ?? null) ? $payload['economics'] : [];

        if ($productCost <= 0) {
            return false;
        }

        return match ($event->event_type) {
            OperationalEvent::TRACKING_NUMBER_ADDED => (float) ($economics['production_cost_reference_amount'] ?? 0) <= 0,
            OperationalEvent::WHOLESALE_PARCEL_SENT => (float) ($economics['production_cost_reference_amount'] ?? 0) <= 0,
            OperationalEvent::ORDER_RETURNED => $this->isRestockable($payload)
                && (float) ($event->recovery_amount ?? 0) <= 0,
            default => false,
        };
    }

    private function isRestockable(array $payload): bool
    {
        return in_array(strtolower((string) ($payload['restockable'] ?? $payload['return_stock'] ?? $payload['restock'] ?? '')), ['1', 'true', 'yes', 'restockable', 'stock'], true);
    }

    private function baseQuery(): Builder
    {
        return OperationalEvent::query()
            ->whereIn('event_type', [
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_RETURNED,
            ]);
    }
}

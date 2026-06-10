<?php

namespace App\Filament\Resources\OperationalEventResource\Pages;

use App\Domains\FinancialClarity\Services\OperationalImpactCalculator;
use App\Domains\FinancialClarity\Services\SkuStockMovementService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\OperationalEventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOperationalEvent extends CreateRecord
{
    protected static string $resource = OperationalEventResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $expectedSaleAmount = (float) ($data['expected_sale_amount'] ?? 0);
        $transportCostAmount = (float) ($data['transport_cost_amount'] ?? 0);

        unset($data['expected_sale_amount'], $data['transport_cost_amount']);

        $data['source'] = 'manual';
        $data['payload'] = array_filter([
            'sale_amount' => $expectedSaleAmount > 0 ? $expectedSaleAmount : null,
            'transport_cost_amount' => $transportCostAmount > 0 ? $transportCostAmount : null,
            'channel' => $data['channel'] ?? null,
            'manual_entry' => true,
        ], fn ($value): bool => $value !== null);

        if (($data['event_type'] ?? null) === OperationalEvent::ORDER_DELIVERED && (float) ($data['revenue_amount'] ?? 0) <= 0 && $expectedSaleAmount > 0) {
            $data['revenue_amount'] = $expectedSaleAmount;
        }

        if (in_array($data['event_type'] ?? null, [OperationalEvent::TRACKING_NUMBER_ADDED, OperationalEvent::WHOLESALE_PARCEL_SENT], true)) {
            $business = Business::query()->find($data['business_id'] ?? null);
            $sku = Sku::query()->find($data['sku_id'] ?? null);

            if ($business instanceof Business) {
                $impact = app(OperationalImpactCalculator::class)->calculate($business, [
                    'event_type' => $data['event_type'],
                    'sku_code' => $sku?->code,
                    'quantity' => $data['quantity'] ?? 1,
                    'sale_amount' => $expectedSaleAmount,
                    'transport_cost_amount' => $transportCostAmount,
                    'channel' => $data['channel'] ?? null,
                ]);

                $data['sku_id'] = $impact['sku_id'] ?? $data['sku_id'] ?? null;
                $data['revenue_amount'] = 0;
                $data['direct_cost_amount'] = (float) ($impact['direct_cost_amount'] ?? 0);
                $data['leakage_amount'] = (float) ($impact['leakage_amount'] ?? 0);
                $data['recovery_amount'] = (float) ($impact['recovery_amount'] ?? 0);
                $data['payload']['economics'] = $impact['economics'] ?? [];
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var OperationalEvent $event */
        $event = $this->record;

        app(SkuStockMovementService::class)->record($event->business, $event, $event->payload ?? []);
    }
}

<?php

namespace App\Filament\Resources\OperationalEventResource\Pages;

use App\Domains\FinancialClarity\Services\OperationalImpactCalculator;
use App\Domains\FinancialClarity\Services\SkuStockMovementService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\OperationalEventResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOperationalEvent extends EditRecord
{
    protected static string $resource = OperationalEventResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var OperationalEvent $event */
        $event = $this->record;
        $business = Business::query()->find($data['business_id'] ?? $event->business_id);
        $sku = Sku::query()->find($data['sku_id'] ?? null);

        if (! $business instanceof Business) {
            return $data;
        }

        $payload = array_merge($event->payload ?? [], [
            'event_type' => $data['event_type'] ?? $event->event_type,
            'external_id' => $data['external_id'] ?? $event->external_id,
            'sku_code' => $sku?->code ?? (($event->payload ?? [])['sku_code'] ?? null),
            'sku_name' => $sku?->name ?? (($event->payload ?? [])['sku_name'] ?? null),
            'quantity' => (int) ($data['quantity'] ?? $event->quantity ?? (($event->payload ?? [])['quantity'] ?? 1)),
            'channel' => $data['channel'] ?? $event->channel ?? (($event->payload ?? [])['channel'] ?? 'cod'),
        ]);

        $impact = app(OperationalImpactCalculator::class)->calculate($business, $payload);

        $data['sku_id'] = $impact['sku_id'] ?? ($data['sku_id'] ?? null);
        $data['revenue_amount'] = (float) ($impact['revenue_amount'] ?? ($data['revenue_amount'] ?? 0));
        $data['direct_cost_amount'] = (float) ($impact['direct_cost_amount'] ?? 0);
        $data['leakage_amount'] = (float) ($impact['leakage_amount'] ?? 0);
        $data['recovery_amount'] = (float) ($impact['recovery_amount'] ?? 0);
        $data['payload'] = array_merge($payload, [
            'economics' => $impact['economics'] ?? [],
        ]);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var OperationalEvent $event */
        $event = $this->record;

        app(SkuStockMovementService::class)->record($event->business, $event, $event->payload ?? []);
    }
}

<?php

namespace App\Filament\Resources\SkuResource\Pages;

use App\Domains\FinancialClarity\Services\OperationalEventRecalculator;
use App\Filament\Resources\SkuResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSku extends EditRecord
{
    protected static string $resource = SkuResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        if (! $this->record->wasChanged([
            'code',
            'name',
            'expected_sale_price',
            'material_cost',
            'packaging_cost',
            'labor_rate',
            'finishing_cost',
            'active',
        ])) {
            return;
        }

        app(OperationalEventRecalculator::class)->recalculateForSku($this->record);
    }
}

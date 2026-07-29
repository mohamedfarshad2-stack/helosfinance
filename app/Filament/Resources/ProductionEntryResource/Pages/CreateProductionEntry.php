<?php

namespace App\Filament\Resources\ProductionEntryResource\Pages;

use App\Domains\Manufacturing\Services\ProductionCostService;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\ProductionEntryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductionEntry extends CreateRecord
{
    protected static string $resource = ProductionEntryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $sku = Sku::query()->findOrFail((int) $data['sku_id']);

        return app(ProductionCostService::class)->record($sku, $data);
    }
}

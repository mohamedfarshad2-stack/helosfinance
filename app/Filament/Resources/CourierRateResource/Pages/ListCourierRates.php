<?php

namespace App\Filament\Resources\CourierRateResource\Pages;

use App\Filament\Resources\CourierRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;

class ListCourierRates extends ListRecords
{
    protected static string $resource = CourierRateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }
}

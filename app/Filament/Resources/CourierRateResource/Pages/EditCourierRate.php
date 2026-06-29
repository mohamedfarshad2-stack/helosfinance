<?php

namespace App\Filament\Resources\CourierRateResource\Pages;

use App\Filament\Resources\CourierRateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCourierRate extends EditRecord
{
    protected static string $resource = CourierRateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}

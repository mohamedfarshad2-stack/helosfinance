<?php

namespace App\Filament\Resources\ProductionWorkStepResource\Pages;

use App\Filament\Resources\ProductionWorkStepResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductionWorkSteps extends ListRecords
{
    protected static string $resource = ProductionWorkStepResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

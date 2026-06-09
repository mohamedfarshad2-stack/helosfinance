<?php

namespace App\Filament\Resources\CostAssumptionResource\Pages;

use App\Filament\Resources\CostAssumptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCostAssumptions extends ListRecords
{
    protected static string $resource = CostAssumptionResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}

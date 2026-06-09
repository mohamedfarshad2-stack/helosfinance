<?php

namespace App\Filament\Resources\MaterialLedgerResource\Pages;

use App\Filament\Resources\MaterialLedgerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMaterialLedgerEntries extends ListRecords
{
    protected static string $resource = MaterialLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

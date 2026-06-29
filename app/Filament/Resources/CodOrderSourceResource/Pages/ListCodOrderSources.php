<?php

namespace App\Filament\Resources\CodOrderSourceResource\Pages;

use App\Filament\Resources\CodOrderSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCodOrderSources extends ListRecords
{
    protected static string $resource = CodOrderSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

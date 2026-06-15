<?php

namespace App\Filament\Resources\MaterialComponentResource\Pages;

use App\Filament\Resources\MaterialComponentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMaterialComponents extends ListRecords
{
    protected static string $resource = MaterialComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

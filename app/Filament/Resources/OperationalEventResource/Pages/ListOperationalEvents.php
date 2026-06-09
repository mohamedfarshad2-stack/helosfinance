<?php

namespace App\Filament\Resources\OperationalEventResource\Pages;

use App\Filament\Resources\OperationalEventResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOperationalEvents extends ListRecords
{
    protected static string $resource = OperationalEventResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

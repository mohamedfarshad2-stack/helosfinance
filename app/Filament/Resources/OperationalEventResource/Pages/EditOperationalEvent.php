<?php

namespace App\Filament\Resources\OperationalEventResource\Pages;

use App\Filament\Resources\OperationalEventResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOperationalEvent extends EditRecord
{
    protected static string $resource = OperationalEventResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}

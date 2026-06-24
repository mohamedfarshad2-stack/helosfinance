<?php

namespace App\Filament\Resources\CodOrderSourceResource\Pages;

use App\Filament\Resources\CodOrderSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCodOrderSource extends EditRecord
{
    protected static string $resource = CodOrderSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\ServiceClientResource\Pages;

use App\Filament\Resources\ServiceClientResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditServiceClient extends EditRecord
{
    protected static string $resource = ServiceClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

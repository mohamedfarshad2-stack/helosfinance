<?php

namespace App\Filament\Resources\CodOrderResource\Pages;

use App\Domains\FinancialClarity\Services\InternalCodOrderEventService;
use App\Filament\Resources\CodOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCodOrder extends EditRecord
{
    protected static string $resource = CodOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        app(InternalCodOrderEventService::class)->sync($this->record);
    }
}

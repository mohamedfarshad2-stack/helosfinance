<?php

namespace App\Filament\Resources\CodOrderResource\Pages;

use App\Domains\FinancialClarity\Services\InternalCodOrderEventService;
use App\Filament\Resources\CodOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCodOrder extends CreateRecord
{
    protected static string $resource = CodOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uploaded_at'] ??= now();

        return $data;
    }

    protected function afterCreate(): void
    {
        app(InternalCodOrderEventService::class)->sync($this->record);
    }
}

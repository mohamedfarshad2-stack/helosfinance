<?php

namespace App\Filament\Resources\ServiceBillingResource\Pages;

use App\Filament\Resources\ServiceBillingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListServiceBillingRecords extends ListRecords
{
    protected static string $resource = ServiceBillingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

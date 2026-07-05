<?php

namespace App\Filament\Resources\ServiceBillingResource\Pages;

use App\Domains\Shared\Models\ServiceClient;
use App\Filament\Resources\ServiceBillingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditServiceBillingRecord extends EditRecord
{
    protected static string $resource = ServiceBillingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $client = filled($data['service_client_id'] ?? null)
            ? ServiceClient::query()->find($data['service_client_id'])
            : null;

        if ($client instanceof ServiceClient) {
            $data['business_id'] = $client->business_id;
            $data['client_name'] = $client->name;
        }

        return $data;
    }
}

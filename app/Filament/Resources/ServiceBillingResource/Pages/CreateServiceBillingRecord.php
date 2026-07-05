<?php

namespace App\Filament\Resources\ServiceBillingResource\Pages;

use App\Domains\Shared\Models\ServiceClient;
use App\Filament\Resources\ServiceBillingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceBillingRecord extends CreateRecord
{
    protected static string $resource = ServiceBillingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->normalizeServiceClientData($data);
    }

    private function normalizeServiceClientData(array $data): array
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

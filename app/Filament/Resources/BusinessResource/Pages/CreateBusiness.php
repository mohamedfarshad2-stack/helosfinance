<?php

namespace App\Filament\Resources\BusinessResource\Pages;

use App\Domains\Shared\Models\ClientGroup;
use App\Filament\Resources\BusinessResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateBusiness extends CreateRecord
{
    protected static string $resource = BusinessResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();

        if ($user?->isOwner()) {
            if (! $user->client_group_id) {
                $clientGroup = ClientGroup::query()->create([
                    'name' => $user->business?->name ?: $user->name,
                ]);

                $user->forceFill(['client_group_id' => $clientGroup->id])->save();
                $user->business?->forceFill(['client_group_id' => $clientGroup->id])->save();
            }

            $data['client_group_id'] = $user->fresh()->client_group_id;
        }

        return $data;
    }
}

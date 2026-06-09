<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Domains\Shared\Models\Business;
use App\Filament\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        $businessId = $user?->isOwner() ? $user->business_id : ($data['business_id'] ?? null);

        if ($user?->isOwner()) {
            $data['business_id'] = $user->business_id;
            $data['is_employee'] = true;
        }

        $business = filled($businessId) ? Business::query()->find($businessId) : null;

        if ($business instanceof Business && ! $business->employeeSeatAvailable()) {
            throw ValidationException::withMessages([
                'business_id' => 'This business has reached its employee account limit.',
            ]);
        }

        $data['is_platform_admin'] = false;
        $data['is_employee'] = true;
        $data['employee_access_profile'] = $data['employee_access_profile'] ?? 'operations';

        return $data;
    }
}

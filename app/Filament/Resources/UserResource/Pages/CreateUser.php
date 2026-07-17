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
        $businessId = $data['business_id'] ?? $user?->defaultBusinessId();
        $isClientOwner = ($user?->isInternalAdmin() ?? false) && (($data['account_role'] ?? 'staff') === 'owner');

        unset($data['account_role']);

        if ($user?->isOwner()) {
            $allowedBusinessIds = $user->accessibleBusinessIds();
            $businessId = in_array((int) $businessId, $allowedBusinessIds, true) ? (int) $businessId : $user->defaultBusinessId();
            $data['business_id'] = $businessId;
            $data['client_group_id'] = $user->client_group_id;
            $data['is_employee'] = true;
            $isClientOwner = false;
        }

        $business = filled($businessId) ? Business::query()->find($businessId) : null;

        if (! $isClientOwner && $business instanceof Business && ! $business->employeeSeatAvailable()) {
            throw ValidationException::withMessages([
                'business_id' => 'This business has reached its employee account limit.',
            ]);
        }

        $data['is_platform_admin'] = false;
        $data['is_employee'] = ! $isClientOwner;
        $data['employee_access_profile'] = $data['employee_access_profile'] ?? 'operations';
        $hasResponsibilityInput = array_key_exists('staff_responsibilities', $data);
        $data['staff_responsibilities'] = $isClientOwner ? null : ($data['staff_responsibilities'] ?? null);
        $data['responsibilities_configured'] = $isClientOwner ? false : $hasResponsibilityInput;
        $data['is_staff_supervisor'] = $isClientOwner ? false : (bool) ($data['is_staff_supervisor'] ?? false);
        $data['client_group_id'] = $data['client_group_id'] ?? $business?->client_group_id;

        return $data;
    }
}

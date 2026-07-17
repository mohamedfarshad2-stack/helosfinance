<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Domains\Shared\Models\Business;
use App\Filament\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user = Auth::user();
        $targetBusinessId = filled($data['business_id'] ?? null) ? (int) $data['business_id'] : (int) $this->record->business_id;
        $isClientOwner = ($user?->isInternalAdmin() ?? false) && (($data['account_role'] ?? ($this->record->is_employee ? 'staff' : 'owner')) === 'owner');

        unset($data['account_role']);

        if ($user?->isOwner()) {
            $allowedBusinessIds = $user->accessibleBusinessIds();
            $targetBusinessId = in_array($targetBusinessId, $allowedBusinessIds, true) ? $targetBusinessId : (int) $user->defaultBusinessId();
            $data['business_id'] = $targetBusinessId;
            $data['client_group_id'] = $user->client_group_id;
            $isClientOwner = false;
        }

        $business = Business::query()->find($targetBusinessId);

        if (! $isClientOwner && $business instanceof Business && ! $business->employeeSeatAvailable($this->record->id)) {
            throw ValidationException::withMessages([
                'business_id' => 'This business has reached its employee account limit.',
            ]);
        }

        $data['is_platform_admin'] = false;
        $data['is_employee'] = ! $isClientOwner;
        $data['employee_access_profile'] = $data['employee_access_profile'] ?? 'operations';
        $hasResponsibilityInput = array_key_exists('staff_responsibilities', $data);
        $data['staff_responsibilities'] = $isClientOwner ? null : ($data['staff_responsibilities'] ?? null);
        $data['responsibilities_configured'] = $isClientOwner ? false : ($hasResponsibilityInput ? true : (bool) $this->record->responsibilities_configured);
        $data['is_staff_supervisor'] = $isClientOwner ? false : (bool) ($data['is_staff_supervisor'] ?? false);
        $data['client_group_id'] = $data['client_group_id'] ?? $business?->client_group_id;

        return $data;
    }
}

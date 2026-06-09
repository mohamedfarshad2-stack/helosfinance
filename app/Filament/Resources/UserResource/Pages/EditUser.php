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

        if ($user?->isOwner()) {
            $data['business_id'] = $user->business_id;
        }

        $business = Business::query()->find($targetBusinessId);

        if ($business instanceof Business && ! $business->employeeSeatAvailable($this->record->id)) {
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

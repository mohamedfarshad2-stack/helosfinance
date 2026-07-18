<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\StaffResponsibilityAssignment;
use App\Filament\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        if (! $this->record->isStaff() || ! $this->record->business_id || ! $this->record->responsibilities_configured) {
            return;
        }

        $this->syncPrimaryBusinessAssignments((array) ($this->record->staff_responsibilities ?? []));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = UserResource::applyStaffRolePresetToData($data);

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

    private function syncPrimaryBusinessAssignments(array $responsibilities): void
    {
        foreach ($responsibilities as $responsibility) {
            StaffResponsibilityAssignment::query()->firstOrCreate(
                [
                    'user_id' => $this->record->id,
                    'business_id' => $this->record->business_id,
                    'responsibility_code' => $responsibility,
                ],
                [
                    'can_view' => true,
                    'can_create' => in_array($responsibility, ['expense_recording', 'production', 'material_stock', 'collections'], true),
                    'can_edit' => true,
                    'can_complete' => true,
                    'can_review' => in_array($responsibility, ['supervisor_review', 'bank_exceptions', 'product_repair'], true),
                    'can_approve' => false,
                    'team_records_allowed' => $responsibility === 'supervisor_review',
                    'assigned_by' => Auth::id(),
                    'assignment_note' => 'Created from Team Access responsibility selection.',
                    'is_active' => true,
                ]
            );
        }
    }
}

<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\StaffResponsibilityAssignment;
use App\Filament\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        if (! $this->record->isStaff() || ! $this->record->business_id || ! $this->record->responsibilities_configured) {
            return;
        }

        $this->syncPrimaryBusinessAssignments((array) ($this->record->staff_responsibilities ?? []));
    }

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
            abort_unless($this->record->isStaff(), 403);

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

    private function syncPrimaryBusinessAssignments(array $responsibilities): void
    {
        $responsibilities = array_values(array_filter($responsibilities));

        StaffResponsibilityAssignment::query()
            ->where('user_id', $this->record->id)
            ->where('business_id', $this->record->business_id)
            ->whereNotIn('responsibility_code', $responsibilities)
            ->where('is_active', true)
            ->get()
            ->each(fn (StaffResponsibilityAssignment $assignment) => $assignment->deactivate(Auth::user(), 'Removed from Team Access responsibility selection.'));

        foreach ($responsibilities as $responsibility) {
            StaffResponsibilityAssignment::query()->updateOrCreate(
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
                    'assignment_note' => 'Synced from Team Access responsibility selection.',
                    'is_active' => true,
                ]
            );
        }
    }
}

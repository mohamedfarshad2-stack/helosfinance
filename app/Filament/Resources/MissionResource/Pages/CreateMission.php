<?php

namespace App\Filament\Resources\MissionResource\Pages;

use App\Filament\Resources\MissionResource;
use App\Filament\Resources\ProductionEntryResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateMission extends CreateRecord
{
    protected static string $resource = MissionResource::class;

    protected static ?string $title = 'Assign one clear employee task';

    public function mount(): void
    {
        abort_unless(Auth::user()?->isOwner(), 403);

        parent::mount();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $owner = Auth::user();
        $employee = User::query()
            ->whereKey($data['assigned_user_id'] ?? null)
            ->where('is_employee', true)
            ->whereIn('business_id', $owner?->accessibleBusinessIds() ?? [])
            ->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'assigned_user_id' => 'Choose an employee from your business.',
            ]);
        }

        $responsibility = (string) ($data['responsibility_code'] ?? '');

        if (! $employee->hasStaffResponsibility($responsibility, (int) $employee->business_id)) {
            throw ValidationException::withMessages([
                'responsibility_code' => 'This work area is not assigned to the selected employee. Update the employee role first, or choose the correct employee.',
            ]);
        }

        $recommendedAction = trim((string) ($data['recommended_action'] ?? ''));
        unset($data['recommended_action']);

        $manualId = (string) Str::uuid();
        $data['source_key'] = 'manual:'.$manualId;
        $data['mission_type'] = 'owner_assigned_task';
        $data['business_id'] = $employee->business_id;
        $data['source_type'] = 'manual';
        $data['source_id'] = $manualId;
        $data['assigned_by'] = $owner?->id;
        $data['status'] = 'open';
        $data['confidence'] = 'confirmed';
        $data['metadata'] = [
            'recommended_action' => $recommendedAction,
            'assigned_team' => User::staffResponsibilityOptions()[$responsibility] ?? 'Assigned work',
            'related_record' => $responsibility === 'production'
                ? [
                    'label' => 'Create the production entry',
                    'url' => ProductionEntryResource::getUrl('create'),
                ]
                : null,
        ];

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->recordEvent('assigned_by_owner', Auth::user(), 'Task assigned from the simple owner task form.');
    }
}

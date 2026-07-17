<?php

namespace App\Filament\Resources\StaffResponsibilityAssignmentResource\Pages;

use App\Filament\Resources\StaffResponsibilityAssignmentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditStaffResponsibilityAssignment extends EditRecord
{
    protected static string $resource = StaffResponsibilityAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['assigned_by'] = Auth::id() ?? $this->record->assigned_by;

        return $data;
    }
}

<?php

namespace App\Filament\Resources\StaffResponsibilityAssignmentResource\Pages;

use App\Filament\Resources\StaffResponsibilityAssignmentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateStaffResponsibilityAssignment extends CreateRecord
{
    protected static string $resource = StaffResponsibilityAssignmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['assigned_by'] = Auth::id();

        return $data;
    }
}

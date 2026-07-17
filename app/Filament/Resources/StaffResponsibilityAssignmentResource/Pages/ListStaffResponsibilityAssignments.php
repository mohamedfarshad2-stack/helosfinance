<?php

namespace App\Filament\Resources\StaffResponsibilityAssignmentResource\Pages;

use App\Filament\Resources\StaffResponsibilityAssignmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStaffResponsibilityAssignments extends ListRecords
{
    protected static string $resource = StaffResponsibilityAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

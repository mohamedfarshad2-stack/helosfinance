<?php

namespace App\Filament\Resources\MissionResource\Pages;

use App\Filament\Resources\MissionResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditMission extends EditRecord
{
    protected static string $resource = MissionResource::class;

    protected function afterSave(): void
    {
        $this->record->recordEvent('updated_from_review', Auth::user(), 'Mission changed from Mission Review.');
    }
}

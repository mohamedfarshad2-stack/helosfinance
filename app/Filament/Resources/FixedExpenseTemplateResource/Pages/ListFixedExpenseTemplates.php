<?php

namespace App\Filament\Resources\FixedExpenseTemplateResource\Pages;

use App\Filament\Resources\FixedExpenseTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFixedExpenseTemplates extends ListRecords
{
    protected static string $resource = FixedExpenseTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

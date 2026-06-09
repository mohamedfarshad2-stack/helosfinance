<?php

namespace App\Filament\Resources\IntegrationSourceResource\Pages;

use App\Filament\Resources\IntegrationSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationSources extends ListRecords
{
    protected static string $resource = IntegrationSourceResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}

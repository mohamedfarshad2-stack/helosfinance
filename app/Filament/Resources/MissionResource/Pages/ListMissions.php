<?php

namespace App\Filament\Resources\MissionResource\Pages;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Services\MissionGeneratorService;
use App\Filament\Resources\MissionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListMissions extends ListRecords
{
    protected static string $resource = MissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Assign a task')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => Auth::user()?->isOwner() ?? false),
            Actions\Action::make('refreshMissions')
                ->label('Refresh missions')
                ->icon('heroicon-o-arrow-path')
                ->action(function (MissionGeneratorService $generator): void {
                    Business::query()
                        ->whereIn('id', Auth::user()?->accessibleBusinessIds() ?? [])
                        ->get()
                        ->each(fn (Business $business) => $generator->syncForBusiness($business));
                }),
        ];
    }
}

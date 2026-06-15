<?php

namespace App\Filament\Resources\ProductionWorkStepResource\Pages;

use App\Domains\Shared\Models\Business;
use App\Filament\Resources\ProductionWorkStepResource;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListProductionWorkSteps extends ListRecords
{
    protected static string $resource = ProductionWorkStepResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('addCommonSteps')
                ->label('Add common work steps')
                ->icon('heroicon-o-plus-circle')
                ->form([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => $this->businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->dehydrated()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $business = Business::query()->findOrFail($data['business_id']);
                    $result = app(\App\Domains\Manufacturing\Services\ProductionWorkStepSetupService::class)->addCommonSteps($business);

                    Notification::make()
                        ->title('Common work steps are ready')
                        ->body("Added {$result['created']} new steps. Refreshed {$result['updated']} existing steps.")
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}

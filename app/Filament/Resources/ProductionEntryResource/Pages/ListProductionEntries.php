<?php

namespace App\Filament\Resources\ProductionEntryResource\Pages;

use App\Domains\Manufacturing\Services\PartWipBalanceService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\ProductionEntryResource;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ListProductionEntries extends ListRecords
{
    protected static string $resource = ProductionEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('guide')
                ->label('Weekly pay guide')
                ->icon('heroicon-o-question-mark-circle')
                ->modalHeading('How Production Pay works')
                ->modalContent(new HtmlString(<<<HTML
<div class="grid gap-3 text-sm">
    <div><strong>Fixed salaries</strong> belong in <strong>Setup > Staff & Salary Setup</strong> and are for month-end pressure.</div>
    <div><strong>Weekly production pay</strong> belongs here and is filtered by production date so you can review one week or one month at a time.</div>
    <div><strong>Gross payout</strong> is the amount before advances and deductions. <strong>Net payable</strong> is what HELOS will settle.</div>
    <div class="pt-2 text-gray-500">Recommended flow: record production during the week, review the date range, then mark selected payouts as paid when you settle them.</div>
</div>
HTML))
                ->modalSubmitAction(false),
            Actions\Action::make('partWipBalance')
                ->label('Part WIP balance')
                ->icon('heroicon-o-scale')
                ->form([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => $this->businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->dehydrated()
                        ->live()
                        ->required(),
                    Select::make('sku_id')
                        ->label('SKU')
                        ->options(fn (Get $get) => $this->skuOptions((int) ($get('business_id') ?? 0)))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data, PartWipBalanceService $service): void {
                    $sku = Sku::query()
                        ->where('business_id', $data['business_id'])
                        ->findOrFail($data['sku_id']);

                    $lines = collect($service->forSku($sku))
                        ->map(fn (array $row): string => "{$row['part_name']}: produced {$row['produced']}, used {$row['consumed']}, balance {$row['balance']}")
                        ->implode(' | ');

                    Notification::make()
                        ->title("Part WIP balance for {$sku->code}")
                        ->body($lines === '' ? 'No part production recorded yet.' : $lines)
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }

    private function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function skuOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return Sku::query()
            ->where('business_id', $businessId)
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }
}

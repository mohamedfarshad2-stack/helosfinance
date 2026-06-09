<?php

namespace App\Filament\Resources\ProductionEntryResource\Pages;

use App\Filament\Resources\ProductionEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
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
                ->modalHeading('How Weekly Production Pay works')
                ->modalContent(new HtmlString(<<<HTML
<div class="grid gap-3 text-sm">
    <div><strong>Fixed salaries</strong> belong in <strong>Input Center > Team Salaries</strong> and are for month-end pressure.</div>
    <div><strong>Weekly production pay</strong> belongs here and is filtered by production date so you can review one week or one month at a time.</div>
    <div><strong>Gross payout</strong> is the amount before advances and deductions. <strong>Net payable</strong> is what HELOS will settle.</div>
    <div class="pt-2 text-gray-500">Recommended flow: record production during the week, review the date range, then mark selected payouts as paid when you settle them.</div>
</div>
HTML))
                ->modalSubmitAction(false),
            Actions\CreateAction::make(),
        ];
    }
}

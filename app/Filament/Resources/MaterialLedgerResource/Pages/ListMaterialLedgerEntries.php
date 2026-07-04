<?php

namespace App\Filament\Resources\MaterialLedgerResource\Pages;

use App\Filament\Resources\MaterialLedgerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListMaterialLedgerEntries extends ListRecords
{
    protected static string $resource = MaterialLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('guide')
                ->label('How raw material stock works')
                ->icon('heroicon-o-question-mark-circle')
                ->modalHeading('How to record raw material stock')
                ->modalContent(new HtmlString(<<<HTML
<div class="grid gap-3 text-sm">
    <div><strong>Bought material</strong> means new raw material came in.</div>
    <div><strong>Used in production</strong> means raw material was consumed for a product or part.</div>
    <div><strong>Wasted / damaged</strong> means material was spoiled, damaged, or lost.</div>
    <div><strong>Stock correction</strong> is only for fixing a wrong balance after checking the real stock by hand.</div>
    <div class="pt-2 text-gray-500">Simple rule: if material arrived today, start with Bought material. If it was used on the factory floor, choose Used in production.</div>
</div>
HTML))
                ->modalSubmitAction(false),
            Actions\CreateAction::make(),
        ];
    }
}

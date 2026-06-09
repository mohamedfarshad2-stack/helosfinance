<?php

namespace App\Filament\Resources\BankTransactionResource\Pages;

use App\Filament\Resources\BankTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListBankTransactions extends ListRecords
{
    protected static string $resource = BankTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('statusGuide')
                ->label('Status guide')
                ->icon('heroicon-o-question-mark-circle')
                ->modalHeading('What the statuses mean')
                ->modalContent(new HtmlString(<<<HTML
<div class="grid gap-3 text-sm">
    <div><strong>Needs review</strong> means HELOS is not sure yet. Leave it here until you classify it.</div>
    <div><strong>Reviewed / classified</strong> means you manually chose the right business meaning. This is the normal finish line after you edit or bulk-review rows.</div>
    <div><strong>Matched rule</strong> means HELOS recognized a saved rule and classified it automatically. You do not need to pick this by hand.</div>
    <div class="pt-2 text-gray-500">Recommended flow: select rows that look the same, choose one classification, and set status to <strong>Reviewed / classified</strong>. Use <strong>Matched rule</strong> only when HELOS has auto-learned the pattern.</div>
</div>
HTML))
                ->modalSubmitAction(false),
            Actions\CreateAction::make(),
        ];
    }
}

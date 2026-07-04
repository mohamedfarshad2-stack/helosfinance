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
                ->label('How to review rows')
                ->icon('heroicon-o-question-mark-circle')
                ->modalHeading('How to review bank and cash rows')
                ->modalContent(new HtmlString(<<<HTML
<div class="grid gap-3 text-sm">
    <div><strong>Step 1:</strong> read the bank line and decide what really happened.</div>
    <div><strong>Step 2:</strong> choose whether it is <strong>Revenue</strong>, <strong>Expense</strong>, <strong>COD settlement</strong>, or <strong>Transfer</strong>.</div>
    <div><strong>Step 3:</strong> if it belongs to a business, choose the right business. Leave business blank only for pure internal transfers.</div>
    <div><strong>Needs review</strong> means HELOS is still waiting for a human decision.</div>
    <div><strong>Reviewed / classified</strong> means you checked it and saved the correct meaning.</div>
    <div><strong>Matched rule</strong> means HELOS recognized a saved pattern and filled it automatically.</div>
    <div class="pt-2 text-gray-500">Simple rule: if money only moved between your own bank, savings, petty cash, or store cash, choose <strong>Transfer</strong>. Do not mark that as Expense.</div>
</div>
HTML))
                ->modalSubmitAction(false),
            Actions\CreateAction::make(),
        ];
    }
}

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
    <div><strong>Step 1:</strong> read the bank line and choose <strong>What happened?</strong></div>
    <div><strong>Step 2:</strong> choose <strong>Which business?</strong> only when the money belongs to a business.</div>
    <div><strong>Step 3:</strong> click <strong>Done</strong> when the row is correct.</div>
    <div><strong>HELOS money effect</strong> is filled by HELOS. It tells you whether the row adds sales, adds cost, confirms COD cash, moves own cash only, or records owner/loan money.</div>
    <div><strong>Needs review</strong> means HELOS is still waiting for a human decision.</div>
    <div><strong>Reviewed</strong> means you checked it and HELOS can use it for cash and business truth.</div>
    <div class="pt-2 text-gray-500">Simple rule: if money only moved between your own bank, savings, petty cash, or store cash, choose <strong>Transfer</strong>. Do not mark that as Expense.</div>
</div>
HTML))
                ->modalSubmitAction(false),
            Actions\CreateAction::make(),
        ];
    }
}

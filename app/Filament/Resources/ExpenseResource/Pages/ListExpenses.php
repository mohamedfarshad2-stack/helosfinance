<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\HtmlString;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('guide')
                ->label('When to use this page')
                ->icon('heroicon-o-question-mark-circle')
                ->modalHeading('Expenses vs Petty Cash vs Bank Review')
                ->modalContent(new HtmlString(<<<HTML
<div class="grid gap-3 text-sm">
    <div><strong>Use Expenses & Payables</strong> for the full expense record, especially when there is due money, part payment, cheque, or supplier balance.</div>
    <div><strong>Use Petty Cash Spend</strong> only when cash was already in hand and the spend happened from that cash.</div>
    <div><strong>Use Bank Review</strong> when the money moved through the bank statement, savings, petty cash funding, or another money container.</div>
    <div class="pt-2 text-gray-500">Simple rule: if a bank line exists, start with Bank Review. If a cash spend happened from money already withdrawn, use Petty Cash Spend or this page depending on how much detail you need.</div>
</div>
HTML))
                ->modalSubmitAction(false),
            Actions\Action::make('quickExpenseEntry')
                ->label('Quick expense entry')
                ->icon('heroicon-o-bolt')
                ->url('/admin/quick-expense-entry'),
            Actions\CreateAction::make(),
        ];
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }
}

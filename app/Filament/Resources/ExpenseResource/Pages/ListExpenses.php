<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;
    protected function getHeaderActions(): array
    {
        return [
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

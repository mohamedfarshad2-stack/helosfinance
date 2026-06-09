<?php

namespace App\Filament\Resources\BankTransactionRuleResource\Pages;

use App\Filament\Resources\BankTransactionRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBankTransactionRules extends ListRecords
{
    protected static string $resource = BankTransactionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

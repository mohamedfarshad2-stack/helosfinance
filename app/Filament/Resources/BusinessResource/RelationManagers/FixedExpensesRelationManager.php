<?php

namespace App\Filament\Resources\BusinessResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FixedExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Fixed expenses';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('category')
                ->label('What is this for?')
                ->required()
                ->maxLength(255),
            TextInput::make('amount')
                ->label('Amount')
                ->numeric()
                ->prefix('LKR')
                ->required(),
            Textarea::make('description')->label('Short note')->rows(2),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('expense_type', 'fixed')->orderByDesc('spent_on'))
            ->recordTitleAttribute('category')
            ->columns([
                Tables\Columns\TextColumn::make('category')->label('Fixed expense')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Amount')->money('LKR'),
                Tables\Columns\IconColumn::make('locked_at')->label('Locked')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add fixed expense')
                    ->mutateFormDataUsing(fn (array $data): array => [
                        ...$data,
                        'expense_type' => 'fixed',
                        'business_id' => $this->getOwnerRecord()->id,
                        'spent_on' => now()->startOfMonth()->toDateString(),
                        'payment_status' => 'paid',
                        'payment_method' => 'cash',
                        'paid_amount' => $data['amount'] ?? 0,
                        'allocation_bucket' => 'company_expense',
                        'recurring' => true,
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record): bool => blank($record->locked_at)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}

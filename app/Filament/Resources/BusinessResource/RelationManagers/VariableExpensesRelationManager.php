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

class VariableExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Variable expenses';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('category')
                ->label('What was spent on?')
                ->required()
                ->maxLength(255),
            TextInput::make('amount')
                ->label('Amount')
                ->numeric()
                ->prefix('LKR')
                ->required(),
            DatePicker::make('spent_on')
                ->label('Date')
                ->default(now())
                ->required(),
            Textarea::make('description')
                ->label('Short note')
                ->rows(2),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('expense_type', 'variable')->orderByDesc('spent_on'))
            ->recordTitleAttribute('category')
            ->columns([
                Tables\Columns\TextColumn::make('category')->label('Variable expense')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Amount')->money('LKR'),
                Tables\Columns\TextColumn::make('spent_on')->label('Date')->date(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add variable expense')
                    ->mutateFormDataUsing(fn (array $data): array => [
                        ...$data,
                        'expense_type' => 'variable',
                        'business_id' => $this->getOwnerRecord()->id,
                        'payment_status' => 'paid',
                        'payment_method' => 'cash',
                        'paid_amount' => $data['amount'] ?? 0,
                        'allocation_bucket' => 'operations',
                        'recurring' => false,
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Filament\Resources\BankTransactionResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class BankTransactionResource extends Resource
{
    protected static ?string $model = BankTransaction::class;
    protected static ?string $navigationGroup = 'Money';
    protected static ?string $navigationLabel = 'Bank Review';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('business_id')
                ->options(fn () => static::businessOptions())
                ->default(fn () => Auth::user()?->defaultBusinessId())
                ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                ->required(),
            DatePicker::make('transaction_date')->required(),
            TextInput::make('description')->required(),
            Select::make('money_container')
                ->label('Bank account / cash container')
                ->placeholder('Current Account, Savings Account, Petty Cash...')
                ->searchable()
                ->options(fn () => static::moneyContainerOptions())
                ->preload()
                ->helperText('Use Store Cash / Cash Drawer for cash settled in the shop before it reaches the bank.')
                ->required(),
            TextInput::make('debit')->numeric()->prefix('LKR')->default(0),
            TextInput::make('credit')->numeric()->prefix('LKR')->default(0),
            TextInput::make('balance')->numeric()->prefix('LKR')->nullable(),
            Select::make('transaction_type')
                ->label('Transaction type')
                ->options(BankTransaction::transactionTypeOptions())
                ->helperText('Use Transfer when money only moved between your own places like Current Account, Savings, Petty Cash, or Store Cash. Use Expense only when the business really spent the money outside.')
                ->required(),
            Select::make('counter_money_container')
                ->label('Transfer destination account')
                ->placeholder('Only when this is a transfer')
                ->searchable()
                ->options(fn () => static::moneyContainerOptions())
                ->preload()
                ->visible(fn (Get $get): bool => $get('transaction_type') === 'transfer')
                ->required(fn (Get $get): bool => $get('transaction_type') === 'transfer'),
            Select::make('allocated_business_id')
                ->label('Business assignment')
                ->options(fn () => static::businessOptions())
                ->searchable()
                ->nullable()
                ->placeholder('Shared / Unallocated')
                ->helperText('Choose the business this money belongs to. Leave blank only for pure internal transfers between your own money containers.'),
            Select::make('classification')->options(BankTransaction::classificationOptions())
                ->helperText('If this row only moved money into petty cash or store cash, classify it as Transfer, not Expense.')
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                    $inferredType = BankTransaction::inferTransactionType($state);

                    if (filled($inferredType)) {
                        $set('transaction_type', $inferredType);
                    }

                    if (BankTransaction::needsBusinessAssignment($inferredType) && blank($get('allocated_business_id')) && ! (Auth::user()?->isInternalAdmin() ?? false)) {
                        $set('allocated_business_id', Auth::user()?->defaultBusinessId());
                    }
                })
                ->required(),
            Select::make('status')->options([
                'review' => 'Needs review',
                'classified' => 'Reviewed / classified',
            ])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query)
                ->orderByRaw("case when status = 'review' then 0 else 1 end")
                ->orderByDesc('transaction_date')
                ->orderByDesc('id'))
            ->defaultSort('transaction_date', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No bank or cash rows to review')
            ->emptyStateDescription('Import a statement or add a cash row here. First decide what happened: money came in, money went out, or money only moved between your own accounts.')
            ->columns([
                Grid::make([
                    'default' => 1,
                    'xl' => 6,
                ])->schema([
                    Stack::make([
                        Tables\Columns\TextColumn::make('transaction_date')
                            ->label('Date')
                            ->date('M j, Y')
                            ->sortable()
                            ->weight('semibold'),
                        Tables\Columns\TextColumn::make('status')
                            ->label('Review')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => static::statusOptions()[$state] ?? ucfirst($state))
                            ->color(fn (string $state): string => match ($state) {
                                'classified', 'matched' => 'success',
                                default => 'warning',
                            }),
                    ])->space(1),
                    Stack::make([
                        Tables\Columns\TextColumn::make('description')
                            ->label('Bank line')
                            ->searchable()
                            ->wrap()
                            ->weight('medium'),
                        Tables\Columns\TextColumn::make('money_container')
                            ->label('Container')
                            ->placeholder('Unassigned')
                            ->badge(),
                        Tables\Columns\TextColumn::make('counter_money_container')
                            ->label('Transfer to')
                            ->placeholder('-')
                            ->visible(fn (?BankTransaction $record): bool => filled($record?->counter_money_container)),
                    ])->space(1)->grow(true),
                    Tables\Columns\TextColumn::make('signed_amount')
                        ->label('Amount')
                        ->state(function (BankTransaction $record): string {
                            $amount = (float) ($record->credit ?: 0) - (float) ($record->debit ?: 0);
                            $prefix = $amount >= 0 ? '+' : '-';

                            return $prefix.'LKR '.number_format(abs($amount), 2);
                        })
                        ->color(fn (BankTransaction $record): string => ((float) ($record->credit ?: 0) - (float) ($record->debit ?: 0)) >= 0 ? 'success' : 'danger')
                        ->weight('semibold'),
                    SelectColumn::make('classification')
                        ->label('Meaning')
                        ->options(BankTransaction::classificationOptions())
                        ->selectablePlaceholder(false)
                        ->afterStateUpdated(function (BankTransaction $record, string $state): void {
                            $transactionType = BankTransaction::inferTransactionType($state) ?? $record->transaction_type;
                            $allocatedBusinessId = $record->allocated_business_id;

                            if (blank($allocatedBusinessId) && BankTransaction::needsBusinessAssignment($transactionType)) {
                                $allocatedBusinessId = $record->business_id;
                            }

                            $record->forceFill([
                                'classification' => $state,
                                'transaction_type' => $transactionType,
                                'allocated_business_id' => $allocatedBusinessId,
                                'status' => static::resolveReviewStatus($record, $state, $transactionType, $allocatedBusinessId),
                                'reviewed_at' => now(),
                            ])->save();
                        }),
                    SelectColumn::make('transaction_type')
                        ->label('Effect')
                        ->options(BankTransaction::transactionTypeOptions())
                        ->selectablePlaceholder(false)
                        ->afterStateUpdated(function (BankTransaction $record, string $state): void {
                            $allocatedBusinessId = $record->allocated_business_id;

                            if (blank($allocatedBusinessId) && BankTransaction::needsBusinessAssignment($state)) {
                                $allocatedBusinessId = $record->business_id;
                            }

                            $record->forceFill([
                                'transaction_type' => $state,
                                'allocated_business_id' => $allocatedBusinessId,
                                'status' => static::resolveReviewStatus($record, $record->classification, $state, $allocatedBusinessId),
                                'reviewed_at' => now(),
                            ])->save();
                        }),
                    SelectColumn::make('allocated_business_id')
                        ->label('Business')
                        ->options(static::businessOptions())
                        ->placeholder('Shared / Unallocated')
                        ->afterStateUpdated(function (BankTransaction $record, $state): void {
                            $record->forceFill([
                                'allocated_business_id' => filled($state) ? (int) $state : null,
                                'status' => static::resolveReviewStatus($record, $record->classification, $record->transaction_type, filled($state) ? (int) $state : null),
                                'reviewed_at' => now(),
                            ])->save();
                        }),
                ]),
            ])->filters([
            Filter::make('transaction_date')
                ->form([
                    DatePicker::make('from')->label('From'),
                    DatePicker::make('to')->label('To'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('transaction_date', '>=', $date))
                        ->when($data['to'] ?? null, fn (Builder $query, $date) => $query->whereDate('transaction_date', '<=', $date));
                }),
            Tables\Filters\SelectFilter::make('status')->options([
                'review' => 'Needs review',
                'classified' => 'Reviewed / classified',
                'matched' => 'Matched rule',
            ]),
            Tables\Filters\SelectFilter::make('classification')->options(BankTransaction::classificationOptions()),
        ])->actions([
            Action::make('markReviewed')
                ->label('Done')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Mark this row as reviewed')
                ->modalDescription('HELOS will keep the row, but treat this decision as checked and ready for finance reporting.')
                ->action(function (BankTransaction $record): void {
                    $status = static::resolveReviewStatus($record, $record->classification, $record->transaction_type, $record->allocated_business_id, 'classified');

                    if ($status === 'review') {
                        Notification::make()
                            ->title('This row still needs a few fields')
                            ->body('Choose the meaning, effect, and business first. Transfers also need the transfer destination.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $record->forceFill([
                        'status' => 'classified',
                        'reviewed_at' => now(),
                    ])->save();

                    Notification::make()
                        ->title('Row marked as reviewed')
                        ->success()
                        ->send();
                }),
            Tables\Actions\EditAction::make()
                ->label('More'),
        ])->bulkActions([
            BulkActionGroup::make([
                BulkAction::make('reviewSelected')
                    ->label('Review selected')
                    ->icon('heroicon-o-check-circle')
                    ->form([
                        Select::make('classification')
                            ->label('Classification')
                            ->options(BankTransaction::classificationOptions())
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                $inferredType = BankTransaction::inferTransactionType($state);

                                if (filled($inferredType)) {
                                    $set('transaction_type', $inferredType);
                                }

                                if (BankTransaction::needsBusinessAssignment($inferredType) && blank($get('allocated_business_id')) && ! (Auth::user()?->isInternalAdmin() ?? false)) {
                                    $set('allocated_business_id', Auth::user()?->defaultBusinessId());
                                }
                            })
                            ->required(),
                        Select::make('transaction_type')
                            ->label('Transaction type')
                            ->options(BankTransaction::transactionTypeOptions())
                            ->required(),
                        Select::make('money_container')
                            ->label('Bank account / cash container')
                            ->placeholder('Current Account, Savings Account, Petty Cash...')
                            ->searchable()
                            ->options(fn () => static::moneyContainerOptions())
                            ->preload()
                            ->helperText('Use Store Cash / Cash Drawer for cash settled in the shop before it reaches the bank.')
                            ->required(),
                        Select::make('counter_money_container')
                            ->label('Transfer destination account')
                            ->placeholder('Only when this is a transfer')
                            ->searchable()
                            ->options(fn () => static::moneyContainerOptions())
                            ->preload()
                            ->visible(fn (Get $get): bool => $get('transaction_type') === 'transfer')
                            ->required(fn (Get $get): bool => $get('transaction_type') === 'transfer'),
                        Select::make('allocated_business_id')
                            ->label('Business assignment')
                            ->options(fn () => static::businessOptions())
                            ->searchable()
                            ->nullable()
                            ->placeholder('Shared / Unallocated'),
                        Select::make('status')
                            ->label('Status')
                            ->options(static::statusOptions(false))
                            ->default('classified')
                            ->required(),
                    ])
                            ->action(function (Collection $records, array $data): void {
                        $records->each(function (BankTransaction $record) use ($data): void {
                            $transactionType = $data['transaction_type'] ?: BankTransaction::inferTransactionType($data['classification']);
                            $allocatedBusinessId = filled($data['allocated_business_id'] ?? null)
                                ? (int) $data['allocated_business_id']
                                : (BankTransaction::needsBusinessAssignment($transactionType) ? $record->business_id : null);

                            $record->forceFill([
                                'classification' => $data['classification'],
                                'transaction_type' => $transactionType,
                                'money_container' => $data['money_container'],
                                'counter_money_container' => $data['counter_money_container'] ?? null,
                                'allocated_business_id' => $allocatedBusinessId,
                                'status' => static::resolveReviewStatus($record, $data['classification'], $transactionType, $allocatedBusinessId, $data['status']),
                                'reviewed_at' => now(),
                            ])->save();
                        });

                        Notification::make()
                            ->title('Selected rows updated')
                            ->body('HELOS learned from the selected bank lines.')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankTransactions::route('/'),
            'create' => Pages\CreateBankTransaction::route('/create'),
            'edit' => Pages\EditBankTransaction::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || ($user?->canAccessFinanceOperations() ?? false));
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false) || ($user?->canAccessFinanceOperations() ?? false));
    }

    private static function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->pluck('name', 'id')
            ->all();
    }

    private static function moneyContainerOptions(): array
    {
        $user = Auth::user();

        $existing = BankTransaction::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []))
            ->whereNotNull('money_container')
            ->where('money_container', '!=', '')
            ->distinct()
            ->orderBy('money_container')
            ->pluck('money_container')
            ->all();

        return array_values(array_unique(array_merge(BankTransaction::treasuryContainerDefaults(), $existing)));
    }

    private static function scopeToCurrentBusiness(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->seesAllBusinesses()) {
            return $query;
        }

        return $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []);
    }

    private static function statusOptions(bool $includeMatched = true): array
    {
        $options = [
            'review' => 'Needs review',
            'classified' => 'Reviewed / classified',
        ];

        if ($includeMatched) {
            $options['matched'] = 'Matched rule';
        }

        return $options;
    }

    private static function resolveReviewStatus(BankTransaction $record, ?string $classification, ?string $transactionType, ?int $allocatedBusinessId, ?string $requestedStatus = null): string
    {
        $classification = $classification ?: $record->classification;
        $transactionType = $transactionType ?: $record->transaction_type;
        $allocatedBusinessId = $allocatedBusinessId ?? $record->allocated_business_id;

        if ($requestedStatus === 'review') {
            return 'review';
        }

        if (blank($record->money_container) || blank($transactionType)) {
            return 'review';
        }

        if ($transactionType === 'transfer') {
            return blank($record->counter_money_container) ? 'review' : 'classified';
        }

        if (BankTransaction::needsBusinessAssignment($transactionType) && blank($allocatedBusinessId)) {
            return 'review';
        }

        return in_array($classification, ['unknown', null], true) ? 'review' : 'classified';
    }
}

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
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
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
                ->options(static::transactionTypeOptions())
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
                ->helperText('Required for revenue, COD settlement, expenses, loans, owner contributions, and owner withdrawals. Transfers stay unallocated.'),
            Select::make('classification')->options([
                'unknown' => 'Unknown',
                'revenue' => 'Revenue',
                'cod_settlement' => 'COD settlement',
                'expense' => 'Expense',
                'salary' => 'Salary',
                'supplier_payment' => 'Supplier payment',
                'courier' => 'Courier',
                'fuel' => 'Fuel',
                'packing' => 'Packing',
                'marketing' => 'Marketing',
                'rent' => 'Rent',
                'utility' => 'Utility',
                'bank_charge' => 'Bank charge',
                'owner_withdrawal' => 'Owner withdrawal',
                'transfer' => 'Transfer',
                'petty_cash' => 'Petty cash',
                'maintenance' => 'Maintenance',
            ])->required(),
            Select::make('status')->options([
                'review' => 'Needs review',
                'classified' => 'Reviewed / classified',
            ])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))->defaultSort('transaction_date', 'desc')
            ->emptyStateHeading('No bank or cash rows to review')
            ->emptyStateDescription('Import a statement or add a cash transaction. Then choose whether each row is revenue, COD settlement, expense, transfer, owner money, loan, or other.')
            ->columns([
            Tables\Columns\TextColumn::make('transaction_date')->date()->sortable(),
            Tables\Columns\TextColumn::make('description')->searchable()->limit(30),
            Tables\Columns\TextColumn::make('money_container')->label('Account')->placeholder('Unassigned')->badge(),
            Tables\Columns\TextColumn::make('transaction_type')->badge()->label('Type'),
            Tables\Columns\TextColumn::make('allocatedBusiness.name')->label('Business')->placeholder('Shared / Unallocated')->toggleable(),
            Tables\Columns\TextColumn::make('counter_money_container')->label('Transfer to')->placeholder('-')->toggleable(),
            SelectColumn::make('classification')
                ->options(static::classificationOptions())
                ->afterStateUpdated(function (BankTransaction $record, string $state): void {
                    $record->forceFill([
                        'classification' => $state,
                        'status' => static::resolveReviewStatus($record, $state, $record->transaction_type, $record->allocated_business_id),
                        'reviewed_at' => now(),
                    ])->save();
                }),
            SelectColumn::make('transaction_type')
                ->options(static::transactionTypeOptions())
                ->afterStateUpdated(function (BankTransaction $record, string $state): void {
                    $record->forceFill([
                        'transaction_type' => $state,
                        'status' => static::resolveReviewStatus($record, $record->classification, $state, $record->allocated_business_id),
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
            Tables\Columns\TextColumn::make('debit')->money('LKR'),
            Tables\Columns\TextColumn::make('credit')->money('LKR'),
            Tables\Columns\TextColumn::make('confidence')->label('Conf.')->formatStateUsing(fn ($state) => number_format((float) $state, 2)),
            SelectColumn::make('status')
                ->options(static::statusOptions())
                ->disableOptionWhen(fn (string $value): bool => $value === 'matched')
                ->afterStateUpdated(function (BankTransaction $record, string $state): void {
                    $record->forceFill([
                        'status' => $state,
                        'reviewed_at' => now(),
                    ])->save();
                }),
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
            Tables\Filters\SelectFilter::make('classification')->options(static::classificationOptions()),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            BulkActionGroup::make([
                BulkAction::make('reviewSelected')
                    ->label('Review selected')
                    ->icon('heroicon-o-check-circle')
                    ->form([
                        Select::make('classification')
                            ->label('Classification')
                            ->options(static::classificationOptions())
                            ->required(),
                        Select::make('transaction_type')
                            ->label('Transaction type')
                            ->options(static::transactionTypeOptions())
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
                            $record->forceFill([
                                'classification' => $data['classification'],
                                'transaction_type' => $data['transaction_type'],
                                'money_container' => $data['money_container'],
                                'counter_money_container' => $data['counter_money_container'] ?? null,
                                'allocated_business_id' => filled($data['allocated_business_id'] ?? null) ? (int) $data['allocated_business_id'] : null,
                                'status' => static::resolveReviewStatus($record, $data['classification'], $data['transaction_type'], filled($data['allocated_business_id'] ?? null) ? (int) $data['allocated_business_id'] : null, $data['status']),
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

    private static function classificationOptions(): array
    {
        return [
            'unknown' => 'Unknown',
            'revenue' => 'Revenue',
            'cod_settlement' => 'COD settlement',
            'expense' => 'Expense',
            'salary' => 'Salary',
            'supplier_payment' => 'Supplier payment',
            'courier' => 'Courier',
            'fuel' => 'Fuel',
            'packing' => 'Packing',
            'marketing' => 'Marketing',
            'rent' => 'Rent',
            'utility' => 'Utility',
            'bank_charge' => 'Bank charge',
            'owner_withdrawal' => 'Owner withdrawal',
            'transfer' => 'Transfer',
            'petty_cash' => 'Petty cash',
            'maintenance' => 'Maintenance',
        ];
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

    private static function transactionTypeOptions(): array
    {
        return [
            'revenue' => 'Revenue',
            'cod_settlement' => 'COD settlement',
            'expense' => 'Expense',
            'transfer' => 'Transfer',
            'owner_contribution' => 'Owner contribution',
            'owner_withdrawal' => 'Owner withdrawal',
            'loan' => 'Loan',
            'other' => 'Other',
        ];
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

        if (in_array($transactionType, ['revenue', 'cod_settlement', 'expense', 'loan', 'owner_contribution', 'owner_withdrawal'], true) && blank($allocatedBusinessId)) {
            return 'review';
        }

        return in_array($classification, ['unknown', null], true) ? 'review' : 'classified';
    }
}

<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\FixedExpenseTemplate;
use App\Filament\Resources\ExpenseResource\Pages;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Get;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;
    protected static ?string $navigationGroup = 'Money';
    protected static ?string $navigationLabel = 'Expenses & Payables';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Placeholder::make('locked_notice')
                ->label('Locked fixed expense')
                ->content('This fixed expense has been confirmed for clarity calculations. Unlock only when the client has corrected the business assumption.')
                ->visible(fn (?Expense $record): bool => filled($record?->locked_at)),
            Section::make('Expense')
                ->schema([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => static::businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->disabled(fn (?Expense $record): bool => filled($record?->locked_at) || ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->required(),
                    TextInput::make('amount')
                        ->label('Total expense amount')
                        ->numeric()
                        ->required()
                        ->prefix('LKR')
                        ->live()
                        ->disabled(fn (?Expense $record): bool => filled($record?->locked_at)),
                    Select::make('category')
                        ->label('What was paid for?')
                        ->placeholder('Search or choose a repeat cost')
                        ->searchable()
                        ->options(fn () => static::categoryOptions())
                        ->preload()
                        ->required()
                        ->disabled(fn (?Expense $record): bool => filled($record?->locked_at)),
                    Select::make('expense_type')
                        ->label('Does it happen monthly or change with work?')
                        ->options([
                            'variable' => 'Changes with work / daily spending',
                            'fixed' => 'Monthly fixed cost',
                        ])
                        ->default('variable')
                        ->disabled(fn (?Expense $record): bool => filled($record?->locked_at))
                        ->required(),
                    DatePicker::make('spent_on')
                        ->label('Date')
                        ->default(now())
                        ->required()
                        ->disabled(fn (?Expense $record): bool => filled($record?->locked_at)),
                    TextInput::make('description')
                        ->label('Short note')
                        ->placeholder('Optional')
                        ->disabled(fn (?Expense $record): bool => filled($record?->locked_at)),
                ])
                ->columns(2),
            Section::make('Payment and settlement')
                ->schema([
                    Select::make('payment_status')
                        ->label('Payment status')
                        ->options([
                            'paid' => 'Paid fully',
                            'partial' => 'Part paid',
                            'cheque_pending' => 'Cheque given / pending',
                            'credit_due' => 'Credit / pay later',
                            'settled' => 'Settled',
                        ])
                        ->default('paid')
                        ->required(),
                    Select::make('payment_method')
                        ->label('Payment method')
                        ->options([
                            'cash' => 'Cash',
                            'bank' => 'Bank transfer',
                            'cheque' => 'Cheque',
                            'card' => 'Card',
                            'credit' => 'Credit',
                            'other' => 'Other',
                        ])
                        ->live()
                        ->default('cash'),
                    TextInput::make('cheque_number')
                        ->label('Cheque number')
                        ->placeholder('Only when payment method is cheque')
                        ->visible(fn (Get $get): bool => $get('payment_method') === 'cheque')
                        ->required(fn (Get $get): bool => $get('payment_method') === 'cheque'),
                    DatePicker::make('cheque_date')
                        ->label('Cheque date')
                        ->visible(fn (Get $get): bool => $get('payment_method') === 'cheque')
                        ->required(fn (Get $get): bool => $get('payment_method') === 'cheque'),
                    TextInput::make('paid_amount')
                        ->label('Paid amount now')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->live()
                        ->afterStateHydrated(function (TextInput $component, mixed $state): void {
                            if ($state === null || $state === '') {
                                $component->state(0);
                            }
                        })
                        ->dehydrateStateUsing(fn (mixed $state): float => filled($state) ? (float) $state : 0),
                    Placeholder::make('balance_due')
                        ->label('Balance to pay')
                        ->content(function (Get $get): string {
                            $amount = (float) ($get('amount') ?? 0);
                            $paid = (float) ($get('paid_amount') ?? 0);
                            $balance = max($amount - $paid, 0);

                            return $amount > 0 ? 'LKR '.number_format($balance, 2) : 'Enter total and paid amount to see the balance.';
                        }),
                    DatePicker::make('due_on')->label('Due date'),
                    DatePicker::make('settled_on')->label('Settled date'),
                ])
                ->columns(2),
            Section::make('More details')
                ->collapsed()
                ->schema([
                    Select::make('reported_by')
                        ->label('Reported by employee')
                        ->options(fn () => static::employeeOptions())
                        ->searchable()
                        ->placeholder('Select employee'),
                    Select::make('payee')
                        ->label('Paid to / supplier')
                        ->placeholder('Search supplier / payee')
                        ->searchable()
                        ->options(fn () => static::payeeOptions())
                        ->preload()
                        ->helperText('If one purchase is paid to different people, save them as separate rows.'),
                    Select::make('allocation_bucket')
                        ->label('Allocate to')
                        ->options([
                            'company_expense' => 'Company expense',
                            'salary' => 'Employee salary / payout',
                            'owner' => 'Owner drawing',
                            'operations' => 'Operations',
                            'production' => 'Production',
                        ])
                        ->default('company_expense'),
                    Select::make('department')
                        ->label('Department')
                        ->placeholder('Search or choose department')
                        ->searchable()
                        ->options(fn () => static::departmentOptions())
                        ->preload()
                        ->disabled(fn (?Expense $record): bool => filled($record?->locked_at)),
                    Toggle::make('recurring')->label('Repeats monthly')->disabled(fn (?Expense $record): bool => filled($record?->locked_at)),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('spent_on', 'desc')->columns([
            Tables\Columns\TextColumn::make('spent_on')->label('Date')->date()->sortable(),
            Tables\Columns\TextColumn::make('category')
                ->label('Expense')
                ->searchable()
                ->limit(28),
            Tables\Columns\TextColumn::make('payee')
                ->label('Payee')
                ->placeholder('-')
                ->searchable(),
            Tables\Columns\TextColumn::make('payment_status')->label('Payment')->badge(),
            Tables\Columns\TextColumn::make('amount')->label('Total')->money('LKR')->sortable(),
            Tables\Columns\TextColumn::make('paid_amount')->label('Paid')->money('LKR')->sortable(),
            Tables\Columns\TextColumn::make('due_amount')
                ->label('Due')
                ->state(fn (Expense $record): float => max((float) $record->amount - (float) $record->paid_amount, 0))
                ->money('LKR'),
            Tables\Columns\TextColumn::make('reported_by')
                ->label('By')
                ->placeholder('-')
                ->searchable(),
            Tables\Columns\TextColumn::make('expense_type')
                ->label('Type')
                ->badge()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('description')
                ->label('Note')
                ->limit(35)
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('department')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('cheque_number')
                ->label('Cheque #')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('cheque_date')
                ->label('Cheque date')
                ->date()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('due_on')
                ->label('Due date')
                ->date()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\IconColumn::make('locked_at')
                ->label('Locked')
                ->boolean()
                ->state(fn (Expense $record): bool => filled($record->locked_at))
                ->toggleable(isToggledHiddenByDefault: true),
        ])
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))
            ->filters([
                Tables\Filters\SelectFilter::make('expense_type')
                    ->label('Cost behavior')
                    ->options([
                        'fixed' => 'Fixed cost',
                        'variable' => 'Variable cost',
                    ]),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('Payment status')
                    ->options([
                        'paid' => 'Paid fully',
                        'partial' => 'Part paid',
                        'cheque_pending' => 'Cheque given / pending',
                        'credit_due' => 'Credit / pay later',
                        'settled' => 'Settled',
                    ]),
            ])
            ->headerActions([
                Action::make('recordToday')
                    ->label('Record today expense')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form([
                        Select::make('business_id')
                            ->label('Business')
                            ->options(fn () => static::businessOptions())
                            ->default(fn () => Auth::user()?->defaultBusinessId())
                            ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                            ->required(),
                        TextInput::make('amount')
                            ->label('Total expense amount')
                            ->numeric()
                            ->prefix('LKR')
                            ->live()
                            ->required(),
                        Select::make('category')
                            ->label('What was paid for?')
                            ->placeholder('Search or choose a repeat cost')
                            ->searchable()
                            ->options(fn () => static::categoryOptions())
                            ->preload()
                            ->required(),
                        Select::make('payee')
                            ->label('Paid to / supplier')
                            ->placeholder('Search supplier / payee')
                            ->searchable()
                            ->options(fn () => static::payeeOptions())
                            ->preload(),
                        DatePicker::make('spent_on')
                            ->label('Date')
                            ->default(now())
                            ->required(),
                        Select::make('payment_method')
                            ->label('Payment method')
                            ->options([
                                'cash' => 'Cash',
                                'bank' => 'Bank transfer',
                                'cheque' => 'Cheque',
                                'card' => 'Card',
                                'credit' => 'Credit',
                                'other' => 'Other',
                            ])
                            ->live()
                            ->default('cash')
                            ->required(),
                        Select::make('payment_status')
                            ->label('Payment')
                            ->options([
                                'paid' => 'Paid fully',
                                'partial' => 'Part paid',
                                'cheque_pending' => 'Cheque given / pending',
                                'credit_due' => 'Credit / pay later',
                            ])
                            ->default('paid')
                            ->required(),
                        TextInput::make('cheque_number')
                            ->label('Cheque number')
                            ->placeholder('Only when payment method is cheque')
                            ->visible(fn (Get $get): bool => $get('payment_method') === 'cheque')
                            ->required(fn (Get $get): bool => $get('payment_method') === 'cheque'),
                        DatePicker::make('cheque_date')
                            ->label('Cheque date')
                            ->visible(fn (Get $get): bool => $get('payment_method') === 'cheque')
                            ->required(fn (Get $get): bool => $get('payment_method') === 'cheque'),
                        TextInput::make('paid_amount')
                            ->label('Paid amount now')
                            ->numeric()
                            ->prefix('LKR')
                            ->default(0)
                            ->live()
                            ->afterStateHydrated(function (TextInput $component, mixed $state): void {
                                if ($state === null || $state === '') {
                                    $component->state(0);
                                }
                            })
                            ->dehydrateStateUsing(fn (mixed $state): float => filled($state) ? (float) $state : 0),
                        DatePicker::make('due_on')->label('Due date'),
                        Select::make('reported_by')
                        ->label('Reported by')
                        ->options(fn () => static::employeeOptions())
                        ->searchable()
                        ->placeholder('Select employee'),
                        TextInput::make('description')
                            ->label('Short note')
                            ->placeholder('Optional'),
                    ])
                    ->action(function (array $data): void {
                        $paidAmount = $data['paid_amount'] ?? null;
                        $paymentStatus = $data['payment_status'];

                        if (($data['payment_method'] ?? null) === 'cheque' && $paymentStatus === 'paid') {
                            $paymentStatus = 'cheque_pending';
                        }

                        if (($data['payment_method'] ?? null) === 'credit') {
                            $paymentStatus = 'credit_due';
                        }

                        Expense::query()->create([
                            'business_id' => $data['business_id'],
                            'category' => $data['category'],
                            'expense_type' => 'variable',
                            'description' => $data['description'] ?? null,
                            'amount' => $data['amount'],
                            'payee' => $data['payee'] ?? null,
                            'payment_status' => $paymentStatus,
                            'payment_method' => $data['payment_method'] ?? null,
                            'cheque_number' => $data['cheque_number'] ?? null,
                            'cheque_date' => $data['cheque_date'] ?? null,
                            'paid_amount' => filled($paidAmount) ? $paidAmount : ($paymentStatus === 'paid' ? $data['amount'] : 0),
                            'due_on' => $data['due_on'] ?? null,
                            'reported_by' => $data['reported_by'] ?? null,
                            'allocation_bucket' => 'company_expense',
                            'spent_on' => $data['spent_on'],
                            'recurring' => false,
                        ]);

                        Notification::make()
                            ->title('Expense recorded')
                            ->body('This cost is now included in business health.')
                            ->success()
                            ->send();
                    }),
                Action::make('createGuidedFixedCosts')
                    ->label('Add guided fixed costs')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (): bool => (Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false))
                    ->form([
                        Select::make('business_id')
                            ->label('Business')
                            ->options(fn () => static::businessOptions())
                            ->default(fn () => Auth::user()?->defaultBusinessId())
                            ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $business = Business::query()->findOrFail($data['business_id']);
                        $created = 0;

                        FixedExpenseTemplate::query()
                            ->where('expense_type', 'fixed')
                            ->orderBy('sort_order')
                            ->get()
                            ->each(function (FixedExpenseTemplate $template) use ($business, &$created): void {
                            $expense = Expense::query()->firstOrCreate(
                                [
                                    'business_id' => $business->id,
                                    'suggested_key' => $template->key,
                                ],
                                [
                                    'department' => $template->department,
                                    'category' => $template->label,
                                    'expense_type' => 'fixed',
                                    'description' => $template->plain_hint,
                                    'amount' => 0,
                                    'spent_on' => now()->startOfMonth()->toDateString(),
                                    'recurring' => true,
                                ]
                            );

                            if ($expense->wasRecentlyCreated) {
                                $created++;
                            }
                        });

                        Notification::make()
                            ->title('Guided fixed costs added')
                            ->body($created === 0 ? 'The business already has the suggested fixed cost rows.' : "{$created} suggested fixed cost rows were added with zero amounts.")
                            ->success()
                            ->send();
                    }),
                Action::make('createGuidedVariableCosts')
                    ->label('Add guided variable costs')
                    ->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (): bool => (Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false))
                    ->form([
                        Select::make('business_id')
                            ->label('Business')
                            ->options(fn () => static::businessOptions())
                            ->default(fn () => Auth::user()?->defaultBusinessId())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $business = Business::query()->findOrFail($data['business_id']);
                        $created = 0;

                        FixedExpenseTemplate::query()
                            ->where('expense_type', 'variable')
                            ->orderBy('sort_order')
                            ->get()
                            ->each(function (FixedExpenseTemplate $template) use ($business, &$created): void {
                                $expense = Expense::query()->firstOrCreate(
                                    [
                                        'business_id' => $business->id,
                                        'suggested_key' => $template->key,
                                    ],
                                    [
                                        'department' => $template->department,
                                        'category' => $template->label,
                                        'expense_type' => 'variable',
                                        'description' => trim(($template->basis ? $template->basis.'. ' : '').$template->plain_hint),
                                        'amount' => 0,
                                        'spent_on' => now()->toDateString(),
                                        'recurring' => false,
                                    ]
                                );

                                if ($expense->wasRecentlyCreated) {
                                    $created++;
                                }
                            });

                        Notification::make()
                            ->title('Guided variable costs added')
                            ->body($created === 0 ? 'The business already has the suggested variable cost rows.' : "{$created} suggested variable cost rows were added with zero amounts.")
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('markSettled')
                        ->label('Settle')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Expense $record): bool => max((float) $record->amount - (float) $record->paid_amount, 0) > 0 || in_array($record->payment_status, ['partial', 'cheque_pending', 'credit_due'], true))
                        ->requiresConfirmation()
                        ->action(function (Expense $record): void {
                            $record->update([
                                'payment_status' => 'settled',
                                'paid_amount' => $record->amount,
                                'settled_on' => now()->toDateString(),
                            ]);

                            Notification::make()->title('Expense settled')->success()->send();
                        }),
                    Action::make('lock')
                        ->label('Lock')
                        ->icon('heroicon-o-lock-closed')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Lock this fixed expense?')
                        ->modalDescription('Lock it after the client confirms the amount. It will stay part of business health calculations and cannot be edited casually.')
                        ->visible(fn (Expense $record): bool => $record->expense_type === 'fixed' && blank($record->locked_at) && (Auth::user()?->isOwner() ?? false || Auth::user()?->isInternalAdmin() ?? false))
                        ->action(function (Expense $record): void {
                            $record->update([
                                'locked_at' => now(),
                                'locked_by' => Auth::id(),
                            ]);

                            Notification::make()->title('Fixed expense locked')->success()->send();
                        }),
                    Action::make('unlock')
                        ->label('Unlock')
                        ->icon('heroicon-o-lock-open')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (Expense $record): bool => filled($record->locked_at) && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false)))
                        ->action(function (Expense $record): void {
                            $record->update([
                                'locked_at' => null,
                                'locked_by' => null,
                            ]);

                            Notification::make()->title('Fixed expense unlocked')->warning()->send();
                        }),
                ]),
            ])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false) || ($user?->isInternalAdmin() ?? false));
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false) || ($user?->canAccessExpenseWork() ?? false));
    }

    private static function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! $user?->seesAllBusinesses(), fn (Builder $query) => $query->whereIn('id', static::expenseBusinessIds()))
            ->pluck('name', 'id')
            ->all();
    }

    private static function categoryOptions(): array
    {
        $businessIds = static::expenseBusinessIds();

        $defaultCategories = collect([
            'Fuel',
            'Courier',
            'Packing',
            'Lunch',
            'Repairs',
            'Electricity',
            'Water',
            'Rent',
            'Salary',
            'Bank charges',
            'Marketing',
            'Suppliers',
            'Other',
        ])->mapWithKeys(fn (string $category): array => [$category => $category])->all();

        $templateCategories = FixedExpenseTemplate::query()
            ->orderBy('label')
            ->pluck('label', 'label')
            ->all();

        $savedCategories = Expense::query()
            ->when($businessIds !== [], fn (Builder $query) => $query->whereIn('business_id', $businessIds))
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category', 'category')
            ->all();

        return $defaultCategories + $templateCategories + $savedCategories;
    }

    private static function payeeOptions(): array
    {
        $businessIds = static::expenseBusinessIds();

        return Expense::query()
            ->when($businessIds !== [], fn (Builder $query) => $query->whereIn('business_id', $businessIds))
            ->whereNotNull('payee')
            ->where('payee', '!=', '')
            ->distinct()
            ->orderBy('payee')
            ->pluck('payee', 'payee')
            ->all();
    }

    private static function departmentOptions(): array
    {
        $businessIds = static::expenseBusinessIds();

        $defaultDepartments = collect([
            'Operations',
            'Dispatch',
            'Packing',
            'Production',
            'Courier',
            'Customer Operations',
            'Finance',
            'Sales',
            'Admin',
            'Other',
        ])->mapWithKeys(fn (string $department): array => [$department => $department])->all();

        $templateDepartments = FixedExpenseTemplate::query()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->orderBy('department')
            ->pluck('department', 'department')
            ->all();

        $savedDepartments = Expense::query()
            ->when($businessIds !== [], fn (Builder $query) => $query->whereIn('business_id', $businessIds))
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->orderBy('department')
            ->pluck('department', 'department')
            ->all();

        return $defaultDepartments + $templateDepartments + $savedDepartments;
    }

    private static function employeeOptions(): array
    {
        $businessIds = static::expenseBusinessIds();

        return Employee::query()
            ->when($businessIds !== [], fn (Builder $query) => $query->whereIn('business_id', $businessIds))
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    private static function scopeToCurrentBusiness(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->seesAllBusinesses()) {
            return $query;
        }

        return $query->whereIn('business_id', static::expenseBusinessIds());
    }

    private static function expenseBusinessIds(): array
    {
        $user = Auth::user();

        if (! $user) {
            return [];
        }

        return $user->isStaff()
            ? $user->accessibleBusinessIdsForResponsibility('expense_recording')
            : $user->accessibleBusinessIds();
    }
}

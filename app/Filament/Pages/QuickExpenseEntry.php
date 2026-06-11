<?php

namespace App\Filament\Pages;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class QuickExpenseEntry extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'quick-expense-entry';
    protected static ?string $navigationGroup = 'Spend';
    protected static ?string $navigationLabel = 'Quick Spend';
    protected static ?string $navigationIcon = 'heroicon-o-bolt';
    protected static string $view = 'filament.pages.quick-expense-entry';

    public ?array $data = [];

    public Collection $recentExpenses;

    public function mount(): void
    {
        $this->form->fill([
            'business_id' => Auth::user()?->business_id,
            'payment_status' => 'paid',
            'paid_amount' => 0,
            'spent_on' => now()->toDateString(),
        ]);

        $this->refreshRecentExpenses();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('tip')
                    ->hiddenLabel()
                    ->content('Use this for day-to-day spend like fuel, packing, courier support, small repairs, meals, petty cash, and other variable costs. It stays fast on purpose.'),
                Select::make('business_id')
                    ->label('Business')
                    ->options(fn () => $this->businessOptions())
                    ->default(fn () => Auth::user()?->business_id)
                    ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                    ->required(),
                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->prefix('LKR')
                    ->live()
                    ->required(),
                Select::make('category')
                    ->label('What was it for?')
                    ->placeholder('Search or choose a repeat cost')
                    ->searchable()
                    ->options(fn () => $this->expenseCategoryOptions())
                    ->preload()
                    ->required(),
                Select::make('payee')
                    ->label('Paid to')
                    ->placeholder('Search supplier / payee')
                    ->searchable()
                    ->options(fn () => $this->payeeOptions())
                    ->preload(),
                DatePicker::make('spent_on')
                    ->label('Date')
                    ->default(now())
                    ->required(),
                Select::make('payment_method')
                    ->label('How it was paid')
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
                    ->label('Money status')
                    ->options([
                        'paid' => 'Paid',
                        'partial' => 'Part paid',
                        'cheque_pending' => 'Cheque waiting',
                        'credit_due' => 'Pay later',
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
                    ->label('Paid today')
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
                    ->hiddenLabel()
                    ->content(function (Get $get): string {
                        $amount = (float) ($get('amount') ?? 0);
                        $paid = (float) ($get('paid_amount') ?? 0);
                        $balance = max($amount - $paid, 0);

                        return $amount > 0 ? 'Balance to pay: LKR '.number_format($balance, 2) : 'Enter total and paid amount to see the balance.';
                    }),
                Select::make('reported_by')
                    ->label('Reported by')
                    ->options(fn () => $this->employeeOptions())
                    ->searchable()
                    ->placeholder('Select employee')
                    ->nullable(),
                TextInput::make('description')
                    ->label('Short note')
                    ->placeholder('Optional')
                    ->nullable(),
            ])
            ->statePath('data')
            ->columns(2);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $paidAmount = $data['paid_amount'] ?? null;
        $paymentStatus = $this->normalizePaymentStatus($data['payment_status'], $data['payment_method'] ?? null);

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
            'reported_by' => $data['reported_by'] ?? null,
            'allocation_bucket' => 'company_expense',
            'spent_on' => $data['spent_on'],
            'recurring' => false,
        ]);

        Notification::make()
            ->title('Spend recorded')
            ->body('This variable spend is now included in the business picture.')
            ->success()
            ->send();

        $this->form->fill([
            'business_id' => $data['business_id'] ?? Auth::user()?->business_id,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'paid_amount' => 0,
            'spent_on' => now()->toDateString(),
            'payee' => null,
            'cheque_number' => null,
            'cheque_date' => null,
            'reported_by' => null,
            'description' => null,
            'amount' => null,
            'category' => null,
        ]);

        $this->refreshRecentExpenses();
    }

    protected function getViewData(): array
    {
        return [
            'recentExpenses' => $this->recentExpenses,
        ];
    }

    private function refreshRecentExpenses(): void
    {
        $businessId = Auth::user()?->business_id;

        $this->recentExpenses = Expense::query()
            ->when($businessId, fn ($query) => $query->where('business_id', $businessId))
            ->latest('spent_on')
            ->latest('id')
            ->limit(8)
            ->get();
    }

    private function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! $user?->seesAllBusinesses(), fn ($query) => $query->whereKey($user?->business_id))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function expenseCategoryOptions(): array
    {
        $businessId = Auth::user()?->business_id;

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

        $savedCategories = Expense::query()
            ->when($businessId, fn ($query) => $query->where('business_id', $businessId))
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category', 'category')
            ->all();

        return $defaultCategories + $savedCategories;
    }

    private function payeeOptions(): array
    {
        $businessId = Auth::user()?->business_id;

        return Expense::query()
            ->when($businessId, fn ($query) => $query->where('business_id', $businessId))
            ->whereNotNull('payee')
            ->where('payee', '!=', '')
            ->distinct()
            ->orderBy('payee')
            ->pluck('payee', 'payee')
            ->all();
    }

    private function employeeOptions(): array
    {
        $businessId = Auth::user()?->business_id;

        return Employee::query()
            ->when($businessId, fn ($query) => $query->where('business_id', $businessId))
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    private function normalizePaymentStatus(string $paymentStatus, ?string $paymentMethod): string
    {
        if ($paymentMethod === 'cheque' && $paymentStatus === 'paid') {
            return 'cheque_pending';
        }

        if ($paymentMethod === 'credit') {
            return 'credit_due';
        }

        return $paymentStatus;
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
}

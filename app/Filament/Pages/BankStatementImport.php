<?php

namespace App\Filament\Pages;

use App\Domains\FinancialClarity\Services\BankStatementImportService;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class BankStatementImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'bank-statement-import';
    protected static ?string $navigationGroup = 'Money';
    protected static ?string $navigationLabel = 'Import Bank Statement';
    protected static ?int $navigationSort = 0;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static string $view = 'filament.pages.bank-statement-import';

    public ?array $data = [];

    public Collection $recentTransactions;

    public function mount(): void
    {
        $businessId = Auth::user()?->defaultBusinessId();

        $this->form->fill([
            'business_id' => $businessId,
        ]);

        $this->refreshRecentTransactions($businessId);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('tip')
                    ->hiddenLabel()
                    ->content('Upload a CSV or Excel bank statement. This is the bank-money source for HELOS. If the bank row is only moving money into Petty Cash or Store Cash, classify it later as Transfer. Record the actual fuel, meals, or petty-cash spend separately when the cash is used.'),
                Select::make('money_container')
                    ->label('Bank account / cash container')
                    ->placeholder('Current Account, Savings Account, Petty Cash...')
                    ->searchable()
                    ->options(fn () => static::moneyContainerOptions())
                    ->preload()
                    ->helperText('Use Store Cash / Cash Drawer when the settlement is being handled at the shop.')
                    ->required(),
                Select::make('business_id')
                    ->label('Business')
                    ->options(fn () => $this->businessOptions())
                    ->required(),
                FileUpload::make('statement_file')
                    ->label('CSV or Excel statement')
                    ->disk('public')
                    ->directory('bank-statements')
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required(),
            ])
            ->statePath('data')
            ->columns(1);
    }

    public function import(BankStatementImportService $importer): void
    {
        $data = $this->form->getState();
        $business = Business::query()->findOrFail($data['business_id']);
        $path = Storage::disk('public')->path($data['statement_file']);
        $result = $importer->import($business, $path, null, $data['money_container'] ?? null);

        Notification::make()
            ->title('Statement imported')
            ->body("Created {$result['created']} classified rows and {$result['reviewed']} review rows. Skipped {$result['skipped']} blank rows and {$result['duplicates']} duplicate rows.")
            ->success()
            ->send();

        $this->refreshRecentTransactions($business->id);
    }

    protected function getViewData(): array
    {
        return [
            'recentTransactions' => $this->recentTransactions,
        ];
    }

    private function refreshRecentTransactions(?int $businessId = null): void
    {
        $businessId ??= Auth::user()?->defaultBusinessId();

        $this->recentTransactions = BankTransaction::query()
            ->when($businessId, fn ($query) => $query->where('business_id', $businessId))
            ->latest('transaction_date')
            ->latest('id')
            ->limit(8)
            ->get();
    }

    private function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! $user?->seesAllBusinesses(), fn ($query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function moneyContainerOptions(): array
    {
        $user = Auth::user();

        $existing = BankTransaction::query()
            ->when(! $user?->seesAllBusinesses(), fn ($query) => $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []))
            ->whereNotNull('money_container')
            ->where('money_container', '!=', '')
            ->distinct()
            ->orderBy('money_container')
            ->pluck('money_container')
            ->all();

        return array_values(array_unique(array_merge(BankTransaction::treasuryContainerDefaults(), $existing)));
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || ($user?->canAccessBankExceptionWork() ?? false));
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false) || ($user?->canAccessBankExceptionWork() ?? false));
    }
}

<?php

namespace App\Filament\Pages;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Services\ClientOnboardingService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class NewClientWizard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'new-client';
    protected static ?string $navigationGroup = 'Admin';
    protected static ?string $navigationLabel = 'New Client';
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static string $view = 'filament.pages.new-client-wizard';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'currency' => 'LKR',
            'integration_status' => 'draft',
            'platform_admin' => false,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Wizard::make([
                Wizard\Step::make('Business')
                    ->schema([
                        Placeholder::make('business_tip')
                            ->hiddenLabel()
                            ->content('Create the business first. This becomes the financial container for the client. Then answer the quick readiness questions so HELOS can suggest the starting maturity.'),
                        TextInput::make('business_name')
                            ->label('Business name')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                if (blank($get('integration_name'))) {
                                    $set('integration_name', $state.' Stock App');
                                }

                                if (blank($get('stock_app_business_key'))) {
                                    $set('stock_app_business_key', Str::slug($state));
                                }
                            })
                            ->required(),
                        TextInput::make('industry')
                            ->placeholder('COD retail, manufacturing, service, distribution...')
                            ->required(),
                        TextInput::make('currency')
                            ->default('LKR')
                            ->maxLength(8)
                            ->required(),
                        Select::make('business_type')
                            ->label('Business type')
                            ->options(Business::businessTypeOptions())
                            ->default(Business::TYPE_SERVICE)
                            ->live()
                            ->required(),
                        Select::make('primary_business_type')
                            ->label('Primary type')
                            ->options([
                                Business::TYPE_SERVICE => 'Service',
                                Business::TYPE_TRADING => 'Trading',
                                Business::TYPE_MANUFACTURING => 'Manufacturing',
                            ])
                            ->visible(fn ($get): bool => $get('business_type') === Business::TYPE_HYBRID)
                            ->required(fn ($get): bool => $get('business_type') === Business::TYPE_HYBRID),
                        CheckboxList::make('secondary_business_types')
                            ->label('Secondary types / extensions')
                            ->options(Business::hybridExtensionOptions())
                            ->visible(fn ($get): bool => $get('business_type') === Business::TYPE_HYBRID)
                            ->columns(2)
                            ->required(fn ($get): bool => $get('business_type') === Business::TYPE_HYBRID),
                        Select::make('business_maturity')
                            ->label('Starting maturity')
                            ->options(Business::businessMaturityOptions())
                            ->default(Business::MATURITY_LEVEL_1)
                            ->required(),
                        TextInput::make('employee_seat_limit')
                            ->label('Employee accounts allowed')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('Enter the maximum number of staff logins this client should have.'),
                        Select::make('regular_sales')
                            ->label('Do they have regular sales?')
                            ->options([0 => 'No', 1 => 'Yes'])
                            ->default(0)
                            ->live()
                            ->required()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::syncSuggestedMaturity($get, $set)),
                        Select::make('cost_visibility')
                            ->label('Do they know product cost and margin?')
                            ->options([0 => 'No', 1 => 'Yes'])
                            ->default(0)
                            ->live()
                            ->required()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::syncSuggestedMaturity($get, $set)),
                        Select::make('stock_control')
                            ->label('Do they track stock clearly?')
                            ->options([0 => 'No', 1 => 'Yes'])
                            ->default(0)
                            ->live()
                            ->required()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::syncSuggestedMaturity($get, $set)),
                        Select::make('production_control')
                            ->label('Do they manage production or dispatch regularly?')
                            ->options([0 => 'No', 1 => 'Yes'])
                            ->default(0)
                            ->live()
                            ->required()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::syncSuggestedMaturity($get, $set)),
                        Select::make('cash_review')
                            ->label('Do they review cash and obligations regularly?')
                            ->options([0 => 'No', 1 => 'Yes'])
                            ->default(0)
                            ->live()
                            ->required()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::syncSuggestedMaturity($get, $set)),
                        Placeholder::make('maturity_tip')
                            ->hiddenLabel()
                            ->content(fn (Get $get): HtmlString => new HtmlString(static::maturityTip(
                                (string) ($get('business_type') ?? ''),
                                (string) ($get('business_maturity') ?? Business::MATURITY_LEVEL_1),
                                (bool) ($get('regular_sales') ?? false),
                                (bool) ($get('cost_visibility') ?? false),
                                (bool) ($get('stock_control') ?? false),
                                (bool) ($get('production_control') ?? false),
                                (bool) ($get('cash_review') ?? false),
                            ))),
                    ])
                    ->columns(2),
                Wizard\Step::make('Client login')
                    ->schema([
                        Placeholder::make('login_tip')
                            ->hiddenLabel()
                            ->content('Create the client portal owner login the business will use to sign in to HELOS.'),
                        TextInput::make('user_name')
                            ->label('Client owner name')
                            ->required(),
                        TextInput::make('user_email')
                            ->label('Client owner login email')
                            ->email()
                            ->required(),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required(),
                    ])
                    ->columns(2),
                Wizard\Step::make('Stock-app')
                    ->schema([
                        Placeholder::make('integration_tip')
                            ->hiddenLabel()
                            ->content('This step is optional. Fill it in only if the client already has a stock-app to connect. Leave it blank if they are new and do not have one yet.'),
                        TextInput::make('integration_name')
                            ->label('Connection name')
                            ->placeholder(fn (Get $get): string => filled($get('business_name'))
                                ? $get('business_name').' Stock App'
                                : 'Primary stock-app connection')
                            ->helperText('Auto-filled from the business name. Change only if this client has more than one stock-app connection.'),
                        TextInput::make('integration_base_url')
                            ->label('Stock-app base URL')
                            ->url()
                            ->placeholder('https://stock-app.example.com'),
                        Select::make('integration_status')
                            ->label('Connection status')
                            ->options([
                                'draft' => 'Draft',
                                'testing' => 'Testing',
                                'active' => 'Active',
                                'paused' => 'Paused',
                            ])
                            ->nullable(),
                        TextInput::make('stock_app_business_key')
                            ->label('Stock-app client code')
                            ->placeholder(fn (Get $get): string => filled($get('business_name'))
                                ? Str::slug((string) $get('business_name'))
                                : 'Auto-filled from business name')
                            ->helperText('Use the Code from the Stock App client screen. Example: if Stock App shows Code = horns, enter horns here.'),
                        TextInput::make('integration_webhook_secret')
                            ->label('Webhook secret')
                            ->password()
                            ->revealable()
                            ->helperText('Leave blank if the client has not set a secret yet.'),
                        TextInput::make('integration_shared_token')
                            ->label('Shared token')
                            ->password()
                            ->revealable()
                            ->helperText('Leave blank if the client uses signature-only access.'),
                        TextInput::make('integration_signature_secret')
                            ->label('Signature secret')
                            ->password()
                            ->revealable()
                            ->helperText('Leave blank if the client is not signing requests yet.'),
                        TextInput::make('integration_notes')
                            ->label('Notes')
                            ->placeholder('Optional'),
                    ])
                    ->columns(2),
            ])->submitAction(new \Illuminate\Support\HtmlString('<x-filament::button type="submit" icon="heroicon-o-check-circle">Create client</x-filament::button>')),
        ])->statePath('data');
    }

    public function save(ClientOnboardingService $onboarding): void
    {
        $data = $this->form->getState();
        $result = $onboarding->create($data);

        Notification::make()
            ->title('Client onboarded')
            ->body('Business, client portal login, and optional stock-app connection were created together.')
            ->success()
            ->send();

        $this->redirect('/admin/businesses/'.$result['business']->id.'/edit');
    }

    public static function canAccess(): bool
    {
        return Auth::check() && (Auth::user()?->seesAllBusinesses() ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    private static function syncSuggestedMaturity(Get $get, Set $set): void
    {
        $set('business_maturity', Business::suggestedMaturityFromSignals([
            'regular_sales' => (bool) ($get('regular_sales') ?? false),
            'cost_visibility' => (bool) ($get('cost_visibility') ?? false),
            'stock_control' => (bool) ($get('stock_control') ?? false),
            'production_control' => (bool) ($get('production_control') ?? false),
            'cash_review' => (bool) ($get('cash_review') ?? false),
        ], (string) ($get('business_type') ?? null)));
    }

    private static function maturityTip(
        string $businessType,
        string $maturity,
        bool $regularSales,
        bool $costVisibility,
        bool $stockControl,
        bool $productionControl,
        bool $cashReview,
    ): string {
        $suggested = Business::suggestedMaturityFromSignals([
            'regular_sales' => $regularSales,
            'cost_visibility' => $costVisibility,
            'stock_control' => $stockControl,
            'production_control' => $productionControl,
            'cash_review' => $cashReview,
        ], $businessType ?: null);

        $label = Business::businessMaturityOptions()[$suggested] ?? Business::businessMaturityOptions()[Business::MATURITY_LEVEL_1];
        $selected = Business::businessMaturityOptions()[$maturity] ?? Business::businessMaturityOptions()[Business::MATURITY_LEVEL_1];

        return '<div style="padding:.75rem;border:1px solid #e5e7eb;border-radius:.5rem;background:#fafafa">'
            .'<strong>Suggested starting maturity:</strong> '.e($label)
            .'<div style="margin-top:.35rem;color:#6b7280">Current selection: '.e($selected).'. You can change it if your conversation with the client points to a different level.</div>'
            .'</div>';
    }
}

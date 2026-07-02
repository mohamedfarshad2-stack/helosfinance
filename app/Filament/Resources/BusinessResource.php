<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ClientGroup;
use App\Domains\Shared\Models\Expense;
use App\Filament\Resources\BusinessResource\Pages;
use App\Filament\Resources\BusinessResource\RelationManagers\ClientUsersRelationManager;
use App\Filament\Resources\BusinessResource\RelationManagers\FixedExpensesRelationManager;
use App\Filament\Resources\BusinessResource\RelationManagers\VariableExpensesRelationManager;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class BusinessResource extends Resource
{
    protected static ?string $model = Business::class;
    protected static ?string $navigationGroup = 'Setup';
    protected static ?string $navigationLabel = 'Business Setup';
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Client profile')
                ->schema([
                    Select::make('client_group_id')
                        ->label('Client group')
                        ->relationship('clientGroup', 'name')
                        ->searchable()
                        ->preload()
                        ->default(fn () => Auth::user()?->client_group_id)
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Client group name')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('note')
                                ->label('Note')
                                ->maxLength(255),
                        ])
                        ->createOptionUsing(fn (array $data): int => ClientGroup::query()->create($data)->getKey())
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->helperText('Use this when one owner has multiple businesses under the same portal.'),
                    TextInput::make('name')->label('Business name')->required(),
                    TextInput::make('currency')->default('LKR')->required()->maxLength(8),
                    TextInput::make('industry')->placeholder('COD retail, manufacturing, service, food, etc.'),
                ])
                ->columns(2),
            Section::make('Business setup')
                ->schema([
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
                        ->label('Business maturity')
                        ->options(Business::businessMaturityOptions())
                        ->default(Business::MATURITY_LEVEL_1)
                        ->required(),
                    Select::make('settings.cod_order_source')
                        ->label('COD order source')
                        ->options(Business::codOrderSourceOptions())
                        ->default(Business::COD_SOURCE_STOCK_APP)
                        ->required()
                        ->visible(fn (): bool => Auth::user()?->isInternalAdmin() ?? false)
                        ->helperText('Super admin decides whether this business uses Stock App, HELOS internal COD orders, or no COD order workflow.'),
                    TextInput::make('settings.employee_seat_limit')
                        ->label('Employee accounts allowed')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->helperText('Enter the maximum number of staff logins this client should have.'),
                    TextInput::make('settings.integration_security.webhook_secret')
                        ->label('Stock-app webhook secret')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('Leave blank to keep the existing secret.'),
                    TextInput::make('settings.integration_security.shared_token')
                        ->label('Stock-app shared token')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('Leave blank to keep the existing token.'),
                    TextInput::make('settings.integration_security.signature_secret')
                        ->label('Stock-app signature secret')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('Leave blank to keep the existing secret.'),
                    Placeholder::make('activation_preview')
                        ->hiddenLabel()
                        ->content(fn ($get): HtmlString => new HtmlString(static::activationPreview(
                            (string) ($get('business_type') ?? ''),
                            (string) ($get('primary_business_type') ?? ''),
                            (array) ($get('secondary_business_types') ?? []),
                            (string) ($get('business_maturity') ?? ''),
                        ))),
                ])
                ->columns(2),
            Section::make('Setup progress')
                ->schema([
                    Select::make('onboarding_status')
                        ->label('Where are we now?')
                        ->options([
                            'setup' => 'Setup',
                            'fixed_expenses' => 'Fixed costs',
                            'sku_costing' => 'Product costing',
                            'ready' => 'Ready to read',
                        ])
                        ->live()
                        ->default('setup')
                        ->required(),
                    DatePicker::make('clarity_started_on')->label('When we started reading the business'),
                ])
                ->columns(2),
            Section::make('Setup step')
                ->visible(fn ($get): bool => $get('onboarding_status') === 'setup')
                ->schema([
                    Placeholder::make('setup_guidance')
                        ->hiddenLabel()
                        ->content(new HtmlString('<strong>Start here.</strong> Choose what kind of business this is. HELOS uses this to decide whether the owner needs COD orders, service billing, product costing, production, or only money review.')),
                ]),
            Section::make('Fixed cost setup')
                ->visible(fn ($get): bool => $get('onboarding_status') === 'fixed_expenses')
                ->schema([
                    Placeholder::make('fixed_expense_guidance')
                        ->hiddenLabel()
                        ->content(new HtmlString('<strong>Next job:</strong> create fixed cost rows first, fill the amounts with the client, then lock confirmed fixed costs. You can add, edit, and delete them in the table below. After that, add variable cost rows in the next section for courier, packing, fuel, commission, payment fees, and production-linked labor. Variable costs are not locked because they move with operations.')),
                    Placeholder::make('fixed_expense_rows')
                        ->label('Fixed costs added')
                        ->content(function (?Business $record): HtmlString {
                            if (! $record) {
                                return new HtmlString('Save this client first, then add guided fixed costs.');
                            }

                            $expenses = Expense::query()
                                ->where('business_id', $record->id)
                                ->where('expense_type', 'fixed')
                                ->orderBy('category')
                                ->get();

                            if ($expenses->isEmpty()) {
                                return new HtmlString('No fixed costs added yet. Use the Add guided fixed costs button above, or work in the fixed cost table below.');
                            }

                            $items = $expenses
                                ->map(function (Expense $expense): string {
                                    $amount = number_format((float) $expense->amount, 2);
                                    $lock = filled($expense->locked_at) ? ' locked' : ' not locked';

                                    return '<li><strong>'.e($expense->category).'</strong> - LKR '.$amount.' <span style="color:#6b7280">('.$lock.')</span></li>';
                                })
                                ->implode('');

                            return new HtmlString('<ul style="margin:0;padding-left:1rem">'.$items.'</ul><p style="margin-top:0.75rem;color:#6b7280">Use the table below to edit or delete rows.</p>');
                        }),
                ]),
            Section::make('Variable cost setup')
                ->visible(fn ($get): bool => $get('onboarding_status') === 'fixed_expenses')
                ->schema([
                    Placeholder::make('variable_expense_guidance')
                        ->hiddenLabel()
                        ->content(new HtmlString('<strong>Variable costs move with work.</strong> Use this section for courier, packing, fuel, commission, and production-linked labor. These are daily or per-order style costs, so they stay editable and do not get locked like fixed expenses.')),
                    Placeholder::make('variable_expense_rows')
                        ->label('Variable costs added')
                        ->content(function (?Business $record): HtmlString {
                            if (! $record) {
                                return new HtmlString('Save this client first, then add guided variable costs.');
                            }

                            $expenses = Expense::query()
                                ->where('business_id', $record->id)
                                ->where('expense_type', 'variable')
                                ->orderByDesc('spent_on')
                                ->get();

                            if ($expenses->isEmpty()) {
                                return new HtmlString('No variable costs added yet. Use the Add guided variable costs button above.');
                            }

                            $items = $expenses
                                ->map(function (Expense $expense): string {
                                    $amount = number_format((float) $expense->amount, 2);
                                    $date = optional($expense->spent_on)->format('Y-m-d');

                                    return '<li><strong>'.e($expense->category).'</strong> - LKR '.$amount.' <span style="color:#6b7280">('.e((string) $date).')</span></li>';
                                })
                                ->implode('');

                            return new HtmlString('<ul style="margin:0;padding-left:1rem">'.$items.'</ul><p style="margin-top:0.75rem;color:#6b7280">Use the table below to edit or delete rows.</p>');
                        }),
                ]),
            Section::make('Product costing')
                ->visible(fn ($get): bool => $get('onboarding_status') === 'sku_costing')
                ->schema([
                    Placeholder::make('sku_guidance')
                        ->hiddenLabel()
                        ->content(new HtmlString('<strong>Next job:</strong> add products, raw materials, labour work types, and product cost recipes. Product profit cannot be trusted until this step is complete.')),
                ]),
            Section::make('Ready for clarity')
                ->visible(fn ($get): bool => $get('onboarding_status') === 'ready')
                ->schema([
                    Placeholder::make('ready_guidance')
                        ->hiddenLabel()
                        ->content(new HtmlString('<strong>Use this only when setup is complete.</strong> Fixed costs, product costs, and operating assumptions should be entered before this client is treated as ready.')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('clientGroup.name')->label('Client group')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('industry'),
                Tables\Columns\TextColumn::make('business_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Business::businessTypeOptions()[$state] ?? 'Service'),
                Tables\Columns\TextColumn::make('business_maturity')
                    ->label('Maturity')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Business::businessMaturityOptions()[$state] ?? 'Level 1 - Survival'),
                Tables\Columns\TextColumn::make('cod_order_source')
                    ->label('COD source')
                    ->state(fn (Business $record): string => Business::codOrderSourceOptions()[$record->codOrderSource()] ?? 'Stock App integration')
                    ->badge()
                    ->color(fn (Business $record): string => match ($record->codOrderSource()) {
                        Business::COD_SOURCE_INTERNAL => 'success',
                        Business::COD_SOURCE_NONE => 'gray',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('currency')->badge(),
                Tables\Columns\TextColumn::make('onboarding_status')->label('Setup stage')->badge(),
                Tables\Columns\TextColumn::make('next_step')
                    ->label('Next action')
                    ->state(fn (Business $record): string => $record->nextSetupStep()),
                Tables\Columns\IconColumn::make('fixed_expenses_locked_at')->label('Fixed costs locked')->boolean()->state(fn (Business $record): bool => filled($record->fixed_expenses_locked_at)),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusinesses::route('/'),
            'create' => Pages\CreateBusiness::route('/create'),
            'edit' => Pages\EditBusiness::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            ClientUsersRelationManager::class,
            FixedExpensesRelationManager::class,
            VariableExpensesRelationManager::class,
        ];
    }

    public static function canCreate(): bool
    {
        $user = Auth::user();

        return (bool) (($user?->seesAllBusinesses() ?? false) || ($user?->isOwner() ?? false));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }

    public static function canAccess(): bool
    {
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }

    private static function scopeToCurrentBusiness(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->seesAllBusinesses()) {
            return $query;
        }

        return $query->whereIn('id', $user?->accessibleBusinessIds() ?? []);
    }

    private static function activationPreview(string $businessType, string $primaryType, array $secondaryTypes, string $maturity): string
    {
        $business = new Business([
            'business_type' => $businessType ?: Business::TYPE_SERVICE,
            'primary_business_type' => $primaryType ?: null,
            'secondary_business_types' => $secondaryTypes,
            'business_maturity' => $maturity ?: Business::MATURITY_LEVEL_1,
        ]);

        $summary = $business->activationSummary();

        $enabled = collect($summary['enabled'])->values()->all();
        $disabled = collect($summary['disabled'])->values()->all();

        return '<div style="display:grid;gap:.75rem">'
            .'<div><strong>Enabled modules</strong><ul style="margin:.35rem 0 0;padding-left:1rem">'.collect($enabled)->map(fn (string $label): string => '<li>'.e($label).'</li>')->implode('').'</ul></div>'
            .'<div><strong>Hidden for now</strong><ul style="margin:.35rem 0 0;padding-left:1rem">'.collect($disabled)->map(fn (string $label): string => '<li>'.e($label).'</li>')->implode('').'</ul></div>'
            .'</div>';
    }
}

<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\ServiceClient;
use App\Filament\Concerns\RespectsBusinessModules;
use App\Filament\Resources\ServiceBillingResource\Pages;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ServiceBillingResource extends Resource
{
    use RespectsBusinessModules;

    protected static ?string $model = ServiceBillingRecord::class;
    protected static ?string $navigationGroup = 'Sales & Work';
    protected static ?string $navigationLabel = 'Service Income';
    protected static ?string $navigationIcon = 'heroicon-o-credit-card';
    protected static ?int $navigationSort = 2;

    protected static function businessScopeResponsibilities(): array
    {
        return ['collections'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Service client')
                ->description('Use this for service businesses that collect registration fees and monthly subscription money.')
                ->schema([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => static::serviceBusinessOptions())
                        ->default(fn () => static::defaultServiceBusinessId())
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('service_client_id', null);
                            $set('client_name', null);
                        })
                        ->disabled(fn (): bool => ! static::canChooseServiceBusiness())
                        ->dehydrated()
                        ->required(),
                    Select::make('service_client_id')
                        ->label('Service client')
                        ->options(fn (Get $get): array => static::serviceClientOptions((int) ($get('business_id') ?: 0)))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        ->helperText('Select the service client once. HELOS will keep the billing rows tied to that client.')
                        ->createOptionForm(static::serviceClientQuickForm())
                        ->createOptionUsing(function (array $data, Get $get): int {
                            $businessId = (int) ($get('business_id') ?: static::defaultServiceBusinessId());

                            return ServiceClient::query()->create([
                                'business_id' => $businessId,
                                'name' => $data['name'],
                                'status' => $data['status'] ?? ServiceClient::STATUS_ACTIVE,
                                'billing_style' => $data['billing_style'] ?? ServiceClient::BILLING_FIXED_MONTHLY,
                                'default_monthly_amount' => $data['default_monthly_amount'] ?? 0,
                                'default_registration_fee' => $data['default_registration_fee'] ?? 0,
                                'default_due_day' => $data['default_due_day'] ?? null,
                                'notes' => $data['notes'] ?? null,
                            ])->id;
                        })
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                            $client = filled($state) ? ServiceClient::query()->find($state) : null;

                            if (! $client instanceof ServiceClient) {
                                return;
                            }

                            $set('client_name', $client->name);

                            if ((float) ($get('amount_due') ?? 0) <= 0) {
                                $amount = static::defaultAmountForClient($client, (string) ($get('billing_type') ?? ServiceBillingRecord::TYPE_SUBSCRIPTION));
                                $set('amount_due', $amount);
                            }

                            if (blank($get('due_on')) && filled($client->default_due_day)) {
                                $set('due_on', static::defaultDueDateForDay((int) $client->default_due_day));
                            }

                            if (blank($get('period_start'))) {
                                $set('period_start', now()->startOfMonth()->toDateString());
                            }

                            if (blank($get('period_end'))) {
                                $set('period_end', now()->endOfMonth()->toDateString());
                            }
                        }),
                    Hidden::make('client_name')
                        ->dehydrated(),
                    Select::make('billing_type')
                        ->label('What money is this?')
                        ->options(ServiceBillingRecord::billingTypeOptions())
                        ->default(ServiceBillingRecord::TYPE_SUBSCRIPTION)
                        ->live()
                        ->required(),
                    TextInput::make('amount_due')
                        ->label('Amount to collect')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->required()
                        ->live(),
                ])
                ->columns(2),
            Section::make('Month and payment')
                ->schema([
                    DatePicker::make('period_start')
                        ->label('Month starts'),
                    DatePicker::make('period_end')
                        ->label('Month ends'),
                    DatePicker::make('due_on')
                        ->label('Due date'),
                    Select::make('payment_status')
                        ->label('Payment status')
                        ->options(ServiceBillingRecord::paymentStatusOptions())
                        ->default('unpaid')
                        ->required(),
                    TextInput::make('paid_amount')
                        ->label('Paid amount')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->live()
                        ->required(),
                    Placeholder::make('balance_due')
                        ->label('Still to collect')
                        ->content(function ($get): string {
                            $amount = (float) ($get('amount_due') ?? 0);
                            $paid = (float) ($get('paid_amount') ?? 0);
                            $balance = max($amount - $paid, 0);

                            return 'LKR '.number_format($balance, 2);
                        }),
                    DatePicker::make('paid_on')
                        ->label('Paid date'),
                    Select::make('payment_method')
                        ->label('Payment method')
                        ->options([
                            'cash' => 'Cash',
                            'bank' => 'Bank transfer',
                            'cheque' => 'Cheque',
                            'card' => 'Card',
                            'other' => 'Other',
                        ])
                        ->placeholder('Not selected'),
                    TextInput::make('reference')
                        ->label('Reference')
                        ->placeholder('Receipt, cheque, bank ref'),
                    Textarea::make('note')
                        ->label('Note')
                        ->rows(3)
                        ->columnSpanFull(),
                    Actions::make([
                        \Filament\Forms\Components\Actions\Action::make('openServiceClients')
                            ->label('Open service clients')
                            ->icon('heroicon-o-users')
                            ->url(fn (): string => ServiceClientResource::getUrl('index'))
                            ->openUrlInNewTab(),
                    ])->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToServiceBusinesses($query))
            ->defaultSort('due_on')
            ->emptyStateHeading('No service income records yet')
            ->emptyStateDescription('Use this for service businesses that collect registration fees or monthly subscription money. Add the client, amount to collect, due date, and paid status.')
            ->columns([
                Tables\Columns\TextColumn::make('business.name')->label('Business')->toggleable(),
                Tables\Columns\TextColumn::make('serviceClient.name')
                    ->label('Service client')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $inner) use ($search): void {
                            $inner->where('client_name', 'like', "%{$search}%")
                                ->orWhereHas('serviceClient', fn (Builder $clientQuery): Builder => $clientQuery->where('name', 'like', "%{$search}%"));
                        });
                    }),
                Tables\Columns\TextColumn::make('billing_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ServiceBillingRecord::billingTypeOptions()[$state] ?? 'Other'),
                Tables\Columns\TextColumn::make('period_start')->label('From')->date()->toggleable(),
                Tables\Columns\TextColumn::make('period_end')->label('To')->date()->toggleable(),
                Tables\Columns\TextColumn::make('amount_due')->label('Due')->money('LKR')->sortable(),
                Tables\Columns\TextColumn::make('paid_amount')->label('Paid')->money('LKR')->sortable(),
                Tables\Columns\TextColumn::make('balance_due')
                    ->label('Still to collect')
                    ->state(fn (ServiceBillingRecord $record): float => $record->balanceDue())
                    ->money('LKR'),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partial' => 'warning',
                        'overdue' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('due_on')->label('Due date')->date()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('Payment status')
                    ->options(ServiceBillingRecord::paymentStatusOptions()),
                Tables\Filters\SelectFilter::make('billing_type')
                    ->label('Billing type')
                    ->options(ServiceBillingRecord::billingTypeOptions()),
            ])
            ->actions([
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ServiceBillingRecord $record): bool => $record->balanceDue() > 0 || $record->payment_status !== 'paid')
                    ->action(function (ServiceBillingRecord $record): void {
                        $record->update([
                            'paid_amount' => $record->amount_due,
                            'payment_status' => 'paid',
                            'paid_on' => now()->toDateString(),
                        ]);
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceBillingRecords::route('/'),
            'create' => Pages\CreateServiceBillingRecord::route('/create'),
            'edit' => Pages\EditServiceBillingRecord::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && static::hasAccessibleServiceBusiness();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false)
            || ($user?->isInternalAdmin() ?? false)
            || ($user?->canAccessCollectionsWork() ?? false))
            && static::hasAccessibleServiceBusiness();
    }

    private static function serviceBusinessOptions(): array
    {
        return static::businessOptionsMatching(fn (Business $business): bool => $business->supportsBusinessType(Business::TYPE_SERVICE));
    }

    private static function defaultServiceBusinessId(): ?int
    {
        $default = Auth::user()?->defaultBusinessId();
        $business = $default ? Business::query()->find($default) : null;

        if ($business?->supportsBusinessType(Business::TYPE_SERVICE)) {
            return $business->id;
        }

        return array_key_first(static::serviceBusinessOptions());
    }

    private static function hasAccessibleServiceBusiness(): bool
    {
        return static::hasAccessibleBusinessMatching(fn (Business $business): bool => $business->supportsBusinessType(Business::TYPE_SERVICE));
    }

    private static function canChooseServiceBusiness(): bool
    {
        $user = Auth::user();

        if ($user?->isInternalAdmin() ?? false) {
            return true;
        }

        return count(static::serviceBusinessOptions()) > 1;
    }

    private static function serviceClientOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return ServiceClient::query()
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function serviceClientQuickForm(): array
    {
        return [
            TextInput::make('name')
                ->label('Client name')
                ->required()
                ->maxLength(255),
            Select::make('status')
                ->label('Status')
                ->options(ServiceClient::statusOptions())
                ->default(ServiceClient::STATUS_ACTIVE)
                ->required(),
            Select::make('billing_style')
                ->label('Billing style')
                ->options(ServiceClient::billingStyleOptions())
                ->default(ServiceClient::BILLING_FIXED_MONTHLY)
                ->required(),
            TextInput::make('default_monthly_amount')
                ->label('Usual monthly amount')
                ->numeric()
                ->prefix('LKR')
                ->default(0),
            TextInput::make('default_registration_fee')
                ->label('Registration fee')
                ->numeric()
                ->prefix('LKR')
                ->default(0),
            TextInput::make('default_due_day')
                ->label('Usual due day')
                ->numeric()
                ->minValue(1)
                ->maxValue(31),
            Textarea::make('notes')
                ->label('Notes')
                ->rows(2),
        ];
    }

    private static function defaultAmountForClient(ServiceClient $client, string $billingType): float
    {
        if ($billingType === ServiceBillingRecord::TYPE_REGISTRATION && (float) $client->default_registration_fee > 0) {
            return (float) $client->default_registration_fee;
        }

        return (float) ($client->default_monthly_amount ?? 0);
    }

    private static function defaultDueDateForDay(int $day): string
    {
        $safeDay = max(1, min($day, (int) now()->endOfMonth()->day));

        return now()->startOfMonth()->addDays($safeDay - 1)->toDateString();
    }

    private static function scopeToServiceBusinesses(Builder $query): Builder
    {
        return static::scopeToAccessibleBusinessesMatching($query, fn (Business $business): bool => $business->supportsBusinessType(Business::TYPE_SERVICE));
    }
}

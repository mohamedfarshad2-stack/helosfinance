<?php

namespace App\Filament\Resources;

use App\Domains\FinancialClarity\Services\InternalCodOrderEventService;
use App\Domains\FinancialClarity\Services\CourierRateService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CodOrder;
use App\Domains\Shared\Models\CodOrderSource;
use App\Domains\Shared\Models\CourierRate;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Sku;
use App\Filament\Concerns\RespectsBusinessModules;
use App\Filament\Resources\CodOrderResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class CodOrderResource extends Resource
{
    use RespectsBusinessModules;

    protected static ?string $model = CodOrder::class;
    protected static ?string $navigationGroup = 'Sales & Work';
    protected static ?string $navigationLabel = 'COD Orders';
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Order')
                ->schema([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => static::businessOptions())
                        ->default(fn () => static::defaultBusinessId())
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->dehydrated()
                        ->live()
                        ->required(),
                    TextInput::make('order_number')
                        ->label('Internal reference')
                        ->placeholder('Optional; staff works from tracking number')
                        ->maxLength(255),
                    Select::make('sku_id')
                        ->label('Product')
                        ->options(fn (Get $get) => static::skuOptions((int) ($get('business_id') ?? 0)))
                        ->searchable()
                        ->preload(),
                    TextInput::make('size')
                        ->label('Size / variant')
                        ->maxLength(255),
                    TextInput::make('sale_amount')
                        ->label('Marked price / sale amount')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->required(),
                    Select::make('status')
                        ->options(CodOrder::statusOptions())
                        ->default(CodOrder::STATUS_NEW)
                        ->required()
                        ->live(),
                    DatePicker::make('order_date')
                        ->default(today())
                        ->required(),
                ])
                ->columns(2),
            Section::make('Customer')
                ->schema([
                    TextInput::make('customer_name')->required()->maxLength(255),
                    TextInput::make('customer_phone')->tel()->maxLength(50),
                    TextInput::make('customer_alt_phone')->label('Alternate phone')->tel()->maxLength(50),
                    TextInput::make('address')->maxLength(255),
                    TextInput::make('city')->maxLength(120),
                    TextInput::make('district')->maxLength(120),
                    Select::make('cod_order_source_id')
                        ->label('Order source')
                        ->options(fn (Get $get) => static::sourceOptions((int) ($get('business_id') ?? 0)))
                        ->searchable()
                        ->preload(),
                    Select::make('csr_employee_id')
                        ->label('CSR employee')
                        ->options(fn (Get $get) => static::employeeOptions((int) ($get('business_id') ?? 0)))
                        ->searchable()
                        ->preload(),
                    TextInput::make('call_attempts')
                        ->label('Call attempts')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                    Textarea::make('confirmation_remark')
                        ->label('Calling remark')
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('delivery_instruction')
                        ->label('Delivery instruction')
                        ->rows(2)
                        ->columnSpanFull(),
                    Textarea::make('note')->rows(3)->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Courier and status cost')
                ->description('Use the real courier and the real charge for this order. Different clients and couriers can carry different rates.')
                ->schema([
                    Select::make('courier_name')
                        ->label('Courier / delivery partner')
                        ->options(fn (Get $get) => app(CourierRateService::class)->optionsForBusiness((int) ($get('business_id') ?? 0)))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                            $rate = app(CourierRateService::class)->rateForBusiness((int) ($get('business_id') ?? 0), $state);

                            if (! $rate) {
                                return;
                            }

                            $set('delivery_charge', $rate->delivery_charge);
                            $set('return_charge', $rate->return_charge);
                            $set('resend_charge', $rate->resend_charge);
                        }),
                    TextInput::make('tracking_number')->maxLength(255),
                    Toggle::make('resend_from_stock')
                        ->label('Resend from already produced stock')
                        ->helperText('Use this when dispatching a replacement from stock. HELOS will not add product COGS again for this dispatch.'),
                    TextInput::make('delivery_charge')
                        ->label('Courier charge')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0),
                    TextInput::make('return_charge')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0),
                    TextInput::make('resend_charge')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0),
                    TextInput::make('collected_amount')
                        ->label('Collected amount')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0),
                    DatePicker::make('dispatched_on'),
                    DatePicker::make('delivered_on'),
                    DatePicker::make('returned_on'),
                    Select::make('return_reason')
                        ->options(CodOrder::returnReasonOptions())
                        ->searchable(),
                    Select::make('resend_reason')
                        ->options(CodOrder::resendReasonOptions())
                        ->searchable(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToInternalCodBusinesses($query))
            ->defaultSort('order_date', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(100)
            ->columns([
                Grid::make([
                    'default' => 2,
                    'md' => 4,
                    'xl' => 8,
                    '2xl' => 9,
                ])->schema([
                    Tables\Columns\TextColumn::make('order_date')
                        ->label('Date')
                        ->date('m/d')
                        ->sortable(),
                    TextInputColumn::make('order_number')
                        ->label('Order')
                        ->searchable()
                        ->afterStateUpdated(fn (CodOrder $record): CodOrder => $record),
                    TextInputColumn::make('customer_name')
                        ->label('Customer')
                        ->searchable(),
                    TextInputColumn::make('customer_phone')
                        ->label('Phone')
                        ->searchable(),
                    TextInputColumn::make('city')
                        ->label('City')
                        ->searchable(),
                    SelectColumn::make('sku_id')
                        ->label('SKU')
                        ->options(fn (CodOrder $record): array => static::skuOptions($record->business_id))
                        ->afterStateUpdated(fn (CodOrder $record): CodOrder => static::syncGridOrder($record)),
                    TextInputColumn::make('quantity')
                        ->label('Qty')
                        ->type('number')
                        ->step(1)
                        ->afterStateUpdated(fn (CodOrder $record): CodOrder => static::syncGridOrder($record)),
                    TextInputColumn::make('sale_amount')
                        ->label('Sale')
                        ->type('number')
                        ->step('0.01')
                        ->afterStateUpdated(fn (CodOrder $record): CodOrder => static::syncGridOrder($record)),
                    TextInputColumn::make('call_attempts')
                        ->label('Calls')
                        ->type('number')
                        ->step(1)
                        ->sortable(),
                    SelectColumn::make('status')
                        ->label('Status')
                        ->options(CodOrder::statusOptions())
                        ->selectablePlaceholder(false)
                        ->afterStateUpdated(fn (CodOrder $record, string $state): CodOrder => static::applyStatusFromGrid($record, $state)),
                    TextInputColumn::make('tracking_number')
                        ->label('Tracking')
                        ->searchable()
                        ->afterStateUpdated(fn (CodOrder $record, ?string $state): CodOrder => static::applyTrackingFromGrid($record, $state)),
                    SelectColumn::make('courier_name')
                        ->label('Courier')
                        ->options(fn (CodOrder $record): array => app(CourierRateService::class)->optionsForBusiness($record->business_id))
                        ->afterStateUpdated(fn (CodOrder $record, ?string $state): CodOrder => static::applyCourierFromGrid($record, $state)),
                    TextInputColumn::make('delivery_charge')
                        ->label('Delivery')
                        ->type('number')
                        ->step('0.01')
                        ->afterStateUpdated(fn (CodOrder $record): CodOrder => static::syncGridOrder($record)),
                    TextInputColumn::make('return_charge')
                        ->label('Return')
                        ->type('number')
                        ->step('0.01')
                        ->afterStateUpdated(fn (CodOrder $record): CodOrder => static::syncGridOrder($record)),
                    TextInputColumn::make('resend_charge')
                        ->label('Resend')
                        ->type('number')
                        ->step('0.01')
                        ->afterStateUpdated(fn (CodOrder $record): CodOrder => static::syncGridOrder($record)),
                    TextInputColumn::make('collected_amount')
                        ->label('Collected')
                        ->type('number')
                        ->step('0.01')
                        ->afterStateUpdated(fn (CodOrder $record): CodOrder => static::syncGridOrder($record)),
                    ToggleColumn::make('resend_from_stock')
                        ->label('Stock resend')
                        ->afterStateUpdated(fn (CodOrder $record): CodOrder => static::syncGridOrder($record)),
                    TextInputColumn::make('confirmation_remark')
                        ->label('Remark')
                        ->afterStateUpdated(fn (CodOrder $record): CodOrder => static::syncGridOrder($record)),
                ]),
            ])
            ->filters([
                SelectFilter::make('status')->options(CodOrder::statusOptions()),
                SelectFilter::make('courier_name')
                    ->label('Courier')
                    ->options(fn (): array => static::courierOptionsForAccessibleBusinesses()),
            ])
            ->actions([])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCodOrders::route('/'),
            'create' => Pages\CreateCodOrder::route('/create'),
            'edit' => Pages\EditCodOrder::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check()
            && (($user?->isOwner() ?? false)
                || ($user?->isInternalAdmin() ?? false)
                || ($user?->canAccessOperationalTasks() ?? false)
                || ($user?->canAccessFinanceOperations() ?? false))
            && static::hasInternalCodBusiness();
    }

    /**
     * @param  array<int, string>  $allowedFrom
     */
    private static function statusAction(string $status, string $label, string $icon, array $allowedFrom = []): Action
    {
        return Action::make('mark_'.$status)
            ->label($label)
            ->icon($icon)
            ->visible(fn (CodOrder $record): bool => $record->status !== $status
                && ($allowedFrom === [] || in_array($record->status, $allowedFrom, true)))
            ->action(function (CodOrder $record) use ($status): void {
                $record->update(array_filter([
                    'status' => $status,
                    'dispatched_on' => $status === CodOrder::STATUS_DISPATCHED ? today()->toDateString() : null,
                    'delivered_on' => $status === CodOrder::STATUS_DELIVERED ? today()->toDateString() : null,
                    'returned_on' => $status === CodOrder::STATUS_RETURNED ? today()->toDateString() : null,
                    'confirmed_at' => $status === CodOrder::STATUS_CONFIRMED && ! $record->confirmed_at ? now() : null,
                    'dispatched_at' => $status === CodOrder::STATUS_DISPATCHED && ! $record->dispatched_at ? now() : null,
                    'collected_amount' => $status === CodOrder::STATUS_DELIVERED && (float) $record->collected_amount <= 0 ? $record->totalPrice() : null,
                ], fn ($value): bool => $value !== null));

                app(InternalCodOrderEventService::class)->sync($record->fresh());
            });
    }

    private static function dispatchAction(): Action
    {
        return Action::make('mark_'.CodOrder::STATUS_DISPATCHED)
            ->label('Dispatch')
            ->icon('heroicon-o-truck')
            ->visible(fn (CodOrder $record): bool => in_array($record->status, [
                CodOrder::STATUS_CONFIRMED,
                CodOrder::STATUS_RETURNED,
                CodOrder::STATUS_RESENT,
            ], true))
            ->form([
                TextInput::make('tracking_number')
                    ->label('Tracking number')
                    ->required()
                    ->maxLength(255),
                Select::make('courier_name')
                    ->label('Courier / delivery partner')
                    ->options(fn (CodOrder $record): array => app(CourierRateService::class)->optionsForBusiness($record->business_id))
                    ->searchable()
                    ->preload(),
                TextInput::make('delivery_charge')
                    ->label('Courier charge')
                    ->numeric()
                    ->prefix('LKR')
                    ->default(0),
                Toggle::make('resend_from_stock')
                    ->label('Resend from already produced stock')
                    ->helperText('No product COGS will be added again for this dispatch.'),
            ])
            ->fillForm(fn (CodOrder $record): array => [
                'tracking_number' => $record->tracking_number,
                'courier_name' => $record->courier_name,
                'delivery_charge' => $record->delivery_charge,
                'resend_from_stock' => $record->resend_from_stock,
            ])
            ->action(function (CodOrder $record, array $data): void {
                $record->update([
                    'status' => CodOrder::STATUS_DISPATCHED,
                    'tracking_number' => $data['tracking_number'] ?? $record->tracking_number,
                    'courier_name' => $data['courier_name'] ?? $record->courier_name,
                    'delivery_charge' => $data['delivery_charge'] ?? $record->delivery_charge,
                    'resend_from_stock' => (bool) ($data['resend_from_stock'] ?? false),
                    'dispatched_on' => today()->toDateString(),
                    'dispatched_at' => $record->dispatched_at ?? now(),
                ]);

                app(InternalCodOrderEventService::class)->sync($record->fresh());
            });
    }

    private static function applyStatusFromGrid(CodOrder $record, string $state): CodOrder
    {
        if ($state === CodOrder::STATUS_DISPATCHED && blank($record->tracking_number)) {
            $record->forceFill(['status' => CodOrder::STATUS_CONFIRMED])->save();

            Notification::make()
                ->title('Add tracking number first')
                ->body('Courier cost starts when tracking is added.')
                ->warning()
                ->send();

            return $record->fresh();
        }

        $record->forceFill(array_filter([
            'status' => $state,
            'delivered_on' => $state === CodOrder::STATUS_DELIVERED ? today()->toDateString() : null,
            'returned_on' => $state === CodOrder::STATUS_RETURNED ? today()->toDateString() : null,
            'confirmed_at' => $state === CodOrder::STATUS_CONFIRMED && ! $record->confirmed_at ? now() : null,
            'dispatched_at' => $state === CodOrder::STATUS_DISPATCHED && ! $record->dispatched_at ? now() : null,
            'collected_amount' => $state === CodOrder::STATUS_DELIVERED && (float) $record->collected_amount <= 0 ? $record->totalPrice() : null,
        ], fn ($value): bool => $value !== null))->save();

        return static::syncGridOrder($record->fresh());
    }

    private static function applyTrackingFromGrid(CodOrder $record, ?string $state): CodOrder
    {
        $updates = ['tracking_number' => $state];

        if (filled($state) && in_array($record->status, [
            CodOrder::STATUS_CONFIRMED,
            CodOrder::STATUS_RETURNED,
            CodOrder::STATUS_RESENT,
            CodOrder::STATUS_COURIER_PENDING,
        ], true)) {
            $updates['status'] = CodOrder::STATUS_DISPATCHED;
            $updates['dispatched_on'] = today()->toDateString();
            $updates['dispatched_at'] = $record->dispatched_at ?? now();
        }

        $record->forceFill($updates)->save();

        return static::syncGridOrder($record->fresh());
    }

    private static function applyCourierFromGrid(CodOrder $record, ?string $state): CodOrder
    {
        app(CourierRateService::class)->applyToOrder($record, $state);

        return static::syncGridOrder($record->fresh());
    }

    private static function syncGridOrder(CodOrder $record): CodOrder
    {
        app(InternalCodOrderEventService::class)->sync($record);

        return $record->fresh();
    }

    private static function businessOptions(): array
    {
        return static::businessOptionsMatching(fn (Business $business): bool => $business->usesInternalCodOrders());
    }

    private static function defaultBusinessId(): ?int
    {
        $business = Business::query()->find(Auth::user()?->defaultBusinessId());

        if ($business?->usesInternalCodOrders()) {
            return $business->id;
        }

        return array_key_first(static::businessOptions());
    }

    private static function skuOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return Sku::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }

    private static function courierOptionsForAccessibleBusinesses(): array
    {
        return CourierRate::query()
            ->whereIn('business_id', Auth::user()?->accessibleBusinessIds() ?? [])
            ->where('active', true)
            ->orderBy('courier_name')
            ->pluck('courier_name', 'courier_name')
            ->all();
    }

    private static function sourceOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return CodOrderSource::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function employeeOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return Employee::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function hasInternalCodBusiness(): bool
    {
        return static::hasAccessibleBusinessMatching(fn (Business $business): bool => $business->usesInternalCodOrders());
    }

    private static function scopeToInternalCodBusinesses(Builder $query): Builder
    {
        return static::scopeToAccessibleBusinessesMatching($query, fn (Business $business): bool => $business->usesInternalCodOrders());
    }
}

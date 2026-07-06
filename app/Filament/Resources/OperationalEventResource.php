<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\OperationalEventResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class OperationalEventResource extends Resource
{
    protected static ?string $model = OperationalEvent::class;
    protected static ?string $navigationGroup = 'Sales & Work';
    protected static ?string $navigationLabel = 'Stock App Order Events';
    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('business_id')
                ->options(fn () => static::businessOptions())
                ->default(fn () => Auth::user()?->defaultBusinessId())
                ->required(),
            Select::make('sku_id')->options(Sku::query()->pluck('code', 'id'))->searchable(),
            Select::make('event_type')->options([
                OperationalEvent::ORDER_CREATED => 'Order created',
                OperationalEvent::ORDER_CONFIRMED => 'Order confirmed',
                OperationalEvent::TRACKING_NUMBER_ADDED => 'Tracking number added',
                OperationalEvent::WHOLESALE_PARCEL_SENT => 'Wholesale parcel sent by transport',
                OperationalEvent::ORDER_DELIVERED => 'Order delivered',
                OperationalEvent::ORDER_RETURNED => 'Order returned',
                OperationalEvent::ORDER_RESENT => 'Order resent',
                OperationalEvent::FAKE_ORDER_DETECTED => 'Fake order detected',
                OperationalEvent::SKU_PRODUCED => 'SKU produced',
                OperationalEvent::PRODUCTION_WASTE => 'Production waste',
                OperationalEvent::PAYOUT_GENERATED => 'Payout generated',
                OperationalEvent::EXPENSE_ADDED => 'Expense added',
            ])->required(),
            TextInput::make('external_id')->label('Stock-app ID'),
            TextInput::make('customer_name')
                ->label('Customer name')
                ->placeholder('Wholesale or service customer')
                ->visible(fn (Get $get): bool => in_array($get('event_type'), [OperationalEvent::WHOLESALE_PARCEL_SENT], true))
                ->dehydrated(),
            Select::make('channel')
                ->options([
                    'cod' => 'COD',
                    'wholesale' => 'Wholesale',
                    'cheque' => 'Wholesale - cheque',
                    'credit' => 'Wholesale - credit',
                    'service' => 'Service',
                ])
                ->searchable()
                ->default('cod'),
            TextInput::make('department'),
            TextInput::make('quantity')->numeric()->required(),
            TextInput::make('expected_sale_amount')
                ->label('Expected sale amount')
                ->numeric()
                ->prefix('LKR')
                ->helperText('Use this for wholesale cheque or credit orders before the money is collected.')
                ->visible(fn (Get $get): bool => in_array($get('event_type'), [
                    OperationalEvent::ORDER_CREATED,
                    OperationalEvent::ORDER_CONFIRMED,
                    OperationalEvent::TRACKING_NUMBER_ADDED,
                    OperationalEvent::WHOLESALE_PARCEL_SENT,
                ], true))
                ->dehydrated(),
            TextInput::make('transport_cost_amount')
                ->label('Transport cost')
                ->numeric()
                ->prefix('LKR')
                ->helperText('Use this when a wholesale parcel goes by transport without a tracking number.')
                ->visible(fn (Get $get): bool => $get('event_type') === OperationalEvent::WHOLESALE_PARCEL_SENT)
                ->dehydrated(),
            TextInput::make('customer_paid_amount')
                ->label('Customer paid now')
                ->numeric()
                ->prefix('LKR')
                ->helperText('Use this for cash, bank, cheque advance, or any partial amount already received.')
                ->visible(fn (Get $get): bool => $get('event_type') === OperationalEvent::WHOLESALE_PARCEL_SENT)
                ->dehydrated(),
            Select::make('customer_payment_method')
                ->label('Customer payment method')
                ->options([
                    'cash' => 'Cash',
                    'bank' => 'Bank transfer',
                    'cheque' => 'Cheque',
                    'credit' => 'Credit',
                    'mixed' => 'Mixed',
                ])
                ->visible(fn (Get $get): bool => $get('event_type') === OperationalEvent::WHOLESALE_PARCEL_SENT)
                ->dehydrated(),
            TextInput::make('cheque_number')
                ->label('Cheque number')
                ->visible(fn (Get $get): bool => $get('event_type') === OperationalEvent::WHOLESALE_PARCEL_SENT && $get('customer_payment_method') === 'cheque')
                ->dehydrated(),
            DatePicker::make('cheque_date')
                ->label('Cheque date')
                ->visible(fn (Get $get): bool => $get('event_type') === OperationalEvent::WHOLESALE_PARCEL_SENT && $get('customer_payment_method') === 'cheque')
                ->dehydrated(),
            DateTimePicker::make('payment_due_at')
                ->label('Balance due date')
                ->helperText('Use this when the remaining amount will be settled later.')
                ->visible(fn (Get $get): bool => $get('event_type') === OperationalEvent::WHOLESALE_PARCEL_SENT)
                ->dehydrated(),
            TextInput::make('revenue_amount')->numeric()->prefix('LKR'),
            TextInput::make('direct_cost_amount')->numeric()->prefix('LKR'),
            TextInput::make('leakage_amount')->numeric()->prefix('LKR'),
            TextInput::make('recovery_amount')->numeric()->prefix('LKR'),
            DateTimePicker::make('occurred_at')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('event_type', [
                OperationalEvent::ORDER_CREATED,
                OperationalEvent::ORDER_CONFIRMED,
            ]))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('When')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->description(fn (OperationalEvent $record): string => $record->business?->name ?? ''),
                Tables\Columns\TextColumn::make('event_type')
                    ->label('Stage')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::eventTypeLabel($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('tracking_reference')
                    ->label('Tracking / order')
                    ->state(fn (OperationalEvent $record): string => static::trackingReference($record))
                    ->description(fn (OperationalEvent $record): string => static::orderReferenceLine($record))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $inner) use ($search): void {
                            $inner
                                ->where('external_id', 'like', "%{$search}%")
                                ->orWhere('payload->tracking_number', 'like', "%{$search}%")
                                ->orWhere('payload->order_number', 'like', "%{$search}%");
                        });
                    })
                    ->wrap(),
                Tables\Columns\TextColumn::make('sku_summary')
                    ->label('Product')
                    ->state(fn (OperationalEvent $record): string => $record->sku?->code ?: 'No SKU linked')
                    ->description(fn (OperationalEvent $record): string => static::productDetails($record))
                    ->wrap(),
                Tables\Columns\TextColumn::make('money_effect')
                    ->label('Money effect')
                    ->state(fn (OperationalEvent $record): string => static::moneyHeadline($record))
                    ->description(fn (OperationalEvent $record): string => static::moneyBreakdown($record))
                    ->wrap(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationalEvents::route('/'),
            'create' => Pages\CreateOperationalEvent::route('/create'),
            'edit' => Pages\EditOperationalEvent::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()?->isOwner() ?? false);
    }

    public static function canAccess(): bool
    {
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }

    private static function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function eventTypeLabel(string $state): string
    {
        return match ($state) {
            OperationalEvent::TRACKING_NUMBER_ADDED => 'Tracking added',
            OperationalEvent::WHOLESALE_PARCEL_SENT => 'Wholesale sent',
            OperationalEvent::ORDER_DELIVERED => 'Delivered',
            OperationalEvent::ORDER_RETURNED => 'Returned',
            OperationalEvent::ORDER_RESENT => 'Resent',
            OperationalEvent::FAKE_ORDER_DETECTED => 'Fake order',
            OperationalEvent::SKU_PRODUCED => 'Produced',
            OperationalEvent::PRODUCTION_WASTE => 'Waste',
            OperationalEvent::PAYOUT_GENERATED => 'Payout',
            OperationalEvent::EXPENSE_ADDED => 'Expense',
            default => Str::headline(str_replace('_', ' ', $state)),
        };
    }

    private static function trackingReference(OperationalEvent $record): string
    {
        $payload = $record->payload ?? [];

        if (filled($payload['tracking_number'] ?? null)) {
            return (string) $payload['tracking_number'];
        }

        if (filled($payload['order_number'] ?? null)) {
            return 'Order '.(string) $payload['order_number'];
        }

        if (filled($record->external_id)) {
            return static::shortExternalId((string) $record->external_id);
        }

        return 'No tracking yet';
    }

    private static function orderReferenceLine(OperationalEvent $record): string
    {
        $payload = $record->payload ?? [];
        $parts = [];

        if (filled($payload['customer_name'] ?? null)) {
            $parts[] = (string) $payload['customer_name'];
        }

        if (filled($payload['customer_phone'] ?? null)) {
            $parts[] = (string) $payload['customer_phone'];
        }

        if (filled($record->external_id)) {
            $parts[] = 'Ref: '.static::shortExternalId((string) $record->external_id);
        }

        return implode(' | ', $parts);
    }

    private static function productDetails(OperationalEvent $record): string
    {
        $payload = $record->payload ?? [];
        $parts = [];

        if (filled($payload['product_name'] ?? null)) {
            $parts[] = (string) $payload['product_name'];
        }

        $quantity = max((int) ($record->quantity ?? 0), 0);
        if ($quantity > 0) {
            $parts[] = 'Qty '.$quantity;
        }

        if (filled($record->channel)) {
            $parts[] = Str::headline((string) $record->channel);
        }

        return implode(' | ', $parts);
    }

    private static function moneyHeadline(OperationalEvent $record): string
    {
        $revenue = (float) ($record->revenue_amount ?? 0);
        $directCost = (float) ($record->direct_cost_amount ?? 0);
        $leakage = (float) ($record->leakage_amount ?? 0);

        if ($revenue > 0) {
            return 'Revenue LKR '.number_format($revenue, 2);
        }

        if ($directCost > 0 || $leakage > 0) {
            return 'Cost / leakage event';
        }

        return 'No money impact yet';
    }

    private static function moneyBreakdown(OperationalEvent $record): string
    {
        return 'Cost LKR '.number_format((float) ($record->direct_cost_amount ?? 0), 2)
            .' | Leakage LKR '.number_format((float) ($record->leakage_amount ?? 0), 2);
    }

    private static function shortExternalId(string $externalId): string
    {
        if (preg_match('/cod-order-(\d+)/', $externalId, $matches) === 1) {
            return 'COD-'.$matches[1];
        }

        return Str::limit($externalId, 36);
    }
}

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
            ->defaultSort('occurred_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('occurred_at')->dateTime()->sortable(),
            Tables\Columns\TextColumn::make('event_type')->badge()->searchable(),
            Tables\Columns\TextColumn::make('business.name')->label('Client / Business')->searchable(),
            Tables\Columns\TextColumn::make('source')->badge()->toggleable(),
            Tables\Columns\TextColumn::make('external_id')->label('Stock-app ID')->toggleable(),
            Tables\Columns\TextColumn::make('sku.code')->label('SKU'),
            Tables\Columns\TextColumn::make('channel'),
            Tables\Columns\TextColumn::make('revenue_amount')->money('LKR'),
            Tables\Columns\TextColumn::make('direct_cost_amount')->money('LKR'),
            Tables\Columns\TextColumn::make('leakage_amount')->money('LKR')->color('danger'),
        ])->actions([Tables\Actions\EditAction::make()]);
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
}

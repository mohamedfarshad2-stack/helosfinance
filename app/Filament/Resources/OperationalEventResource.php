<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\OperationalEventResource\Pages;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class OperationalEventResource extends Resource
{
    protected static ?string $model = OperationalEvent::class;
    protected static ?string $navigationGroup = 'Operations Impact';
    protected static ?string $navigationLabel = 'Operational Events';
    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('business_id')->options(Business::query()->pluck('name', 'id'))->required(),
            Select::make('sku_id')->options(Sku::query()->pluck('code', 'id'))->searchable(),
            Select::make('event_type')->options([
                OperationalEvent::ORDER_CREATED => 'Order created',
                OperationalEvent::ORDER_CONFIRMED => 'Order confirmed',
                OperationalEvent::TRACKING_NUMBER_ADDED => 'Tracking number added',
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
            TextInput::make('channel'),
            TextInput::make('department'),
            TextInput::make('quantity')->numeric()->required(),
            TextInput::make('revenue_amount')->numeric()->prefix('LKR'),
            TextInput::make('direct_cost_amount')->numeric()->prefix('LKR'),
            TextInput::make('leakage_amount')->numeric()->prefix('LKR'),
            TextInput::make('recovery_amount')->numeric()->prefix('LKR'),
            DateTimePicker::make('occurred_at')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('occurred_at', 'desc')->columns([
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
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }

    public static function canAccess(): bool
    {
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }
}

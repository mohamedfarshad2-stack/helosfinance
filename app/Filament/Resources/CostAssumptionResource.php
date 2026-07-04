<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CostAssumption;
use App\Filament\Resources\CostAssumptionResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CostAssumptionResource extends Resource
{
    protected static ?string $model = CostAssumption::class;
    protected static ?string $navigationGroup = 'Setup';
    protected static ?string $navigationLabel = 'Other Cost Rules';
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    public static function getPluralModelLabel(): string
    {
        return 'Other Cost Rules';
    }

    public static function getModelLabel(): string
    {
        return 'Other Cost Rule';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('business_id')
                ->label('Client / business')
                ->options(Business::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->helperText('Choose which client this rule belongs to. Use Courier Charges for delivery, return, and resend rates.'),
            TextInput::make('key')
                ->label('Rule key')
                ->required()
                ->placeholder('verification_cost')
                ->helperText('Use this only for non-courier rules. Delivery, return, and resend charges now belong in Courier Charges.'),
            TextInput::make('label')
                ->label('Rule name')
                ->required()
                ->helperText('Use a plain name the owner can understand, like Verification cost.'),
            TextInput::make('amount')
                ->label('Amount per rule')
                ->numeric()
                ->required()
                ->prefix('LKR')
                ->helperText('Set the normal amount for this one non-courier rule.'),
            Select::make('behavior')
                ->options([
                    'per_event' => 'Per event',
                    'monthly' => 'Monthly',
                    'manual' => 'Manual',
                ])
                ->required(),
            Select::make('event_type')
                ->label('Event type')
                ->options([
                    'order_delivered' => 'Order delivered',
                    'order_returned' => 'Order returned',
                    'order_resent' => 'Order resent',
                    'fake_order_detected' => 'Fake order detected',
                    'verification' => 'Verification',
                    'cod_collection' => 'COD collection',
                    'recovery' => 'Recovery',
                    'other' => 'Other',
                ])
                ->searchable()
                ->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('business.name')
                ->label('Client')
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('label')
                ->label('Rule')
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('amount')
                ->money('LKR')
                ->sortable(),
            Tables\Columns\TextColumn::make('behavior')->badge(),
            Tables\Columns\TextColumn::make('event_type')
                ->badge()
                ->toggleable(),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCostAssumptions::route('/'),
            'create' => Pages\CreateCostAssumption::route('/create'),
            'edit' => Pages\EditCostAssumption::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return Auth::check() && (Auth::user()?->isInternalAdmin() ?? false);
    }
}

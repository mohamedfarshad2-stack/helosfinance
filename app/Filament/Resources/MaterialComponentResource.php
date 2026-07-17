<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use App\Filament\Concerns\RespectsBusinessModules;
use App\Filament\Resources\MaterialComponentResource\Pages;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MaterialComponentResource extends Resource
{
    use RespectsBusinessModules;

    protected static ?string $model = MaterialComponent::class;

    protected static ?string $navigationGroup = 'Products & Production';

    protected static ?string $navigationLabel = 'Raw Material Components';

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('business_id')
                ->label('Business')
                ->options(fn () => static::businessOptions())
                ->default(fn () => Auth::user()?->defaultBusinessId())
                ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                ->required(),
            TextInput::make('name')
                ->label('Component name')
                ->placeholder('DSI sheet')
                ->required()
                ->maxLength(255),
            TextInput::make('purchase_unit')
                ->label('Buying unit')
                ->placeholder('sheet, roll, bottle')
                ->default('unit')
                ->required()
                ->maxLength(50),
            TextInput::make('consumption_unit')
                ->label('Used as')
                ->placeholder('piece, use, meter')
                ->default('piece')
                ->required()
                ->maxLength(50),
            TextInput::make('units_per_purchase_unit')
                ->label('Usable pieces per buying unit')
                ->numeric()
                ->default(1)
                ->required()
                ->helperText('Example: one DSI sheet cuts 12 pieces.'),
            TextInput::make('waste_percent')
                ->label('Expected waste %')
                ->numeric()
                ->default(0)
                ->required(),
            TextInput::make('latest_purchase_unit_cost')
                ->label('Latest buying unit cost')
                ->numeric()
                ->default(0)
                ->prefix('LKR')
                ->required(),
            Checkbox::make('active')
                ->default(true),
            Textarea::make('note')
                ->label('Note')
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))
            ->defaultSort('name')
            ->emptyStateHeading('No raw material components yet')
            ->emptyStateDescription('Add buying units such as DSI sheet, glue, rexine, or thread. HELOS converts each buying unit into cost per used piece including waste.')
            ->columns([
                Tables\Columns\TextColumn::make('business.name')->label('Business')->toggleable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('purchase_unit')->label('Buying unit'),
                Tables\Columns\TextColumn::make('consumption_unit')->label('Used as'),
                Tables\Columns\TextColumn::make('units_per_purchase_unit')->label('Yield'),
                Tables\Columns\TextColumn::make('waste_percent')->label('Waste %'),
                Tables\Columns\TextColumn::make('latest_purchase_unit_cost')->label('Buying cost')->money('LKR'),
                Tables\Columns\TextColumn::make('cost_per_consumption_unit')
                    ->label('Cost / used unit')
                    ->state(fn (MaterialComponent $record): float => $record->costPerConsumptionUnit())
                    ->money('LKR'),
                Tables\Columns\TextColumn::make('stock_balance')
                    ->label('Stock balance')
                    ->state(fn (MaterialComponent $record): string => number_format($record->stockBalance(), 2).' '.$record->consumption_unit)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('stock_value')
                    ->label('Stock value')
                    ->state(fn (MaterialComponent $record): float => $record->stockValue())
                    ->money('LKR')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaterialComponents::route('/'),
            'create' => Pages\CreateMaterialComponent::route('/create'),
            'edit' => Pages\EditMaterialComponent::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false)
            || ($user?->isInternalAdmin() ?? false)
            || ($user?->canAccessMaterialWork() ?? false)
            || ($user?->canAccessSupervisorReview() ?? false))
            && static::currentBusinessSupportsManufacturingSetup();
    }

    private static function businessOptions(): array
    {
        $user = Auth::user();

        return static::businessOptionsMatching(fn (Business $business): bool => $business->supportsManufacturingSetup());
    }

    private static function scopeToCurrentBusiness(Builder $query): Builder
    {
        $user = Auth::user();

        return static::scopeToAccessibleBusinessesMatching($query, fn (Business $business): bool => $business->supportsManufacturingSetup());
    }

    private static function currentBusinessSupportsManufacturingSetup(): bool
    {
        return static::hasAccessibleBusinessMatching(fn (Business $business): bool => $business->supportsManufacturingSetup());
    }
}

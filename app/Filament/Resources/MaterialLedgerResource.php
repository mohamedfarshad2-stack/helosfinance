<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use App\Domains\Shared\Models\Sku;
use App\Filament\Concerns\RespectsBusinessModules;
use App\Filament\Resources\MaterialLedgerResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MaterialLedgerResource extends Resource
{
    use RespectsBusinessModules;

    protected static ?string $model = MaterialLedgerEntry::class;
    protected static ?string $navigationGroup = 'Products & Production';
    protected static ?string $navigationLabel = 'Material Stock';
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('business_id')
                ->options(fn () => static::businessOptions())
                ->default(fn () => Auth::user()?->defaultBusinessId())
                ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                ->required(),
            Select::make('sku_id')
                ->label('SKU')
                ->options(fn (Get $get) => static::skuOptions((int) ($get('business_id') ?? 0)))
                ->searchable()
                ->visible(fn (Get $get): bool => in_array($get('entry_type'), ['consumption', 'waste'], true))
                ->helperText('Select SKU only when this material was used or wasted for a specific product.')
                ->nullable(),
            Select::make('entry_type')
                ->label('Movement type')
                ->options([
                    'purchase' => 'Bought material',
                    'consumption' => 'Used in production',
                    'waste' => 'Wasted / damaged',
                    'adjustment' => 'Stock correction',
                ])
                ->default('purchase')
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    if ($state === 'purchase') {
                        $set('sku_id', null);
                    }
                })
                ->required(),
            Select::make('component_name')
                ->label('Component')
                ->placeholder('Search or choose a component')
                ->searchable()
                ->options(fn (Get $get) => static::componentOptions((int) ($get('business_id') ?? 0)))
                ->preload()
                ->live()
                ->createOptionForm(static::materialComponentForm())
                ->createOptionUsing(fn (array $data, Get $get): string => static::createMaterialComponent((int) ($get('business_id') ?? 0), $data)->name)
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                    $component = static::materialComponent((int) ($get('business_id') ?? 0), (string) $state);

                    if (! $component) {
                        return;
                    }

                    $set('material_component_id', $component->id);

                    if ((float) ($get('unit_cost') ?? 0) <= 0) {
                        $set('unit_cost', (float) $component->latest_purchase_unit_cost);
                    }
                })
                ->required(),
            TextInput::make('material_component_id')
                ->hidden()
                ->dehydrated(),
            TextInput::make('quantity')
                ->label(fn (Get $get): string => match ($get('entry_type')) {
                    'purchase' => 'Quantity bought',
                    'consumption' => 'Quantity used',
                    'waste' => 'Quantity wasted',
                    'adjustment' => 'Quantity adjustment',
                    default => 'Quantity',
                })
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set): mixed => $set('total_cost', round((float) ($get('quantity') ?? 0) * (float) ($get('unit_cost') ?? 0), 2)))
                ->helperText(fn (Get $get): string => match ($get('entry_type')) {
                    'purchase' => 'Use the buying unit. Example: 10 DSI sheets.',
                    'consumption' => 'Use the component used-as unit. Example: 80 pieces used.',
                    'waste' => 'Use the component used-as unit. Example: 3 damaged pieces.',
                    'adjustment' => 'Use positive or negative quantity to correct stock.',
                    default => '',
                })
                ->required(),
            TextInput::make('unit_cost')
                ->label(fn (Get $get): string => match ($get('entry_type')) {
                    'purchase' => 'Cost per buying unit',
                    default => 'Cost per used unit',
                })
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set): mixed => $set('total_cost', round((float) ($get('quantity') ?? 0) * (float) ($get('unit_cost') ?? 0), 2)))
                ->required()
                ->prefix('LKR'),
            TextInput::make('total_cost')
                ->label(fn (Get $get): string => match ($get('entry_type')) {
                    'purchase' => 'Total purchase cost',
                    'consumption' => 'Material used value',
                    'waste' => 'Wasted material value',
                    default => 'Total value',
                })
                ->numeric()
                ->required()
                ->prefix('LKR'),
            DatePicker::make('occurred_on')->required(),
            TextInput::make('note')->label('Note')->placeholder('Optional'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))
            ->defaultSort('occurred_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('occurred_on')->date()->sortable(),
                Tables\Columns\TextColumn::make('entry_type')->badge(),
                Tables\Columns\TextColumn::make('sku.code')->label('SKU')->toggleable(),
                Tables\Columns\TextColumn::make('component_name')->searchable(),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('unit_cost')->money('LKR'),
                Tables\Columns\TextColumn::make('total_cost')->money('LKR'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaterialLedgerEntries::route('/'),
            'create' => Pages\CreateMaterialLedgerEntry::route('/create'),
            'edit' => Pages\EditMaterialLedgerEntry::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return Auth::check()
            && ((Auth::user()?->isOwner() ?? false) || ($user?->canAccessOperationalTasks() ?? false) || ($user?->canAccessFinanceOperations() ?? false))
            && static::currentBusinessSupportsManufacturing();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check()
            && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false) || ($user?->canAccessOperationalTasks() ?? false) || ($user?->canAccessFinanceOperations() ?? false))
            && static::currentBusinessSupportsManufacturing();
    }

    private static function businessOptions(): array
    {
        $user = Auth::user();

        return static::businessOptionsMatching(fn (Business $business): bool => $business->supportsProductionTracking());
    }

    private static function skuOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return Sku::query()
            ->where('business_id', $businessId)
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }

    private static function componentOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return static::defaultComponentOptions();
        }

        $saved = MaterialLedgerEntry::query()
            ->where('business_id', $businessId)
            ->whereNotNull('component_name')
            ->where('component_name', '!=', '')
            ->distinct()
            ->orderBy('component_name')
            ->pluck('component_name', 'component_name')
            ->all();

        $components = MaterialComponent::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();

        return static::defaultComponentOptions() + $components + $saved;
    }

    private static function defaultComponentOptions(): array
    {
        return collect([
            'Rubber sheet',
            'Cloth',
            'Thread',
            'Carton',
            'Label',
            'Tape',
            'Packaging',
            'Packing material',
            'Other',
        ])->mapWithKeys(fn (string $component): array => [$component => $component])->all();
    }

    private static function scopeToCurrentBusiness(Builder $query): Builder
    {
        $user = Auth::user();

        return static::scopeToAccessibleBusinessesMatching($query, fn (Business $business): bool => $business->supportsProductionTracking());
    }

    private static function currentBusinessSupportsManufacturing(): bool
    {
        $user = Auth::user();

        return static::hasAccessibleBusinessMatching(fn (Business $business): bool => $business->supportsProductionTracking());
    }

    private static function materialComponentForm(): array
    {
        return [
            TextInput::make('name')
                ->label('Component name')
                ->placeholder('DSI sheet')
                ->required()
                ->maxLength(255),
            TextInput::make('purchase_unit')
                ->label('Buying unit')
                ->default('sheet')
                ->required()
                ->maxLength(50),
            TextInput::make('consumption_unit')
                ->label('Used as')
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
        ];
    }

    private static function createMaterialComponent(int $businessId, array $data): MaterialComponent
    {
        $businessId = $businessId > 0 ? $businessId : (int) Auth::user()?->defaultBusinessId();

        return MaterialComponent::query()->updateOrCreate(
            [
                'business_id' => $businessId,
                'name' => trim((string) $data['name']),
            ],
            [
                'purchase_unit' => trim((string) ($data['purchase_unit'] ?? 'unit')) ?: 'unit',
                'consumption_unit' => trim((string) ($data['consumption_unit'] ?? 'piece')) ?: 'piece',
                'units_per_purchase_unit' => (float) ($data['units_per_purchase_unit'] ?? 1),
                'waste_percent' => (float) ($data['waste_percent'] ?? 0),
                'latest_purchase_unit_cost' => (float) ($data['latest_purchase_unit_cost'] ?? 0),
                'active' => true,
            ],
        );
    }

    private static function materialComponent(int $businessId, string $name): ?MaterialComponent
    {
        if ($businessId <= 0 || blank($name)) {
            return null;
        }

        return MaterialComponent::query()
            ->where('business_id', $businessId)
            ->where('name', $name)
            ->first();
    }
}

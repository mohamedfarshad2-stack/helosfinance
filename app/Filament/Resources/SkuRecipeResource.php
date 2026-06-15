<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use App\Domains\Shared\Models\ProductionWorkStep;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use App\Filament\Resources\SkuRecipeResource\Pages;
use Filament\Forms\Components\Checkbox;
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

class SkuRecipeResource extends Resource
{
    protected static ?string $model = SkuRecipeItem::class;
    protected static ?string $navigationGroup = 'Manufacturing';
    protected static ?string $navigationLabel = 'SKU Recipe / BOM';
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?int $navigationSort = 4;

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
                ->required(),
            Select::make('line_type')
                ->label('Line type')
                ->options([
                    SkuRecipeItem::TYPE_RAW_MATERIAL => 'Raw material',
                    SkuRecipeItem::TYPE_LABOR => 'Manpower / labour',
                ])
                ->default(SkuRecipeItem::TYPE_RAW_MATERIAL)
                ->live()
                ->helperText('Use raw material for inputs like rubber, cloth, thread, and packaging. Use labor when this line represents paid worker effort per finished product.')
                ->required(),
            Select::make('material_component_id')
                ->label('Material component')
                ->placeholder('Search or create material component')
                ->options(fn (Get $get) => static::materialComponentOptions((int) ($get('business_id') ?? 0)))
                ->searchable()
                ->preload()
                ->live()
                ->visible(fn (Get $get): bool => ($get('line_type') ?? SkuRecipeItem::TYPE_RAW_MATERIAL) === SkuRecipeItem::TYPE_RAW_MATERIAL)
                ->required(fn (Get $get): bool => ($get('line_type') ?? SkuRecipeItem::TYPE_RAW_MATERIAL) === SkuRecipeItem::TYPE_RAW_MATERIAL)
                ->createOptionForm(static::materialComponentForm())
                ->createOptionUsing(fn (array $data, Get $get): int => static::createMaterialComponent((int) ($get('business_id') ?? 0), $data)->id)
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    $component = MaterialComponent::query()->find((int) $state);

                    if (! $component) {
                        return;
                    }

                    $set('component_name', $component->name);
                    $set('quantity_per_unit', 1);
                    $set('unit_cost', $component->costPerConsumptionUnit());
                }),
            Select::make('component_name')
                ->label('Work step')
                ->placeholder('Search or choose approved work step')
                ->searchable()
                ->options(fn (Get $get) => static::workStepNameOptions((int) ($get('business_id') ?? 0)))
                ->preload()
                ->visible(fn (Get $get): bool => ($get('line_type') ?? SkuRecipeItem::TYPE_RAW_MATERIAL) === SkuRecipeItem::TYPE_LABOR)
                ->required(fn (Get $get): bool => ($get('line_type') ?? SkuRecipeItem::TYPE_RAW_MATERIAL) === SkuRecipeItem::TYPE_LABOR)
                ->createOptionForm(static::workStepForm())
                ->createOptionUsing(fn (array $data, Get $get): string => static::createWorkStep((int) ($get('business_id') ?? 0), $data)->name)
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                    $step = static::workStep((int) ($get('business_id') ?? 0), (string) $state);

                    if (! $step) {
                        return;
                    }

                    $set('production_work_step_id', $step->id);

                    if ((float) ($get('unit_cost') ?? 0) <= 0) {
                        $set('unit_cost', (float) $step->unit_cost);
                    }
                })
                ->dehydrated(fn (Get $get): bool => ($get('line_type') ?? SkuRecipeItem::TYPE_RAW_MATERIAL) === SkuRecipeItem::TYPE_LABOR),
            TextInput::make('production_work_step_id')
                ->hidden()
                ->dehydrated(),
            TextInput::make('quantity_per_unit')->label('Qty per unit')->numeric()->required()->prefix(''),
            TextInput::make('unit_cost')->label('Unit cost')->numeric()->required()->prefix('LKR'),
            Checkbox::make('active')->default(true),
            TextInput::make('note')->label('Note')->placeholder('Optional'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))
            ->defaultSort('component_name')
            ->columns([
                Tables\Columns\TextColumn::make('business.name')->label('Business')->toggleable(),
                Tables\Columns\TextColumn::make('sku.code')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('line_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        SkuRecipeItem::TYPE_LABOR => 'Labor',
                        default => 'Raw material',
                    }),
                Tables\Columns\TextColumn::make('component_name')->label('Component')->searchable(),
                Tables\Columns\TextColumn::make('materialComponent.name')->label('Material master')->toggleable(),
                Tables\Columns\TextColumn::make('productionWorkStep.name')->label('Work step master')->toggleable(),
                Tables\Columns\TextColumn::make('quantity_per_unit')->label('Qty / unit'),
                Tables\Columns\TextColumn::make('unit_cost')->money('LKR'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSkuRecipeItems::route('/'),
            'create' => Pages\CreateSkuRecipeItem::route('/create'),
            'edit' => Pages\EditSkuRecipeItem::route('/{record}/edit'),
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
            ->when(! $user?->seesAllBusinesses(), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
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

        $saved = SkuRecipeItem::query()
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

    private static function materialComponentOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return MaterialComponent::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (MaterialComponent $component): array => [
                $component->id => $component->name.' - LKR '.number_format($component->costPerConsumptionUnit(), 2).' / '.$component->consumption_unit,
            ])
            ->all();
    }

    private static function workStepNameOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return ProductionWorkStep::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
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

        if ($user?->seesAllBusinesses()) {
            return $query;
        }

        return $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []);
    }

    private static function currentBusinessSupportsManufacturing(): bool
    {
        $user = Auth::user();

        if ($user?->seesAllBusinesses()) {
            return true;
        }

        return $user?->business?->supportsProductionTracking() ?? false;
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

    private static function workStepForm(): array
    {
        return [
            TextInput::make('name')
                ->label('Work name')
                ->placeholder('Bottom labour')
                ->required()
                ->maxLength(255),
            TextInput::make('unit_cost')
                ->label('Rate per unit')
                ->numeric()
                ->default(0)
                ->prefix('LKR')
                ->required(),
        ];
    }

    private static function createWorkStep(int $businessId, array $data): ProductionWorkStep
    {
        $businessId = $businessId > 0 ? $businessId : (int) Auth::user()?->defaultBusinessId();

        return ProductionWorkStep::query()->updateOrCreate(
            [
                'business_id' => $businessId,
                'name' => trim((string) $data['name']),
            ],
            [
                'unit_cost' => (float) ($data['unit_cost'] ?? 0),
                'active' => true,
            ],
        );
    }

    private static function workStep(int $businessId, string $name): ?ProductionWorkStep
    {
        if ($businessId <= 0 || blank($name)) {
            return null;
        }

        return ProductionWorkStep::query()
            ->where('business_id', $businessId)
            ->where('name', $name)
            ->first();
    }
}

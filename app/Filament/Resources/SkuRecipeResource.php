<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use App\Filament\Resources\SkuRecipeResource\Pages;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
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
                    SkuRecipeItem::TYPE_LABOR => 'Manpower / labor',
                ])
                ->default(SkuRecipeItem::TYPE_RAW_MATERIAL)
                ->helperText('Use raw material for inputs like rubber, cloth, thread, and packaging. Use labor when this line represents paid worker effort per finished product.')
                ->required(),
            Select::make('component_name')
                ->label('Component / worker step')
                ->placeholder('Search or choose a component / step')
                ->searchable()
                ->options(fn (Get $get) => static::componentOptions((int) ($get('business_id') ?? 0)))
                ->preload()
                ->required(),
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

        return static::defaultComponentOptions() + $saved;
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
            'Labour',
            'Labor step',
            'Cutting',
            'Stitching',
            'Assembly',
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
}

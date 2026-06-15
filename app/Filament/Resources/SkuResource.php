<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\SkuResource\Pages;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SkuResource extends Resource
{
    protected static ?string $model = Sku::class;
    protected static ?string $navigationGroup = 'Manufacturing';
    protected static ?string $navigationLabel = 'Products / SKUs';
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Product')
                ->schema([
                    Select::make('business_id')
                        ->options(fn () => static::businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->dehydrated()
                        ->required(),
                    TextInput::make('code')
                        ->label('SKU code')
                        ->required()
                        ->rule(fn (Get $get, ?Sku $record): \Illuminate\Validation\Rules\Unique => Rule::unique('skus', 'code')
                            ->where('business_id', (int) ($get('business_id') ?? Auth::user()?->defaultBusinessId()))
                            ->ignore($record?->id))
                        ->maxLength(80),
                    TextInput::make('name')
                        ->label('Product name')
                        ->required()
                        ->maxLength(160),
                    TextInput::make('expected_sale_price')
                        ->label('Expected sale price')
                        ->numeric()
                        ->default(0)
                        ->required()
                        ->prefix('LKR'),
                    Checkbox::make('active')->default(true),
                ])
                ->columns(2),
            Section::make('Fallback costs')
                ->description('Use these only when this product does not have a SKU Recipe yet. When a recipe exists, HELOS uses recipe materials and labor first.')
                ->collapsed()
                ->schema([
                    TextInput::make('material_cost')->numeric()->default(0)->required()->prefix('LKR'),
                    TextInput::make('packaging_cost')->numeric()->default(0)->required()->prefix('LKR'),
                    TextInput::make('labor_rate')->numeric()->default(0)->required()->prefix('LKR'),
                    TextInput::make('finishing_cost')->numeric()->default(0)->required()->prefix('LKR'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->searchable(),
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('expected_sale_price')->money('LKR'),
            Tables\Columns\TextColumn::make('production_cost')->label('Estimated cost')->state(fn (Sku $record) => $record->productionCostPerUnit())->money('LKR'),
            Tables\Columns\TextColumn::make('recipe_items_count')
                ->label('Recipe lines')
                ->counts('recipeItems'),
        ])->actions([Tables\Actions\EditAction::make()])->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSkus::route('/'),
            'create' => Pages\CreateSku::route('/create'),
            'edit' => Pages\EditSku::route('/{record}/edit'),
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

    private static function currentBusinessSupportsSkuManagement(): bool
    {
        $user = Auth::user();

        if ($user?->seesAllBusinesses()) {
            return true;
        }

        return $user?->business?->supportsSkuManagement() ?? false;
    }

    private static function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn ($query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}

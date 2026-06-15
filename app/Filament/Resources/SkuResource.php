<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\SkuResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class SkuResource extends Resource
{
    protected static ?string $model = Sku::class;
    protected static ?string $navigationGroup = 'Manufacturing';
    protected static ?string $navigationLabel = 'SKU Profitability';
    protected static ?string $navigationIcon = 'heroicon-o-cube';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('business_id')
                ->options(fn () => static::businessOptions())
                ->default(fn () => Auth::user()?->defaultBusinessId())
                ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                ->required(),
            TextInput::make('code')->required()->maxLength(80),
            TextInput::make('name')->required()->maxLength(160),
            TextInput::make('material_cost')->numeric()->required()->prefix('LKR'),
            TextInput::make('packaging_cost')->numeric()->required()->prefix('LKR'),
            TextInput::make('labor_rate')->numeric()->required()->prefix('LKR'),
            TextInput::make('finishing_cost')->numeric()->required()->prefix('LKR'),
            TextInput::make('expected_sale_price')->numeric()->required()->prefix('LKR'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->searchable(),
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('expected_sale_price')->money('LKR'),
            Tables\Columns\TextColumn::make('production_cost')->label('Estimated cost')->state(fn (Sku $record) => $record->productionCostPerUnit())->money('LKR'),
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

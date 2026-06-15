<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\MaterialLedgerResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MaterialLedgerResource extends Resource
{
    protected static ?string $model = MaterialLedgerEntry::class;
    protected static ?string $navigationGroup = 'Manufacturing';
    protected static ?string $navigationLabel = 'Material Ledger';
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

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
                ->nullable(),
            Select::make('entry_type')
                ->options([
                    'purchase' => 'Purchase',
                    'consumption' => 'Consumption',
                    'adjustment' => 'Adjustment',
                    'waste' => 'Waste',
                ])
                ->required(),
            Select::make('component_name')
                ->label('Component')
                ->placeholder('Search or choose a component')
                ->searchable()
                ->options(fn (Get $get) => static::componentOptions((int) ($get('business_id') ?? 0)))
                ->preload()
                ->required(),
            TextInput::make('quantity')->numeric()->required(),
            TextInput::make('unit_cost')->label('Unit cost')->numeric()->required()->prefix('LKR'),
            TextInput::make('total_cost')->label('Total cost')->numeric()->required()->prefix('LKR'),
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

        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || ($user?->canAccessOperationalTasks() ?? false) || ($user?->canAccessFinanceOperations() ?? false));
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false) || ($user?->canAccessOperationalTasks() ?? false) || ($user?->canAccessFinanceOperations() ?? false));
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

        $saved = MaterialLedgerEntry::query()
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

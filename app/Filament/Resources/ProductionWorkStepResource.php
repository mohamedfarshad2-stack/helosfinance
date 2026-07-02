<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ProductionWorkStep;
use App\Filament\Concerns\RespectsBusinessModules;
use App\Filament\Resources\ProductionWorkStepResource\Pages;
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

class ProductionWorkStepResource extends Resource
{
    use RespectsBusinessModules;

    protected static ?string $model = ProductionWorkStep::class;

    protected static ?string $navigationGroup = 'Products & Production';

    protected static ?string $navigationLabel = 'Labour Work Types';

    protected static ?string $modelLabel = 'work step';

    protected static ?string $pluralModelLabel = 'work steps';

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('business_id')
                ->label('Business')
                ->options(fn () => static::businessOptions())
                ->default(fn () => Auth::user()?->defaultBusinessId())
                ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                ->dehydrated()
                ->required(),
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
            Checkbox::make('active')
                ->default(true),
            Textarea::make('note')
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))
            ->defaultSort('name')
            ->emptyStateHeading('No labour work types yet')
            ->emptyStateDescription('Create work types such as bottom labour, stitching labour, top making, cutting, finishing, and packing with their rate per unit.')
            ->columns([
                Tables\Columns\TextColumn::make('business.name')->label('Business')->toggleable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('unit_cost')->label('Rate / unit')->money('LKR'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductionWorkSteps::route('/'),
            'create' => Pages\CreateProductionWorkStep::route('/create'),
            'edit' => Pages\EditProductionWorkStep::route('/{record}/edit'),
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
            || ($user?->canAccessOperationalTasks() ?? false)
            || ($user?->canAccessFinanceOperations() ?? false))
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

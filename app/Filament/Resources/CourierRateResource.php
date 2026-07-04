<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CourierRate;
use App\Filament\Concerns\RespectsBusinessModules;
use App\Filament\Resources\CourierRateResource\Pages;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class CourierRateResource extends Resource
{
    use RespectsBusinessModules;

    protected static ?string $model = CourierRate::class;
    protected static ?string $navigationGroup = 'Setup';
    protected static ?string $navigationLabel = 'Courier Charges';
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?int $navigationSort = 12;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Courier charge setup')
                ->description('Set the normal delivery, return, and resend charge for each courier once. Staff only chooses the courier on COD orders, so they do not type charges order by order.')
                ->schema([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => static::businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->dehydrated()
                        ->required(),
                    TextInput::make('courier_name')
                        ->label('Courier')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Example: Domex, Prompt Xpress, or your transport partner name.'),
                    TextInput::make('delivery_charge')
                        ->label('Delivery charge')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->required()
                        ->helperText('Charge when the parcel gets delivered.'),
                    TextInput::make('return_charge')
                        ->label('Return charge')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->required()
                        ->helperText('Charge when the parcel comes back returned.'),
                    TextInput::make('resend_charge')
                        ->label('Resend charge')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->required()
                        ->helperText('Charge when you send a replacement or resend parcel.'),
                    Toggle::make('active')
                        ->default(true)
                        ->helperText('Keep only the courier rates you still use as active.'),
                    Textarea::make('note')
                        ->helperText('Optional note for special situations. No need to repeat these numbers in Other Cost Rules.')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToAccessibleBusinesses($query))
            ->defaultSort('courier_name')
            ->emptyStateHeading('No courier charges set yet')
            ->emptyStateDescription('Add each courier once with delivery, return, and resend charges. Staff can then select the courier on COD orders without typing charges every time.')
            ->columns([
                Tables\Columns\TextColumn::make('business.name')->label('Business')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('courier_name')->label('Courier')->searchable(),
                Tables\Columns\TextColumn::make('delivery_charge')->money('LKR'),
                Tables\Columns\TextColumn::make('return_charge')->money('LKR'),
                Tables\Columns\TextColumn::make('resend_charge')->money('LKR'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourierRates::route('/'),
            'create' => Pages\CreateCourierRate::route('/create'),
            'edit' => Pages\EditCourierRate::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }

    private static function businessOptions(): array
    {
        return static::businessOptionsMatching(fn (Business $business): bool => true);
    }

    private static function scopeToAccessibleBusinesses(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->seesAllBusinesses()) {
            return $query;
        }

        return $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []);
    }
}

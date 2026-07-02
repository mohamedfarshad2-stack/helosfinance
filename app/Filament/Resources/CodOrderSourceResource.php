<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CodOrderSource;
use App\Filament\Concerns\RespectsBusinessModules;
use App\Filament\Resources\CodOrderSourceResource\Pages;
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

class CodOrderSourceResource extends Resource
{
    use RespectsBusinessModules;

    protected static ?string $model = CodOrderSource::class;
    protected static ?string $navigationGroup = 'Setup';
    protected static ?string $navigationLabel = 'COD Order Sources';
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?int $navigationSort = 13;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Order source')
                ->description('Create the channels staff can select on COD orders, such as WhatsApp, Facebook, TikTok, or third-party.')
                ->schema([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => static::businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->dehydrated()
                        ->required(),
                    TextInput::make('name')
                        ->label('Source name')
                        ->placeholder('WhatsApp')
                        ->required()
                        ->maxLength(255),
                    Toggle::make('active')
                        ->default(true),
                    Textarea::make('note')
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
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('business.name')->label('Business')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('name')->label('Source')->searchable(),
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
            'index' => Pages\ListCodOrderSources::route('/'),
            'create' => Pages\CreateCodOrderSource::route('/create'),
            'edit' => Pages\EditCodOrderSource::route('/{record}/edit'),
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
        return static::businessOptionsMatching(fn (Business $business): bool => $business->usesInternalCodOrders());
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

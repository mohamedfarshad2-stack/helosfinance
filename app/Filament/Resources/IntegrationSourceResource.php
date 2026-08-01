<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Filament\Resources\IntegrationSourceResource\Pages;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class IntegrationSourceResource extends Resource
{
    protected static ?string $model = IntegrationSource::class;

    protected static ?string $navigationGroup = 'Setup';

    protected static ?string $navigationLabel = 'Stock App Link';

    protected static ?string $navigationIcon = 'heroicon-o-link';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Connection')
                ->schema([
                    Select::make('business_id')
                        ->options(fn () => static::businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->required(),
                    TextInput::make('name')->required(),
                    Select::make('type')->options(['stock_app' => 'stock-app', 'csv' => 'CSV fallback'])->required(),
                    TextInput::make('base_url')
                        ->label('Stock-app base URL')
                        ->url()
                        ->helperText('The Stock App site address. Example: https://codreturnslanka.lk'),
                    TextInput::make('settings.stock_app_business_key')
                        ->label('Stock-app client code')
                        ->helperText('Use the Code from the Stock App client screen. Example: horns. HELOS uses this to match orders to this business.'),
                    TextInput::make('settings.stock_app_client_id')
                        ->label('Stock-app client ID')
                        ->numeric()
                        ->helperText('Use the numeric clientId from the Stock App orders URL. Example: 1 for Horns England.'),
                    Select::make('status')->options(['draft' => 'Draft', 'testing' => 'Testing', 'active' => 'Active', 'paused' => 'Paused'])->required(),
                ])
                ->columns(2),
            Section::make('Security')
                ->schema([
                    TextInput::make('webhook_secret')
                        ->label('Webhook secret')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('Leave blank to keep the existing secret.'),
                    TextInput::make('shared_token')
                        ->label('Shared token')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('Leave blank to keep the existing token.'),
                    TextInput::make('signature_secret')
                        ->label('Signature secret')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('Leave blank to keep the existing secret.'),
                    TextInput::make('settings.stock_app_admin_email')
                        ->label('Stock-app read-only email')
                        ->email()
                        ->helperText('HELOS uses this only to compare live pending queue numbers against synced rows.'),
                    TextInput::make('settings.stock_app_admin_password')
                        ->label('Stock-app read-only password')
                        ->password()
                        ->revealable()
                        ->helperText('Used only for live pending queue verification inside HELOS.'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $user = Auth::user();

                return $query->when(
                    ! ($user?->seesAllBusinesses() ?? false),
                    fn (Builder $query) => $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? [])
                );
            })
            ->columns([
                Tables\Columns\TextColumn::make('business.name')->label('Business')->toggleable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('base_url'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('last_synced_at')->dateTime(),
                Tables\Columns\TextColumn::make('last_webhook_received_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_successful_sync_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_health_status')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('failed_sync_attempts')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('duplicate_event_count')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('rejected_event_count')->badge()->toggleable(isToggledHiddenByDefault: true),
            ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntegrationSources::route('/'),
            'create' => Pages\CreateIntegrationSource::route('/create'),
            'edit' => Pages\EditIntegrationSource::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }

    public static function canAccess(): bool
    {
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }

    private static function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}

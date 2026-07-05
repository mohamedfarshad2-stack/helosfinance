<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ServiceClient;
use App\Filament\Concerns\RespectsBusinessModules;
use App\Filament\Resources\ServiceClientResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ServiceClientResource extends Resource
{
    use RespectsBusinessModules;

    protected static ?string $model = ServiceClient::class;
    protected static ?string $navigationGroup = 'Sales & Work';
    protected static ?string $navigationLabel = 'Service Clients';
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Service client truth')
                ->description('Keep one clean service-client record here. Monthly billing rows can then reuse it without typing the same name again.')
                ->schema([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => static::serviceBusinessOptions())
                        ->default(fn () => static::defaultServiceBusinessId())
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->required(),
                    TextInput::make('name')
                        ->label('Client name')
                        ->placeholder('Client or company name')
                        ->required()
                        ->maxLength(255),
                    Select::make('status')
                        ->label('Client status')
                        ->options(ServiceClient::statusOptions())
                        ->default(ServiceClient::STATUS_ACTIVE)
                        ->required(),
                    Select::make('billing_style')
                        ->label('How does this client usually pay?')
                        ->options(ServiceClient::billingStyleOptions())
                        ->default(ServiceClient::BILLING_FIXED_MONTHLY)
                        ->required(),
                    TextInput::make('default_monthly_amount')
                        ->label('Usual monthly amount')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0),
                    TextInput::make('default_registration_fee')
                        ->label('Registration fee')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0),
                    TextInput::make('default_due_day')
                        ->label('Usual due day')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(31)
                        ->placeholder('1 to 31'),
                    DatePicker::make('active_from')
                        ->label('Active from'),
                    DatePicker::make('inactive_from')
                        ->label('Stopped / paused from'),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToServiceBusinesses($query))
            ->defaultSort('name')
            ->emptyStateHeading('No service clients yet')
            ->emptyStateDescription('Add each service client once, then monthly billing rows can reuse the same client without typing different spellings.')
            ->columns([
                Tables\Columns\TextColumn::make('business.name')->label('Business')->toggleable(),
                Tables\Columns\TextColumn::make('name')->label('Client')->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ServiceClient::STATUS_ACTIVE => 'success',
                        ServiceClient::STATUS_PAUSED => 'warning',
                        ServiceClient::STATUS_STOPPED => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('billing_style')
                    ->label('Style')
                    ->formatStateUsing(fn (?string $state): string => ServiceClient::billingStyleOptions()[$state] ?? 'Other'),
                Tables\Columns\TextColumn::make('default_monthly_amount')->label('Usual monthly')->money('LKR')->sortable(),
                Tables\Columns\TextColumn::make('default_registration_fee')->label('Registration')->money('LKR')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('default_due_day')->label('Due day'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceClients::route('/'),
            'create' => Pages\CreateServiceClient::route('/create'),
            'edit' => Pages\EditServiceClient::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && static::hasAccessibleServiceBusiness();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false)
            || ($user?->isInternalAdmin() ?? false)
            || ($user?->canAccessFinanceOperations() ?? false))
            && static::hasAccessibleServiceBusiness();
    }

    public static function serviceBusinessOptions(): array
    {
        return static::businessOptionsMatching(fn (Business $business): bool => $business->supportsBusinessType(Business::TYPE_SERVICE));
    }

    public static function defaultServiceBusinessId(): ?int
    {
        $default = Auth::user()?->defaultBusinessId();
        $business = $default ? Business::query()->find($default) : null;

        if ($business?->supportsBusinessType(Business::TYPE_SERVICE)) {
            return $business->id;
        }

        return array_key_first(static::serviceBusinessOptions());
    }

    private static function hasAccessibleServiceBusiness(): bool
    {
        return static::hasAccessibleBusinessMatching(fn (Business $business): bool => $business->supportsBusinessType(Business::TYPE_SERVICE));
    }

    private static function scopeToServiceBusinesses(Builder $query): Builder
    {
        return static::scopeToAccessibleBusinessesMatching($query, fn (Business $business): bool => $business->supportsBusinessType(Business::TYPE_SERVICE));
    }
}

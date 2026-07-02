<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Models\User;
use App\Filament\Resources\UserResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationGroup = 'Admin';
    protected static ?string $navigationLabel = 'Team Access';
    protected static ?string $navigationIcon = 'heroicon-o-identification';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            Select::make('business_id')
                ->label('Business')
                ->options(fn () => static::businessOptions())
                ->searchable()
                ->required()
                ->default(fn () => Auth::user()?->defaultBusinessId())
                ->disabled(fn (): bool => Auth::user()?->isStaff() ?? false),
            Select::make('account_role')
                ->label('Account role')
                ->options([
                    'owner' => 'Client owner',
                    'staff' => 'Staff',
                ])
                ->default('staff')
                ->live()
                ->visible(fn (): bool => Auth::user()?->isInternalAdmin() ?? false)
                ->afterStateHydrated(function (Select $component, ?User $record): void {
                    if (! $record) {
                        return;
                    }

                    $component->state($record->is_employee ? 'staff' : 'owner');
                })
                ->helperText('Super admin chooses who owns the client portal. Client owners can create staff later.'),
            Placeholder::make('seat_usage')
                ->hiddenLabel()
                ->content(fn (Get $get): HtmlString => new HtmlString(static::seatUsageContent($get('business_id')))),
            Placeholder::make('employee_hint')
                ->hiddenLabel()
                ->content(fn (): string => Auth::user()?->isInternalAdmin()
                    ? 'Create the client owner login or staff accounts for the selected business.'
                    : 'This screen creates staff accounts for your business. Owner access stays protected.'),
            Select::make('employee_access_profile')
                ->label('Staff access')
                ->options(fn (): array => User::employeeAccessProfileOptions())
                ->default('operations')
                ->required(fn (Get $get): bool => ($get('account_role') ?? 'staff') === 'staff')
                ->visible(fn (Get $get): bool => (Auth::user()?->isOwner() ?? false)
                    || ((Auth::user()?->isInternalAdmin() ?? false) && ($get('account_role') ?? 'staff') === 'staff'))
                ->helperText('Choose what this staff member can see inside HELOS. Owners automatically get owner visibility.'),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn ($state): bool => filled($state))
                ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null)
                ->helperText('Leave blank when editing if you do not want to change the password.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('email')->searchable(),
            Tables\Columns\TextColumn::make('business.name')->label('Business')->toggleable(),
            Tables\Columns\TextColumn::make('role')
                ->label('Role')
                ->badge()
                ->state(fn (User $record): string => $record->is_employee ? 'Staff' : 'Owner'),
            Tables\Columns\TextColumn::make('employee_access_profile')
                ->label('Staff access')
                ->badge()
                ->placeholder('-')
                ->formatStateUsing(fn (?string $state): string => User::employeeAccessProfileOptions()[$state ?? 'operations'] ?? 'Operations'),
            Tables\Columns\IconColumn::make('is_employee')->label('Employee')->boolean(),
        ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->label('Remove access')
                    ->modalHeading('Remove user access?')
                    ->modalDescription('This removes the login account. Business records and transactions stay in HELOS.')
                    ->visible(fn (User $record): bool => static::canDelete($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
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

    public static function canDelete(Model $record): bool
    {
        $user = Auth::user();

        return $record instanceof User
            && ($user?->isInternalAdmin() ?? false)
            && ! $record->isInternalAdmin()
            && $record->id !== $user->id;
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

    private static function scopeToCurrentBusiness(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->seesAllBusinesses()) {
            return $query;
        }

        return $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []);
    }

    private static function seatUsageContent(mixed $businessId): string
    {
        if (! filled($businessId)) {
            return '<div style="padding:.75rem;border:1px solid #e5e7eb;border-radius:.5rem;background:#fafafa">Select a business to see the employee account limit.</div>';
        }

        $business = Business::query()->find($businessId);

        if (! $business) {
            return '<div style="padding:.75rem;border:1px solid #e5e7eb;border-radius:.5rem;background:#fafafa">Employee account limit not available.</div>';
        }

        $usage = $business->employeeSeatUsage();
        $limit = $usage['limit'];
        $used = $usage['used'];
        $remaining = $usage['remaining'];

        $limitLabel = $limit === null ? 'Not set yet' : $limit.' seats';
        $remainingLabel = $remaining === null ? 'Unlimited' : $remaining.' left';

        return '<div style="padding:.75rem;border:1px solid #e5e7eb;border-radius:.5rem;background:#fafafa">'
            .'<strong>Employee accounts:</strong> '.e($used.' used').'<br>'
            .'<strong>Limit:</strong> '.e($limitLabel).'<br>'
            .'<strong>Remaining:</strong> '.e($remainingLabel)
            .'</div>';
    }
}

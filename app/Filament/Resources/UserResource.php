<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'Team Work';

    protected static ?string $navigationLabel = 'Employees & Roles';

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
            Select::make('staff_role_preset')
                ->label('What is this employee responsible for?')
                ->options(fn (): array => static::staffRolePresetOptions())
                ->default('daily_operations')
                ->live()
                ->visible(fn (Get $get, ?User $record): bool => static::staffFieldsVisible($get, $record))
                ->afterStateHydrated(function (Select $component, ?User $record): void {
                    $component->state(static::staffRolePresetForRecord($record));
                })
                ->afterStateUpdated(fn (?string $state, Set $set): mixed => static::applyStaffRolePresetToForm($state, $set))
                ->helperText('Choose the job first. HELOAS will automatically show work connected to that role. Use Custom only for an unusual mix.'),
            Select::make('employee_access_profile')
                ->label('Advanced access profile')
                ->options(fn (): array => User::employeeAccessProfileOptions())
                ->default('operations')
                ->required(fn (Get $get): bool => ($get('account_role') ?? 'staff') === 'staff')
                ->visible(fn (Get $get, ?User $record): bool => static::staffFieldsVisible($get, $record) && ($get('staff_role_preset') ?? 'daily_operations') === 'custom')
                ->helperText('Only use this for unusual employees. Normal staff should use Employee role above.'),
            Select::make('staff_responsibilities')
                ->label('Custom work areas')
                ->options(fn (): array => User::staffResponsibilityOptions())
                ->multiple()
                ->searchable()
                ->preload()
                ->visible(fn (Get $get, ?User $record): bool => static::staffFieldsVisible($get, $record) && ($get('staff_role_preset') ?? 'daily_operations') === 'custom')
                ->helperText('Only use this if the simple Employee role does not fit.'),
            Toggle::make('is_staff_supervisor')
                ->label('Can review team work')
                ->visible(fn (Get $get, ?User $record): bool => static::staffFieldsVisible($get, $record) && in_array($get('staff_role_preset') ?? 'daily_operations', ['supervisor', 'custom'], true))
                ->helperText('Supervisor can see review-style work without owner financial guidance.'),
            Select::make('supervisor_user_id')
                ->label('Reports to')
                ->options(fn (?User $record): array => static::reportingSupervisorOptions($record))
                ->searchable()
                ->preload()
                ->nullable()
                ->visible(fn (Get $get, ?User $record): bool => static::staffFieldsVisible($get, $record))
                ->helperText('Missions escalate to this employee first. Leave blank only when the owner directly supervises this account.'),
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
                Tables\Columns\TextColumn::make('staff_responsibilities')
                    ->label('Responsibilities')
                    ->badge()
                    ->separator(',')
                    ->placeholder('Profile default')
                    ->formatStateUsing(fn (string $state): string => User::staffResponsibilityOptions()[$state] ?? $state)
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_staff_supervisor')
                    ->label('Supervisor')
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('supervisor.name')
                    ->label('Reports to')
                    ->placeholder('Owner / not set')
                    ->toggleable(),
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

    public static function canEdit(Model $record): bool
    {
        $user = Auth::user();

        if (! $record instanceof User || ! $user) {
            return false;
        }

        if ($user->isInternalAdmin()) {
            return ! $record->isInternalAdmin() || $record->id === $user->id;
        }

        return $user->isOwner() && static::ownerCanManageStaffRecord($record, $user);
    }

    public static function staffRolePresetOptions(): array
    {
        return [
            'daily_operations' => 'Daily operations - orders, dispatch, returns',
            'production_store' => 'Production and stock',
            'money_admin' => 'Money admin - expenses, collections, bank exceptions',
            'supervisor' => 'Supervisor - review team work',
            'no_work' => 'No HELOS work yet',
            'custom' => 'Custom',
        ];
    }

    public static function applyStaffRolePresetToData(array $data): array
    {
        $preset = $data['staff_role_preset'] ?? null;

        unset($data['staff_role_preset']);

        if (! $preset || $preset === 'custom') {
            return $data;
        }

        $data['employee_access_profile'] = static::staffRolePresetConfig($preset)['employee_access_profile'];
        $data['staff_responsibilities'] = static::staffRolePresetConfig($preset)['staff_responsibilities'];
        $data['is_staff_supervisor'] = static::staffRolePresetConfig($preset)['is_staff_supervisor'];

        return $data;
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

        if (! ($user?->isOwner() ?? false)) {
            return $query->whereRaw('1 = 0');
        }

        $businessIds = $user->accessibleBusinessIds();
        $clientGroupId = $user->client_group_id;

        return $query
            ->where('is_employee', true)
            ->where(function (Builder $query) use ($businessIds, $clientGroupId): void {
                $query->whereIn('business_id', $businessIds);

                if (filled($clientGroupId)) {
                    $query->orWhere('client_group_id', $clientGroupId);
                }
            });
    }

    private static function staffFieldsVisible(Get $get, ?User $record): bool
    {
        $user = Auth::user();

        if ($user?->isOwner()) {
            return ! $record || $record->isStaff();
        }

        return ($user?->isInternalAdmin() ?? false)
            && ($get('account_role') ?? ($record?->is_employee ? 'staff' : 'owner')) === 'staff';
    }

    private static function applyStaffRolePresetToForm(?string $preset, Set $set): void
    {
        if (! $preset || $preset === 'custom') {
            return;
        }

        $config = static::staffRolePresetConfig($preset);

        $set('employee_access_profile', $config['employee_access_profile']);
        $set('staff_responsibilities', $config['staff_responsibilities']);
        $set('is_staff_supervisor', $config['is_staff_supervisor']);
    }

    private static function staffRolePresetForRecord(?User $record): string
    {
        if (! $record || ! $record->isStaff()) {
            return 'daily_operations';
        }

        $responsibilities = array_values(array_filter((array) ($record->staff_responsibilities ?? [])));
        sort($responsibilities);

        foreach (array_keys(static::staffRolePresetOptions()) as $preset) {
            if ($preset === 'custom') {
                continue;
            }

            $config = static::staffRolePresetConfig($preset);
            $presetResponsibilities = $config['staff_responsibilities'];
            sort($presetResponsibilities);

            if (
                $record->employeeAccessProfileValue() === $config['employee_access_profile']
                && $responsibilities === $presetResponsibilities
                && (bool) $record->is_staff_supervisor === $config['is_staff_supervisor']
            ) {
                return $preset;
            }
        }

        return 'custom';
    }

    private static function staffRolePresetConfig(string $preset): array
    {
        return match ($preset) {
            'production_store' => [
                'employee_access_profile' => 'operations',
                'staff_responsibilities' => ['production', 'material_stock', 'product_repair'],
                'is_staff_supervisor' => false,
            ],
            'money_admin' => [
                'employee_access_profile' => 'finance_ops',
                'staff_responsibilities' => ['expense_recording', 'collections', 'bank_exceptions'],
                'is_staff_supervisor' => false,
            ],
            'supervisor' => [
                'employee_access_profile' => 'full_staff',
                'staff_responsibilities' => ['supervisor_review'],
                'is_staff_supervisor' => true,
            ],
            'no_work' => [
                'employee_access_profile' => 'work_only',
                'staff_responsibilities' => [],
                'is_staff_supervisor' => false,
            ],
            default => [
                'employee_access_profile' => 'operations',
                'staff_responsibilities' => ['order_confirmation', 'dispatch', 'return_recovery', 'product_repair'],
                'is_staff_supervisor' => false,
            ],
        };
    }

    private static function ownerCanManageStaffRecord(User $record, User $owner): bool
    {
        if (! $record->isStaff()) {
            return false;
        }

        $businessIds = $owner->accessibleBusinessIds();

        return (filled($record->business_id) && in_array((int) $record->business_id, $businessIds, true))
            || (filled($owner->client_group_id) && (int) $record->client_group_id === (int) $owner->client_group_id);
    }

    private static function reportingSupervisorOptions(?User $record): array
    {
        $user = Auth::user();
        $businessIds = $user?->accessibleBusinessIds() ?? [];

        return User::query()
            ->whereIn('business_id', $businessIds)
            ->when($record, fn (Builder $query) => $query->whereKeyNot($record->id))
            ->where(function (Builder $query): void {
                $query->where('is_employee', false)
                    ->orWhere('is_staff_supervisor', true)
                    ->orWhereHas('activeStaffResponsibilityAssignments', fn (Builder $query) => $query->where('responsibility_code', 'supervisor_review'));
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
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

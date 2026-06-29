<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Filament\Resources\EmployeeResource\Pages;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;
    protected static ?string $navigationGroup = 'Setup';
    protected static ?string $navigationLabel = 'Staff & Pay';
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Employee pay setup')
                ->description('Use monthly salary only for fixed salaried team members. Production workers can be weekly piece-work with no fixed amount.')
                ->schema([
                    Select::make('business_id')
                        ->options(fn () => static::businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->required(),
                    TextInput::make('name')->required(),
                    Select::make('role')
                        ->label('Role')
                        ->placeholder('Search or choose a role')
                        ->searchable()
                        ->options(fn () => static::roleOptions())
                        ->preload(),
                    Select::make('pay_cycle')
                        ->label('When is it settled?')
                        ->helperText('Choose weekly production / piece work for workers paid by produced quantity.')
                        ->options([
                            'month_end' => 'At month end',
                            'weekly_piece' => 'Weekly production / piece work',
                            'custom' => 'Custom',
                        ])
                        ->default('month_end')
                        ->required(),
                    TextInput::make('monthly_salary')
                        ->label('Fixed monthly salary')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->helperText('Leave 0 for weekly production workers. Their pay is recorded in Production Pay.'),
                    Textarea::make('note')->label('Short note')->rows(3),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('role')->searchable(),
                Tables\Columns\TextColumn::make('monthly_salary')->label('Fixed monthly')->money('LKR'),
                Tables\Columns\TextColumn::make('pay_cycle')->badge(),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
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

    private static function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! $user?->seesAllBusinesses(), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
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

    private static function roleOptions(): array
    {
        $businessIds = Auth::user()?->accessibleBusinessIds() ?? [];

        $defaults = collect([
            'Supervisor',
            'Designer',
            'Helper',
            'Admin',
            'Sales',
            'Operations',
            'Production',
            'Courier',
            'Accounts',
            'Other',
        ])->mapWithKeys(fn (string $role): array => [$role => $role])->all();

        $savedRoles = Employee::query()
            ->when($businessIds !== [], fn (Builder $query) => $query->whereIn('business_id', $businessIds))
            ->whereNotNull('role')
            ->where('role', '!=', '')
            ->distinct()
            ->orderBy('role')
            ->pluck('role', 'role')
            ->all();

        return $defaults + $savedRoles;
    }
}

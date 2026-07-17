<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\StaffResponsibilityAssignment;
use App\Filament\Resources\StaffResponsibilityAssignmentResource\Pages;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class StaffResponsibilityAssignmentResource extends Resource
{
    protected static ?string $model = StaffResponsibilityAssignment::class;
    protected static ?string $navigationGroup = 'Admin';
    protected static ?string $navigationLabel = 'Responsibilities';
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Who can do this work?')
                ->schema([
                    Select::make('user_id')
                        ->label('Staff member')
                        ->options(fn (): array => static::staffOptions())
                        ->searchable()
                        ->required(),
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn (): array => static::businessOptions())
                        ->searchable()
                        ->required(),
                    Select::make('responsibility_code')
                        ->label('Responsibility')
                        ->options(fn (): array => User::staffResponsibilityOptions())
                        ->searchable()
                        ->required(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ])
                ->columns(2),
            Section::make('Access level')
                ->schema([
                    Toggle::make('can_view')->label('View')->default(true),
                    Toggle::make('can_create')->label('Create'),
                    Toggle::make('can_edit')->label('Edit')->default(true),
                    Toggle::make('can_complete')->label('Complete missions')->default(true),
                    Toggle::make('can_review')->label('Review'),
                    Toggle::make('can_approve')->label('Approve sensitive work'),
                    Toggle::make('own_records_only')->label('Own records only'),
                    Toggle::make('team_records_allowed')->label('Team records allowed'),
                ])
                ->columns(4),
            Section::make('Temporary access')
                ->schema([
                    DateTimePicker::make('active_from')
                        ->label('Starts at')
                        ->seconds(false),
                    DateTimePicker::make('expires_at')
                        ->label('Expires at')
                        ->seconds(false),
                    Textarea::make('assignment_note')
                        ->label('Reason / note')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToOwnerBusinesses($query))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Staff')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('business.name')->label('Business')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('responsibility_code')
                    ->label('Responsibility')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => User::staffResponsibilityOptions()[$state] ?? $state),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
                Tables\Columns\IconColumn::make('can_review')->label('Review')->boolean()->toggleable(),
                Tables\Columns\IconColumn::make('can_approve')->label('Approve')->boolean()->toggleable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('No expiry')
                    ->color(fn (?StaffResponsibilityAssignment $record): string => $record?->expires_at?->isPast() ? 'danger' : 'gray'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('deactivate')
                    ->label('Remove')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (StaffResponsibilityAssignment $record): bool => $record->is_active)
                    ->action(fn (StaffResponsibilityAssignment $record) => $record->deactivate(Auth::user(), 'Removed from responsibility manager')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaffResponsibilityAssignments::route('/'),
            'create' => Pages\CreateStaffResponsibilityAssignment::route('/create'),
            'edit' => Pages\EditStaffResponsibilityAssignment::route('/{record}/edit'),
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
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function staffOptions(): array
    {
        $user = Auth::user();

        return User::query()
            ->where('is_employee', true)
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function scopeToOwnerBusinesses(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->seesAllBusinesses()) {
            return $query;
        }

        return $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []);
    }
}

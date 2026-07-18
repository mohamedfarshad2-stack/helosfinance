<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\StaffResponsibilityAudit;
use App\Filament\Resources\StaffResponsibilityAuditResource\Pages;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class StaffResponsibilityAuditResource extends Resource
{
    protected static ?string $model = StaffResponsibilityAudit::class;
    protected static ?string $navigationGroup = 'Team';
    protected static ?string $navigationLabel = 'Access History';
    protected static ?string $navigationIcon = 'heroicon-o-clock';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToOwnerBusinesses($query))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Staff')->searchable(),
                Tables\Columns\TextColumn::make('business.name')->label('Business')->placeholder('-'),
                Tables\Columns\TextColumn::make('responsibility_code')
                    ->label('Responsibility')
                    ->formatStateUsing(fn (?string $state): string => $state ? (User::staffResponsibilityOptions()[$state] ?? $state) : '-')
                    ->badge(),
                Tables\Columns\TextColumn::make('event_type')->label('Change')->badge(),
                Tables\Columns\TextColumn::make('changedBy.name')->label('Changed by')->placeholder('System'),
                Tables\Columns\TextColumn::make('reason')->limit(50)->placeholder('-'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaffResponsibilityAudits::route('/'),
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

    private static function scopeToOwnerBusinesses(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->seesAllBusinesses()) {
            return $query;
        }

        return $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []);
    }
}

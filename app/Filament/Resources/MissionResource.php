<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Mission;
use App\Filament\Resources\MissionResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MissionResource extends Resource
{
    protected static ?string $model = Mission::class;
    protected static ?string $navigationGroup = 'Work';
    protected static ?string $navigationLabel = 'Mission Review';
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('assigned_user_id')
                ->label('Assigned employee')
                ->options(fn (?Mission $record): array => static::staffOptions($record))
                ->searchable(),
            Select::make('status')
                ->options([
                    Mission::STATUS_OPEN => 'Open',
                    Mission::STATUS_IN_PROGRESS => 'In progress',
                    Mission::STATUS_BLOCKED => 'Blocked',
                    Mission::STATUS_WAITING_REVIEW => 'Waiting review',
                    Mission::STATUS_COMPLETED => 'Completed',
                    Mission::STATUS_ESCALATED => 'Escalated',
                    Mission::STATUS_CANCELLED => 'Cancelled',
                    Mission::STATUS_REOPENED => 'Reopened',
                ])
                ->required(),
            Textarea::make('blocked_reason')
                ->label('Blocked / review note')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToAllowedMissions($query))
            ->defaultSort('due_at')
            ->columns([
                Tables\Columns\TextColumn::make('priority')->badge()->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('business.name')->label('Business')->sortable(),
                Tables\Columns\TextColumn::make('responsibility_code')
                    ->label('Area')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? (User::staffResponsibilityOptions()[$state] ?? $state) : 'General'),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('assignedUser.name')->label('Assigned')->placeholder('Unassigned'),
                Tables\Columns\TextColumn::make('due_at')->label('Due')->dateTime()->placeholder('-')->sortable(),
                Tables\Columns\TextColumn::make('estimated_impact')->label('Impact')->money('LKR')->placeholder('-')->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('returnForCorrection')
                    ->label('Return')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Mission $record): void {
                        $record->transition(Mission::STATUS_REOPENED, Auth::user(), 'returned_for_correction', 'Returned by supervisor/owner.');
                    }),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Mission $record): void {
                        $record->complete(Auth::user(), 'Approved from Mission Review.');
                    }),
                Action::make('escalate')
                    ->label('Escalate')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Mission $record): void {
                        $record->escalate(Auth::user(), 'Escalated from Mission Review.');
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMissions::route('/'),
            'edit' => Pages\EditMission::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false) || ($user?->isInternalAdmin() ?? false) || ($user?->canAccessSupervisorReview() ?? false));
    }

    private static function scopeToAllowedMissions(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->isInternalAdmin()) {
            return $query;
        }

        if ($user?->isOwner()) {
            return $query->whereIn('business_id', $user->accessibleBusinessIds());
        }

        if ($user?->canAccessSupervisorReview()) {
            return $query->whereIn('business_id', $user->accessibleBusinessIdsForResponsibility('supervisor_review'));
        }

        return $query->whereRaw('1 = 0');
    }

    private static function staffOptions(?Mission $record): array
    {
        if (! $record) {
            return [];
        }

        return User::query()
            ->where('is_employee', true)
            ->whereIn('business_id', [$record->business_id])
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}

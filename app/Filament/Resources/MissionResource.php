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

    protected static ?string $navigationGroup = 'Team Work';

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
                Tables\Columns\TextColumn::make('assignedUser.supervisor.name')->label('Reports to')->placeholder('Owner / not set')->toggleable(),
                Tables\Columns\TextColumn::make('escalatedToUser.name')->label('Escalated to')->placeholder('-')->toggleable(),
                Tables\Columns\TextColumn::make('due_at')->label('Due')->dateTime()->placeholder('-')->sortable(),
                Tables\Columns\TextColumn::make('estimated_impact')->label('Impact')->money('LKR')->placeholder('-')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        Mission::STATUS_OPEN => 'Open',
                        Mission::STATUS_IN_PROGRESS => 'In progress',
                        Mission::STATUS_BLOCKED => 'Blocked',
                        Mission::STATUS_WAITING_REVIEW => 'Waiting review',
                        Mission::STATUS_COMPLETED => 'Completed',
                        Mission::STATUS_ESCALATED => 'Escalated',
                        Mission::STATUS_CANCELLED => 'Cancelled',
                        Mission::STATUS_REOPENED => 'Reopened',
                    ]),
                Tables\Filters\SelectFilter::make('responsibility_code')
                    ->label('Area')
                    ->options(User::staffResponsibilityOptions()),
            ])
            ->actions([
                Action::make('reassign')
                    ->label('Reassign')
                    ->icon('heroicon-o-user-plus')
                    ->color('gray')
                    ->visible(fn (Mission $record): bool => $record->canBeReviewedBy(Auth::user()))
                    ->form([
                        Select::make('assigned_user_id')
                            ->label('Assigned employee')
                            ->options(fn (Mission $record): array => static::staffOptions($record))
                            ->searchable()
                            ->required(),
                        Textarea::make('note')
                            ->label('Reason')
                            ->rows(2),
                    ])
                    ->action(function (Mission $record, array $data): void {
                        abort_unless($record->canBeReviewedBy(Auth::user()), 403);
                        $previous = $record->only(['assigned_user_id', 'status']);

                        $record->forceFill([
                            'assigned_user_id' => $data['assigned_user_id'],
                            'assigned_by' => Auth::id(),
                            'status' => Mission::STATUS_OPEN,
                        ])->save();

                        $record->recordEvent('reassigned', Auth::user(), $data['note'] ?? 'Reassigned from Mission Review.', $previous, $record->only(['assigned_user_id', 'status']));
                    }),
                Tables\Actions\EditAction::make()
                    ->visible(fn (): bool => (Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false)),
                Action::make('returnForCorrection')
                    ->label('Return')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Mission $record): bool => $record->canBeReviewedBy(Auth::user()))
                    ->form([
                        Textarea::make('note')
                            ->label('Correction needed')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Mission $record, array $data): void {
                        abort_unless($record->canBeReviewedBy(Auth::user()), 403);
                        $record->transition(Mission::STATUS_REOPENED, Auth::user(), 'returned_for_correction', $data['note']);
                    }),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Mission $record): bool => $record->canBeApprovedBy(Auth::user()))
                    ->requiresConfirmation()
                    ->action(function (Mission $record): void {
                        abort_unless($record->canBeApprovedBy(Auth::user()), 403);
                        $record->complete(Auth::user(), 'Approved from Mission Review.');
                    }),
                Action::make('escalate')
                    ->label('Escalate')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('danger')
                    ->visible(fn (Mission $record): bool => $record->canBeReviewedBy(Auth::user()))
                    ->form([
                        Textarea::make('note')
                            ->label('Why owner is needed')
                            ->rows(3),
                    ])
                    ->action(function (Mission $record, array $data): void {
                        abort_unless($record->canBeReviewedBy(Auth::user()), 403);
                        $record->escalate(Auth::user(), $data['note'] ?? 'Escalated from Mission Review.');
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
            $directReportIds = $user->directReportIds();

            return $query
                ->whereIn('business_id', $user->accessibleBusinessIds())
                ->where(function (Builder $query) use ($user, $directReportIds): void {
                    $query->where('escalated_to_user_id', $user->id)
                        ->orWhere('escalation_level', 'owner')
                        ->when($directReportIds !== [], fn (Builder $query) => $query->orWhereIn('assigned_user_id', $directReportIds));
                });
        }

        if ($user?->canAccessSupervisorReview()) {
            $directReportIds = $user->directReportIds();

            return $query
                ->whereIn('business_id', $user->accessibleBusinessIdsForResponsibility('supervisor_review'))
                ->where(function (Builder $query) use ($user, $directReportIds): void {
                    $query->where('escalated_to_user_id', $user->id)
                        ->when($directReportIds !== [], fn (Builder $query) => $query->orWhereIn('assigned_user_id', $directReportIds));
                });
        }

        return $query->whereRaw('1 = 0');
    }

    private static function staffOptions(?Mission $record): array
    {
        if (! $record) {
            return [];
        }

        $user = Auth::user();
        $query = User::query()
            ->where('is_employee', true)
            ->where('business_id', $record->business_id);

        if ($user?->isStaff()) {
            $query->whereIn('id', $user->directReportIds());
        }

        return $query
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}

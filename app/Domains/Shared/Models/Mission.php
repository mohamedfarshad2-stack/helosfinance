<?php

namespace App\Domains\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mission extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_WAITING_REVIEW = 'waiting_review';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ESCALATED = 'escalated';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REOPENED = 'reopened';

    protected $fillable = [
        'source_key',
        'mission_type',
        'responsibility_code',
        'business_id',
        'source_type',
        'source_id',
        'title',
        'summary',
        'priority',
        'impact_type',
        'estimated_impact',
        'confidence',
        'due_at',
        'assigned_user_id',
        'assigned_by',
        'status',
        'blocked_reason',
        'escalation_level',
        'escalated_to_user_id',
        'completed_by',
        'completed_at',
        'reopened_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'estimated_impact' => 'float',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function escalatedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MissionEvent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_OPEN,
            self::STATUS_IN_PROGRESS,
            self::STATUS_BLOCKED,
            self::STATUS_WAITING_REVIEW,
            self::STATUS_ESCALATED,
            self::STATUS_REOPENED,
        ]);
    }

    public function start(User $user): void
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            $this->transition(self::STATUS_IN_PROGRESS, $user, 'started');
        }
    }

    public function complete(User $user, ?string $note = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'completed_by' => $user->id,
            'completed_at' => now(),
            'blocked_reason' => null,
        ])->save();

        $this->recordEvent('completed', $user, $note);
    }

    public function block(User $user, string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_BLOCKED,
            'blocked_reason' => $reason,
        ])->save();

        $this->recordEvent('blocked', $user, $reason);
    }

    public function escalate(User $user, ?string $note = null): void
    {
        $target = $user->supervisor;

        $this->forceFill([
            'status' => self::STATUS_ESCALATED,
            'escalation_level' => match (true) {
                ! $target, $target->isOwner() => 'owner',
                $target->supervisor?->isOwner() => 'manager',
                default => 'supervisor',
            },
            'escalated_to_user_id' => $target?->id,
        ])->save();

        $targetLabel = $target?->name ?: 'Owner';
        $this->recordEvent('escalated', $user, $note ?: 'Escalated to '.$targetLabel.'.');
    }

    public function submitForOwnerReview(User $user, ?string $note = null): void
    {
        $owner = User::query()
            ->where('is_platform_admin', false)
            ->where('is_employee', false)
            ->where(function (Builder $query): void {
                $query->where('business_id', $this->business_id)
                    ->when($this->business?->client_group_id, fn (Builder $query, int $groupId) => $query->orWhere('client_group_id', $groupId));
            })
            ->orderByRaw('case when business_id = ? then 0 else 1 end', [$this->business_id])
            ->first();

        $this->forceFill([
            'status' => self::STATUS_WAITING_REVIEW,
            'escalation_level' => 'owner',
            'escalated_to_user_id' => $owner?->id,
        ])->save();

        $this->recordEvent('submitted_for_owner_review', $user, $note ?: 'Owner-only decision submitted for review.');
    }

    public function canBeReviewedBy(User $user): bool
    {
        if ($user->isInternalAdmin()) {
            return true;
        }

        if (! in_array((int) $this->business_id, $user->accessibleBusinessIds(), true)) {
            return false;
        }

        if ($user->isOwner()) {
            return (int) $this->escalated_to_user_id === (int) $user->id
                || $this->escalation_level === 'owner'
                || ($this->assigned_user_id && in_array((int) $this->assigned_user_id, $user->directReportIds(), true));
        }

        if (! $user->canAccessSupervisorReview((int) $this->business_id)) {
            return false;
        }

        return (int) $this->escalated_to_user_id === (int) $user->id
            || ($this->assigned_user_id && $this->assignedUser && $user->isDirectSupervisorOf($this->assignedUser));
    }

    public function canBeApprovedBy(User $user): bool
    {
        if ((int) $this->assigned_user_id === (int) $user->id) {
            return false;
        }

        if ($this->escalation_level === 'owner' && ! ($user->isOwner() || $user->isInternalAdmin())) {
            return false;
        }

        return $this->canBeReviewedBy($user);
    }

    public function transition(string $status, User $user, string $event, ?string $note = null): void
    {
        $previous = $this->only(['status', 'blocked_reason', 'escalation_level', 'escalated_to_user_id']);

        $this->forceFill(['status' => $status])->save();

        $this->recordEvent($event, $user, $note, $previous, $this->only(['status', 'blocked_reason', 'escalation_level', 'escalated_to_user_id']));
    }

    public function recordEvent(string $event, ?User $user = null, ?string $note = null, ?array $previous = null, ?array $new = null): MissionEvent
    {
        return $this->events()->create([
            'user_id' => $user?->id,
            'event_type' => $event,
            'previous_values' => $previous,
            'new_values' => $new ?? $this->only(['status', 'blocked_reason', 'escalation_level', 'escalated_to_user_id', 'completed_by', 'completed_at']),
            'note' => $note,
        ]);
    }
}

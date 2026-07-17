<?php

namespace App\Domains\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class StaffResponsibilityAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'responsibility_code',
        'can_view',
        'can_create',
        'can_edit',
        'can_complete',
        'can_review',
        'can_approve',
        'own_records_only',
        'team_records_allowed',
        'active_from',
        'expires_at',
        'is_active',
        'assigned_by',
        'assignment_note',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_create' => 'boolean',
            'can_edit' => 'boolean',
            'can_complete' => 'boolean',
            'can_review' => 'boolean',
            'can_approve' => 'boolean',
            'own_records_only' => 'boolean',
            'team_records_allowed' => 'boolean',
            'active_from' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            if (! $assignment->assigned_by && Auth::id()) {
                $assignment->assigned_by = Auth::id();
            }

            $assignment->validateResponsibility();
        });

        static::updating(function (self $assignment): void {
            $assignment->validateResponsibility();
        });

        static::created(fn (self $assignment): StaffResponsibilityAudit => $assignment->audit('assigned'));

        static::updated(function (self $assignment): void {
            $event = $assignment->is_active ? 'updated' : 'removed';

            if ($assignment->wasChanged('is_active') && $assignment->is_active) {
                $event = 'reactivated';
            }

            $assignment->audit($event, $assignment->getOriginal());
        });

        static::deleted(fn (self $assignment): StaffResponsibilityAudit => $assignment->audit('deleted', $assignment->getOriginal()));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActiveNow(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('active_from')->orWhere('active_from', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActiveNow(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->active_from && $this->active_from->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function deactivate(?User $actor = null, ?string $reason = null): void
    {
        $this->forceFill([
            'is_active' => false,
            'assignment_note' => $reason ?? $this->assignment_note,
            'assigned_by' => $actor?->id ?? Auth::id() ?? $this->assigned_by,
        ])->save();
    }

    public function accessSnapshot(?array $values = null): array
    {
        $values ??= $this->getAttributes();

        return [
            'can_view' => (bool) ($values['can_view'] ?? false),
            'can_create' => (bool) ($values['can_create'] ?? false),
            'can_edit' => (bool) ($values['can_edit'] ?? false),
            'can_complete' => (bool) ($values['can_complete'] ?? false),
            'can_review' => (bool) ($values['can_review'] ?? false),
            'can_approve' => (bool) ($values['can_approve'] ?? false),
            'own_records_only' => (bool) ($values['own_records_only'] ?? false),
            'team_records_allowed' => (bool) ($values['team_records_allowed'] ?? false),
            'is_active' => (bool) ($values['is_active'] ?? false),
        ];
    }

    private function audit(string $event, ?array $previous = null): StaffResponsibilityAudit
    {
        return StaffResponsibilityAudit::query()->create([
            'user_id' => $this->user_id,
            'business_id' => $this->business_id,
            'staff_responsibility_assignment_id' => $this->exists ? $this->id : null,
            'responsibility_code' => $this->responsibility_code,
            'event_type' => $event,
            'previous_access' => $previous ? $this->accessSnapshot($previous) : null,
            'new_access' => $this->accessSnapshot(),
            'active_from' => $this->active_from,
            'expires_at' => $this->expires_at,
            'changed_by' => Auth::id() ?: $this->assigned_by,
            'reason' => $this->assignment_note,
        ]);
    }

    private function validateResponsibility(): void
    {
        if (! array_key_exists((string) $this->responsibility_code, User::staffResponsibilityOptions())) {
            throw new \InvalidArgumentException('Invalid staff responsibility: '.$this->responsibility_code);
        }
    }
}

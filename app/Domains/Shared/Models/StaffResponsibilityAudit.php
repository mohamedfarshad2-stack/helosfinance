<?php

namespace App\Domains\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffResponsibilityAudit extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'staff_responsibility_assignment_id',
        'responsibility_code',
        'event_type',
        'previous_access',
        'new_access',
        'active_from',
        'expires_at',
        'changed_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_access' => 'array',
            'new_access' => 'array',
            'active_from' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(StaffResponsibilityAssignment::class, 'staff_responsibility_assignment_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

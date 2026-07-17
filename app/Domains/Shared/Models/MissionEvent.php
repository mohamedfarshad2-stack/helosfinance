<?php

namespace App\Domains\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissionEvent extends Model
{
    protected $fillable = [
        'mission_id',
        'user_id',
        'event_type',
        'previous_values',
        'new_values',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'previous_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

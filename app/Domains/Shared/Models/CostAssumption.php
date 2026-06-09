<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostAssumption extends Model
{
    protected $fillable = ['business_id', 'key', 'label', 'amount', 'behavior', 'event_type'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}

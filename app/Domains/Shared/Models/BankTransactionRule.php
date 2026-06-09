<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransactionRule extends Model
{
    protected $fillable = [
        'business_id',
        'match_text',
        'classification',
        'confidence',
        'active',
        'last_matched_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'active' => 'boolean',
            'last_matched_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}

<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierRate extends Model
{
    protected $fillable = [
        'business_id',
        'courier_name',
        'delivery_charge',
        'return_charge',
        'resend_charge',
        'active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'delivery_charge' => 'decimal:2',
            'return_charge' => 'decimal:2',
            'resend_charge' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}

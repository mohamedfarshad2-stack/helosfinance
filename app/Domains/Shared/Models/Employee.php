<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'role',
        'monthly_salary',
        'pay_cycle',
        'active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'monthly_salary' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}

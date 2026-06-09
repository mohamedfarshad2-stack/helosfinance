<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'business_id',
        'department',
        'category',
        'expense_type',
        'suggested_key',
        'description',
        'amount',
        'payment_status',
        'payment_method',
        'cheque_number',
        'cheque_date',
        'paid_amount',
        'due_on',
        'settled_on',
        'payee',
        'reported_by',
        'allocation_bucket',
        'spent_on',
        'recurring',
        'locked_at',
        'locked_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cheque_date' => 'date',
            'paid_amount' => 'decimal:2',
            'due_on' => 'date',
            'settled_on' => 'date',
            'spent_on' => 'date',
            'recurring' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}

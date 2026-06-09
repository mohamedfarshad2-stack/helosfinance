<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;

class FixedExpenseTemplate extends Model
{
    protected $fillable = [
        'key',
        'label',
        'expense_type',
        'department',
        'basis',
        'plain_hint',
        'common_for_most_businesses',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'common_for_most_businesses' => 'boolean',
        ];
    }
}

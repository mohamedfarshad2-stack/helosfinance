<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialSnapshot extends Model
{
    protected $fillable = [
        'business_id',
        'period_start',
        'period_end',
        'revenue_total',
        'cost_total',
        'leakage_total',
        'estimated_profit',
        'metrics',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'metrics' => 'array',
            'revenue_total' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'leakage_total' => 'decimal:2',
            'estimated_profit' => 'decimal:2',
        ];
    }
}

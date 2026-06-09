<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalEvent extends Model
{
    public const ORDER_CREATED = 'order_created';
    public const ORDER_CONFIRMED = 'order_confirmed';
    public const TRACKING_NUMBER_ADDED = 'tracking_number_added';
    public const ORDER_DELIVERED = 'order_delivered';
    public const ORDER_RETURNED = 'order_returned';
    public const ORDER_RESENT = 'order_resent';
    public const FAKE_ORDER_DETECTED = 'fake_order_detected';
    public const SKU_PRODUCED = 'sku_produced';
    public const PRODUCTION_WASTE = 'production_waste';
    public const PAYOUT_GENERATED = 'payout_generated';
    public const EXPENSE_ADDED = 'expense_added';

    protected $fillable = [
        'business_id',
        'sku_id',
        'source',
        'event_type',
        'external_id',
        'channel',
        'department',
        'quantity',
        'revenue_amount',
        'direct_cost_amount',
        'leakage_amount',
        'recovery_amount',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'revenue_amount' => 'decimal:2',
            'direct_cost_amount' => 'decimal:2',
            'leakage_amount' => 'decimal:2',
            'recovery_amount' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}

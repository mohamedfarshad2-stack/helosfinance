<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkuStockMovement extends Model
{
    protected $fillable = [
        'business_id',
        'sku_id',
        'operational_event_id',
        'order_external_id',
        'movement_type',
        'quantity',
        'quantity_delta',
        'is_restockable',
        'note',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'quantity' => 'integer',
            'quantity_delta' => 'integer',
            'is_restockable' => 'boolean',
            'occurred_at' => 'datetime',
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

    public function operationalEvent(): BelongsTo
    {
        return $this->belongsTo(OperationalEvent::class);
    }
}

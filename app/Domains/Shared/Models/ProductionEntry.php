<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionEntry extends Model
{
    protected $fillable = [
        'business_id',
        'sku_id',
        'sku_recipe_item_id',
        'employee_name',
        'production_step',
        'piece_rate',
        'quantity_produced',
        'waste_quantity',
        'employee_payout',
        'advance_amount',
        'deduction_amount',
        'net_payable',
        'payment_status',
        'paid_on',
        'note',
        'estimated_total_cost',
        'produced_on',
    ];

    protected function casts(): array
    {
        return [
            'employee_payout' => 'decimal:2',
            'piece_rate' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'paid_on' => 'date',
            'estimated_total_cost' => 'decimal:2',
            'produced_on' => 'date',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function skuRecipeItem(): BelongsTo
    {
        return $this->belongsTo(SkuRecipeItem::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}

<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkuRecipeItem extends Model
{
    public const TYPE_RAW_MATERIAL = 'raw_material';

    public const TYPE_LABOR = 'labor';

    protected $fillable = [
        'business_id',
        'sku_id',
        'line_type',
        'component_name',
        'quantity_per_unit',
        'unit_cost',
        'active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'line_type' => 'string',
            'quantity_per_unit' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'active' => 'boolean',
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

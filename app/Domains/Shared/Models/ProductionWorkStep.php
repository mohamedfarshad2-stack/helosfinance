<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionWorkStep extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'unit_cost',
        'active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(SkuRecipeItem::class);
    }
}

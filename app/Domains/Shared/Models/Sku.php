<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sku extends Model
{
    protected $fillable = [
        'business_id',
        'code',
        'name',
        'material_cost',
        'packaging_cost',
        'labor_rate',
        'finishing_cost',
        'expected_sale_price',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'material_cost' => 'decimal:2',
            'packaging_cost' => 'decimal:2',
            'labor_rate' => 'decimal:2',
            'finishing_cost' => 'decimal:2',
            'expected_sale_price' => 'decimal:2',
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

    public function materialLedgerEntries(): HasMany
    {
        return $this->hasMany(MaterialLedgerEntry::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(SkuStockMovement::class);
    }

    public function materialCostPerUnit(): float
    {
        $recipeItems = $this->recipeItems()
            ->where('active', true)
            ->where('line_type', SkuRecipeItem::TYPE_RAW_MATERIAL)
            ->get();

        if ($recipeItems->isNotEmpty()) {
            return (float) $recipeItems->sum(fn (SkuRecipeItem $item): float => (float) $item->quantity_per_unit * (float) $item->unit_cost);
        }

        return (float) $this->material_cost;
    }

    public function productionCostPerUnit(): float
    {
        return $this->materialCostPerUnit()
            + (float) $this->packaging_cost
            + $this->laborCostPerUnit()
            + (float) $this->finishing_cost;
    }

    public function laborCostPerUnit(): float
    {
        $recipeItems = $this->recipeItems()
            ->where('active', true)
            ->where('line_type', SkuRecipeItem::TYPE_LABOR)
            ->get();

        if ($recipeItems->isNotEmpty()) {
            return (float) $recipeItems->sum(fn (SkuRecipeItem $item): float => (float) $item->quantity_per_unit * (float) $item->unit_cost);
        }

        return (float) $this->labor_rate;
    }
}

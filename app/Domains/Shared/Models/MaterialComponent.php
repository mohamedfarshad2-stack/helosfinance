<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialComponent extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'purchase_unit',
        'consumption_unit',
        'units_per_purchase_unit',
        'waste_percent',
        'latest_purchase_unit_cost',
        'active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'units_per_purchase_unit' => 'decimal:4',
            'waste_percent' => 'decimal:2',
            'latest_purchase_unit_cost' => 'decimal:2',
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

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(MaterialLedgerEntry::class);
    }

    public function usableUnitsPerPurchaseUnit(): float
    {
        $units = max((float) $this->units_per_purchase_unit, 1);
        $wasteMultiplier = max(0, 1 - ((float) $this->waste_percent / 100));

        return round(max($units * $wasteMultiplier, 0.0001), 4);
    }

    public function costPerConsumptionUnit(): float
    {
        return round((float) $this->latest_purchase_unit_cost / $this->usableUnitsPerPurchaseUnit(), 2);
    }

    public function purchasedConsumptionUnits(): float
    {
        $purchaseUnits = (float) $this->ledgerEntries()
            ->where('entry_type', 'purchase')
            ->sum('quantity');

        return round($purchaseUnits * $this->usableUnitsPerPurchaseUnit(), 4);
    }

    public function consumedUnits(): float
    {
        return (float) $this->ledgerEntries()
            ->whereIn('entry_type', ['consumption', 'waste'])
            ->sum('quantity');
    }

    public function adjustmentUnits(): float
    {
        return (float) $this->ledgerEntries()
            ->where('entry_type', 'adjustment')
            ->sum('quantity');
    }

    public function stockBalance(): float
    {
        return round($this->purchasedConsumptionUnits() - $this->consumedUnits() + $this->adjustmentUnits(), 4);
    }

    public function stockValue(): float
    {
        return round($this->stockBalance() * $this->costPerConsumptionUnit(), 2);
    }
}

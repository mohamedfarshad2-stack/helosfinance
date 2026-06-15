<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialLedgerEntry extends Model
{
    protected $fillable = [
        'business_id',
        'sku_id',
        'entry_type',
        'component_name',
        'material_component_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'occurred_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'occurred_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MaterialLedgerEntry $entry): void {
            if (blank($entry->material_component_id)) {
                return;
            }

            $component = $entry->materialComponent()->first();

            if ($component) {
                $entry->component_name = $component->name;
            }
        });

        static::saved(function (MaterialLedgerEntry $entry): void {
            if ($entry->entry_type !== 'purchase' || blank($entry->material_component_id) || (float) $entry->unit_cost <= 0) {
                return;
            }

            $entry->materialComponent()
                ->update(['latest_purchase_unit_cost' => (float) $entry->unit_cost]);
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function materialComponent(): BelongsTo
    {
        return $this->belongsTo(MaterialComponent::class);
    }
}

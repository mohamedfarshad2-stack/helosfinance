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
        'part_name',
        'material_component_id',
        'production_work_step_id',
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

    protected static function booted(): void
    {
        static::saving(function (SkuRecipeItem $item): void {
            if ((string) $item->line_type === self::TYPE_LABOR && filled($item->production_work_step_id)) {
                $step = $item->productionWorkStep()->first();

                if (! $step) {
                    return;
                }

                $item->component_name = $step->name;

                if ((float) $item->unit_cost <= 0) {
                    $item->unit_cost = (float) $step->unit_cost;
                }

                if ((float) $item->quantity_per_unit <= 0) {
                    $item->quantity_per_unit = 1;
                }

                return;
            }

            if ((string) $item->line_type !== self::TYPE_RAW_MATERIAL || blank($item->material_component_id)) {
                return;
            }

            $component = $item->materialComponent()->first();

            if (! $component) {
                return;
            }

            $item->component_name = $component->name;

            if ((float) $item->unit_cost <= 0) {
                $item->unit_cost = $component->costPerConsumptionUnit();
            }

            if ((float) $item->quantity_per_unit <= 0) {
                $item->quantity_per_unit = 1;
            }
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

    public function productionWorkStep(): BelongsTo
    {
        return $this->belongsTo(ProductionWorkStep::class);
    }
}

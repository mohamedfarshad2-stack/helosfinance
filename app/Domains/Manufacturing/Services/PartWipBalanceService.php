<?php

namespace App\Domains\Manufacturing\Services;

use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use Illuminate\Support\Collection;

class PartWipBalanceService
{
    /**
     * @return array<int, array{part_name:string, produced:float, consumed:float, balance:float}>
     */
    public function forSku(Sku $sku): array
    {
        $parts = $this->recipeParts($sku);
        $produced = $this->producedParts($sku);
        $completed = $this->completedQuantity($sku);

        return $parts
            ->map(function (string $part) use ($produced, $completed): array {
                $partProduced = (float) ($produced[$part] ?? 0);

                return [
                    'part_name' => $part,
                    'produced' => $partProduced,
                    'consumed' => $completed,
                    'balance' => $partProduced - $completed,
                ];
            })
            ->values()
            ->all();
    }

    private function recipeParts(Sku $sku): Collection
    {
        $parts = SkuRecipeItem::query()
            ->where('sku_id', $sku->id)
            ->where('active', true)
            ->whereNotNull('part_name')
            ->where('part_name', '!=', '')
            ->distinct()
            ->orderBy('part_name')
            ->pluck('part_name');

        return $parts->isEmpty() ? collect(['General']) : $parts;
    }

    private function producedParts(Sku $sku): array
    {
        return ProductionEntry::query()
            ->where('sku_id', $sku->id)
            ->where('production_kind', 'part_production')
            ->whereNotNull('part_name')
            ->selectRaw('part_name, SUM(quantity_produced) as total_quantity')
            ->groupBy('part_name')
            ->pluck('total_quantity', 'part_name')
            ->map(fn (mixed $quantity): float => (float) $quantity)
            ->all();
    }

    private function completedQuantity(Sku $sku): float
    {
        return (float) ProductionEntry::query()
            ->where('sku_id', $sku->id)
            ->where('production_kind', 'finished_product')
            ->sum('quantity_produced');
    }
}

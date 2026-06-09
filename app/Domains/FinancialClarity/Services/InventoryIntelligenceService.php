<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use App\Domains\Shared\Models\SkuStockMovement;
use App\Domains\Shared\Models\Sku;
use Illuminate\Support\Collection;

class InventoryIntelligenceService
{
    public function forCurrentMonth(Business $business): array
    {
        if (! $business->supportsInventoryIntelligence()) {
            return [
                'activated' => false,
                'headline' => 'Inventory intelligence is not activated for this business yet.',
                'confidence' => 'Low',
                'recipe_coverage' => 0,
                'material_purchase_total' => 0.0,
                'material_consumption_total' => 0.0,
                'material_waste_total' => 0.0,
                'material_adjustment_total' => 0.0,
                'flow_gap' => 0.0,
                'waste_ratio' => 0.0,
                'top_skus' => [],
                'actions' => [],
            ];
        }

        $ledger = MaterialLedgerEntry::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        $stockMovements = SkuStockMovement::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()]);

        $purchaseTotal = (float) (clone $ledger)->where('entry_type', 'purchase')->sum('total_cost');
        $consumptionTotal = (float) (clone $ledger)->where('entry_type', 'consumption')->sum('total_cost');
        $wasteTotal = (float) (clone $ledger)->where('entry_type', 'waste')->sum('total_cost');
        $adjustmentTotal = (float) (clone $ledger)->where('entry_type', 'adjustment')->sum('total_cost');
        $dispatchCount = (int) (clone $stockMovements)->whereIn('movement_type', ['dispatch', 'resend_dispatch'])->sum('quantity');
        $returnRestockedCount = (int) (clone $stockMovements)->where('movement_type', 'return_restocked')->sum('quantity');
        $returnDamagedCount = (int) (clone $stockMovements)->where('movement_type', 'return_damaged')->sum('quantity');
        $netMovement = (int) (clone $stockMovements)->sum('quantity_delta');

        $recipeSkus = Sku::query()
            ->where('business_id', $business->id)
            ->withCount(['recipeItems as active_recipe_items_count' => fn ($query) => $query->where('active', true)])
            ->orderByDesc('active_recipe_items_count')
            ->get();

        $activeRecipeSkus = $recipeSkus->filter(fn (Sku $sku): bool => (int) ($sku->active_recipe_items_count ?? 0) > 0);
        $recipeCoverage = $recipeSkus->count() > 0 ? (int) round(($activeRecipeSkus->count() / $recipeSkus->count()) * 100) : 0;

        $topSkus = $activeRecipeSkus
            ->sortByDesc(fn (Sku $sku): float => $sku->materialCostPerUnit())
            ->take(3)
            ->map(fn (Sku $sku): array => [
                'code' => $sku->code,
                'name' => $sku->name,
                'material_cost_per_unit' => $sku->materialCostPerUnit(),
                'production_cost_per_unit' => $sku->productionCostPerUnit(),
                'active_recipe_items' => (int) ($sku->active_recipe_items_count ?? 0),
            ])
            ->values()
            ->all();

        $flowGap = $purchaseTotal - $consumptionTotal - $wasteTotal;
        $wasteRatio = $purchaseTotal > 0 ? ($wasteTotal / $purchaseTotal) * 100 : 0.0;

        $headline = match (true) {
            $recipeCoverage === 0 => 'Inventory truth is available, but BOM coverage is still empty.',
            $returnDamagedCount > 0 => 'Returned parcels need review before they become sellable stock again.',
            $returnRestockedCount > 0 => 'Returned parcels are flowing back into stock where possible.',
            $purchaseTotal > $consumptionTotal && $business->supportsProductionTracking() => 'Material capital is moving faster than consumption.',
            $wasteTotal > 0 => 'Material waste is visible and should be trimmed.',
            default => 'Inventory flow looks steady for the current month.',
        };

        return [
            'activated' => true,
            'headline' => $headline,
            'confidence' => ($purchaseTotal > 0 || $consumptionTotal > 0) ? 'High' : 'Medium',
            'recipe_coverage' => $recipeCoverage,
            'active_recipe_skus' => $activeRecipeSkus->count(),
            'material_purchase_total' => $purchaseTotal,
            'material_consumption_total' => $consumptionTotal,
            'material_waste_total' => $wasteTotal,
            'material_adjustment_total' => $adjustmentTotal,
            'flow_gap' => $flowGap,
            'waste_ratio' => round($wasteRatio, 2),
            'finished_goods_dispatches' => $dispatchCount,
            'finished_goods_return_restocked' => $returnRestockedCount,
            'finished_goods_return_damaged' => $returnDamagedCount,
            'finished_goods_net_movement' => $netMovement,
            'top_skus' => $topSkus,
            'actions' => array_values(array_filter([
                $recipeCoverage < 100 ? 'Increase BOM coverage for active SKUs before scaling production.' : null,
                $wasteTotal > 0 ? 'Review waste and adjustment entries to reduce trapped material value.' : null,
                $flowGap > 0 ? 'Material purchases are ahead of consumption, so check whether stock is turning fast enough.' : null,
                $dispatchCount > 0 ? 'Finished goods are leaving the stock room, so watch return behavior closely.' : null,
                $returnDamagedCount > 0 ? 'Review damaged returns so restockable stock does not get mixed with scrap.' : null,
                $business->supportsProductionTracking() ? 'Align production and material movement with real demand.' : null,
            ])),
        ];
    }
}

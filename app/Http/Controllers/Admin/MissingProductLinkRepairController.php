<?php

namespace App\Http\Controllers\Admin;

use App\Domains\FinancialClarity\Services\OperationalImpactCalculator;
use App\Domains\FinancialClarity\Services\SkuStockMovementService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MissingProductLinkRepairController extends Controller
{
    public function store(Request $request, OperationalImpactCalculator $calculator, SkuStockMovementService $stockMovements): RedirectResponse
    {
        $user = Auth::user();

        abort_unless($user && (($user->isOwner() ?? false) || ($user->isInternalAdmin() ?? false) || ($user->canAccessOperationalTasks() ?? false)), 403);

        $data = $request->validate([
            'business_id' => ['required', 'integer'],
            'group_key' => ['required', 'string'],
            'sku_id' => ['required', 'integer'],
        ]);

        abort_unless(in_array((int) $data['business_id'], $user->accessibleBusinessIds(), true), 403);

        $business = Business::query()->findOrFail($data['business_id']);
        $sku = Sku::query()
            ->where('business_id', $business->id)
            ->whereKey($data['sku_id'])
            ->firstOrFail();

        $events = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereNull('sku_id')
            ->whereIn('event_type', $this->skuRequiredEventTypes())
            ->latest('occurred_at')
            ->latest('id')
            ->get()
            ->filter(fn (OperationalEvent $event): bool => $this->groupKeyForEvent($event) === $data['group_key'])
            ->take(500);

        if ($events->isEmpty()) {
            return back()->with('missing_product_repair_warning', 'Nothing changed. This group may already be fixed.');
        }

        foreach ($events as $event) {
            $this->repairEvent($event, $business, $sku, $calculator, $stockMovements);
        }

        return back()->with('missing_product_repair_success', $events->count().' row(s) linked to '.$sku->code.' and recalculated.');
    }

    /**
     * @return array<int, string>
     */
    private function skuRequiredEventTypes(): array
    {
        return [
            OperationalEvent::ORDER_CREATED,
            OperationalEvent::ORDER_CONFIRMED,
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
            OperationalEvent::ORDER_DELIVERED,
            OperationalEvent::ORDER_RETURNED,
            OperationalEvent::ORDER_RESENT,
            OperationalEvent::FAKE_ORDER_DETECTED,
            OperationalEvent::SKU_PRODUCED,
            OperationalEvent::PRODUCTION_WASTE,
            OperationalEvent::PAYOUT_GENERATED,
        ];
    }

    private function repairEvent(OperationalEvent $event, Business $business, Sku $sku, OperationalImpactCalculator $calculator, SkuStockMovementService $stockMovements): void
    {
        $payload = $event->payload ?? [];
        $payload = array_merge($payload, [
            'event_type' => $event->event_type,
            'external_id' => $event->external_id ?: ($payload['external_id'] ?? null),
            'sku_code' => $sku->code,
            'sku_name' => $sku->name,
            'quantity' => (int) ($event->quantity ?: ($payload['quantity'] ?? 1)),
            'channel' => $event->channel ?: ($payload['channel'] ?? 'cod'),
        ]);

        $impact = $calculator->calculate($business, $payload);

        $event->update([
            'sku_id' => $impact['sku_id'] ?: $sku->id,
            'payload' => array_merge($payload, ['economics' => $impact['economics'] ?? []]),
            'revenue_amount' => $impact['revenue_amount'] ?? 0,
            'direct_cost_amount' => $impact['direct_cost_amount'] ?? 0,
            'leakage_amount' => $impact['leakage_amount'] ?? 0,
            'recovery_amount' => $impact['recovery_amount'] ?? 0,
        ]);

        $event->refresh();
        $stockMovements->record($business, $event, $event->payload ?? []);
    }

    private function productHint(OperationalEvent $event): string
    {
        $payload = $event->payload ?? [];
        $hint = $payload['sku_code'] ?? $payload['sku_name'] ?? $payload['product_name'] ?? $payload['item_name'] ?? null;

        return filled($hint) ? trim((string) $hint) : 'Product not sent';
    }

    private function groupKeyForEvent(OperationalEvent $event): string
    {
        $hint = $this->productHint($event);

        if ($hint === 'Product not sent') {
            return 'missing-product-hint';
        }

        return str($hint)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->value() ?: 'missing-product-hint';
    }
}

<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use Illuminate\Support\Collection;

class RevenuePipelineService
{
    public function forCurrentMonth(Business $business): array
    {
        $events = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereIn('event_type', [
                OperationalEvent::ORDER_CREATED,
                OperationalEvent::ORDER_CONFIRMED,
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_DELIVERED,
                OperationalEvent::ORDER_RETURNED,
                OperationalEvent::ORDER_RESENT,
            ])
            ->get();

        if ($events->isEmpty()) {
            return [
                'activated' => true,
                'headline' => 'No money coming in has been recorded yet for this month.',
                'confidence' => 'Medium',
                'cod' => $this->emptyChannel('COD'),
                'wholesale' => $this->emptyChannel('Wholesale'),
                'actions' => [
                    'Capture confirmed and tracking-added orders so the COD pipeline becomes visible.',
                    'Tag wholesale orders clearly so collections can be read separately from COD.',
                ],
            ];
        }

        $orders = $events
            ->groupBy(fn (OperationalEvent $event): string => (string) ($event->external_id ?: $event->id))
            ->map(fn (Collection $group): array => $this->summarizeOrder($business, $group->sortBy(fn (OperationalEvent $event): string => (string) $event->occurred_at?->timestamp.'-'.$event->id)))
            ->values();

        $codOrders = $orders->filter(fn (array $order): bool => $this->isCodChannel($order['channel']));
        $wholesaleOrders = $orders->filter(fn (array $order): bool => $this->isWholesaleChannel($order['channel']));

        $codPending = $codOrders->filter(fn (array $order): bool => $this->isPendingStatus($order['status']))->sum('expected_amount');
        $codCollected = $codOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_DELIVERED)->sum('recognized_amount');
        $codReturned = $codOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_RETURNED)->sum('reversed_amount');

        $wholesalePending = $wholesaleOrders->filter(fn (array $order): bool => $this->isPendingStatus($order['status']))->sum('expected_amount');
        $wholesaleDelivered = $wholesaleOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_DELIVERED)->sum('recognized_amount');
        $wholesaleReturned = $wholesaleOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_RETURNED)->sum('reversed_amount');

        $pendingCount = $orders->filter(fn (array $order): bool => $this->isPendingStatus($order['status']))->count();
        $deliveredCount = $orders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_DELIVERED)->count();
        $returnedCount = $orders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_RETURNED)->count();

        $headline = match (true) {
            $codPending > 0 && $wholesalePending > 0 => 'COD and wholesale both need collection attention.',
            $codPending > 0 => 'COD money is still waiting to be delivered and collected.',
            $wholesalePending > 0 => 'Wholesale money is still waiting for settlement.',
            $returnedCount > 0 => 'Returns are active and need to be watched closely.',
            default => 'Money coming in is visible and mostly settled for this month.',
        };

        return [
            'activated' => true,
            'headline' => $headline,
            'confidence' => $pendingCount > 0 || $deliveredCount > 0 ? 'High' : 'Medium',
            'cod' => [
                'expected_revenue' => round($codPending, 2),
                'collected_revenue' => round($codCollected, 2),
                'returned_revenue' => round($codReturned, 2),
                'pending_orders' => $codOrders->filter(fn (array $order): bool => $this->isPendingStatus($order['status']))->count(),
                'delivered_orders' => $codOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_DELIVERED)->count(),
                'returned_orders' => $codOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_RETURNED)->count(),
            ],
            'wholesale' => [
                'expected_revenue' => round($wholesalePending, 2),
                'collected_revenue' => round($wholesaleDelivered, 2),
                'returned_revenue' => round($wholesaleReturned, 2),
                'pending_orders' => $wholesaleOrders->filter(fn (array $order): bool => $this->isPendingStatus($order['status']))->count(),
                'delivered_orders' => $wholesaleOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_DELIVERED)->count(),
                'returned_orders' => $wholesaleOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_RETURNED)->count(),
                'note' => 'Wholesale collection timing still needs bank or invoice matching to become exact.',
            ],
            'total_expected_revenue' => round($codPending + $wholesalePending, 2),
            'total_collected_revenue' => round($codCollected + $wholesaleDelivered, 2),
            'total_returned_revenue' => round($codReturned + $wholesaleReturned, 2),
            'orders' => $orders->take(8)->values()->all(),
            'actions' => array_values(array_filter([
                $codPending > 0 ? 'Review COD parcels that are confirmed or tracked but not yet delivered.' : null,
                $wholesalePending > 0 ? 'Follow up wholesale customers with unpaid or uncollected orders.' : null,
                $returnedCount > 0 ? 'Check which returned parcels can be restocked and which should be treated as scrap.' : null,
                $deliveredCount > 0 ? 'Match delivered money with bank settlements so money stays clear.' : null,
            ])),
        ];
    }

    private function summarizeOrder(Business $business, Collection $group): array
    {
        /** @var OperationalEvent $latest */
        $latest = $group->last();
        /** @var OperationalEvent $first */
        $first = $group->first();

        $payload = array_merge(
            is_array($first->payload ?? null) ? $first->payload : [],
            is_array($latest->payload ?? null) ? $latest->payload : [],
        );

        $sku = $latest->sku ?? ($first?->sku ?? null);

        $expectedAmount = $this->expectedAmount($sku, $payload);
        $recognizedAmount = max((float) ($latest->revenue_amount ?? 0), 0.0);
        $reversedAmount = abs((float) ($latest->revenue_amount ?? 0));

        return [
            'external_id' => $latest->external_id ?? $first->external_id ?? null,
            'channel' => strtolower((string) ($latest->channel ?? $first->channel ?? ($payload['channel'] ?? 'cod'))),
            'status' => $latest->event_type,
            'sku_code' => $sku?->code,
            'sku_name' => $sku?->name,
            'expected_amount' => $expectedAmount,
            'recognized_amount' => $recognizedAmount,
            'reversed_amount' => $reversedAmount,
            'occurred_at' => $latest->occurred_at?->toDateTimeString(),
        ];
    }

    private function expectedAmount(?Sku $sku, array $payload): float
    {
        $amount = (float) ($payload['sale_amount'] ?? $payload['revenue_amount'] ?? 0);

        if ($amount > 0) {
            return $amount;
        }

        if ($sku instanceof Sku && (float) $sku->expected_sale_price > 0) {
            return (float) $sku->expected_sale_price;
        }

        return 0.0;
    }

    private function isPendingStatus(string $status): bool
    {
        return in_array($status, [
            OperationalEvent::ORDER_CREATED,
            OperationalEvent::ORDER_CONFIRMED,
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
            OperationalEvent::ORDER_RESENT,
        ], true);
    }

    private function isCodChannel(string $channel): bool
    {
        return $channel === '' || in_array($channel, ['cod', 'cash_on_delivery', 'cash', 'retail'], true);
    }

    private function isWholesaleChannel(string $channel): bool
    {
        return in_array($channel, ['wholesale', 'cheque', 'credit'], true);
    }

    private function emptyChannel(string $name): array
    {
        return [
            'expected_revenue' => 0.0,
            'collected_revenue' => 0.0,
            'returned_revenue' => 0.0,
            'pending_orders' => 0,
            'delivered_orders' => 0,
            'returned_orders' => 0,
            'label' => $name,
        ];
    }
}

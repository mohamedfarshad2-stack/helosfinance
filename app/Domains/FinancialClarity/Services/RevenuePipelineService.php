<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\Sku;
use Illuminate\Support\Collection;

class RevenuePipelineService
{
    public function forCurrentMonth(Business $business): array
    {
        $serviceBilling = $this->serviceBilling($business);
        $codSettlement = $this->codSettlement($business);
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
            $serviceExpected = (float) ($serviceBilling['expected_revenue'] ?? 0);
            $serviceCollected = (float) ($serviceBilling['collected_revenue'] ?? 0);

            return [
                'activated' => true,
                'headline' => $serviceExpected > 0 || $serviceCollected > 0
                    ? 'Service billing money is visible for this month.'
                    : 'No money coming in has been recorded yet for this month.',
                'confidence' => 'Medium',
                'cod' => $this->emptyChannel('COD'),
                'wholesale' => $this->emptyChannel('Wholesale'),
                'service' => $serviceBilling,
                'cod_settlement' => $codSettlement,
                'total_expected_revenue' => round($serviceExpected, 2),
                'total_collected_revenue' => round($serviceCollected, 2),
                'total_cash_confirmed' => round($serviceCollected + (float) ($codSettlement['cash_received'] ?? 0), 2),
                'total_returned_revenue' => 0.0,
                'orders' => [],
                'actions' => [
                    $serviceExpected > 0 ? 'Follow up service clients with unpaid or part-paid monthly fees.' : 'Capture confirmed and tracking-added orders so the COD pipeline becomes visible.',
                    $business->supportsBusinessType(Business::TYPE_SERVICE) ? 'Record registration fees and monthly subscription payments in Service Income.' : 'Tag wholesale orders clearly so collections can be read separately from COD.',
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
        $codCashReceived = (float) ($codSettlement['cash_received'] ?? 0);
        $codSettlementGap = round($codCollected - $codCashReceived, 2);

        $wholesalePending = $wholesaleOrders->filter(fn (array $order): bool => $this->isPendingStatus($order['status']))->sum('remaining_amount');
        $wholesaleDelivered = $wholesaleOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_DELIVERED)->sum('recognized_amount')
            + $wholesaleOrders->filter(fn (array $order): bool => $this->isPendingStatus($order['status']))->sum('paid_amount');
        $wholesaleReturned = $wholesaleOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_RETURNED)->sum('reversed_amount');

        $pendingCount = $orders->filter(fn (array $order): bool => $this->isPendingStatus($order['status']))->count();
        $deliveredCount = $orders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_DELIVERED)->count();
        $returnedCount = $orders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_RETURNED)->count();
        $servicePending = (float) ($serviceBilling['expected_revenue'] ?? 0);
        $serviceCollected = (float) ($serviceBilling['collected_revenue'] ?? 0);

        $headline = match (true) {
            $servicePending > 0 && ($codPending > 0 || $wholesalePending > 0) => 'Service billing and order collections both need attention.',
            $servicePending > 0 => 'Service clients still have subscription money to collect.',
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
                'cash_received' => round($codCashReceived, 2),
                'settlement_gap' => $codSettlementGap,
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
            'service' => $serviceBilling,
            'cod_settlement' => $codSettlement,
            'total_expected_revenue' => round($codPending + $wholesalePending + $servicePending, 2),
            'total_collected_revenue' => round($codCollected + $wholesaleDelivered + $serviceCollected, 2),
            'total_cash_confirmed' => round($codCashReceived + $wholesaleDelivered + $serviceCollected, 2),
            'total_returned_revenue' => round($codReturned + $wholesaleReturned, 2),
            'orders' => $orders->take(8)->values()->all(),
            'actions' => array_values(array_filter([
                $codPending > 0 ? 'Review COD parcels that are confirmed or tracked but not yet delivered.' : null,
                $wholesalePending > 0 ? 'Follow up wholesale customers with unpaid or uncollected orders.' : null,
                $servicePending > 0 ? 'Follow up service clients with unpaid or part-paid monthly fees.' : null,
                $returnedCount > 0 ? 'Check which returned parcels can be restocked and which should be treated as scrap.' : null,
                $deliveredCount > 0 && abs($codSettlementGap) > 0.01 ? 'Match delivered COD revenue with bank COD settlement deposits so cash is clear.' : null,
            ])),
        ];
    }

    private function codSettlement(Business $business): array
    {
        $rows = BankTransaction::query()
            ->where('business_id', $business->id)
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->where(function ($query): void {
                $query->where('transaction_type', 'cod_settlement')
                    ->orWhere('classification', 'cod_settlement');
            })
            ->get();

        $cashReceived = (float) $rows->sum('credit');
        $deductions = (float) $rows->sum('debit');

        return [
            'cash_received' => round($cashReceived, 2),
            'deductions' => round($deductions, 2),
            'row_count' => $rows->count(),
            'label' => 'COD settlement',
            'note' => 'Bank COD settlement confirms cash received. It does not create sales revenue again.',
        ];
    }

    private function serviceBilling(Business $business): array
    {
        if (! $business->supportsBusinessType(Business::TYPE_SERVICE)) {
            return $this->emptyChannel('Service');
        }

        $records = ServiceBillingRecord::query()
            ->where('business_id', $business->id)
            ->where(function ($query): void {
                $query->whereBetween('due_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                    ->orWhereBetween('paid_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                    ->orWhereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->orWhere(function ($query): void {
                        $query->whereDate('period_start', '<=', now()->endOfMonth()->toDateString())
                            ->whereDate('period_end', '>=', now()->startOfMonth()->toDateString());
                    });
            })
            ->get();

        $pending = $records->filter(fn (ServiceBillingRecord $record): bool => $record->balanceDue() > 0);

        return [
            'expected_revenue' => round((float) $pending->sum(fn (ServiceBillingRecord $record): float => $record->balanceDue()), 2),
            'collected_revenue' => round((float) $records->sum('paid_amount'), 2),
            'returned_revenue' => 0.0,
            'pending_orders' => $pending->count(),
            'delivered_orders' => $records->where('payment_status', 'paid')->count(),
            'returned_orders' => 0,
            'label' => 'Service',
            'records' => $records->sortBy('due_on')->take(8)->map(fn (ServiceBillingRecord $record): array => [
                'client_name' => $record->client_name,
                'billing_type' => $record->billing_type,
                'amount_due' => (float) $record->amount_due,
                'paid_amount' => (float) $record->paid_amount,
                'remaining_amount' => $record->balanceDue(),
                'payment_status' => $record->payment_status,
                'due_on' => optional($record->due_on)->toDateString(),
            ])->values()->all(),
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
        $paidAmount = max((float) ($payload['customer_paid_amount'] ?? $payload['paid_amount'] ?? 0), 0.0);
        $reversedAmount = abs((float) ($latest->revenue_amount ?? 0));

        return [
            'external_id' => $latest->external_id ?? $first->external_id ?? null,
            'channel' => strtolower((string) ($latest->channel ?? $first->channel ?? ($payload['channel'] ?? 'cod'))),
            'status' => $latest->event_type,
            'sku_code' => $sku?->code,
            'sku_name' => $sku?->name,
            'customer_name' => $payload['customer_name'] ?? null,
            'expected_amount' => $expectedAmount,
            'recognized_amount' => $recognizedAmount,
            'paid_amount' => $paidAmount,
            'remaining_amount' => max($expectedAmount - $paidAmount - $recognizedAmount, 0.0),
            'reversed_amount' => $reversedAmount,
            'customer_payment_method' => $payload['customer_payment_method'] ?? null,
            'payment_due_at' => $payload['payment_due_at'] ?? null,
            'cheque_number' => $payload['cheque_number'] ?? null,
            'cheque_date' => $payload['cheque_date'] ?? null,
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

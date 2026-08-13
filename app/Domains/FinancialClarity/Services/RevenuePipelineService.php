<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\ServiceClient;
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
                'cod_reconciliation' => $this->codReconciliation(0, 0, 0, 0, 0, 0, $codSettlement),
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
            ->groupBy(fn (OperationalEvent $event): string => $this->stableOrderKey($event))
            ->map(fn (Collection $group): array => $this->summarizeOrder($business, $group->sortBy(fn (OperationalEvent $event): string => (string) $event->occurred_at?->timestamp.'-'.$event->id)))
            ->filter(fn (array $order): bool => $this->isFinanceVisibleOrder($order))
            ->values();

        $codOrders = $orders->filter(fn (array $order): bool => $this->isCodChannel($order['channel']));
        $wholesaleOrders = $orders->filter(fn (array $order): bool => $this->isWholesaleChannel($order['channel']));

        $codPending = $codOrders->filter(fn (array $order): bool => $this->isPendingStatus($order['status']))->sum('expected_amount');
        $codCollected = $codOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_DELIVERED)->sum('recognized_amount');
        $codReturnedOrders = $codOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_RETURNED);
        $codReturned = $codReturnedOrders->sum('expected_amount');
        $codReturnLoss = $codReturnedOrders->sum('leakage_amount');
        $codDeliveredCourierCost = $codOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_DELIVERED)->sum('direct_cost_amount');
        $codResendCost = $codOrders->filter(fn (array $order): bool => $order['status'] === OperationalEvent::ORDER_RESENT)->sum('direct_cost_amount');
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
                'return_loss' => round($codReturnLoss, 2),
                'delivered_courier_cost' => round($codDeliveredCourierCost, 2),
                'resend_cost' => round($codResendCost, 2),
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
            'cod_reconciliation' => $this->codReconciliation(
                $codCollected,
                $codPending,
                $codReturned,
                $codReturnLoss,
                $codDeliveredCourierCost,
                $codResendCost,
                $codSettlement
            ),
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

    private function codReconciliation(float $deliveredRevenue, float $pendingRevenue, float $returnedValue, float $returnLoss, float $deliveredCourierCost, float $resendCost, array $settlement): array
    {
        $cashReceived = (float) ($settlement['cash_received'] ?? 0);
        $courierDeductions = (float) ($settlement['deductions'] ?? 0);
        $settlementGap = round($deliveredRevenue - $cashReceived, 2);
        $parcelCost = $returnLoss + $deliveredCourierCost + $resendCost;

        return [
            'parcel_truth' => [
                'delivered_revenue' => round($deliveredRevenue, 2),
                'pending_revenue' => round($pendingRevenue, 2),
                'returned_value' => round($returnedValue, 2),
                'return_loss' => round($returnLoss, 2),
                'delivered_courier_cost' => round($deliveredCourierCost, 2),
                'resend_cost' => round($resendCost, 2),
                'parcel_cost_total' => round($parcelCost, 2),
            ],
            'settlement_truth' => [
                'cash_received' => round($cashReceived, 2),
                'courier_deductions' => round($courierDeductions, 2),
                'settlement_rows' => (int) ($settlement['row_count'] ?? 0),
                'settlement_gap' => $settlementGap,
            ],
            'month_end' => [
                'cash_confirmed_against_delivered_percent' => $deliveredRevenue > 0 ? round(($cashReceived / $deliveredRevenue) * 100, 1) : 0.0,
                'net_cod_after_known_parcel_costs' => round($deliveredRevenue - $parcelCost, 2),
                'needs_review' => abs($settlementGap) > 0.01 || $pendingRevenue > 0 || $returnedValue > 0,
                'owner_message' => match (true) {
                    abs($settlementGap) > 0.01 => 'Delivered COD and bank settlement do not match yet. Check courier settlement report and bank review.',
                    $pendingRevenue > 0 => 'Some COD money is still in the parcel pipeline and not settled yet.',
                    $returnedValue > 0 => 'Returns are reducing the month. Check return reasons, restock, and marketing waste.',
                    default => 'COD parcel result and settlement cash are aligned for the visible data.',
                },
            ],
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

        $clients = ServiceClient::query()
            ->where('business_id', $business->id)
            ->get();

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
            ->with('serviceClient')
            ->get();

        $pending = $records->filter(fn (ServiceBillingRecord $record): bool => $record->balanceDue() > 0);
        $overdue = $pending->filter(fn (ServiceBillingRecord $record): bool => filled($record->due_on) && $record->due_on->isBefore(now()->startOfDay()));
        $activeClients = $clients->filter(fn (ServiceClient $client): bool => $client->status === ServiceClient::STATUS_ACTIVE);
        $fixedClients = $activeClients->filter(fn (ServiceClient $client): bool => $client->billing_style === ServiceClient::BILLING_FIXED_MONTHLY);
        $variableClients = $activeClients->filter(fn (ServiceClient $client): bool => $client->billing_style === ServiceClient::BILLING_VARIABLE_MONTHLY);
        $expectedRecurring = (float) $fixedClients->sum('default_monthly_amount');
        $fixedCosts = $this->fixedMonthlyCostForServiceBusiness($business);
        $collected = (float) $records->sum('paid_amount');
        $billedThisMonth = (float) $records->sum('amount_due');
        $outstanding = (float) $pending->sum(fn (ServiceBillingRecord $record): float => $record->balanceDue());

        return [
            'expected_revenue' => round($outstanding, 2),
            'collected_revenue' => round($collected, 2),
            'returned_revenue' => 0.0,
            'pending_orders' => $pending->count(),
            'delivered_orders' => $records->where('payment_status', 'paid')->count(),
            'returned_orders' => 0,
            'label' => 'Service',
            'active_clients' => $activeClients->count(),
            'paused_clients' => $clients->where('status', ServiceClient::STATUS_PAUSED)->count(),
            'fixed_clients' => $fixedClients->count(),
            'variable_clients' => $variableClients->count(),
            'expected_monthly_revenue' => round($expectedRecurring, 2),
            'billed_this_month' => round($billedThisMonth, 2),
            'overdue_amount' => round((float) $overdue->sum(fn (ServiceBillingRecord $record): float => $record->balanceDue()), 2),
            'fixed_monthly_costs' => round($fixedCosts, 2),
            'coverage_gap_expected' => round(max($fixedCosts - $expectedRecurring, 0), 2),
            'coverage_gap_collected' => round(max($fixedCosts - $collected, 0), 2),
            'coverage_surplus_collected' => round(max($collected - $fixedCosts, 0), 2),
            'records' => $records->sortBy('due_on')->take(8)->map(fn (ServiceBillingRecord $record): array => [
                'client_name' => $record->serviceClient?->name ?? $record->client_name,
                'billing_type' => $record->billing_type,
                'amount_due' => (float) $record->amount_due,
                'paid_amount' => (float) $record->paid_amount,
                'remaining_amount' => $record->balanceDue(),
                'payment_status' => $record->payment_status,
                'due_on' => optional($record->due_on)->toDateString(),
            ])->values()->all(),
        ];
    }

    private function fixedMonthlyCostForServiceBusiness(Business $business): float
    {
        $expenseTotal = (float) Expense::query()
            ->where('business_id', $business->id)
            ->where('expense_type', 'fixed')
            ->where(function ($query): void {
                $query->whereBetween('spent_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                    ->orWhere(function ($query): void {
                        $query->where('recurring', true)
                            ->whereDate('spent_on', '<=', now()->endOfMonth()->toDateString());
                    });
            })
            ->sum('amount');

        $salaryTotal = (float) Employee::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->sum('monthly_salary');

        return round($expenseTotal + $salaryTotal, 2);
    }

    private function summarizeOrder(Business $business, Collection $group): array
    {
        /** @var OperationalEvent $latest */
        $terminal = $group->whereIn('event_type', [
            OperationalEvent::ORDER_DELIVERED,
            OperationalEvent::ORDER_RETURNED,
        ]);
        $latest = $terminal->isNotEmpty() ? $terminal->last() : $group->last();
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
            'direct_cost_amount' => (float) ($latest->direct_cost_amount ?? 0),
            'leakage_amount' => (float) ($latest->leakage_amount ?? 0),
            'recovery_amount' => (float) ($latest->recovery_amount ?? 0),
            'return_courier_amount' => (float) ($payload['economics']['return_courier_amount'] ?? 0),
            'return_packaging_amount' => (float) ($payload['economics']['return_packaging_amount'] ?? 0),
            'return_marketing_amount' => (float) ($payload['economics']['return_marketing_amount'] ?? 0),
            'resend_courier_amount' => (float) ($payload['economics']['resend_courier_amount'] ?? 0),
            'resend_packaging_amount' => (float) ($payload['economics']['resend_packaging_amount'] ?? 0),
            'customer_payment_method' => $payload['customer_payment_method'] ?? null,
            'payment_due_at' => $payload['payment_due_at'] ?? null,
            'cheque_number' => $payload['cheque_number'] ?? null,
            'cheque_date' => $payload['cheque_date'] ?? null,
            'occurred_at' => $latest->occurred_at?->toDateTimeString(),
        ];
    }

    private function stableOrderKey(OperationalEvent $event): string
    {
        $orderId = trim((string) (data_get($event->payload, 'order_id') ?: data_get($event->payload, 'cod_order_id') ?: data_get($event->payload, 'order_number') ?: data_get($event->payload, 'reference')));

        if ($orderId !== '') {
            return $orderId;
        }

        $externalId = trim((string) $event->external_id);
        if ($externalId !== '') {
            if (preg_match('/^(.*?)-(?:created|new|pending|confirmed|delivered|returned|resent|tracking_number_added|tracking_added|tracking|dispatch|dispatched|shipped|shipping|sent_to_courier)(?:-|$)/i', $externalId, $matches) === 1) {
                return $matches[1];
            }

            return $externalId;
        }

        return (string) $event->id;
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
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
            OperationalEvent::ORDER_RESENT,
        ], true);
    }

    private function isFinanceVisibleOrder(array $order): bool
    {
        $status = (string) ($order['status'] ?? '');
        $channel = strtolower((string) ($order['channel'] ?? 'cod'));

        if ($this->isCodChannel($channel)) {
            return in_array($status, [
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::ORDER_DELIVERED,
                OperationalEvent::ORDER_RETURNED,
                OperationalEvent::ORDER_RESENT,
            ], true);
        }

        if ($this->isWholesaleChannel($channel)) {
            return in_array($status, [
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_DELIVERED,
                OperationalEvent::ORDER_RETURNED,
                OperationalEvent::ORDER_RESENT,
            ], true);
        }

        return true;
    }

    private function isCodChannel(string $channel): bool
    {
        return $channel === '' || in_array($channel, ['cod', 'cash_on_delivery', 'cash', 'retail', 'default'], true);
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
            'return_loss' => 0.0,
            'delivered_courier_cost' => 0.0,
            'resend_cost' => 0.0,
            'cash_received' => 0.0,
            'settlement_gap' => 0.0,
            'pending_orders' => 0,
            'delivered_orders' => 0,
            'returned_orders' => 0,
            'label' => $name,
        ];
    }
}

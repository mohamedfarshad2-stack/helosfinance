<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\FinancialSnapshot;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\ServiceClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BusinessHealthSnapshotService
{
    public function create(Business $business, Carbon $start, Carbon $end): FinancialSnapshot
    {
        $summary = $this->buildSummary($business, $start, $end);

        FinancialSnapshot::query()
            ->where('business_id', $business->id)
            ->whereDate('period_start', $start->toDateString())
            ->delete();

        return FinancialSnapshot::query()->create(array_merge($summary, [
            'business_id' => $business->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ]));
    }

    public function currentMonth(Business $business): FinancialSnapshot
    {
        $start = now()->startOfMonth();
        $end = now();
        $summary = $this->buildSummary($business, $start, $end);

        $snapshot = $this->readCurrentMonth($business) ?? new FinancialSnapshot([
            'business_id' => $business->id,
            'period_start' => $start->toDateString(),
        ]);

        $snapshot->fill(array_merge($summary, [
            'business_id' => $business->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ]));
        $snapshot->save();

        return $snapshot->fresh();
    }

    public function refreshCurrentMonth(Business $business): FinancialSnapshot
    {
        return $this->currentMonth($business);
    }

    public function readCurrentMonth(Business $business): ?FinancialSnapshot
    {
        return FinancialSnapshot::query()
            ->where('business_id', $business->id)
            ->whereDate('period_start', now()->startOfMonth()->toDateString())
            ->latest('period_end')
            ->first();
    }

    public function previewCurrentMonth(Business $business): array
    {
        return $this->buildSummary($business, now()->startOfMonth(), now());
    }

    public function currentMonthSummary(Business $business): array
    {
        return $this->previewCurrentMonth($business);
    }

    private function buildSummary(Business $business, Carbon $start, Carbon $end): array
    {
        $events = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [$start->startOfDay(), $end->endOfDay()]);

        $serviceBilling = ServiceBillingRecord::query()
            ->where('business_id', $business->id)
            ->where(function ($query) use ($start, $end): void {
                $monthEnd = $end->copy()->endOfMonth()->toDateString();

                $query->whereBetween('due_on', [$start->toDateString(), $monthEnd])
                    ->orWhereBetween('paid_on', [$start->toDateString(), $monthEnd])
                    ->orWhereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfMonth()->endOfDay()])
                    ->orWhere(function ($query) use ($start, $end): void {
                        $query->whereDate('period_start', '<=', $end->toDateString())
                            ->whereDate('period_end', '>=', $start->toDateString());
                    });
            })
            ->get();

        $serviceRevenue = (float) $serviceBilling->sum('paid_amount');
        $serviceExpected = (float) $serviceBilling->sum('amount_due');
        $serviceOutstanding = (float) $serviceBilling->sum(fn (ServiceBillingRecord $record): float => $record->balanceDue());
        $serviceOverdue = (float) $serviceBilling
            ->filter(fn (ServiceBillingRecord $record): bool => $record->balanceDue() > 0 && filled($record->due_on) && $record->due_on->isBefore(now()->startOfDay()))
            ->sum(fn (ServiceBillingRecord $record): float => $record->balanceDue());

        $orderEvents = (clone $events)
            ->whereIn('event_type', $this->orderLifecycleEventTypes())
            ->get();
        $latestOrderEvents = $this->latestOrderEvents($orderEvents);
        $latestDeliveredOrderEvents = $latestOrderEvents
            ->where('event_type', OperationalEvent::ORDER_DELIVERED)
            ->values();
        $pendingDispatchEvents = $latestOrderEvents
            ->whereIn('event_type', [
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_RESENT,
            ])
            ->values();
        $recognizedOrderRevenue = (float) $latestDeliveredOrderEvents->sum('revenue_amount');
        $deliveredCourierCosts = (float) $latestDeliveredOrderEvents->sum('direct_cost_amount');
        $deliveredValueAfterCourier = $recognizedOrderRevenue - $deliveredCourierCosts;
        $pendingDispatchValue = (float) $pendingDispatchEvents->sum(fn (OperationalEvent $event): float => $this->orderValue($event));
        $deliveredWithoutCourierCost = $latestDeliveredOrderEvents
            ->filter(fn (OperationalEvent $event): bool => (float) $event->direct_cost_amount <= 0)
            ->count();
        $unrecognizedOrderRevenue = max((float) (clone $events)->sum('revenue_amount') - $recognizedOrderRevenue, 0.0);
        $revenue = $recognizedOrderRevenue + $serviceRevenue;
        $directCosts = (clone $events)->sum('direct_cost_amount');
        $leakage = (clone $events)->sum('leakage_amount');
        $recovery = (clone $events)->sum('recovery_amount');

        $expenseQuery = Expense::query()
            ->where('business_id', $business->id)
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('spent_on', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function ($query) use ($end): void {
                        $query->where('recurring', true)
                            ->where('expense_type', 'fixed')
                            ->whereDate('spent_on', '<=', $end->toDateString());
                    });
            });
        $expenses = (clone $expenseQuery)->sum('amount');
        $fixedExpenses = (clone $expenseQuery)->where('expense_type', 'fixed')->sum('amount');
        $variableExpenses = (clone $expenseQuery)->where('expense_type', 'variable')->sum('amount');
        $cashPaid = (clone $expenseQuery)->sum('paid_amount');
        $toSettle = max($expenses - $cashPaid, 0);
        $salaryPressure = Employee::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->sum('monthly_salary');
        $serviceClients = ServiceClient::query()
            ->where('business_id', $business->id)
            ->get();
        $serviceActiveClients = $serviceClients->where('status', ServiceClient::STATUS_ACTIVE)->count();
        $serviceRecurringExpected = (float) $serviceClients
            ->where('status', ServiceClient::STATUS_ACTIVE)
            ->where('billing_style', ServiceClient::BILLING_FIXED_MONTHLY)
            ->sum('default_monthly_amount');

        $returnImpact = (clone $events)->where('event_type', OperationalEvent::ORDER_RETURNED)->sum('leakage_amount');
        $resendImpact = (clone $events)->where('event_type', OperationalEvent::ORDER_RESENT)->sum('leakage_amount');
        $fakeImpact = (clone $events)->where('event_type', OperationalEvent::FAKE_ORDER_DETECTED)->sum('leakage_amount');
        $courierImpact = (clone $events)
            ->whereIn('event_type', [OperationalEvent::TRACKING_NUMBER_ADDED, OperationalEvent::WHOLESALE_PARCEL_SENT, OperationalEvent::ORDER_RESENT, OperationalEvent::ORDER_RETURNED])
            ->sum('direct_cost_amount');
        $confirmationImpact = (clone $events)
            ->whereIn('event_type', [OperationalEvent::ORDER_CREATED, OperationalEvent::ORDER_CONFIRMED])
            ->sum('direct_cost_amount');
        $orderActivityCounts = [
            'confirmed' => (clone $events)->where('event_type', OperationalEvent::ORDER_CONFIRMED)->count(),
            'tracking_added' => (clone $events)->where('event_type', OperationalEvent::TRACKING_NUMBER_ADDED)->count(),
            'wholesale_sent' => (clone $events)->where('event_type', OperationalEvent::WHOLESALE_PARCEL_SENT)->count(),
            'delivered' => (clone $events)->where('event_type', OperationalEvent::ORDER_DELIVERED)->count(),
            'returned' => (clone $events)->where('event_type', OperationalEvent::ORDER_RETURNED)->count(),
            'resent' => (clone $events)->where('event_type', OperationalEvent::ORDER_RESENT)->count(),
            'fake' => (clone $events)->where('event_type', OperationalEvent::FAKE_ORDER_DETECTED)->count(),
        ];
        $orderCounts = [
            'confirmed' => $latestOrderEvents->whereIn('event_type', [
                OperationalEvent::ORDER_CREATED,
                OperationalEvent::ORDER_CONFIRMED,
            ])->count(),
            'dispatched' => $pendingDispatchEvents->count(),
            'tracking_added' => $latestOrderEvents->where('event_type', OperationalEvent::TRACKING_NUMBER_ADDED)->count(),
            'wholesale_sent' => $latestOrderEvents->where('event_type', OperationalEvent::WHOLESALE_PARCEL_SENT)->count(),
            'delivered' => $latestDeliveredOrderEvents->count(),
            'returned' => $latestOrderEvents->where('event_type', OperationalEvent::ORDER_RETURNED)->count(),
            'resent' => $latestOrderEvents->where('event_type', OperationalEvent::ORDER_RESENT)->count(),
            'fake' => $latestOrderEvents->where('event_type', OperationalEvent::FAKE_ORDER_DETECTED)->count(),
        ];
        $topLossSku = $this->topSkuByField((clone $events)->get(), 'leakage_amount');
        $topRevenueSku = $this->topSkuByField($latestDeliveredOrderEvents, 'revenue_amount');
        $topExpenseCategories = Expense::query()
            ->where('business_id', $business->id)
            ->whereBetween('spent_on', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('category, SUM(amount) as total_amount')
            ->groupBy('category')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'category' => $row->category,
                'total_amount' => (float) $row->total_amount,
            ])
            ->values()
            ->all();

        $costTotal = $directCosts + $expenses + $salaryPressure + $leakage - $recovery;
        $profit = $revenue - $costTotal;

        return [
            'business_id' => $business->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'revenue_total' => $revenue,
            'cost_total' => $costTotal,
            'leakage_total' => $leakage,
            'estimated_profit' => $profit,
            'metrics' => [
                'direct_operational_costs' => $directCosts,
                'recognized_order_revenue' => $recognizedOrderRevenue,
                'delivered_courier_costs' => $deliveredCourierCosts,
                'delivered_value_after_courier' => $deliveredValueAfterCourier,
                'delivered_contribution_after_all_direct_costs' => $recognizedOrderRevenue - $directCosts - $leakage + $recovery,
                'other_direct_operational_costs' => max($directCosts - $deliveredCourierCosts, 0),
                'pending_dispatch_count' => $pendingDispatchEvents->count(),
                'pending_dispatch_value' => $pendingDispatchValue,
                'delivered_without_courier_cost_count' => $deliveredWithoutCourierCost,
                'unrecognized_order_revenue' => $unrecognizedOrderRevenue,
                'fixed_expenses' => $fixedExpenses,
                'variable_expenses' => $variableExpenses,
                'salary_pressure' => $salaryPressure,
                'manual_overhead_costs' => $expenses,
                'cash_paid' => $cashPaid,
                'to_settle' => $toSettle,
                'recovered_value' => $recovery,
                'event_count' => (clone $events)->count(),
                'return_impact' => $returnImpact,
                'resend_impact' => $resendImpact,
                'fake_impact' => $fakeImpact,
                'courier_impact' => $courierImpact,
                'confirmation_impact' => $confirmationImpact,
                'order_counts' => $orderCounts,
                'order_activity_counts' => $orderActivityCounts,
                'top_loss_sku' => $topLossSku,
                'top_revenue_sku' => $topRevenueSku,
                'top_expense_categories' => $topExpenseCategories,
                'service_billing_expected' => $serviceExpected,
                'service_billing_collected' => $serviceRevenue,
                'service_billing_outstanding' => $serviceOutstanding,
                'service_billing_count' => $serviceBilling->count(),
                'service_billing_overdue' => $serviceOverdue,
                'service_active_clients' => $serviceActiveClients,
                'service_client_count' => $serviceClients->count(),
                'service_recurring_expected' => $serviceRecurringExpected,
                'service_fixed_cost_coverage_gap' => max(($fixedExpenses + $salaryPressure) - $serviceRecurringExpected, 0),
                'pressure_note' => $leakage > 0 ? 'Leakage is creating margin pressure.' : 'No leakage recorded in this period.',
            ],
        ];
    }

    private function snapshotToArray(FinancialSnapshot $snapshot): array
    {
        return [
            'business_id' => $snapshot->business_id,
            'period_start' => $snapshot->period_start?->toDateString(),
            'period_end' => $snapshot->period_end?->toDateString(),
            'revenue_total' => (float) $snapshot->revenue_total,
            'cost_total' => (float) $snapshot->cost_total,
            'leakage_total' => (float) $snapshot->leakage_total,
            'estimated_profit' => (float) $snapshot->estimated_profit,
            'metrics' => $snapshot->metrics ?? [],
        ];
    }

    private function latestDeliveredOrderEvents(Collection $events): Collection
    {
        return $this->latestOrderEvents($events)
            ->where('event_type', OperationalEvent::ORDER_DELIVERED)
            ->values();
    }

    private function latestOrderEvents(Collection $events): Collection
    {
        return $events
            ->groupBy(fn (OperationalEvent $event): string => $this->stableOrderKey($event))
            ->map(function (Collection $group): ?OperationalEvent {
                $ordered = $group->sortBy(fn (OperationalEvent $event): string => (string) $event->occurred_at?->timestamp.'-'.$event->id);
                $terminal = $ordered->whereIn('event_type', [
                    OperationalEvent::ORDER_DELIVERED,
                    OperationalEvent::ORDER_RETURNED,
                ]);

                return $terminal->isNotEmpty() ? $terminal->last() : $ordered->last();
            })
            ->filter(fn (?OperationalEvent $event): bool => $event instanceof OperationalEvent)
            ->values();
    }

    private function orderValue(OperationalEvent $event): float
    {
        return max((float) (
            data_get($event->payload, 'sale_amount')
            ?? data_get($event->payload, 'customer_total_amount')
            ?? data_get($event->payload, 'total_amount')
            ?? $event->revenue_amount
            ?? 0
        ), 0);
    }

    private function stableOrderKey(OperationalEvent $event): string
    {
        return (string) (data_get($event->payload, 'order_id') ?: $event->external_id ?: $event->id);
    }

    /**
     * @return array<int, string>
     */
    private function orderLifecycleEventTypes(): array
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
        ];
    }

    private function topSkuByField($events, string $field): ?string
    {
        $topSku = $events
            ->filter(fn (OperationalEvent $event): bool => filled($event->sku_id))
            ->groupBy('sku_id')
            ->map(function ($skuEvents) use ($field): array {
                $first = $skuEvents->first();
                $sku = $first?->sku;

                return [
                    'name' => $sku?->name ?? $sku?->code ?? 'Unknown SKU',
                    'value' => (float) $skuEvents->sum($field),
                ];
            })
            ->sortByDesc('value')
            ->first();

        if (! $topSku || $topSku['value'] <= 0) {
            return null;
        }

        return $topSku['name'];
    }
}

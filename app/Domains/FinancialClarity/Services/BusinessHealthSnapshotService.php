<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\FinancialSnapshot;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\ProductionEntry;
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

    public function previewRange(Business $business, Carbon $start, Carbon $end): array
    {
        return $this->buildSummary($business, $start->copy()->startOfDay(), $end->copy()->endOfDay());
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
        $dispatchEvents = $orderEvents
            ->whereIn('event_type', [OperationalEvent::TRACKING_NUMBER_ADDED, OperationalEvent::WHOLESALE_PARCEL_SENT])
            ->groupBy(fn (OperationalEvent $event): string => $this->stableOrderKey($event))
            ->map(fn (Collection $group): OperationalEvent => $group->sortBy('occurred_at')->last())
            ->values();
        $dispatchedParcelValue = (float) $dispatchEvents->sum(fn (OperationalEvent $event): float => $this->orderValue($event));
        $productionEntries = ProductionEntry::query()
            ->where('business_id', $business->id)
            ->whereDate('produced_on', '>=', $start->toDateString())
            ->whereDate('produced_on', '<=', $end->toDateString());
        $productionCosts = (float) (clone $events)
            ->where('source', 'manufacturing')
            ->where('event_type', OperationalEvent::SKU_PRODUCED)
            ->sum('direct_cost_amount');
        $productionPendingPay = (float) (clone $productionEntries)
            ->where('payment_status', '!=', 'paid')
            ->sum('net_payable');
        $productionReferenceCosts = (float) $dispatchEvents->sum(fn (OperationalEvent $event): float => $this->productionCostReference($event));
        $missingEstimatedProductionCosts = max($productionReferenceCosts - $productionCosts, 0);
        $productCosts = $productionCosts;
        $returnCourierCosts = (float) $orderEvents
            ->where('event_type', OperationalEvent::ORDER_RETURNED)
            ->sum(fn (OperationalEvent $event): float => (float) data_get($event->payload, 'economics.return_courier_amount', 0));
        $totalCourierCosts = $deliveredCourierCosts + $returnCourierCosts;
        $parcelGrossProfit = $recognizedOrderRevenue - $productCosts - $totalCourierCosts;
        $deliveredWithoutCourierCost = $latestDeliveredOrderEvents
            ->filter(fn (OperationalEvent $event): bool => (float) $event->direct_cost_amount <= 0)
            ->count();
        $deliveredCohorts = $this->deliveredCohorts($business, $latestDeliveredOrderEvents, $start, $end);
        $deliveredDispatchCohorts = $this->deliveredDispatchCohorts($business, $latestDeliveredOrderEvents, $start, $end);
        $unrecognizedOrderRevenue = max((float) (clone $events)->sum('revenue_amount') - $recognizedOrderRevenue, 0.0);
        $revenue = $recognizedOrderRevenue + $serviceRevenue;
        $directCosts = (clone $events)->sum('direct_cost_amount');
        $leakage = (clone $events)->sum('leakage_amount');
        $recovery = (clone $events)->sum('recovery_amount');

        $expenseQuery = Expense::query()
            ->where('business_id', $business->id)
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($query) use ($start, $end): void {
                    $query->whereDate('spent_on', '>=', $start->toDateString())
                        ->whereDate('spent_on', '<=', $end->toDateString());
                })
                    ->orWhere(function ($query) use ($end): void {
                        $query->where('recurring', true)
                            ->where('expense_type', 'fixed')
                            ->whereDate('spent_on', '<=', $end->toDateString());
                    });
            });
        $expenses = (clone $expenseQuery)->sum('amount');
        $fixedExpenses = (clone $expenseQuery)->where('expense_type', 'fixed')->sum('amount');
        $variableExpenses = (clone $expenseQuery)->where('expense_type', 'variable')->sum('amount');
        $marketingSpend = $this->expenseCategoryTotal(
            clone $expenseQuery,
            ['marketing'],
            ['marketing', 'ad spend', 'advertising', 'ads']
        );
        $bankPaymentChargeExpenses = $this->expenseCategoryTotal(
            clone $expenseQuery,
            ['bank_charge', 'payment_gateway', 'payment_fee', 'cod_fee'],
            ['bank charge', 'bank charges', 'payment fee', 'payment fees', 'payment gateway', 'cod handling fee']
        );
        $bankPaymentChargeBankRows = (float) BankTransaction::query()
            ->where(function ($query) use ($business): void {
                $query->where('business_id', $business->id)
                    ->orWhere('allocated_business_id', $business->id);
            })
            ->whereDate('transaction_date', '>=', $start->toDateString())
            ->whereDate('transaction_date', '<=', $end->toDateString())
            ->where('classification', 'bank_charge')
            ->sum('debit');
        $bankPaymentCharges = $bankPaymentChargeExpenses + $bankPaymentChargeBankRows;
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
        $initialPackagingReferenceCosts = (float) $dispatchEvents->sum(fn (OperationalEvent $event): float => $this->packagingCostReference($event));
        $returnPackagingCosts = (float) $orderEvents
            ->where('event_type', OperationalEvent::ORDER_RETURNED)
            ->sum(fn (OperationalEvent $event): float => (float) data_get($event->payload, 'economics.return_packaging_amount', 0));
        $resendPackagingCosts = (float) $orderEvents
            ->where('event_type', OperationalEvent::ORDER_RESENT)
            ->sum(fn (OperationalEvent $event): float => (float) data_get($event->payload, 'economics.resend_packaging_amount', 0));
        $packagingCosts = $initialPackagingReferenceCosts + $returnPackagingCosts + $resendPackagingCosts;
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
            ->whereDate('spent_on', '>=', $start->toDateString())
            ->whereDate('spent_on', '<=', $end->toDateString())
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

        $costTotal = $directCosts + $expenses + $salaryPressure + $bankPaymentChargeBankRows + $leakage - $recovery;
        $profit = $revenue - $costTotal;
        $profitAfterEstimatedProductionCosts = $profit - $missingEstimatedProductionCosts;

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
                'dispatched_parcel_count' => $dispatchEvents->count(),
                'dispatched_parcel_value' => $dispatchedParcelValue,
                'product_costs' => $productCosts,
                'production_costs' => $productionCosts,
                'production_cost_reference_amount' => $productionReferenceCosts,
                'missing_estimated_production_costs' => $missingEstimatedProductionCosts,
                'profit_after_estimated_production_costs' => $profitAfterEstimatedProductionCosts,
                'production_cost_trusted' => $missingEstimatedProductionCosts <= 0,
                'production_pending_pay' => $productionPendingPay,
                'return_courier_costs' => $returnCourierCosts,
                'total_courier_costs' => $totalCourierCosts,
                'parcel_gross_profit' => $parcelGrossProfit,
                'delivered_without_courier_cost_count' => $deliveredWithoutCourierCost,
                'delivered_cohorts' => $deliveredCohorts,
                'delivered_dispatch_cohorts' => $deliveredDispatchCohorts,
                'unrecognized_order_revenue' => $unrecognizedOrderRevenue,
                'fixed_expenses' => $fixedExpenses,
                'variable_expenses' => $variableExpenses,
                'marketing_spend' => $marketingSpend,
                'bank_payment_charge_expenses' => $bankPaymentChargeExpenses,
                'bank_payment_charge_bank_rows' => $bankPaymentChargeBankRows,
                'bank_payment_charges' => $bankPaymentCharges,
                'packaging_costs' => $packagingCosts,
                'initial_packaging_reference_costs' => $initialPackagingReferenceCosts,
                'return_packaging_costs' => $returnPackagingCosts,
                'resend_packaging_costs' => $resendPackagingCosts,
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

    private function productionCostReference(OperationalEvent $event): float
    {
        return max((float) (
            data_get($event->payload, 'economics.production_cost_reference_amount')
            ?? data_get($event->payload, 'economics.product_cost_amount')
            ?? 0
        ), 0);
    }

    private function packagingCostReference(OperationalEvent $event): float
    {
        if ($event->sku) {
            return max((float) $event->sku->packaging_cost * max((int) ($event->quantity ?? 1), 1), 0);
        }

        return max((float) data_get($event->payload, 'economics.packaging_amount', 0), 0);
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<int, string>  $categories
     */
    private function expenseCategoryTotal(mixed $query, array $keys, array $categories): float
    {
        $normalizedKeys = array_map(fn (string $key): string => strtolower($key), $keys);
        $normalizedCategories = array_map(fn (string $category): string => strtolower($category), $categories);

        return (float) $query->get()
            ->filter(function (Expense $expense) use ($normalizedKeys, $normalizedCategories): bool {
                $key = strtolower((string) $expense->suggested_key);
                $category = strtolower((string) $expense->category);

                return in_array($key, $normalizedKeys, true)
                    || collect($normalizedCategories)->contains(
                        fn (string $needle): bool => str_contains($category, $needle)
                    );
            })
            ->sum('amount');
    }

    private function deliveredCohorts(Business $business, Collection $deliveredEvents, Carbon $start, Carbon $end): array
    {
        $confirmationEvents = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', OperationalEvent::ORDER_CONFIRMED)
            ->where('occurred_at', '<=', $end->copy()->endOfDay())
            ->get()
            ->groupBy(fn (OperationalEvent $event): string => $this->stableOrderKey($event));

        $cohorts = [
            'confirmed_this_month' => ['count' => 0, 'value' => 0.0, 'courier_cost' => 0.0],
            'carryover_from_earlier_months' => ['count' => 0, 'value' => 0.0, 'courier_cost' => 0.0],
            'confirmation_missing' => ['count' => 0, 'value' => 0.0, 'courier_cost' => 0.0],
            'invalid_confirmation_sequence' => ['count' => 0, 'value' => 0.0, 'courier_cost' => 0.0],
        ];

        foreach ($deliveredEvents as $delivered) {
            $confirmation = $confirmationEvents
                ->get($this->stableOrderKey($delivered), collect())
                ->sortBy('occurred_at')
                ->first();
            $confirmedAt = $confirmation?->occurred_at ?? $this->payloadConfirmationAt($delivered);
            $value = max((float) $delivered->revenue_amount, 0);

            $key = match (true) {
                ! $confirmedAt => 'confirmation_missing',
                $confirmedAt->gt($delivered->occurred_at) => 'invalid_confirmation_sequence',
                $confirmedAt->lt($start->copy()->startOfDay()) => 'carryover_from_earlier_months',
                default => 'confirmed_this_month',
            };

            $cohorts[$key]['count']++;
            $cohorts[$key]['value'] += $value;
            $cohorts[$key]['courier_cost'] += max((float) $delivered->direct_cost_amount, 0);
        }

        return collect($cohorts)
            ->map(fn (array $cohort): array => [
                'count' => (int) $cohort['count'],
                'value' => round((float) $cohort['value'], 2),
                'courier_cost' => round((float) $cohort['courier_cost'], 2),
            ])
            ->all();
    }

    private function deliveredDispatchCohorts(Business $business, Collection $deliveredEvents, Carbon $start, Carbon $end): array
    {
        $dispatchEvents = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereIn('event_type', [
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
            ])
            ->where('occurred_at', '<=', $end->copy()->endOfDay())
            ->get()
            ->groupBy(fn (OperationalEvent $event): string => $this->stableOrderKey($event));

        $cohorts = [
            'dispatched_this_period' => ['count' => 0, 'value' => 0.0, 'courier_cost' => 0.0],
            'dispatched_before_period' => ['count' => 0, 'value' => 0.0, 'courier_cost' => 0.0],
            'dispatch_missing' => ['count' => 0, 'value' => 0.0, 'courier_cost' => 0.0],
            'invalid_dispatch_sequence' => ['count' => 0, 'value' => 0.0, 'courier_cost' => 0.0],
        ];

        foreach ($deliveredEvents as $delivered) {
            $dispatch = $dispatchEvents
                ->get($this->stableOrderKey($delivered), collect())
                ->sortBy('occurred_at')
                ->first();
            $dispatchedAt = $dispatch?->occurred_at ?? $this->payloadDispatchAt($delivered);
            $value = max((float) $delivered->revenue_amount, 0);

            $key = match (true) {
                ! $dispatchedAt => 'dispatch_missing',
                $dispatchedAt->gt($delivered->occurred_at) => 'invalid_dispatch_sequence',
                $dispatchedAt->lt($start->copy()->startOfDay()) => 'dispatched_before_period',
                default => 'dispatched_this_period',
            };

            $cohorts[$key]['count']++;
            $cohorts[$key]['value'] += $value;
            $cohorts[$key]['courier_cost'] += max((float) $delivered->direct_cost_amount, 0);
        }

        return collect($cohorts)
            ->map(fn (array $cohort): array => [
                'count' => (int) $cohort['count'],
                'value' => round((float) $cohort['value'], 2),
                'courier_cost' => round((float) $cohort['courier_cost'], 2),
            ])
            ->all();
    }

    private function payloadConfirmationAt(OperationalEvent $event): ?Carbon
    {
        foreach (['confirmed_at', 'order_confirmed_at', 'client_confirmed_at'] as $key) {
            $value = data_get($event->payload, $key);

            if (filled($value)) {
                try {
                    return Carbon::parse($value);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private function payloadDispatchAt(OperationalEvent $event): ?Carbon
    {
        foreach (['dispatched_at', 'tracking_added_at', 'tracking_number_added_at', 'courier_sent_at', 'shipped_at'] as $key) {
            $value = data_get($event->payload, $key);

            if (filled($value)) {
                try {
                    return Carbon::parse($value);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
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

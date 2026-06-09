<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\FinancialSnapshot;
use App\Domains\Shared\Models\OperationalEvent;
use Illuminate\Support\Carbon;

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
        $snapshot = $this->readCurrentMonth($business);

        if ($snapshot instanceof FinancialSnapshot) {
            return $this->snapshotToArray($snapshot);
        }

        return $this->previewCurrentMonth($business);
    }

    private function buildSummary(Business $business, Carbon $start, Carbon $end): array
    {
        $events = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [$start->startOfDay(), $end->endOfDay()]);

        $revenue = (clone $events)->sum('revenue_amount');
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

        $returnImpact = (clone $events)->where('event_type', OperationalEvent::ORDER_RETURNED)->sum('leakage_amount');
        $resendImpact = (clone $events)->where('event_type', OperationalEvent::ORDER_RESENT)->sum('leakage_amount');
        $fakeImpact = (clone $events)->where('event_type', OperationalEvent::FAKE_ORDER_DETECTED)->sum('leakage_amount');
        $courierImpact = (clone $events)
            ->whereIn('event_type', [OperationalEvent::TRACKING_NUMBER_ADDED, OperationalEvent::ORDER_RESENT, OperationalEvent::ORDER_RETURNED])
            ->sum('direct_cost_amount');
        $confirmationImpact = (clone $events)
            ->whereIn('event_type', [OperationalEvent::ORDER_CREATED, OperationalEvent::ORDER_CONFIRMED])
            ->sum('direct_cost_amount');
        $orderCounts = [
            'confirmed' => (clone $events)->where('event_type', OperationalEvent::ORDER_CONFIRMED)->count(),
            'tracking_added' => (clone $events)->where('event_type', OperationalEvent::TRACKING_NUMBER_ADDED)->count(),
            'delivered' => (clone $events)->where('event_type', OperationalEvent::ORDER_DELIVERED)->count(),
            'returned' => (clone $events)->where('event_type', OperationalEvent::ORDER_RETURNED)->count(),
            'resent' => (clone $events)->where('event_type', OperationalEvent::ORDER_RESENT)->count(),
            'fake' => (clone $events)->where('event_type', OperationalEvent::FAKE_ORDER_DETECTED)->count(),
        ];
        $topLossSku = $this->topSkuByField((clone $events)->get(), 'leakage_amount');
        $topRevenueSku = $this->topSkuByField((clone $events)->get(), 'revenue_amount');
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
                'top_loss_sku' => $topLossSku,
                'top_revenue_sku' => $topRevenueSku,
                'top_expense_categories' => $topExpenseCategories,
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

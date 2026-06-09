<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\FinancialSnapshot;

class BusinessExplainabilityService
{
    public function __construct(
        private readonly BusinessHealthSnapshotService $snapshots,
        private readonly CashIntelligenceService $cashIntelligence,
        private readonly CapitalIntelligenceService $capitalIntelligence,
        private readonly InventoryIntelligenceService $inventoryIntelligence,
    )
    {
    }

    public function forCurrentMonth(Business $business): array
    {
        $current = $this->snapshots->currentMonthSummary($business);
        $previous = $this->previousSnapshotSummary($business);
        $cash = $this->cashIntelligence->forCurrentMonth($business);
        $capital = $this->capitalIntelligence->forCurrentMonth($business);
        $inventory = $this->inventoryIntelligence->forCurrentMonth($business);

        $metrics = $current['metrics'] ?? [];
        $previousMetrics = $previous['metrics'] ?? [];
        $topRevenueSku = $metrics['top_revenue_sku'] ?? null;
        $previousTopRevenueSku = $previousMetrics['top_revenue_sku'] ?? null;
        $topLossSku = $metrics['top_loss_sku'] ?? null;

        $currentRevenue = (float) ($current['revenue_total'] ?? 0);
        $currentCosts = (float) ($current['cost_total'] ?? 0);
        $currentLeakage = (float) ($current['leakage_total'] ?? 0);
        $currentProfit = (float) ($current['estimated_profit'] ?? 0);
        $currentFixed = (float) ($metrics['fixed_expenses'] ?? 0);
        $currentVariable = (float) ($metrics['variable_expenses'] ?? 0);
        $currentSalary = (float) ($metrics['salary_pressure'] ?? 0);
        $currentDirect = (float) ($metrics['direct_operational_costs'] ?? 0);
        $currentRecovered = (float) ($metrics['recovered_value'] ?? 0);
        $currentCashDue = max((float) ($metrics['to_settle'] ?? 0), 0);
        $currentEventCount = (int) ($metrics['event_count'] ?? 0);
        $currentBankMovement = (float) ($cash['net_movement'] ?? $this->bankNetMovement($business));
        $currentBankCount = $this->bankTransactionCount($business);

        $previousRevenue = (float) ($previous['revenue_total'] ?? 0);
        $previousCosts = (float) ($previous['cost_total'] ?? 0);
        $previousLeakage = (float) ($previous['leakage_total'] ?? 0);
        $previousProfit = (float) ($previous['estimated_profit'] ?? 0);
        $previousFixed = (float) ($previousMetrics['fixed_expenses'] ?? 0);
        $previousVariable = (float) ($previousMetrics['variable_expenses'] ?? 0);
        $previousSalary = (float) ($previousMetrics['salary_pressure'] ?? 0);
        $previousDirect = (float) ($previousMetrics['direct_operational_costs'] ?? 0);
        $previousRecovered = (float) ($previousMetrics['recovered_value'] ?? 0);
        $previousCashDue = max((float) ($previousMetrics['to_settle'] ?? 0), 0);
        $previousEventCount = (int) ($previousMetrics['event_count'] ?? 0);
        $previousBankMovement = $previous ? $this->bankNetMovement($business, $previous['period_start'], $previous['period_end']) : 0.0;

        $orderCounts = $metrics['order_counts'] ?? [];
        $previousOrderCounts = $previousMetrics['order_counts'] ?? [];

        $whatHappened = [
            $this->metricStory(
                'Money coming in',
                $currentRevenue,
                $previousRevenue,
                $this->describeRevenueChange($currentRevenue, $previousRevenue, (int) ($orderCounts['delivered'] ?? 0), (int) ($previousOrderCounts['delivered'] ?? 0)),
                'High'
            ),
            $this->metricStory(
                'Money already spent',
                $currentCosts,
                $previousCosts,
                $this->describeExpenseChange($currentCosts, $previousCosts, $currentFixed, $currentVariable, $currentSalary),
                $this->confidence($previous ? 2 : 1)
            ),
            $this->metricStory(
                'Money left after running the business',
                $currentProfit,
                $previousProfit,
                $this->describeProfitChange($currentProfit, $previousProfit, $currentRevenue, $currentCosts, $currentLeakage, $currentRecovered),
                $this->confidence($previous ? 2 : 1)
            ),
            $this->metricStory(
                'Money still waiting to settle',
                $currentBankMovement - $currentCashDue,
                $previousBankMovement - $previousCashDue,
                $this->describeCashChange($currentBankMovement, $currentCashDue, $previousBankMovement, $previousCashDue, $currentBankCount),
                $this->confidence($currentBankCount > 0 ? 2 : 1)
            ),
            $this->metricStory(
                'Business position',
                $currentProfit,
                $previousProfit,
                $this->describeClientProfitability($currentProfit, $previousProfit, $currentLeakage, $currentCashDue),
                $this->confidence($previous ? 2 : 1)
            ),
            $this->metricStory(
                'Product line pressure',
                (float) ($topRevenueSku ? $currentProfit : 0),
                (float) ($previousTopRevenueSku ? $previousProfit : 0),
                $this->describeSkuProfitability($business, $metrics, $previousMetrics, $currentLeakage),
                $this->confidence($business->supportsSkuManagement() ? 2 : 1)
            ),
            $this->metricStory(
                'Overall business picture',
                $currentProfit - $currentCashDue,
                $previousProfit - $previousCashDue,
                $this->describeBusinessHealth($currentProfit, $currentCashDue, $currentLeakage, $currentEventCount),
                $this->confidence($previous ? 2 : 1)
            ),
        ];

        $whyItHappened = [
            [
                'metric' => 'Money coming in',
                'primary_driver' => $currentRevenue >= $previousRevenue ? 'Delivered volume is holding the money coming in up.' : 'Delivered volume softened this month.',
                'secondary_drivers' => array_filter([
                    $this->differenceLine('Delivered orders', (int) ($orderCounts['delivered'] ?? 0), (int) ($previousOrderCounts['delivered'] ?? 0)),
                    $this->differenceLine('Confirmed orders', (int) ($orderCounts['confirmed'] ?? 0), (int) ($previousOrderCounts['confirmed'] ?? 0)),
                    $topRevenueSku ? 'Top earning product: '.$topRevenueSku : null,
                ]),
                'supporting_evidence' => [
                    'Money coming in now: LKR '.number_format($currentRevenue, 2),
                    'Money coming in previous: LKR '.number_format($previousRevenue, 2),
                    'Change: '.$this->formatDelta($currentRevenue - $previousRevenue, true),
                ],
                'confidence' => $this->confidenceLabel($previous),
            ],
            [
                'metric' => 'Money already spent',
                'primary_driver' => $currentCosts >= $previousCosts ? 'Money already spent is rising faster than the money coming in.' : 'Money already spent is lighter than the previous month.',
                'secondary_drivers' => array_filter([
                    'Fixed costs: '.$this->formatDelta($currentFixed - $previousFixed),
                    'Variable costs: '.$this->formatDelta($currentVariable - $previousVariable),
                    'Salary pressure: '.$this->formatDelta($currentSalary - $previousSalary),
                ]),
                'supporting_evidence' => [
                    'Money already spent now: LKR '.number_format($currentCosts, 2),
                    'Money already spent previous: LKR '.number_format($previousCosts, 2),
                    'Direct running cost change: '.$this->formatDelta($currentDirect - $previousDirect, true),
                ],
                'confidence' => $this->confidenceLabel($previous),
            ],
            [
                'metric' => 'Money left after running the business',
                'primary_driver' => $currentProfit >= $previousProfit ? 'Money left after running the business improved because the balance between money coming in and money already spent is better.' : 'Money left after running the business weakened because costs and returns pressure absorbed more of the income line.',
                'secondary_drivers' => array_filter([
                    $currentLeakage > $previousLeakage ? 'Returns or retry loss increased by '.$this->formatDelta($currentLeakage - $previousLeakage, true) : null,
                    $currentRecovered > $previousRecovered ? 'Recovered value improved by '.$this->formatDelta($currentRecovered - $previousRecovered, true) : null,
                    $currentCashDue > $previousCashDue ? 'Money still waiting to settle increased.' : 'Money still waiting to settle eased.',
                ]),
                'supporting_evidence' => [
                    'Money left after running the business now: LKR '.number_format($currentProfit, 2),
                    'Money left after running the business previous: LKR '.number_format($previousProfit, 2),
                    'Change: '.$this->formatDelta($currentProfit - $previousProfit, true),
                ],
                'confidence' => $this->confidenceLabel($previous),
            ],
            [
                'metric' => 'Money still waiting to settle',
                'primary_driver' => $currentCashDue > $previousCashDue ? 'More money is still waiting to be settled.' : 'Settlement pressure is lighter than before.',
                'secondary_drivers' => array_filter([
                    $currentBankCount > 0 ? 'Bank rows imported: '.$currentBankCount : null,
                    $currentBankMovement !== 0.0 ? 'Net money movement in the bank: LKR '.number_format($currentBankMovement, 2) : null,
                ]),
                'supporting_evidence' => [
                    'Money still waiting to settle now: LKR '.number_format($currentCashDue, 2),
                    'Money still waiting to settle previous: LKR '.number_format($previousCashDue, 2),
                    'Net money movement in the bank: LKR '.number_format($currentBankMovement, 2),
                ],
                'confidence' => $currentBankCount > 0 ? 'High' : 'Medium',
            ],
            [
                'metric' => 'Business position',
                'primary_driver' => $currentProfit >= 0 ? 'The business is still generating value.' : 'The business is not yet protecting margin well enough.',
                'secondary_drivers' => array_filter([
                    $currentLeakage > 0 ? 'Returns or retry loss is visible in the month.' : null,
                    $currentCashDue > 0 ? 'Settlement pressure remains visible.' : null,
                ]),
                'supporting_evidence' => [
                    'Current money left after running the business: LKR '.number_format($currentProfit, 2),
                    'Current money coming in: LKR '.number_format($currentRevenue, 2),
                ],
                'confidence' => $this->confidenceLabel($previous),
            ],
            [
                'metric' => 'Product line pressure',
                'primary_driver' => $topLossSku ? 'The strongest loss is tied to '.$topLossSku.'.' : 'Product-level pressure is not yet visible.',
                'secondary_drivers' => array_filter([
                    $topRevenueSku ? 'Best earning product: '.$topRevenueSku : null,
                    $currentLeakage > 0 ? 'Return pressure is affecting product economics.' : null,
                ]),
                'supporting_evidence' => [
                    'Top loss product: '.($topLossSku ?? 'none'),
                    'Top earning product: '.($topRevenueSku ?? 'none'),
                ],
                'confidence' => $business->supportsSkuManagement() ? 'Medium' : 'Low',
            ],
            [
                'metric' => 'Overall business picture',
                'primary_driver' => $currentProfit >= 0 ? 'The business remains strong enough to keep improving.' : 'The business needs pressure relief before scaling further.',
                'secondary_drivers' => array_filter([
                    $currentLeakage > 0 ? 'Returns or retry loss is affecting the picture.' : null,
                    $currentCashDue > 0 ? 'Cash settlement pressure remains active.' : null,
                    $currentEventCount > 0 ? 'Operational activity is present.' : null,
                ]),
                'supporting_evidence' => [
                    'Business picture proxy: LKR '.number_format($currentProfit - $currentCashDue, 2),
                    'Event count: '.$currentEventCount,
                ],
                'confidence' => $this->confidenceLabel($previous),
            ],
        ];

        $opportunities = $this->buildOpportunities(
            $business,
            $current,
            $previous,
            $currentRevenue,
            $currentProfit,
            $currentCashDue,
            $currentLeakage,
            $currentSalary,
            $currentDirect,
            $metrics,
            $orderCounts,
            $capital,
            $inventory
        );

        $alerts = $this->buildAlerts(
            $currentProfit,
            $currentCashDue,
            $currentSalary,
            $currentRevenue,
            $currentDirect,
            $orderCounts
        );

        $actions = collect(array_merge(
            $this->buildActionHints($business, $alerts, $opportunities, $metrics, $currentCashDue, $capital, $inventory),
            $this->legacyActionHints($business, $metrics, $currentCashDue, $currentProfit, $orderCounts)
        ))->filter()->unique()->take(3)->values()->all();

        $briefing = [
            'critical_alerts' => array_slice($alerts, 0, 3),
            'opportunities' => array_slice($opportunities, 0, 3),
            'cash_position' => [
                'headline' => $currentCashDue > 0
                    ? 'Cash settlement pressure remains visible.'
                    : 'Cash settlement pressure is low for now.',
                'bank_net_movement' => $currentBankMovement,
                'cash_due' => $currentCashDue,
                'bank_imports' => $currentBankCount,
                'confidence' => $currentBankCount > 0 ? 'High' : 'Medium',
            ],
            'profit_position' => [
                'headline' => $currentProfit >= $previousProfit
                    ? 'Profit is holding better than the previous month.'
                    : 'Profit is weaker than the previous month.',
                'profit' => $currentProfit,
                'revenue' => $currentRevenue,
                'costs' => $currentCosts,
                'change' => $currentProfit - $previousProfit,
            ],
            'capital_position' => [
                'headline' => $capital['headline'] ?? 'Capital signal is not ready yet.',
                'cash_after_obligations_proxy' => $capital['cash_after_obligations_proxy'] ?? 0,
                'capital_committed' => $capital['capital_committed'] ?? 0,
                'value_signal' => $capital['value_signal'] ?? 0,
                'confidence' => $capital['confidence'] ?? 'Medium',
            ],
            'inventory_position' => [
                'headline' => $inventory['headline'] ?? 'Inventory signal is not ready yet.',
                'recipe_coverage' => $inventory['recipe_coverage'] ?? 0,
                'flow_gap' => $inventory['flow_gap'] ?? 0,
                'waste_ratio' => $inventory['waste_ratio'] ?? 0,
                'confidence' => $inventory['confidence'] ?? 'Medium',
            ],
            'risks' => $this->buildRisks($business, $currentProfit, $currentCashDue, $currentSalary, $currentLeakage, $metrics, $orderCounts),
            'recommended_actions' => $actions,
            'expected_impact' => array_map(fn (array $opportunity): array => [
                'title' => $opportunity['title'],
                'impact' => $opportunity['expected_benefit'],
                'confidence' => $opportunity['confidence'],
            ], array_slice($opportunities, 0, 3)),
        ];

        $decisionStory = $this->buildDecisionStory(
            $whatHappened,
            $whyItHappened,
            $alerts,
            $opportunities,
            $actions,
            $currentProfit,
            $currentCashDue,
            $currentLeakage,
            $currentEventCount
        );

        $briefing['decision_story'] = $decisionStory;

        return [
            'period_label' => now()->format('F Y'),
            'revenue' => $currentRevenue,
            'direct_costs' => $currentDirect,
            'leakage' => $currentLeakage,
            'recovery' => $currentRecovered,
            'fixed_expenses' => $currentFixed,
            'variable_expenses' => $currentVariable,
            'salary_pressure' => $currentSalary,
            'cash_due' => $currentCashDue,
            'profit' => $currentProfit,
            'order_counts' => [
                'confirmed' => (int) ($orderCounts['confirmed'] ?? 0),
                'tracking_added' => (int) ($orderCounts['tracking_added'] ?? 0),
                'delivered' => (int) ($orderCounts['delivered'] ?? 0),
                'returned' => (int) ($orderCounts['returned'] ?? 0),
                'resent' => (int) ($orderCounts['resent'] ?? 0),
                'fake' => (int) ($orderCounts['fake'] ?? 0),
            ],
            'top_loss_sku' => $metrics['top_loss_sku'] ?? null,
            'top_revenue_sku' => $metrics['top_revenue_sku'] ?? null,
            'return_impact' => (float) ($metrics['return_impact'] ?? 0),
            'courier_impact' => (float) ($metrics['courier_impact'] ?? 0),
            'confirmation_impact' => (float) ($metrics['confirmation_impact'] ?? 0),
            'alerts' => $alerts,
            'actions' => $actions,
            'what_happened' => $whatHappened,
            'why_it_happened' => $whyItHappened,
            'opportunities' => $opportunities,
            'briefing' => $briefing,
            'decision_story' => $decisionStory,
            'capital_intelligence' => $capital,
            'inventory_intelligence' => $inventory,
        ];
    }

    private function previousSnapshotSummary(Business $business): ?array
    {
        $snapshot = FinancialSnapshot::query()
            ->where('business_id', $business->id)
            ->whereDate('period_start', '<', now()->startOfMonth()->toDateString())
            ->latest('period_end')
            ->first();

        if (! $snapshot instanceof FinancialSnapshot) {
            return null;
        }

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

    private function bankNetMovement(Business $business, ?string $start = null, ?string $end = null): float
    {
        $query = BankTransaction::query()
            ->where('business_id', $business->id);

        if ($start && $end) {
            $query->whereBetween('transaction_date', [$start, $end]);
        } else {
            $query->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        }

        return (float) $query->sum('credit') - (float) $query->sum('debit');
    }

    private function bankTransactionCount(Business $business): int
    {
        return BankTransaction::query()
            ->where('business_id', $business->id)
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->count();
    }

    private function metricStory(string $metric, float $current, float $previous, string $summary, string $confidence): array
    {
        return [
            'metric' => $metric,
            'current' => $current,
            'previous' => $previous,
            'delta' => $current - $previous,
            'direction' => $this->direction($current - $previous),
            'summary' => $summary,
            'confidence' => $confidence,
        ];
    }

    private function buildOpportunities(Business $business, array $current, ?array $previous, float $revenue, float $profit, float $cashDue, float $leakage, float $salaryPressure, float $directCosts, array $metrics, array $orderCounts, array $capital, array $inventory): array
    {
        $opportunities = [];

        if ($cashDue > 0) {
            $opportunities[] = $this->opportunity(
                'Money still waiting to settle',
                'Settle waiting balances',
                'Use money discipline to reduce the recorded settlement pressure.',
                'LKR '.number_format($cashDue, 2).' remains waiting to be settled.',
                'High',
                'Lower pressure on cash and cleaner monthly reporting.',
                'Delay low-priority spend until the waiting amount is reduced.'
            );
        }

        if ($leakage > 0) {
            $opportunities[] = $this->opportunity(
                'Money left after running the business',
                'Reduce returns and retry loss',
                'Returns, retry costs, or fake-order pressure are already visible.',
                'Loss from returns and retries is recorded in this month.',
                $business->supportsProductionTracking() ? 'Medium' : 'High',
                'Protect margin and improve true profit.',
                'Fix return-heavy patterns and tighten verification.'
            );
        }

        if (($metrics['top_revenue_sku'] ?? null) !== null) {
            $opportunities[] = $this->opportunity(
                'Product',
                'Protect the strongest product',
                'The current month already has a leading earning product.',
                'Top earning product: '.$metrics['top_revenue_sku'],
                $business->supportsSkuManagement() ? 'High' : 'Medium',
                'Keep the strongest revenue line stable while the weaker lines are reviewed.',
                'Do not starve the winning product of stock or attention.'
            );
        }

        if (($metrics['top_loss_sku'] ?? null) !== null) {
            $opportunities[] = $this->opportunity(
                'Product',
                'Review the weakest product',
                'A product is showing the most recorded pressure this month.',
                'Top loss product: '.$metrics['top_loss_sku'],
                $business->supportsSkuManagement() ? 'Medium' : 'Low',
                'Reduce avoidable margin loss.',
                'Check whether pricing, courier, or return handling is hurting this product.'
            );
        }

        if ($salaryPressure > 0 && $revenue > 0 && $salaryPressure > ($revenue * 0.4)) {
            $opportunities[] = $this->opportunity(
                'People',
                'Review payroll pressure',
                'Fixed people cost is taking a large share of money coming in.',
                'People cost is above 40% of money coming in.',
                'Medium',
                'Improve cash and profit efficiency.',
                'Check whether team size matches order volume.'
            );
        }

        if ($directCosts > 0 && $revenue > 0 && $directCosts > ($revenue * 0.35)) {
            $opportunities[] = $this->opportunity(
                'Operations',
                'Trim running cost',
                'Running cost is taking a large share of money coming in.',
                'Direct cost is above the comfort threshold.',
                'Medium',
                'Protect profit margin.',
                'Review courier, production, and verification pressure.'
            );
        }

        if ($business->supportsInventoryIntelligence() && ($metrics['top_loss_sku'] ?? null)) {
            $opportunities[] = $this->opportunity(
                'Stock',
                'Shift attention to tied-up money in stock',
                'The business type can support stock intelligence.',
                'Stock-related pressure can be explored further.',
                'Medium',
                'Release money from weak or slow stock.',
                'Check the stock-to-cash path for the losing product.'
            );
        }

        if ($profit > 0) {
            $opportunities[] = $this->opportunity(
                'Growth',
                'Scale the healthier pattern',
                'The month is still strong enough to support careful growth.',
                'Money left after running the business remains positive.',
                'Medium',
                'Increase volume where the margin is healthy.',
                'Only scale what is already working.'
            );
        }

        if ($business->supportsBusinessType(Business::TYPE_SERVICE) && $profit > 0) {
            $opportunities[] = $this->opportunity(
                'Clients',
                'Protect the better client work',
                'Service businesses win through client quality and money discipline.',
                'Business is still making positive profit.',
                'High',
                'Focus on better clients and cleaner billing.',
                'Avoid low-quality work that drags the month down.'
            );
        }

        if ($business->supportsProductionTracking() && ($metrics['top_revenue_sku'] ?? null)) {
            $opportunities[] = $this->opportunity(
                'Production',
                'Protect production flow on the best product',
                'A manufacturing business should keep strong production lines flowing.',
                'Top earning product can be protected in production.',
                'Medium',
                'Keep labor and material aligned with actual demand.',
                'Do not overproduce the losing product.'
            );
        }

        if (($capital['capital_committed'] ?? 0) > 0) {
            $opportunities[] = $this->opportunity(
                'Capital',
                'Release tied-up money',
                'The capital view shows money committed to obligations, materials, or production.',
                'Money tied up: LKR '.number_format((float) ($capital['capital_committed'] ?? 0), 2),
                'Medium',
                'Release pressure on cash and improve working capital.',
                'Clear the oldest commitments first and delay new spend until the pressure eases.'
            );
        }

        if (($inventory['activated'] ?? false) && (($inventory['material_waste_total'] ?? 0) > 0 || ($inventory['flow_gap'] ?? 0) > 0)) {
            $opportunities[] = $this->opportunity(
                'Inventory',
                'Reduce material waste and stock gap',
                'The stock view shows a gap between material spend and what the month is consuming.',
                'Flow gap: LKR '.number_format((float) ($inventory['flow_gap'] ?? 0), 2),
                'Medium',
                'Free up tied-up material value and reduce waste.',
                'Review waste, adjustments, and the BOM coverage on active products.'
            );
        }

        return collect($opportunities)
            ->unique(fn (array $opportunity): string => $opportunity['category'].'|'.$opportunity['title'])
            ->values()
            ->all();
    }

    private function buildAlerts(float $profit, float $cashDue, float $salaryPressure, float $revenue, float $directCosts, array $orderCounts): array
    {
        $alerts = [];

        if ($profit < 0) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'Money left after running the business is under pressure',
                'message' => 'The current month is still losing money. Review costs and returns first.',
            ];
        }

        if ($cashDue > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Money still waiting to settle',
                'message' => 'There is LKR '.number_format($cashDue, 2).' still waiting to be settled from recorded spend.',
            ];
        }

        if ((int) ($orderCounts['returned'] ?? 0) > max((int) ($orderCounts['delivered'] ?? 0), 1)) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'Returns are higher than deliveries',
                'message' => 'That usually means the business is losing margin through returns, courier pressure, or weak order quality.',
            ];
        }

        if ((int) ($orderCounts['resent'] ?? 0) > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Retry cost is active',
                'message' => 'Resends are happening this month. Treat them as retry pressure, not new product cost.',
            ];
        }

        if ((int) ($orderCounts['fake'] ?? 0) > 0) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'Fake orders are creating leakage',
                'message' => 'Fake orders are creating avoidable operational loss. Tighten verification and confirmation discipline.',
            ];
        }

        if ($salaryPressure > 0 && $revenue > 0 && $salaryPressure > ($revenue * 0.4)) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'People cost is heavy',
                'message' => 'Fixed people cost is taking a large share of money coming in this month. Check whether output is matching payroll load.',
            ];
        }

        if ($directCosts > 0 && $revenue > 0 && $directCosts > ($revenue * 0.35)) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Running cost is high',
                'message' => 'Delivery, verification, and production cost are taking too much of the month\'s money coming in.',
            ];
        }

        if ($alerts === []) {
            $alerts[] = [
                'type' => 'success',
                'title' => 'Operations look steady',
                'message' => 'No major margin warning appeared in the current month so far. Keep watching returns and money still waiting to settle.',
            ];
        }

        return $alerts;
    }

    private function buildActionHints(Business $business, array $alerts, array $opportunities, array $metrics, float $cashDue, array $capital, array $inventory): array
    {
        $actions = [];

        foreach ($alerts as $alert) {
            if (($alert['type'] ?? null) === 'danger' || ($alert['type'] ?? null) === 'warning') {
                $actions[] = $alert['title'];
            }
        }

        foreach ($opportunities as $opportunity) {
            $actions[] = $opportunity['action'];
        }

        if ($business->supportsExplainability()) {
            $actions[] = 'Use the what-happened and why-it-matters view before changing anything major.';
        }

        if ($business->supportsCashIntelligence()) {
            $actions[] = 'Check the money still waiting to settle before approving new spend.';
        }

        if ($business->supportsInventoryIntelligence()) {
            $actions[] = 'Review slow stock and production pressure before ordering or producing more.';
        }

        if ($business->supportsProductionTracking()) {
            $actions[] = 'Keep weekly production pay tied to actual output and the right product.';
        }

        if ($cashDue > 0) {
            $actions[] = 'Reduce waiting balances first so the money picture stays readable.';
        }

        if (($capital['capital_committed'] ?? 0) > 0) {
            $actions[] = 'Review the oldest commitments in the capital view before adding new spend.';
        }

        if (($inventory['activated'] ?? false) && (($inventory['flow_gap'] ?? 0) > 0 || ($inventory['material_waste_total'] ?? 0) > 0)) {
            $actions[] = 'Trim waste and close BOM gaps so stock does not trap more money.';
        }

        return $actions;
    }

    private function legacyActionHints(Business $business, array $metrics, float $cashDue, float $profit, array $orderCounts): array
    {
        return [
            $profit < 0 ? 'Focus on the top loss product and the highest-return courier first.' : null,
            $cashDue > 0 ? 'Settle waiting balances early so the money picture stays clear.' : null,
            ($orderCounts['returned'] ?? 0) > 0 ? 'Review return reasons and order quality before scaling more volume.' : null,
            ($orderCounts['resent'] ?? 0) > 0 ? 'Track retry orders separately so pressure stays visible.' : null,
            ($metrics['top_loss_sku'] ?? null) ? 'Reduce pressure on '.$metrics['top_loss_sku'].' if it keeps losing margin.' : null,
            ($metrics['top_revenue_sku'] ?? null) ? 'Protect the strongest product: '.$metrics['top_revenue_sku'].' is helping money coming in most.' : null,
        ];
    }

    private function buildRisks(Business $business, float $profit, float $cashDue, float $salaryPressure, float $leakage, array $metrics, array $orderCounts): array
    {
        $risks = [];

        if ($profit < 0) {
            $risks[] = 'Money left after running the business risk: the business is still losing money this month.';
        }

        if ($cashDue > 0) {
            $risks[] = 'Money still waiting to settle risk: waiting balances remain active.';
        }

        if ($leakage > 0) {
            $risks[] = 'Leakage risk: returns or retry cost are already visible.';
        }

        if ($salaryPressure > 0) {
            $risks[] = 'People cost risk: salary pressure is part of the month\'s load.';
        }

        if (($metrics['top_loss_sku'] ?? null) !== null) {
            $risks[] = 'Product risk: '.$metrics['top_loss_sku'].' is the main pressure point.';
        }

        if ((int) ($orderCounts['fake'] ?? 0) > 0) {
            $risks[] = 'Verification risk: fake orders are creating avoidable loss.';
        }

        if ($business->supportsInventoryIntelligence()) {
            $risks[] = 'Stock risk: tied-up money could become more important as the business grows.';
        }

        return array_values(array_unique($risks));
    }

    private function buildDecisionStory(array $whatHappened, array $whyItHappened, array $alerts, array $opportunities, array $actions, float $profit, float $cashDue, float $leakage, int $eventCount): array
    {
        $businessHealthStory = $this->storyByMetric($whatHappened, 'Overall business picture') ?? [];
        $businessHealthWhy = $this->storyByMetric($whyItHappened, 'Overall business picture') ?? [];
        $profitStory = $this->storyByMetric($whatHappened, 'Money left after running the business') ?? [];
        $cashStory = $this->storyByMetric($whatHappened, 'Money still waiting to settle') ?? [];
        $primaryAlert = $alerts[0] ?? null;
        $primaryOpportunity = $opportunities[0] ?? null;
        $primaryAction = $actions[0] ?? 'Review the briefing before changing anything major.';

        $headline = match (true) {
            ($primaryAlert['type'] ?? null) === 'danger' => $primaryAlert['title'] ?? 'The month needs attention.',
            $profit < 0 => 'Money left after running the business is under pressure.',
            $cashDue > 0 => 'Money still waiting to settle still needs attention.',
            $leakage > 0 => 'Returns or retry loss is affecting the month.',
            ! empty($primaryOpportunity) => 'There is a clear next step to improve the month.',
            default => 'The month is readable and mostly stable.',
        };

        return [
            'headline' => $headline,
            'what_happened' => $businessHealthStory['summary'] ?? ($profitStory['summary'] ?? ($cashStory['summary'] ?? 'The month has been measured across money coming in, money left after running the business, money still waiting to settle, returns, stock, and tied-up money.')),
            'why_it_matters' => $businessHealthWhy['summary'] ?? ($businessHealthWhy['primary_driver'] ?? 'The month matters because it affects money left after running the business, money still waiting to settle, and running pressure together.'),
            'risk' => $primaryAlert['message'] ?? ($this->storyRisk($whatHappened, $profit, $cashDue, $leakage, $eventCount) ?? 'No major risk surfaced yet.'),
            'opportunity' => ! empty($primaryOpportunity)
                ? ($primaryOpportunity['title'].' - '.$primaryOpportunity['evidence'])
                : 'No clear opportunity surfaced yet.',
            'first_action' => $primaryAction,
            'supporting_signals' => array_values(array_filter([
                $profitStory['summary'] ?? null,
                $cashStory['summary'] ?? null,
                $primaryOpportunity['confidence'] ?? null ? 'Opportunity confidence: '.$primaryOpportunity['confidence'] : null,
            ])),
        ];
    }

    private function storyByMetric(array $stories, string $metric): ?array
    {
        foreach ($stories as $story) {
            if (($story['metric'] ?? null) === $metric) {
                return $story;
            }
        }

        return null;
    }

    private function storyRisk(array $whatHappened, float $profit, float $cashDue, float $leakage, int $eventCount): ?string
    {
        $story = $this->storyByMetric($whatHappened, 'Overall business picture')
            ?? $this->storyByMetric($whatHappened, 'Money left after running the business')
            ?? $this->storyByMetric($whatHappened, 'Money still waiting to settle');

        if (($story['summary'] ?? null) !== null && $profit < 0) {
            return 'Money left after running the business risk: '.$story['summary'];
        }

        if ($cashDue > 0) {
            return 'Money still waiting to settle risk: waiting balances still need attention.';
        }

        if ($leakage > 0) {
            return 'Returns or retry loss risk: the month is already showing pressure.';
        }

        if ($eventCount > 0) {
            return 'Operational activity is present, so changes should follow the evidence.';
        }

        return $story['summary'] ?? null;
    }

    private function opportunity(string $category, string $title, string $evidence, string $supportingEvidence, string $confidence, string $expectedBenefit, string $action): array
    {
        return [
            'category' => $category,
            'title' => $title,
            'evidence' => $evidence,
            'supporting_evidence' => $supportingEvidence,
            'confidence' => $confidence,
            'expected_benefit' => $expectedBenefit,
            'action' => $action,
            'risk' => $expectedBenefit,
        ];
    }

    private function describeRevenueChange(float $current, float $previous, int $currentDelivered, int $previousDelivered): string
    {
        if ($current === $previous) {
            return 'Money coming in is flat against the last saved month.';
        }

        $movement = $current > $previous ? 'up' : 'down';
        $deliveryLine = $currentDelivered > $previousDelivered ? 'delivered orders improved' : 'delivered orders weakened';

        return 'Money coming in is '.$movement.' because '.$deliveryLine.' and the month is carrying a different order mix.';
    }

    private function describeExpenseChange(float $currentCosts, float $previousCosts, float $currentFixed, float $currentVariable, float $currentSalary): string
    {
        if ($currentCosts === $previousCosts) {
            return 'Money already spent is steady against the last saved month.';
        }

        $movement = $currentCosts > $previousCosts ? 'up' : 'down';
        $pressure = $currentVariable > $currentFixed ? 'variable spending' : 'fixed pressure';

        return 'Money already spent is '.$movement.' because '.$pressure.' and people cost are moving differently this month.';
    }

    private function describeProfitChange(float $currentProfit, float $previousProfit, float $revenue, float $costs, float $leakage, float $recovery): string
    {
        if ($currentProfit === $previousProfit) {
            return 'Money left after running the business is flat against the last saved month.';
        }

        if ($currentProfit > $previousProfit) {
            return 'Money left after running the business improved because money coming in covers money already spent and returns pressure better.';
        }

        return 'Money left after running the business weakened because money already spent and returns pressure are taking more of the income line.';
    }

    private function describeCashChange(float $currentBankMovement, float $currentCashDue, float $previousBankMovement, float $previousCashDue, int $bankCount): string
    {
        if ($bankCount === 0) {
            return 'Money movement is still partial because no bank statements have been imported for the current month.';
        }

        if ($currentCashDue > $previousCashDue) {
            return 'Money still waiting to settle is higher because unsettled balances have increased.';
        }

        return 'Money movement is visible through bank imports, but settlement pressure is lighter than the prior month.';
    }

    private function describeClientProfitability(float $currentProfit, float $previousProfit, float $currentLeakage, float $currentCashDue): string
    {
        if ($currentProfit >= $previousProfit) {
            return 'The business is stronger or steadier than the last saved month.';
        }

        if ($currentLeakage > 0 || $currentCashDue > 0) {
            return 'The business is under pressure because leakage and money waiting to settle are visible.';
        }

        return 'The business needs review, but the current data does not point to one clear cause yet.';
    }

    private function describeSkuProfitability(Business $business, array $metrics, array $previousMetrics, float $leakage): string
    {
        if (! $business->supportsSkuManagement()) {
            return 'This business model does not yet rely on product-level detail.';
        }

        if ($metrics['top_loss_sku'] ?? null) {
            return 'Product pressure is centered on '.$metrics['top_loss_sku'].'.';
        }

        if ($leakage > 0) {
            return 'Product economics are being affected by return or retry loss.';
        }

        return 'Product pressure is not obvious yet, so the strongest seller should be protected.';
    }

    private function describeBusinessHealth(float $currentProfit, float $currentCashDue, float $leakage, int $eventCount): string
    {
        if ($currentProfit >= 0 && $currentCashDue <= 0) {
            return 'The business is stable enough to continue controlled growth.';
        }

        if ($currentProfit < 0) {
            return 'The business is under pressure because the month is not yet leaving enough money after running costs.';
        }

        return 'The business is mixed: money is coming in, but waiting balances or returns pressure still needs attention.';
    }

    private function differenceLine(string $label, int|float $current, int|float $previous): ?string
    {
        if ($current === $previous) {
            return null;
        }

        return $label.' '.$this->formatDelta((float) ($current - $previous), false);
    }

    private function formatDelta(float $delta, bool $includeCurrency = false): string
    {
        $prefix = $delta >= 0 ? '+' : '-';
        $value = number_format(abs($delta), 2);

        return $includeCurrency ? 'LKR '.$prefix.$value : $prefix.$value;
    }

    private function direction(float $delta): string
    {
        if ($delta > 0) {
            return 'up';
        }

        if ($delta < 0) {
            return 'down';
        }

        return 'flat';
    }

    private function confidence(int $level): string
    {
        return match ($level) {
            2 => 'High',
            1 => 'Medium',
            default => 'Low',
        };
    }

    private function confidenceLabel(?array $previous): string
    {
        return $previous ? 'High' : 'Medium';
    }
}




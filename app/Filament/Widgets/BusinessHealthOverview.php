<?php

namespace App\Filament\Widgets;

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\Shared\Models\Business;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BusinessHealthOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $business = $this->resolveBusiness();
        $summary = $this->summaryForBusiness($business);
        $metrics = $summary['metrics'] ?? [];
        $revenue = (float) ($summary['revenue_total'] ?? 0);
        $eventCosts = (float) ($metrics['direct_operational_costs'] ?? 0);
        $fixedExpenses = (float) ($metrics['fixed_expenses'] ?? 0);
        $variableExpenses = (float) ($metrics['variable_expenses'] ?? 0);
        $expenses = $fixedExpenses + $variableExpenses;
        $profit = (float) ($summary['estimated_profit'] ?? ($revenue - (($summary['cost_total'] ?? 0))));
        $costTotal = (float) ($summary['cost_total'] ?? ($eventCosts + $expenses));

        return [
            Stat::make('Money left after running the business', number_format($profit, 2))
                ->description($profit >= 0 ? 'The business is still keeping some money after the bills' : 'Costs and leakage are taking more than the money coming in')
                ->color($profit >= 0 ? 'success' : 'danger'),
            Stat::make('Money coming in', number_format($revenue, 2))
                ->description('Delivered and recorded business money')
                ->color('info'),
            Stat::make('Money already spent', number_format($costTotal, 2))
                ->description('Direct '.number_format($eventCosts, 2).' + fixed '.number_format($fixedExpenses, 2).' + variable '.number_format($variableExpenses, 2).' + people cost and returns pressure in snapshot')
                ->color('warning'),
        ];
    }

    private function summaryForBusiness(?Business $business): array
    {
        if (! $business instanceof Business) {
            return [
                'revenue_total' => 0,
                'cost_total' => 0,
                'leakage_total' => 0,
                'estimated_profit' => 0,
                'metrics' => [
                    'direct_operational_costs' => 0,
                    'fixed_expenses' => 0,
                    'variable_expenses' => 0,
                ],
            ];
        }

        return app(BusinessHealthSnapshotService::class)->currentMonthSummary($business);
    }

    private function resolveBusiness(): ?Business
    {
        $user = auth()->user();

        if ($user?->business instanceof Business) {
            return $user->business;
        }

        if ($user?->defaultBusinessId()) {
            return Business::query()->find($user->defaultBusinessId());
        }

        return null;
    }
}

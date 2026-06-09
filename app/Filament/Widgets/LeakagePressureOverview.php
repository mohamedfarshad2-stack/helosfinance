<?php

namespace App\Filament\Widgets;

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\Shared\Models\Business;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LeakagePressureOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $business = $this->resolveBusiness();
        $summary = $this->summaryForBusiness($business);
        $metrics = $summary['metrics'] ?? [];
        $returns = (float) ($metrics['return_impact'] ?? 0);
        $resends = (float) ($metrics['resend_impact'] ?? 0);
        $fake = (float) ($metrics['fake_impact'] ?? 0);

        return [
            Stat::make('Returns hurting profits', number_format($returns, 2))->description('COD returns and courier pressure')->color('danger'),
            Stat::make('Retry cost', number_format($resends, 2))->description('Retry cost without new product cost')->color('warning'),
            Stat::make('Fake orders', number_format($fake, 2))->description('Avoidable operational loss')->color('danger'),
        ];
    }

    private function summaryForBusiness(?Business $business): array
    {
        if (! $business instanceof Business) {
            return [
                'metrics' => [
                    'return_impact' => 0,
                    'resend_impact' => 0,
                    'fake_impact' => 0,
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

        if ($user?->business_id) {
            return Business::query()->find($user->business_id);
        }

        return null;
    }
}

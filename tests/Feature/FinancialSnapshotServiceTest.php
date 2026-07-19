<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\FinancialSnapshot;
use App\Domains\Shared\Models\OperationalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_month_snapshot_updates_the_same_row_instead_of_creating_duplicates(): void
    {
        $business = Business::query()->create([
            'name' => 'Snapshot Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $service = app(BusinessHealthSnapshotService::class);

        $first = $service->currentMonth($business);
        $second = $service->currentMonth($business);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FinancialSnapshot::query()->where('business_id', $business->id)->count());
    }

    public function test_current_month_summary_uses_live_preview_instead_of_stale_saved_snapshot(): void
    {
        $business = Business::query()->create([
            'name' => 'Live Snapshot Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        FinancialSnapshot::query()->create([
            'business_id' => $business->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'revenue_total' => 679979,
            'cost_total' => 0,
            'leakage_total' => 0,
            'estimated_profit' => 679979,
            'metrics' => [],
        ]);

        $summary = app(BusinessHealthSnapshotService::class)->currentMonthSummary($business);

        $this->assertSame(0.0, (float) $summary['revenue_total']);
        $this->assertSame(0.0, (float) $summary['estimated_profit']);
    }

    public function test_snapshot_revenue_only_counts_delivered_order_revenue(): void
    {
        $business = Business::query()->create([
            'name' => 'Owner Revenue Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_CONFIRMED,
            'external_id' => 'CONFIRMED-WITH-OLD-REVENUE',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 679979,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 679979],
            'occurred_at' => now(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'DISPATCHED-WITH-OLD-REVENUE',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 200000,
            'direct_cost_amount' => 50000,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 200000],
            'occurred_at' => now(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'DELIVERED-REAL-REVENUE',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 3000,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 3000],
            'occurred_at' => now(),
        ]);

        $summary = app(BusinessHealthSnapshotService::class)->previewCurrentMonth($business);

        $this->assertSame(3000.0, (float) $summary['revenue_total']);
        $this->assertSame(879979.0, (float) $summary['metrics']['unrecognized_order_revenue']);
    }

    public function test_snapshot_revenue_uses_latest_order_state_not_old_delivered_event(): void
    {
        $business = Business::query()->create([
            'name' => 'Returned Revenue Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'RETURNED-LATER',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 5000,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 5000],
            'occurred_at' => now()->subDay(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_RETURNED,
            'external_id' => 'RETURNED-LATER',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 0,
            'leakage_amount' => 500,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 5000],
            'occurred_at' => now(),
        ]);

        $summary = app(BusinessHealthSnapshotService::class)->previewCurrentMonth($business);

        $this->assertSame(0.0, (float) $summary['revenue_total']);
        $this->assertSame(5000.0, (float) $summary['metrics']['unrecognized_order_revenue']);
    }
}

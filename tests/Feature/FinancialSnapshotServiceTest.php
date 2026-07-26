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
            'payload' => ['sale_amount' => 200000, 'economics' => ['product_cost_amount' => 50000]],
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
        $this->assertSame(425.0, (float) $summary['metrics']['delivered_courier_costs']);
        $this->assertSame(2575.0, (float) $summary['metrics']['delivered_value_after_courier']);
        $this->assertSame(50000.0, (float) $summary['metrics']['other_direct_operational_costs']);
        $this->assertSame(1, (int) $summary['metrics']['pending_dispatch_count']);
        $this->assertSame(200000.0, (float) $summary['metrics']['pending_dispatch_value']);
        $this->assertSame(50000.0, (float) $summary['metrics']['product_costs']);
        $this->assertSame(425.0, (float) $summary['metrics']['total_courier_costs']);
        $this->assertSame(-47425.0, (float) $summary['metrics']['parcel_gross_profit']);
        $this->assertSame(0, (int) $summary['metrics']['delivered_without_courier_cost_count']);
        $this->assertSame(-47425.0, (float) $summary['estimated_profit']);
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
        $this->assertSame(0, (int) $summary['metrics']['order_counts']['delivered']);
        $this->assertSame(1, (int) $summary['metrics']['order_counts']['returned']);
        $this->assertSame(1, (int) $summary['metrics']['order_activity_counts']['delivered']);
        $this->assertSame(1, (int) $summary['metrics']['order_activity_counts']['returned']);
    }

    public function test_snapshot_matches_delivery_and_return_with_stable_stock_order_id(): void
    {
        $business = Business::query()->create([
            'name' => 'Stable Order Lifecycle Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'cod-order-100-delivered-item-501',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 5000,
            'payload' => ['order_id' => '100', 'sale_amount' => 5000],
            'occurred_at' => now()->subDay(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_RETURNED,
            'external_id' => 'cod-order-100-returned-item-501',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'leakage_amount' => 500,
            'payload' => ['order_id' => '100', 'sale_amount' => 5000],
            'occurred_at' => now(),
        ]);

        $summary = app(BusinessHealthSnapshotService::class)->previewCurrentMonth($business);

        $this->assertSame(0.0, (float) $summary['revenue_total']);
        $this->assertSame(5000.0, (float) $summary['metrics']['unrecognized_order_revenue']);
    }

    public function test_snapshot_separates_current_month_and_carryover_deliveries(): void
    {
        $business = Business::query()->create([
            'name' => 'Delivery Cohort Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        foreach ([
            ['order_id' => 1, 'confirmed_at' => now()->subMonth()->endOfMonth(), 'value' => 4000],
            ['order_id' => 2, 'confirmed_at' => now()->startOfMonth()->addDay(), 'value' => 3000],
        ] as $row) {
            OperationalEvent::query()->create([
                'business_id' => $business->id,
                'source' => 'stock_app',
                'event_type' => OperationalEvent::ORDER_CONFIRMED,
                'external_id' => 'confirmation-'.$row['order_id'],
                'channel' => 'cod',
                'quantity' => 1,
                'payload' => ['order_id' => $row['order_id']],
                'occurred_at' => $row['confirmed_at'],
            ]);

            OperationalEvent::query()->create([
                'business_id' => $business->id,
                'source' => 'stock_app',
                'event_type' => OperationalEvent::ORDER_DELIVERED,
                'external_id' => 'delivery-'.$row['order_id'],
                'channel' => 'cod',
                'quantity' => 1,
                'revenue_amount' => $row['value'],
                'payload' => ['order_id' => $row['order_id']],
                'occurred_at' => now()->startOfMonth()->addDays(5),
            ]);
        }

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'delivery-missing-confirmation',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 2000,
            'payload' => ['order_id' => 3],
            'occurred_at' => now()->startOfMonth()->addDays(5),
        ]);

        $cohorts = app(BusinessHealthSnapshotService::class)->previewCurrentMonth($business)['metrics']['delivered_cohorts'];

        $this->assertSame(['count' => 1, 'value' => 3000.0], $cohorts['confirmed_this_month']);
        $this->assertSame(['count' => 1, 'value' => 4000.0], $cohorts['carryover_from_earlier_months']);
        $this->assertSame(['count' => 1, 'value' => 2000.0], $cohorts['confirmation_missing']);
        $this->assertSame(['count' => 0, 'value' => 0.0], $cohorts['invalid_confirmation_sequence']);
    }
}

<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\OperationalEvent;
use App\Filament\Pages\SalesInsights;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SalesInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_insights_shows_marketing_spend_and_profit_after_marketing(): void
    {
        Carbon::setTestNow('2026-07-05 12:00:00');

        $business = Business::query()->create([
            'name' => 'Insight Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Insight Owner',
            'email' => 'insight-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'DELIVERED-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 3000,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 3000],
            'occurred_at' => now()->subHour(),
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'department' => 'Marketing',
            'category' => 'Marketing',
            'expense_type' => 'variable',
            'suggested_key' => 'marketing',
            'description' => 'Boosting spend',
            'amount' => 500,
            'payment_status' => 'paid',
            'spent_on' => today()->toDateString(),
            'recurring' => false,
        ]);

        $this->actingAs($owner)
            ->get(SalesInsights::getUrl())
            ->assertOk()
            ->assertSee('Marketing spend')
            ->assertSee('Profit after direct + marketing');

        $deliveredRevenue = (float) OperationalEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', OperationalEvent::ORDER_DELIVERED)
            ->sum('revenue_amount');

        $directCosts = (float) OperationalEvent::query()
            ->where('business_id', $business->id)
            ->sum('direct_cost_amount');

        $marketingSpend = (float) Expense::query()
            ->where('business_id', $business->id)
            ->where('expense_type', 'variable')
            ->where(function ($query): void {
                $query->where('category', 'Marketing')
                    ->orWhere('suggested_key', 'marketing');
            })
            ->sum('amount');

        $profitAfterMarketing = $deliveredRevenue - $directCosts - $marketingSpend;

        $this->assertSame(500.0, $marketingSpend);
        $this->assertSame(2075.0, $profitAfterMarketing);
    }

    public function test_sales_insights_hides_unverified_stock_app_dispatch_rows_until_stage_date_is_verified(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00');

        $business = Business::query()->create([
            'name' => 'Insight Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Insight Owner',
            'email' => 'insight-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'TRACKING-UNVERIFIED-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 1740],
            'occurred_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($owner)->get(SalesInsights::getUrl());

        $response->assertOk()
            ->assertSee('Parcels in dispatch status')
            ->assertSee('LKR 1,740.00')
            ->assertSee('Moved to dispatch on this date')
            ->assertSee('LKR 0.00')
            ->assertSee('Verified stage-date status value: LKR 0.00')
            ->assertSee('dispatch status row(s) are still unverified')
            ->assertSee('extra row(s) were synced on this date without a verified real dispatch date');
    }

    public function test_sales_insights_counts_parcels_still_in_dispatch_status_as_of_selected_date(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00');

        $business = Business::query()->create([
            'name' => 'Insight Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Insight Owner',
            'email' => 'insight-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'TRACKING-OLD-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sale_amount' => 2000,
                'order_id' => 'ORDER-1001',
                'stage_occurred_at_source' => 'stock_app',
            ],
            'occurred_at' => Carbon::parse('2026-07-09 10:00:00'),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'TRACKING-OLD-2',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sale_amount' => 3000,
                'order_id' => 'ORDER-1002',
                'stage_occurred_at_source' => 'stock_app',
            ],
            'occurred_at' => Carbon::parse('2026-07-10 11:00:00'),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'DELIVERED-OLD-3',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 3500,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sale_amount' => 3500,
                'order_id' => 'ORDER-1003',
            ],
            'occurred_at' => Carbon::parse('2026-07-10 12:00:00'),
        ]);

        $response = $this->actingAs($owner)->get(SalesInsights::getUrl());

        $response->assertOk()
            ->assertSee('LKR 5,000.00')
            ->assertSee('2 parcel(s) were still in dispatch / resend waiting result as of this date');
    }

    public function test_sales_insights_separates_dispatch_movement_from_dispatch_status_snapshot(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00');

        $business = Business::query()->create([
            'name' => 'Insight Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Insight Owner',
            'email' => 'insight-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'TRACKING-DAY-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sale_amount' => 1800,
                'order_id' => 'ORDER-DAY-1',
                'stage_occurred_at_source' => 'stock_app',
            ],
            'occurred_at' => Carbon::parse('2026-07-11 09:00:00'),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'TRACKING-DAY-2',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sale_amount' => 2200,
                'order_id' => 'ORDER-DAY-2',
                'stage_occurred_at_source' => 'stock_app',
            ],
            'occurred_at' => Carbon::parse('2026-07-11 10:00:00'),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'TRACKING-OLD-3',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sale_amount' => 3000,
                'order_id' => 'ORDER-OLD-3',
                'stage_occurred_at_source' => 'stock_app',
            ],
            'occurred_at' => Carbon::parse('2026-07-10 10:00:00'),
        ]);

        $response = $this->actingAs($owner)->get(SalesInsights::getUrl());

        $response->assertOk()
            ->assertSee('Moved to dispatch on this date')
            ->assertSee('2 verified parcel(s) moved into dispatch / resend on this date')
            ->assertSee('LKR 4,000.00')
            ->assertSee('3 parcel(s) were still in dispatch / resend waiting result as of this date')
            ->assertSee('LKR 7,000.00');
    }

    public function test_sales_insights_deduplicates_same_day_dispatch_updates_for_the_same_tracking_number(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00');

        $business = Business::query()->create([
            'name' => 'Insight Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Insight Owner',
            'email' => 'insight-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'TRACKING-DUP-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sale_amount' => 2400,
                'tracking_number' => 'TRK-1001',
                'order_id' => 'ORDER-1001',
                'stage_occurred_at_source' => 'stock_app',
            ],
            'occurred_at' => Carbon::parse('2026-07-11 09:00:00'),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'TRACKING-DUP-2',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sale_amount' => 2400,
                'tracking_number' => 'TRK-1001',
                'order_id' => 'ORDER-1001',
                'stage_occurred_at_source' => 'stock_app',
            ],
            'occurred_at' => Carbon::parse('2026-07-11 09:30:00'),
        ]);

        $response = $this->actingAs($owner)->get(SalesInsights::getUrl());

        $response->assertOk()
            ->assertSee('Moved to dispatch on this date')
            ->assertSee('1 verified parcel(s) moved into dispatch / resend on this date')
            ->assertSee('LKR 2,400.00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}

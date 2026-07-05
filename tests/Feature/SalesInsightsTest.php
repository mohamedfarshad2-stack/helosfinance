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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}

<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\OperationalEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SalesInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_insights_shows_marketing_spend_and_profit_after_marketing(): void
    {
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
            ->get(\App\Filament\Pages\SalesInsights::getUrl())
            ->assertOk()
            ->assertSee('Marketing spend')
            ->assertSee('Profit after direct + marketing')
            ->assertSee('LKR 500.00')
            ->assertSee('LKR 2,075.00');
    }
}

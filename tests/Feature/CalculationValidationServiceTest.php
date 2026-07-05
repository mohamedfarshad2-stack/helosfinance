<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\CalculationValidationService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\OperationalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculationValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_profit_validation_does_not_double_count_leakage(): void
    {
        $business = Business::query()->create([
            'name' => 'Validation Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'department' => 'Operations',
            'category' => 'Fuel',
            'expense_type' => 'variable',
            'description' => 'Daily spend',
            'amount' => 100,
            'payment_status' => 'paid',
            'spent_on' => now()->toDateString(),
            'recurring' => false,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'VAL-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 1000, 'economics' => []],
            'occurred_at' => now()->subHour(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'VAL-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 1000,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 1000, 'economics' => ['courier_amount' => 50]],
            'occurred_at' => now(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_RETURNED,
            'external_id' => 'VAL-2',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 0,
            'leakage_amount' => 120,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 0, 'economics' => ['return_courier_amount' => 120]],
            'occurred_at' => now(),
        ]);

        $validation = app(CalculationValidationService::class)->forCurrentMonth($business);

        $this->assertSame('PASS', $validation['results']['profit']['status']);
    }
}

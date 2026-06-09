<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\BreakEvenIntelligenceService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CostAssumption;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Filament\Pages\ClientHealthReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BreakEvenIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_break_even_service_calculates_fixed_costs_variable_costs_and_progress(): void
    {
        $business = Business::query()->create([
            'name' => 'Break Even Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        Employee::query()->create([
            'business_id' => $business->id,
            'name' => 'Payroll One',
            'role' => 'Supervisor',
            'monthly_salary' => 1000,
            'pay_cycle' => 'monthly',
            'active' => true,
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'department' => 'Operations',
            'category' => 'Rent',
            'expense_type' => 'fixed',
            'suggested_key' => 'rent',
            'description' => 'Monthly rent',
            'amount' => 1500,
            'payment_status' => 'paid',
            'spent_on' => now()->toDateString(),
            'recurring' => true,
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'department' => 'Operations',
            'category' => 'Electricity',
            'expense_type' => 'fixed',
            'suggested_key' => 'electricity',
            'description' => 'Monthly electricity',
            'amount' => 500,
            'payment_status' => 'paid',
            'spent_on' => now()->toDateString(),
            'recurring' => true,
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'department' => 'Operations',
            'category' => 'Software',
            'expense_type' => 'fixed',
            'suggested_key' => 'software_tools',
            'description' => 'Monthly software tools',
            'amount' => 500,
            'payment_status' => 'paid',
            'spent_on' => now()->toDateString(),
            'recurring' => true,
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'department' => 'Dispatch',
            'category' => 'Fuel',
            'expense_type' => 'variable',
            'description' => 'Fuel for daily work',
            'amount' => 100,
            'payment_status' => 'paid',
            'spent_on' => now()->toDateString(),
            'recurring' => false,
        ]);

        CostAssumption::query()->create([
            'business_id' => $business->id,
            'key' => 'delivery_fee',
            'label' => 'Delivery cost',
            'amount' => 50,
        ]);

        $skuA = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SKU-A',
            'name' => 'SKU A',
            'material_cost' => 250,
            'packaging_cost' => 50,
            'labor_rate' => 100,
            'finishing_cost' => 50,
            'expected_sale_price' => 1000,
        ]);

        $skuB = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SKU-B',
            'name' => 'SKU B',
            'material_cost' => 300,
            'packaging_cost' => 100,
            'labor_rate' => 150,
            'finishing_cost' => 100,
            'expected_sale_price' => 600,
        ]);

        foreach (range(1, 5) as $index) {
            OperationalEvent::query()->create([
                'business_id' => $business->id,
                'sku_id' => $skuA->id,
                'source' => 'stock_app',
                'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
                'external_id' => 'A-'.$index,
                'channel' => 'cod',
                'quantity' => 1,
                'revenue_amount' => 0,
                'direct_cost_amount' => 500,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'payload' => ['economics' => []],
                'occurred_at' => now()->subDays(5),
            ]);

            OperationalEvent::query()->create([
                'business_id' => $business->id,
                'sku_id' => $skuA->id,
                'source' => 'stock_app',
                'event_type' => OperationalEvent::ORDER_DELIVERED,
                'external_id' => 'A-'.$index,
                'channel' => 'cod',
                'quantity' => 1,
                'revenue_amount' => 1000,
                'direct_cost_amount' => 0,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'payload' => ['economics' => []],
                'occurred_at' => now()->subDays(4),
            ]);
        }

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $skuB->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'B-1',
            'channel' => 'wholesale',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 700,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['economics' => []],
            'occurred_at' => now()->subDays(5),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $skuB->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'B-1',
            'channel' => 'wholesale',
            'quantity' => 1,
            'revenue_amount' => 600,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['economics' => []],
            'occurred_at' => now()->subDays(4),
        ]);

        $report = app(BreakEvenIntelligenceService::class)->forCurrentMonth($business);

        $this->assertSame(3500.0, $report['fixed_costs']['total']);
        $this->assertSame(3300.0, $report['variable_costs']['total']);
        $this->assertSame(2300.0, $report['contribution']['total']);
        $this->assertEqualsWithDelta(8521.74, (float) $report['break_even']['revenue'], 0.1);
        $this->assertEqualsWithDelta(9.13, (float) $report['break_even']['deliveries'], 0.1);
        $this->assertEqualsWithDelta(65.71, (float) $report['progress']['coverage_percent'], 0.1);
        $this->assertSame(4, $report['progress']['remaining_deliveries']);
        $this->assertSame('SKU A', $report['contribution']['top_helping_skus'][0]['name']);
        $this->assertSame('SKU B', $report['contribution']['top_slowing_skus'][0]['name']);
        $this->assertSame('COD', $report['contribution']['revenue_streams'][0]['name']);
        $this->assertNotEmpty($report['pressure']['top_obstacles']);
        $this->assertContains('Fixed monthly costs', collect($report['pressure']['top_obstacles'])->pluck('label')->all());
        $this->assertContains('Salary pressure', collect($report['pressure']['top_obstacles'])->pluck('label')->all());
    }

    public function test_client_dashboard_shows_the_break_even_story_for_an_owner(): void
    {
        $business = Business::query()->create([
            'name' => 'Dashboard Break Even Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        User::query()->create([
            'name' => 'Business Owner',
            'email' => 'owner-break-even@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $this->actingAs(User::query()->where('email', 'owner-break-even@example.com')->first())
            ->get(ClientHealthReport::getUrl())
            ->assertOk()
            ->assertSee('Money needed to cover the month')
            ->assertSee('Money Needed To Cover The Month')
            ->assertSee('Deliveries Needed To Cover The Month')
            ->assertSee('Revenue Needed To Cover The Month')
            ->assertSee('Current Progress')
            ->assertSee('Still Needed')
            ->assertSee('What Is Making It Harder')
            ->assertSee('What helps most');
    }
}

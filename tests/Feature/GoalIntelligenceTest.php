<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\GoalIntelligenceService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Filament\Pages\ClientHealthReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GoalIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_service_saves_a_monthly_profit_goal_and_calculates_progress(): void
    {
        $business = $this->seedGoalBusiness();
        $service = app(GoalIntelligenceService::class);

        $savedBusiness = $service->saveGoal($business, [
            'goal_type' => 'profit',
            'goal_amount' => 4000,
        ]);

        $this->assertSame('profit', $savedBusiness->settings['goal']['type']);
        $this->assertSame(4000.0, (float) $savedBusiness->settings['goal']['amount']);

        $story = $service->forCurrentMonth($savedBusiness);

        $this->assertTrue($story['configured']);
        $this->assertSame('profit', $story['goal']['type']);
        $this->assertSame(4000.0, (float) $story['goal']['target']);
        $this->assertSame(1000.0, (float) $story['goal']['current']);
        $this->assertSame(3000.0, (float) $story['goal']['gap']);
        $this->assertEqualsWithDelta(25.0, (float) $story['goal']['progress_percent'], 0.01);
        $this->assertSame(6, $story['delivery_requirements']['additional_deliveries']);
        $this->assertSame(6, $story['delivery_requirements']['additional_orders']);
        $this->assertSame(6000.0, (float) $story['delivery_requirements']['additional_revenue']);
        $this->assertNotEmpty($story['pressure']['top_obstacles']);
        $this->assertSame('Fixed monthly costs', $story['pressure']['top_obstacles'][0]['label']);
        $this->assertContains('Salary pressure', collect($story['pressure']['top_obstacles'])->pluck('label')->all());
        $this->assertNotEmpty($story['pressure']['what_helps_most']);
        $this->assertStringContainsString('6,000.00', $story['pressure']['fastest_path']['note']);
        $this->assertContains('Keep the strongest product or revenue stream moving first.', $story['actions']);
    }

    public function test_owner_dashboard_renders_the_goal_story(): void
    {
        $business = $this->seedGoalBusiness();

        app(GoalIntelligenceService::class)->saveGoal($business, [
            'goal_type' => 'profit',
            'goal_amount' => 4000,
        ]);

        $owner = User::query()->create([
            'name' => 'Business Owner',
            'email' => 'goal-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $this->actingAs($owner)
            ->get(ClientHealthReport::getUrl())
            ->assertOk()
            ->assertSee('Your Goal')
            ->assertSee('Current Progress')
            ->assertSee('Still Needed')
            ->assertSee('What Is Slowing You Down')
            ->assertSee('Fastest Path Forward');
    }

    private function seedGoalBusiness(): Business
    {
        $business = Business::query()->create([
            'name' => 'Goal Engine Client',
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
            'amount' => 500,
            'payment_status' => 'paid',
            'spent_on' => now()->toDateString(),
            'recurring' => true,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'GOAL-SKU',
            'name' => 'Goal SKU',
            'material_cost' => 250,
            'packaging_cost' => 50,
            'labor_rate' => 100,
            'finishing_cost' => 100,
            'expected_sale_price' => 1000,
        ]);

        foreach (range(1, 5) as $index) {
            OperationalEvent::query()->create([
                'business_id' => $business->id,
                'sku_id' => $sku->id,
                'source' => 'stock_app',
                'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
                'external_id' => 'GOAL-'.$index,
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
                'sku_id' => $sku->id,
                'source' => 'stock_app',
                'event_type' => OperationalEvent::ORDER_DELIVERED,
                'external_id' => 'GOAL-'.$index,
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

        return $business;
    }
}

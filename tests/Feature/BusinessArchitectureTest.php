<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Expense;
use App\Filament\Pages\ClientHealthReport;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\QuickExpenseEntry;
use App\Filament\Resources\BusinessResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\MaterialComponentResource;
use App\Filament\Resources\IntegrationSourceResource;
use App\Filament\Resources\MaterialLedgerResource;
use App\Filament\Resources\ProductionWorkStepResource;
use App\Filament\Resources\ProductionEntryResource;
use App\Filament\Resources\ServiceBillingResource;
use App\Filament\Resources\SkuRecipeResource;
use App\Filament\Resources\SkuResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_business_stays_on_survival_stack(): void
    {
        $business = Business::query()->create([
            'name' => 'Service Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $this->assertTrue($business->moduleEnabled('financial_engine'));
        $this->assertTrue($business->moduleEnabled('cash_position'));
        $this->assertTrue($business->moduleEnabled('advisor'));
        $this->assertFalse($business->moduleEnabled('client_profitability'));
        $this->assertFalse($business->moduleEnabled('sku_profitability'));
        $this->assertFalse($business->moduleEnabled('production_tracking'));
        $this->assertFalse($business->supportsSkuManagement());
        $this->assertFalse($business->supportsProductionTracking());
    }

    public function test_hybrid_manufacturing_business_unlocks_inventory_and_production_layers_at_level_5(): void
    {
        $business = Business::query()->create([
            'name' => 'Hybrid Manufacturing Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_HYBRID,
            'primary_business_type' => Business::TYPE_MANUFACTURING,
            'secondary_business_types' => ['trading', 'retail'],
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $this->assertTrue($business->supportsBusinessType(Business::TYPE_MANUFACTURING));
        $this->assertTrue($business->supportsBusinessType(Business::TYPE_TRADING));
        $this->assertTrue($business->supportsSkuManagement());
        $this->assertTrue($business->supportsInventoryIntelligence());
        $this->assertTrue($business->supportsProductionTracking());
        $this->assertTrue($business->moduleEnabled('daily_cfo_briefing'));
        $this->assertTrue($business->moduleEnabled('production_tracking'));
    }

    public function test_business_maturity_can_be_suggested_from_simple_onboarding_signals(): void
    {
        $this->assertSame(
            Business::MATURITY_LEVEL_1,
            Business::suggestedMaturityFromSignals([
                'regular_sales' => false,
                'cost_visibility' => false,
                'stock_control' => false,
                'production_control' => false,
                'cash_review' => false,
            ], Business::TYPE_SERVICE)
        );

        $this->assertSame(
            Business::MATURITY_LEVEL_3,
            Business::suggestedMaturityFromSignals([
                'regular_sales' => true,
                'cost_visibility' => true,
                'stock_control' => false,
                'production_control' => false,
                'cash_review' => true,
            ], Business::TYPE_TRADING)
        );

        $this->assertSame(
            Business::MATURITY_LEVEL_5,
            Business::suggestedMaturityFromSignals([
                'regular_sales' => true,
                'cost_visibility' => true,
                'stock_control' => true,
                'production_control' => true,
                'cash_review' => true,
            ], Business::TYPE_MANUFACTURING)
        );
    }

    public function test_module_visibility_follows_business_type_and_maturity(): void
    {
        $serviceBusiness = Business::query()->create([
            'name' => 'Service Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_2,
            'onboarding_status' => 'ready',
        ]);

        $user = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $serviceBusiness->id,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($user);

        $this->assertTrue(ServiceBillingResource::canAccess());
        $this->assertFalse(SkuResource::canAccess());
        $this->assertFalse(ProductionEntryResource::canAccess());

        $manufacturingBusiness = Business::query()->create([
            'name' => 'Manufacturing Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $user->update(['business_id' => $manufacturingBusiness->id]);
        $this->actingAs($user->fresh());

        $this->assertFalse(ServiceBillingResource::canAccess());
        $this->assertTrue(SkuResource::canAccess());
        $this->assertTrue(ProductionEntryResource::canAccess());
    }

    public function test_client_owners_land_on_the_cfo_dashboard_and_can_access_setup_surfaces(): void
    {
        $business = Business::query()->create([
            'name' => 'Service Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_2,
            'onboarding_status' => 'ready',
        ]);

        $user = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'client-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(ClientHealthReport::getUrl());

        $this->assertTrue(BusinessResource::canAccess());
        $this->assertTrue(IntegrationSourceResource::canAccess());
    }

    public function test_client_health_report_presents_a_single_health_story(): void
    {
        $business = Business::query()->create([
            'name' => 'Health Story Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $user = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'health-story@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(ClientHealthReport::getUrl())
            ->assertOk()
            ->assertSee('Business flow')
            ->assertSee('Business setup')
            ->assertSee('Orders')
            ->assertSee('Parcels on the move')
            ->assertSee('Returns and retries')
            ->assertSee('Stock movement')
            ->assertSee('Money movement')
            ->assertSee('sales source')
            ->assertSee('Production activity')
            ->assertSee('What to do next')
            ->assertSee('What happened')
            ->assertSee('Why it matters')
            ->assertSee('Risk')
            ->assertSee('Opportunity')
            ->assertSee('First action')
            ->assertSee('Business picture')
            ->assertSee('Money safe to use')
            ->assertSee('Money Safe To Use')
            ->assertSee('Money Already Committed')
            ->assertSee('Money Tied Up')
            ->assertSee('Money Waiting To Settle')
            ->assertSee('Money Safe To Withdraw')
            ->assertSee('Growth Capacity')
            ->assertSee('Money coming in')
            ->assertSee('Money left after running the business')
            ->assertSee('Money still waiting to settle')
            ->assertSee('Returns hurting profits')
            ->assertSee('Stock holding cash')
            ->assertSee('Money tied up');
    }

    public function test_client_health_report_surfaces_weekly_reminders(): void
    {
        $business = Business::query()->create([
            'name' => 'Reminder Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'category' => 'Supplier payment',
            'expense_type' => 'variable',
            'description' => 'Weekly supplier settlement',
            'amount' => 15000,
            'payee' => 'Alpha Supplies',
            'payment_status' => 'credit_due',
            'payment_method' => 'bank',
            'paid_amount' => 0,
            'due_on' => now()->addDays(3)->toDateString(),
            'spent_on' => now()->toDateString(),
            'recurring' => false,
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'category' => 'Weekly payroll',
            'expense_type' => 'variable',
            'description' => 'Overdue salary settlement',
            'amount' => 22000,
            'payee' => 'Staff team',
            'payment_status' => 'credit_due',
            'payment_method' => 'bank',
            'paid_amount' => 0,
            'due_on' => now()->subDay()->toDateString(),
            'spent_on' => now()->toDateString(),
            'recurring' => false,
        ]);

        $user = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'reminder-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(ClientHealthReport::getUrl())
            ->assertOk()
            ->assertSee('Weekly reminders')
            ->assertSee('Due soon')
            ->assertSee('Overdue');
    }

    public function test_picker_based_entry_surfaces_render_for_repeated_values(): void
    {
        $business = Business::query()->create([
            'name' => 'Picker Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'picker-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(QuickExpenseEntry::getUrl())
            ->assertOk()
            ->assertSee('What was it for?')
            ->assertSee('Paid to');

        $this->get(BankStatementImport::getUrl())
            ->assertOk()
            ->assertSee('Bank account / cash container');

        $this->get(MaterialLedgerResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Movement type')
            ->assertSee('Quantity bought');

        $this->get(MaterialComponentResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Raw Material Components')
            ->assertSee('Upload components')
            ->assertSee('Download sample');

        $this->get(MaterialComponentResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Usable pieces per buying unit')
            ->assertSee('Expected waste');

        $this->get(ProductionWorkStepResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Work name')
            ->assertSee('Rate per unit');

        $this->get(SkuResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Product / SKU')
            ->assertSee('Expected sale price')
            ->assertSee('Simple fallback costs');

        $this->get(SkuRecipeResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Material component');

        $this->get(SkuRecipeResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Delete SKU recipe');

        $this->get(ExpenseResource::getUrl('create'))
            ->assertOk()
            ->assertSee('What was paid for?')
            ->assertSee('Paid to / supplier')
            ->assertSee('Department');
    }
}

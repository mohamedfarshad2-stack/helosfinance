<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\TrustValidationService;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use App\Filament\Pages\ClientHealthReport;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\ExpenseResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TrustValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_trust_validation_service_flags_missing_truth_as_pending_validation(): void
    {
        $business = Business::query()->create([
            'name' => 'Trust Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'TRUST-001',
            'name' => 'Trust Product',
            'material_cost' => 0,
            'packaging_cost' => 0,
            'labor_rate' => 0,
            'finishing_cost' => 0,
            'expected_sale_price' => 0,
            'active' => true,
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'department' => 'Operations',
            'category' => 'Supplier payment',
            'expense_type' => 'variable',
            'description' => 'Missing supplier and due date',
            'amount' => 1500,
            'payment_status' => 'cheque_pending',
            'payment_method' => 'cheque',
            'paid_amount' => 0,
            'spent_on' => now()->toDateString(),
            'recurring' => false,
        ]);

        Employee::query()->create([
            'business_id' => $business->id,
            'name' => 'Worker One',
            'role' => 'Helper',
            'monthly_salary' => 0,
            'pay_cycle' => 'month_end',
            'active' => true,
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Unassigned bank row',
            'debit' => 1000,
            'credit' => 0,
            'classification' => 'unknown',
            'transaction_type' => 'revenue',
            'status' => 'review',
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'manual',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'TRUST-ORDER-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 5000,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['economics' => []],
            'occurred_at' => now(),
        ]);

        MaterialLedgerEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'entry_type' => 'purchase',
            'component_name' => 'Packaging',
            'quantity' => 10,
            'unit_cost' => 100,
            'total_cost' => 1000,
            'occurred_on' => now()->toDateString(),
            'note' => 'Missing SKU link',
        ]);

        $trust = app(TrustValidationService::class)->forCurrentMonth($business);

        $this->assertSame('Pending Validation', $trust['status_label']);
        $this->assertNotEmpty($trust['warnings']['critical']);
        $this->assertGreaterThan(0, $trust['validation_issue_count']);
        $this->assertLessThan(100, $trust['data_quality_percent']);
        $this->assertSame('Pending Validation', $trust['metric_statuses']['profit']['status']);
        $this->assertSame('Pending Validation', $trust['metric_statuses']['break_even']['status']);
        $this->assertSame('Pending Validation', $trust['metric_statuses']['safe_to_withdraw']['status']);
        $this->assertSame('Pending Validation', $trust['section_statuses']['treasury']);
    }

    public function test_trust_validation_service_marks_clean_inputs_as_verified_or_estimated(): void
    {
        $business = Business::query()->create([
            'name' => 'Clean Trust Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'TRUST-OK',
            'name' => 'Trust OK Product',
            'material_cost' => 0,
            'packaging_cost' => 25,
            'labor_rate' => 0,
            'finishing_cost' => 10,
            'expected_sale_price' => 500,
            'active' => true,
        ]);

        SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'line_type' => SkuRecipeItem::TYPE_RAW_MATERIAL,
            'component_name' => 'Rubber sheet',
            'quantity_per_unit' => 1,
            'unit_cost' => 100,
            'active' => true,
        ]);

        SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'line_type' => SkuRecipeItem::TYPE_LABOR,
            'component_name' => 'Assembly',
            'quantity_per_unit' => 1,
            'unit_cost' => 50,
            'active' => true,
        ]);

        Employee::query()->create([
            'business_id' => $business->id,
            'name' => 'Worker Two',
            'role' => 'Helper',
            'monthly_salary' => 25000,
            'pay_cycle' => 'month_end',
            'active' => true,
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'department' => 'Operations',
            'category' => 'Rent',
            'expense_type' => 'fixed',
            'suggested_key' => 'rent',
            'description' => 'Monthly rent',
            'amount' => 10000,
            'payment_status' => 'paid',
            'payment_method' => 'bank',
            'paid_amount' => 10000,
            'spent_on' => now()->toDateString(),
            'recurring' => true,
            'payee' => 'Landlord',
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Delivered order money',
            'money_container' => 'Current Account',
            'debit' => 0,
            'credit' => 5000,
            'classification' => 'revenue',
            'transaction_type' => 'revenue',
            'allocated_business_id' => $business->id,
            'status' => 'classified',
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'manual',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'TRUST-OK-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 5000,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['economics' => []],
            'occurred_at' => now(),
        ]);

        $trust = app(TrustValidationService::class)->forCurrentMonth($business);

        $this->assertSame('Estimated', $trust['status_label']);
        $this->assertEmpty($trust['warnings']['critical']);
        $this->assertSame('Verified', $trust['metric_statuses']['profit']['status']);
        $this->assertSame('Estimated', $trust['metric_statuses']['safe_to_withdraw']['status']);
        $this->assertSame('Pending Validation', $trust['metric_statuses']['goal_progress']['status']);
        $this->assertNotEmpty($trust['warnings']['informational']);
        $this->assertGreaterThan(0, $trust['data_quality_percent']);
        $this->assertLessThan(100, $trust['data_quality_percent']);
    }

    public function test_owner_dashboard_renders_the_trust_status_section(): void
    {
        $business = Business::query()->create([
            'name' => 'Trust Render Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'trust-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'category' => 'Transport',
            'expense_type' => 'variable',
            'amount' => 2500,
            'paid_amount' => 0,
            'payment_status' => 'credit_due',
            'spent_on' => today()->toDateString(),
            'payee' => null,
        ]);

        $this->actingAs($owner)
            ->get(ClientHealthReport::getUrl())
            ->assertOk()
            ->assertSee('HELOS Trust Center')
            ->assertSee('Data Quality')
            ->assertSee('Validation Issues')
            ->assertSee('Missing Information')
            ->assertSee('Estimated Numbers')
            ->assertSee('Critical warnings')
            ->assertSee('Important warnings')
            ->assertSee('Informational warnings')
            ->assertSee('Fix expenses')
            ->assertSee(ExpenseResource::getUrl('index'), false);
    }

    public function test_employee_role_is_selectable_from_common_roles(): void
    {
        $business = Business::query()->create([
            'name' => 'Role Picker Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'role-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $this->actingAs($owner)
            ->get(EmployeeResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Role')
            ->assertSee('Search or choose a role');
    }
}

<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\FinancialSnapshot;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use App\Domains\Shared\Models\Mission;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuStockMovement;
use App\Domains\Shared\Services\MissionGeneratorService;
use App\Domains\Shared\Services\WorkQueueService;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\ClientHealthReport;
use App\Filament\Pages\ManagerWorkQueue;
use App\Filament\Pages\TodaysWork;
use App\Filament\Resources\BankTransactionResource;
use App\Filament\Resources\BusinessResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\MaterialLedgerResource;
use App\Filament\Resources\MissionResource;
use App\Filament\Resources\ProductionEntryResource;
use App\Filament\Resources\SkuRecipeResource;
use App\Filament\Resources\SkuResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkQueueIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_queue_service_turns_open_work_into_tasks(): void
    {
        $business = Business::query()->create([
            'name' => 'Work Queue Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SKU-001',
            'name' => 'Demo Product',
            'material_cost' => 100,
            'packaging_cost' => 25,
            'labor_rate' => 50,
            'finishing_cost' => 10,
            'expected_sale_price' => 250,
            'active' => true,
        ]);

        Employee::query()->create([
            'business_id' => $business->id,
            'name' => 'Nila',
            'role' => 'Accounts',
            'monthly_salary' => 50000,
            'pay_cycle' => 'monthly',
            'active' => true,
        ]);

        Employee::query()->create([
            'business_id' => $business->id,
            'name' => 'Ravi',
            'role' => 'Operations',
            'monthly_salary' => 45000,
            'pay_cycle' => 'monthly',
            'active' => true,
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => today()->subDay()->toDateString(),
            'description' => 'Unreviewed bank row',
            'debit' => 1200,
            'credit' => 0,
            'classification' => 'unknown',
            'confidence' => 0,
            'status' => 'review',
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => today()->toDateString(),
            'description' => 'Reviewed bank row',
            'debit' => 0,
            'credit' => 1800,
            'classification' => 'collection',
            'confidence' => 0.95,
            'transaction_type' => 'revenue',
            'status' => 'classified',
            'reviewed_at' => now(),
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => today()->toDateString(),
            'description' => 'Savings to current transfer',
            'debit' => 0,
            'credit' => 2500,
            'classification' => 'transfer',
            'confidence' => 0.8,
            'status' => 'review',
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'category' => 'Supplier payment',
            'expense_type' => 'variable',
            'description' => 'Open supplier bill',
            'amount' => 1000,
            'payment_status' => 'partial',
            'payment_method' => 'bank',
            'paid_amount' => 200,
            'due_on' => today()->subDay()->toDateString(),
            'payee' => 'ABC Suppliers',
            'spent_on' => today()->subDays(2)->toDateString(),
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'category' => 'Courier bill',
            'expense_type' => 'variable',
            'description' => 'Settled courier bill',
            'amount' => 500,
            'payment_status' => 'settled',
            'payment_method' => 'bank',
            'paid_amount' => 500,
            'due_on' => today()->toDateString(),
            'settled_on' => today()->toDateString(),
            'payee' => 'Courier Co',
            'spent_on' => today()->toDateString(),
        ]);

        ProductionEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'employee_name' => 'Mina',
            'quantity_produced' => 10,
            'waste_quantity' => 0,
            'employee_payout' => 1000,
            'advance_amount' => 0,
            'deduction_amount' => 0,
            'net_payable' => 1000,
            'payment_status' => 'pending',
            'produced_on' => today()->subDay()->toDateString(),
            'estimated_total_cost' => 1000,
        ]);

        ProductionEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'employee_name' => 'Mina',
            'quantity_produced' => 5,
            'waste_quantity' => 0,
            'employee_payout' => 500,
            'advance_amount' => 0,
            'deduction_amount' => 0,
            'net_payable' => 500,
            'payment_status' => 'paid',
            'paid_on' => today()->toDateString(),
            'produced_on' => today()->toDateString(),
            'estimated_total_cost' => 500,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'manual',
            'event_type' => OperationalEvent::ORDER_CREATED,
            'external_id' => 'ORD-1',
            'channel' => 'cod',
            'department' => 'Sales',
            'quantity' => 1,
            'occurred_at' => today()->subDay()->startOfDay(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'manual',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'ORD-2',
            'channel' => 'cod',
            'department' => 'Operations',
            'quantity' => 1,
            'occurred_at' => now(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'manual',
            'event_type' => OperationalEvent::ORDER_RETURNED,
            'external_id' => 'ORD-3',
            'channel' => 'cod',
            'department' => 'Operations',
            'quantity' => 1,
            'occurred_at' => now(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'manual',
            'event_type' => OperationalEvent::ORDER_RESENT,
            'external_id' => 'ORD-4',
            'channel' => 'cod',
            'department' => 'Operations',
            'quantity' => 1,
            'occurred_at' => now(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'manual',
            'event_type' => OperationalEvent::FAKE_ORDER_DETECTED,
            'external_id' => 'ORD-5',
            'channel' => 'cod',
            'department' => 'Operations',
            'quantity' => 1,
            'occurred_at' => now(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'manual',
            'event_type' => OperationalEvent::PRODUCTION_WASTE,
            'external_id' => 'WASTE-1',
            'department' => 'Production',
            'quantity' => 1,
            'occurred_at' => now(),
        ]);

        SkuStockMovement::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'operational_event_id' => null,
            'order_external_id' => 'ORD-2',
            'movement_type' => 'dispatch',
            'quantity' => 1,
            'quantity_delta' => -1,
            'is_restockable' => false,
            'note' => 'Dispatched today',
            'occurred_at' => now(),
        ]);

        SkuStockMovement::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'operational_event_id' => null,
            'order_external_id' => 'ORD-3',
            'movement_type' => 'return_restocked',
            'quantity' => 1,
            'quantity_delta' => 1,
            'is_restockable' => true,
            'note' => 'Returned stock restocked',
            'occurred_at' => now(),
        ]);

        MaterialLedgerEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'entry_type' => 'purchase',
            'component_name' => 'Packaging rolls',
            'quantity' => 10,
            'unit_cost' => 25,
            'total_cost' => 250,
            'occurred_on' => today()->toDateString(),
            'note' => 'Missing SKU link',
        ]);

        $workQueue = app(WorkQueueService::class)->forBusiness($business);

        $this->assertGreaterThan(0, $workQueue['open_count']);
        $this->assertGreaterThan(0, $workQueue['blocked_count']);
        $this->assertGreaterThan(0, $workQueue['completed_today_count']);
        $this->assertNotEmpty($workQueue['sections']['due_today']);
        $this->assertNotEmpty($workQueue['sections']['high_priority']);
        $this->assertNotEmpty($workQueue['sections']['waiting_review']);
        $this->assertNotEmpty($workQueue['sections']['completed_today']);

        $titles = collect($workQueue['tasks'])->pluck('title')->all();

        $this->assertContains('Bank transaction needs review', $titles);
        $this->assertContains('Transaction type missing', $titles);
        $this->assertContains('Business assignment missing', $titles);
        $this->assertContains('Possible transfer needs confirmation', $titles);
        $this->assertContains('Supplier payment needs settlement', $titles);
        $this->assertContains('Production payout pending', $titles);
        $this->assertContains('Order needs a tracking number', $titles);
        $this->assertContains('Dispatched parcel needs delivery follow-up', $titles);
        $this->assertContains('Return needs action', $titles);
        $this->assertContains('Resend is still open', $titles);
        $this->assertContains('Possible fake order needs checking', $titles);
        $this->assertContains('Material entry needs a SKU', $titles);
    }

    public function test_dispatched_order_stays_a_revenue_recovery_task_until_delivery(): void
    {
        $business = Business::query()->create([
            'name' => 'Delivery Recovery Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'dispatch-event-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'payload' => ['order_id' => 9001, 'sale_amount' => 3500],
            'occurred_at' => now()->subDays(3),
        ]);

        $task = collect(app(WorkQueueService::class)->forBusiness($business)['tasks'])
            ->firstWhere('work_type', 'delivery_follow_up');

        $this->assertNotNull($task);
        $this->assertSame('open', $task['state']);
        $this->assertSame(3500.0, $task['amount']);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'delivered-event-99',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 3500,
            'direct_cost_amount' => 425,
            'payload' => ['order_id' => 9001, 'sale_amount' => 3500],
            'occurred_at' => now(),
        ]);

        $tasks = collect(app(WorkQueueService::class)->forBusiness($business)['tasks']);
        $this->assertNull($tasks->firstWhere('work_type', 'delivery_follow_up'));
        $this->assertNotNull($tasks->firstWhere('work_type', 'order_delivery'));
    }

    public function test_employee_users_land_on_todays_work(): void
    {
        $business = Business::query()->create([
            'name' => 'Employee Queue Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $user = User::query()->create([
            'name' => 'Floor Worker',
            'email' => 'employee@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(TodaysWork::getUrl());
    }

    public function test_internal_admins_land_on_client_businesses(): void
    {
        $business = Business::query()->create([
            'name' => 'Manager Queue Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $user = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'admin-queue@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => false,
            'is_platform_admin' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(BusinessResource::getUrl('index'));

        $this->actingAs($user)
            ->get(ManagerWorkQueue::getUrl())
            ->assertForbidden();
    }

    public function test_employee_work_screen_renders(): void
    {
        $business = Business::query()->create([
            'name' => 'Render Queue Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
            'settings' => ['goal' => ['type' => 'profit', 'amount' => 100000]],
        ]);

        $employee = User::query()->create([
            'name' => 'Floor Worker',
            'email' => 'queue-employee@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
            'is_staff_supervisor' => true,
            'employee_access_profile' => 'finance_ops',
            'staff_responsibilities' => ['bank_exceptions', 'expense_recording', 'dispatch'],
        ]);

        User::query()->create([
            'name' => 'Dispatch Assistant',
            'email' => 'dispatch-assistant@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
            'supervisor_user_id' => $employee->id,
            'employee_access_profile' => 'operations',
            'staff_responsibilities' => ['dispatch'],
            'responsibilities_configured' => true,
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => today()->toDateString(),
            'description' => 'Unreviewed bank row',
            'debit' => 1200,
            'credit' => 0,
            'classification' => 'unknown',
            'confidence' => 0,
            'status' => 'review',
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'category' => 'Supplier payment',
            'expense_type' => 'variable',
            'description' => 'Open supplier bill',
            'amount' => 1000,
            'payment_status' => 'partial',
            'payment_method' => 'bank',
            'paid_amount' => 200,
            'due_on' => today()->subDay()->toDateString(),
            'payee' => 'ABC Suppliers',
            'spent_on' => today()->subDays(2)->toDateString(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'manual',
            'event_type' => OperationalEvent::ORDER_CREATED,
            'external_id' => 'ORD-EMP-1',
            'channel' => 'cod',
            'department' => 'Sales',
            'quantity' => 1,
            'occurred_at' => today()->subDay()->startOfDay(),
        ]);

        FinancialSnapshot::query()->create([
            'business_id' => $business->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'revenue_total' => 50000,
            'cost_total' => 60000,
            'leakage_total' => 5000,
            'estimated_profit' => -10000,
            'metrics' => [
                'unrecognized_order_revenue' => 20000,
                'return_impact' => 4000,
                'direct_operational_costs' => 10000,
                'manual_overhead_costs' => 12000,
                'salary_pressure' => 8000,
                'order_counts' => ['delivered' => 10],
            ],
        ]);

        $this->actingAs($employee)
            ->get(TodaysWork::getUrl())
            ->assertOk()
            ->assertSee('Manager control')
            ->assertSee('Floor Worker, focus the team')
            ->assertSee('You report to')
            ->assertSee('Your direct team')
            ->assertSee('Your three actions today')
            ->assertSee('Who needs your attention')
            ->assertDontSee('Operational work - do it in Stock App')
            ->assertDontSee('Control work - do it in HELOAS')
            ->assertSee('Where to work: Stock App.')
            ->assertSee('Open Stock App')
            ->assertSee('Manager target recovery')
            ->assertSee('Monthly company target gap')
            ->assertSee('LKR 110,000.00')
            ->assertSee('Additional deliveries required')
            ->assertSee('Dispatch Assistant')
            ->assertSee('Managers see only the remaining operational gap')
            ->assertDontSee('Revenue LKR')
            ->assertDontSee('Total cost')
            ->assertDontSee('Estimated profit / loss')
            ->assertSee("Today's work", false)
            ->assertSee('Tasks Due Today')
            ->assertSee('High Priority')
            ->assertSee('Waiting For Review')
            ->assertSee('Completed Today')
            ->assertSee('Order needs a tracking number')
            ->assertSee('Bank transaction needs review')
            ->assertSee('Supplier payment needs settlement')
            ->assertDontSee('{{ $task', false)
            ->assertDontSee('@if (($task', false)
            ->assertDontSee('@endif', false);
    }

    public function test_employee_work_queue_url_redirects_to_todays_work(): void
    {
        $business = Business::query()->create([
            'name' => 'Restricted Queue Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $employee = User::query()->create([
            'name' => 'Floor Worker',
            'email' => 'restricted-employee@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
            'employee_access_profile' => 'work_only',
        ]);

        $this->actingAs($employee)
            ->get(ManagerWorkQueue::getUrl())
            ->assertRedirect(TodaysWork::getUrl());

        $this->actingAs($employee)
            ->get(TodaysWork::getUrl())
            ->assertOk();

        $this->actingAs($employee)
            ->get(ClientHealthReport::getUrl())
            ->assertForbidden();
    }

    public function test_employees_can_access_operational_pages(): void
    {
        $business = Business::query()->create([
            'name' => 'Restricted Employee Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $employee = User::query()->create([
            'name' => 'Floor Worker',
            'email' => 'restricted-work@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
            'employee_access_profile' => 'finance_ops',
            'staff_responsibilities' => ['bank_exceptions', 'expense_recording', 'production', 'material_stock'],
        ]);

        $this->actingAs($employee);

        $this->assertFalse(BankStatementImport::shouldRegisterNavigation());
        $this->assertFalse(BankTransactionResource::shouldRegisterNavigation());
        $this->assertFalse(ExpenseResource::shouldRegisterNavigation());
        $this->assertFalse(ProductionEntryResource::shouldRegisterNavigation());
        $this->assertFalse(MaterialLedgerResource::shouldRegisterNavigation());

        $this->actingAs($employee)
            ->get(BankStatementImport::getUrl())
            ->assertOk();

        $this->actingAs($employee)
            ->get(EmployeeResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($employee)
            ->get(BankTransactionResource::getUrl('index'))
            ->assertOk();

        $this->actingAs($employee)
            ->get(ExpenseResource::getUrl('index'))
            ->assertOk();

        $this->actingAs($employee)
            ->get(ProductionEntryResource::getUrl('index'))
            ->assertOk();

        $this->actingAs($employee)
            ->get(MaterialLedgerResource::getUrl('index'))
            ->assertOk();

        $this->actingAs($employee)
            ->get(SkuResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($employee)
            ->get(SkuRecipeResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_work_queue_screen_renders_for_client_owners(): void
    {
        $business = Business::query()->create([
            'name' => 'Render Queue Manager',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'queue-owner-render@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => false,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($owner)
            ->get(ManagerWorkQueue::getUrl())
            ->assertOk()
            ->assertSee('Work queue')
            ->assertSee('Blocked Work')
            ->assertSee('Team Workload')
            ->assertSee('Open Tasks')
            ->assertSee('Waiting For Review')
            ->assertSee('Completed Today');
    }

    public function test_bank_transaction_review_screen_shows_shared_treasury_fields(): void
    {
        $business = Business::query()->create([
            'name' => 'Treasury Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $user = User::query()->create([
            'name' => 'Bank Reviewer',
            'email' => 'bank-reviewer@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => false,
            'is_platform_admin' => true,
        ]);

        $transaction = BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => today()->toDateString(),
            'description' => 'Daily cash move',
            'money_container' => 'Current Account',
            'counter_money_container' => 'Savings Account',
            'debit' => 1000,
            'credit' => 0,
            'classification' => 'unknown',
            'transaction_type' => 'transfer',
            'status' => 'review',
        ]);

        $this->actingAs($user)
            ->get(BankTransactionResource::getUrl('edit', ['record' => $transaction]))
            ->assertOk()
            ->assertSee('HELOS money effect')
            ->assertSee('Which business does this belong to?')
            ->assertSee('What happened?')
            ->assertSee('Bank account / cash container')
            ->assertSee('Transfer destination account')
            ->assertSee('If money only moved into petty cash, store cash, savings, or another own account, choose Transfer, not Expense.');
    }

    public function test_client_owner_dashboard_keeps_the_existing_business_picture_and_operational_summary(): void
    {
        $business = Business::query()->create([
            'name' => 'Owner Queue Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $user = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'owner-queue@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => false,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(ClientHealthReport::getUrl())
            ->assertOk()
            ->assertSee('Operational completion summary')
            ->assertSee('Money Safe To Use');
    }

    public function test_generated_mission_refresh_does_not_cancel_owner_assigned_tasks(): void
    {
        $business = Business::query()->create([
            'name' => 'Manual Task Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $mission = Mission::query()->create([
            'source_key' => 'manual:test-production-entry',
            'mission_type' => 'owner_assigned_task',
            'responsibility_code' => 'production',
            'business_id' => $business->id,
            'source_type' => 'manual',
            'source_id' => 'test-production-entry',
            'title' => 'Enter today\'s completed production',
            'summary' => 'Record every completed SKU with good quantity and waste.',
            'priority' => 'high',
            'confidence' => 'confirmed',
            'due_at' => now()->endOfDay(),
            'status' => Mission::STATUS_OPEN,
        ]);

        app(MissionGeneratorService::class)->syncForBusiness($business);

        $this->assertSame(Mission::STATUS_OPEN, $mission->fresh()->status);
    }

    public function test_owner_can_open_the_simple_employee_task_form(): void
    {
        $business = Business::query()->create([
            'name' => 'Task Assignment Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Task Owner',
            'email' => 'task-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => false,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($owner)
            ->get(MissionResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Assign one clear employee task')
            ->assertSee('What must be done?')
            ->assertSee('Expected result')
            ->assertSee('Exact steps for the employee');
    }

    public function test_regular_employee_sees_a_private_colourful_contribution_plan(): void
    {
        $business = Business::query()->create([
            'name' => 'Contribution Plan Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $employee = User::query()->create([
            'name' => 'Delivery Employee',
            'email' => 'delivery-contribution@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
            'employee_access_profile' => 'operations',
            'staff_responsibilities' => ['dispatch', 'return_recovery'],
            'responsibilities_configured' => true,
            'is_staff_supervisor' => false,
        ]);

        FinancialSnapshot::query()->create([
            'business_id' => $business->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'revenue_total' => 50000,
            'cost_total' => 65000,
            'leakage_total' => 5000,
            'estimated_profit' => -15000,
            'metrics' => [
                'direct_operational_costs' => 20000,
                'order_counts' => ['delivered' => 20],
            ],
        ]);

        $this->actingAs($employee)
            ->get(TodaysWork::getUrl())
            ->assertOk()
            ->assertSee('Your contribution today')
            ->assertSee('Daily progress')
            ->assertSee('Protect today')
            ->assertSee('deliveries to support')
            ->assertDontSee('estimated_profit')
            ->assertDontSee('Monthly salary')
            ->assertDontSee('company profit');
    }
}

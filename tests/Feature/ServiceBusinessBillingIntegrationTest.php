<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\FinancialClarity\Services\CashIntelligenceService;
use App\Domains\FinancialClarity\Services\RevenuePipelineService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\ServiceClient;
use App\Domains\Shared\Services\WorkQueueService;
use App\Filament\Pages\ClientHealthReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ServiceBusinessBillingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_billing_feeds_revenue_cash_and_work_queue(): void
    {
        $business = $this->serviceBusiness();
        $fixedClient = ServiceClient::query()->create([
            'business_id' => $business->id,
            'name' => 'Horns England',
            'status' => ServiceClient::STATUS_ACTIVE,
            'billing_style' => ServiceClient::BILLING_FIXED_MONTHLY,
            'default_monthly_amount' => 25000,
            'default_due_day' => 5,
        ]);
        $variableClient = ServiceClient::query()->create([
            'business_id' => $business->id,
            'name' => 'ShoeHub SL',
            'status' => ServiceClient::STATUS_ACTIVE,
            'billing_style' => ServiceClient::BILLING_VARIABLE_MONTHLY,
            'default_monthly_amount' => 0,
        ]);

        ServiceBillingRecord::query()->create([
            'business_id' => $business->id,
            'service_client_id' => $fixedClient->id,
            'client_name' => 'Horns England',
            'billing_type' => ServiceBillingRecord::TYPE_REGISTRATION,
            'amount_due' => 25000,
            'paid_amount' => 25000,
            'payment_status' => 'paid',
            'due_on' => now()->toDateString(),
            'paid_on' => now()->toDateString(),
        ]);

        ServiceBillingRecord::query()->create([
            'business_id' => $business->id,
            'service_client_id' => $variableClient->id,
            'client_name' => 'ShoeHub SL',
            'billing_type' => ServiceBillingRecord::TYPE_SUBSCRIPTION,
            'amount_due' => 15000,
            'paid_amount' => 5000,
            'payment_status' => 'partial',
            'due_on' => now()->addDays(2)->toDateString(),
        ]);

        $snapshot = app(BusinessHealthSnapshotService::class)->previewCurrentMonth($business);
        $pipeline = app(RevenuePipelineService::class)->forCurrentMonth($business);
        $cash = app(CashIntelligenceService::class)->forCurrentMonth($business);
        $queue = app(WorkQueueService::class)->forBusiness($business);

        $this->assertSame(30000.0, (float) $snapshot['revenue_total']);
        $this->assertSame(40000.0, (float) $snapshot['metrics']['service_billing_expected']);
        $this->assertSame(30000.0, (float) $snapshot['metrics']['service_billing_collected']);
        $this->assertSame(10000.0, (float) $snapshot['metrics']['service_billing_outstanding']);
        $this->assertSame(2, (int) $snapshot['metrics']['service_active_clients']);
        $this->assertSame(25000.0, (float) $snapshot['metrics']['service_recurring_expected']);
        $this->assertSame(10000.0, (float) $pipeline['service']['expected_revenue']);
        $this->assertSame(30000.0, (float) $pipeline['service']['collected_revenue']);
        $this->assertSame(2, (int) $pipeline['service']['active_clients']);
        $this->assertSame(1, (int) $pipeline['service']['fixed_clients']);
        $this->assertSame(1, (int) $pipeline['service']['variable_clients']);
        $this->assertSame(25000.0, (float) $pipeline['service']['expected_monthly_revenue']);
        $this->assertSame(10000.0, (float) $cash['total_incoming_receivables']);
        $this->assertNotEmpty($cash['incoming_due_soon']);
        $this->assertTrue(collect($queue['tasks'])->contains(fn (array $task): bool => $task['work_type'] === 'service_collection'));
    }

    public function test_owner_dashboard_renders_service_billing_money(): void
    {
        $business = $this->serviceBusiness();
        $client = ServiceClient::query()->create([
            'business_id' => $business->id,
            'name' => 'COD Returns Client',
            'status' => ServiceClient::STATUS_ACTIVE,
            'billing_style' => ServiceClient::BILLING_FIXED_MONTHLY,
            'default_monthly_amount' => 12000,
        ]);
        ServiceBillingRecord::query()->create([
            'business_id' => $business->id,
            'service_client_id' => $client->id,
            'client_name' => 'COD Returns Client',
            'billing_type' => ServiceBillingRecord::TYPE_SUBSCRIPTION,
            'amount_due' => 12000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'due_on' => now()->toDateString(),
        ]);

        $owner = User::query()->create([
            'name' => 'Service Owner',
            'email' => 'service-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $this->actingAs($owner)
            ->get(ClientHealthReport::getUrl())
            ->assertOk()
            ->assertSee('Start Here')
            ->assertSee('Completed setup work')
            ->assertSee('Service business truth')
            ->assertSee('Should come this month')
            ->assertSee('Service money overdue')
            ->assertSee('COD Returns Client');
    }

    private function serviceBusiness(): Business
    {
        return Business::query()->create([
            'name' => 'COD Returns Lanka',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
        ]);
    }
}

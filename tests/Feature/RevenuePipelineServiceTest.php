<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\RevenuePipelineService;
use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Services\WorkQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenuePipelineServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_splits_cod_and_wholesale_revenue_pipeline(): void
    {
        $business = Business::query()->create([
            'name' => 'Pipeline Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_HYBRID,
            'primary_business_type' => Business::TYPE_MANUFACTURING,
            'secondary_business_types' => [Business::TYPE_TRADING],
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'COD-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 3000, 'channel' => 'cod'],
            'occurred_at' => now()->subDay(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'COD-2',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 4500,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 4500, 'channel' => 'cod'],
            'occurred_at' => now()->subDay(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::WHOLESALE_PARCEL_SENT,
            'external_id' => 'WHO-1',
            'channel' => 'wholesale',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 1500,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sale_amount' => 8000,
                'customer_paid_amount' => 3000,
                'customer_name' => 'Wholesale Customer',
                'customer_payment_method' => 'cash',
                'payment_due_at' => now()->addDays(3)->toDateTimeString(),
                'channel' => 'wholesale',
                'economics' => [
                    'product_cost_amount' => 1200,
                    'courier_amount' => 300,
                ],
            ],
            'occurred_at' => now()->subDay(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'WHO-2',
            'channel' => 'wholesale',
            'quantity' => 1,
            'revenue_amount' => 12000,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 12000, 'channel' => 'wholesale'],
            'occurred_at' => now()->subDay(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_RETURNED,
            'external_id' => 'COD-3',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 0,
            'leakage_amount' => 420,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 4500, 'channel' => 'cod'],
            'occurred_at' => now()->subDay(),
        ]);

        $pipeline = app(RevenuePipelineService::class)->forCurrentMonth($business);

        $this->assertSame(3000.0, (float) $pipeline['cod']['expected_revenue']);
        $this->assertSame(4500.0, (float) $pipeline['cod']['collected_revenue']);
        $this->assertSame(0.0, (float) $pipeline['cod']['cash_received']);
        $this->assertSame(4500.0, (float) $pipeline['cod']['settlement_gap']);
        $this->assertSame(0.0, (float) $pipeline['cod']['returned_revenue']);
        $this->assertSame(5000.0, (float) $pipeline['wholesale']['expected_revenue']);
        $this->assertSame(15000.0, (float) $pipeline['wholesale']['collected_revenue']);
        $this->assertSame(3000.0, (float) collect($pipeline['orders'])->firstWhere('external_id', 'WHO-1')['paid_amount']);
        $this->assertSame(5000.0, (float) collect($pipeline['orders'])->firstWhere('external_id', 'WHO-1')['remaining_amount']);
        $this->assertSame('Wholesale Customer', collect($pipeline['orders'])->firstWhere('external_id', 'WHO-1')['customer_name']);
        $this->assertNotEmpty($pipeline['actions']);

        $queue = app(WorkQueueService::class)->forBusiness($business);
        $this->assertTrue(collect($queue['tasks'])->contains(fn (array $task): bool => $task['work_type'] === 'wholesale_collection' && (float) ($task['amount'] ?? 0) === 5000.0));
    }

    public function test_cod_bank_settlement_confirms_cash_without_double_counting_revenue(): void
    {
        $business = Business::query()->create([
            'name' => 'COD Settlement Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'COD-SETTLED-1',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 5000,
            'direct_cost_amount' => 425,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => ['sale_amount' => 5000, 'channel' => 'cod'],
            'occurred_at' => now()->subDay(),
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'allocated_business_id' => $business->id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Courier weekly COD settlement',
            'money_container' => 'Current Account',
            'debit' => 0,
            'credit' => 4700,
            'balance' => 4700,
            'classification' => 'cod_settlement',
            'transaction_type' => 'cod_settlement',
            'status' => 'classified',
            'confidence' => 1,
            'reviewed_at' => now(),
        ]);

        $pipeline = app(RevenuePipelineService::class)->forCurrentMonth($business);
        $snapshot = app(BusinessHealthSnapshotService::class)->previewCurrentMonth($business);

        $this->assertSame(5000.0, (float) $pipeline['cod']['collected_revenue']);
        $this->assertSame(4700.0, (float) $pipeline['cod']['cash_received']);
        $this->assertSame(300.0, (float) $pipeline['cod']['settlement_gap']);
        $this->assertSame(4700.0, (float) $pipeline['cod_settlement']['cash_received']);
        $this->assertSame(5000.0, (float) $snapshot['revenue_total']);
    }
}

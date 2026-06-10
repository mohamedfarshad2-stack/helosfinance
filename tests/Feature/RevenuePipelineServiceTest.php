<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\RevenuePipelineService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
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
        $this->assertSame(0.0, (float) $pipeline['cod']['returned_revenue']);
        $this->assertSame(8000.0, (float) $pipeline['wholesale']['expected_revenue']);
        $this->assertSame(12000.0, (float) $pipeline['wholesale']['collected_revenue']);
        $this->assertNotEmpty($pipeline['actions']);
    }
}

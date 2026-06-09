<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CostAssumption;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\SkuStockMovement;
use App\Domains\Shared\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_order_keeps_profitability_cost_zero(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_CREATED,
            'external_id' => 'ORDER-0',
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 0)
            ->assertJsonPath('impact.leakage_amount', 0)
            ->assertJsonPath('impact.recovery_amount', 0)
            ->assertJsonPath('impact.economics.verification_amount', 0);
    }

    public function test_confirmed_order_keeps_profitability_cost_zero(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'verification_cost', 'label' => 'Verification cost', 'amount' => 50]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_CONFIRMED,
            'external_id' => 'ORDER-1',
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 0)
            ->assertJsonPath('impact.leakage_amount', 0)
            ->assertJsonPath('impact.recovery_amount', 0)
            ->assertJsonPath('impact.economics.verification_amount', 50);
    }

    public function test_tracking_number_adds_delivery_and_product_costs(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SKU-1',
            'name' => 'Test SKU',
            'material_cost' => 1000,
            'packaging_cost' => 100,
            'labor_rate' => 200,
            'finishing_cost' => 50,
            'expected_sale_price' => 3000,
        ]);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'delivery_fee', 'label' => 'Delivery cost', 'amount' => 350]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'ORDER-2',
            'sku_code' => 'SKU-1',
            'quantity' => 1,
            ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 1700)
            ->assertJsonPath('impact.leakage_amount', 0);

        $this->assertSame(1, SkuStockMovement::query()->where('business_id', $business->id)->where('movement_type', 'dispatch')->count());
    }

    public function test_dispatched_status_is_normalized_to_tracking_event(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SKU-2',
            'name' => 'Test SKU 2',
            'material_cost' => 1000,
            'packaging_cost' => 100,
            'labor_rate' => 200,
            'finishing_cost' => 50,
            'expected_sale_price' => 3000,
        ]);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'delivery_fee', 'label' => 'Delivery cost', 'amount' => 350]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'status' => 'dispatched',
            'external_id' => 'ORDER-2-ALIAS',
            'sku_code' => 'SKU-2',
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 1700)
            ->assertJsonPath('impact.leakage_amount', 0);

        $event = OperationalEvent::query()->where('business_id', $business->id)->where('external_id', 'ORDER-2-ALIAS')->first();

        $this->assertNotNull($event);
        $this->assertSame(OperationalEvent::TRACKING_NUMBER_ADDED, $event->event_type);
        $this->assertSame(1, SkuStockMovement::query()->where('business_id', $business->id)->where('movement_type', 'dispatch')->count());
    }

    public function test_delivered_order_records_revenue_without_repeating_costs(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'ORDER-3',
            'sale_amount' => 3000,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 3000)
            ->assertJsonPath('impact.direct_cost_amount', 0)
            ->assertJsonPath('impact.leakage_amount', 0);
    }

    public function test_resent_order_has_retry_cost_without_new_cogs(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'resend_courier_fee', 'label' => 'Resend courier cost', 'amount' => 180]);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'resend_packaging_fee', 'label' => 'Resend packaging cost', 'amount' => 120]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_RESENT,
            'external_id' => 'ORDER-2',
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 300)
            ->assertJsonPath('impact.leakage_amount', 0)
            ->assertJsonPath('impact.recovery_amount', 0)
            ->assertJsonPath('impact.economics.resend_courier_amount', 180)
            ->assertJsonPath('impact.economics.resend_packaging_amount', 120);
    }

    public function test_returned_order_has_return_cost_without_negative_revenue(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'return_courier_fee', 'label' => 'Return courier cost', 'amount' => 260]);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'return_packaging_fee', 'label' => 'Return packaging cost', 'amount' => 160]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_RETURNED,
            'external_id' => 'ORDER-4',
            'sale_amount' => 3000,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 0)
            ->assertJsonPath('impact.leakage_amount', 420)
            ->assertJsonPath('impact.recovery_amount', 0)
            ->assertJsonPath('impact.economics.return_courier_amount', 260)
            ->assertJsonPath('impact.economics.return_packaging_amount', 160);
    }

    public function test_returned_order_can_restock_back_into_stock(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SKU-RESTOCK',
            'name' => 'Restock SKU',
            'material_cost' => 800,
            'packaging_cost' => 100,
            'labor_rate' => 150,
            'finishing_cost' => 25,
            'expected_sale_price' => 2000,
        ]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_RETURNED,
            'external_id' => 'ORDER-RESTOCK',
            'sku_code' => 'SKU-RESTOCK',
            'quantity' => 1,
            'restockable' => true,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 0)
            ->assertJsonPath('impact.leakage_amount', 0)
            ->assertJsonPath('impact.recovery_amount', 1075)
            ->assertJsonPath('impact.economics.recovery_amount', 1075);

        $movement = SkuStockMovement::query()->where('business_id', $business->id)->where('movement_type', 'return_restocked')->first();

        $this->assertNotNull($movement);
        $this->assertSame(1, $movement->quantity_delta);
        $this->assertTrue($movement->is_restockable);
    }

    public function test_returned_order_can_be_marked_as_damaged_without_restocking(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SKU-DAMAGED',
            'name' => 'Damaged SKU',
            'material_cost' => 800,
            'packaging_cost' => 100,
            'labor_rate' => 150,
            'finishing_cost' => 25,
            'expected_sale_price' => 2000,
        ]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_RETURNED,
            'external_id' => 'ORDER-DAMAGED',
            'sku_code' => 'SKU-DAMAGED',
            'quantity' => 1,
            'restockable' => false,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 0)
            ->assertJsonPath('impact.leakage_amount', 0)
            ->assertJsonPath('impact.recovery_amount', 0)
            ->assertJsonPath('impact.economics.recovery_amount', 0);

        $movement = SkuStockMovement::query()->where('business_id', $business->id)->where('movement_type', 'return_damaged')->first();

        $this->assertNotNull($movement);
        $this->assertSame(0, $movement->quantity_delta);
        $this->assertFalse($movement->is_restockable);
    }

    public function test_resend_then_delivery_recognizes_revenue_without_new_product_cost(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SKU-RESEND',
            'name' => 'Resend SKU',
            'material_cost' => 800,
            'packaging_cost' => 100,
            'labor_rate' => 150,
            'finishing_cost' => 25,
            'expected_sale_price' => 2000,
        ]);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'resend_courier_fee', 'label' => 'Resend courier cost', 'amount' => 180]);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'resend_packaging_fee', 'label' => 'Resend packaging cost', 'amount' => 120]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_RESENT,
            'external_id' => 'ORDER-RESEND',
            'sku_code' => 'SKU-RESEND',
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 300)
            ->assertJsonPath('impact.leakage_amount', 0)
            ->assertJsonPath('impact.recovery_amount', 0)
            ->assertJsonPath('impact.economics.resend_courier_amount', 180)
            ->assertJsonPath('impact.economics.resend_packaging_amount', 120);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'ORDER-RESEND',
            'sku_code' => 'SKU-RESEND',
            'sale_amount' => 3000,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 3000)
            ->assertJsonPath('impact.direct_cost_amount', 0)
            ->assertJsonPath('impact.leakage_amount', 0)
            ->assertJsonPath('impact.recovery_amount', 0)
            ->assertJsonPath('impact.economics.sale_amount', 3000);
    }

    public function test_duplicate_webhook_event_is_not_created_twice(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        IntegrationSource::query()->create([
            'business_id' => $business->id,
            'name' => 'Test stock-app',
            'type' => 'stock_app',
            'base_url' => 'http://127.0.0.1:8001',
            'status' => 'testing',
            'settings' => ['stock_app_business_key' => 'TEST-001'],
        ]);

        $payload = [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_CONFIRMED,
            'external_id' => 'ORDER-DUP-1',
            'quantity' => 1,
        ];

        $this->postJson('/api/v1/stock-app/webhook', $payload)->assertCreated();
        $this->postJson('/api/v1/stock-app/webhook', $payload)->assertCreated();

        $this->assertSame(1, OperationalEvent::query()->count());
        $this->assertNotNull(IntegrationSource::query()->where('business_id', $business->id)->first()?->last_synced_at);
    }

    public function test_webhook_rejects_missing_business_context(): void
    {
        $this->postJson('/api/v1/stock-app/webhook', [
            'event_type' => OperationalEvent::ORDER_CONFIRMED,
            'external_id' => 'ORDER-MISSING-BIZ',
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Business context is required for this webhook.');
    }
}

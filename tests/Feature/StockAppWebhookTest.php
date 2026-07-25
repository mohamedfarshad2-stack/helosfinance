<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CostAssumption;
use App\Domains\Shared\Models\CourierRate;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuStockMovement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_discovers_stock_app_csr_as_employee_without_creating_login(): void
    {
        $business = Business::query()->create(['name' => 'Horns England']);

        $payload = [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_CONFIRMED,
            'external_id' => 'CSR-DISCOVERY-1',
            'csr_employee' => '  Shamindi  ',
            'quantity' => 1,
        ];

        $this->postJson('/api/v1/stock-app/webhook', $payload)->assertCreated();
        $this->postJson('/api/v1/stock-app/webhook', $payload)->assertCreated();

        $this->assertDatabaseHas('employees', [
            'business_id' => $business->id,
            'name' => 'Shamindi',
            'role' => 'CSR',
            'active' => true,
        ]);
        $this->assertSame(1, Employee::query()->where('business_id', $business->id)->count());
        $this->assertDatabaseMissing('users', ['name' => 'Shamindi']);
    }

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
            ->assertJsonPath('impact.direct_cost_amount', 1350)
            ->assertJsonPath('impact.economics.courier_amount', 0)
            ->assertJsonPath('impact.economics.delivery_charge_pending', 350)
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
            ->assertJsonPath('impact.direct_cost_amount', 1350)
            ->assertJsonPath('impact.economics.courier_amount', 0)
            ->assertJsonPath('impact.economics.delivery_charge_pending', 350)
            ->assertJsonPath('impact.leakage_amount', 0);

        $event = OperationalEvent::query()->where('business_id', $business->id)->where('external_id', 'ORDER-2-ALIAS')->first();

        $this->assertNotNull($event);
        $this->assertSame(OperationalEvent::TRACKING_NUMBER_ADDED, $event->event_type);
        $this->assertSame(1, SkuStockMovement::query()->where('business_id', $business->id)->where('movement_type', 'dispatch')->count());
    }

    public function test_webhook_upgrades_fallback_dispatch_date_when_real_stage_date_arrives(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'ORDER-DISPATCH-UPGRADE-1',
            'order_id' => 'ORDER-UPGRADE-1',
            'sale_amount' => 2500,
            'occurred_at' => '2026-07-10 09:00:00',
            'stage_occurred_at_source' => 'order_date',
        ])->assertCreated();

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'ORDER-DISPATCH-UPGRADE-1',
            'order_id' => 'ORDER-UPGRADE-1',
            'sale_amount' => 2500,
            'occurred_at' => '2026-07-11 14:30:00',
            'stage_occurred_at_source' => 'stock_app',
        ])->assertCreated();

        $event = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->where('external_id', 'ORDER-DISPATCH-UPGRADE-1')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('2026-07-11 14:30:00', $event->occurred_at?->format('Y-m-d H:i:s'));
        $this->assertSame('stock_app', $event->payload['stage_occurred_at_source'] ?? null);
    }

    public function test_wholesale_transport_status_records_parcel_cost_without_tracking_number(): void
    {
        $business = Business::query()->create(['name' => 'Wholesale Business']);
        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'WHO-SKU-1',
            'name' => 'Wholesale SKU',
            'material_cost' => 1000,
            'packaging_cost' => 100,
            'labor_rate' => 200,
            'finishing_cost' => 50,
            'expected_sale_price' => 4000,
        ]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'status' => 'transport_sent',
            'external_id' => 'WHO-TRANSPORT-1',
            'sku_code' => 'WHO-SKU-1',
            'channel' => 'wholesale',
            'quantity' => 1,
            'sale_amount' => 5000,
            'transport_cost_amount' => 600,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 1950)
            ->assertJsonPath('impact.economics.product_cost_amount', 1350)
            ->assertJsonPath('impact.economics.courier_amount', 600)
            ->assertJsonPath('impact.economics.sale_amount', 5000);

        $event = OperationalEvent::query()->where('business_id', $business->id)->where('external_id', 'WHO-TRANSPORT-1')->first();

        $this->assertNotNull($event);
        $this->assertSame(OperationalEvent::WHOLESALE_PARCEL_SENT, $event->event_type);
        $this->assertSame('wholesale', $event->channel);
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

    public function test_delivered_order_understands_customer_total_includes_delivery_charge(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'ORDER-GROSS-1',
            'product_sale_amount' => 1390,
            'customer_delivery_charge' => 350,
            'customer_total_amount' => 1740,
            'delivery_amount' => 425,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 1740)
            ->assertJsonPath('impact.direct_cost_amount', 425)
            ->assertJsonPath('impact.economics.gross_customer_amount', 1740)
            ->assertJsonPath('impact.economics.product_selling_amount', 1390)
            ->assertJsonPath('impact.economics.customer_delivery_charge_amount', 350)
            ->assertJsonPath('impact.economics.actual_courier_cost_amount', 425)
            ->assertJsonPath('impact.economics.delivery_charge_margin_amount', -75);
    }

    public function test_stock_app_delivered_order_uses_owner_courier_rate_when_payload_has_no_courier_cost(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);

        CourierRate::query()->create([
            'business_id' => $business->id,
            'courier_name' => 'Fardar Express',
            'delivery_charge' => 425,
            'return_charge' => 212.50,
            'resend_charge' => 0,
            'active' => true,
        ]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'ORDER-COURIER-RATE-1',
            'customer_total_amount' => 1740,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 1740)
            ->assertJsonPath('impact.direct_cost_amount', 425)
            ->assertJsonPath('impact.economics.actual_courier_cost_amount', 425)
            ->assertJsonPath('impact.economics.delivery_cost_source', 'courier_rate');
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
            ->assertJsonPath('impact.economics.return_packaging_amount', 160)
            ->assertJsonPath('impact.economics.return_marketing_amount', 0);
    }

    public function test_returned_order_includes_return_marketing_cost_when_configured(): void
    {
        $business = Business::query()->create(['name' => 'Return Marketing Business']);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'return_courier_fee', 'label' => 'Return courier cost', 'amount' => 260]);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'return_packaging_fee', 'label' => 'Return packaging cost', 'amount' => 160]);
        CostAssumption::query()->create(['business_id' => $business->id, 'key' => 'return_marketing_fee', 'label' => 'Return marketing cost', 'amount' => 500]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_RETURNED,
            'external_id' => 'ORDER-RETURN-MARKETING',
            'sale_amount' => 3000,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('impact.revenue_amount', 0)
            ->assertJsonPath('impact.direct_cost_amount', 0)
            ->assertJsonPath('impact.leakage_amount', 920)
            ->assertJsonPath('impact.economics.return_courier_amount', 260)
            ->assertJsonPath('impact.economics.return_packaging_amount', 160)
            ->assertJsonPath('impact.economics.return_marketing_amount', 500);
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

    public function test_duplicate_sync_event_keeps_the_earliest_real_occurred_at(): void
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

        $firstOccurredAt = Carbon::parse('2026-07-08 10:00:00')->toIso8601String();
        $wrongLaterOccurredAt = Carbon::parse('2026-07-11 09:00:00')->toIso8601String();

        $payload = [
            'business_key' => 'TEST-001',
            'orders' => [[
                'event_type' => 'tracking_number_added',
                'external_id' => 'ORDER-DATE-1',
                'sku_code' => 'SKU-001',
                'quantity' => 1,
                'occurred_at' => $firstOccurredAt,
            ]],
        ];

        $this->postJson('/api/v1/stock-app/sync/orders', $payload)->assertOk();

        $payload['orders'][0]['occurred_at'] = $wrongLaterOccurredAt;

        $this->postJson('/api/v1/stock-app/sync/orders', $payload)->assertOk();

        $event = OperationalEvent::query()->where('external_id', 'ORDER-DATE-1')->first();

        $this->assertNotNull($event);
        $this->assertSame('2026-07-08 10:00:00', $event->occurred_at?->format('Y-m-d H:i:s'));
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

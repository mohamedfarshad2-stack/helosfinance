<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuStockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAppSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_sync_orders_are_not_created_twice(): void
    {
        $business = Business::query()->create(['name' => 'Sync Business']);
        IntegrationSource::query()->create([
            'business_id' => $business->id,
            'name' => 'Sync stock-app',
            'type' => 'stock_app',
            'base_url' => 'http://127.0.0.1:8001',
            'status' => 'testing',
            'settings' => ['stock_app_business_key' => 'SYNC-001'],
        ]);

        $payload = [
            'business_id' => $business->id,
            'orders' => [
                [
                    'event_type' => OperationalEvent::ORDER_CONFIRMED,
                    'external_id' => 'SYNC-ORDER-1',
                    'quantity' => 1,
                ],
            ],
        ];

        $this->postJson('/api/v1/stock-app/sync/orders', $payload)
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('duplicates', 0);

        $this->postJson('/api/v1/stock-app/sync/orders', $payload)
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('duplicates', 1);

        $this->assertSame(1, OperationalEvent::query()->count());
        $this->assertNotNull(IntegrationSource::query()->where('business_id', $business->id)->first()?->last_synced_at);
    }

    public function test_sync_orders_reject_missing_business_context(): void
    {
        $this->postJson('/api/v1/stock-app/sync/orders', [
            'orders' => [
                [
                    'event_type' => OperationalEvent::ORDER_CONFIRMED,
                    'external_id' => 'SYNC-ORDER-MISSING-BIZ',
                    'quantity' => 1,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Business context is required for this sync.');
    }

    public function test_sync_orders_record_restockable_return_movement(): void
    {
        $business = Business::query()->create(['name' => 'Sync Business']);
        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SYNC-SKU-1',
            'name' => 'Sync SKU',
            'material_cost' => 500,
            'packaging_cost' => 50,
            'labor_rate' => 75,
            'finishing_cost' => 25,
            'expected_sale_price' => 1200,
        ]);

        $this->postJson('/api/v1/stock-app/sync/orders', [
            'business_id' => $business->id,
            'orders' => [
                [
                    'event_type' => OperationalEvent::ORDER_RETURNED,
                    'external_id' => 'SYNC-RETURN-1',
                    'sku_code' => 'SYNC-SKU-1',
                    'quantity' => 1,
                    'restockable' => true,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('duplicates', 0);

        $movement = SkuStockMovement::query()->where('business_id', $business->id)->where('movement_type', 'return_restocked')->first();

        $this->assertNotNull($movement);
        $this->assertSame(1, $movement->quantity_delta);
    }

    public function test_sync_orders_normalize_dispatched_status_to_tracking_event(): void
    {
        $business = Business::query()->create(['name' => 'Sync Business']);
        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SYNC-SKU-3',
            'name' => 'Sync SKU 3',
            'material_cost' => 500,
            'packaging_cost' => 50,
            'labor_rate' => 75,
            'finishing_cost' => 25,
            'expected_sale_price' => 1200,
        ]);

        $this->postJson('/api/v1/stock-app/sync/orders', [
            'business_id' => $business->id,
            'orders' => [
                [
                    'status' => 'dispatched',
                    'external_id' => 'SYNC-DISPATCH-1',
                    'sku_code' => 'SYNC-SKU-3',
                    'quantity' => 1,
                    'tracking_number' => 'TRK-001',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('duplicates', 0);

        $event = OperationalEvent::query()->where('business_id', $business->id)->where('external_id', 'SYNC-DISPATCH-1')->first();

        $this->assertNotNull($event);
        $this->assertSame(OperationalEvent::TRACKING_NUMBER_ADDED, $event->event_type);
        $this->assertSame(1, SkuStockMovement::query()->where('business_id', $business->id)->where('movement_type', 'dispatch')->count());
    }

    public function test_sync_orders_keep_return_revenue_zero_and_preserve_recovery_truth(): void
    {
        $business = Business::query()->create(['name' => 'Sync Business']);
        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SYNC-SKU-2',
            'name' => 'Sync SKU 2',
            'material_cost' => 500,
            'packaging_cost' => 50,
            'labor_rate' => 75,
            'finishing_cost' => 25,
            'expected_sale_price' => 1200,
        ]);

        $this->postJson('/api/v1/stock-app/sync/orders', [
            'business_id' => $business->id,
            'orders' => [
                [
                    'event_type' => OperationalEvent::ORDER_RETURNED,
                    'external_id' => 'SYNC-RETURN-2',
                    'sku_code' => 'SYNC-SKU-2',
                    'quantity' => 1,
                    'restockable' => true,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('duplicates', 0);

        $event = OperationalEvent::query()->where('business_id', $business->id)->where('external_id', 'SYNC-RETURN-2')->first();

        $this->assertNotNull($event);
        $this->assertSame(0.0, (float) $event->revenue_amount);
        $this->assertSame(0.0, (float) $event->direct_cost_amount);
        $this->assertSame(0.0, (float) $event->leakage_amount);
        $this->assertSame(650.0, (float) $event->recovery_amount);
        $this->assertArrayHasKey('economics', $event->payload);
        $this->assertSame(650.0, (float) ($event->payload['economics']['recovery_amount'] ?? 0));
    }
}

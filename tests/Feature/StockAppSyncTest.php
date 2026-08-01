<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuStockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAppSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_preserves_csr_identity_and_discovers_employee(): void
    {
        $business = Business::query()->create(['name' => 'Horns England']);

        $this->postJson('/api/v1/stock-app/sync/orders', [
            'business_id' => $business->id,
            'orders' => [[
                'event_type' => OperationalEvent::ORDER_CONFIRMED,
                'external_id' => 'CSR-SYNC-1',
                'csr_employee' => 'Pramila',
                'quantity' => 1,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('employees', [
            'business_id' => $business->id,
            'name' => 'Pramila',
            'role' => 'CSR',
        ]);
        $this->assertSame('Pramila', OperationalEvent::query()->first()?->payload['csr_employee']);
        $this->assertSame(1, Employee::query()->count());
    }

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

    public function test_sync_orders_normalize_pending_status_to_order_created(): void
    {
        $business = Business::query()->create(['name' => 'Sync Business']);

        $this->postJson('/api/v1/stock-app/sync/orders', [
            'business_id' => $business->id,
            'orders' => [
                [
                    'event_type' => 'order_updated',
                    'status' => 'Pending',
                    'external_id' => 'SYNC-PENDING-1',
                    'quantity' => 1,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertDatabaseHas('operational_events', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_CREATED,
            'external_id' => 'SYNC-PENDING-1',
        ]);
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

    public function test_sync_orders_upgrade_fallback_dispatch_date_when_real_stage_date_arrives(): void
    {
        $business = Business::query()->create(['name' => 'Sync Business']);

        $firstPayload = [
            'business_id' => $business->id,
            'orders' => [
                [
                    'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
                    'external_id' => 'SYNC-DISPATCH-UPGRADE-1',
                    'order_id' => 'ORDER-UPGRADE-1',
                    'sale_amount' => 2500,
                    'occurred_at' => '2026-07-10 09:00:00',
                    'stage_occurred_at_source' => 'order_date',
                ],
            ],
        ];

        $this->postJson('/api/v1/stock-app/sync/orders', $firstPayload)->assertOk();

        $secondPayload = [
            'business_id' => $business->id,
            'orders' => [
                [
                    'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
                    'external_id' => 'SYNC-DISPATCH-UPGRADE-1',
                    'order_id' => 'ORDER-UPGRADE-1',
                    'sale_amount' => 2500,
                    'occurred_at' => '2026-07-11 14:30:00',
                    'stage_occurred_at_source' => 'client_dispatched_at',
                ],
            ],
        ];

        $this->postJson('/api/v1/stock-app/sync/orders', $secondPayload)
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('duplicates', 1);

        $event = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->where('external_id', 'SYNC-DISPATCH-UPGRADE-1')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('2026-07-11 14:30:00', $event->occurred_at?->format('Y-m-d H:i:s'));
        $this->assertSame('client_dispatched_at', $event->payload['stage_occurred_at_source'] ?? null);
    }

    public function test_sku_list_endpoint_returns_business_item_codes(): void
    {
        $business = Business::query()->create(['name' => 'Sync Business']);
        IntegrationSource::query()->create([
            'business_id' => $business->id,
            'name' => 'Sync stock-app',
            'type' => 'stock_app',
            'status' => 'testing',
            'settings' => ['stock_app_business_key' => 'SYNC-001'],
        ]);
        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SYNC-SKU-3',
            'name' => 'Sync SKU 3',
            'active' => true,
        ]);

        $this->getJson('/api/v1/stock-app/skus?business_key=SYNC-001')
            ->assertOk()
            ->assertJsonPath('items.0.code', 'SYNC-SKU-3')
            ->assertJsonPath('items.0.name', 'Sync SKU 3');
    }

    public function test_sync_orders_create_missing_sku_for_finance_setup(): void
    {
        $business = Business::query()->create(['name' => 'Sync Business']);

        $this->postJson('/api/v1/stock-app/sync/orders', [
            'business_id' => $business->id,
            'orders' => [
                [
                    'status' => 'dispatched',
                    'external_id' => 'SYNC-NEW-SKU-1',
                    'sku_code' => 'NEW-SKU-1',
                    'sku_name' => 'New SKU 1',
                    'quantity' => 1,
                    'sale_amount' => 2500,
                    'tracking_number' => 'TRK-NEW-001',
                ],
            ],
        ])->assertOk();

        $sku = Sku::query()->where('business_id', $business->id)->where('code', 'NEW-SKU-1')->first();

        $this->assertNotNull($sku);
        $this->assertSame('New SKU 1', $sku->name);
        $this->assertSame(0.0, (float) $sku->productionCostPerUnit());
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

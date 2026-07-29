<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\OperationalEventRecalculator;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuStockMovement;
use App\Filament\Resources\OperationalEventResource\Pages\EditOperationalEvent;
use App\Filament\Resources\SkuResource\Pages\EditSku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class OperationalEventRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_sku_cost_recalculates_existing_tracking_events(): void
    {
        $business = Business::query()->create([
            'name' => 'Horns England',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'sku-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'PS476',
            'name' => 'Brown Geta',
            'material_cost' => 0,
            'packaging_cost' => 0,
            'labor_rate' => 0,
            'finishing_cost' => 0,
            'expected_sale_price' => 2350,
            'active' => true,
        ]);

        $event = OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'cod-order-ps476',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sku_code' => 'PS476',
                'sku_name' => 'Brown Geta',
                'sale_amount' => 2700,
                'tracking_number' => 'TRK-476',
                'economics' => [
                    'product_cost_amount' => 0,
                    'actual_courier_cost_amount' => 0,
                ],
            ],
            'occurred_at' => now(),
        ]);

        SkuStockMovement::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'operational_event_id' => $event->id,
            'movement_type' => 'dispatch',
            'order_external_id' => $event->external_id,
            'quantity' => 1,
            'quantity_delta' => -1,
            'is_restockable' => false,
            'note' => 'Parcel left the stock room for delivery.',
            'payload' => ['old' => true],
            'occurred_at' => now(),
        ]);

        $this->actingAs($owner);

        Livewire::test(EditSku::class, ['record' => (string) $sku->getKey()])
            ->fillForm([
                'business_id' => $business->id,
                'code' => 'PS476',
                'name' => 'Brown Geta',
                'expected_sale_price' => 2350,
                'active' => true,
                'material_cost' => 416,
                'packaging_cost' => 0,
                'labor_rate' => 291,
                'finishing_cost' => 0,
            ])
            ->call('save');

        $event->refresh();
        $movement = SkuStockMovement::query()->where('operational_event_id', $event->id)->first();

        $this->assertSame(0.0, (float) $event->direct_cost_amount);
        $this->assertSame(0.0, (float) ($event->payload['economics']['product_cost_amount'] ?? 0));
        $this->assertSame(707.0, (float) ($event->payload['economics']['production_cost_reference_amount'] ?? 0));
        $this->assertNotNull($movement);
        $this->assertSame(1, SkuStockMovement::query()->where('operational_event_id', $event->id)->count());
        $this->assertSame('PS476', $movement->payload['sku_code'] ?? null);
    }

    public function test_stale_zero_cost_events_can_be_backfilled_without_editing_the_sku_again(): void
    {
        $business = Business::query()->create([
            'name' => 'Backfill Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'PS500',
            'name' => 'Recovered Cost SKU',
            'material_cost' => 500,
            'packaging_cost' => 20,
            'labor_rate' => 80,
            'finishing_cost' => 0,
            'expected_sale_price' => 2000,
            'active' => true,
        ]);

        $event = OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'stale-zero-cost',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sku_code' => 'PS500',
                'sku_name' => 'Recovered Cost SKU',
                'sale_amount' => 2000,
                'economics' => [
                    'product_cost_amount' => 0,
                ],
            ],
            'occurred_at' => now(),
        ]);

        $processed = app(OperationalEventRecalculator::class)->recalculateStaleProductCostEvents();

        $event->refresh();

        $this->assertSame(1, $processed);
        $this->assertSame(0.0, (float) $event->direct_cost_amount);
        $this->assertSame(0.0, (float) ($event->payload['economics']['product_cost_amount'] ?? 0));
        $this->assertSame(600.0, (float) ($event->payload['economics']['production_cost_reference_amount'] ?? 0));
    }

    public function test_editing_operational_event_sku_recalculates_costs_immediately(): void
    {
        $business = Business::query()->create([
            'name' => 'Event Edit Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'event-edit-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'PS476',
            'name' => 'Brown Geta',
            'material_cost' => 416,
            'packaging_cost' => 0,
            'labor_rate' => 291,
            'finishing_cost' => 0,
            'expected_sale_price' => 2350,
            'active' => true,
        ]);

        $event = OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'cod-order-edit-ps476',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 0,
            'direct_cost_amount' => 0,
            'leakage_amount' => 0,
            'recovery_amount' => 0,
            'payload' => [
                'sku_code' => 'ps 476',
                'sku_name' => 'brown geta 1390 + 350 delivery size 6',
                'sale_amount' => 1740,
                'tracking_number' => 'TRK-EDIT-476',
                'economics' => [
                    'product_cost_amount' => 0,
                ],
            ],
            'occurred_at' => now(),
        ]);

        $this->actingAs($owner);

        Livewire::test(EditOperationalEvent::class, ['record' => (string) $event->getKey()])
            ->fillForm([
                'business_id' => $business->id,
                'sku_id' => $sku->id,
                'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
                'external_id' => $event->external_id,
                'channel' => 'cod',
                'department' => 'Operations',
                'quantity' => 1,
                'revenue_amount' => 0,
                'direct_cost_amount' => 0,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'occurred_at' => $event->occurred_at,
            ])
            ->call('save');

        $event->refresh();

        $this->assertSame($sku->id, $event->sku_id);
        $this->assertSame(0.0, (float) $event->direct_cost_amount);
        $this->assertSame('PS476', $event->payload['sku_code'] ?? null);
        $this->assertSame(0.0, (float) ($event->payload['economics']['product_cost_amount'] ?? 0));
        $this->assertSame(707.0, (float) ($event->payload['economics']['production_cost_reference_amount'] ?? 0));
    }
}

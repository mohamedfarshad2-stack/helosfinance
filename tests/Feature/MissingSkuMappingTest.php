<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\TrustValidationService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuStockMovement;
use App\Filament\Pages\MissingSkuMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MissingSkuMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_repair_missing_product_link_and_recalculate_event_costs(): void
    {
        $business = Business::query()->create([
            'name' => 'Horns England',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'PS364',
            'name' => 'Slipper PS364',
            'material_cost' => 500,
            'packaging_cost' => 50,
            'labor_rate' => 75,
            'finishing_cost' => 25,
            'active' => true,
        ]);

        $event = OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'cod-order-1001-tracking',
            'channel' => 'cod',
            'quantity' => 2,
            'payload' => [
                'customer_name' => 'Customer One',
                'tracking_number' => 'TRK-1001',
                'sale_amount' => 2500,
            ],
            'occurred_at' => now()->subDay(),
        ]);

        $this->actingAs($owner);

        Livewire::test(MissingSkuMapping::class)
            ->assertSee('cod-order-1001-tracking')
            ->set("skuSelections.{$event->id}", $sku->id)
            ->call('assignSku', $event->id);

        $event->refresh();

        $this->assertSame($sku->id, $event->sku_id);
        $this->assertSame(1300.0, (float) $event->direct_cost_amount);
        $this->assertSame('PS364', $event->payload['sku_code']);

        $movement = SkuStockMovement::query()->where('operational_event_id', $event->id)->first();

        $this->assertNotNull($movement);
        $this->assertSame($sku->id, $movement->sku_id);
        $this->assertSame('dispatch', $movement->movement_type);
        $this->assertSame(-1, $movement->quantity_delta);
    }

    public function test_operational_staff_can_open_missing_product_links_without_owner_dashboard_access(): void
    {
        $business = Business::query()->create([
            'name' => 'Staff Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $staff = User::query()->create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'operations',
        ]);

        $this->actingAs($staff)
            ->get(MissingSkuMapping::getUrl())
            ->assertOk()
            ->assertSee('Fix orders that cannot calculate product profit');
    }

    public function test_trust_warning_opens_missing_product_link_repair_path(): void
    {
        $business = Business::query()->create([
            'name' => 'Warning Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'cod-order-missing-sku',
            'channel' => 'cod',
            'quantity' => 1,
            'payload' => ['sale_amount' => 1800],
            'occurred_at' => now(),
        ]);

        $trust = app(TrustValidationService::class)->forCurrentMonth($business);
        $warning = collect($trust['warnings']['critical'])
            ->firstWhere('title', 'Missing stock mapping');

        $this->assertSame('Fix missing product links', $warning['action_label']);
        $this->assertSame(MissingSkuMapping::getUrl(), $warning['action_url']);
        $this->assertStringContainsString('choose the correct product', $warning['fix_guidance']);
    }

    public function test_repeated_product_hint_can_be_repaired_in_bulk(): void
    {
        $business = Business::query()->create([
            'name' => 'Bulk Repair Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'bulk-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'PS-BLUE',
            'name' => 'Blue Slipper',
            'material_cost' => 300,
            'packaging_cost' => 40,
            'labor_rate' => 60,
            'finishing_cost' => 20,
            'active' => true,
        ]);

        foreach ([1, 2] as $number) {
            OperationalEvent::query()->create([
                'business_id' => $business->id,
                'sku_id' => null,
                'source' => 'stock_app_sync',
                'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
                'external_id' => 'bulk-order-'.$number,
                'channel' => 'cod',
                'quantity' => 1,
                'payload' => [
                    'sku_name' => 'Blue slipper from stock app',
                    'sale_amount' => 1900,
                ],
                'occurred_at' => now()->subDay(),
            ]);
        }

        $this->actingAs($owner);

        Livewire::test(MissingSkuMapping::class)
            ->assertSee('Blue slipper from stock app')
            ->set('bulkSkuSelections.blue-slipper-from-stock-app', $sku->id)
            ->call('assignGroup', 'blue-slipper-from-stock-app', null)
            ->assertDontSee('Blue slipper from stock app');

        $this->assertSame(0, OperationalEvent::query()->where('business_id', $business->id)->whereNull('sku_id')->count());
        $this->assertSame(2, SkuStockMovement::query()->where('sku_id', $sku->id)->count());
    }

    public function test_repairing_the_same_event_again_does_not_duplicate_stock_movement(): void
    {
        $business = Business::query()->create([
            'name' => 'Duplicate Safety Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'duplicate-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'PS-DUP',
            'name' => 'Duplicate Safe Slipper',
            'active' => true,
        ]);

        $event = OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'duplicate-safe-order',
            'channel' => 'cod',
            'quantity' => 1,
            'payload' => [
                'sku_name' => 'Duplicate Safe Slipper',
                'sale_amount' => 1900,
            ],
            'occurred_at' => now(),
        ]);

        $this->actingAs($owner);

        Livewire::test(MissingSkuMapping::class)
            ->set("skuSelections.{$event->id}", $sku->id)
            ->call('assignSku', $event->id);

        $event->update(['sku_id' => null]);

        Livewire::test(MissingSkuMapping::class)
            ->set("skuSelections.{$event->id}", $sku->id)
            ->call('assignSku', $event->id);

        $this->assertSame(1, SkuStockMovement::query()->where('operational_event_id', $event->id)->count());
    }

    public function test_bulk_repair_form_post_repairs_group_and_flashes_result(): void
    {
        $business = Business::query()->create([
            'name' => 'Post Repair Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'post-repair-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'BROWN-43',
            'name' => 'Brown Geta Size 43',
            'active' => true,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'cod-order-form-post',
            'channel' => 'cod',
            'quantity' => 1,
            'payload' => [
                'sku_name' => 'brown geta 1390 + 350 delivery Size 43',
                'sale_amount' => 1740,
            ],
            'occurred_at' => now(),
        ]);

        $this->actingAs($owner)
            ->post(route('admin.missing-product-links.repair-group'), [
                'business_id' => $business->id,
                'group_key' => 'brown-geta-1390-350-delivery-size-43',
                'sku_id' => $sku->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('missing_product_repair_success');

        $this->assertSame(0, OperationalEvent::query()->where('business_id', $business->id)->whereNull('sku_id')->count());
    }

    public function test_confirmed_rows_without_tracking_do_not_show_in_missing_product_links_page(): void
    {
        $business = Business::query()->create([
            'name' => 'Confirmed Queue Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'confirmed-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::ORDER_CONFIRMED,
            'external_id' => 'cod-order-confirmed-only',
            'channel' => 'cod',
            'quantity' => 1,
            'payload' => [
                'customer_name' => 'Waiting Customer',
                'sale_amount' => 1990,
            ],
            'occurred_at' => now(),
        ]);

        $this->actingAs($owner);

        Livewire::test(MissingSkuMapping::class)
            ->assertDontSee('cod-order-confirmed-only')
            ->assertSet('businessId', $business->id);
    }

    public function test_auto_fix_obvious_matches_repairs_exact_code_and_name_rows(): void
    {
        $business = Business::query()->create([
            'name' => 'Auto Fix Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'autofix-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'PS487',
            'name' => 'Brown Geta Size 10',
            'material_cost' => 400,
            'packaging_cost' => 40,
            'labor_rate' => 50,
            'finishing_cost' => 10,
            'active' => true,
        ]);

        $exactCode = OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'auto-code',
            'channel' => 'cod',
            'quantity' => 1,
            'payload' => [
                'sku_code' => 'PS487',
                'sale_amount' => 2500,
            ],
            'occurred_at' => now()->subDay(),
        ]);

        $exactName = OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'auto-name',
            'channel' => 'cod',
            'quantity' => 1,
            'payload' => [
                'sku_name' => 'Brown Geta Size 10',
                'sale_amount' => 2500,
            ],
            'occurred_at' => now()->subDay(),
        ]);

        $unclear = OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'auto-unclear',
            'channel' => 'cod',
            'quantity' => 1,
            'payload' => [
                'sku_name' => 'brown geta 1390 + 350 delivery size 10',
                'sale_amount' => 2500,
            ],
            'occurred_at' => now()->subDay(),
        ]);

        $this->actingAs($owner);

        Livewire::test(MissingSkuMapping::class)
            ->call('autoFixObviousMatches');

        $this->assertSame($sku->id, $exactCode->fresh()->sku_id);
        $this->assertSame($sku->id, $exactName->fresh()->sku_id);
        $this->assertNull($unclear->fresh()->sku_id);
    }

    public function test_missing_product_links_defaults_to_backlog_scope_and_separates_today_from_older_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 10:00:00'));

        $business = Business::query()->create([
            'name' => 'Queue Scope Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'scope-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'backlog-row',
            'channel' => 'cod',
            'quantity' => 1,
            'payload' => ['sku_name' => 'Backlog Item'],
            'occurred_at' => Carbon::parse('2026-07-10 15:00:00'),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => null,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'today-row',
            'channel' => 'cod',
            'quantity' => 1,
            'payload' => ['sku_name' => 'Today Item'],
            'occurred_at' => Carbon::parse('2026-07-11 09:00:00'),
        ]);

        $this->actingAs($owner);

        Livewire::test(MissingSkuMapping::class)
            ->assertSet('scope', 'backlog')
            ->assertSee('1')
            ->assertSee('Older backlog')
            ->assertSee('New today')
            ->assertSee('Total unresolved')
            ->assertSee('backlog-row')
            ->assertDontSee('today-row')
            ->set('scope', 'today')
            ->assertDontSee('backlog-row')
            ->assertSee('today-row')
            ->set('scope', 'all')
            ->assertSee('backlog-row')
            ->assertSee('today-row');

        Carbon::setTestNow();
    }
}

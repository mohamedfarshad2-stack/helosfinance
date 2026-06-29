<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\InternalCodOrderEventService;
use App\Domains\FinancialClarity\Services\CourierRateService;
use App\Domains\FinancialClarity\Services\CodOrderSpreadsheetImportService;
use App\Domains\FinancialClarity\Services\CodOrderTemplateExportService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CodOrder;
use App\Domains\Shared\Models\CodOrderSource;
use App\Domains\Shared\Models\CourierRate;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Filament\Pages\CodOrderWorkbench;
use App\Filament\Resources\CodOrderResource;
use App\Filament\Resources\CostAssumptionResource;
use App\Filament\Resources\CourierRateResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InternalCodOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_cod_order_syncs_real_per_order_courier_costs_to_operational_truth(): void
    {
        $business = Business::query()->create([
            'name' => 'Internal COD Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_INTERNAL],
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SLIPPER-01',
            'name' => 'Slipper 01',
            'material_cost' => 100,
            'packaging_cost' => 20,
            'labor_rate' => 30,
            'finishing_cost' => 0,
            'expected_sale_price' => 2500,
            'active' => true,
        ]);

        $order = CodOrder::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'order_number' => 'HELOS-1001',
            'customer_name' => 'Test Customer',
            'quantity' => 1,
            'sale_amount' => 2500,
            'status' => CodOrder::STATUS_DISPATCHED,
            'courier_name' => 'Courier A',
            'tracking_number' => 'TRK-1',
            'delivery_charge' => 375,
            'return_charge' => 225,
            'order_date' => today(),
            'dispatched_on' => today(),
        ]);

        app(InternalCodOrderEventService::class)->sync($order);

        $dispatchEvent = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->where('external_id', 'HELOS-1001')
            ->where('event_type', OperationalEvent::TRACKING_NUMBER_ADDED)
            ->firstOrFail();

        $this->assertSame('helos_internal_cod', $dispatchEvent->source);
        $this->assertSame('Courier A', $dispatchEvent->payload['courier_name']);
        $this->assertSame(150.0, (float) $dispatchEvent->direct_cost_amount);
        $this->assertSame(0.0, (float) $dispatchEvent->payload['economics']['courier_amount']);
        $this->assertSame(375.0, (float) $dispatchEvent->payload['economics']['delivery_charge_pending']);

        $order->update([
            'status' => CodOrder::STATUS_DELIVERED,
            'delivered_on' => today(),
        ]);

        app(InternalCodOrderEventService::class)->sync($order->fresh());

        $deliveredEvent = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->where('external_id', 'HELOS-1001')
            ->where('event_type', OperationalEvent::ORDER_DELIVERED)
            ->firstOrFail();

        $this->assertSame(2500.0, (float) $deliveredEvent->revenue_amount);
        $this->assertSame(375.0, (float) $deliveredEvent->direct_cost_amount);
        $this->assertSame(375.0, (float) $deliveredEvent->payload['economics']['courier_amount']);

        $order->update([
            'status' => CodOrder::STATUS_RETURNED,
            'returned_on' => today(),
        ]);

        app(InternalCodOrderEventService::class)->sync($order->fresh());

        $returnEvent = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->where('external_id', 'HELOS-1001')
            ->where('event_type', OperationalEvent::ORDER_RETURNED)
            ->firstOrFail();

        $this->assertSame(225.0, (float) $returnEvent->leakage_amount);
        $this->assertSame(225.0, (float) $returnEvent->payload['economics']['return_courier_amount']);
    }

    public function test_cod_order_resource_only_opens_for_businesses_using_internal_cod(): void
    {
        $stockAppBusiness = Business::query()->create([
            'name' => 'Stock App Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_STOCK_APP],
        ]);

        $internalBusiness = Business::query()->create([
            'name' => 'Internal COD Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_INTERNAL],
        ]);

        $owner = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $stockAppBusiness->id,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($owner);
        $this->assertFalse(CodOrderResource::canAccess());

        $owner->update(['business_id' => $internalBusiness->id]);
        $this->actingAs($owner->fresh());
        $this->assertTrue(CodOrderResource::canAccess());
    }

    public function test_resend_from_stock_dispatch_does_not_add_product_cogs_again(): void
    {
        $business = Business::query()->create([
            'name' => 'Internal COD Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_INTERNAL],
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'RESEND-SLIPPER',
            'name' => 'Resend Slipper',
            'material_cost' => 100,
            'packaging_cost' => 20,
            'labor_rate' => 30,
            'finishing_cost' => 0,
            'expected_sale_price' => 2500,
            'active' => true,
        ]);

        $order = CodOrder::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'order_number' => 'RESEND-1001',
            'customer_name' => 'Test Customer',
            'quantity' => 1,
            'sale_amount' => 2500,
            'status' => CodOrder::STATUS_DISPATCHED,
            'courier_name' => 'Courier B',
            'tracking_number' => 'TRK-RESEND',
            'delivery_charge' => 375,
            'resend_from_stock' => true,
            'order_date' => today(),
            'dispatched_on' => today(),
        ]);

        app(InternalCodOrderEventService::class)->sync($order);

        $event = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->where('external_id', 'RESEND-1001')
            ->where('event_type', OperationalEvent::TRACKING_NUMBER_ADDED)
            ->firstOrFail();

        $this->assertSame(0.0, (float) $event->direct_cost_amount);
        $this->assertTrue((bool) $event->payload['resend_from_stock']);
        $this->assertTrue((bool) $event->payload['economics']['product_cost_skipped']);
        $this->assertSame(0.0, (float) $event->payload['economics']['product_cost_amount']);
    }

    public function test_no_answer_and_courier_pending_do_not_create_false_money_events(): void
    {
        $business = Business::query()->create([
            'name' => 'Internal COD Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_INTERNAL],
        ]);

        foreach ([CodOrder::STATUS_NO_ANSWER, CodOrder::STATUS_COURIER_PENDING, CodOrder::STATUS_CANCELLED] as $status) {
            $order = CodOrder::query()->create([
                'business_id' => $business->id,
                'order_number' => 'NO-MONEY-'.$status,
                'customer_name' => 'Test Customer',
                'quantity' => 1,
                'sale_amount' => 1000,
                'status' => $status,
                'order_date' => today(),
            ]);

            app(InternalCodOrderEventService::class)->sync($order);

            $this->assertDatabaseMissing('operational_events', [
                'business_id' => $business->id,
                'external_id' => 'NO-MONEY-'.$status,
            ]);
        }
    }

    public function test_internal_cod_sync_is_ignored_for_stock_app_businesses(): void
    {
        $business = Business::query()->create([
            'name' => 'Stock App Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_STOCK_APP],
        ]);

        $order = CodOrder::query()->create([
            'business_id' => $business->id,
            'order_number' => 'SHOULD-NOT-SYNC',
            'customer_name' => 'Test Customer',
            'quantity' => 1,
            'sale_amount' => 1000,
            'status' => CodOrder::STATUS_DISPATCHED,
            'delivery_charge' => 250,
            'order_date' => today(),
        ]);

        app(InternalCodOrderEventService::class)->sync($order);

        $this->assertDatabaseMissing('operational_events', [
            'business_id' => $business->id,
            'external_id' => 'SHOULD-NOT-SYNC',
        ]);
    }

    public function test_owner_courier_rate_can_be_applied_to_cod_order(): void
    {
        $business = Business::query()->create([
            'name' => 'Internal COD Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_INTERNAL],
        ]);

        CourierRate::query()->create([
            'business_id' => $business->id,
            'courier_name' => 'Courier A',
            'delivery_charge' => 350,
            'return_charge' => 250,
            'resend_charge' => 200,
            'active' => true,
        ]);

        $order = CodOrder::query()->create([
            'business_id' => $business->id,
            'order_number' => 'RATE-1001',
            'customer_name' => 'Test Customer',
            'quantity' => 1,
            'sale_amount' => 1000,
            'status' => CodOrder::STATUS_CONFIRMED,
            'order_date' => today(),
        ]);

        app(CourierRateService::class)->applyToOrder($order, 'Courier A');

        $order->refresh();

        $this->assertSame('Courier A', $order->courier_name);
        $this->assertSame(350.0, (float) $order->delivery_charge);
        $this->assertSame(250.0, (float) $order->return_charge);
        $this->assertSame(200.0, (float) $order->resend_charge);
    }

    public function test_courier_rates_are_owner_only_not_staff_controlled(): void
    {
        $business = Business::query()->create([
            'name' => 'Internal COD Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_INTERNAL],
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-rates@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
        ]);

        $staff = User::query()->create([
            'name' => 'Staff',
            'email' => 'staff-rates@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'operations',
        ]);

        $this->actingAs($owner);
        $this->assertTrue(CourierRateResource::canAccess());

        $this->actingAs($staff);
        $this->assertFalse(CourierRateResource::canAccess());
        $this->assertTrue(CodOrderResource::canAccess());
    }

    public function test_cost_rules_are_hidden_from_client_owners_to_avoid_duplicate_courier_setup(): void
    {
        $business = Business::query()->create([
            'name' => 'Internal COD Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_INTERNAL],
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-cost-rules@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
        ]);

        $platformAdmin = User::query()->create([
            'name' => 'Super Admin',
            'email' => 'admin-cost-rules@example.com',
            'password' => Hash::make('password'),
            'is_platform_admin' => true,
            'is_employee' => false,
        ]);

        $this->actingAs($owner);
        $this->assertFalse(CostAssumptionResource::canAccess());
        $this->assertTrue(CourierRateResource::canAccess());

        $this->actingAs($platformAdmin);
        $this->assertTrue(CostAssumptionResource::canAccess());
    }

    public function test_cod_orders_can_be_bulk_uploaded_for_calling_work(): void
    {
        $business = Business::query()->create([
            'name' => 'Internal COD Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_INTERNAL],
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'COD-SKU-1',
            'name' => 'COD SKU',
            'expected_sale_price' => 1500,
            'active' => true,
        ]);
        $source = CodOrderSource::query()->create([
            'business_id' => $business->id,
            'name' => 'WhatsApp',
            'active' => true,
        ]);
        $employee = Employee::query()->create([
            'business_id' => $business->id,
            'name' => 'CSR One',
            'role' => 'CSR',
            'active' => true,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'cod_orders_').'.csv';
        file_put_contents($path, implode(PHP_EOL, [
            'business_name,order_number,customer_name,customer_phone,customer_alt_phone,address,city,district,sku_code,size,sale_amount,order_source,csr_employee,remark,delivery_instruction,order_date',
            "{$business->name},COD-1,Customer One,0771111111,0779999999,No 10 Main Street,Colombo,Colombo,{$sku->code},8,1500,{$source->name},{$employee->name},First call,Leave near gate,2026-06-21",
            "{$business->name},COD-2,Customer Two,0772222222,,No 25 Temple Road,Galle,Galle,{$sku->code},9,3000,{$source->name},{$employee->name},,,2026-06-21",
        ]));

        $result = app(CodOrderSpreadsheetImportService::class)->import($business, $path);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseHas('cod_orders', [
            'business_id' => $business->id,
            'order_number' => 'COD-1',
            'customer_name' => 'Customer One',
            'customer_phone' => '0771111111',
            'customer_alt_phone' => '0779999999',
            'address' => 'No 10 Main Street',
            'district' => 'Colombo',
            'size' => '8',
            'cod_order_source_id' => $source->id,
            'csr_employee_id' => $employee->id,
            'delivery_instruction' => 'Leave near gate',
            'status' => CodOrder::STATUS_NEW,
        ]);
        $this->assertDatabaseHas('cod_orders', [
            'business_id' => $business->id,
            'order_number' => 'COD-2',
            'size' => '9',
        ]);
    }

    public function test_cod_order_excel_template_can_be_generated(): void
    {
        $business = Business::query()->create([
            'name' => 'Internal COD Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_INTERNAL],
        ]);

        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'COD-SKU-1',
            'name' => 'COD SKU',
            'active' => true,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'cod_template_').'.xlsx';

        app(CodOrderTemplateExportService::class)->export($business, [$business->id => $business->name], $path);

        $this->assertFileExists($path);
        $this->assertGreaterThan(1000, filesize($path));
    }

    public function test_cod_order_workbench_renders_all_daily_fields_without_old_resource_navigation(): void
    {
        $business = Business::query()->create([
            'name' => 'Internal COD Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
            'settings' => ['cod_order_source' => Business::COD_SOURCE_INTERNAL],
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'workbench-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
        ]);

        CodOrder::query()->create([
            'business_id' => $business->id,
            'order_number' => 'COD-1001',
            'customer_name' => 'Customer One',
            'customer_phone' => '0771234567',
            'address' => 'No 10 Main Street',
            'city' => 'Colombo',
            'size' => '8',
            'sale_amount' => 2500,
            'status' => CodOrder::STATUS_NEW,
            'order_date' => today(),
            'confirmation_remark' => 'Call after 6pm',
        ]);

        $this->actingAs($owner)
            ->get(CodOrderWorkbench::getUrl())
            ->assertOk()
            ->assertSee('Customer One')
            ->assertSee('Tracking')
            ->assertSee('Address')
            ->assertSee('Alt phone')
            ->assertSee('District')
            ->assertSee('Size')
            ->assertSee('Source')
            ->assertSee('CSR')
            ->assertSee('From stock?')
            ->assertSee('Remark');

        $this->assertFalse(CodOrderResource::shouldRegisterNavigation());
    }
}

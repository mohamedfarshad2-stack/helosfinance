<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CourierRate;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\WholesaleOrder;
use App\Filament\Pages\NifrasAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class NifrasWholesaleOrderCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_nifras_can_book_a_wholesale_order_and_see_profit_calculation(): void
    {
        $business = Business::query()->create([
            'name' => 'ShoeHub Wholesale',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $skuOne = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SH-001',
            'name' => 'Black Loafer',
            'material_cost' => 400,
            'packaging_cost' => 60,
            'labor_rate' => 150,
            'finishing_cost' => 90,
            'expected_sale_price' => 1400,
            'active' => true,
        ]);

        $skuTwo = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SH-002',
            'name' => 'Tan Sandal',
            'material_cost' => 260,
            'packaging_cost' => 40,
            'labor_rate' => 120,
            'finishing_cost' => 80,
            'expected_sale_price' => 900,
            'active' => true,
        ]);

        CourierRate::query()->create([
            'business_id' => $business->id,
            'courier_name' => 'Fast Express',
            'delivery_charge' => 300,
            'return_charge' => 150,
            'resend_charge' => 120,
            'active' => true,
        ]);

        $nifras = User::query()->create([
            'name' => 'Nifras',
            'email' => 'nifras@helos.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
        ]);

        Livewire::actingAs($nifras)
            ->test(NifrasAccount::class)
            ->set('data', [
                'business_id' => $business->id,
                'order_number' => null,
                'customer_name' => 'Dilshan Footwear',
                'customer_phone' => '0771234567',
                'customer_location' => 'Kurunegala',
                'order_date' => '2026-08-15',
                'status' => WholesaleOrder::STATUS_DELIVERED,
                'payment_status' => WholesaleOrder::PAYMENT_PARTIAL,
                'payment_method' => 'bank',
                'delivery_method' => WholesaleOrder::DELIVERY_COURIER,
                'courier_name' => 'Fast Express',
                'discount_amount' => 150,
                'delivery_charge_charged' => 250,
                'paid_amount' => 2000,
                'notes' => 'First wholesale booking through the Nifras account desk.',
                'line_items' => [
                    [
                        'sku_id' => $skuOne->id,
                        'size' => '8',
                        'quantity' => 1,
                        'unit_price' => 1400,
                    ],
                    [
                        'sku_id' => $skuTwo->id,
                        'size' => '7',
                        'quantity' => 2,
                        'unit_price' => 900,
                    ],
                ],
            ])
            ->call('saveWholesaleOrder')
            ->assertHasNoFormErrors();

        $order = WholesaleOrder::query()->firstOrFail();

        $this->assertSame($business->id, $order->business_id);
        $this->assertSame('Dilshan Footwear', $order->customer_name);
        $this->assertSame(3200.0, (float) $order->gross_sale_amount);
        $this->assertSame(3300.0, (float) $order->net_sales_amount);
        $this->assertSame(1700.0, (float) $order->product_cost_amount);
        $this->assertSame(300.0, (float) $order->courier_cost_amount);
        $this->assertSame(1300.0, (float) $order->gross_profit_amount);
        $this->assertSame(2000.0, (float) $order->paid_amount);
        $this->assertSame(1300.0, (float) $order->outstandingAmount());
        $this->assertSame('delivered', $order->status);
        $this->assertSame('partial', $order->payment_status);
        $this->assertSame('WHO-20260815-0001', $order->order_number);
        $this->assertSame('2026-08-29', optional($order->next_follow_up_at)->toDateString());
        $this->assertSame('2026-09-05', optional($order->reorder_due_at)->toDateString());

        $this->assertDatabaseHas('wholesale_orders', [
            'business_id' => $business->id,
            'customer_name' => 'Dilshan Footwear',
            'order_number' => 'WHO-20260815-0001',
        ]);
    }
}

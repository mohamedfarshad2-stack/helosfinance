<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Filament\Pages\SalesInsights;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SalesInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_see_today_and_yesterday_sales_insights(): void
    {
        $business = Business::query()->create([
            'name' => 'Sales Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'sales-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'PS364',
            'name' => 'Slipper PS364',
            'active' => true,
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'today-delivered',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 2500,
            'direct_cost_amount' => 700,
            'occurred_at' => now(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'external_id' => 'today-dispatched',
            'channel' => 'cod',
            'quantity' => 1,
            'payload' => ['sale_amount' => 1800],
            'occurred_at' => now(),
        ]);

        OperationalEvent::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'source' => 'stock_app_sync',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'yesterday-delivered',
            'channel' => 'cod',
            'quantity' => 1,
            'revenue_amount' => 1400,
            'occurred_at' => now()->subDay(),
        ]);

        $this->actingAs($owner)
            ->get(SalesInsights::getUrl())
            ->assertOk()
            ->assertSee('Today delivered sales')
            ->assertSee('LKR 2,500.00')
            ->assertSee('LKR 1,400.00')
            ->assertSee('LKR 1,800.00')
            ->assertSee('Top delivered products today')
            ->assertSee('PS364');
    }
}

<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Filament\Resources\MaterialComponentResource;
use App\Filament\Resources\MaterialLedgerResource;
use App\Filament\Resources\ProductionEntryResource;
use App\Filament\Resources\ProductionWorkStepResource;
use App\Filament\Resources\ServiceBillingResource;
use App\Filament\Resources\SkuRecipeResource;
use App\Filament\Resources\SkuResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessTypeModuleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_business_gets_service_billing_not_manufacturing_modules(): void
    {
        $business = $this->business(Business::TYPE_SERVICE, Business::MATURITY_LEVEL_5, 'COD Returns Lanka');
        $this->actingAs($this->owner($business));

        $this->assertTrue(ServiceBillingResource::canAccess());
        $this->assertTrue(ServiceBillingResource::shouldRegisterNavigation());
        $this->assertFalse(SkuResource::canAccess());
        $this->assertFalse(SkuRecipeResource::canAccess());
        $this->assertFalse(MaterialComponentResource::canAccess());
        $this->assertFalse(MaterialLedgerResource::canAccess());
        $this->assertFalse(ProductionEntryResource::canAccess());
        $this->assertFalse(ProductionWorkStepResource::canAccess());

        $this->get(ServiceBillingResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Service client');
    }

    public function test_trading_business_gets_products_but_not_factory_modules(): void
    {
        $business = $this->business(Business::TYPE_TRADING, Business::MATURITY_LEVEL_5, 'Trading Client');
        $this->actingAs($this->owner($business));

        $this->assertTrue(SkuResource::canAccess());
        $this->assertFalse(ServiceBillingResource::canAccess());
        $this->assertFalse(SkuRecipeResource::canAccess());
        $this->assertFalse(MaterialLedgerResource::canAccess());
        $this->assertFalse(ProductionEntryResource::canAccess());
        $this->assertFalse(ProductionWorkStepResource::canAccess());
    }

    public function test_manufacturing_business_keeps_factory_modules(): void
    {
        $business = $this->business(Business::TYPE_MANUFACTURING, Business::MATURITY_LEVEL_5, 'Factory Client');
        $this->actingAs($this->owner($business));

        $this->assertTrue(SkuResource::canAccess());
        $this->assertTrue(SkuRecipeResource::canAccess());
        $this->assertTrue(MaterialComponentResource::canAccess());
        $this->assertTrue(MaterialLedgerResource::canAccess());
        $this->assertTrue(ProductionEntryResource::canAccess());
        $this->assertTrue(ProductionWorkStepResource::canAccess());
        $this->assertFalse(ServiceBillingResource::canAccess());
    }

    public function test_service_billing_records_track_due_paid_and_balance(): void
    {
        $business = $this->business(Business::TYPE_SERVICE, Business::MATURITY_LEVEL_3, 'COD Returns Lanka');

        $record = ServiceBillingRecord::query()->create([
            'business_id' => $business->id,
            'client_name' => 'Horns England',
            'billing_type' => ServiceBillingRecord::TYPE_SUBSCRIPTION,
            'amount_due' => 15000,
            'paid_amount' => 5000,
            'payment_status' => 'partial',
            'due_on' => today()->toDateString(),
        ]);

        $this->assertSame(10000.0, $record->balanceDue());
    }

    private function business(string $type, string $maturity, string $name): Business
    {
        return Business::query()->create([
            'name' => $name,
            'currency' => 'LKR',
            'business_type' => $type,
            'business_maturity' => $maturity,
            'onboarding_status' => 'ready',
        ]);
    }

    private function owner(Business $business): User
    {
        return User::query()->create([
            'name' => $business->name.' Owner',
            'email' => strtolower(str_replace(' ', '-', $business->name)).'@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);
    }
}

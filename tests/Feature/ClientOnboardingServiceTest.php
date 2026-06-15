<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Services\ClientOnboardingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientOnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_creates_business_login_and_stock_app_connection(): void
    {
        $result = app(ClientOnboardingService::class)->create([
            'business_name' => 'Fresh Client',
            'industry' => 'Trading',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'employee_seat_limit' => 5,
            'user_name' => 'Client Owner',
            'user_email' => 'client@example.com',
            'password' => 'secret123',
            'integration_name' => 'Fresh Client stock-app',
            'integration_base_url' => 'https://stock-app.example.com',
            'integration_status' => 'testing',
            'stock_app_business_key' => 'CLIENT-001',
            'integration_notes' => 'Imported from stock-app',
        ]);

        $this->assertSame('Fresh Client', $result['business']->name);
        $this->assertInstanceOf(Business::class, $result['business']);
        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertInstanceOf(IntegrationSource::class, $result['integration_source']);
        $this->assertSame($result['business']->id, $result['user']->business_id);
        $this->assertNotNull($result['business']->client_group_id);
        $this->assertSame($result['business']->client_group_id, $result['user']->client_group_id);
        $this->assertSame($result['business']->id, $result['integration_source']->business_id);
        $this->assertSame('testing', $result['integration_source']->status);
        $this->assertSame('secret123', $result['password']);
        $this->assertFalse($result['user']->is_platform_admin);
        $this->assertFalse($result['user']->is_employee);
        $this->assertSame(5, $result['business']->employeeSeatLimit());
    }

    public function test_onboarding_can_skip_stock_app_connection_for_new_clients(): void
    {
        $result = app(ClientOnboardingService::class)->create([
            'business_name' => 'New Client Without Stock App',
            'industry' => 'Service',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'user_name' => 'Client Owner',
            'user_email' => 'new-client@example.com',
            'password' => 'secret123',
        ]);

        $this->assertSame('New Client Without Stock App', $result['business']->name);
        $this->assertInstanceOf(Business::class, $result['business']);
        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertNull($result['integration_source']);
        $this->assertSame('secret123', $result['password']);
    }

    public function test_onboarding_generates_stock_app_name_and_business_key_from_business_name(): void
    {
        $result = app(ClientOnboardingService::class)->create([
            'business_name' => 'Horns England Pvt Ltd',
            'industry' => 'Manufacturing wholesale',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_4,
            'employee_seat_limit' => 3,
            'user_name' => 'Horns Owner',
            'user_email' => 'horns-owner@example.com',
            'password' => 'secret123',
            'integration_base_url' => 'https://codreturnslanka.lk',
            'integration_status' => 'testing',
        ]);

        $this->assertInstanceOf(IntegrationSource::class, $result['integration_source']);
        $this->assertSame('Horns England Pvt Ltd stock-app', $result['integration_source']->name);
        $this->assertSame('horns-england-pvt-ltd', $result['integration_source']->settings['stock_app_business_key']);
    }
}

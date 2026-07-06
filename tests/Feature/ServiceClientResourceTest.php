<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ServiceClient;
use App\Filament\Resources\ServiceClientResource\Pages\CreateServiceClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceClientResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_service_client_when_business_field_is_disabled(): void
    {
        $business = Business::query()->create([
            'name' => 'COD Returns Lanka',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Service Owner',
            'email' => 'service-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        Livewire::actingAs($owner)
            ->test(CreateServiceClient::class)
            ->fillForm([
                'business_id' => $business->id,
                'name' => 'Alpha Client',
                'status' => ServiceClient::STATUS_ACTIVE,
                'billing_style' => ServiceClient::BILLING_FIXED_MONTHLY,
                'default_monthly_amount' => 15000,
                'default_registration_fee' => 5000,
                'default_due_day' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('service_clients', [
            'business_id' => $business->id,
            'name' => 'Alpha Client',
        ]);
    }

    public function test_duplicate_service_client_name_is_validated_per_business(): void
    {
        $business = Business::query()->create([
            'name' => 'COD Returns Lanka',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $otherBusiness = Business::query()->create([
            'name' => 'Other Service Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Service Owner',
            'email' => 'service-owner-duplicate@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        ServiceClient::query()->create([
            'business_id' => $business->id,
            'name' => 'Repeat Client',
            'status' => ServiceClient::STATUS_ACTIVE,
            'billing_style' => ServiceClient::BILLING_FIXED_MONTHLY,
            'default_monthly_amount' => 0,
            'default_registration_fee' => 0,
        ]);

        ServiceClient::query()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Repeat Client',
            'status' => ServiceClient::STATUS_ACTIVE,
            'billing_style' => ServiceClient::BILLING_FIXED_MONTHLY,
            'default_monthly_amount' => 0,
            'default_registration_fee' => 0,
        ]);

        Livewire::actingAs($owner)
            ->test(CreateServiceClient::class)
            ->fillForm([
                'business_id' => $business->id,
                'name' => 'Repeat Client',
                'status' => ServiceClient::STATUS_ACTIVE,
                'billing_style' => ServiceClient::BILLING_FIXED_MONTHLY,
                'default_monthly_amount' => 15000,
                'default_registration_fee' => 5000,
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    }
}

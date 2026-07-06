<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ClientGroup;
use App\Filament\Resources\BusinessResource\Pages\CreateBusiness;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_another_business_under_the_same_client_group(): void
    {
        $group = ClientGroup::query()->create([
            'name' => 'Horns Owner Group',
        ]);

        $existingBusiness = Business::query()->create([
            'name' => 'Horns England Pvt Ltd',
            'client_group_id' => $group->id,
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-business@example.com',
            'password' => Hash::make('password'),
            'business_id' => $existingBusiness->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        Livewire::actingAs($owner)
            ->test(CreateBusiness::class)
            ->fillForm([
                'client_group_id' => $group->id,
                'name' => 'COD Returns Lanka',
                'currency' => 'LKR',
                'industry' => 'Service',
                'business_type' => Business::TYPE_SERVICE,
                'business_maturity' => Business::MATURITY_LEVEL_5,
                'onboarding_status' => 'setup',
                'settings' => [
                    'employee_seat_limit' => 5,
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('businesses', [
            'name' => 'COD Returns Lanka',
            'client_group_id' => $group->id,
        ]);
    }
}

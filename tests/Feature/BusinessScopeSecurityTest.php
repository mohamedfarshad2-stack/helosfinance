<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\OperationalEvent;
use App\Filament\Resources\IntegrationSourceResource;
use App\Filament\Resources\OperationalEventResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessScopeSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cannot_see_other_business_integrations_or_operational_events(): void
    {
        $ownedBusiness = Business::query()->create(['name' => 'Owned Business']);
        $otherBusiness = Business::query()->create(['name' => 'Other Business']);
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'scope-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $ownedBusiness->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        IntegrationSource::query()->create([
            'business_id' => $ownedBusiness->id,
            'name' => 'Owned Stock App',
            'type' => 'stock_app',
            'status' => 'active',
        ]);
        IntegrationSource::query()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Secret Stock App',
            'type' => 'stock_app',
            'status' => 'active',
        ]);

        OperationalEvent::query()->create([
            'business_id' => $ownedBusiness->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'OWNED-EVENT-1',
            'occurred_at' => now(),
        ]);
        OperationalEvent::query()->create([
            'business_id' => $otherBusiness->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_DELIVERED,
            'external_id' => 'OTHER-SECRET-EVENT-1',
            'occurred_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(IntegrationSourceResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Owned Stock App')
            ->assertDontSee('Other Secret Stock App');

        $this->actingAs($owner)
            ->get(OperationalEventResource::getUrl('index'))
            ->assertOk()
            ->assertSee('OWNED-EVENT-1')
            ->assertDontSee('OTHER-SECRET-EVENT-1');
    }
}

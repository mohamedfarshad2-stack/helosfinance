<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Filament\Pages\NifrasAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NifrasAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_nifras_email_can_access_the_account_page(): void
    {
        $business = Business::query()->create([
            'name' => 'ShoeHub Wholesale',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $nifras = User::query()->create([
            'name' => 'Nifras',
            'email' => 'nifras@helos.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
        ]);

        $other = User::query()->create([
            'name' => 'Other Staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
        ]);

        $this->actingAs($nifras)
            ->get(NifrasAccount::getUrl())
            ->assertOk()
            ->assertSee('Nifras account')
            ->assertSee('Lead desk')
            ->assertSee('Open production board')
            ->assertSee('Open petty cash')
            ->assertSee('Create wholesale order');

        $this->actingAs($other)
            ->get(NifrasAccount::getUrl())
            ->assertRedirect();
    }
}

<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Filament\Pages\QuickExpenseEntry;
use App\Filament\Resources\BankTransactionResource;
use App\Filament\Resources\BusinessResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_accounts_resource_is_available_to_business_owners_only(): void
    {
        $business = Business::query()->create([
            'name' => 'Client Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $clientUser = User::query()->create([
            'name' => 'Client Manager',
            'email' => 'client@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $this->actingAs($clientUser);
        $this->assertTrue(UserResource::canAccess());
        $this->get(UserResource::getUrl('create'))->assertOk()->assertSee('employee accounts');
        $this->assertTrue($clientUser->isOwner());
    }

    public function test_internal_admin_can_access_employee_accounts_too(): void
    {
        $business = Business::query()->create([
            'name' => 'Platform Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $platformUser = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'platform@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => true,
            'is_employee' => false,
        ]);

        $this->actingAs($platformUser);
        $this->assertTrue(UserResource::canAccess());
        $this->assertFalse($platformUser->isOwner());
        $this->assertTrue($platformUser->isInternalAdmin());
        $this->get(UserResource::getUrl('index'))->assertOk();
    }

    public function test_internal_admin_can_see_client_users_inside_client_account(): void
    {
        $business = Business::query()->create([
            'name' => 'Visible Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $platformUser = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-client-users@example.com',
            'password' => Hash::make('password'),
            'business_id' => null,
            'is_platform_admin' => true,
            'is_employee' => false,
        ]);

        User::query()->create([
            'name' => 'Client Owner',
            'email' => 'visible-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        User::query()->create([
            'name' => 'Client Staff',
            'email' => 'visible-staff@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'operations',
        ]);

        $this->actingAs($platformUser)
            ->get(BusinessResource::getUrl('edit', ['record' => $business]))
            ->assertOk()
            ->assertSee('Client users');

        $this->assertSame(
            ['Client Owner', 'Client Staff'],
            $business->users()->orderBy('name')->pluck('name')->all()
        );
    }

    public function test_business_can_track_employee_seat_limit_and_usage(): void
    {
        $business = Business::query()->create([
            'name' => 'Seat Limited Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
            'settings' => [
                'employee_seat_limit' => 2,
            ],
        ]);

        User::query()->create([
            'name' => 'Staff One',
            'email' => 'staff1@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
        ]);

        $this->assertSame(2, $business->employeeSeatLimit());
        $this->assertSame(1, $business->employeeSeatUsage()['used']);
        $this->assertSame(1, $business->employeeSeatUsage()['remaining']);
        $this->assertTrue($business->employeeSeatAvailable());

        User::query()->create([
            'name' => 'Staff Two',
            'email' => 'staff2@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
        ]);

        $this->assertFalse($business->fresh()->employeeSeatAvailable());
    }

    public function test_employee_access_profile_controls_finance_page_access(): void
    {
        $business = Business::query()->create([
            'name' => 'Access Profile Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
            'settings' => [
                'employee_seat_limit' => 3,
            ],
        ]);

        $workOnly = User::query()->create([
            'name' => 'Work Only Staff',
            'email' => 'work-only@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'work_only',
        ]);

        $finance = User::query()->create([
            'name' => 'Finance Staff',
            'email' => 'finance@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'finance_ops',
        ]);

        $this->actingAs($workOnly);
        $this->assertFalse(QuickExpenseEntry::canAccess());
        $this->assertFalse(BankTransactionResource::canAccess());
        $this->assertSame('work_only', $workOnly->employeeAccessProfileValue());

        $this->actingAs($finance);
        $this->assertTrue(QuickExpenseEntry::canAccess());
        $this->assertTrue(BankTransactionResource::canAccess());
        $this->assertSame('finance_ops', $finance->employeeAccessProfileValue());
    }
}

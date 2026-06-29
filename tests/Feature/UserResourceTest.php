<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ClientGroup;
use App\Filament\Pages\QuickExpenseEntry;
use App\Filament\Resources\BankTransactionResource;
use App\Filament\Resources\BusinessResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
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
        $this->get(UserResource::getUrl('create'))->assertOk()->assertSee('staff accounts');
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

    public function test_internal_admin_can_create_client_owner_access(): void
    {
        $group = ClientGroup::query()->create(['name' => 'Owner Group']);
        $business = Business::query()->create([
            'client_group_id' => $group->id,
            'name' => 'Owner Access Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
            'settings' => [
                'employee_seat_limit' => 1,
            ],
        ]);

        $platformUser = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'owner-access-platform@example.com',
            'password' => Hash::make('password'),
            'is_platform_admin' => true,
            'is_employee' => false,
        ]);

        User::query()->create([
            'name' => 'Existing Staff',
            'email' => 'owner-access-staff@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => true,
        ]);

        $this->actingAs($platformUser);

        $data = $this->mutateCreateUserData([
            'name' => 'Client Owner',
            'email' => 'new-client-owner@example.com',
            'password' => 'password',
            'business_id' => $business->id,
            'account_role' => 'owner',
            'employee_access_profile' => 'full_staff',
        ]);

        $this->assertFalse($data['is_employee']);
        $this->assertFalse($data['is_platform_admin']);
        $this->assertSame($group->id, $data['client_group_id']);
    }

    public function test_client_owner_created_user_is_always_staff(): void
    {
        $group = ClientGroup::query()->create(['name' => 'Staff Group']);
        $business = Business::query()->create([
            'client_group_id' => $group->id,
            'name' => 'Staff Access Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $owner = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'staff-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $this->actingAs($owner);

        $data = $this->mutateCreateUserData([
            'name' => 'Client Staff',
            'email' => 'new-client-staff@example.com',
            'password' => 'password',
            'business_id' => $business->id,
            'account_role' => 'owner',
            'employee_access_profile' => 'finance_ops',
        ]);

        $this->assertTrue($data['is_employee']);
        $this->assertFalse($data['is_platform_admin']);
        $this->assertSame($group->id, $data['client_group_id']);
        $this->assertSame('finance_ops', $data['employee_access_profile']);
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

    public function test_internal_admin_can_remove_client_access_but_not_platform_admins(): void
    {
        $business = Business::query()->create([
            'name' => 'Access Removal Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $platformUser = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'remove-platform@example.com',
            'password' => Hash::make('password'),
            'is_platform_admin' => true,
            'is_employee' => false,
        ]);

        $clientOwner = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'remove-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $clientStaff = User::query()->create([
            'name' => 'Client Staff',
            'email' => 'remove-staff@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
        ]);

        $this->actingAs($platformUser);
        $this->assertTrue(UserResource::canDelete($clientOwner));
        $this->assertTrue(UserResource::canDelete($clientStaff));
        $this->assertFalse(UserResource::canDelete($platformUser));

        $this->actingAs($clientOwner);
        $this->assertFalse(UserResource::canDelete($clientStaff));
    }

    public function test_client_owner_can_see_employees_across_their_client_group_businesses_only(): void
    {
        $group = ClientGroup::query()->create(['name' => 'Horns Group']);
        $otherGroup = ClientGroup::query()->create(['name' => 'Other Group']);

        $horns = Business::query()->create([
            'client_group_id' => $group->id,
            'name' => 'Horns England',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $shoehub = Business::query()->create([
            'client_group_id' => $group->id,
            'name' => 'ShoeHub SL',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'setup',
        ]);

        $otherBusiness = Business::query()->create([
            'client_group_id' => $otherGroup->id,
            'name' => 'Other Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $owner = User::query()->create([
            'name' => 'Group Owner',
            'email' => 'group-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $horns->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        User::query()->create([
            'name' => 'Horns Staff',
            'email' => 'horns-staff@example.com',
            'password' => Hash::make('password'),
            'business_id' => $horns->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => true,
        ]);

        User::query()->create([
            'name' => 'ShoeHub Staff',
            'email' => 'shoehub-staff@example.com',
            'password' => Hash::make('password'),
            'business_id' => $shoehub->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => true,
        ]);

        User::query()->create([
            'name' => 'Other Staff',
            'email' => 'other-staff@example.com',
            'password' => Hash::make('password'),
            'business_id' => $otherBusiness->id,
            'client_group_id' => $otherGroup->id,
            'is_platform_admin' => false,
            'is_employee' => true,
        ]);

        $this->actingAs($owner)
            ->get(BusinessResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Horns England')
            ->assertSee('ShoeHub SL')
            ->assertDontSee('Other Client');

        $this->actingAs($owner)
            ->get(UserResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Horns Staff')
            ->assertSee('ShoeHub Staff')
            ->assertDontSee('Other Staff');
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

    private function mutateCreateUserData(array $data): array
    {
        $method = new ReflectionMethod(CreateUser::class, 'mutateFormDataBeforeCreate');
        $method->setAccessible(true);

        return $method->invoke(new CreateUser(), $data);
    }
}

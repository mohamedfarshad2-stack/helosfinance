<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ClientGroup;
use App\Filament\Pages\QuickExpenseEntry;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\ClientHealthReport;
use App\Filament\Pages\MissingSkuMapping;
use App\Filament\Resources\BankTransactionResource;
use App\Filament\Resources\BusinessResource;
use App\Filament\Resources\CodOrderResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\MaterialLedgerResource;
use App\Filament\Resources\ProductionEntryResource;
use App\Filament\Resources\ServiceBillingResource;
use App\Filament\Resources\ServiceClientResource;
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

    public function test_client_owner_team_access_does_not_manage_owner_accounts(): void
    {
        $group = ClientGroup::query()->create(['name' => 'Protected Owner Group']);
        $business = Business::query()->create([
            'client_group_id' => $group->id,
            'name' => 'Protected Owner Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $owner = User::query()->create([
            'name' => 'Actual Owner Account',
            'email' => 'actual-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $otherOwner = User::query()->create([
            'name' => 'Hidden Partner Owner',
            'email' => 'hidden-partner-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $staff = User::query()->create([
            'name' => 'Visible Staff Account',
            'email' => 'visible-staff-account@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => true,
        ]);

        $this->actingAs($owner)
            ->get(UserResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Visible Staff Account')
            ->assertDontSee('Hidden Partner Owner')
            ->assertDontSee('hidden-partner-owner@example.com');

        $this->actingAs($owner);
        $this->assertFalse(UserResource::canEdit($owner));
        $this->assertFalse(UserResource::canEdit($otherOwner));
        $this->assertTrue(UserResource::canEdit($staff));
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

    public function test_staff_responsibilities_split_bank_and_expense_access(): void
    {
        $business = Business::query()->create([
            'name' => 'Responsibility Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $bankStaff = User::query()->create([
            'name' => 'Bank Exception Staff',
            'email' => 'bank-exception@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'work_only',
            'staff_responsibilities' => ['bank_exceptions'],
        ]);

        $expenseStaff = User::query()->create([
            'name' => 'Expense Staff',
            'email' => 'expense-staff@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'work_only',
            'staff_responsibilities' => ['expense_recording'],
        ]);

        $this->actingAs($bankStaff);
        $this->assertTrue(BankTransactionResource::canAccess());
        $this->assertFalse(ExpenseResource::canAccess());
        $this->assertFalse(QuickExpenseEntry::canAccess());

        $this->actingAs($expenseStaff);
        $this->assertFalse(BankTransactionResource::canAccess());
        $this->assertTrue(ExpenseResource::canAccess());
        $this->assertTrue(QuickExpenseEntry::canAccess());
    }

    public function test_owner_can_assign_responsibilities_and_empty_means_no_staff_work(): void
    {
        $group = ClientGroup::query()->create(['name' => 'Responsibility Owner Group']);
        $business = Business::query()->create([
            'client_group_id' => $group->id,
            'name' => 'Responsibility Owner Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $owner = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'responsibility-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $this->actingAs($owner);

        $assigned = $this->mutateCreateUserData([
            'name' => 'Dispatch Staff',
            'email' => 'dispatch-assigned@example.com',
            'password' => 'password',
            'business_id' => $business->id,
            'employee_access_profile' => 'work_only',
            'staff_responsibilities' => ['dispatch'],
        ]);

        $this->assertTrue($assigned['responsibilities_configured']);
        $this->assertSame(['dispatch'], $assigned['staff_responsibilities']);

        $empty = $this->mutateCreateUserData([
            'name' => 'No Work Staff',
            'email' => 'no-work@example.com',
            'password' => 'password',
            'business_id' => $business->id,
            'employee_access_profile' => 'full_staff',
            'staff_responsibilities' => [],
        ]);

        $this->assertTrue($empty['responsibilities_configured']);
        $this->assertSame([], $empty['staff_responsibilities']);
    }

    public function test_owner_can_use_simple_employee_role_presets(): void
    {
        $group = ClientGroup::query()->create(['name' => 'Simple Role Group']);
        $business = Business::query()->create([
            'client_group_id' => $group->id,
            'name' => 'Simple Role Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $owner = User::query()->create([
            'name' => 'Simple Role Owner',
            'email' => 'simple-role-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $this->actingAs($owner);

        $moneyAdmin = $this->mutateCreateUserData([
            'name' => 'Money Admin',
            'email' => 'money-admin@example.com',
            'password' => 'password',
            'business_id' => $business->id,
            'staff_role_preset' => 'money_admin',
        ]);

        $this->assertSame('finance_ops', $moneyAdmin['employee_access_profile']);
        $this->assertSame(['expense_recording', 'collections', 'bank_exceptions'], $moneyAdmin['staff_responsibilities']);
        $this->assertFalse($moneyAdmin['is_staff_supervisor']);
        $this->assertTrue($moneyAdmin['responsibilities_configured']);

        $supervisor = $this->mutateCreateUserData([
            'name' => 'Supervisor',
            'email' => 'simple-supervisor@example.com',
            'password' => 'password',
            'business_id' => $business->id,
            'staff_role_preset' => 'supervisor',
        ]);

        $this->assertSame('full_staff', $supervisor['employee_access_profile']);
        $this->assertSame(['supervisor_review'], $supervisor['staff_responsibilities']);
        $this->assertTrue($supervisor['is_staff_supervisor']);

        $deliveryFollowUp = $this->mutateCreateUserData([
            'name' => 'Delivery Follow Up',
            'email' => 'delivery-follow-up@example.com',
            'password' => 'password',
            'business_id' => $business->id,
            'staff_role_preset' => 'delivery_follow_up',
        ]);

        $this->assertSame('operations', $deliveryFollowUp['employee_access_profile']);
        $this->assertSame(['delivery_follow_up'], $deliveryFollowUp['staff_responsibilities']);
        $this->assertFalse($deliveryFollowUp['is_staff_supervisor']);

        $orderControl = $this->mutateCreateUserData([
            'name' => 'Arafath',
            'email' => 'arafath-role@example.com',
            'password' => 'password',
            'business_id' => $business->id,
            'staff_role_preset' => 'order_control',
        ]);

        $this->assertSame('operations', $orderControl['employee_access_profile']);
        $this->assertSame(['order_confirmation', 'dispatch', 'delivery_follow_up', 'return_recovery'], $orderControl['staff_responsibilities']);
        $this->assertFalse($orderControl['is_staff_supervisor']);

        $wholesaleManagement = $this->mutateCreateUserData([
            'name' => 'Nifras',
            'email' => 'nifras-role@example.com',
            'password' => 'password',
            'business_id' => $business->id,
            'staff_role_preset' => 'wholesale_management',
        ]);

        $this->assertSame('full_staff', $wholesaleManagement['employee_access_profile']);
        $this->assertSame(['collections', 'supervisor_review', 'order_confirmation', 'dispatch', 'delivery_follow_up'], $wholesaleManagement['staff_responsibilities']);
        $this->assertTrue($wholesaleManagement['is_staff_supervisor']);
    }

    public function test_invalid_responsibility_codes_are_rejected(): void
    {
        $business = Business::query()->create([
            'name' => 'Invalid Responsibility Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        User::query()->create([
            'name' => 'Invalid Staff',
            'email' => 'invalid-responsibility@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'staff_responsibilities' => ['not_real_work'],
        ]);
    }

    public function test_legacy_fallback_only_applies_until_responsibilities_are_configured(): void
    {
        $business = Business::query()->create([
            'name' => 'Legacy Fallback Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $legacyFinance = User::query()->create([
            'name' => 'Legacy Finance',
            'email' => 'legacy-finance@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'finance_ops',
            'responsibilities_configured' => false,
        ]);

        $this->actingAs($legacyFinance);
        $this->assertTrue(BankTransactionResource::canAccess());
        $this->assertTrue(ExpenseResource::canAccess());

        $legacyFinance->update([
            'staff_responsibilities' => [],
            'responsibilities_configured' => true,
        ]);

        $this->actingAs($legacyFinance->fresh());
        $this->assertFalse(BankTransactionResource::canAccess());
        $this->assertFalse(ExpenseResource::canAccess());
    }

    public function test_responsibility_combinations_and_direct_url_access_are_enforced(): void
    {
        $serviceBusiness = Business::query()->create([
            'name' => 'Service Responsibility Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $manufacturingBusiness = Business::query()->create([
            'name' => 'Factory Responsibility Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $dispatchOnly = User::query()->create([
            'name' => 'Dispatch Only',
            'email' => 'dispatch-only@example.com',
            'password' => Hash::make('password'),
            'business_id' => $serviceBusiness->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'staff_responsibilities' => ['dispatch'],
        ]);

        $productionMaterial = User::query()->create([
            'name' => 'Production Material',
            'email' => 'production-material@example.com',
            'password' => Hash::make('password'),
            'business_id' => $manufacturingBusiness->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'staff_responsibilities' => ['production', 'material_stock'],
        ]);

        $collectionsBank = User::query()->create([
            'name' => 'Collections Bank',
            'email' => 'collections-bank@example.com',
            'password' => Hash::make('password'),
            'business_id' => $serviceBusiness->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'staff_responsibilities' => ['collections', 'bank_exceptions'],
        ]);

        $supervisor = User::query()->create([
            'name' => 'Supervisor',
            'email' => 'supervisor-review@example.com',
            'password' => Hash::make('password'),
            'business_id' => $manufacturingBusiness->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'staff_responsibilities' => ['supervisor_review'],
            'is_staff_supervisor' => true,
        ]);

        $this->actingAs($dispatchOnly);
        $this->assertFalse(BankTransactionResource::canAccess());
        $this->assertFalse(ExpenseResource::canAccess());
        $this->assertFalse(ProductionEntryResource::canAccess());
        $this->assertFalse(ServiceBillingResource::canAccess());
        $this->assertFalse(ClientHealthReport::canAccess());

        $this->actingAs($productionMaterial);
        $this->assertTrue(ProductionEntryResource::canAccess());
        $this->assertTrue(MaterialLedgerResource::canAccess());
        $this->assertFalse(ServiceBillingResource::canAccess());
        $this->assertFalse(BankTransactionResource::canAccess());
        $this->assertFalse(ClientHealthReport::canAccess());

        $this->actingAs($collectionsBank);
        $this->assertTrue(BankTransactionResource::canAccess());
        $this->assertTrue(BankStatementImport::canAccess());
        $this->assertTrue(ServiceClientResource::canAccess());
        $this->assertTrue(ServiceBillingResource::canAccess());
        $this->assertFalse(ProductionEntryResource::canAccess());
        $this->assertFalse(ClientHealthReport::canAccess());

        $this->actingAs($supervisor);
        $this->assertTrue($supervisor->canAccessSupervisorReview());
        $this->assertFalse(ClientHealthReport::canAccess());
        $this->assertFalse(BankTransactionResource::canAccess());
        $this->assertFalse(ServiceBillingResource::canAccess());
    }

    public function test_sandhamali_no_longer_receives_production_access(): void
    {
        $business = Business::query()->create([
            'name' => 'Sandhamali Returns Business',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $sandhamali = User::query()->create([
            'name' => 'Sandhamali',
            'email' => 'sandhamali@helos.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'work_only',
            'staff_responsibilities' => ['order_confirmation', 'delivery_follow_up', 'return_recovery'],
            'responsibilities_configured' => true,
            'is_staff_supervisor' => false,
        ]);

        $this->actingAs($sandhamali);

        $this->assertFalse($sandhamali->hasStaffResponsibility('production', $business->id));
        $this->assertFalse($sandhamali->canAccessProductionWork($business->id));
        $this->assertFalse($sandhamali->canAccessMaterialWork($business->id));
        $this->assertFalse(ProductionEntryResource::canAccess());
        $this->assertFalse(MaterialLedgerResource::canAccess());
    }

    private function mutateCreateUserData(array $data): array
    {
        $method = new ReflectionMethod(CreateUser::class, 'mutateFormDataBeforeCreate');
        $method->setAccessible(true);

        return $method->invoke(new CreateUser(), $data);
    }
}

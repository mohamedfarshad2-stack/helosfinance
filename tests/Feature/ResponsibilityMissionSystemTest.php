<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\Mission;
use App\Domains\Shared\Models\StaffResponsibilityAssignment;
use App\Domains\Shared\Models\StaffResponsibilityAudit;
use App\Domains\Shared\Services\MissionGeneratorService;
use App\Filament\Pages\ClientHealthReport;
use App\Filament\Pages\TodaysWork;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\MissionResource;
use App\Filament\Resources\StaffResponsibilityAssignmentResource;
use App\Filament\Resources\StaffResponsibilityAuditResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResponsibilityMissionSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_scoped_responsibility_limits_resource_query_scope(): void
    {
        [$owner, $horns, $codReturns] = $this->ownerWithBusinesses();

        $staff = $this->staff($horns, [
            'staff_responsibilities' => [],
            'responsibilities_configured' => true,
        ]);

        $this->actingAs($owner);

        StaffResponsibilityAssignment::query()->create([
            'user_id' => $staff->id,
            'business_id' => $horns->id,
            'responsibility_code' => 'expense_recording',
            'can_view' => true,
            'can_create' => true,
            'can_edit' => true,
            'can_complete' => true,
            'is_active' => true,
        ]);

        Expense::query()->create([
            'business_id' => $horns->id,
            'category' => 'Horns supplier',
            'expense_type' => 'variable',
            'amount' => 1000,
            'payment_status' => 'partial',
            'paid_amount' => 0,
            'spent_on' => today(),
        ]);

        Expense::query()->create([
            'business_id' => $codReturns->id,
            'category' => 'COD service supplier',
            'expense_type' => 'variable',
            'amount' => 2000,
            'payment_status' => 'partial',
            'paid_amount' => 0,
            'spent_on' => today(),
        ]);

        $staff = $staff->fresh();

        $this->assertSame([$horns->id], $staff->accessibleBusinessIdsForResponsibility('expense_recording'));

        $this->actingAs($staff)
            ->get(ExpenseResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Horns supplier')
            ->assertDontSee('COD service supplier');
    }

    public function test_temporary_responsibility_expires_and_removes_access(): void
    {
        [$owner, $horns] = $this->ownerWithBusinesses();
        $staff = $this->staff($horns, [
            'staff_responsibilities' => [],
            'responsibilities_configured' => true,
        ]);

        $this->actingAs($owner);

        StaffResponsibilityAssignment::query()->create([
            'user_id' => $staff->id,
            'business_id' => $horns->id,
            'responsibility_code' => 'bank_exceptions',
            'can_view' => true,
            'can_edit' => true,
            'can_complete' => true,
            'expires_at' => now()->subMinute(),
            'is_active' => true,
        ]);

        $this->assertFalse($staff->fresh()->canAccessBankExceptionWork($horns->id));
        $this->assertSame([], $staff->fresh()->accessibleBusinessIdsForResponsibility('bank_exceptions'));
    }

    public function test_responsibility_assignment_changes_are_audited(): void
    {
        [$owner, $horns] = $this->ownerWithBusinesses();
        $staff = $this->staff($horns);

        $this->actingAs($owner);

        $assignment = StaffResponsibilityAssignment::query()->create([
            'user_id' => $staff->id,
            'business_id' => $horns->id,
            'responsibility_code' => 'dispatch',
            'can_view' => true,
            'can_complete' => true,
            'is_active' => true,
            'assignment_note' => 'Dispatch team member',
        ]);

        $assignment->deactivate($owner, 'Temporary help finished');

        $this->assertDatabaseHas('staff_responsibility_audits', [
            'user_id' => $staff->id,
            'business_id' => $horns->id,
            'responsibility_code' => 'dispatch',
            'event_type' => 'assigned',
        ]);

        $this->assertDatabaseHas('staff_responsibility_audits', [
            'user_id' => $staff->id,
            'business_id' => $horns->id,
            'responsibility_code' => 'dispatch',
            'event_type' => 'removed',
        ]);

        $this->actingAs($owner)->get(StaffResponsibilityAuditResource::getUrl('index'))->assertOk();
    }

    public function test_missions_are_generated_from_real_conditions_and_respect_scope(): void
    {
        [$owner, $horns, $codReturns] = $this->ownerWithBusinesses();

        $expenseStaff = $this->staff($horns, [
            'staff_responsibilities' => [],
            'responsibilities_configured' => true,
        ]);
        $collectionsStaff = $this->staff($codReturns, [
            'email' => 'collections@example.com',
            'staff_responsibilities' => [],
            'responsibilities_configured' => true,
        ]);

        $this->actingAs($owner);

        StaffResponsibilityAssignment::query()->create([
            'user_id' => $expenseStaff->id,
            'business_id' => $horns->id,
            'responsibility_code' => 'expense_recording',
            'can_view' => true,
            'can_edit' => true,
            'can_complete' => true,
            'is_active' => true,
        ]);

        StaffResponsibilityAssignment::query()->create([
            'user_id' => $collectionsStaff->id,
            'business_id' => $codReturns->id,
            'responsibility_code' => 'collections',
            'can_view' => true,
            'can_complete' => true,
            'is_active' => true,
        ]);

        Expense::query()->create([
            'business_id' => $horns->id,
            'category' => 'Open supplier bill',
            'expense_type' => 'variable',
            'amount' => 5000,
            'payment_status' => 'partial',
            'paid_amount' => 1000,
            'due_on' => today()->subDay(),
            'spent_on' => today()->subDays(2),
        ]);

        $missions = app(MissionGeneratorService::class)->visibleForUser($expenseStaff->fresh());

        $this->assertCount(1, $missions);
        $this->assertSame('expense_recording', $missions->first()->responsibility_code);
        $this->assertSame($horns->id, $missions->first()->business_id);
        $this->assertTrue(app(MissionGeneratorService::class)->visibleForUser($collectionsStaff->fresh())->isEmpty());

        $mission = $missions->first();
        $mission->complete($expenseStaff);

        $this->assertDatabaseHas('mission_events', [
            'mission_id' => $mission->id,
            'user_id' => $expenseStaff->id,
            'event_type' => 'completed',
        ]);
    }

    public function test_supervisor_can_review_missions_without_owner_finance_access(): void
    {
        [$owner, $horns] = $this->ownerWithBusinesses();

        $supervisor = $this->staff($horns, [
            'email' => 'supervisor@example.com',
            'staff_responsibilities' => [],
            'responsibilities_configured' => true,
            'is_staff_supervisor' => true,
        ]);

        $this->actingAs($owner);

        StaffResponsibilityAssignment::query()->create([
            'user_id' => $supervisor->id,
            'business_id' => $horns->id,
            'responsibility_code' => 'supervisor_review',
            'can_view' => true,
            'can_review' => true,
            'team_records_allowed' => true,
            'is_active' => true,
        ]);

        $this->actingAs($supervisor->fresh());
        $this->assertTrue(MissionResource::canAccess());
        $this->assertFalse(ClientHealthReport::canAccess());

        $this->get(MissionResource::getUrl('index'))->assertOk();
    }

    public function test_owner_can_access_responsibility_manager_but_staff_cannot(): void
    {
        [$owner, $horns] = $this->ownerWithBusinesses();
        $staff = $this->staff($horns);

        $this->actingAs($owner);
        $this->assertTrue(StaffResponsibilityAssignmentResource::canAccess());
        $this->get(StaffResponsibilityAssignmentResource::getUrl('index'))->assertOk();

        $this->actingAs($staff);
        $this->assertFalse(StaffResponsibilityAssignmentResource::canAccess());
        $this->get(StaffResponsibilityAssignmentResource::getUrl('index'))->assertStatus(302);
    }

    private function ownerWithBusinesses(): array
    {
        $horns = Business::query()->create([
            'name' => 'Horns England',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $codReturns = Business::query()->create([
            'name' => 'COD Returns Lanka',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $horns->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        return [$owner, $horns, $codReturns];
    }

    private function staff(Business $business, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Staff',
            'email' => 'staff-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'work_only',
        ], $overrides));
    }
}

<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ClientGroup;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\Mission;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\StaffResponsibilityAssignment;
use App\Domains\Shared\Services\MissionGeneratorService;
use App\Domains\Shared\Services\MissionSourceActionService;
use App\Filament\Pages\ClientHealthReport;
use App\Filament\Pages\LegacyAccessMigrationStatus;
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

    public function test_staff_today_work_does_not_show_missions_assigned_to_another_employee(): void
    {
        [$owner, $horns] = $this->ownerWithBusinesses();

        $arafath = $this->staff($horns, [
            'name' => 'Arafath',
            'email' => 'arafath-mission@example.com',
            'staff_responsibilities' => ['expense_recording'],
            'responsibilities_configured' => true,
        ]);

        $sandamali = $this->staff($horns, [
            'name' => 'Sandamali',
            'email' => 'sandamali-mission@example.com',
            'staff_responsibilities' => ['expense_recording'],
            'responsibilities_configured' => true,
        ]);

        StaffResponsibilityAssignment::query()->create([
            'user_id' => $arafath->id,
            'business_id' => $horns->id,
            'responsibility_code' => 'expense_recording',
            'can_view' => true,
            'can_complete' => true,
            'is_active' => true,
        ]);

        StaffResponsibilityAssignment::query()->create([
            'user_id' => $sandamali->id,
            'business_id' => $horns->id,
            'responsibility_code' => 'expense_recording',
            'can_view' => true,
            'can_complete' => true,
            'is_active' => true,
        ]);

        Expense::query()->create([
            'business_id' => $horns->id,
            'category' => 'Supplier bill for Arafath',
            'expense_type' => 'variable',
            'amount' => 5000,
            'payment_status' => 'partial',
            'paid_amount' => 1000,
            'due_on' => today()->subDay(),
            'spent_on' => today()->subDays(2),
        ]);

        $arafathMissions = app(MissionGeneratorService::class)->visibleForUser($arafath->fresh());
        $sandamaliMissions = app(MissionGeneratorService::class)->visibleForUser($sandamali->fresh());

        $this->assertCount(1, $arafathMissions);
        $this->assertSame($arafath->id, $arafathMissions->first()->assigned_user_id);
        $this->assertTrue($sandamaliMissions->isEmpty());
        $this->assertFalse(app(MissionGeneratorService::class)->canUserAccessMission($sandamali->fresh(), $arafathMissions->first()));
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

    public function test_responsibility_staff_selector_finds_client_group_staff_without_business_id(): void
    {
        $group = ClientGroup::query()->create(['name' => 'Owner Group']);
        $horns = Business::query()->create([
            'client_group_id' => $group->id,
            'name' => 'Horns England',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-selector@example.com',
            'password' => Hash::make('password'),
            'business_id' => $horns->id,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);
        $staff = User::query()->create([
            'name' => 'Arafath Dispatch',
            'email' => 'arafath@example.com',
            'password' => Hash::make('password'),
            'business_id' => null,
            'client_group_id' => $group->id,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'operations',
        ]);

        $this->actingAs($owner);

        $method = new \ReflectionMethod(StaffResponsibilityAssignmentResource::class, 'staffOptions');
        $method->setAccessible(true);
        $options = $method->invoke(null, 'Arafath');

        $this->assertArrayHasKey($staff->id, $options);
        $this->assertSame('Arafath Dispatch', $options[$staff->id]);
    }

    public function test_collection_mission_action_updates_source_and_completes(): void
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
            'responsibility_code' => 'collections',
            'can_view' => true,
            'can_edit' => true,
            'can_complete' => true,
            'is_active' => true,
        ]);

        $billing = ServiceBillingRecord::query()->create([
            'business_id' => $horns->id,
            'client_name' => 'Service Client',
            'billing_type' => ServiceBillingRecord::TYPE_SUBSCRIPTION,
            'amount_due' => 10000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'due_on' => today()->subDay(),
        ]);

        $mission = Mission::query()->create([
            'source_key' => $horns->id.':service-billing-'.$billing->id,
            'mission_type' => 'service_collection',
            'responsibility_code' => 'collections',
            'business_id' => $horns->id,
            'source_type' => 'service_billing_record',
            'source_id' => (string) $billing->id,
            'title' => 'Collect service money',
            'summary' => 'Service money is due.',
            'status' => Mission::STATUS_OPEN,
        ]);

        $this->actingAs($staff->fresh());

        app(MissionSourceActionService::class)->apply($mission, $staff->fresh(), [
            'paid_amount' => 10000,
            'payment_method' => 'bank',
            'reference' => 'TEST-REF',
        ]);

        $this->assertSame('paid', $billing->fresh()->payment_status);
        $this->assertSame(Mission::STATUS_COMPLETED, $mission->fresh()->status);
        $this->assertDatabaseHas('mission_events', [
            'mission_id' => $mission->id,
            'event_type' => 'source_action',
        ]);
    }

    public function test_staff_bank_action_cannot_approve_owner_only_money_type(): void
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
            'is_active' => true,
        ]);

        $transaction = BankTransaction::query()->create([
            'business_id' => $horns->id,
            'transaction_date' => today(),
            'description' => 'Owner cash movement',
            'debit' => 10000,
            'credit' => 0,
            'classification' => 'unknown',
            'transaction_type' => 'other',
            'status' => 'review',
        ]);

        $mission = Mission::query()->create([
            'source_key' => $horns->id.':bank-review-'.$transaction->id,
            'mission_type' => 'bank_review',
            'responsibility_code' => 'bank_exceptions',
            'business_id' => $horns->id,
            'source_type' => 'bank_transaction',
            'source_id' => (string) $transaction->id,
            'title' => 'Review bank row',
            'summary' => 'Bank row needs review.',
            'status' => Mission::STATUS_OPEN,
        ]);

        $this->actingAs($staff->fresh());

        app(MissionSourceActionService::class)->apply($mission, $staff->fresh(), [
            'classification' => 'owner_withdrawal',
            'transaction_type' => 'owner_withdrawal',
            'allocated_business_id' => $horns->id,
        ]);

        $this->assertSame('review', $transaction->fresh()->status);
        $this->assertSame(Mission::STATUS_WAITING_REVIEW, $mission->fresh()->status);
    }

    public function test_unresolved_mission_cannot_be_completed_without_source_fix(): void
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
            'responsibility_code' => 'expense_recording',
            'can_view' => true,
            'can_edit' => true,
            'can_complete' => true,
            'is_active' => true,
        ]);

        $expense = Expense::query()->create([
            'business_id' => $horns->id,
            'category' => 'Supplier bill',
            'expense_type' => 'variable',
            'amount' => 5000,
            'payment_status' => 'partial',
            'paid_amount' => 0,
            'spent_on' => today(),
        ]);

        $mission = Mission::query()->create([
            'source_key' => $horns->id.':expense-settlement-'.$expense->id,
            'mission_type' => 'expense_settlement',
            'responsibility_code' => 'expense_recording',
            'business_id' => $horns->id,
            'source_type' => 'expense',
            'source_id' => (string) $expense->id,
            'title' => 'Settle supplier bill',
            'summary' => 'Expense needs settlement.',
            'status' => Mission::STATUS_OPEN,
        ]);

        $this->actingAs($staff->fresh());

        $this->assertFalse(app(MissionSourceActionService::class)->completeIfResolved($mission, $staff->fresh()));
        $this->assertSame(Mission::STATUS_OPEN, $mission->fresh()->status);
    }

    public function test_legacy_migration_status_identifies_fallback_and_explicit_no_access(): void
    {
        [$owner, $horns] = $this->ownerWithBusinesses();
        $fallbackStaff = $this->staff($horns, [
            'employee_access_profile' => 'operations',
            'responsibilities_configured' => false,
        ]);
        $noAccessStaff = $this->staff($horns, [
            'email' => 'no-access@example.com',
            'staff_responsibilities' => [],
            'responsibilities_configured' => true,
        ]);

        $this->actingAs($owner);

        $this->assertTrue(LegacyAccessMigrationStatus::canAccess());
        $this->get(LegacyAccessMigrationStatus::getUrl())->assertOk()->assertSee('Legacy Migration Status');

        $page = new LegacyAccessMigrationStatus;
        $page->mount();

        $fallbackRow = collect($page->rows)->firstWhere('id', $fallbackStaff->id);
        $noAccessRow = collect($page->rows)->firstWhere('id', $noAccessStaff->id);

        $this->assertTrue($fallbackRow['uses_fallback']);
        $this->assertTrue($noAccessRow['no_access']);
    }

    public function test_trusted_higher_impact_mission_ranks_first(): void
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
            'responsibility_code' => 'expense_recording',
            'can_view' => true,
            'can_edit' => true,
            'can_complete' => true,
            'is_active' => true,
        ]);

        Expense::query()->create([
            'business_id' => $horns->id,
            'category' => 'Small supplier bill',
            'expense_type' => 'variable',
            'amount' => 1000,
            'payment_status' => 'partial',
            'paid_amount' => 0,
            'due_on' => today(),
            'spent_on' => today(),
        ]);

        Expense::query()->create([
            'business_id' => $horns->id,
            'category' => 'Large supplier bill',
            'expense_type' => 'variable',
            'amount' => 125000,
            'payment_status' => 'partial',
            'paid_amount' => 0,
            'due_on' => today(),
            'spent_on' => today(),
        ]);

        $missions = app(MissionGeneratorService::class)->visibleForUser($staff->fresh());

        $this->assertSame('critical', $missions->first()->priority);
        $this->assertSame(125000.0, $missions->first()->estimated_impact);
    }

    public function test_company_hierarchy_limits_supervisors_to_direct_report_missions(): void
    {
        [$owner, $horns] = $this->ownerWithBusinesses();
        [$nifras, $sandhamali, $arafath, $csr] = $this->companyHierarchy($owner, $horns);

        $nifrasMission = $this->mission($horns, $nifras, 'Manager daily review');
        $sandhamaliMission = $this->mission($horns, $sandhamali, 'CSR team leader review');
        $arafathMission = $this->mission($horns, $arafath, 'Online store dispatch');
        $csrMission = $this->mission($horns, $csr, 'CSR no-answer follow-up');

        $this->assertTrue($csrMission->canBeReviewedBy($sandhamali));
        $this->assertFalse($arafathMission->canBeReviewedBy($sandhamali));
        $this->assertTrue($sandhamaliMission->canBeReviewedBy($nifras));
        $this->assertTrue($arafathMission->canBeReviewedBy($nifras));
        $this->assertFalse($csrMission->canBeReviewedBy($nifras));
        $this->assertTrue($nifrasMission->canBeReviewedBy($owner));
        $this->assertFalse($csrMission->canBeReviewedBy($owner));

        $sandhamaliTitles = $this->reviewMissionTitlesFor($sandhamali);
        $this->assertContains('CSR no-answer follow-up', $sandhamaliTitles);
        $this->assertNotContains('Online store dispatch', $sandhamaliTitles);

        $nifrasTitles = $this->reviewMissionTitlesFor($nifras);
        $this->assertContains('CSR team leader review', $nifrasTitles);
        $this->assertContains('Online store dispatch', $nifrasTitles);
        $this->assertNotContains('CSR no-answer follow-up', $nifrasTitles);

        $ownerTitles = $this->reviewMissionTitlesFor($owner);
        $this->assertContains('Manager daily review', $ownerTitles);
        $this->assertNotContains('CSR no-answer follow-up', $ownerTitles);
    }

    public function test_mission_escalation_follows_employee_team_leader_manager_owner_chain(): void
    {
        [$owner, $horns] = $this->ownerWithBusinesses();
        [$nifras, $sandhamali, , $csr] = $this->companyHierarchy($owner, $horns);
        $mission = $this->mission($horns, $csr, 'Recover a return');

        $mission->escalate($csr, 'CSR needs team leader help.');
        $this->assertSame($sandhamali->id, $mission->fresh()->escalated_to_user_id);
        $this->assertSame('supervisor', $mission->fresh()->escalation_level);

        $mission->escalate($sandhamali, 'Team leader needs manager help.');
        $this->assertSame($nifras->id, $mission->fresh()->escalated_to_user_id);
        $this->assertSame('manager', $mission->fresh()->escalation_level);

        $mission->escalate($nifras, 'Manager needs owner decision.');
        $this->assertSame($owner->id, $mission->fresh()->escalated_to_user_id);
        $this->assertSame('owner', $mission->fresh()->escalation_level);
        $this->assertTrue($mission->fresh()->canBeReviewedBy($owner));
    }

    public function test_hierarchy_prevents_self_approval_and_keeps_owner_decisions_protected(): void
    {
        [$owner, $horns] = $this->ownerWithBusinesses();
        [$nifras, $sandhamali, , $csr] = $this->companyHierarchy($owner, $horns);
        $mission = $this->mission($horns, $csr, 'Correct customer outcome');

        $this->assertFalse($mission->canBeApprovedBy($csr));
        $this->assertTrue($mission->canBeApprovedBy($sandhamali));

        $mission->submitForOwnerReview($csr, 'Protected bank decision.');

        $this->assertFalse($mission->fresh()->canBeApprovedBy($sandhamali));
        $this->assertFalse($mission->fresh()->canBeApprovedBy($nifras));
        $this->assertTrue($mission->fresh()->canBeApprovedBy($owner));
        $this->assertSame($owner->id, $mission->fresh()->escalated_to_user_id);
    }

    public function test_csr_event_mission_stays_with_named_employee_and_keeps_manual_lifecycle(): void
    {
        [$owner, $horns] = $this->ownerWithBusinesses();
        $shamindi = $this->staff($horns, [
            'name' => 'Shamindi',
            'email' => 'shamindi-routing@example.com',
            'responsibilities_configured' => true,
            'staff_responsibilities' => ['return_recovery'],
        ]);
        $pramila = $this->staff($horns, [
            'name' => 'Pramila',
            'email' => 'pramila-routing@example.com',
            'responsibilities_configured' => true,
            'staff_responsibilities' => ['return_recovery'],
        ]);

        foreach ([$shamindi, $pramila] as $staff) {
            StaffResponsibilityAssignment::query()->create([
                'user_id' => $staff->id,
                'business_id' => $horns->id,
                'responsibility_code' => 'return_recovery',
                'can_view' => true,
                'can_complete' => true,
                'is_active' => true,
            ]);
        }

        OperationalEvent::query()->create([
            'business_id' => $horns->id,
            'source' => 'stock_app',
            'event_type' => OperationalEvent::ORDER_RETURNED,
            'external_id' => 'RETURN-SHAMINDI-1',
            'quantity' => 1,
            'payload' => ['csr_employee' => 'Shamindi', 'return_reason' => 'No answer'],
            'occurred_at' => now(),
        ]);

        $missions = app(MissionGeneratorService::class)->syncForBusiness($horns);
        $mission = $missions->firstWhere('responsibility_code', 'return_recovery');

        $this->assertNotNull($mission);
        $this->assertSame($shamindi->id, $mission->assigned_user_id);

        $mission->block($shamindi, 'Waiting for customer response.');
        app(MissionGeneratorService::class)->syncForBusiness($horns);

        $this->assertSame(Mission::STATUS_BLOCKED, $mission->fresh()->status);
        $this->assertSame($shamindi->id, $mission->fresh()->assigned_user_id);
    }

    private function companyHierarchy(User $owner, Business $business): array
    {
        $nifras = $this->staff($business, [
            'name' => 'Nifras',
            'email' => 'nifras-hierarchy@example.com',
            'supervisor_user_id' => $owner->id,
            'responsibilities_configured' => true,
            'staff_responsibilities' => ['supervisor_review'],
            'is_staff_supervisor' => true,
        ]);
        $sandhamali = $this->staff($business, [
            'name' => 'Sandhamali',
            'email' => 'sandhamali-hierarchy@example.com',
            'supervisor_user_id' => $nifras->id,
            'responsibilities_configured' => true,
            'staff_responsibilities' => ['supervisor_review'],
            'is_staff_supervisor' => true,
        ]);
        $arafath = $this->staff($business, [
            'name' => 'Arafath',
            'email' => 'arafath-hierarchy@example.com',
            'supervisor_user_id' => $nifras->id,
            'responsibilities_configured' => true,
            'staff_responsibilities' => ['dispatch', 'product_repair'],
        ]);
        $csr = $this->staff($business, [
            'name' => 'CSR Employee',
            'email' => 'csr-hierarchy@example.com',
            'supervisor_user_id' => $sandhamali->id,
            'responsibilities_configured' => true,
            'staff_responsibilities' => ['order_confirmation', 'return_recovery'],
        ]);

        foreach ([$nifras, $sandhamali] as $supervisor) {
            StaffResponsibilityAssignment::query()->create([
                'user_id' => $supervisor->id,
                'business_id' => $business->id,
                'responsibility_code' => 'supervisor_review',
                'can_view' => true,
                'can_review' => true,
                'team_records_allowed' => true,
                'is_active' => true,
            ]);
        }

        return [$nifras->fresh(), $sandhamali->fresh(), $arafath->fresh(), $csr->fresh()];
    }

    private function mission(Business $business, User $assignee, string $title): Mission
    {
        return Mission::query()->create([
            'source_key' => $business->id.':hierarchy-'.md5($title.'-'.$assignee->id),
            'mission_type' => 'hierarchy_test',
            'responsibility_code' => 'order_confirmation',
            'business_id' => $business->id,
            'title' => $title,
            'summary' => 'Protect revenue by completing operational work on time.',
            'assigned_user_id' => $assignee->id,
            'status' => Mission::STATUS_OPEN,
            'impact_type' => 'revenue_protected',
            'confidence' => 'incomplete',
            'due_at' => now()->subHour(),
        ]);
    }

    private function reviewMissionTitlesFor(User $reviewer): array
    {
        $this->actingAs($reviewer);

        $method = new \ReflectionMethod(MissionResource::class, 'scopeToAllowedMissions');
        $method->setAccessible(true);

        return $method->invoke(null, Mission::query())->pluck('title')->all();
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

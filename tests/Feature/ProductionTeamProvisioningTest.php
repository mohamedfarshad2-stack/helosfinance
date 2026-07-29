<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\StaffResponsibilityAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionTeamProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_horns_owner_is_reconnected_and_approved_team_hierarchy_is_provisioned(): void
    {
        $emptyBusiness = Business::query()->create(['name' => 'Horns England Pvt Ltd']);
        $canonicalBusiness = Business::query()->create(['name' => 'Horns England']);
        IntegrationSource::query()->create([
            'business_id' => $canonicalBusiness->id,
            'name' => 'Horns England',
            'type' => 'stock_app',
            'status' => 'active',
            'last_successful_sync_at' => now(),
        ]);
        $owner = User::query()->create([
            'name' => 'Horns Owner',
            'email' => 'Horns@admin.com',
            'password' => Hash::make('password'),
            'business_id' => $emptyBusiness->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $migration = require database_path('migrations/2026_07_25_000002_reconnect_horns_owner_and_provision_team.php');
        $migration->up();

        $this->assertSame($canonicalBusiness->id, $owner->fresh()->business_id);
        $this->assertDatabaseHas('employees', [
            'business_id' => $canonicalBusiness->id,
            'name' => 'sandamali',
            'role' => 'CSR Team Leader',
        ]);
        $this->assertDatabaseHas('employees', [
            'business_id' => $canonicalBusiness->id,
            'name' => 'Arafath',
            'role' => 'Online Store Handler',
        ]);

        $nifras = User::query()->where('name', 'Nifras')->firstOrFail();
        $sandamali = User::query()->where('name', 'sandamali')->firstOrFail();
        $arafath = User::query()->where('name', 'Arafath')->firstOrFail();
        $shamindi = User::query()->where('name', 'shamindi')->firstOrFail();

        $this->assertSame($owner->id, $nifras->supervisor_user_id);
        $this->assertSame($nifras->id, $sandamali->supervisor_user_id);
        $this->assertSame($nifras->id, $arafath->supervisor_user_id);
        $this->assertSame($sandamali->id, $shamindi->supervisor_user_id);
        $this->assertTrue($nifras->is_staff_supervisor);
        $this->assertTrue($sandamali->is_staff_supervisor);
        $this->assertTrue(StaffResponsibilityAssignment::query()
            ->where('user_id', $sandamali->id)
            ->where('responsibility_code', 'supervisor_review')
            ->where('can_review', true)
            ->exists());

        $loginMigration = require database_path('migrations/2026_07_25_000003_activate_horns_leadership_logins.php');
        $loginMigration->up();
        $cleanupMigration = require database_path('migrations/2026_07_25_000004_archive_duplicate_horns_leadership_access.php');
        $cleanupMigration->up();

        $nifras->refresh();
        $arafath->refresh();
        $sandamali->refresh();

        $this->assertSame('nifras@helos.com', $nifras->email);
        $this->assertSame('arafath@helos.com', $arafath->email);
        $this->assertSame('sandhamali@helos.com', $sandamali->email);
        $this->assertTrue(Hash::check('nifras@789', $nifras->password));
        $this->assertTrue(Hash::check('arafath@789', $arafath->password));
        $this->assertTrue(Hash::check('sandhamali@789', $sandamali->password));
        $this->assertSame($nifras->id, $arafath->supervisor_user_id);
        $this->assertSame($nifras->id, $sandamali->supervisor_user_id);
        $this->assertEqualsCanonicalizing(
            ['supervisor_review', 'order_confirmation', 'delivery_follow_up', 'return_recovery'],
            StaffResponsibilityAssignment::query()
                ->where('user_id', $sandamali->id)
                ->where('is_active', true)
                ->pluck('responsibility_code')
                ->all(),
        );
    }
}

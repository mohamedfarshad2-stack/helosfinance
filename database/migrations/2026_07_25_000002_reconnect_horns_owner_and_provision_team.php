<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $canonicalBusiness = DB::table('businesses')
            ->join('integration_sources', 'integration_sources.business_id', '=', 'businesses.id')
            ->where('integration_sources.type', 'stock_app')
            ->where('integration_sources.status', 'active')
            ->whereRaw("LOWER(businesses.name) LIKE '%horns%england%'")
            ->orderByDesc('integration_sources.last_successful_sync_at')
            ->orderByDesc('integration_sources.last_webhook_received_at')
            ->orderByDesc('integration_sources.id')
            ->first(['businesses.id', 'businesses.client_group_id']);

        if (! $canonicalBusiness) {
            return;
        }

        $owner = DB::table('users')
            ->whereRaw('LOWER(email) = ?', ['horns@admin.com'])
            ->first(['id', 'client_group_id']);

        if (! $owner) {
            return;
        }

        $clientGroupId = $canonicalBusiness->client_group_id ?: $owner->client_group_id;

        if ($clientGroupId && ! $canonicalBusiness->client_group_id) {
            DB::table('businesses')->where('id', $canonicalBusiness->id)->update([
                'client_group_id' => $clientGroupId,
                'updated_at' => now(),
            ]);
        }

        DB::table('users')->where('id', $owner->id)->update([
            'business_id' => $canonicalBusiness->id,
            'client_group_id' => $clientGroupId,
            'updated_at' => now(),
        ]);

        $team = [
            [
                'name' => 'Nifras',
                'email' => 'nifras.manager@helos.invalid',
                'role' => 'Manager',
                'responsibilities' => ['supervisor_review', 'production', 'expense_recording'],
                'supervisor' => 'owner',
                'supervisor_access' => true,
            ],
            [
                'name' => 'sandamali',
                'email' => 'nisansalasandamalinew1@gmail.com',
                'role' => 'CSR Team Leader',
                'responsibilities' => ['supervisor_review', 'order_confirmation', 'return_recovery'],
                'supervisor' => 'Nifras',
                'supervisor_access' => true,
            ],
            [
                'name' => 'Arafath',
                'email' => 'arafath.store@helos.invalid',
                'role' => 'Online Store Handler',
                'responsibilities' => ['dispatch', 'product_repair', 'material_stock'],
                'supervisor' => 'Nifras',
                'supervisor_access' => false,
            ],
            ['name' => 'shamindi', 'email' => 'shamindigovipothage2021@gmail.com', 'role' => 'CSR', 'responsibilities' => ['order_confirmation', 'return_recovery'], 'supervisor' => 'sandamali', 'supervisor_access' => false],
            ['name' => 'M. Pramila dilrukshi', 'email' => 'parthiprami20@gmail.com', 'role' => 'CSR', 'responsibilities' => ['order_confirmation', 'return_recovery'], 'supervisor' => 'sandamali', 'supervisor_access' => false],
            ['name' => 'Rasheeda', 'email' => 'raseedasaheed5@gmail.com', 'role' => 'CSR', 'responsibilities' => ['order_confirmation', 'return_recovery'], 'supervisor' => 'sandamali', 'supervisor_access' => false],
            ['name' => 'Sumaiya', 'email' => 'fathimasumaiya8992@gmail.com', 'role' => 'CSR', 'responsibilities' => ['order_confirmation', 'return_recovery'], 'supervisor' => 'sandamali', 'supervisor_access' => false],
            ['name' => 'bubyniru', 'email' => 'bubyniru1302@gmail.com', 'role' => 'CSR', 'responsibilities' => ['order_confirmation', 'return_recovery'], 'supervisor' => 'sandamali', 'supervisor_access' => false],
            ['name' => 'Nipuni', 'email' => 'nipuni.trainee@helos.invalid', 'role' => 'Trainee CSR', 'responsibilities' => ['order_confirmation'], 'supervisor' => 'sandamali', 'supervisor_access' => false],
            ['name' => 'Sanduashani', 'email' => 'sanduashani@gmail.com', 'role' => 'Operations Lead', 'responsibilities' => ['dispatch', 'return_recovery'], 'supervisor' => 'Nifras', 'supervisor_access' => false],
            ['name' => 'niska', 'email' => 'fathimaniska12@gmail.com', 'role' => 'CSR', 'responsibilities' => ['order_confirmation', 'return_recovery'], 'supervisor' => 'sandamali', 'supervisor_access' => false],
        ];

        foreach ($team as $member) {
            DB::table('employees')->updateOrInsert([
                'business_id' => $canonicalBusiness->id,
                'name' => $member['name'],
            ], [
                'role' => $member['role'],
                'monthly_salary' => 0,
                'pay_cycle' => 'month_end',
                'active' => true,
                'note' => 'Provisioned from the hosted Stock App team review. Salary, employment status, and contact details require owner confirmation.',
                'updated_at' => now(),
                'created_at' => now(),
            ]);

            $existingUser = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($member['email'])])
                ->first(['id']);

            $userData = [
                'name' => $member['name'],
                'business_id' => $canonicalBusiness->id,
                'client_group_id' => $clientGroupId,
                'is_platform_admin' => false,
                'is_employee' => true,
                'employee_access_profile' => $member['supervisor_access'] ? 'full_staff' : 'operations',
                'staff_responsibilities' => json_encode($member['responsibilities']),
                'responsibilities_configured' => true,
                'is_staff_supervisor' => $member['supervisor_access'],
                'updated_at' => now(),
            ];

            if ($existingUser) {
                DB::table('users')->where('id', $existingUser->id)->update($userData);

                continue;
            }

            DB::table('users')->insert($userData + [
                'email' => $member['email'],
                'password' => Hash::make(Str::random(64)),
                'created_at' => now(),
            ]);
        }

        $userIds = DB::table('users')
            ->where('business_id', $canonicalBusiness->id)
            ->whereIn('name', collect($team)->pluck('name')->all())
            ->pluck('id', 'name');

        foreach ($team as $member) {
            $userId = $userIds[$member['name']] ?? null;
            $supervisorId = $member['supervisor'] === 'owner'
                ? $owner->id
                : ($userIds[$member['supervisor']] ?? null);

            if (! $userId) {
                continue;
            }

            DB::table('users')->where('id', $userId)->update([
                'supervisor_user_id' => $supervisorId,
                'updated_at' => now(),
            ]);

            foreach ($member['responsibilities'] as $responsibility) {
                $exists = DB::table('staff_responsibility_assignments')
                    ->where('user_id', $userId)
                    ->where('business_id', $canonicalBusiness->id)
                    ->where('responsibility_code', $responsibility)
                    ->where('is_active', true)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('staff_responsibility_assignments')->insert([
                    'user_id' => $userId,
                    'business_id' => $canonicalBusiness->id,
                    'responsibility_code' => $responsibility,
                    'can_view' => true,
                    'can_create' => in_array($responsibility, ['expense_recording', 'production', 'material_stock'], true),
                    'can_edit' => true,
                    'can_complete' => true,
                    'can_review' => $responsibility === 'supervisor_review',
                    'can_approve' => false,
                    'own_records_only' => ! $member['supervisor_access'],
                    'team_records_allowed' => $responsibility === 'supervisor_review',
                    'active_from' => now(),
                    'expires_at' => null,
                    'is_active' => true,
                    'assigned_by' => $owner->id,
                    'assignment_note' => 'Provisioned from the approved Horns reporting hierarchy.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep provisioned staff and owner access on rollback. Removing access automatically is unsafe.
    }
};

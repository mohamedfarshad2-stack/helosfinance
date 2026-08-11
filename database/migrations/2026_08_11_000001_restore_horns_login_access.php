<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $business = DB::table('businesses')
            ->leftJoin('integration_sources', 'integration_sources.business_id', '=', 'businesses.id')
            ->whereRaw("LOWER(businesses.name) LIKE '%horns%england%'")
            ->orderByRaw("CASE WHEN integration_sources.type = 'stock_app' AND integration_sources.status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('integration_sources.last_successful_sync_at')
            ->orderByDesc('businesses.id')
            ->first(['businesses.id', 'businesses.client_group_id']);

        if (! $business) {
            return;
        }

        $ownerAccounts = [
            'mohamedfarshad2@gmail.com' => [
                'name' => 'Farshad',
                'password_hash' => '$2y$10$3G3vQbSgpYhX7ARnJYbhxuXH3meB9SgtpfwUOUbS.xFWJYSmzBQn6',
            ],
            'horns@admin.com' => [
                'name' => 'Horns Owner',
                'password_hash' => '$2y$10$79v5QKUxWMhqwH1QTZD/iO/CvRAEiLT25mSoegTXuhYtV1V/URutC',
            ],
        ];

        foreach ($ownerAccounts as $email => $account) {
            $existingId = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->value('id');

            $payload = [
                'name' => $account['name'],
                'email' => $email,
                'password' => $account['password_hash'],
                'business_id' => $business->id,
                'client_group_id' => $business->client_group_id,
                'is_platform_admin' => false,
                'is_employee' => false,
                'employee_access_profile' => 'operations',
                'staff_responsibilities' => null,
                'responsibilities_configured' => false,
                'is_staff_supervisor' => false,
                'supervisor_user_id' => null,
                'updated_at' => now(),
            ];

            if ($existingId) {
                DB::table('users')->where('id', $existingId)->update($payload);

                continue;
            }

            DB::table('users')->insert($payload + ['created_at' => now()]);
        }

        $ownerId = DB::table('users')
            ->whereRaw('LOWER(email) = ?', ['horns@admin.com'])
            ->value('id');

        $teamAccounts = [
            'nifras@helos.com' => [
                'name' => 'Nifras',
                'password_hash' => '$2y$10$Slc6yTFfWOzQ6vuWPQdU9usjGLy4Fb8gY83oFy.m/r1bA1Y9lWLae',
                'profile' => 'full_staff',
                'responsibilities' => ['supervisor_review', 'production', 'expense_recording'],
                'supervisor_email' => 'horns@admin.com',
            ],
            'arafath@helos.com' => [
                'name' => 'Arafath',
                'password_hash' => '$2y$10$vbUdpAljqExMTDTahbgizudSGos2M/hNhZBGGE.aHNX.RQqwntbSO',
                'profile' => 'operations',
                'responsibilities' => ['dispatch', 'product_repair', 'material_stock'],
                'supervisor_email' => 'nifras@helos.com',
            ],
            'sandhamali@helos.com' => [
                'name' => 'Sandhamali',
                'password_hash' => '$2y$10$1HeKBZnDI4rD3XNFd0sJNeJZhzIH4Vd8qKu1KHqunXD/1vNVffSgi',
                'profile' => 'full_staff',
                'responsibilities' => ['supervisor_review', 'order_confirmation', 'delivery_follow_up', 'return_recovery'],
                'supervisor_email' => 'nifras@helos.com',
            ],
        ];

        foreach ($teamAccounts as $email => $account) {
            $existingId = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->value('id');

            $supervisorId = $account['supervisor_email'] === 'horns@admin.com'
                ? $ownerId
                : DB::table('users')->whereRaw('LOWER(email) = ?', [$account['supervisor_email']])->value('id');

            $payload = [
                'name' => $account['name'],
                'email' => $email,
                'password' => $account['password_hash'],
                'business_id' => $business->id,
                'client_group_id' => $business->client_group_id,
                'is_platform_admin' => false,
                'is_employee' => true,
                'employee_access_profile' => $account['profile'],
                'staff_responsibilities' => json_encode($account['responsibilities']),
                'responsibilities_configured' => true,
                'is_staff_supervisor' => $account['profile'] === 'full_staff',
                'supervisor_user_id' => $supervisorId,
                'updated_at' => now(),
            ];

            if ($existingId) {
                DB::table('users')->where('id', $existingId)->update($payload);
            } else {
                DB::table('users')->insert($payload + ['created_at' => now()]);
                $existingId = DB::getPdo()->lastInsertId();
            }

            DB::table('staff_responsibility_assignments')
                ->where('user_id', $existingId)
                ->where('business_id', $business->id)
                ->whereNotIn('responsibility_code', $account['responsibilities'])
                ->update(['is_active' => false, 'updated_at' => now()]);

            foreach ($account['responsibilities'] as $responsibility) {
                DB::table('staff_responsibility_assignments')->updateOrInsert([
                    'user_id' => $existingId,
                    'business_id' => $business->id,
                    'responsibility_code' => $responsibility,
                ], [
                    'can_view' => true,
                    'can_create' => in_array($responsibility, ['expense_recording', 'production', 'material_stock'], true),
                    'can_edit' => true,
                    'can_complete' => true,
                    'can_review' => $responsibility === 'supervisor_review',
                    'can_approve' => false,
                    'own_records_only' => $account['profile'] !== 'full_staff',
                    'team_records_allowed' => $responsibility === 'supervisor_review',
                    'active_from' => now(),
                    'expires_at' => null,
                    'is_active' => true,
                    'assigned_by' => $ownerId,
                    'assignment_note' => 'Restored login access after production lockout report.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Do not revoke restored production logins automatically.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $business = DB::table('businesses')
            ->join('integration_sources', 'integration_sources.business_id', '=', 'businesses.id')
            ->where('integration_sources.type', 'stock_app')
            ->where('integration_sources.status', 'active')
            ->whereRaw("LOWER(businesses.name) LIKE '%horns%england%'")
            ->orderByDesc('integration_sources.last_successful_sync_at')
            ->orderByDesc('integration_sources.id')
            ->first(['businesses.id', 'businesses.client_group_id']);

        if (! $business) {
            return;
        }

        $ownerId = DB::table('users')
            ->whereRaw('LOWER(email) IN (?, ?)', ['horns@admin.com', 'mohamedfarshad2@gmail.com'])
            ->orderByRaw("CASE WHEN LOWER(email) = 'horns@admin.com' THEN 0 ELSE 1 END")
            ->value('id');

        if (! $ownerId) {
            return;
        }

        $clientGroupId = $business->client_group_id
            ?: DB::table('users')->where('id', $ownerId)->value('client_group_id');

        $accounts = [
            'Nifras' => [
                'emails' => ['nifras@helos.com', 'nifras.manager@helos.invalid'],
                'password' => '$2y$10$Slc6yTFfWOzQ6vuWPQdU9usjGLy4Fb8gY83oFy.m/r1bA1Y9lWLae',
                'profile' => 'full_staff',
                'supervisor_user_id' => $ownerId,
                'responsibilities' => ['collections', 'supervisor_review', 'order_confirmation', 'dispatch', 'delivery_follow_up'],
                'is_staff_supervisor' => true,
            ],
            'Arafath' => [
                'emails' => ['arafath@helos.com', 'arafath.store@helos.invalid'],
                'password' => '$2y$10$vbUdpAljqExMTDTahbgizudSGos2M/hNhZBGGE.aHNX.RQqwntbSO',
                'profile' => 'operations',
                'supervisor_user_id' => null,
                'responsibilities' => ['order_confirmation', 'dispatch', 'delivery_follow_up', 'return_recovery'],
                'is_staff_supervisor' => false,
            ],
            'Sandhamali' => [
                'emails' => ['sandhamali@helos.com', 'nisansalasandamalinew1@gmail.com'],
                'password' => '$2y$10$1HeKBZnDI4rD3XNFd0sJNeJZhzIH4Vd8qKu1KHqunXD/1vNVffSgi',
                'profile' => 'full_staff',
                'supervisor_user_id' => null,
                'responsibilities' => ['supervisor_review', 'order_confirmation', 'delivery_follow_up', 'return_recovery'],
                'is_staff_supervisor' => true,
            ],
        ];

        $ids = [];

        foreach ($accounts as $name => $account) {
            $user = DB::table('users')
                ->where(function ($query) use ($account): void {
                    foreach ($account['emails'] as $email) {
                        $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
                    }
                })
                ->first(['id']);

            if (! $user) {
                continue;
            }

            $ids[$name] = $user->id;

            DB::table('users')->where('id', $user->id)->update([
                'name' => $name,
                'email' => $account['emails'][0],
                'password' => $account['password'],
                'business_id' => $business->id,
                'client_group_id' => $clientGroupId,
                'is_platform_admin' => false,
                'is_employee' => true,
                'employee_access_profile' => $account['profile'],
                'staff_responsibilities' => json_encode($account['responsibilities']),
                'responsibilities_configured' => true,
                'is_staff_supervisor' => $account['is_staff_supervisor'],
                'supervisor_user_id' => $account['supervisor_user_id'],
                'updated_at' => now(),
            ]);
        }

        if (isset($ids['Nifras'])) {
            foreach (['Arafath', 'Sandhamali'] as $directReport) {
                if (! isset($ids[$directReport])) {
                    continue;
                }

                DB::table('users')->where('id', $ids[$directReport])->update([
                    'supervisor_user_id' => $ids['Nifras'],
                    'updated_at' => now(),
                ]);
            }
        }

        foreach ($accounts as $name => $account) {
            $userId = $ids[$name] ?? null;

            if (! $userId) {
                continue;
            }

            DB::table('staff_responsibility_assignments')
                ->where('user_id', $userId)
                ->where('business_id', $business->id)
                ->whereNotIn('responsibility_code', $account['responsibilities'])
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            foreach ($account['responsibilities'] as $responsibility) {
                DB::table('staff_responsibility_assignments')->updateOrInsert([
                    'user_id' => $userId,
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
                    'assignment_note' => 'Synced from Horns England live role bundle.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Do not roll back live Horns access bundles automatically.
    }
};

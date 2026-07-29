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

        $owner = DB::table('users')
            ->whereRaw('LOWER(email) = ?', ['horns@admin.com'])
            ->first(['id', 'client_group_id']);

        if (! $business || ! $owner) {
            return;
        }

        $clientGroupId = $business->client_group_id ?: $owner->client_group_id;
        $accounts = [
            'Nifras' => [
                'email' => 'nifras@helos.com',
                'previous_email' => 'nifras.manager@helos.invalid',
                'password' => '$2y$10$Sr8QCAyTvvXsiHTxeSqRpeRfautQcxXbBz.GwX4iKwdg.J8rAJ93G',
                'profile' => 'full_staff',
                'supervisor' => $owner->id,
                'responsibilities' => ['supervisor_review', 'production', 'expense_recording'],
            ],
            'Arafath' => [
                'email' => 'arafath@helos.com',
                'previous_email' => 'arafath.store@helos.invalid',
                'password' => '$2y$10$1uDdiM7EWmf1oEkNoLGR4eAEeZC5g7qe25lGTPI.C/xHS1HQ2.9Tm',
                'profile' => 'operations',
                'supervisor' => null,
                'responsibilities' => ['dispatch', 'product_repair', 'material_stock'],
            ],
            'Sandhamali' => [
                'email' => 'sandhamali@helos.com',
                'previous_email' => 'nisansalasandamalinew1@gmail.com',
                'password' => '$2y$10$ErUcZgbYwCODTunSCOgK8OG/tVZAzrRZ1Fpf5W.ehrG/WcONmHDIa',
                'profile' => 'full_staff',
                'supervisor' => null,
                'responsibilities' => ['supervisor_review', 'order_confirmation', 'delivery_follow_up', 'return_recovery'],
            ],
        ];

        $ids = [];

        foreach ($accounts as $name => $account) {
            $user = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($account['email'])])
                ->first(['id']);

            if (! $user) {
                $user = DB::table('users')
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower($account['previous_email'])])
                    ->first(['id']);
            }

            if (! $user) {
                continue;
            }

            $ids[$name] = $user->id;
            DB::table('users')->where('id', $user->id)->update([
                'name' => $name,
                'email' => $account['email'],
                'password' => $account['password'],
                'business_id' => $business->id,
                'client_group_id' => $clientGroupId,
                'is_platform_admin' => false,
                'is_employee' => true,
                'employee_access_profile' => $account['profile'],
                'staff_responsibilities' => json_encode($account['responsibilities']),
                'responsibilities_configured' => true,
                'is_staff_supervisor' => $account['profile'] === 'full_staff',
                'supervisor_user_id' => $account['supervisor'],
                'updated_at' => now(),
            ]);
        }

        $nifrasId = $ids['Nifras'] ?? null;
        $sandhamaliId = $ids['Sandhamali'] ?? null;

        if ($nifrasId) {
            foreach (['Arafath', 'Sandhamali'] as $directReport) {
                if (isset($ids[$directReport])) {
                    DB::table('users')->where('id', $ids[$directReport])->update([
                        'supervisor_user_id' => $nifrasId,
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if ($sandhamaliId) {
            DB::table('users')
                ->where('business_id', $business->id)
                ->whereIn('name', ['shamindi', 'M. Pramila dilrukshi', 'Rasheeda', 'Sumaiya', 'bubyniru', 'Nipuni', 'niska'])
                ->update(['supervisor_user_id' => $sandhamaliId, 'updated_at' => now()]);
        }

        foreach ($accounts as $name => $account) {
            $userId = $ids[$name] ?? null;

            if (! $userId) {
                continue;
            }

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
                    'assigned_by' => $owner->id,
                    'assignment_note' => 'Activated as an approved individual HELOAS login.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Do not disable working employee accounts automatically.
    }
};

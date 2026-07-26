<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $nifras = DB::table('users')
            ->whereRaw('LOWER(email) = ?', ['nifras@helos.com'])
            ->first(['id']);

        if (! $nifras) {
            return;
        }

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
        $responsibilities = ['supervisor_review', 'production', 'expense_recording'];

        DB::table('users')->where('id', $nifras->id)->update([
            'name' => 'Nifras',
            'business_id' => $business->id,
            'client_group_id' => $clientGroupId,
            'is_platform_admin' => false,
            'is_employee' => true,
            'employee_access_profile' => 'full_staff',
            'staff_responsibilities' => json_encode($responsibilities),
            'responsibilities_configured' => true,
            'is_staff_supervisor' => true,
            'supervisor_user_id' => $owner->id,
            'updated_at' => now(),
        ]);

        DB::table('users')
            ->where('business_id', $business->id)
            ->whereRaw('LOWER(email) IN (?, ?)', ['arafath@helos.com', 'sandhamali@helos.com'])
            ->update([
                'supervisor_user_id' => $nifras->id,
                'updated_at' => now(),
            ]);

        DB::table('staff_responsibility_assignments')
            ->where('user_id', $nifras->id)
            ->where('business_id', $business->id)
            ->whereNotIn('responsibility_code', $responsibilities)
            ->update(['is_active' => false, 'updated_at' => now()]);

        foreach ($responsibilities as $responsibility) {
            DB::table('staff_responsibility_assignments')->updateOrInsert([
                'user_id' => $nifras->id,
                'business_id' => $business->id,
                'responsibility_code' => $responsibility,
            ], [
                'can_view' => true,
                'can_create' => in_array($responsibility, ['production', 'expense_recording'], true),
                'can_edit' => true,
                'can_complete' => true,
                'can_review' => $responsibility === 'supervisor_review',
                'can_approve' => false,
                'own_records_only' => false,
                'team_records_allowed' => $responsibility === 'supervisor_review',
                'active_from' => now(),
                'expires_at' => null,
                'is_active' => true,
                'assigned_by' => $owner->id,
                'assignment_note' => 'Restored Nifras manager access after production authorization failure.',
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Do not revoke a working production manager account automatically.
    }
};

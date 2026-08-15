<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $businessId = DB::table('integration_sources')
            ->join('businesses', 'businesses.id', '=', 'integration_sources.business_id')
            ->where('integration_sources.type', 'stock_app')
            ->where('integration_sources.status', 'active')
            ->whereRaw("LOWER(businesses.name) LIKE '%horns%england%'")
            ->orderByDesc('integration_sources.last_successful_sync_at')
            ->value('businesses.id');

        if (! $businessId) {
            return;
        }

        $sandhamaliIds = DB::table('users')
            ->whereRaw('LOWER(email) IN (?, ?)', ['sandhamali@helos.com', 'nisansalasandamalinew1@gmail.com'])
            ->pluck('id')
            ->all();

        if ($sandhamaliIds === []) {
            return;
        }

        $responsibilities = ['return_recovery'];

        DB::table('users')
            ->whereIn('id', $sandhamaliIds)
            ->update([
                'employee_access_profile' => 'work_only',
                'staff_responsibilities' => json_encode($responsibilities),
                'responsibilities_configured' => true,
                'is_staff_supervisor' => false,
                'updated_at' => now(),
            ]);

        DB::table('staff_responsibility_assignments')
            ->whereIn('user_id', $sandhamaliIds)
            ->where('business_id', $businessId)
            ->whereNotIn('responsibility_code', $responsibilities)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        foreach ($sandhamaliIds as $userId) {
            foreach ($responsibilities as $responsibility) {
                DB::table('staff_responsibility_assignments')->updateOrInsert([
                    'user_id' => $userId,
                    'business_id' => $businessId,
                    'responsibility_code' => $responsibility,
                ], [
                    'can_view' => true,
                    'can_create' => false,
                    'can_edit' => true,
                    'can_complete' => true,
                    'can_review' => false,
                    'team_records_allowed' => false,
                    'expires_at' => null,
                    'is_active' => true,
                    'assigned_by' => null,
                    'assignment_note' => 'Refined Sandhamali access to COD return work only.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep the refined Sandhamali access path in place.
    }
};

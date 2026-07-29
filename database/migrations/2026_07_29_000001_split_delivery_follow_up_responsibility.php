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
            ->first(['businesses.id']);

        if (! $business) {
            return;
        }

        $ownerId = DB::table('users')
            ->whereRaw('LOWER(email) IN (?, ?)', ['horns@admin.com', 'mohamedfarshad2@gmail.com'])
            ->orderByRaw("CASE WHEN LOWER(email) = 'horns@admin.com' THEN 0 ELSE 1 END")
            ->value('id');

        $sandhamaliId = DB::table('users')
            ->whereRaw('LOWER(email) = ?', ['sandhamali@helos.com'])
            ->value('id');

        if ($sandhamaliId) {
            $current = DB::table('users')->where('id', $sandhamaliId)->value('staff_responsibilities');
            $responsibilities = is_string($current) && $current !== '' ? json_decode($current, true) : [];
            $responsibilities = is_array($responsibilities) ? $responsibilities : [];
            $responsibilities[] = 'delivery_follow_up';
            $responsibilities = array_values(array_unique(array_filter($responsibilities)));

            DB::table('users')->where('id', $sandhamaliId)->update([
                'staff_responsibilities' => json_encode($responsibilities),
                'responsibilities_configured' => true,
                'updated_at' => now(),
            ]);

            DB::table('staff_responsibility_assignments')->updateOrInsert([
                'user_id' => $sandhamaliId,
                'business_id' => $business->id,
                'responsibility_code' => 'delivery_follow_up',
            ], [
                'can_view' => true,
                'can_create' => false,
                'can_edit' => true,
                'can_complete' => true,
                'can_review' => false,
                'can_approve' => false,
                'own_records_only' => false,
                'team_records_allowed' => false,
                'active_from' => now(),
                'expires_at' => null,
                'is_active' => true,
                'assigned_by' => $ownerId,
                'assignment_note' => 'Follow dispatched parcels until they are delivered.',
                'updated_at' => now(),
                'created_at' => now(),
            ]);

            DB::table('missions')
                ->where('business_id', $business->id)
                ->where('mission_type', 'delivery_follow_up')
                ->whereIn('status', ['open', 'started', 'blocked', 'escalated', 'waiting_review'])
                ->update([
                    'responsibility_code' => 'delivery_follow_up',
                    'assigned_user_id' => $sandhamaliId,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('missions')
                ->where('business_id', $business->id)
                ->where('mission_type', 'delivery_follow_up')
                ->whereIn('status', ['open', 'started', 'blocked', 'escalated', 'waiting_review'])
                ->update([
                    'responsibility_code' => 'delivery_follow_up',
                    'assigned_user_id' => null,
                    'updated_at' => now(),
                ]);
        }

        DB::table('missions')
            ->where('business_id', $business->id)
            ->where('mission_type', 'order_tracking')
            ->where('responsibility_code', 'delivery_follow_up')
            ->update([
                'responsibility_code' => 'dispatch',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Keep the safer split; do not merge delivery follow-up back into dispatch automatically.
    }
};

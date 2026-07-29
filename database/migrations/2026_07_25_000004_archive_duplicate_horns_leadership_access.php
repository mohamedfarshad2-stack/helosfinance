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

        $approved = [
            'nifras@helos.com' => ['supervisor_review', 'production', 'expense_recording'],
            'arafath@helos.com' => ['dispatch', 'product_repair', 'material_stock'],
            'sandhamali@helos.com' => ['supervisor_review', 'order_confirmation', 'delivery_follow_up', 'return_recovery'],
        ];

        $canonicalIds = [];

        foreach ($approved as $email => $responsibilities) {
            $userId = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->value('id');

            if (! $userId) {
                continue;
            }

            $canonicalIds[$email] = $userId;
            DB::table('staff_responsibility_assignments')
                ->where('user_id', $userId)
                ->where('business_id', $businessId)
                ->whereNotIn('responsibility_code', $responsibilities)
                ->update(['is_active' => false, 'updated_at' => now()]);
        }

        $duplicates = [
            'arafath.store@helos.invalid' => $canonicalIds['arafath@helos.com'] ?? null,
            'nisansalasandamalinew1@gmail.com' => $canonicalIds['sandhamali@helos.com'] ?? null,
        ];

        foreach ($duplicates as $email => $canonicalId) {
            $duplicateId = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->value('id');

            if (! $duplicateId || ! $canonicalId || $duplicateId === $canonicalId) {
                continue;
            }

            DB::table('missions')->where('assigned_user_id', $duplicateId)->update(['assigned_user_id' => $canonicalId, 'updated_at' => now()]);
            DB::table('missions')->where('escalated_to_user_id', $duplicateId)->update(['escalated_to_user_id' => $canonicalId, 'updated_at' => now()]);
            DB::table('users')->where('supervisor_user_id', $duplicateId)->update(['supervisor_user_id' => $canonicalId, 'updated_at' => now()]);
            DB::table('staff_responsibility_assignments')->where('user_id', $duplicateId)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('users')->where('id', $duplicateId)->update([
                'name' => 'Archived duplicate - '.DB::table('users')->where('id', $duplicateId)->value('name'),
                'business_id' => null,
                'client_group_id' => null,
                'supervisor_user_id' => null,
                'staff_responsibilities' => json_encode([]),
                'responsibilities_configured' => true,
                'is_staff_supervisor' => false,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Historical duplicate access must not be restored automatically.
    }
};

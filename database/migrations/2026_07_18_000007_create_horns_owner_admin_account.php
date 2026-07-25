<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $business = DB::table('businesses')
            ->where(function ($query): void {
                $query
                    ->where('name', 'Horns England Pvt Ltd')
                    ->orWhere('name', 'Horns England');
            })
            ->orderByRaw("CASE WHEN name = 'Horns England Pvt Ltd' THEN 0 ELSE 1 END")
            ->first(['id', 'client_group_id']);

        if (! $business) {
            return;
        }

        $existingUserId = DB::table('users')
            ->whereRaw('LOWER(email) = ?', ['horns@admin.com'])
            ->value('id');

        $ownerData = [
            'name' => 'Horns Owner',
            'email' => 'Horns@admin.com',
            'password' => Hash::make(Str::random(64)),
            'business_id' => $business->id,
            'client_group_id' => $business->client_group_id,
            'is_platform_admin' => false,
            'is_employee' => false,
            'employee_access_profile' => 'operations',
            'staff_responsibilities' => null,
            'responsibilities_configured' => false,
            'is_staff_supervisor' => false,
            'updated_at' => now(),
        ];

        if ($existingUserId) {
            DB::table('users')
                ->where('id', $existingUserId)
                ->update($ownerData);

            return;
        }

        DB::table('users')->insert($ownerData + [
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep owner access on rollback. Removing access automatically would be unsafe.
    }
};

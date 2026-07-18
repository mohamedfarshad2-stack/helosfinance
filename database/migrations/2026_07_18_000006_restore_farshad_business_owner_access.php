<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereRaw('LOWER(email) = ?', ['mohamedfarshad2@gmail.com'])
            ->orderBy('id')
            ->get(['id', 'business_id', 'client_group_id'])
            ->each(function (object $user): void {
                $clientGroupId = $user->client_group_id;

                if (! $clientGroupId && $user->business_id) {
                    $clientGroupId = DB::table('businesses')
                        ->where('id', $user->business_id)
                        ->value('client_group_id');
                }

                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'client_group_id' => $clientGroupId,
                        'is_platform_admin' => false,
                        'is_employee' => false,
                        'staff_responsibilities' => null,
                        'responsibilities_configured' => false,
                        'is_staff_supervisor' => false,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // This is a one-way production data correction. Do not demote the owner on rollback.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        $user = DB::table('users')
            ->whereRaw('LOWER(email) = ?', ['mohamedfarshad2@gmail.com'])
            ->orderBy('id')
            ->first(['id']);

        if (! $user) {
            return;
        }

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'name' => 'Farshad',
                'email' => 'mohamedfarshad2@gmail.com',
                'password' => Hash::make('Farshad@789'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Keep the restored login credential in place.
    }
};

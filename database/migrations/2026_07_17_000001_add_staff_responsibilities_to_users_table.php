<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('staff_responsibilities')->nullable()->after('employee_access_profile');
            $table->boolean('responsibilities_configured')->default(false)->after('staff_responsibilities');
            $table->boolean('is_staff_supervisor')->default(false)->after('responsibilities_configured');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['staff_responsibilities', 'responsibilities_configured', 'is_staff_supervisor']);
        });
    }
};

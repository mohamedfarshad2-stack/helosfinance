<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('supervisor_user_id')
                ->nullable()
                ->after('is_staff_supervisor')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('missions', function (Blueprint $table): void {
            $table->foreignId('escalated_to_user_id')
                ->nullable()
                ->after('escalation_level')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['escalated_to_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table): void {
            $table->dropIndex(['escalated_to_user_id', 'status']);
            $table->dropConstrainedForeignId('escalated_to_user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supervisor_user_id');
        });
    }
};

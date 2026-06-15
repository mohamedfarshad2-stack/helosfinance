<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->foreignId('client_group_id')->nullable()->after('id')->constrained('client_groups')->nullOnDelete();
            $table->index(['client_group_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('client_group_id')->nullable()->after('business_id')->constrained('client_groups')->nullOnDelete();
            $table->index(['client_group_id', 'is_employee']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['client_group_id', 'is_employee']);
            $table->dropConstrainedForeignId('client_group_id');
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropIndex(['client_group_id', 'name']);
            $table->dropConstrainedForeignId('client_group_id');
        });

        Schema::dropIfExists('client_groups');
    }
};

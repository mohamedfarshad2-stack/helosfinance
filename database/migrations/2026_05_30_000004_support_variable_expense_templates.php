<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_expense_templates', function (Blueprint $table): void {
            $table->string('expense_type')->default('fixed')->after('label');
            $table->string('basis')->nullable()->after('department');
        });

        DB::table('fixed_expense_templates')->update(['expense_type' => 'fixed']);
    }

    public function down(): void
    {
        Schema::table('fixed_expense_templates', function (Blueprint $table): void {
            $table->dropColumn(['expense_type', 'basis']);
        });
    }
};

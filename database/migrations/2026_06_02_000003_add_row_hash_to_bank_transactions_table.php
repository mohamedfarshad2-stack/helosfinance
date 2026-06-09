<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->string('row_hash', 64)->nullable()->after('statement_name');
            $table->index(['business_id', 'row_hash']);
            $table->unique(['business_id', 'row_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropUnique(['business_id', 'row_hash']);
            $table->dropIndex(['business_id', 'row_hash']);
            $table->dropColumn('row_hash');
        });
    }
};

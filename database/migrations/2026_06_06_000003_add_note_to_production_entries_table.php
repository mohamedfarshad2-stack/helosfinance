<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_entries', function (Blueprint $table): void {
            $table->text('note')->nullable()->after('paid_on');
        });
    }

    public function down(): void
    {
        Schema::table('production_entries', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};

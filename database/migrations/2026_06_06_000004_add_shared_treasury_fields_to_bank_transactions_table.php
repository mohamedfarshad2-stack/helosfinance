<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->string('money_container')->nullable()->after('description');
            $table->string('counter_money_container')->nullable()->after('money_container');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropColumn(['money_container', 'counter_money_container']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('cheque_number')->nullable()->after('payment_method');
            $table->date('cheque_date')->nullable()->after('cheque_number');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn([
                'cheque_number',
                'cheque_date',
            ]);
        });
    }
};

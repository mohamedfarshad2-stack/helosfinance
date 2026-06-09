<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->string('transaction_type')->nullable()->after('classification');
            $table->foreignId('allocated_business_id')->nullable()->after('transaction_type')->constrained('businesses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('allocated_business_id');
            $table->dropColumn('transaction_type');
        });
    }
};

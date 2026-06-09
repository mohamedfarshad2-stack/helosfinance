<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_entries', function (Blueprint $table): void {
            $table->decimal('advance_amount', 12, 2)->default(0)->after('employee_payout');
            $table->decimal('deduction_amount', 12, 2)->default(0)->after('advance_amount');
            $table->decimal('net_payable', 12, 2)->default(0)->after('deduction_amount');
            $table->string('payment_status')->default('pending')->after('net_payable');
            $table->date('paid_on')->nullable()->after('payment_status');
            $table->index(['business_id', 'produced_on', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('production_entries', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'produced_on', 'payment_status']);
            $table->dropColumn(['advance_amount', 'deduction_amount', 'net_payable', 'payment_status', 'paid_on']);
        });
    }
};

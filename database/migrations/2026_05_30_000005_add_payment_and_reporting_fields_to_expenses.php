<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('payment_status')->default('paid')->after('amount');
            $table->string('payment_method')->nullable()->after('payment_status');
            $table->decimal('paid_amount', 12, 2)->default(0)->after('payment_method');
            $table->date('due_on')->nullable()->after('paid_amount');
            $table->date('settled_on')->nullable()->after('due_on');
            $table->string('payee')->nullable()->after('settled_on');
            $table->string('reported_by')->nullable()->after('payee');
            $table->string('allocation_bucket')->default('company_expense')->after('reported_by');
        });

        DB::table('expenses')->update([
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'paid_amount' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_status',
                'payment_method',
                'paid_amount',
                'due_on',
                'settled_on',
                'payee',
                'reported_by',
                'allocation_bucket',
            ]);
        });
    }
};

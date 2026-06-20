<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_billing_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('client_name');
            $table->string('billing_type')->default('subscription');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('payment_status')->default('unpaid');
            $table->date('due_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'payment_status', 'due_on']);
            $table->index(['business_id', 'client_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_billing_records');
    }
};

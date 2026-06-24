<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number')->nullable();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('city')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('sale_amount', 12, 2)->default(0);
            $table->string('status')->default('new');
            $table->unsignedInteger('call_attempts')->default(0);
            $table->string('confirmation_remark')->nullable();
            $table->string('courier_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->boolean('resend_from_stock')->default(false);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('return_charge', 12, 2)->default(0);
            $table->decimal('resend_charge', 12, 2)->default(0);
            $table->string('return_reason')->nullable();
            $table->string('resend_reason')->nullable();
            $table->decimal('collected_amount', 12, 2)->default(0);
            $table->date('order_date')->nullable();
            $table->date('dispatched_on')->nullable();
            $table->date('delivered_on')->nullable();
            $table->date('returned_on')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'order_number']);
            $table->index(['business_id', 'status', 'order_date']);
            $table->index(['business_id', 'courier_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_orders');
    }
};

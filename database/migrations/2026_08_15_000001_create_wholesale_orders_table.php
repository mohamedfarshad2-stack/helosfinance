<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('order_number')->nullable()->index();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('customer_location')->nullable();
            $table->date('order_date');
            $table->string('status')->default('booked')->index();
            $table->string('payment_status')->default('pending')->index();
            $table->string('payment_method')->nullable();
            $table->string('delivery_method')->nullable();
            $table->string('courier_name')->nullable();
            $table->json('line_items')->nullable();
            $table->decimal('gross_sale_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('delivery_charge_charged', 12, 2)->default(0);
            $table->decimal('courier_cost_amount', 12, 2)->default(0);
            $table->decimal('product_cost_amount', 12, 2)->default(0);
            $table->decimal('net_sales_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('gross_profit_amount', 12, 2)->default(0);
            $table->dateTime('next_follow_up_at')->nullable();
            $table->dateTime('reorder_due_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_orders');
    }
};

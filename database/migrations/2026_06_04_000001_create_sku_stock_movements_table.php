<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sku_stock_movements')) {
            Schema::table('sku_stock_movements', function (Blueprint $table): void {
                $table->unique(['business_id', 'operational_event_id', 'movement_type'], 'sku_stock_movements_event_unique');
                $table->index(['business_id', 'sku_id', 'movement_type', 'occurred_at'], 'sku_stock_movements_sku_move_idx');
            });

            return;
        }

        Schema::create('sku_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
            $table->foreignId('operational_event_id')->nullable()->constrained('operational_events')->nullOnDelete();
            $table->string('order_external_id')->nullable();
            $table->string('movement_type');
            $table->integer('quantity')->default(0);
            $table->integer('quantity_delta')->default(0);
            $table->boolean('is_restockable')->default(false);
            $table->string('note')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'operational_event_id', 'movement_type'], 'sku_stock_movements_event_unique');
            $table->index(['business_id', 'sku_id', 'movement_type', 'occurred_at'], 'sku_stock_movements_sku_move_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_stock_movements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_recipe_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->cascadeOnDelete();
            $table->string('component_name');
            $table->decimal('quantity_per_unit', 12, 4)->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'sku_id', 'active']);
        });

        Schema::create('material_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
            $table->string('entry_type');
            $table->string('component_name');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->date('occurred_on');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'occurred_on', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_ledger_entries');
        Schema::dropIfExists('sku_recipe_items');
    }
};

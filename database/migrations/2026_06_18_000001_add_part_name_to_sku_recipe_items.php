<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_recipe_items', function (Blueprint $table): void {
            $table->string('part_name')->default('General')->after('component_name');
            $table->index(['business_id', 'sku_id', 'part_name'], 'sku_recipe_part_index');
        });
    }

    public function down(): void
    {
        Schema::table('sku_recipe_items', function (Blueprint $table): void {
            $table->dropIndex('sku_recipe_part_index');
            $table->dropColumn('part_name');
        });
    }
};

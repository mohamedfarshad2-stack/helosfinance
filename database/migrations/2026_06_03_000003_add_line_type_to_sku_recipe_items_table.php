<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_recipe_items', function (Blueprint $table): void {
            $table->string('line_type')->default('raw_material')->after('sku_id');
            $table->index(['business_id', 'sku_id', 'line_type']);
        });
    }

    public function down(): void
    {
        Schema::table('sku_recipe_items', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'sku_id', 'line_type']);
            $table->dropColumn('line_type');
        });
    }
};

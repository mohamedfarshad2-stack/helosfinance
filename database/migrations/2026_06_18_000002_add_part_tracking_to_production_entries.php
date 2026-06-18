<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_entries', function (Blueprint $table): void {
            $table->string('production_kind')->default('part_production')->after('sku_recipe_item_id');
            $table->string('part_name')->nullable()->after('production_kind');
            $table->index(['business_id', 'sku_id', 'production_kind', 'part_name'], 'production_part_wip_index');
        });
    }

    public function down(): void
    {
        Schema::table('production_entries', function (Blueprint $table): void {
            $table->dropIndex('production_part_wip_index');
            $table->dropColumn(['production_kind', 'part_name']);
        });
    }
};

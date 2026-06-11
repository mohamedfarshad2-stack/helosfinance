<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_entries', function (Blueprint $table): void {
            $table->foreignId('sku_recipe_item_id')->nullable()->after('sku_id')->constrained('sku_recipe_items')->nullOnDelete();
            $table->string('production_step')->nullable()->after('employee_name');
            $table->decimal('piece_rate', 12, 2)->default(0)->after('production_step');
            $table->index(['business_id', 'employee_name', 'produced_on']);
        });
    }

    public function down(): void
    {
        Schema::table('production_entries', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'employee_name', 'produced_on']);
            $table->dropConstrainedForeignId('sku_recipe_item_id');
            $table->dropColumn(['production_step', 'piece_rate']);
        });
    }
};

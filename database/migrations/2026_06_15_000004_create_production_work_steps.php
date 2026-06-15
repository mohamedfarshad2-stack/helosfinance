<?php

use App\Domains\Shared\Models\ProductionWorkStep;
use App\Domains\Shared\Models\SkuRecipeItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_work_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'active']);
        });

        Schema::table('sku_recipe_items', function (Blueprint $table): void {
            $table->foreignId('production_work_step_id')->nullable()->after('material_component_id')->constrained('production_work_steps')->nullOnDelete();
            $table->index(['business_id', 'production_work_step_id']);
        });

        DB::table('sku_recipe_items')
            ->select('business_id', 'component_name')
            ->where('line_type', SkuRecipeItem::TYPE_LABOR)
            ->whereNotNull('component_name')
            ->where('component_name', '!=', '')
            ->distinct()
            ->orderBy('business_id')
            ->get()
            ->each(function (object $row): void {
                $step = ProductionWorkStep::query()->firstOrCreate(
                    [
                        'business_id' => $row->business_id,
                        'name' => trim((string) $row->component_name),
                    ],
                    [
                        'unit_cost' => (float) DB::table('sku_recipe_items')
                            ->where('business_id', $row->business_id)
                            ->where('line_type', SkuRecipeItem::TYPE_LABOR)
                            ->where('component_name', $row->component_name)
                            ->value('unit_cost'),
                        'active' => true,
                        'note' => 'Created automatically from existing labor recipe names.',
                    ],
                );

                DB::table('sku_recipe_items')
                    ->where('business_id', $row->business_id)
                    ->where('line_type', SkuRecipeItem::TYPE_LABOR)
                    ->where('component_name', $row->component_name)
                    ->update(['production_work_step_id' => $step->id]);
            });
    }

    public function down(): void
    {
        Schema::table('sku_recipe_items', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'production_work_step_id']);
            $table->dropConstrainedForeignId('production_work_step_id');
        });

        Schema::dropIfExists('production_work_steps');
    }
};

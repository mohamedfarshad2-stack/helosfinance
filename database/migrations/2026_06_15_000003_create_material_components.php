<?php

use App\Domains\Shared\Models\MaterialComponent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('purchase_unit')->default('unit');
            $table->string('consumption_unit')->default('piece');
            $table->decimal('units_per_purchase_unit', 12, 4)->default(1);
            $table->decimal('waste_percent', 5, 2)->default(0);
            $table->decimal('latest_purchase_unit_cost', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'active']);
        });

        Schema::table('sku_recipe_items', function (Blueprint $table): void {
            $table->foreignId('material_component_id')->nullable()->after('component_name')->constrained('material_components')->nullOnDelete();
            $table->index(['business_id', 'material_component_id']);
        });

        Schema::table('material_ledger_entries', function (Blueprint $table): void {
            $table->foreignId('material_component_id')->nullable()->after('component_name')->constrained('material_components')->nullOnDelete();
            $table->index(['business_id', 'material_component_id']);
        });

        $this->backfillComponents('sku_recipe_items');
        $this->backfillComponents('material_ledger_entries');
    }

    public function down(): void
    {
        Schema::table('material_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'material_component_id']);
            $table->dropConstrainedForeignId('material_component_id');
        });

        Schema::table('sku_recipe_items', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'material_component_id']);
            $table->dropConstrainedForeignId('material_component_id');
        });

        Schema::dropIfExists('material_components');
    }

    private function backfillComponents(string $table): void
    {
        DB::table($table)
            ->select('business_id', 'component_name')
            ->whereNotNull('component_name')
            ->where('component_name', '!=', '')
            ->distinct()
            ->orderBy('business_id')
            ->get()
            ->each(function (object $row) use ($table): void {
                $component = MaterialComponent::query()->firstOrCreate(
                    [
                        'business_id' => $row->business_id,
                        'name' => trim((string) $row->component_name),
                    ],
                    [
                        'purchase_unit' => 'unit',
                        'consumption_unit' => 'piece',
                        'units_per_purchase_unit' => 1,
                        'waste_percent' => 0,
                        'latest_purchase_unit_cost' => 0,
                        'note' => 'Created automatically from existing material names.',
                    ],
                );

                DB::table($table)
                    ->where('business_id', $row->business_id)
                    ->where('component_name', $row->component_name)
                    ->update(['material_component_id' => $component->id]);
            });
    }
};

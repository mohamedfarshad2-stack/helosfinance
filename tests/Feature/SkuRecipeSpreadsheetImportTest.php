<?php

namespace Tests\Feature;

use App\Domains\Manufacturing\Services\SkuRecipeSpreadsheetImportService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkuRecipeSpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_raw_material_and_labor_recipe_lines_from_a_sheet(): void
    {
        $business = Business::query()->create([
            'name' => 'Factory Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SLP-001',
            'name' => 'Slipper A',
            'material_cost' => 0,
            'packaging_cost' => 0,
            'labor_rate' => 0,
            'finishing_cost' => 0,
            'expected_sale_price' => 1200,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'sku_recipe_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'sku_code,line_type,component_name,quantity_per_unit,unit_cost,purchase_unit,purchase_unit_cost,units_per_purchase_unit,waste_percent,consumption_unit,active,note',
            'SLP-001,raw_material,DSI sheet,1,,sheet,1200,12,5,piece,yes,',
            'SLP-001,raw_material,Glue,0.2,40,bottle,,,,use,yes,',
            'SLP-001,labor,Stitching labor,2,100,,,,,,yes,',
        ]));

        $result = app(SkuRecipeSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseCount('sku_recipe_items', 3);

        $this->assertSame(2, SkuRecipeItem::query()->where('line_type', SkuRecipeItem::TYPE_RAW_MATERIAL)->count());
        $this->assertSame(1, SkuRecipeItem::query()->where('line_type', SkuRecipeItem::TYPE_LABOR)->count());
        $this->assertDatabaseCount('material_components', 2);

        $component = MaterialComponent::query()->where('name', 'DSI sheet')->firstOrFail();

        $this->assertSame(105.26, $component->costPerConsumptionUnit());
        $this->assertSame(105.26, (float) SkuRecipeItem::query()->where('component_name', 'DSI sheet')->value('unit_cost'));

        $repeat = app(SkuRecipeSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(0, $repeat['created']);
        $this->assertSame(3, $repeat['updated']);
        $this->assertDatabaseCount('sku_recipe_items', 3);

        @unlink($csvPath);
        @unlink($path);
    }
}

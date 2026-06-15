<?php

namespace Tests\Feature;

use App\Domains\Manufacturing\Services\SkuRecipeSpreadsheetImportService;
use App\Domains\Manufacturing\Services\SkuRecipeTemplateExportService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use App\Domains\Shared\Models\ProductionWorkStep;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

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

        ProductionWorkStep::query()->create([
            'business_id' => $business->id,
            'name' => 'Stitching labour',
            'unit_cost' => 100,
            'active' => true,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'sku_recipe_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'sku_code,line_type,component_name,quantity_per_unit,unit_cost,purchase_unit,purchase_unit_cost,units_per_purchase_unit,waste_percent,consumption_unit,active,note',
            'SLP-001,raw_material,DSI sheet,1,,sheet,1200,12,5,piece,yes,',
            'SLP-001,raw_material,Glue,0.2,40,bottle,,,,use,yes,',
            'SLP-001,labour,Stitching labour,2,100,,,,,,yes,',
            'SLP-001,labour,Stiching labour,2,100,,,,,,yes,',
        ]));

        $result = app(SkuRecipeSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseCount('sku_recipe_items', 3);

        $this->assertSame(2, SkuRecipeItem::query()->where('line_type', SkuRecipeItem::TYPE_RAW_MATERIAL)->count());
        $this->assertSame(1, SkuRecipeItem::query()->where('line_type', SkuRecipeItem::TYPE_LABOR)->count());
        $this->assertDatabaseCount('material_components', 2);
        $this->assertSame(1, SkuRecipeItem::query()->whereNotNull('production_work_step_id')->count());

        $component = MaterialComponent::query()->where('name', 'DSI sheet')->firstOrFail();

        $this->assertSame(105.26, $component->costPerConsumptionUnit());
        $this->assertSame(105.26, (float) SkuRecipeItem::query()->where('component_name', 'DSI sheet')->value('unit_cost'));

        $repeat = app(SkuRecipeSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(0, $repeat['created']);
        $this->assertSame(3, $repeat['updated']);
        $this->assertSame(1, $repeat['skipped']);
        $this->assertDatabaseCount('sku_recipe_items', 3);

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_it_exports_recipe_excel_template_with_dropdown_lists(): void
    {
        $business = Business::query()->create([
            'name' => 'Dropdown Factory',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SLP-001',
            'name' => 'Slipper A',
            'expected_sale_price' => 1200,
        ]);

        MaterialComponent::query()->create([
            'business_id' => $business->id,
            'name' => 'DSI sheet',
            'purchase_unit' => 'sheet',
            'consumption_unit' => 'piece',
            'units_per_purchase_unit' => 12,
            'waste_percent' => 5,
            'latest_purchase_unit_cost' => 1200,
            'active' => true,
        ]);

        ProductionWorkStep::query()->create([
            'business_id' => $business->id,
            'name' => 'Bottom labour',
            'unit_cost' => 80,
            'active' => true,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'sku_recipe_template_').'.xlsx';

        app(SkuRecipeTemplateExportService::class)->export($business, $path);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        $recipeSheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $listsSheet = $zip->getFromName('xl/worksheets/sheet2.xml');
        $workbook = $zip->getFromName('xl/workbook.xml');

        $zip->close();

        $this->assertStringContainsString('<dataValidations count="4">', $recipeSheet);
        $this->assertStringContainsString('IF($B2=&quot;raw_material&quot;,MaterialNames,WorkStepNames)', $recipeSheet);
        $this->assertStringContainsString('DSI sheet', $listsSheet);
        $this->assertStringContainsString('Bottom labour', $listsSheet);
        $this->assertStringContainsString('SLP-001', $listsSheet);
        $this->assertStringContainsString('<definedName name="MaterialNames">', $workbook);

        @unlink($path);
    }
}

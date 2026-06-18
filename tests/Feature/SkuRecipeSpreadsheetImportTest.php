<?php

namespace Tests\Feature;

use App\Domains\Manufacturing\Services\SkuRecipeSpreadsheetImportService;
use App\Domains\Manufacturing\Services\SkuRecipeTemplateExportService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use App\Domains\Shared\Models\ProductionWorkStep;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use App\Filament\Resources\SkuRecipeResource\Pages\ListSkuRecipeItems;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
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

        $this->assertSame(4, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([], $result['skipped_reasons']);
        $this->assertDatabaseCount('sku_recipe_items', 4);

        $this->assertSame(2, SkuRecipeItem::query()->where('line_type', SkuRecipeItem::TYPE_RAW_MATERIAL)->count());
        $this->assertSame(2, SkuRecipeItem::query()->where('line_type', SkuRecipeItem::TYPE_LABOR)->count());
        $this->assertDatabaseCount('material_components', 2);
        $this->assertSame(2, SkuRecipeItem::query()->whereNotNull('production_work_step_id')->count());
        $this->assertTrue(ProductionWorkStep::query()->where('name', 'Stiching labour')->exists());

        $component = MaterialComponent::query()->where('name', 'DSI sheet')->firstOrFail();

        $this->assertSame(105.26, $component->costPerConsumptionUnit());
        $this->assertSame(105.26, (float) SkuRecipeItem::query()->where('component_name', 'DSI sheet')->value('unit_cost'));

        $repeat = app(SkuRecipeSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(0, $repeat['created']);
        $this->assertSame(4, $repeat['updated']);
        $this->assertSame(0, $repeat['skipped']);
        $this->assertDatabaseCount('sku_recipe_items', 4);

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_it_reports_why_recipe_rows_are_skipped(): void
    {
        $business = Business::query()->create([
            'name' => 'Factory Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'sku_recipe_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'sku_code,line_type,component_name,quantity_per_unit,unit_cost',
            'MISSING-001,raw_material,DSI sheet,1,100',
            ',labour,Stitching labour,1,50',
        ]));

        $result = app(SkuRecipeSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, $result['skipped']);
        $this->assertStringContainsString("SKU 'MISSING-001' was not found", $result['skipped_reasons'][0]);
        $this->assertStringContainsString('SKU code or component/work step name is missing', $result['skipped_reasons'][1]);

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_it_can_create_products_and_part_based_recipe_lines_from_one_sheet(): void
    {
        $business = Business::query()->create([
            'name' => 'Factory Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'sku_recipe_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'sku_code,product_name,part_name,line_type,component_name,quantity_per_unit,unit_cost,purchase_unit,purchase_unit_cost,units_per_purchase_unit,waste_percent,consumption_unit,active,note',
            'PS364,Classic Slipper,Strap,raw_material,Rexine,1,,sheet,1400,30,5,piece,yes,',
            'PS364,Classic Slipper,Strap,labour,Stitching labour,1,35,,,,,,yes,',
            'PS364,Classic Slipper,Sole,raw_material,DSI Sheet,1,,sheet,2300,12,5,piece,yes,',
        ]));

        $result = app(SkuRecipeSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseHas('skus', [
            'business_id' => $business->id,
            'code' => 'PS364',
            'name' => 'Classic Slipper',
        ]);
        $this->assertDatabaseHas('sku_recipe_items', [
            'part_name' => 'Strap',
            'component_name' => 'Rexine',
        ]);
        $this->assertDatabaseHas('sku_recipe_items', [
            'part_name' => 'Sole',
            'component_name' => 'DSI Sheet',
        ]);

        $sku = Sku::query()->where('code', 'PS364')->firstOrFail();

        $this->assertSame(2, $sku->recipeItems()->where('part_name', 'Strap')->count());
        $this->assertSame(1, $sku->recipeItems()->where('part_name', 'Sole')->count());

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

        $this->assertStringContainsString('<dataValidations count="5">', $recipeSheet);
        $this->assertStringContainsString('PartNames', $recipeSheet);
        $this->assertStringContainsString('IF($D2=&quot;raw_material&quot;,MaterialNames,WorkStepNames)', $recipeSheet);
        $this->assertStringContainsString('Strap', $listsSheet);
        $this->assertStringContainsString('Sole', $listsSheet);
        $this->assertStringContainsString('DSI sheet', $listsSheet);
        $this->assertStringContainsString('Bottom labour', $listsSheet);
        $this->assertStringContainsString('SLP-001', $listsSheet);
        $this->assertStringContainsString('<definedName name="PartNames">', $workbook);
        $this->assertStringContainsString('<definedName name="MaterialNames">', $workbook);

        @unlink($path);
    }

    public function test_owner_can_delete_all_recipe_lines_for_one_sku_without_deleting_the_product(): void
    {
        $business = Business::query()->create([
            'name' => 'Factory Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'recipe-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'PS364',
            'name' => 'Classic Bag',
            'expected_sale_price' => 1200,
        ]);

        SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'line_type' => SkuRecipeItem::TYPE_RAW_MATERIAL,
            'component_name' => 'Rexine',
            'quantity_per_unit' => 1,
            'unit_cost' => 49.12,
            'active' => true,
        ]);

        SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'line_type' => SkuRecipeItem::TYPE_RAW_MATERIAL,
            'component_name' => 'DSI Sheet',
            'quantity_per_unit' => 1,
            'unit_cost' => 201.75,
            'active' => true,
        ]);

        Livewire::actingAs($owner)
            ->test(ListSkuRecipeItems::class)
            ->callAction('deleteSkuRecipe', data: [
                'business_id' => $business->id,
                'sku_id' => $sku->id,
            ]);

        $this->assertDatabaseHas('skus', [
            'id' => $sku->id,
            'code' => 'PS364',
        ]);
        $this->assertDatabaseMissing('sku_recipe_items', [
            'sku_id' => $sku->id,
        ]);
    }
}

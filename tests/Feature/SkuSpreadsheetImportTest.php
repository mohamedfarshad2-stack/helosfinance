<?php

namespace Tests\Feature;

use App\Domains\Manufacturing\Services\SkuSpreadsheetImportService;
use App\Domains\Manufacturing\Services\SkuUploadTemplateExportService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\SkuResource\Pages\CreateSku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class SkuSpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_products_without_requiring_duplicate_cost_columns(): void
    {
        $business = Business::query()->create([
            'name' => 'Factory Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'skus_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'business_name,code,name,expected_sale_price,active',
            'Factory Client,SLP-001,Black Slipper Size 8,1200,yes',
            'Factory Client,SLP-002,Brown Slipper Size 9,1350,yes',
        ]));

        $result = app(SkuSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([], $result['skipped_reasons']);
        $this->assertDatabaseCount('skus', 2);

        $sku = Sku::query()->where('code', 'SLP-001')->firstOrFail();

        $this->assertSame('Black Slipper Size 8', $sku->name);
        $this->assertSame(1200.0, (float) $sku->expected_sale_price);
        $this->assertSame(0.0, (float) $sku->material_cost);

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_it_skips_product_rows_for_the_wrong_business(): void
    {
        $business = Business::query()->create([
            'name' => 'Factory Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'skus_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'business_name,code,name,expected_sale_price,active',
            'Other Business,SLP-001,Black Slipper Size 8,1200,yes',
        ]));

        $result = app(SkuSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString("Business 'Other Business' does not match selected business 'Factory Client'", $result['skipped_reasons'][0]);
        $this->assertDatabaseCount('skus', 0);

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_it_exports_product_upload_template_with_business_dropdown(): void
    {
        $business = Business::query()->create([
            'name' => 'Factory Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $otherBusiness = Business::query()->create([
            'name' => 'ShoeHub SL',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_3,
            'onboarding_status' => 'ready',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'sku_upload_template_').'.xlsx';

        app(SkuUploadTemplateExportService::class)->export($business, [
            $business->id => $business->name,
            $otherBusiness->id => $otherBusiness->name,
        ], $path);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        $productSheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $listsSheet = $zip->getFromName('xl/worksheets/sheet2.xml');
        $workbook = $zip->getFromName('xl/workbook.xml');

        $zip->close();

        $this->assertStringContainsString('<dataValidations count="2">', $productSheet);
        $this->assertStringContainsString('BusinessNames', $productSheet);
        $this->assertStringContainsString('Factory Client', $listsSheet);
        $this->assertStringContainsString('ShoeHub SL', $listsSheet);
        $this->assertStringContainsString('<definedName name="BusinessNames">', $workbook);

        @unlink($path);
    }

    public function test_owner_can_create_sku_manually_and_duplicate_code_is_validated(): void
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
            'email' => 'sku-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        Livewire::actingAs($owner)
            ->test(CreateSku::class)
            ->fillForm([
                'business_id' => $business->id,
                'code' => 'PS364',
                'name' => 'Classic Bag',
                'expected_sale_price' => 1200,
                'active' => true,
                'material_cost' => 0,
                'packaging_cost' => 0,
                'labor_rate' => 0,
                'finishing_cost' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('skus', [
            'business_id' => $business->id,
            'code' => 'PS364',
        ]);

        Livewire::actingAs($owner)
            ->test(CreateSku::class)
            ->fillForm([
                'business_id' => $business->id,
                'code' => 'PS364',
                'name' => 'Duplicate Bag',
                'expected_sale_price' => 1300,
                'active' => true,
                'material_cost' => 0,
                'packaging_cost' => 0,
                'labor_rate' => 0,
                'finishing_cost' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);
    }
}

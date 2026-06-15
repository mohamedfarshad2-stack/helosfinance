<?php

namespace Tests\Feature;

use App\Domains\Manufacturing\Services\SkuSpreadsheetImportService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
}

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
            'code,name,expected_sale_price,active',
            'SLP-001,Black Slipper Size 8,1200,yes',
            'SLP-002,Brown Slipper Size 9,1350,yes',
        ]));

        $result = app(SkuSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertDatabaseCount('skus', 2);

        $sku = Sku::query()->where('code', 'SLP-001')->firstOrFail();

        $this->assertSame('Black Slipper Size 8', $sku->name);
        $this->assertSame(1200.0, (float) $sku->expected_sale_price);
        $this->assertSame(0.0, (float) $sku->material_cost);

        @unlink($csvPath);
        @unlink($path);
    }
}

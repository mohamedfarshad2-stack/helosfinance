<?php

namespace Tests\Feature;

use App\Domains\Manufacturing\Services\MaterialComponentSpreadsheetImportService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialComponentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_material_components_and_calculates_stock_balance(): void
    {
        $business = Business::query()->create([
            'name' => 'Factory Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'material_components_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'name,purchase_unit,purchase_unit_cost,units_per_purchase_unit,waste_percent,consumption_unit,active,note',
            'DSI sheet,sheet,1200,12,5,piece,yes,',
            'Glue,bottle,400,10,0,use,yes,',
        ]));

        $result = app(MaterialComponentSpreadsheetImportService::class)->import($business, $csvPath);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertDatabaseCount('material_components', 2);

        $component = MaterialComponent::query()->where('name', 'DSI sheet')->firstOrFail();

        MaterialLedgerEntry::query()->create([
            'business_id' => $business->id,
            'material_component_id' => $component->id,
            'entry_type' => 'purchase',
            'component_name' => $component->name,
            'quantity' => 10,
            'unit_cost' => 1200,
            'total_cost' => 12000,
            'occurred_on' => now()->toDateString(),
        ]);

        MaterialLedgerEntry::query()->create([
            'business_id' => $business->id,
            'material_component_id' => $component->id,
            'entry_type' => 'consumption',
            'component_name' => $component->name,
            'quantity' => 80,
            'unit_cost' => 105.26,
            'total_cost' => 8420.80,
            'occurred_on' => now()->toDateString(),
        ]);

        $this->assertSame(114.0, $component->fresh()->purchasedConsumptionUnits());
        $this->assertSame(34.0, $component->fresh()->stockBalance());

        @unlink($csvPath);
        @unlink($path);
    }
}

<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\CapitalIntelligenceService;
use App\Domains\FinancialClarity\Services\BusinessExplainabilityService;
use App\Domains\FinancialClarity\Services\InventoryIntelligenceService;
use App\Domains\Manufacturing\Services\ProductionCostService;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapitalAndInventoryIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_capital_and_inventory_intelligence_use_the_same_verified_truth_layer(): void
    {
        $business = Business::query()->create([
            'name' => 'Manufacturing Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SKU-100',
            'name' => 'Test SKU',
            'material_cost' => 1000,
            'packaging_cost' => 100,
            'labor_rate' => 200,
            'finishing_cost' => 50,
            'expected_sale_price' => 3000,
        ]);

        SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'component_name' => 'Fabric',
            'quantity_per_unit' => 2,
            'unit_cost' => 250,
            'active' => true,
        ]);

        MaterialLedgerEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'entry_type' => 'purchase',
            'component_name' => 'Fabric',
            'quantity' => 10,
            'unit_cost' => 250,
            'total_cost' => 2500,
            'occurred_on' => now()->toDateString(),
        ]);

        MaterialLedgerEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'entry_type' => 'consumption',
            'component_name' => 'Fabric',
            'quantity' => 5,
            'unit_cost' => 250,
            'total_cost' => 1250,
            'occurred_on' => now()->toDateString(),
        ]);

        MaterialLedgerEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'entry_type' => 'waste',
            'component_name' => 'Fabric',
            'quantity' => 1,
            'unit_cost' => 250,
            'total_cost' => 250,
            'occurred_on' => now()->toDateString(),
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'department' => 'Ops',
            'category' => 'Courier',
            'expense_type' => 'variable',
            'description' => 'Pending courier cost',
            'amount' => 1500,
            'payment_status' => 'partial',
            'paid_amount' => 500,
            'spent_on' => now()->toDateString(),
            'payee' => 'Courier Partner',
        ]);

        Employee::query()->create([
            'business_id' => $business->id,
            'name' => 'Fixed Staff',
            'role' => 'Supervisor',
            'monthly_salary' => 1200,
            'pay_cycle' => 'monthly',
            'active' => true,
        ]);

        ProductionEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'employee_name' => 'Worker One',
            'quantity_produced' => 20,
            'waste_quantity' => 2,
            'employee_payout' => 900,
            'advance_amount' => 100,
            'deduction_amount' => 50,
            'net_payable' => 750,
            'payment_status' => 'pending',
            'estimated_total_cost' => 1500,
            'produced_on' => now()->toDateString(),
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Opening balance',
            'debit' => 3000,
            'credit' => 7000,
            'classification' => 'unknown',
            'confidence' => 0,
            'status' => 'review',
            'row_hash' => sha1('opening-balance'),
        ]);

        $capital = app(CapitalIntelligenceService::class)->forCurrentMonth($business);
        $inventory = app(InventoryIntelligenceService::class)->forCurrentMonth($business);

        $this->assertTrue($capital['activated']);
        $this->assertGreaterThan(0, $capital['capital_committed']);
        $this->assertLessThan(0, $capital['value_signal']);
        $this->assertNotEmpty($capital['actions']);

        $this->assertTrue($inventory['activated']);
        $this->assertSame(100, $inventory['recipe_coverage']);
        $this->assertSame(1250.0, $inventory['material_consumption_total']);
        $this->assertSame(250.0, $inventory['material_waste_total']);
        $this->assertNotEmpty($inventory['actions']);
    }

    public function test_explainability_service_handles_missing_sku_metrics_without_crashing(): void
    {
        $business = Business::query()->create([
            'name' => 'Service Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'ready',
        ]);

        $explainability = app(BusinessExplainabilityService::class)->forCurrentMonth($business);

        $this->assertIsArray($explainability);
        $this->assertArrayHasKey('what_happened', $explainability);
        $this->assertArrayHasKey('why_it_happened', $explainability);
        $this->assertArrayHasKey('briefing', $explainability);
        $this->assertArrayHasKey('decision_story', $explainability);
        $this->assertNull($explainability['top_revenue_sku']);
        $this->assertNull($explainability['top_loss_sku']);
    }

    public function test_production_cost_service_uses_recipe_labor_lines_for_pay_calculation(): void
    {
        $business = Business::query()->create([
            'name' => 'Factory Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SLP-001',
            'name' => 'Slipper A',
            'material_cost' => 500,
            'packaging_cost' => 50,
            'labor_rate' => 0,
            'finishing_cost' => 25,
            'expected_sale_price' => 1200,
        ]);

        SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'line_type' => SkuRecipeItem::TYPE_RAW_MATERIAL,
            'component_name' => 'Rubber sheet',
            'quantity_per_unit' => 1,
            'unit_cost' => 300,
            'active' => true,
        ]);

        SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'line_type' => SkuRecipeItem::TYPE_LABOR,
            'component_name' => 'Stitching labor',
            'quantity_per_unit' => 2,
            'unit_cost' => 100,
            'active' => true,
        ]);

        $entry = app(ProductionCostService::class)->record($sku, [
            'employee_name' => 'Worker One',
            'quantity_produced' => 5,
            'advance_amount' => 50,
            'deduction_amount' => 25,
            'produced_on' => now()->toDateString(),
        ]);

        $this->assertSame(1000.0, (float) $entry->employee_payout);
        $this->assertSame(925.0, (float) $entry->net_payable);
        $this->assertSame(5, $entry->quantity_produced);
        $this->assertSame(2875.0, (float) $entry->estimated_total_cost);
    }

    public function test_production_cost_service_can_pay_one_selected_piece_work_step(): void
    {
        $business = Business::query()->create([
            'name' => 'Piece Work Factory',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'STRAP-001',
            'name' => 'Slipper Strap',
            'material_cost' => 20,
            'packaging_cost' => 0,
            'labor_rate' => 0,
            'finishing_cost' => 0,
            'expected_sale_price' => 80,
        ]);

        SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'line_type' => SkuRecipeItem::TYPE_LABOR,
            'component_name' => 'Cutting',
            'quantity_per_unit' => 1,
            'unit_cost' => 4,
            'active' => true,
        ]);

        $stitching = SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'line_type' => SkuRecipeItem::TYPE_LABOR,
            'component_name' => 'Strap stitching',
            'quantity_per_unit' => 1,
            'unit_cost' => 12,
            'active' => true,
        ]);

        $entry = app(ProductionCostService::class)->record($sku, [
            'employee_name' => 'Stitching Worker',
            'sku_recipe_item_id' => $stitching->id,
            'quantity_produced' => 100,
            'produced_on' => now()->toDateString(),
        ]);

        $this->assertSame('Strap stitching', $entry->production_step);
        $this->assertSame(12.0, (float) $entry->piece_rate);
        $this->assertSame(1200.0, (float) $entry->employee_payout);
        $this->assertSame(1200.0, (float) $entry->net_payable);
    }
}

<?php

namespace Tests\Feature;

use App\Domains\Manufacturing\Services\PartWipBalanceService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\ProductionWorkStep;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use App\Filament\Resources\ProductionEntryResource;
use App\Filament\Resources\ProductionEntryResource\Pages\CreateProductionEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class PartWipProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_production_pay_is_separate_from_finished_product_completion(): void
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
            'code' => 'PS364',
            'name' => 'Classic Slipper',
            'expected_sale_price' => 1200,
        ]);

        $workStep = ProductionWorkStep::query()->create([
            'business_id' => $business->id,
            'name' => 'Strap stitching labour',
            'unit_cost' => 35,
            'active' => true,
        ]);

        $recipeLine = SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'line_type' => SkuRecipeItem::TYPE_LABOR,
            'part_name' => 'Strap',
            'component_name' => $workStep->name,
            'production_work_step_id' => $workStep->id,
            'quantity_per_unit' => 1,
            'unit_cost' => 35,
            'active' => true,
        ]);

        ProductionEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'sku_recipe_item_id' => $recipeLine->id,
            'production_kind' => 'part_production',
            'part_name' => 'Strap',
            'employee_name' => 'Fathima',
            'production_step' => 'Strap stitching labour',
            'piece_rate' => 35,
            'quantity_produced' => 100,
            'waste_quantity' => 0,
            'employee_payout' => 3500,
            'advance_amount' => 0,
            'deduction_amount' => 0,
            'net_payable' => 3500,
            'payment_status' => 'pending',
            'produced_on' => now()->toDateString(),
        ]);

        ProductionEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'production_kind' => 'finished_product',
            'part_name' => null,
            'employee_name' => 'Assembly team',
            'production_step' => 'Finished slipper completion',
            'piece_rate' => 0,
            'quantity_produced' => 20,
            'waste_quantity' => 0,
            'employee_payout' => 0,
            'advance_amount' => 0,
            'deduction_amount' => 0,
            'net_payable' => 0,
            'payment_status' => 'pending',
            'produced_on' => now()->toDateString(),
        ]);

        $balance = app(PartWipBalanceService::class)->forSku($sku);

        $this->assertSame('Strap', $balance[0]['part_name']);
        $this->assertSame(100.0, $balance[0]['produced']);
        $this->assertSame(20.0, $balance[0]['consumed']);
        $this->assertSame(80.0, $balance[0]['balance']);
        $this->assertSame(3500.0, (float) ProductionEntry::query()->where('employee_name', 'Fathima')->sum('net_payable'));
    }

    public function test_production_screen_exposes_part_wip_workflow(): void
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
            'email' => 'wip-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $this->actingAs($owner)
            ->get(ProductionEntryResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Part WIP balance');

        $this->get(ProductionEntryResource::getUrl('create'))
            ->assertOk()
            ->assertSee('What was produced?')
            ->assertSee('Product part');
    }

    public function test_production_form_auto_selects_single_matching_work_step_and_sets_paid_date(): void
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
            'email' => 'production-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
            'is_employee' => false,
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'PS364',
            'name' => 'Classic Slipper',
            'expected_sale_price' => 1200,
        ]);

        $employee = \App\Domains\Shared\Models\Employee::query()->create([
            'business_id' => $business->id,
            'name' => 'Fathima',
            'role' => 'Production',
            'active' => true,
        ]);

        $workStep = ProductionWorkStep::query()->create([
            'business_id' => $business->id,
            'name' => 'Strap stitching labour',
            'unit_cost' => 35,
            'active' => true,
        ]);

        $recipeLine = SkuRecipeItem::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'line_type' => SkuRecipeItem::TYPE_LABOR,
            'part_name' => 'Strap',
            'component_name' => $workStep->name,
            'production_work_step_id' => $workStep->id,
            'quantity_per_unit' => 1,
            'unit_cost' => 35,
            'active' => true,
        ]);

        $this->actingAs($owner);

        Livewire::test(CreateProductionEntry::class)
            ->fillForm([
                'business_id' => $business->id,
                'sku_id' => $sku->id,
                'production_kind' => 'part_production',
                'part_name' => 'Strap',
                'employee_name' => $employee->name,
                'quantity_produced' => 10,
                'payment_status' => 'paid',
                'produced_on' => now()->toDateString(),
            ])
            ->assertFormSet([
                'sku_recipe_item_id' => $recipeLine->id,
                'production_step' => 'Strap stitching labour',
                'piece_rate' => 35.0,
                'employee_payout' => 350.0,
                'net_payable' => 350.0,
                'paid_on' => now()->toDateString(),
            ]);
    }
}

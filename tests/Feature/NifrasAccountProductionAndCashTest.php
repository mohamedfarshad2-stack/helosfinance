<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\Sku;
use App\Filament\Pages\NifrasAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class NifrasAccountProductionAndCashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_nifras_sees_production_and_petty_cash_workspaces_on_the_account_page(): void
    {
        $business = Business::query()->create([
            'name' => 'ShoeHub Manufacturing',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $sku = Sku::query()->create([
            'business_id' => $business->id,
            'code' => 'SLP-001',
            'name' => 'Classic Slipper',
            'expected_sale_price' => 1200,
            'active' => true,
        ]);

        ProductionEntry::query()->create([
            'business_id' => $business->id,
            'sku_id' => $sku->id,
            'production_kind' => 'part_production',
            'part_name' => 'Sole',
            'employee_name' => 'Saman',
            'production_step' => 'Sole labour',
            'piece_rate' => 35,
            'quantity_produced' => 120,
            'waste_quantity' => 0,
            'employee_payout' => 4200,
            'advance_amount' => 0,
            'deduction_amount' => 0,
            'net_payable' => 4200,
            'payment_status' => 'pending',
            'produced_on' => now()->toDateString(),
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'category' => 'Fuel',
            'expense_type' => 'variable',
            'amount' => 1500,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'paid_amount' => 1500,
            'allocation_bucket' => 'company_expense',
            'spent_on' => now()->toDateString(),
        ]);

        $nifras = User::query()->create([
            'name' => 'Nifras',
            'email' => 'nifras@helos.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
        ]);

        Livewire::actingAs($nifras)
            ->test(NifrasAccount::class)
            ->assertSee('Products and production')
            ->assertSee('Petty cash')
            ->assertSee('Entries today')
            ->assertSee('Rows this month')
            ->assertSee('Saman')
            ->assertSee('Fuel');
    }
}

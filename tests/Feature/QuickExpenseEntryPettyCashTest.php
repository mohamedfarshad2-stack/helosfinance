<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Expense;
use App\Filament\Pages\QuickExpenseEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class QuickExpenseEntryPettyCashTest extends TestCase
{
    use RefreshDatabase;

    public function test_petty_cash_shows_only_quick_spends_and_live_balance(): void
    {
        $business = Business::query()->create([
            'name' => 'ShoeHub Wholesale',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => false,
            'is_platform_admin' => false,
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'statement_name' => 'Test statement',
            'transaction_date' => now()->toDateString(),
            'description' => 'Move cash to petty cash',
            'money_container' => 'Current Account',
            'counter_money_container' => 'Petty Cash',
            'debit' => 1000,
            'credit' => 0,
            'balance' => 0,
            'classification' => 'petty_cash',
            'transaction_type' => 'transfer',
            'status' => 'classified',
            'confidence' => 1,
            'raw_payload' => [],
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'category' => 'Fuel',
            'expense_type' => 'variable',
            'description' => 'Quick cash spend',
            'amount' => 200,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'paid_amount' => 200,
            'allocation_bucket' => 'company_expense',
            'spent_on' => now()->toDateString(),
            'recurring' => false,
        ]);

        Expense::query()->create([
            'business_id' => $business->id,
            'category' => 'Rent',
            'expense_type' => 'fixed',
            'description' => 'Fixed expense that should not appear here',
            'amount' => 500,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'paid_amount' => 500,
            'allocation_bucket' => 'company_expense',
            'spent_on' => now()->toDateString(),
            'recurring' => true,
        ]);

        $response = $this->actingAs($owner)->get(QuickExpenseEntry::getUrl());

        $response->assertOk();
        $response->assertSee('LKR 1,000.00');
        $response->assertSee('LKR 200.00');
        $response->assertSee('LKR 800.00');
        $response->assertSee('Quick cash spend');
        $response->assertDontSee('Fixed expense that should not appear here');
    }
}

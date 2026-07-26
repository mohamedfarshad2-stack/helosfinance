<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\BankStatementImportService;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Services\WorkQueueService;
use App\Filament\Pages\ClientHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use App\Models\User;

class SharedTreasuryTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_import_records_the_selected_money_container(): void
    {
        $business = Business::query()->create([
            'name' => 'Treasury Import Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'bank_statement_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'Transaction Date,Narrative,Withdrawal,Deposit,Running Balance',
            '2026-06-01,Customer payment,,500,10500',
        ]));

        app(BankStatementImportService::class)->import($business, $csvPath, null, 'Current Account');

        $transaction = BankTransaction::query()->first();

        $this->assertSame('Current Account', $transaction?->money_container);

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_store_cash_is_available_as_a_treasury_container_default(): void
    {
        $this->assertContains('Store Cash / Cash Drawer', BankTransaction::treasuryContainerDefaults());
    }

    public function test_transfer_rows_stay_out_of_business_allocation_tasks_when_destination_is_known(): void
    {
        $business = Business::query()->create([
            'name' => 'Treasury Queue Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => today()->toDateString(),
            'description' => 'Sales receipt',
            'money_container' => 'Current Account',
            'debit' => 0,
            'credit' => 10000,
            'classification' => 'revenue',
            'transaction_type' => 'revenue',
            'status' => 'classified',
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => today()->toDateString(),
            'description' => 'Move to savings',
            'money_container' => 'Current Account',
            'counter_money_container' => 'Savings Account',
            'debit' => 2500,
            'credit' => 0,
            'classification' => 'transfer',
            'transaction_type' => 'transfer',
            'status' => 'classified',
        ]);

        $workQueue = app(WorkQueueService::class)->forBusiness($business);
        $taskIds = collect($workQueue['tasks'])->pluck('id')->all();

        $this->assertContains('bank-missing-business-'.BankTransaction::query()->where('description', 'Sales receipt')->value('id'), $taskIds);
        $this->assertNotContains('bank-missing-business-'.BankTransaction::query()->where('description', 'Move to savings')->value('id'), $taskIds);
        $this->assertNotContains('bank-transfer-destination-'.BankTransaction::query()->where('description', 'Move to savings')->value('id'), $taskIds);
    }

    public function test_owner_dashboard_shows_treasury_picture_and_shared_rows(): void
    {
        $business = Business::query()->create([
            'name' => 'Treasury Dashboard Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $user = User::query()->create([
            'name' => 'Client Owner',
            'email' => 'treasury-owner@example.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_platform_admin' => false,
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => today()->toDateString(),
            'description' => 'Paid order',
            'money_container' => 'Current Account',
            'debit' => 0,
            'credit' => 15000,
            'classification' => 'revenue',
            'transaction_type' => 'revenue',
            'allocated_business_id' => $business->id,
            'status' => 'classified',
        ]);

        BankTransaction::query()->create([
            'business_id' => $business->id,
            'transaction_date' => today()->toDateString(),
            'description' => 'Shared supplier bill',
            'money_container' => 'Petty Cash',
            'debit' => 2500,
            'credit' => 0,
            'classification' => 'expense',
            'transaction_type' => 'expense',
            'status' => 'review',
        ]);

        $this->actingAs($user)
            ->get(ClientHealthReport::getUrl())
            ->assertOk()
            ->assertSee('Owner parcel dashboard')
            ->assertDontSee('Treasury picture');
    }
}

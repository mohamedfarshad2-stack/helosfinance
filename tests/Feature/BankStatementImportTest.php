<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\BankStatementImportService;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_common_bank_export_headers(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        $path = tempnam(sys_get_temp_dir(), 'bank_statement_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'Transaction Date,Narrative,Withdrawal,Deposit,Running Balance',
            '2026-05-01,Tea and snacks,250,,9750',
            '2026-05-02,Customer payment,,500,10250',
        ]));

        $result = app(BankStatementImportService::class)->import($business, $csvPath);

        $this->assertSame(2, $result['created'] + $result['reviewed']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseCount('bank_transactions', 2);

        $first = BankTransaction::query()->orderBy('transaction_date')->first();

        $this->assertSame('Tea and snacks', $first?->description);
        $this->assertSame(250.0, (float) $first?->debit);
        $this->assertSame(0.0, (float) $first?->credit);

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_it_skips_metadata_rows_and_reads_real_bank_header_line(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        $path = tempnam(sys_get_temp_dir(), 'bank_statement_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            ',,,,Transaction History,,,,,,,',
            ',,,,,,,,,,e-Receipt,',
            ',Transaction Date,,Description,,Currency,Debit,Credit,Running Balance,,,',
            ',02/06/2026,,Tea and snacks,,LKR,250.00,,9,750.00,,,',
            ',02/06/2026,,Customer payment,,LKR,,500.00,10,250.00,,,',
        ]));

        $result = app(BankStatementImportService::class)->import($business, $csvPath);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['reviewed']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseCount('bank_transactions', 2);

        $first = BankTransaction::query()->orderBy('transaction_date')->first();

        $this->assertSame('Tea and snacks', $first?->description);
        $this->assertSame(250.0, (float) $first?->debit);
        $this->assertSame(0.0, (float) $first?->credit);

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_it_parses_day_first_bank_dates_without_guessing_wrong_month(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        $path = tempnam(sys_get_temp_dir(), 'bank_statement_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'Transaction Date,Narrative,Withdrawal,Deposit,Running Balance',
            '29/05/2026,Card payment,1000,,5000',
        ]));

        app(BankStatementImportService::class)->import($business, $csvPath);

        $transaction = BankTransaction::query()->first();

        $this->assertSame('2026-05-29', optional($transaction?->transaction_date)->toDateString());

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_it_skips_duplicate_rows_when_the_same_statement_is_uploaded_again(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        $path = tempnam(sys_get_temp_dir(), 'bank_statement_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'Transaction Date,Narrative,Withdrawal,Deposit,Running Balance',
            '29/05/2026,Card payment,1000,,5000',
            '29/05/2026,Card payment,1000,,5000',
        ]));

        $result = app(BankStatementImportService::class)->import($business, $csvPath);

        $this->assertSame(1, $result['created'] + $result['reviewed']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertDatabaseCount('bank_transactions', 1);

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_it_auto_sets_transaction_effect_and_business_for_clear_expense_rows(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        $path = tempnam(sys_get_temp_dir(), 'bank_statement_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'Transaction Date,Narrative,Withdrawal,Deposit,Running Balance',
            '29/05/2026,Supplier payment for material,1000,,5000',
        ]));

        app(BankStatementImportService::class)->import($business, $csvPath);

        $transaction = BankTransaction::query()->first();

        $this->assertSame('supplier_payment', $transaction?->classification);
        $this->assertSame('expense', $transaction?->transaction_type);
        $this->assertSame($business->id, $transaction?->allocated_business_id);
        $this->assertSame('classified', $transaction?->status);

        @unlink($csvPath);
        @unlink($path);
    }

    public function test_it_keeps_possible_transfers_in_review_until_destination_is_confirmed(): void
    {
        $business = Business::query()->create(['name' => 'Test Business']);
        $path = tempnam(sys_get_temp_dir(), 'bank_statement_');
        $csvPath = $path.'.csv';

        file_put_contents($csvPath, implode(PHP_EOL, [
            'Transaction Date,Narrative,Withdrawal,Deposit,Running Balance',
            '29/05/2026,Transfer to savings,1000,,5000',
        ]));

        app(BankStatementImportService::class)->import($business, $csvPath);

        $transaction = BankTransaction::query()->first();

        $this->assertSame('transfer', $transaction?->classification);
        $this->assertSame('transfer', $transaction?->transaction_type);
        $this->assertNull($transaction?->allocated_business_id);
        $this->assertSame('review', $transaction?->status);

        @unlink($csvPath);
        @unlink($path);
    }
}

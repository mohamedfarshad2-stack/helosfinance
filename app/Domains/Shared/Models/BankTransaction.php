<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BankTransaction extends Model
{
    protected $fillable = [
        'business_id',
        'integration_source_id',
        'statement_name',
        'row_hash',
        'transaction_date',
        'description',
        'money_container',
        'counter_money_container',
        'debit',
        'credit',
        'balance',
        'classification',
        'transaction_type',
        'allocated_business_id',
        'confidence',
        'rule_key',
        'status',
        'raw_payload',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'reviewed_at' => 'datetime',
            'raw_payload' => 'array',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance' => 'decimal:2',
            'confidence' => 'decimal:2',
        ];
    }

    public static function treasuryContainerDefaults(): array
    {
        return [
            'Current Account',
            'Savings Account',
            'Petty Cash',
            'Store Cash / Cash Drawer',
            'Shared / Unallocated',
        ];
    }

    public static function classificationOptions(): array
    {
        return [
            'unknown' => 'Unknown',
            'revenue' => 'Revenue',
            'cod_settlement' => 'COD settlement',
            'expense' => 'Expense',
            'salary' => 'Salary',
            'supplier_payment' => 'Supplier payment',
            'courier' => 'Courier',
            'fuel' => 'Fuel',
            'packing' => 'Packing',
            'marketing' => 'Marketing',
            'rent' => 'Rent',
            'utility' => 'Utility',
            'bank_charge' => 'Bank charge',
            'owner_contribution' => 'Owner contribution',
            'owner_withdrawal' => 'Owner withdrawal',
            'loan' => 'Loan',
            'transfer' => 'Transfer',
            'petty_cash' => 'Petty cash funding',
            'maintenance' => 'Maintenance',
        ];
    }

    public static function transactionTypeOptions(): array
    {
        return [
            'revenue' => 'Revenue',
            'cod_settlement' => 'COD settlement',
            'expense' => 'Expense',
            'transfer' => 'Transfer',
            'owner_contribution' => 'Owner contribution',
            'owner_withdrawal' => 'Owner withdrawal',
            'loan' => 'Loan',
            'other' => 'Other',
        ];
    }

    public static function inferTransactionType(?string $classification): ?string
    {
        return match ($classification) {
            'revenue' => 'revenue',
            'cod_settlement' => 'cod_settlement',
            'owner_contribution' => 'owner_contribution',
            'owner_withdrawal' => 'owner_withdrawal',
            'loan' => 'loan',
            'transfer', 'petty_cash' => 'transfer',
            'expense', 'salary', 'supplier_payment', 'courier', 'fuel', 'packing', 'marketing', 'rent', 'utility', 'bank_charge', 'maintenance' => 'expense',
            default => null,
        };
    }

    public static function needsBusinessAssignment(?string $transactionType): bool
    {
        return in_array($transactionType, ['revenue', 'cod_settlement', 'expense', 'loan', 'owner_contribution', 'owner_withdrawal'], true);
    }

    public function setMoneyContainerAttribute(mixed $value): void
    {
        $this->attributes['money_container'] = filled($value) ? Str::squish((string) $value) : null;
    }

    public function setCounterMoneyContainerAttribute(mixed $value): void
    {
        $this->attributes['counter_money_container'] = filled($value) ? Str::squish((string) $value) : null;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function integrationSource(): BelongsTo
    {
        return $this->belongsTo(IntegrationSource::class);
    }

    public function allocatedBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'allocated_business_id');
    }
}

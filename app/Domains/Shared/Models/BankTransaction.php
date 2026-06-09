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

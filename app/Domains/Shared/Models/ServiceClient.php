<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceClient extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_STOPPED = 'stopped';

    public const BILLING_FIXED_MONTHLY = 'fixed_monthly';
    public const BILLING_VARIABLE_MONTHLY = 'variable_monthly';
    public const BILLING_ONE_TIME = 'one_time';

    protected $fillable = [
        'business_id',
        'name',
        'status',
        'billing_style',
        'default_monthly_amount',
        'default_registration_fee',
        'default_due_day',
        'active_from',
        'inactive_from',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'default_monthly_amount' => 'decimal:2',
            'default_registration_fee' => 'decimal:2',
            'default_due_day' => 'integer',
            'active_from' => 'date',
            'inactive_from' => 'date',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_PAUSED => 'Paused',
            self::STATUS_STOPPED => 'Stopped',
        ];
    }

    public static function billingStyleOptions(): array
    {
        return [
            self::BILLING_FIXED_MONTHLY => 'Fixed monthly',
            self::BILLING_VARIABLE_MONTHLY => 'Variable monthly',
            self::BILLING_ONE_TIME => 'One-time / irregular',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function billingRecords(): HasMany
    {
        return $this->hasMany(ServiceBillingRecord::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function usesVariableBilling(): bool
    {
        return $this->billing_style === self::BILLING_VARIABLE_MONTHLY;
    }
}

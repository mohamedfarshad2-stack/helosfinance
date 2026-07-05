<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceBillingRecord extends Model
{
    public const TYPE_REGISTRATION = 'registration';
    public const TYPE_SUBSCRIPTION = 'subscription';
    public const TYPE_SERVICE_FEE = 'service_fee';
    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'business_id',
        'service_client_id',
        'client_name',
        'billing_type',
        'period_start',
        'period_end',
        'amount_due',
        'paid_amount',
        'payment_status',
        'due_on',
        'paid_on',
        'payment_method',
        'reference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount_due' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_on' => 'date',
            'paid_on' => 'date',
        ];
    }

    public static function billingTypeOptions(): array
    {
        return [
            self::TYPE_REGISTRATION => 'Registration fee',
            self::TYPE_SUBSCRIPTION => 'Monthly subscription',
            self::TYPE_SERVICE_FEE => 'Service fee',
            self::TYPE_OTHER => 'Other',
        ];
    }

    public static function paymentStatusOptions(): array
    {
        return [
            'unpaid' => 'Unpaid',
            'partial' => 'Part paid',
            'paid' => 'Paid',
            'overdue' => 'Overdue',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function serviceClient(): BelongsTo
    {
        return $this->belongsTo(ServiceClient::class);
    }

    public function balanceDue(): float
    {
        return max((float) $this->amount_due - (float) $this->paid_amount, 0);
    }
}

<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodOrder extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_NO_ANSWER = 'no_answer';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_COURIER_PENDING = 'courier_pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_RESENT = 'resent';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'business_id',
        'sku_id',
        'cod_order_source_id',
        'csr_employee_id',
        'order_number',
        'customer_name',
        'customer_phone',
        'customer_alt_phone',
        'address',
        'city',
        'district',
        'size',
        'quantity',
        'sale_amount',
        'status',
        'call_attempts',
        'confirmation_remark',
        'confirmation_reason',
        'delivery_instruction',
        'courier_name',
        'tracking_number',
        'resend_from_stock',
        'delivery_charge',
        'return_charge',
        'resend_charge',
        'return_reason',
        'resend_reason',
        'collected_amount',
        'order_date',
        'dispatched_on',
        'delivered_on',
        'returned_on',
        'uploaded_at',
        'confirmed_at',
        'dispatched_at',
        'preferred_delivery_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'call_attempts' => 'integer',
            'resend_from_stock' => 'boolean',
            'sale_amount' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'return_charge' => 'decimal:2',
            'resend_charge' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'order_date' => 'date',
            'dispatched_on' => 'date',
            'delivered_on' => 'date',
            'returned_on' => 'date',
            'uploaded_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'preferred_delivery_at' => 'datetime',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'New / calling',
            self::STATUS_CONFIRMED => 'Confirmed',
            self::STATUS_NO_ANSWER => 'No answer',
            self::STATUS_DISPATCHED => 'Dispatched',
            self::STATUS_COURIER_PENDING => 'Courier pending',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_RETURNED => 'Returned',
            self::STATUS_RESENT => 'Resent',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public static function returnReasonOptions(): array
    {
        return [
            'no_answer' => 'No answer',
            'customer_rejected' => 'Customer rejected',
            'wrong_size' => 'Wrong size',
            'wrong_address' => 'Wrong address',
            'courier_delay' => 'Courier delay',
            'damaged' => 'Damaged',
            'other' => 'Other',
        ];
    }

    public static function resendReasonOptions(): array
    {
        return [
            'customer_request' => 'Customer request',
            'wrong_address' => 'Wrong address',
            'courier_returned' => 'Courier returned',
            'damaged' => 'Damaged parcel',
            'other' => 'Other',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function orderSource(): BelongsTo
    {
        return $this->belongsTo(CodOrderSource::class, 'cod_order_source_id');
    }

    public function csrEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'csr_employee_id');
    }

    public function externalId(): string
    {
        return $this->order_number ?: 'HELOS-COD-'.$this->id;
    }

    public function totalPrice(): float
    {
        return (float) $this->sale_amount + (float) $this->delivery_charge;
    }
}

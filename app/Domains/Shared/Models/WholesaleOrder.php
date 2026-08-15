<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WholesaleOrder extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_BOOKED = 'booked';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PARTIAL = 'partial';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_CREDIT_DUE = 'credit_due';

    public const DELIVERY_PICKUP = 'pickup';
    public const DELIVERY_COURIER = 'courier';
    public const DELIVERY_TRANSPORT = 'transport';

    protected $fillable = [
        'business_id',
        'order_number',
        'customer_name',
        'customer_phone',
        'customer_location',
        'order_date',
        'status',
        'payment_status',
        'payment_method',
        'delivery_method',
        'courier_name',
        'line_items',
        'gross_sale_amount',
        'discount_amount',
        'delivery_charge_charged',
        'courier_cost_amount',
        'product_cost_amount',
        'net_sales_amount',
        'paid_amount',
        'gross_profit_amount',
        'next_follow_up_at',
        'reorder_due_at',
        'delivered_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'next_follow_up_at' => 'datetime',
            'reorder_due_at' => 'datetime',
            'delivered_at' => 'datetime',
            'line_items' => 'array',
            'gross_sale_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'delivery_charge_charged' => 'decimal:2',
            'courier_cost_amount' => 'decimal:2',
            'product_cost_amount' => 'decimal:2',
            'net_sales_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'gross_profit_amount' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_BOOKED => 'Booked',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_CLOSED => 'Closed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public static function paymentStatusOptions(): array
    {
        return [
            self::PAYMENT_PENDING => 'Payment pending',
            self::PAYMENT_PARTIAL => 'Part paid',
            self::PAYMENT_PAID => 'Paid',
            self::PAYMENT_CREDIT_DUE => 'Credit due',
        ];
    }

    public static function deliveryMethodOptions(): array
    {
        return [
            self::DELIVERY_PICKUP => 'Pickup',
            self::DELIVERY_COURIER => 'Courier',
            self::DELIVERY_TRANSPORT => 'Transport',
        ];
    }

    public function scopeForBusiness(Builder $query, Business|int|null $business): Builder
    {
        $businessId = $business instanceof Business ? $business->id : $business;

        return $query->when($businessId, fn (Builder $query) => $query->where('business_id', $businessId));
    }

    public function grossSaleAmount(): float
    {
        return round($this->lineItemLines()->sum(fn (array $item): float => $this->lineItemGrossAmount($item)), 2);
    }

    public function productCostAmount(): float
    {
        return round($this->lineItemLines()->sum(fn (array $item): float => $this->lineItemCostAmount($item)), 2);
    }

    public function netSalesAmount(): float
    {
        return round(max(
            $this->grossSaleAmount()
            + (float) $this->delivery_charge_charged
            - (float) $this->discount_amount,
            0
        ), 2);
    }

    public function paidAmount(): float
    {
        return round(max((float) $this->paid_amount, 0), 2);
    }

    public function outstandingAmount(): float
    {
        return round(max($this->netSalesAmount() - $this->paidAmount(), 0), 2);
    }

    public function grossProfitAmount(): float
    {
        return round(
            $this->netSalesAmount()
            - $this->productCostAmount()
            - (float) $this->courier_cost_amount,
            2
        );
    }

    public function profitMarginPercent(): float
    {
        $netSales = $this->netSalesAmount();

        if ($netSales <= 0) {
            return 0.0;
        }

        return round(($this->grossProfitAmount() / $netSales) * 100, 1);
    }

    public function recognizedRevenueAmount(): float
    {
        if (! in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_CLOSED], true)) {
            return 0.0;
        }

        return $this->netSalesAmount();
    }

    public function isRecognizedRevenue(): bool
    {
        return in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_CLOSED], true);
    }

    public function customerKey(): string
    {
        return Str::lower(trim((string) $this->customer_name).'|'.trim((string) $this->customer_phone));
    }

    public function customerLabel(): string
    {
        $parts = array_values(array_filter([
            trim((string) $this->customer_name),
            trim((string) $this->customer_phone),
        ]));

        return $parts !== [] ? implode(' • ', $parts) : 'Unnamed customer';
    }

    public function lineItemSummary(int $limit = 3): string
    {
        $parts = $this->lineItemLines()
            ->take($limit)
            ->map(function (array $item): string {
                $label = trim((string) ($item['sku_code'] ?? $item['sku_name'] ?? 'Item'));
                $quantity = max((int) ($item['quantity'] ?? 0), 1);
                $size = trim((string) ($item['size'] ?? ''));

                $summary = $label.' x'.$quantity;

                if ($size !== '') {
                    $summary .= ' / '.$size;
                }

                return $summary;
            })
            ->all();

        return $parts !== [] ? implode(', ', $parts) : 'No items';
    }

    public function lineItemLines(): Collection
    {
        return collect($this->line_items ?? [])
            ->filter(fn ($item): bool => is_array($item))
            ->values();
    }

    private function lineItemGrossAmount(array $item): float
    {
        $quantity = max((int) ($item['quantity'] ?? 1), 1);
        $unitPrice = max((float) ($item['unit_price'] ?? 0), 0);

        return $quantity * $unitPrice;
    }

    private function lineItemCostAmount(array $item): float
    {
        $quantity = max((int) ($item['quantity'] ?? 1), 1);
        $unitCost = max((float) ($item['unit_cost'] ?? 0), 0);

        return $quantity * $unitCost;
    }
}

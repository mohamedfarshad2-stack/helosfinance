<?php

namespace App\Domains\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceLead extends Model
{
    public const STATUS_LEAD = 'lead';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_PROPOSAL = 'proposal';
    public const STATUS_CLIENT = 'client';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RETAINED = 'retained';
    public const STATUS_LIFETIME = 'lifetime';
    public const STATUS_LOST = 'lost';

    public const SOURCE_COD_CONFIRMATION = 'cod_order_confirmation';
    public const SOURCE_RETURN_FOLLOW_UP = 'return_follow_up';
    public const SOURCE_CUSTOMER_INTELLIGENCE = 'customer_intelligence';
    public const SOURCE_REFERRAL = 'referral';
    public const SOURCE_WHATSAPP = 'whatsapp';
    public const SOURCE_PHONE = 'phone_call';
    public const SOURCE_OTHER = 'other';

    public const BILLING_UPFRONT = 'upfront';
    public const BILLING_MONTH_END = 'month_end';
    public const BILLING_CUSTOM = 'custom_terms';

    protected $fillable = [
        'business_id',
        'prospect_name',
        'contact_person',
        'phone',
        'whatsapp_number',
        'source',
        'status',
        'billing_terms',
        'expected_monthly_amount',
        'notes',
        'next_follow_up_at',
        'last_contacted_at',
        'converted_client_id',
        'converted_at',
        'captured_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_monthly_amount' => 'decimal:2',
            'next_follow_up_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_LEAD => 'Lead / prospect',
            self::STATUS_CONTACTED => 'Contacted',
            self::STATUS_PROPOSAL => 'Proposal sent',
            self::STATUS_CLIENT => 'Paying client',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_RETAINED => 'Retained',
            self::STATUS_LIFETIME => 'Lifetime',
            self::STATUS_LOST => 'Lost',
        ];
    }

    public static function sourceOptions(): array
    {
        return [
            self::SOURCE_COD_CONFIRMATION => 'COD Order Confirmation',
            self::SOURCE_RETURN_FOLLOW_UP => 'Return Follow-Up',
            self::SOURCE_CUSTOMER_INTELLIGENCE => 'Customer Intelligence',
            self::SOURCE_REFERRAL => 'Referral',
            self::SOURCE_WHATSAPP => 'WhatsApp',
            self::SOURCE_PHONE => 'Phone call',
            self::SOURCE_OTHER => 'Other',
        ];
    }

    public static function billingTermsOptions(): array
    {
        return [
            self::BILLING_UPFRONT => 'Upfront',
            self::BILLING_MONTH_END => 'Month-end',
            self::BILLING_CUSTOM => 'Custom terms',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(ServiceClient::class, 'converted_client_id');
    }

    public function capturedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_LEAD, self::STATUS_CONTACTED, self::STATUS_PROPOSAL], true);
    }

    public function isConverted(): bool
    {
        return in_array($this->status, [self::STATUS_CLIENT, self::STATUS_ACTIVE, self::STATUS_RETAINED, self::STATUS_LIFETIME], true);
    }

    public function isAttentionRequired(): bool
    {
        return $this->isOpen() || $this->status === self::STATUS_LOST;
    }

    public function displayLabel(): string
    {
        return trim((string) ($this->prospect_name ?: 'Service lead'));
    }

    public function billingStyleForClient(): string
    {
        return match ($this->billing_terms) {
            self::BILLING_UPFRONT => ServiceClient::BILLING_ONE_TIME,
            self::BILLING_CUSTOM => ServiceClient::BILLING_VARIABLE_MONTHLY,
            default => ServiceClient::BILLING_FIXED_MONTHLY,
        };
    }

    public function recommendedFollowUpAt(): ?string
    {
        if ($this->status === self::STATUS_LOST) {
            return null;
        }

        return match ($this->status) {
            self::STATUS_CLIENT, self::STATUS_ACTIVE => now()->addDays(7)->toDateTimeString(),
            self::STATUS_RETAINED, self::STATUS_LIFETIME => now()->addDays(30)->toDateTimeString(),
            self::STATUS_PROPOSAL => now()->addDays(2)->toDateTimeString(),
            default => now()->addDay()->toDateTimeString(),
        };
    }

    public function callLink(): string
    {
        $phone = $this->phone ?: $this->whatsapp_number;

        return $phone ? 'tel:'.$phone : '#';
    }

    public function whatsappLink(?string $message = null): string
    {
        $phone = preg_replace('/\D+/', '', (string) ($this->whatsapp_number ?: $this->phone));

        if ($phone === '') {
            return '#';
        }

        $text = rawurlencode($message ?: $this->whatsappMessage());

        return "https://wa.me/{$phone}?text={$text}";
    }

    public function whatsappMessage(): string
    {
        $businessName = trim((string) ($this->business?->name ?? 'HELOS'));

        return 'Hi '.trim((string) ($this->contact_person ?: $this->prospect_name)).', checking the service work and next step for '.$businessName.'.';
    }
}

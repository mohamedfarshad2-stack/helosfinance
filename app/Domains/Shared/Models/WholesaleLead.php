<?php

namespace App\Domains\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class WholesaleLead extends Model
{
    public const STATUS_LEAD = 'lead';
    public const STATUS_CONTACT_DUE = 'contact_due';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_INTERESTED = 'interested';
    public const STATUS_QUOTE_SHARED = 'quote_shared';
    public const STATUS_FOLLOW_UP_DUE = 'follow_up_due';
    public const STATUS_NEGOTIATING = 'negotiating';
    public const STATUS_CUSTOMER = 'customer';
    public const STATUS_REORDER_DUE = 'reorder_due';
    public const STATUS_DORMANT = 'dormant';
    public const STATUS_LOST = 'lost';

    protected $fillable = [
        'business_id',
        'captured_by_user_id',
        'source',
        'status',
        'customer_name',
        'contact_name',
        'phone',
        'whatsapp_phone',
        'location',
        'products_of_interest',
        'last_contacted_at',
        'next_follow_up_at',
        'converted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'last_contacted_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_LEAD => 'Lead / prospect',
            self::STATUS_CONTACT_DUE => 'Contact due',
            self::STATUS_CONTACTED => 'Contacted',
            self::STATUS_INTERESTED => 'Interested',
            self::STATUS_QUOTE_SHARED => 'Price / catalogue shared',
            self::STATUS_FOLLOW_UP_DUE => 'Follow-up due',
            self::STATUS_NEGOTIATING => 'Negotiation / decision pending',
            self::STATUS_CUSTOMER => 'Customer',
            self::STATUS_REORDER_DUE => 'Reorder due',
            self::STATUS_DORMANT => 'Dormant',
            self::STATUS_LOST => 'Lost / not interested',
        ];
    }

    public static function sourceOptions(): array
    {
        return [
            'owner_referral' => 'Owner referral',
            'whatsapp' => 'WhatsApp',
            'phone_call' => 'Phone call',
            'walk_in' => 'Walk-in',
            'facebook' => 'Facebook',
            'repeat_customer' => 'Repeat customer',
            'other' => 'Other',
        ];
    }

    public function scopeForBusiness(Builder $query, Business|int|null $business): Builder
    {
        $businessId = $business instanceof Business ? $business->id : $business;

        return $query->when($businessId, fn (Builder $query) => $query->where('business_id', $businessId));
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CUSTOMER;
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_CUSTOMER, self::STATUS_DORMANT, self::STATUS_LOST], true);
    }

    public function displayLabel(): string
    {
        $parts = array_filter([
            trim((string) $this->customer_name),
            trim((string) $this->contact_name),
        ]);

        return $parts !== [] ? implode(' • ', $parts) : 'Unnamed lead';
    }

    public function normalisedPhone(): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) ($this->whatsapp_phone ?: $this->phone));

        if ($phone === '') {
            return null;
        }

        if (Str::startsWith($phone, '0')) {
            $phone = '94'.Str::substr($phone, 1);
        }

        return $phone;
    }

    public function callLink(): ?string
    {
        $phone = preg_replace('/\s+/', '', (string) ($this->phone ?: $this->whatsapp_phone));

        return $phone !== '' ? 'tel:'.$phone : null;
    }

    public function whatsappLink(string $message): ?string
    {
        $phone = $this->normalisedPhone();

        if ($phone === null) {
            return null;
        }

        return 'https://wa.me/'.$phone.'?text='.urlencode($message);
    }

    public function whatsappMessage(): string
    {
        $name = trim((string) ($this->contact_name ?: $this->customer_name));
        $productText = trim((string) $this->products_of_interest);

        $message = 'Hi '.($name !== '' ? $name : 'there').', this is Nifras from HELOS.';

        if ($productText !== '') {
            $message .= ' I have your wholesale interest notes: '.$productText.'.';
        }

        $message .= ' Please share the best product photo and let us know when you want to talk today.';

        return $message;
    }

    public function nextActionLabel(): string
    {
        return match ($this->status) {
            self::STATUS_CUSTOMER, self::STATUS_REORDER_DUE => 'Book the next order or reorder check.',
            self::STATUS_INTERESTED, self::STATUS_QUOTE_SHARED => 'Send the price list, image, and ask for a decision.',
            self::STATUS_FOLLOW_UP_DUE, self::STATUS_NEGOTIATING => 'Call now and close the open question.',
            self::STATUS_CONTACTED, self::STATUS_CONTACT_DUE => 'Send a short WhatsApp and call again if needed.',
            self::STATUS_DORMANT => 'Try a reactivation message for a stronger buyer.',
            self::STATUS_LOST => 'Leave it closed unless the customer comes back.',
            default => 'Call, qualify, and move the lead forward today.',
        };
    }

    public function recommendedFollowUpAt(): Carbon
    {
        if ($this->next_follow_up_at instanceof Carbon) {
            return $this->next_follow_up_at;
        }

        return match ($this->status) {
            self::STATUS_CONTACT_DUE => now(),
            self::STATUS_INTERESTED, self::STATUS_QUOTE_SHARED => now()->addDays(1),
            self::STATUS_FOLLOW_UP_DUE, self::STATUS_NEGOTIATING => now()->addDay(),
            self::STATUS_CUSTOMER, self::STATUS_REORDER_DUE => now()->addDays(14),
            self::STATUS_DORMANT => now()->addDays(21),
            default => now()->addDays(2),
        };
    }
}

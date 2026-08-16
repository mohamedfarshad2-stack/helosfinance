<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSource extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'type',
        'base_url',
        'webhook_secret',
        'shared_token',
        'signature_secret',
        'allow_local_bypass',
        'status',
        'last_synced_at',
        'last_webhook_received_at',
        'last_successful_sync_at',
        'last_failed_sync_at',
        'failed_sync_attempts',
        'duplicate_event_count',
        'rejected_event_count',
        'last_health_status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'last_webhook_received_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
            'last_failed_sync_at' => 'datetime',
            'failed_sync_attempts' => 'integer',
            'duplicate_event_count' => 'integer',
            'rejected_event_count' => 'integer',
            'allow_local_bypass' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function securitySettings(): array
    {
        $settings = is_array($this->settings ?? null) ? $this->settings : [];

        return [
            'webhook_secret' => $this->webhook_secret ?: ($settings['webhook_secret'] ?? null),
            'shared_token' => $this->shared_token ?: ($settings['shared_token'] ?? null),
            'signature_secret' => $this->signature_secret ?: ($settings['signature_secret'] ?? null),
            'allow_local_bypass' => $this->allow_local_bypass ?? ($settings['allow_local_bypass'] ?? null),
        ];
    }

    public function stockAppPendingReadConfig(): array
    {
        $settings = is_array($this->settings ?? null) ? $this->settings : [];
        $businessName = trim((string) ($this->business?->name ?? ''));
        $isHornsEngland = str_contains(strtolower($businessName), 'horns')
            && str_contains(strtolower($businessName), 'england');
        $defaultEmail = (string) config('services.stock_app.read_email', 'admin1@gmail.com');
        $defaultPassword = (string) config('services.stock_app.read_password', 'Horns@123');
        $defaultClientId = (int) config('services.stock_app.read_client_id', 1);

        return [
            'email' => $settings['stock_app_admin_email'] ?? ($isHornsEngland ? 'admin1@gmail.com' : $defaultEmail),
            'password' => $settings['stock_app_admin_password'] ?? ($isHornsEngland ? 'Horns@123' : $defaultPassword),
            'client_id' => filled($settings['stock_app_client_id'] ?? null)
                ? (int) $settings['stock_app_client_id']
                : ($isHornsEngland ? 1 : $defaultClientId),
        ];
    }
}

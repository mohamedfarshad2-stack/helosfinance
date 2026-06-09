<?php

namespace App\Domains\Shared\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientOnboardingService
{
    /**
     * @return array{business:Business,user:User,integration_source:?IntegrationSource,password:string}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $business = Business::query()->create([
                'name' => $data['business_name'],
                'currency' => $data['currency'] ?? 'LKR',
                'industry' => $data['industry'] ?? null,
                'business_type' => $data['business_type'],
                'primary_business_type' => $data['primary_business_type'] ?? null,
                'secondary_business_types' => $data['secondary_business_types'] ?? [],
                'business_maturity' => $data['business_maturity'],
                'onboarding_status' => 'setup',
                'clarity_started_on' => now()->toDateString(),
                'settings' => array_filter([
                    'employee_seat_limit' => filled($data['employee_seat_limit'] ?? null) ? (int) $data['employee_seat_limit'] : null,
                    'integration_security' => array_filter([
                        'webhook_secret' => $data['integration_webhook_secret'] ?? null,
                        'shared_token' => $data['integration_shared_token'] ?? null,
                        'signature_secret' => $data['integration_signature_secret'] ?? null,
                    ], fn (mixed $value): bool => filled($value)),
                ], fn (mixed $value): bool => filled($value)),
            ]);

            $password = (string) ($data['password'] ?? Str::random(12));

            $user = User::query()->create([
                'name' => $data['user_name'],
                'email' => $data['user_email'],
                'password' => $password,
                'business_id' => $business->id,
                'is_platform_admin' => false,
                'is_employee' => false,
            ]);

            $integrationSource = null;
            $hasIntegrationData = filled($data['integration_name'] ?? null)
                || filled($data['integration_base_url'] ?? null)
                || filled($data['integration_status'] ?? null)
                || filled($data['stock_app_business_key'] ?? null)
                || filled($data['integration_notes'] ?? null);

            if ($hasIntegrationData) {
                $integrationSource = IntegrationSource::query()->create([
                    'business_id' => $business->id,
                    'name' => $data['integration_name'] ?: $business->name.' stock-app',
                    'type' => 'stock_app',
                    'base_url' => $data['integration_base_url'] ?? null,
                    'status' => $data['integration_status'] ?? 'draft',
                    'webhook_secret' => $data['integration_webhook_secret'] ?? null,
                    'shared_token' => $data['integration_shared_token'] ?? null,
                    'signature_secret' => $data['integration_signature_secret'] ?? null,
                    'settings' => [
                        'stock_app_business_key' => $data['stock_app_business_key'] ?? null,
                        'notes' => $data['integration_notes'] ?? null,
                    ],
                ]);
            }

            return [
                'business' => $business,
                'user' => $user,
                'integration_source' => $integrationSource,
                'password' => $password,
            ];
        });
    }
}

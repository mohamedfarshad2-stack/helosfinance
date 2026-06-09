<?php

namespace App\Domains\Shared\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class StockAppIntegrationSecurityService
{
    public function securityConfig(?Business $business, ?IntegrationSource $integrationSource = null): array
    {
        $businessSecurity = $business?->integrationSecurityDefaults() ?? [];
        $sourceSecurity = $integrationSource?->securitySettings() ?? [];

        return [
            'webhook_secret' => $sourceSecurity['webhook_secret'] ?? $businessSecurity['webhook_secret'] ?? null,
            'shared_token' => $sourceSecurity['shared_token'] ?? $businessSecurity['shared_token'] ?? null,
            'signature_secret' => $sourceSecurity['signature_secret'] ?? $businessSecurity['signature_secret'] ?? null,
            'allow_local_bypass' => $this->toBoolean(
                $sourceSecurity['allow_local_bypass'] ?? $businessSecurity['allow_local_bypass'] ?? null,
                true
            ),
        ];
    }

    public function authorize(Request $request, ?Business $business, ?IntegrationSource $integrationSource, string $purpose): array
    {
        $config = $this->securityConfig($business, $integrationSource);
        $hasSecurityConfig = filled($config['shared_token']) || filled($config['signature_secret']) || filled($config['webhook_secret']);

        if (! $hasSecurityConfig) {
            if ($this->canUseLocalBypass($config)) {
                return [
                    'allowed' => true,
                    'mode' => 'local_bypass',
                    'reason' => 'Local development mode is allowing this request.',
                ];
            }

            return [
                'allowed' => false,
                'mode' => 'missing_configuration',
                'reason' => 'Integration security is not configured for this business.',
                'status' => 403,
            ];
        }

        $providedToken = $this->providedToken($request);
        if (filled($config['shared_token']) && filled($providedToken) && hash_equals((string) $config['shared_token'], (string) $providedToken)) {
            return [
                'allowed' => true,
                'mode' => 'token',
                'reason' => 'Shared token matched.',
            ];
        }

        $secret = $config['signature_secret'] ?: $config['webhook_secret'];
        $providedSignature = trim((string) $request->header('X-HELOS-SIGNATURE', ''));

        if (filled($secret) && filled($providedSignature) && $this->signatureMatches($request, (string) $secret, $providedSignature)) {
            return [
                'allowed' => true,
                'mode' => 'signature',
                'reason' => 'Signature matched.',
            ];
        }

        if ($this->canUseLocalBypass($config)) {
            return [
                'allowed' => true,
                'mode' => 'local_bypass',
                'reason' => 'Local development mode is allowing this request.',
            ];
        }

        return [
            'allowed' => false,
            'mode' => 'rejected',
            'reason' => 'Integration token or signature did not match.',
            'status' => 401,
        ];
    }

    public function recordSuccess(?IntegrationSource $integrationSource, bool $duplicate = false): void
    {
        if (! $integrationSource instanceof IntegrationSource) {
            Log::info('Stock-app integration success without a configured source', [
                'duplicate' => $duplicate,
            ]);

            return;
        }

        $integrationSource->forceFill([
            'last_webhook_received_at' => now(),
            'last_successful_sync_at' => now(),
            'last_synced_at' => now(),
            'last_health_status' => 'healthy',
        ]);

        if ($duplicate) {
            $integrationSource->duplicate_event_count = (int) ($integrationSource->duplicate_event_count ?? 0) + 1;
        }

        $integrationSource->save();
    }

    public function recordRejected(?IntegrationSource $integrationSource, string $reason): void
    {
        if (! $integrationSource instanceof IntegrationSource) {
            Log::warning('Stock-app integration rejected without a source', [
                'reason' => $reason,
            ]);

            return;
        }

        $integrationSource->forceFill([
            'last_webhook_received_at' => now(),
            'last_failed_sync_at' => now(),
            'failed_sync_attempts' => (int) ($integrationSource->failed_sync_attempts ?? 0) + 1,
            'rejected_event_count' => (int) ($integrationSource->rejected_event_count ?? 0) + 1,
            'last_health_status' => 'broken',
        ])->save();

        Log::warning('Stock-app integration rejected', [
            'integration_source_id' => $integrationSource->id,
            'business_id' => $integrationSource->business_id,
            'reason' => $reason,
        ]);
    }

    public function recordFailure(?IntegrationSource $integrationSource, string $reason): void
    {
        if (! $integrationSource instanceof IntegrationSource) {
            Log::error('Stock-app integration failed without a source', [
                'reason' => $reason,
            ]);

            return;
        }

        $integrationSource->forceFill([
            'last_failed_sync_at' => now(),
            'failed_sync_attempts' => (int) ($integrationSource->failed_sync_attempts ?? 0) + 1,
            'last_health_status' => 'broken',
        ])->save();

        Log::error('Stock-app integration failed', [
            'integration_source_id' => $integrationSource->id,
            'business_id' => $integrationSource->business_id,
            'reason' => $reason,
        ]);
    }

    private function canUseLocalBypass(array $config): bool
    {
        return $config['allow_local_bypass']
            && in_array(app()->environment(), ['local', 'testing'], true);
    }

    private function providedToken(Request $request): ?string
    {
        $token = trim((string) $request->header('X-HELOS-TOKEN', ''));

        if (filled($token)) {
            return $token;
        }

        $authorization = trim((string) $request->header('Authorization', ''));

        if (str_starts_with($authorization, 'Bearer ')) {
            return trim(substr($authorization, 7));
        }

        return null;
    }

    private function signatureMatches(Request $request, string $secret, string $providedSignature): bool
    {
        $timestamp = trim((string) $request->header('X-HELOS-TIMESTAMP', ''));
        $canonical = strtoupper($request->method())
            .'|'
            .$request->path()
            .'|'
            .($request->getQueryString() ?: '')
            .'|'
            .$request->getContent();

        if (filled($timestamp)) {
            $canonical = $timestamp.'|'.$canonical;
        }

        $expected = hash_hmac('sha256', $canonical, $secret);
        $providedSignature = trim(strtolower($providedSignature));
        $expected = strtolower($expected);

        if (str_contains($providedSignature, '=')) {
            $providedSignature = trim(explode('=', $providedSignature, 2)[1] ?? '');
        }

        return filled($providedSignature) && hash_equals($expected, $providedSignature);
    }

    private function toBoolean(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}

<?php

namespace App\Domains\Shared\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class StockAppPendingParityService
{
    public function compare(Business $business, Carbon $start, Carbon $end, int $syncedCount): array
    {
        if (app()->environment('testing')) {
            return [
                'available' => false,
                'live_count' => null,
                'warning' => false,
                'note' => 'Live Stock App pending comparison is skipped during automated tests.',
            ];
        }

        $integration = IntegrationSource::query()
            ->where('business_id', $business->id)
            ->where('type', 'stock_app')
            ->orderByDesc('last_successful_sync_at')
            ->orderByDesc('last_webhook_received_at')
            ->first();

        if (! $integration instanceof IntegrationSource) {
            return [
                'available' => false,
                'live_count' => null,
                'warning' => false,
                'note' => 'No Stock App link is configured for this business yet.',
            ];
        }

        $config = $integration->stockAppPendingReadConfig();
        $email = trim((string) ($config['email'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        $clientId = $config['client_id'] ?? null;

        if ($email === '' || $password === '' || ! is_int($clientId) || $clientId <= 0) {
            return [
                'available' => false,
                'live_count' => null,
                'warning' => false,
                'note' => 'Add Stock App read-only email, password, and client ID in Stock App Link to compare live pending against HELOAS.',
            ];
        }

        try {
            $liveCount = $this->fetchLivePendingCount($integration, $email, $password, $clientId, $start, $end);
        } catch (Throwable $throwable) {
            return [
                'available' => false,
                'live_count' => null,
                'warning' => true,
                'note' => 'HELOAS could not read the live Stock App pending queue right now: '.Str::limit($throwable->getMessage(), 140),
            ];
        }

        $warning = $liveCount !== $syncedCount;

        return [
            'available' => true,
            'live_count' => $liveCount,
            'warning' => $warning,
            'note' => $warning
                ? 'Live Stock App pending count is '.number_format($liveCount).', but HELOAS currently has '.number_format($syncedCount).' synced pending row(s) for this range.'
                : 'HELOAS synced pending rows match the live Stock App pending count for this range.',
        ];
    }

    private function fetchLivePendingCount(
        IntegrationSource $integration,
        string $email,
        string $password,
        int $clientId,
        Carbon $start,
        Carbon $end,
    ): int {
        $baseUrl = rtrim((string) ($integration->base_url ?: 'https://codreturnslanka.lk'), '/');
        $loginUrl = $baseUrl.'/admin/login';
        $loginPage = Http::timeout(45)->get($loginUrl);

        if (! $loginPage->successful()) {
            throw new \RuntimeException('Unable to load Stock App login page.');
        }

        $csrf = $this->firstMatch($loginPage->body(), '/<meta name="csrf-token" content="([^"]+)"/i');
        $initialData = $this->firstMatch($loginPage->body(), '/wire:initial-data="([^"]+)"/i');

        if ($csrf === null || $initialData === null) {
            throw new \RuntimeException('Unable to read Stock App login payload.');
        }

        $payload = json_decode(html_entity_decode($initialData, ENT_QUOTES | ENT_HTML5), true);

        if (! is_array($payload)) {
            throw new \RuntimeException('Invalid Stock App login payload.');
        }

        $payload['updates'] = [
            ['type' => 'syncInput', 'payload' => ['id' => 'sync-password', 'name' => 'password', 'value' => $password]],
            ['type' => 'syncInput', 'payload' => ['id' => 'sync-email', 'name' => 'email', 'value' => $email]],
            ['type' => 'callMethod', 'payload' => ['id' => 'authenticate', 'method' => 'authenticate', 'params' => []]],
        ];

        $cookies = $this->cookiesFromHeaders($loginPage->headers()['Set-Cookie'] ?? []);
        $loginResponse = Http::timeout(45)
            ->withCookies($cookies, parse_url($baseUrl, PHP_URL_HOST))
            ->withHeaders([
                'X-CSRF-TOKEN' => $csrf,
                'X-Livewire' => 'true',
                'Referer' => $loginUrl,
                'Accept' => 'text/html, application/xhtml+xml',
            ])
            ->asJson()
            ->post($baseUrl.'/livewire/message/filament.core.auth.login', $payload);

        if (! $loginResponse->successful()) {
            throw new \RuntimeException('Stock App login failed.');
        }

        $cookies = array_merge($cookies, $this->cookiesFromHeaders($loginResponse->headers()['Set-Cookie'] ?? []));
        $ordersUrl = $baseUrl.'/admin/client-orders-improved?'.http_build_query([
            'dateFrom' => $start->toDateString(),
            'dateTo' => $end->toDateString(),
            'clientId' => $clientId,
            'status' => 'pending',
            'deliveryStatus' => 'pending',
        ]);

        $ordersPage = Http::timeout(60)
            ->withCookies($cookies, parse_url($baseUrl, PHP_URL_HOST))
            ->withHeaders([
                'Referer' => $baseUrl.'/admin',
            ])
            ->get($ordersUrl);

        if (! $ordersPage->successful()) {
            throw new \RuntimeException('Unable to load Stock App pending orders page.');
        }

        return $this->parsePendingCount($ordersPage->body());
    }

    /**
     * @param  array<int, string>|string  $headerValues
     * @return array<string, string>
     */
    private function cookiesFromHeaders(array|string $headerValues): array
    {
        $headers = is_array($headerValues) ? $headerValues : [$headerValues];
        $cookies = [];

        foreach ($headers as $header) {
            $pair = trim((string) Str::before($header, ';'));

            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $cookies[trim($name)] = trim($value);
        }

        return $cookies;
    }

    private function parsePendingCount(string $html): int
    {
        $text = preg_replace('/\s+/', ' ', trim(strip_tags($html)));

        if (! is_string($text)) {
            throw new \RuntimeException('Unable to read Stock App pending count.');
        }

        if (preg_match('/Pending\s+(\d+)/i', $text, $matches) === 1) {
            return (int) $matches[1];
        }

        throw new \RuntimeException('Stock App pending count was not found.');
    }

    private function firstMatch(string $subject, string $pattern): ?string
    {
        return preg_match($pattern, $subject, $matches) === 1
            ? html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5)
            : null;
    }
}

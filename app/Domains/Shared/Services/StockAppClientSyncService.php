<?php

namespace App\Domains\Shared\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\ServiceClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class StockAppClientSyncService
{
    /**
     * @return array{synced_clients:int,synced_billing_records:int,available:bool,note:string}
     */
    public function sync(Business $business, bool $allowFetch = true): array
    {
        $integration = $this->resolveIntegrationSource($business);

        $config = $integration->stockAppPendingReadConfig();
        $email = trim((string) ($config['email'] ?? ''));
        $password = (string) ($config['password'] ?? '');

        if ($email === '' || $password === '') {
            return [
                'synced_clients' => 0,
                'synced_billing_records' => 0,
                'available' => false,
                'note' => 'Add Stock App read-only login details in the Stock App Link first.',
            ];
        }

        if (! $allowFetch) {
            return [
                'synced_clients' => 0,
                'synced_billing_records' => 0,
                'available' => false,
                'note' => 'Live Stock App sync was skipped.',
            ];
        }

        try {
            [$clients, $pricings] = $this->fetchLiveSnapshots($integration, $email, $password);
        } catch (Throwable $throwable) {
            return [
                'synced_clients' => 0,
                'synced_billing_records' => 0,
                'available' => false,
                'note' => 'HELOS could not read live Stock App clients right now: '.Str::limit($throwable->getMessage(), 140),
            ];
        }

        $pricingByName = $pricings->keyBy(fn (array $row): string => $this->normalizeName((string) ($row['client.name'] ?? '')));
        $syncDate = now();
        $periodStart = $syncDate->copy()->startOfMonth()->toDateString();
        $periodEnd = $syncDate->copy()->endOfMonth()->toDateString();
        $syncedClients = 0;
        $syncedBillingRecords = 0;

        foreach ($clients as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $pricing = $pricingByName[$this->normalizeName($name)] ?? null;
            $monthlyFee = $this->moneyValue($pricing['monthly-fee'] ?? null);
            $status = $this->mapStatus((string) ($row['status'] ?? 'active'));
            $billingStyle = $this->mapBillingStyle((string) ($row['package-type'] ?? ''), $monthlyFee);

            $client = ServiceClient::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'name' => $name,
                ],
                [
                    'status' => $status,
                    'billing_style' => $billingStyle,
                    'default_monthly_amount' => $monthlyFee,
                    'default_registration_fee' => 0,
                    'default_due_day' => 5,
                    'active_from' => $this->parseDate((string) ($row['created-at'] ?? null)) ?? $syncDate->toDateString(),
                    'inactive_from' => $status === ServiceClient::STATUS_ACTIVE ? null : $syncDate->toDateString(),
                    'notes' => $this->buildNotes($row, $pricing),
                ]
            );

            ServiceBillingRecord::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'service_client_id' => $client->id,
                    'billing_type' => ServiceBillingRecord::TYPE_SUBSCRIPTION,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ],
                [
                    'client_name' => $name,
                    'amount_due' => $monthlyFee,
                    'paid_amount' => 0,
                    'payment_status' => $monthlyFee > 0 ? 'unpaid' : 'paid',
                    'due_on' => $monthlyFee > 0 ? $periodEnd : null,
                    'note' => 'Synced from Stock App client/pricings.',
                ]
            );

            $syncedClients++;
            $syncedBillingRecords++;
        }

        $integration->forceFill([
            'last_successful_sync_at' => now(),
            'last_synced_at' => now(),
            'last_health_status' => 'healthy',
        ])->save();

        return [
            'synced_clients' => $syncedClients,
            'synced_billing_records' => $syncedBillingRecords,
            'available' => true,
            'note' => 'HELOS synced '.number_format($syncedClients).' live Stock App client row(s).',
        ];
    }

    private function resolveIntegrationSource(Business $business): IntegrationSource
    {
        $integration = IntegrationSource::query()
            ->where('business_id', $business->id)
            ->where('type', 'stock_app')
            ->orderByDesc('last_successful_sync_at')
            ->orderByDesc('last_webhook_received_at')
            ->first();

        if ($integration instanceof IntegrationSource) {
            return $integration;
        }

        $businessKey = (string) data_get($business->settings, 'stock_app_business_key', Str::slug((string) $business->name));

        return IntegrationSource::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'type' => 'stock_app',
            ],
            [
                'name' => $business->name.' Stock App',
                'base_url' => 'https://codreturnslanka.lk',
                'status' => 'active',
                'settings' => [
                    'stock_app_business_key' => $businessKey,
                    'notes' => 'Auto-created by Sandhamali live sync.',
                ],
            ]
        );
    }

    /**
     * @return array{0: SupportCollection<int, array<string, string>>, 1: SupportCollection<int, array<string, string>>}
     */
    private function fetchLiveSnapshots(IntegrationSource $integration, string $email, string $password): array
    {
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

        $clientsPage = $this->fetchPage($baseUrl, $cookies, $baseUrl.'/admin/clients');
        $pricingPage = $this->fetchPage($baseUrl, $cookies, $baseUrl.'/admin/client-pricings');

        return [
            $this->parseTableRows($clientsPage->body(), 'name'),
            $this->parseTableRows($pricingPage->body(), 'client.name'),
        ];
    }

    /**
     * @return SupportCollection<int, array<string, string>>
     */
    private function parseTableRows(string $html, string $requiredCell): SupportCollection
    {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rowMatches);
        $rows = collect($rowMatches[1] ?? [])
            ->map(function (string $row) use ($requiredCell): ?array {
                $cells = $this->extractCells($row);

                if (! array_key_exists($requiredCell, $cells)) {
                    return null;
                }

                $rows = collect($cells)
                    ->map(fn (string $value): string => trim($value))
                    ->filter(fn (string $value, string $key): bool => ! in_array($key, ['select-deselect-item', 'select-deselect-all-items-for-bulk-actions'], true))
                    ->all();

                if (trim((string) ($rows[$requiredCell] ?? '')) === '') {
                    return null;
                }

                return $rows;
            })
            ->filter()
            ->values();

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function extractCells(string $row): array
    {
        preg_match_all('/<t[dh][^>]*class="[^"]*filament-table-cell-([A-Za-z0-9_.-]+)[^"]*"[^>]*>(.*?)<\/t[dh]>/s', $row, $matches, PREG_SET_ORDER);

        $cells = [];

        foreach ($matches as $match) {
            $cells[$match[1]] = $this->plainText($match[2]);
        }

        return $cells;
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @param  array<int, string>|string|null  $headers
     * @return array<string, string>
     */
    private function cookiesFromHeaders(array|string|null $headers): array
    {
        $headers = is_array($headers) ? $headers : (filled($headers) ? [$headers] : []);
        $cookies = [];

        foreach ($headers as $header) {
            foreach (preg_split('/,(?=[^;]+=[^;]+)/', (string) $header) ?: [] as $chunk) {
                $pair = trim(explode(';', $chunk, 2)[0] ?? '');

                if (! str_contains($pair, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $pair, 2);
                $cookies[trim($name)] = trim($value);
            }
        }

        return $cookies;
    }

    private function fetchPage(string $baseUrl, array $cookies, string $url): \Illuminate\Http\Client\Response
    {
        $response = Http::timeout(60)
            ->withCookies($cookies, parse_url($baseUrl, PHP_URL_HOST))
            ->withHeaders([
                'Referer' => $baseUrl.'/admin',
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Unable to load Stock App page '.$url.'.');
        }

        return $response;
    }

    private function firstMatch(string $subject, string $pattern): ?string
    {
        if (preg_match($pattern, $subject, $matches) !== 1) {
            return null;
        }

        return $matches[1] ?? null;
    }

    private function moneyValue(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', (string) $value) ?? '';

        return (float) ($normalized === '' ? 0 : $normalized);
    }

    private function normalizeName(string $name): string
    {
        return Str::of($name)->lower()->squish()->toString();
    }

    private function mapStatus(string $status): string
    {
        $normalized = Str::of($status)->lower()->squish()->toString();

        return match (true) {
            str_contains($normalized, 'pause') => ServiceClient::STATUS_PAUSED,
            str_contains($normalized, 'stop'), str_contains($normalized, 'inactive') => ServiceClient::STATUS_STOPPED,
            default => ServiceClient::STATUS_ACTIVE,
        };
    }

    private function mapBillingStyle(string $packageType, float $monthlyFee): string
    {
        $normalized = Str::of($packageType)->lower()->squish()->toString();

        return match (true) {
            str_contains($normalized, 'variable') => ServiceClient::BILLING_VARIABLE_MONTHLY,
            str_contains($normalized, 'one-time'), str_contains($normalized, 'one time'), str_contains($normalized, 'irregular') => ServiceClient::BILLING_ONE_TIME,
            $monthlyFee > 0 => ServiceClient::BILLING_FIXED_MONTHLY,
            default => ServiceClient::BILLING_FIXED_MONTHLY,
        };
    }

    private function buildNotes(array $clientRow, ?array $pricingRow): string
    {
        $parts = array_filter([
            filled($clientRow['code'] ?? null) ? 'Code: '.($clientRow['code'] ?? '') : null,
            filled($clientRow['contact-name'] ?? null) ? 'Contact: '.($clientRow['contact-name'] ?? '') : null,
            filled($clientRow['package-type'] ?? null) ? 'Package: '.($clientRow['package-type'] ?? '') : null,
            filled($clientRow['orders-count'] ?? null) ? 'Orders: '.($clientRow['orders-count'] ?? '') : null,
            filled($pricingRow['monthly-fee'] ?? null) ? 'Monthly fee: '.($pricingRow['monthly-fee'] ?? '') : null,
            filled($pricingRow['confirmation-call-rate'] ?? null) ? 'Confirm call: '.($pricingRow['confirmation-call-rate'] ?? '') : null,
            filled($pricingRow['return-handling-rate'] ?? null) ? 'Return support Tamil: '.($pricingRow['return-handling-rate'] ?? '') : null,
            filled($pricingRow['tamil-call-rate'] ?? null) ? 'Confirm returns Tamil: '.($pricingRow['tamil-call-rate'] ?? '') : null,
        ]);

        return implode(' | ', $parts);
    }

    private function parseDate(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}

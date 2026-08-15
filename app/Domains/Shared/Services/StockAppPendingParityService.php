<?php

namespace App\Domains\Shared\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class StockAppPendingParityService
{
    public function compare(
        Business $business,
        Carbon $start,
        Carbon $end,
        int $syncedCount,
        bool $allowFetch = true,
        string $status = 'pending',
        array $queryParams = [],
        string $label = 'pending',
    ): array
    {
        if (app()->environment('testing')) {
            return [
                'available' => false,
                'live_count' => null,
                'live_value' => null,
                'warning' => false,
                'note' => 'Live Stock App '.$label.' comparison is skipped during automated tests.',
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
                'live_value' => null,
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
                'live_value' => null,
                'warning' => false,
                'note' => 'Add Stock App read-only email, password, and client ID in Stock App Link to compare live '.$label.' against HELOAS.',
            ];
        }

        $cacheKey = $this->cacheKey($integration, $clientId, $start, $end, $status, $queryParams);

        if (! $allowFetch && ! Cache::has($cacheKey)) {
            return [
                'available' => false,
                'live_count' => null,
                'live_value' => null,
                'warning' => false,
                'note' => 'Live Stock App '.$label.' count is warming. HELOAS is showing synced '.$label.' rows now.',
            ];
        }

        try {
            if ($allowFetch) {
                $liveSummary = $this->fetchLiveSummary($integration, $email, $password, $clientId, $start, $end, $status, $queryParams, $label);
                Cache::put($cacheKey, $liveSummary, now()->addMinutes(30));
            } else {
                $liveSummary = Cache::get($cacheKey);
            }

            if (is_int($liveSummary)) {
                $liveSummary = ['count' => $liveSummary, 'value' => null, 'items' => []];
            }

            if (! is_array($liveSummary)) {
                throw new \RuntimeException('Invalid cached Stock App '.$label.' summary.');
            }

            $liveCount = (int) ($liveSummary['count'] ?? 0);
            $liveValue = array_key_exists('value', $liveSummary) ? ($liveSummary['value'] !== null ? (float) $liveSummary['value'] : null) : null;
            $liveItems = is_array($liveSummary['items'] ?? null) ? $liveSummary['items'] : [];
        } catch (Throwable $throwable) {
            return [
                'available' => false,
                'live_count' => null,
                'live_value' => null,
                'items' => [],
                'warning' => true,
                'note' => 'HELOAS could not read the live Stock App '.$label.' queue right now: '.Str::limit($throwable->getMessage(), 140),
            ];
        }

        $warning = $liveCount !== $syncedCount;

        return [
            'available' => true,
            'live_count' => $liveCount,
            'live_value' => $liveValue,
            'items' => $liveItems,
            'warning' => $warning,
            'note' => $warning
                ? 'Live Stock App '.$label.' count is '.number_format($liveCount).', but HELOAS currently has '.number_format($syncedCount).' synced '.$label.' row(s) for this range.'
                : 'HELOAS synced '.$label.' rows match the live Stock App '.$label.' count for this range.',
        ];
    }

    private function cacheKey(IntegrationSource $integration, int $clientId, Carbon $start, Carbon $end, string $status, array $queryParams): string
    {
        return implode(':', [
            'stock-app-pending-count-v2',
            $integration->id,
            $clientId,
            $start->toDateString(),
            $end->toDateString(),
            $status,
            sha1(json_encode($queryParams)),
            optional($integration->updated_at)->timestamp ?? 0,
        ]);
    }

    private function fetchLiveSummary(
        IntegrationSource $integration,
        string $email,
        string $password,
        int $clientId,
        Carbon $start,
        Carbon $end,
        string $status,
        array $queryParams,
        string $label,
    ): array {
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
        $ordersUrl = $baseUrl.'/admin/client-orders-improved?'.http_build_query(array_merge([
            'dateFrom' => $start->toDateString(),
            'dateTo' => $end->toDateString(),
            'clientId' => $clientId,
            'status' => $status,
        ], array_filter($queryParams, fn ($value): bool => filled($value))));

        $ordersPage = Http::timeout(60)
            ->withCookies($cookies, parse_url($baseUrl, PHP_URL_HOST))
            ->withHeaders([
                'Referer' => $baseUrl.'/admin',
            ])
            ->get($ordersUrl);

        if (! $ordersPage->successful()) {
            throw new \RuntimeException('Unable to load Stock App pending orders page.');
        }

        return [
            'count' => $this->parseCount($ordersPage->body(), $label),
            'value' => $this->parseLiveValue($ordersPage->body()),
            'items' => $this->parseLiveItems($ordersPage->body()),
        ];
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

    private function parseCount(string $html, string $label): int
    {
        $text = preg_replace('/\s+/', ' ', trim(strip_tags($html)));

        if (! is_string($text)) {
            throw new \RuntimeException('Unable to read Stock App pending count.');
        }

        $labelPattern = preg_quote($label, '/');

        if (preg_match('/'. $labelPattern .'\s+(\d+)/i', $text, $matches) === 1) {
            return (int) $matches[1];
        }

        throw new \RuntimeException('Stock App '.$label.' count was not found.');
    }

    private function parseLiveValue(string $html): ?float
    {
        if (! preg_match_all("/<label>Amount<\\/label>\\s*<input[^>]*value=\"([0-9.]+)\"[^>]*wire:change=\"updateOrderField\\(\\d+, 'total_amount'/is", $html, $matches)) {
            return null;
        }

        $value = array_sum(array_map('floatval', $matches[1] ?? []));

        return $value > 0 ? $value : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseLiveItems(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $amountInputs = $xpath->query('//*[contains(@*[name()="wire:change"], "updateOrderField") and contains(@*[name()="wire:change"], "total_amount")]');

        if (! $amountInputs instanceof \DOMNodeList || $amountInputs->length === 0) {
            return [];
        }

        $items = [];

        foreach ($amountInputs as $amountInput) {
            if (! $amountInput instanceof DOMElement) {
                continue;
            }

            $row = $this->closestQueueRow($amountInput, $xpath);

            if (! $row instanceof DOMElement) {
                continue;
            }

            $fields = $this->queueRowFieldMap($row, $xpath);
            $text = $this->queueRowText($row);
            $amount = $this->queueRowAmount($fields, $text);

            $items[] = [
                'reference' => $this->queueRowReference($fields, $text, $amount),
                'customer' => $this->queueRowCustomer($fields, $text),
                'phone' => $this->queueRowPhone($fields, $text),
                'value' => $amount,
                'date' => $this->queueRowDate($fields, $text),
                'age_label' => $this->queueRowAgeLabel($fields, $text),
                'status' => $this->queueRowStatus($fields, $text),
                'next_action' => $this->queueRowNextAction($fields, $text),
            ];
        }

        return collect($items)
            ->filter(fn (array $item): bool => filled($item['reference']) || filled($item['customer']) || filled($item['value']))
            ->unique(fn (array $item): string => md5(json_encode([$item['reference'], $item['customer'], $item['phone'], $item['value'], $item['date']])))
            ->values()
            ->all();
    }

    private function closestQueueRow(DOMElement $node, DOMXPath $xpath): ?DOMElement
    {
        $candidate = $node;

        while ($candidate instanceof DOMElement) {
            $query = $xpath->query('.//*[contains(@*[name()="wire:change"], "updateOrderField")]', $candidate);
            $wireChangeCount = $query instanceof \DOMNodeList ? $query->length : 0;

            if ($wireChangeCount > 0 && $this->hasQueueRowShape($candidate, $xpath)) {
                return $candidate;
            }

            $candidate = $candidate->parentNode instanceof DOMElement ? $candidate->parentNode : null;
        }

        return null;
    }

    private function hasQueueRowShape(DOMElement $node, DOMXPath $xpath): bool
    {
        $text = $this->queueRowText($node);

        if ($text === '') {
            return false;
        }

        $valueInputs = $xpath->query('.//*[contains(@*[name()="wire:change"], "updateOrderField") and contains(@*[name()="wire:change"], "total_amount")]', $node);

        return $valueInputs instanceof \DOMNodeList && $valueInputs->length >= 1;
    }

    /**
     * @return array<string, string>
     */
    private function queueRowFieldMap(DOMElement $row, DOMXPath $xpath): array
    {
        $fields = [];
        $nodes = $xpath->query('.//*[@*[name()="wire:change"] and contains(@*[name()="wire:change"], "updateOrderField")]', $row);

        if (! $nodes instanceof \DOMNodeList) {
            return $fields;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $wireChange = (string) ($node->attributes?->getNamedItem('wire:change')?->nodeValue ?? '');

            if (! preg_match("/updateOrderField\\((\\d+),\\s*'([^']+)'/i", $wireChange, $matches)) {
                continue;
            }

            $field = strtolower(trim($matches[2]));
            $value = $this->queueRowNodeValue($node);

            if ($value !== '') {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    private function queueRowNodeValue(DOMElement $node): string
    {
        foreach (['value', 'placeholder', 'aria-label'] as $attribute) {
            $attributeNode = $node->attributes?->getNamedItem($attribute);

            if ($attributeNode instanceof \DOMAttr && filled($attributeNode->nodeValue ?? null)) {
                return trim((string) $attributeNode->nodeValue);
            }
        }

        return trim((string) $node->textContent);
    }

    private function queueRowText(DOMElement $row): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $row->textContent) ?? '');
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function queueRowReference(array $fields, string $text, ?float $amount): string
    {
        foreach (['reference', 'order_reference', 'order_number', 'order_no', 'parcel_no', 'invoice_no', 'tracking_no', 'tracking_number', 'id'] as $key) {
            if (filled($fields[$key] ?? null)) {
                return $fields[$key];
            }
        }

        if (preg_match('/(?:order|parcel|invoice|ref|#)\s*[:\-]?\s*([A-Z0-9\-\/]+)/i', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        if ($amount !== null) {
            return 'Confirmed parcel';
        }

        return 'Confirmed parcel';
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function queueRowCustomer(array $fields, string $text): ?string
    {
        foreach (['customer_name', 'customer', 'name', 'business_name', 'buyer_name'] as $key) {
            if (filled($fields[$key] ?? null)) {
                return trim($fields[$key]);
            }
        }

        if (preg_match('/(?:customer|buyer|name)\s*[:\-]?\s*([^|•\n\r]+)/i', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function queueRowPhone(array $fields, string $text): ?string
    {
        foreach (['phone', 'mobile', 'contact_number', 'customer_phone'] as $key) {
            if (filled($fields[$key] ?? null)) {
                return trim($fields[$key]);
            }
        }

        if (preg_match('/(?:\+?\d{2,3}[\s-]?)?(?:0\d{9,10})/', $text, $matches) === 1) {
            return trim($matches[0]);
        }

        return null;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function queueRowAmount(array $fields, string $text): float
    {
        foreach (['total_amount', 'amount', 'value', 'order_amount', 'grand_total'] as $key) {
            if (filled($fields[$key] ?? null) && is_numeric(str_replace(',', '', $fields[$key]))) {
                return (float) str_replace(',', '', $fields[$key]);
            }
        }

        if (preg_match('/LKR\s*([0-9,]+(?:\.[0-9]+)?)/i', $text, $matches) === 1) {
            return (float) str_replace(',', '', $matches[1]);
        }

        return 0.0;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function queueRowDate(array $fields, string $text): ?string
    {
        foreach (['confirmed_at', 'confirmed_date', 'order_date', 'date', 'created_at', 'dispatched_at'] as $key) {
            if (filled($fields[$key] ?? null)) {
                return trim($fields[$key]);
            }
        }

        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $text, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/\b\d{1,2}\/\d{1,2}\/\d{4}\b/', $text, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function queueRowAgeLabel(array $fields, string $text): ?string
    {
        $date = $this->queueRowDate($fields, $text);

        if (! $date) {
            return null;
        }

        try {
            $carbon = Carbon::parse($date);
        } catch (Throwable) {
            return null;
        }

        $days = max(0, (int) $carbon->diffInDays(now()));

        return $days === 0
            ? 'Today'
            : $days.' day'.($days === 1 ? '' : 's').' old';
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function queueRowStatus(array $fields, string $text): string
    {
        foreach (['status', 'order_status', 'current_status', 'delivery_status', 'confirmation_status'] as $key) {
            if (filled($fields[$key] ?? null)) {
                return str_replace(['_', '-'], ' ', trim($fields[$key]));
            }
        }

        if (preg_match('/\b(confirmed|pending|dispatched|delivered|returned)\b/i', $text, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return 'confirmed';
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function queueRowNextAction(array $fields, string $text): string
    {
        foreach (['next_action', 'action', 'instruction', 'note'] as $key) {
            if (filled($fields[$key] ?? null)) {
                return trim($fields[$key]);
            }
        }

        return 'Add tracking and send this parcel to courier.';
    }

    private function firstMatch(string $subject, string $pattern): ?string
    {
        return preg_match($pattern, $subject, $matches) === 1
            ? html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5)
            : null;
    }
}

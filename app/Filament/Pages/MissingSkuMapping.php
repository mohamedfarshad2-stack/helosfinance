<?php

namespace App\Filament\Pages;

use App\Domains\FinancialClarity\Services\OperationalImpactCalculator;
use App\Domains\FinancialClarity\Services\SkuStockMovementService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class MissingSkuMapping extends Page
{
    protected static ?string $slug = 'missing-product-links';
    protected static ?string $navigationGroup = 'Sales & Work';
    protected static ?string $navigationLabel = 'Fix Missing Product Links';
    protected static ?string $navigationIcon = 'heroicon-o-link';
    protected static ?int $navigationSort = 4;
    protected static ?string $title = 'Fix Missing Product Links';
    protected static string $view = 'filament.pages.missing-sku-mapping';

    public ?int $businessId = null;

    public string $search = '';

    /** @var array<int, int|string|null> */
    public array $skuSelections = [];

    /** @var array<string, int|string|null> */
    public array $bulkSkuSelections = [];

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->businessId = $this->defaultBusinessId();
    }

    protected function getViewData(): array
    {
        $businesses = $this->businesses();
        $business = $this->selectedBusiness();

        return [
            'businesses' => $businesses,
            'business' => $business,
            'skuOptions' => $this->skuOptions(),
            'groups' => $business ? $this->groups($business) : collect(),
            'rows' => $business ? $this->rows($business) : collect(),
            'missingCount' => $business ? $this->missingQuery($business)->count() : 0,
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false) || ($user?->isInternalAdmin() ?? false) || ($user?->canAccessOperationalTasks() ?? false));
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false) || ($user?->isInternalAdmin() ?? false) || ($user?->canAccessOperationalTasks() ?? false));
    }

    public function updatedBusinessId(): void
    {
        $this->skuSelections = [];
        $this->bulkSkuSelections = [];
    }

    public function assignSku(int $eventId, OperationalImpactCalculator $calculator, SkuStockMovementService $stockMovements): void
    {
        $skuId = (int) ($this->skuSelections[$eventId] ?? 0);

        if ($skuId <= 0) {
            Notification::make()
                ->title('Choose a product first')
                ->body('Select the correct SKU for this order row, then save.')
                ->warning()
                ->send();

            return;
        }

        $event = OperationalEvent::query()
            ->whereIn('business_id', $this->accessibleBusinessIds())
            ->whereKey($eventId)
            ->firstOrFail();

        $sku = Sku::query()
            ->where('business_id', $event->business_id)
            ->whereKey($skuId)
            ->firstOrFail();

        $this->repairEvent($event, $sku, $calculator, $stockMovements);

        unset($this->skuSelections[$eventId]);

        Notification::make()
            ->title('Product link fixed')
            ->body('HELOS recalculated this row and updated the stock movement where it applies.')
            ->success()
            ->send();
    }

    public function assignGroup(string $groupKey, int|string|null $selectedSkuId, OperationalImpactCalculator $calculator, SkuStockMovementService $stockMovements): void
    {
        $skuId = (int) ($selectedSkuId ?: ($this->bulkSkuSelections[$groupKey] ?? 0));
        $business = $this->selectedBusiness();

        if (! $business || $skuId <= 0) {
            Notification::make()
                ->title('Choose a product first')
                ->body('Select the correct SKU for this repeated Stock App hint, then bulk repair.')
                ->warning()
                ->send();

            return;
        }

        $sku = Sku::query()
            ->where('business_id', $business->id)
            ->whereKey($skuId)
            ->firstOrFail();

        $events = $this->missingQuery($business)
            ->get()
            ->filter(fn (OperationalEvent $event): bool => $this->groupKeyForEvent($event) === $groupKey)
            ->take(500);

        if ($events->isEmpty()) {
            Notification::make()
                ->title('Nothing changed')
                ->body('This repeated hint may already be fixed. Refresh the page and check the remaining count.')
                ->warning()
                ->send();

            return;
        }

        foreach ($events as $event) {
            $this->repairEvent($event, $sku, $calculator, $stockMovements);
        }

        unset($this->bulkSkuSelections[$groupKey]);
        $this->skuSelections = [];

        Notification::make()
            ->title('Repeated product hint repaired')
            ->body($events->count().' row(s) were linked to '.$sku->code.' and recalculated.')
            ->success()
            ->send();
    }

    public function autoFixObviousMatches(OperationalImpactCalculator $calculator, SkuStockMovementService $stockMovements): void
    {
        $business = $this->selectedBusiness();

        if (! $business) {
            Notification::make()
                ->title('Choose a business first')
                ->body('Select the business you want HELOS to clean before auto-fixing obvious product matches.')
                ->warning()
                ->send();

            return;
        }

        $skuDirectory = $this->skuDirectory($business);
        $fixed = 0;
        $checked = 0;

        $events = $this->missingQuery($business)->limit(5000)->get();

        foreach ($events as $event) {
            $checked++;
            $sku = $this->obviousSkuMatch($event, $skuDirectory);

            if (! $sku) {
                continue;
            }

            $this->repairEvent($event, $sku, $calculator, $stockMovements);
            $fixed++;
        }

        $this->skuSelections = [];
        $this->bulkSkuSelections = [];

        if ($fixed > 0) {
            Notification::make()
                ->title('Obvious product links cleaned')
                ->body($fixed.' row(s) were auto-linked using exact SKU code or exact product-name matches. '.$checked.' row(s) checked.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('No safe auto-matches found')
            ->body('HELOS checked '.$checked.' row(s) and did not find any high-confidence exact matches. The remaining rows still need manual or grouped repair.')
            ->warning()
            ->send();
    }

    private function defaultBusinessId(): ?int
    {
        $user = Auth::user();
        $default = $user?->defaultBusinessId();

        if ($default && in_array((int) $default, $this->accessibleBusinessIds(), true)) {
            return (int) $default;
        }

        return $this->businesses()->keys()->first();
    }

    private function selectedBusiness(): ?Business
    {
        if (! $this->businessId) {
            return null;
        }

        return Business::query()
            ->whereIn('id', $this->accessibleBusinessIds())
            ->whereKey($this->businessId)
            ->first();
    }

    /**
     * @return Collection<int, string>
     */
    private function businesses(): Collection
    {
        return Business::query()
            ->whereIn('id', $this->accessibleBusinessIds())
            ->where(fn (Builder $query) => $query->where('business_type', '!=', Business::TYPE_SERVICE)->orWhereNull('business_type'))
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    /**
     * @return array<int, string>
     */
    private function skuOptions(): array
    {
        if (! $this->businessId) {
            return [];
        }

        return Sku::query()
            ->where('business_id', $this->businessId)
            ->where('active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Sku $sku): array => [$sku->id => trim($sku->code.' - '.$sku->name, ' -')])
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Business $business): Collection
    {
        return $this->missingQuery($business)
            ->limit(60)
            ->get()
            ->map(fn (OperationalEvent $event): array => $this->row($event));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function groups(Business $business): Collection
    {
        return $this->missingQuery($business)
            ->limit(2000)
            ->get()
            ->groupBy(fn (OperationalEvent $event): string => $this->groupKeyForEvent($event))
            ->reject(fn (Collection $events, string $key): bool => $key === 'missing-product-hint' || $events->count() < 2)
            ->map(fn (Collection $events, string $key): array => [
                'key' => $key,
                'hint' => $this->productHint($events->first()),
                'count' => $events->count(),
                'latest_at' => $events->max(fn (OperationalEvent $event): string => (string) $event->occurred_at),
                'example' => $events->first()?->external_id,
            ])
            ->sortByDesc('count')
            ->take(12)
            ->values();
    }

    private function missingQuery(Business $business): Builder
    {
        $search = trim($this->search);

        return OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereNull('sku_id')
            ->whereIn('event_type', $this->skuRequiredEventTypes())
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('external_id', 'like', "%{$search}%")
                        ->orWhere('event_type', 'like', "%{$search}%")
                        ->orWhere('channel', 'like', "%{$search}%")
                        ->orWhere('payload->sku_code', 'like', "%{$search}%")
                        ->orWhere('payload->sku_name', 'like', "%{$search}%")
                        ->orWhere('payload->customer_name', 'like', "%{$search}%")
                        ->orWhere('payload->phone', 'like', "%{$search}%")
                        ->orWhere('payload->tracking_number', 'like', "%{$search}%");
                });
            })
            ->latest('occurred_at')
            ->latest('id');
    }

    /**
     * @return array<int, string>
     */
    private function skuRequiredEventTypes(): array
    {
        return [
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
            OperationalEvent::ORDER_DELIVERED,
            OperationalEvent::ORDER_RETURNED,
            OperationalEvent::ORDER_RESENT,
            OperationalEvent::FAKE_ORDER_DETECTED,
            OperationalEvent::SKU_PRODUCED,
            OperationalEvent::PRODUCTION_WASTE,
            OperationalEvent::PAYOUT_GENERATED,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(OperationalEvent $event): array
    {
        $payload = $event->payload ?? [];

        return [
            'id' => $event->id,
            'occurred_at' => $event->occurred_at?->format('M j, Y H:i') ?? 'No date',
            'event_type' => str_replace('_', ' ', $event->event_type),
            'external_id' => $event->external_id ?: ($payload['order_id'] ?? $payload['reference'] ?? 'No order ID'),
            'customer' => $payload['customer_name'] ?? $payload['customer'] ?? 'Customer not sent',
            'phone' => $payload['phone'] ?? $payload['customer_phone'] ?? '',
            'tracking_number' => $payload['tracking_number'] ?? 'No tracking yet',
            'product_hint' => $this->productHint($event),
            'quantity' => (int) ($event->quantity ?: ($payload['quantity'] ?? 1)),
            'sale_amount' => (float) ($payload['sale_amount'] ?? $payload['revenue_amount'] ?? $event->revenue_amount ?? 0),
            'channel' => $event->channel ?: ($payload['channel'] ?? 'cod'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadForRepair(OperationalEvent $event, Sku $sku): array
    {
        $payload = $event->payload ?? [];

        return array_merge($payload, [
            'event_type' => $event->event_type,
            'external_id' => $event->external_id ?: ($payload['external_id'] ?? null),
            'sku_code' => $sku->code,
            'sku_name' => $sku->name,
            'quantity' => (int) ($event->quantity ?: ($payload['quantity'] ?? 1)),
            'channel' => $event->channel ?: ($payload['channel'] ?? 'cod'),
        ]);
    }

    private function repairEvent(OperationalEvent $event, Sku $sku, OperationalImpactCalculator $calculator, SkuStockMovementService $stockMovements): void
    {
        $business = $event->business ?: Business::query()->findOrFail($event->business_id);
        $payload = $this->payloadForRepair($event, $sku);
        $impact = $calculator->calculate($business, $payload);

        $event->update([
            'sku_id' => $impact['sku_id'] ?: $sku->id,
            'payload' => array_merge($payload, ['economics' => $impact['economics'] ?? []]),
            'revenue_amount' => $impact['revenue_amount'] ?? 0,
            'direct_cost_amount' => $impact['direct_cost_amount'] ?? 0,
            'leakage_amount' => $impact['leakage_amount'] ?? 0,
            'recovery_amount' => $impact['recovery_amount'] ?? 0,
        ]);

        $event->refresh();
        $stockMovements->record($business, $event, $event->payload ?? []);
    }

    private function productHint(OperationalEvent $event): string
    {
        $payload = $event->payload ?? [];

        $hint = $payload['sku_code'] ?? $payload['sku_name'] ?? $payload['product_name'] ?? $payload['item_name'] ?? null;

        return filled($hint) ? trim((string) $hint) : 'Product not sent';
    }

    private function groupKeyForEvent(OperationalEvent $event): string
    {
        $hint = $this->productHint($event);

        if ($hint === 'Product not sent') {
            return 'missing-product-hint';
        }

        return str($hint)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->value() ?: 'missing-product-hint';
    }

    /**
     * @return array{
     *     by_code: array<string, \App\Domains\Shared\Models\Sku>,
     *     by_name: array<string, \App\Domains\Shared\Models\Sku>,
     *     all: \Illuminate\Support\Collection<int, \App\Domains\Shared\Models\Sku>
     * }
     */
    private function skuDirectory(Business $business): array
    {
        $skus = Sku::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->orderBy('code')
            ->get();

        $byCode = [];
        $byName = [];
        $duplicateCodes = [];
        $duplicateNames = [];

        foreach ($skus as $sku) {
            $codeKey = $this->normalizeForMatch($sku->code);
            $nameKey = $this->normalizeForMatch($sku->name);

            if ($codeKey !== '') {
                if (isset($byCode[$codeKey])) {
                    $duplicateCodes[$codeKey] = true;
                } else {
                    $byCode[$codeKey] = $sku;
                }
            }

            if ($nameKey !== '') {
                if (isset($byName[$nameKey])) {
                    $duplicateNames[$nameKey] = true;
                } else {
                    $byName[$nameKey] = $sku;
                }
            }
        }

        foreach (array_keys($duplicateCodes) as $duplicateCode) {
            unset($byCode[$duplicateCode]);
        }

        foreach (array_keys($duplicateNames) as $duplicateName) {
            unset($byName[$duplicateName]);
        }

        return [
            'by_code' => $byCode,
            'by_name' => $byName,
            'all' => $skus,
        ];
    }

    private function obviousSkuMatch(OperationalEvent $event, array $skuDirectory): ?Sku
    {
        $payload = $event->payload ?? [];
        $candidates = [];

        foreach ([
            $payload['sku_code'] ?? null,
            $payload['product_code'] ?? null,
            $payload['item_code'] ?? null,
        ] as $rawCode) {
            $codeKey = $this->normalizeForMatch($rawCode);

            if ($codeKey !== '' && isset($skuDirectory['by_code'][$codeKey])) {
                $candidates[$skuDirectory['by_code'][$codeKey]->id] = $skuDirectory['by_code'][$codeKey];
            }
        }

        foreach ([
            $payload['sku_name'] ?? null,
            $payload['product_name'] ?? null,
            $payload['item_name'] ?? null,
            $this->productHint($event),
        ] as $rawName) {
            $nameKey = $this->normalizeForMatch($rawName);

            if ($nameKey !== '' && isset($skuDirectory['by_name'][$nameKey])) {
                $candidates[$skuDirectory['by_name'][$nameKey]->id] = $skuDirectory['by_name'][$nameKey];
            }
        }

        $hint = Str::lower(implode(' ', array_filter([
            $payload['sku_code'] ?? null,
            $payload['sku_name'] ?? null,
            $payload['product_name'] ?? null,
            $payload['item_name'] ?? null,
            $payload['reference'] ?? null,
            $this->productHint($event),
        ], fn ($value): bool => filled($value))));

        if ($hint !== '') {
            foreach ($skuDirectory['all'] as $sku) {
                $code = Str::lower((string) $sku->code);

                if ($code === '') {
                    continue;
                }

                if (preg_match('/(^|[^a-z0-9])'.preg_quote($code, '/').'([^a-z0-9]|$)/', $hint) === 1) {
                    $candidates[$sku->id] = $sku;
                }
            }
        }

        return count($candidates) === 1 ? array_values($candidates)[0] : null;
    }

    private function normalizeForMatch(mixed $value): string
    {
        if (! filled($value)) {
            return '';
        }

        return (string) str((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->trim();
    }

    /**
     * @return array<int>
     */
    private function accessibleBusinessIds(): array
    {
        return Auth::user()?->accessibleBusinessIds() ?? [];
    }
}

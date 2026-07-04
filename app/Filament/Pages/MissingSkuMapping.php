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
            OperationalEvent::ORDER_CREATED,
            OperationalEvent::ORDER_CONFIRMED,
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
     * @return array<int>
     */
    private function accessibleBusinessIds(): array
    {
        return Auth::user()?->accessibleBusinessIds() ?? [];
    }
}

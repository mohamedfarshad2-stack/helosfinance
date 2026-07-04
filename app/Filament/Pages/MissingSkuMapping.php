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
    protected static string $view = 'filament.pages.missing-sku-mapping';

    public ?int $businessId = null;

    public string $search = '';

    /** @var array<int, int|string|null> */
    public array $skuSelections = [];

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

        unset($this->skuSelections[$eventId]);

        Notification::make()
            ->title('Product link fixed')
            ->body('HELOS recalculated this row and updated the stock movement where it applies.')
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
            'product_hint' => $payload['sku_code'] ?? $payload['sku_name'] ?? $payload['product_name'] ?? 'Product not sent',
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

    /**
     * @return array<int>
     */
    private function accessibleBusinessIds(): array
    {
        return Auth::user()?->accessibleBusinessIds() ?? [];
    }
}

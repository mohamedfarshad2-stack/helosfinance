<?php

namespace App\Filament\Pages;

use App\Domains\FinancialClarity\Services\CourierRateService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\OperationalEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Throwable;

class SalesInsights extends Page
{
    protected static ?string $slug = 'sales-insights';
    protected static ?string $navigationGroup = 'Sales & Work';
    protected static ?string $navigationLabel = 'Sales Insights';
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.sales-insights';

    public ?int $businessId = null;
    public string $selectedDate = '';

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        @ini_set('memory_limit', '512M');

        $this->selectedDate = today()->toDateString();

        try {
            $this->businessId = $this->defaultBusinessId();
        } catch (Throwable $exception) {
            report($exception);

            $this->businessId = null;
        }
    }

    protected function getViewData(): array
    {
        try {
            $business = $this->selectedBusiness();
            $selectedDate = $this->selectedDateObject();
            $previousDate = $selectedDate->copy()->subDay();
            $weekStart = $selectedDate->copy()->startOfWeek();
            $weekEnd = $selectedDate->copy()->endOfWeek();
            $monthStart = $selectedDate->copy()->startOfMonth();
            $pageWarning = null;
            $today = $this->emptyStats();
            $yesterday = $this->emptyStats();
            $week = $this->emptyStats();
            $monthToDate = $this->emptyStats();
            $allTime = $this->emptyStats();
            $topProducts = collect();
            $missingProductLinks = 0;

            if ($business) {
                try {
                    $today = $this->periodStats($business, $selectedDate);
                    $yesterday = $this->periodStats($business, $previousDate);
                    $week = $this->rangeStats($business, $weekStart, $weekEnd);
                    $monthToDate = $this->rangeStats($business, $monthStart, $selectedDate->copy()->endOfDay());
                    $allTime = $this->rangeStats($business, $this->firstSalesDate($business, $selectedDate), $selectedDate->copy()->endOfDay());
                    $topProducts = $this->topProducts($business);
                    $missingProductLinks = $this->missingProductLinks($business);
                } catch (Throwable $exception) {
                    report($exception);

                    $pageWarning = 'Some historical sales rows are malformed. HELOS is showing only the safe part of Sales Insights until those rows are cleaned.';
                }
            }

            return [
                'businesses' => $this->businesses(),
                'business' => $business,
                'pageWarning' => $pageWarning,
                'todayLabel' => $selectedDate->format('M j, Y'),
                'yesterdayLabel' => $previousDate->format('M j, Y'),
                'weekLabel' => $weekStart->format('M j').' - '.$weekEnd->format('M j, Y'),
                'monthLabel' => $monthStart->format('M j').' - '.$selectedDate->format('M j, Y'),
                'allTimeLabel' => 'Up to '.$selectedDate->format('M j, Y'),
                'weekDates' => collect(range(0, 6))
                    ->map(fn (int $offset): array => [
                        'date' => $weekStart->copy()->addDays($offset)->toDateString(),
                        'label' => $weekStart->copy()->addDays($offset)->format('D j'),
                        'is_selected' => $weekStart->copy()->addDays($offset)->isSameDay($selectedDate),
                        'is_today' => $weekStart->copy()->addDays($offset)->isToday(),
                    ]),
                'today' => $today,
                'yesterday' => $yesterday,
                'week' => $week,
                'monthToDate' => $monthToDate,
                'allTime' => $allTime,
                'topProducts' => $topProducts,
                'missingProductLinks' => $missingProductLinks,
            ];
        } catch (Throwable $exception) {
            report($exception);

            $selectedDate = today();
            $previousDate = $selectedDate->copy()->subDay();
            $weekStart = $selectedDate->copy()->startOfWeek();
            $weekEnd = $selectedDate->copy()->endOfWeek();

            return [
                'businesses' => collect(),
                'business' => null,
                'pageWarning' => 'Sales Insights found malformed live data and switched to safe mode. The page is available, but historical rows still need cleanup.',
                'todayLabel' => $selectedDate->format('M j, Y'),
                'yesterdayLabel' => $previousDate->format('M j, Y'),
                'weekLabel' => $weekStart->format('M j').' - '.$weekEnd->format('M j, Y'),
                'monthLabel' => $selectedDate->copy()->startOfMonth()->format('M j').' - '.$selectedDate->format('M j, Y'),
                'allTimeLabel' => 'Up to '.$selectedDate->format('M j, Y'),
                'weekDates' => collect(range(0, 6))
                    ->map(fn (int $offset): array => [
                        'date' => $weekStart->copy()->addDays($offset)->toDateString(),
                        'label' => $weekStart->copy()->addDays($offset)->format('D j'),
                        'is_selected' => $weekStart->copy()->addDays($offset)->isSameDay($selectedDate),
                        'is_today' => $weekStart->copy()->addDays($offset)->isToday(),
                    ]),
                'today' => $this->emptyStats(),
                'yesterday' => $this->emptyStats(),
                'week' => $this->emptyStats(),
                'monthToDate' => $this->emptyStats(),
                'allTime' => $this->emptyStats(),
                'topProducts' => collect(),
                'missingProductLinks' => 0,
            ];
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false) || ($user?->isInternalAdmin() ?? false));
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false) || ($user?->isInternalAdmin() ?? false));
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
            $this->businessId = $this->defaultBusinessId();
        }

        $business = Business::query()
            ->whereIn('id', $this->accessibleBusinessIds())
            ->whereKey($this->businessId)
            ->first();

        if ($business) {
            return $business;
        }

        $fallback = Business::query()
            ->whereIn('id', $this->accessibleBusinessIds())
            ->orderBy('name')
            ->first();

        if ($fallback) {
            $this->businessId = $fallback->id;
        }

        return $fallback;
    }

    /**
     * @return Collection<int, string>
     */
    private function businesses(): Collection
    {
        return Business::query()
            ->whereIn('id', $this->accessibleBusinessIds())
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    /**
     * @return array<string, mixed>
     */
    private function periodStats(Business $business, Carbon $day): array
    {
        return $this->rangeStats($business, $day->copy()->startOfDay(), $day->copy()->endOfDay());
    }

    public function selectDate(string $date): void
    {
        $this->selectedDate = Carbon::parse($date)->toDateString();
    }

    public function updatedSelectedDate(string $value): void
    {
        $this->selectedDate = Carbon::parse($value)->toDateString();
    }

    public function moveDay(int $direction): void
    {
        $this->selectedDate = $this->selectedDateObject()
            ->addDays($direction)
            ->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function rangeStats(Business $business, Carbon $start, Carbon $end): array
    {
        $periodEvents = $this->salesEventsBetween($business, $start, $end);

        $delivered = $periodEvents->where('event_type', OperationalEvent::ORDER_DELIVERED);
        $returned = $periodEvents->where('event_type', OperationalEvent::ORDER_RETURNED);
        $dispatchMovement = $this->dispatchMovement($business, $periodEvents);
        $stockAppDispatchSignals = $this->stockAppDispatchSignals($business, $start, $end);
        $dispatchSnapshot = $this->dispatchSnapshot($business, $end);
        $pendingValue = (float) ($dispatchSnapshot['signal_value'] ?? 0);
        $marketingSpend = $this->marketingSpend($business, $start, $end);
        $profitAfterDirectCosts = (float) ($delivered->sum('revenue_amount') - $periodEvents->sum('direct_cost_amount') - $periodEvents->sum('leakage_amount') + $periodEvents->sum('recovery_amount'));

        return [
            'delivered_revenue' => round((float) $delivered->sum('revenue_amount'), 2),
            'delivered_count' => $delivered->count(),
            'dispatch_moved_value' => round((float) ($dispatchMovement['verified_value'] ?? 0), 2),
            'dispatch_moved_count' => (int) ($dispatchMovement['verified_count'] ?? 0),
            'dispatch_moved_hidden_value' => round((float) ($dispatchMovement['unverified_value'] ?? 0), 2),
            'dispatch_moved_hidden_count' => (int) ($dispatchMovement['unverified_count'] ?? 0),
            'dispatch_potential_profit' => round((float) ($dispatchMovement['potential_profit'] ?? 0), 2),
            'dispatch_expected_delivery_cost' => round((float) ($dispatchMovement['expected_delivery_cost'] ?? 0), 2),
            'dispatch_product_cost' => round((float) ($dispatchMovement['product_cost'] ?? 0), 2),
            'dispatch_missing_delivery_cost_count' => (int) ($dispatchMovement['missing_delivery_cost_count'] ?? 0),
            'stock_app_dispatch_value' => round((float) ($stockAppDispatchSignals['value'] ?? 0), 2),
            'stock_app_dispatch_count' => (int) ($stockAppDispatchSignals['count'] ?? 0),
            'dispatch_signal_value' => round((float) ($dispatchSnapshot['signal_value'] ?? 0), 2),
            'dispatch_signal_count' => (int) ($dispatchSnapshot['signal_count'] ?? 0),
            'dispatch_value' => round((float) ($dispatchSnapshot['verified_value'] ?? 0), 2),
            'dispatch_count' => (int) ($dispatchSnapshot['verified_count'] ?? 0),
            'dispatch_hidden_count' => (int) ($dispatchSnapshot['unverified_count'] ?? 0),
            'dispatch_hidden_value' => round((float) ($dispatchSnapshot['unverified_value'] ?? 0), 2),
            'order_day_dispatch_value' => round((float) ($dispatchSnapshot['order_day_value'] ?? 0), 2),
            'order_day_dispatch_count' => (int) ($dispatchSnapshot['order_day_count'] ?? 0),
            'pending_value' => round((float) ($dispatchSnapshot['signal_value'] ?? 0), 2),
            'pending_count' => (int) ($dispatchSnapshot['signal_count'] ?? 0),
            'returned_count' => $returned->count(),
            'return_cost' => round((float) $returned->sum('leakage_amount'), 2),
            'marketing_spend' => round($marketingSpend, 2),
            'marketing_per_delivered_order' => $delivered->count() > 0 ? round($marketingSpend / $delivered->count(), 2) : 0.0,
            'profit_after_direct_costs' => round($profitAfterDirectCosts, 2),
            'profit_after_marketing' => round($profitAfterDirectCosts - $marketingSpend, 2),
        ];
    }

    private function firstSalesDate(Business $business, Carbon $fallback): Carbon
    {
        $first = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereNotNull('occurred_at')
            ->oldest('occurred_at')
            ->value('occurred_at');

        if (! $first) {
            return $fallback->copy()->startOfDay();
        }

        try {
            return Carbon::parse($first)->startOfDay();
        } catch (Throwable) {
            return $fallback->copy()->startOfDay();
        }
    }

    /**
     * @return array{value: float, count: int}
     */
    private function stockAppDispatchSignals(Business $business, Carbon $start, Carbon $end): array
    {
        $events = OperationalEvent::query()
            ->select([
                'id',
                'business_id',
                'source',
                'event_type',
                'external_id',
                'revenue_amount',
                'payload',
                'occurred_at',
                'created_at',
            ])
            ->where('business_id', $business->id)
            ->whereIn('source', ['stock_app', 'stock_app_sync'])
            ->whereIn('event_type', [
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_RESENT,
            ])
            ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (OperationalEvent $event): string => $this->dispatchMovementKey($event))
            ->map(fn (Collection $group): OperationalEvent => $group->last());

        return [
            'value' => (float) $events->sum(fn (OperationalEvent $event): float => $this->saleAmount($event)),
            'count' => $events->count(),
        ];
    }

    /**
     * @return array{verified_value: float, verified_count: int, unverified_value: float, unverified_count: int}
     */
    private function dispatchMovement(Business $business, Collection $periodEvents): array
    {
        $dispatchEvents = $periodEvents
            ->whereIn('event_type', [
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_RESENT,
            ])
            ->groupBy(fn (OperationalEvent $event): string => $this->dispatchMovementKey($event))
            ->map(fn (Collection $group): OperationalEvent => $group
                ->sortBy(fn (OperationalEvent $event): string => sprintf(
                    '%s-%010d',
                    $this->effectiveOccurredAt($event)->format('Y-m-d H:i:s.u'),
                    $event->id
                ))
                ->last());

        $verified = $dispatchEvents->filter(fn (OperationalEvent $event): bool => $this->isVerifiedDispatchEvent($event));
        $unverified = $dispatchEvents->reject(fn (OperationalEvent $event): bool => $this->isVerifiedDispatchEvent($event));
        $potential = $this->dispatchPotential($business, $verified);

        return [
            'verified_value' => (float) $verified->sum(fn (OperationalEvent $event): float => $this->saleAmount($event)),
            'verified_count' => $verified->count(),
            'unverified_value' => (float) $unverified->sum(fn (OperationalEvent $event): float => $this->saleAmount($event)),
            'unverified_count' => $unverified->count(),
            ...$potential,
        ];
    }

    /**
     * @return array{potential_profit: float, expected_delivery_cost: float, product_cost: float, missing_delivery_cost_count: int}
     */
    private function dispatchPotential(Business $business, Collection $verifiedDispatchEvents): array
    {
        $expectedDeliveryCost = 0.0;
        $productCost = 0.0;
        $missingDeliveryCost = 0;

        foreach ($verifiedDispatchEvents as $event) {
            $eventProductCost = (float) $event->direct_cost_amount;
            $eventDeliveryCost = 0.0;

            if ($event->event_type === OperationalEvent::TRACKING_NUMBER_ADDED) {
                $eventDeliveryCost = $this->expectedDeliveryCost($business, $event);

                if ($eventDeliveryCost <= 0) {
                    $missingDeliveryCost++;
                }
            }

            $productCost += $eventProductCost;
            $expectedDeliveryCost += $eventDeliveryCost;
        }

        $value = (float) $verifiedDispatchEvents->sum(fn (OperationalEvent $event): float => $this->saleAmount($event));

        return [
            'potential_profit' => round($value - $productCost - $expectedDeliveryCost, 2),
            'expected_delivery_cost' => round($expectedDeliveryCost, 2),
            'product_cost' => round($productCost, 2),
            'missing_delivery_cost_count' => $missingDeliveryCost,
        ];
    }

    private function expectedDeliveryCost(Business $business, OperationalEvent $event): float
    {
        $payload = $this->eventPayload($event);
        $economics = (array) ($payload['economics'] ?? []);

        foreach (['actual_courier_cost_amount', 'delivery_charge_pending', 'courier_amount'] as $key) {
            if (isset($economics[$key]) && is_numeric($economics[$key]) && (float) $economics[$key] > 0) {
                return (float) $economics[$key];
            }
        }

        $rate = app(CourierRateService::class)->rateForBusiness($business->id, $payload['courier_name'] ?? null)
            ?? app(CourierRateService::class)->defaultRateForBusiness($business->id);

        return $rate ? (float) $rate->delivery_charge : 0.0;
    }

    /**
     * @return array{signal_value: float, signal_count: int, verified_value: float, verified_count: int, unverified_value: float, unverified_count: int, order_day_value: float, order_day_count: int}
     */
    private function dispatchSnapshot(Business $business, Carbon $asOf): array
    {
        $latestStatuses = [];
        $firstSeenDates = [];
        $selectedDate = $this->selectedDateObject();

        foreach ($this->salesEventsCursorUpTo($business, $asOf) as $event) {
            $parcelKey = $this->parcelKey($event);

            if (! array_key_exists($parcelKey, $firstSeenDates)) {
                $firstSeenDates[$parcelKey] = $this->effectiveOccurredAt($event)->toDateString();
            }

            $latestStatuses[$parcelKey] = $event;
        }

        $signalValue = 0.0;
        $signalCount = 0;
        $verifiedValue = 0.0;
        $verifiedCount = 0;
        $unverifiedValue = 0.0;
        $unverifiedCount = 0;
        $orderDayValue = 0.0;
        $orderDayCount = 0;

        foreach ($latestStatuses as $parcelKey => $event) {
            if (! in_array($event->event_type, [
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_RESENT,
            ], true)) {
                continue;
            }

            $amount = $this->saleAmount($event);
            $signalValue += $amount;
            $signalCount++;

            if ($this->isVerifiedDispatchEvent($event)) {
                $verifiedValue += $amount;
                $verifiedCount++;
            } else {
                $unverifiedValue += $amount;
                $unverifiedCount++;
            }

            if (($firstSeenDates[$parcelKey] ?? null) === $selectedDate->toDateString()) {
                $orderDayValue += $amount;
                $orderDayCount++;
            }
        }

        return [
            'signal_value' => $signalValue,
            'signal_count' => $signalCount,
            'verified_value' => $verifiedValue,
            'verified_count' => $verifiedCount,
            'unverified_value' => $unverifiedValue,
            'unverified_count' => $unverifiedCount,
            'order_day_value' => $orderDayValue,
            'order_day_count' => $orderDayCount,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function topProducts(Business $business): Collection
    {
        $dayStart = $this->selectedDateObject()->copy()->startOfDay();
        $dayEnd = $this->selectedDateObject()->copy()->endOfDay();

        return OperationalEvent::query()
            ->select([
                'id',
                'business_id',
                'sku_id',
                'event_type',
                'revenue_amount',
                'occurred_at',
            ])
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [$dayStart, $dayEnd])
            ->where('event_type', OperationalEvent::ORDER_DELIVERED)
            ->whereNotNull('sku_id')
            ->with(['sku:id,code,name'])
            ->get()
            ->groupBy('sku_id')
            ->map(fn (Collection $events): array => [
                'sku' => $events->first()?->sku?->code ?? 'Unknown SKU',
                'name' => $events->first()?->sku?->name ?? '',
                'count' => $events->count(),
                'revenue' => round((float) $events->sum('revenue_amount'), 2),
            ])
            ->sortByDesc('revenue')
            ->take(5)
            ->values();
    }

    private function missingProductLinks(Business $business): int
    {
        return OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereNull('sku_id')
            ->whereIn('event_type', [
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_DELIVERED,
                OperationalEvent::ORDER_RETURNED,
                OperationalEvent::ORDER_RESENT,
            ])
            ->count();
    }

    private function saleAmount(OperationalEvent $event): float
    {
        $payload = $this->eventPayload($event);

        return (float) ($payload['sale_amount'] ?? $payload['revenue_amount'] ?? $event->revenue_amount ?? 0);
    }

    /**
     * @return Collection<int, OperationalEvent>
     */
    private function salesEventsBetween(Business $business, Carbon $start, Carbon $end): Collection
    {
        $dayStart = $start->copy()->startOfDay();
        $dayEnd = $end->copy()->endOfDay();

        return OperationalEvent::query()
            ->select([
                'id',
                'business_id',
                'sku_id',
                'source',
                'event_type',
                'external_id',
                'revenue_amount',
                'direct_cost_amount',
                'leakage_amount',
                'recovery_amount',
                'payload',
                'occurred_at',
            ])
            ->where('business_id', $business->id)
            ->where('occurred_at', '<=', $dayEnd)
            ->whereIn('event_type', [
                OperationalEvent::ORDER_CREATED,
                OperationalEvent::ORDER_CONFIRMED,
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_DELIVERED,
                OperationalEvent::ORDER_RETURNED,
                OperationalEvent::ORDER_RESENT,
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->cursor()
            ->filter(fn (OperationalEvent $event): bool => $this->effectiveOccurredAt($event)->betweenIncluded($dayStart, $dayEnd))
            ->values()
            ->collect();
    }

    private function salesEventsCursorUpTo(Business $business, Carbon $end)
    {
        return OperationalEvent::query()
            ->select([
                'id',
                'business_id',
                'sku_id',
                'source',
                'event_type',
                'external_id',
                'revenue_amount',
                'direct_cost_amount',
                'leakage_amount',
                'recovery_amount',
                'payload',
                'occurred_at',
            ])
            ->where('business_id', $business->id)
            ->where('occurred_at', '<=', $end->copy()->endOfDay())
            ->whereIn('event_type', [
                OperationalEvent::ORDER_CREATED,
                OperationalEvent::ORDER_CONFIRMED,
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_DELIVERED,
                OperationalEvent::ORDER_RETURNED,
                OperationalEvent::ORDER_RESENT,
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->cursor();
    }

    private function effectiveOccurredAt(OperationalEvent $event): Carbon
    {
        $payload = $this->eventPayload($event);
        $payloadOccurredAt = trim((string) ($payload['occurred_at'] ?? ''));
        $storedOccurredAt = $event->getRawOriginal('occurred_at');

        if (
            in_array($event->source, ['stock_app_sync', 'stock_app'], true)
            && $payloadOccurredAt !== ''
            && filled($payload['stage_occurred_at_source'] ?? null)
        ) {
            try {
                return Carbon::parse($payloadOccurredAt);
            } catch (Throwable) {
                // Fall back to the stored event timestamp when legacy payloads contain bad dates.
            }
        }

        try {
            return Carbon::parse($storedOccurredAt ?: now());
        } catch (Throwable) {
            return now();
        }
    }

    private function parcelKey(OperationalEvent $event): string
    {
        $payload = $this->eventPayload($event);

        foreach ([
            'cod_order_id',
            'order_id',
            'order_number',
            'tracking_number',
            'reference',
        ] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if ($value !== '') {
                return $key.':'.$value;
            }
        }

        $externalId = trim((string) ($event->external_id ?? ''));

        if ($externalId !== '') {
            if (preg_match('/^(.*?)-(?:confirmed|delivered|returned|resent|tracking_number_added|tracking_added|tracking|dispatch|dispatched)(?:-|$)/i', $externalId, $matches) === 1) {
                return 'external:'.$matches[1];
            }

            return 'external:'.$externalId;
        }

        return 'event:'.$event->id;
    }

    private function dispatchMovementKey(OperationalEvent $event): string
    {
        $payload = $this->eventPayload($event);

        foreach ([
            'tracking_number',
            'cod_order_id',
            'order_id',
            'order_number',
            'reference',
        ] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if ($value !== '') {
                return $key.':'.$value;
            }
        }

        return $this->parcelKey($event);
    }

    private function isVerifiedDispatchEvent(OperationalEvent $event): bool
    {
        if (! in_array($event->event_type, [
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
            OperationalEvent::ORDER_RESENT,
        ], true)) {
            return true;
        }

        if ($event->source !== 'stock_app_sync') {
            return true;
        }

        $payload = $this->eventPayload($event);

        return filled($payload['stage_occurred_at_source'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(OperationalEvent $event): array
    {
        $rawPayload = $event->getRawOriginal('payload');

        if (is_array($rawPayload)) {
            return $rawPayload;
        }

        if (is_string($rawPayload) && trim($rawPayload) !== '') {
            $decoded = json_decode($rawPayload, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        try {
            $payload = $event->getAttributeValue('payload');

            return is_array($payload) ? $payload : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function marketingSpend(Business $business, Carbon $start, Carbon $end): float
    {
        return (float) Expense::query()
            ->where('business_id', $business->id)
            ->whereBetween('spent_on', [$start->toDateString(), $end->toDateString()])
            ->where('expense_type', 'variable')
            ->where(function (Builder $query): void {
                $query->whereRaw("LOWER(COALESCE(category, '')) = ?", ['marketing'])
                    ->orWhereRaw("LOWER(COALESCE(suggested_key, '')) = ?", ['marketing']);
            })
            ->sum('amount');
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStats(): array
    {
        return [
            'delivered_revenue' => 0.0,
            'delivered_count' => 0,
            'dispatch_moved_value' => 0.0,
            'dispatch_moved_count' => 0,
            'dispatch_moved_hidden_value' => 0.0,
            'dispatch_moved_hidden_count' => 0,
            'dispatch_potential_profit' => 0.0,
            'dispatch_expected_delivery_cost' => 0.0,
            'dispatch_product_cost' => 0.0,
            'dispatch_missing_delivery_cost_count' => 0,
            'stock_app_dispatch_value' => 0.0,
            'stock_app_dispatch_count' => 0,
            'dispatch_signal_value' => 0.0,
            'dispatch_signal_count' => 0,
            'dispatch_value' => 0.0,
            'dispatch_count' => 0,
            'dispatch_hidden_count' => 0,
            'dispatch_hidden_value' => 0.0,
            'order_day_dispatch_value' => 0.0,
            'order_day_dispatch_count' => 0,
            'pending_value' => 0.0,
            'pending_count' => 0,
            'returned_count' => 0,
            'return_cost' => 0.0,
            'marketing_spend' => 0.0,
            'marketing_per_delivered_order' => 0.0,
            'profit_after_direct_costs' => 0.0,
            'profit_after_marketing' => 0.0,
        ];
    }

    /**
     * @return array<int>
     */
    private function accessibleBusinessIds(): array
    {
        return Auth::user()?->accessibleBusinessIds() ?? [];
    }

    private function selectedDateObject(): Carbon
    {
        $date = trim($this->selectedDate);

        if ($date === '') {
            return today();
        }

        return Carbon::parse($date);
    }
}

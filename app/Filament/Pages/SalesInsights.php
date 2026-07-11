<?php

namespace App\Filament\Pages;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\OperationalEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

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

        $this->businessId = $this->defaultBusinessId();
        $this->selectedDate = today()->toDateString();
    }

    protected function getViewData(): array
    {
        $business = $this->selectedBusiness();
        $selectedDate = $this->selectedDateObject();
        $previousDate = $selectedDate->copy()->subDay();
        $weekStart = $selectedDate->copy()->startOfWeek();
        $weekEnd = $selectedDate->copy()->endOfWeek();

        return [
            'businesses' => $this->businesses(),
            'business' => $business,
            'todayLabel' => $selectedDate->format('M j, Y'),
            'yesterdayLabel' => $previousDate->format('M j, Y'),
            'weekLabel' => $weekStart->format('M j').' - '.$weekEnd->format('M j, Y'),
            'weekDates' => collect(range(0, 6))
                ->map(fn (int $offset): array => [
                    'date' => $weekStart->copy()->addDays($offset)->toDateString(),
                    'label' => $weekStart->copy()->addDays($offset)->format('D j'),
                    'is_selected' => $weekStart->copy()->addDays($offset)->isSameDay($selectedDate),
                    'is_today' => $weekStart->copy()->addDays($offset)->isToday(),
                ]),
            'today' => $business ? $this->periodStats($business, $selectedDate) : $this->emptyStats(),
            'yesterday' => $business ? $this->periodStats($business, $previousDate) : $this->emptyStats(),
            'week' => $business ? $this->rangeStats($business, $weekStart, $weekEnd) : $this->emptyStats(),
            'topProducts' => $business ? $this->topProducts($business) : collect(),
            'missingProductLinks' => $business ? $this->missingProductLinks($business) : 0,
        ];
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
        $periodEvents = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->whereIn('event_type', [
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_DELIVERED,
                OperationalEvent::ORDER_RETURNED,
                OperationalEvent::ORDER_RESENT,
            ])
            ->get();

        $delivered = $periodEvents->where('event_type', OperationalEvent::ORDER_DELIVERED);
        $returned = $periodEvents->where('event_type', OperationalEvent::ORDER_RETURNED);
        $dispatchMovement = $this->dispatchMovement($periodEvents);
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
            'dispatch_signal_value' => round((float) ($dispatchSnapshot['signal_value'] ?? 0), 2),
            'dispatch_signal_count' => (int) ($dispatchSnapshot['signal_count'] ?? 0),
            'dispatch_value' => round((float) ($dispatchSnapshot['verified_value'] ?? 0), 2),
            'dispatch_count' => (int) ($dispatchSnapshot['verified_count'] ?? 0),
            'dispatch_hidden_count' => (int) ($dispatchSnapshot['unverified_count'] ?? 0),
            'dispatch_hidden_value' => round((float) ($dispatchSnapshot['unverified_value'] ?? 0), 2),
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

    /**
     * @return array{verified_value: float, verified_count: int, unverified_value: float, unverified_count: int}
     */
    private function dispatchMovement(Collection $periodEvents): array
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
                    $event->occurred_at?->format('Y-m-d H:i:s.u') ?? '',
                    $event->id
                ))
                ->last());

        $verified = $dispatchEvents->filter(fn (OperationalEvent $event): bool => $this->isVerifiedDispatchEvent($event));
        $unverified = $dispatchEvents->reject(fn (OperationalEvent $event): bool => $this->isVerifiedDispatchEvent($event));

        return [
            'verified_value' => (float) $verified->sum(fn (OperationalEvent $event): float => $this->saleAmount($event)),
            'verified_count' => $verified->count(),
            'unverified_value' => (float) $unverified->sum(fn (OperationalEvent $event): float => $this->saleAmount($event)),
            'unverified_count' => $unverified->count(),
        ];
    }

    /**
     * @return array{signal_value: float, signal_count: int, verified_value: float, verified_count: int, unverified_value: float, unverified_count: int}
     */
    private function dispatchSnapshot(Business $business, Carbon $asOf): array
    {
        $events = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->where('occurred_at', '<=', $asOf->copy()->endOfDay())
            ->whereIn('event_type', [
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::WHOLESALE_PARCEL_SENT,
                OperationalEvent::ORDER_DELIVERED,
                OperationalEvent::ORDER_RETURNED,
                OperationalEvent::ORDER_RESENT,
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $latestStatuses = $events
            ->groupBy(fn (OperationalEvent $event): string => $this->parcelKey($event))
            ->map(fn (Collection $group): OperationalEvent => $group->last());

        $dispatchStatuses = $latestStatuses->filter(fn (OperationalEvent $event): bool => in_array($event->event_type, [
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
            OperationalEvent::ORDER_RESENT,
        ], true));

        $verified = $dispatchStatuses->filter(fn (OperationalEvent $event): bool => $this->isVerifiedDispatchEvent($event));
        $unverified = $dispatchStatuses->reject(fn (OperationalEvent $event): bool => $this->isVerifiedDispatchEvent($event));

        return [
            'signal_value' => (float) $dispatchStatuses->sum(fn (OperationalEvent $event): float => $this->saleAmount($event)),
            'signal_count' => $dispatchStatuses->count(),
            'verified_value' => (float) $verified->sum(fn (OperationalEvent $event): float => $this->saleAmount($event)),
            'verified_count' => $verified->count(),
            'unverified_value' => (float) $unverified->sum(fn (OperationalEvent $event): float => $this->saleAmount($event)),
            'unverified_count' => $unverified->count(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function topProducts(Business $business): Collection
    {
        return OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereDate('occurred_at', $this->selectedDateObject()->toDateString())
            ->where('event_type', OperationalEvent::ORDER_DELIVERED)
            ->whereNotNull('sku_id')
            ->with('sku')
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
        $payload = $event->payload ?? [];

        return (float) ($payload['sale_amount'] ?? $payload['revenue_amount'] ?? $event->revenue_amount ?? 0);
    }

    private function parcelKey(OperationalEvent $event): string
    {
        $payload = $event->payload ?? [];

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
        $payload = $event->payload ?? [];

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

        $payload = $event->payload ?? [];

        return filled($payload['stage_occurred_at_source'] ?? null);
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
            'dispatch_signal_value' => 0.0,
            'dispatch_signal_count' => 0,
            'dispatch_value' => 0.0,
            'dispatch_count' => 0,
            'dispatch_hidden_count' => 0,
            'dispatch_hidden_value' => 0.0,
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

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
        $events = OperationalEvent::query()
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

        $delivered = $events->where('event_type', OperationalEvent::ORDER_DELIVERED);
        $dispatchEvents = $events->whereIn('event_type', [OperationalEvent::TRACKING_NUMBER_ADDED, OperationalEvent::WHOLESALE_PARCEL_SENT, OperationalEvent::ORDER_RESENT]);
        $dispatched = $dispatchEvents->filter(fn (OperationalEvent $event): bool => $this->isVerifiedDispatchEvent($event));
        $unverifiedDispatch = $dispatchEvents->reject(fn (OperationalEvent $event): bool => $this->isVerifiedDispatchEvent($event));
        $unverifiedDispatchCount = $dispatchEvents->count() - $dispatched->count();
        $returned = $events->where('event_type', OperationalEvent::ORDER_RETURNED);

        $dispatchSignalValue = $dispatchEvents->sum(fn (OperationalEvent $event): float => $this->saleAmount($event));
        $dispatchValue = $dispatched->sum(fn (OperationalEvent $event): float => $this->saleAmount($event));
        $unverifiedDispatchValue = $unverifiedDispatch->sum(fn (OperationalEvent $event): float => $this->saleAmount($event));
        $pendingValue = $dispatchValue;
        $marketingSpend = $this->marketingSpend($business, $start, $end);
        $profitAfterDirectCosts = (float) ($delivered->sum('revenue_amount') - $events->sum('direct_cost_amount') - $events->sum('leakage_amount') + $events->sum('recovery_amount'));

        return [
            'delivered_revenue' => round((float) $delivered->sum('revenue_amount'), 2),
            'delivered_count' => $delivered->count(),
            'dispatch_signal_value' => round((float) $dispatchSignalValue, 2),
            'dispatch_signal_count' => $dispatchEvents->count(),
            'dispatch_value' => round((float) $dispatchValue, 2),
            'dispatch_count' => $dispatched->count(),
            'dispatch_hidden_count' => $unverifiedDispatchCount,
            'dispatch_hidden_value' => round((float) $unverifiedDispatchValue, 2),
            'pending_value' => round((float) $pendingValue, 2),
            'pending_count' => $dispatched->count(),
            'returned_count' => $returned->count(),
            'return_cost' => round((float) $returned->sum('leakage_amount'), 2),
            'marketing_spend' => round($marketingSpend, 2),
            'marketing_per_delivered_order' => $delivered->count() > 0 ? round($marketingSpend / $delivered->count(), 2) : 0.0,
            'profit_after_direct_costs' => round($profitAfterDirectCosts, 2),
            'profit_after_marketing' => round($profitAfterDirectCosts - $marketingSpend, 2),
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

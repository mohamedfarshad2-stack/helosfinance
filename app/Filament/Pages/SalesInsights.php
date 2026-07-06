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

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->businessId = $this->defaultBusinessId();
    }

    protected function getViewData(): array
    {
        $business = $this->selectedBusiness();

        return [
            'businesses' => $this->businesses(),
            'business' => $business,
            'today' => $business ? $this->periodStats($business, today()) : $this->emptyStats(),
            'yesterday' => $business ? $this->periodStats($business, today()->subDay()) : $this->emptyStats(),
            'week' => $business ? $this->rangeStats($business, now()->startOfWeek(), now()->endOfWeek()) : $this->emptyStats(),
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
        $dispatched = $events->whereIn('event_type', [OperationalEvent::TRACKING_NUMBER_ADDED, OperationalEvent::WHOLESALE_PARCEL_SENT, OperationalEvent::ORDER_RESENT]);
        $returned = $events->where('event_type', OperationalEvent::ORDER_RETURNED);

        $dispatchValue = $dispatched->sum(fn (OperationalEvent $event): float => $this->saleAmount($event));
        $pendingValue = $dispatchValue;
        $marketingSpend = $this->marketingSpend($business, $start, $end);
        $profitAfterDirectCosts = (float) ($delivered->sum('revenue_amount') - $events->sum('direct_cost_amount') - $events->sum('leakage_amount') + $events->sum('recovery_amount'));

        return [
            'delivered_revenue' => round((float) $delivered->sum('revenue_amount'), 2),
            'delivered_count' => $delivered->count(),
            'dispatch_value' => round((float) $dispatchValue, 2),
            'dispatch_count' => $dispatched->count(),
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
            ->whereDate('occurred_at', today()->toDateString())
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
            'dispatch_value' => 0.0,
            'dispatch_count' => 0,
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
}

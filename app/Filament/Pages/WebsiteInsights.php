<?php

namespace App\Filament\Pages;

use App\Domains\Shared\Models\CodOrder;
use App\Domains\Shared\Models\WebsiteAnalyticsEvent;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Auth;

class WebsiteInsights extends Page
{
    protected static ?string $slug = 'website-insights';
    protected static ?string $navigationGroup = 'Analytics';
    protected static ?string $navigationLabel = 'Website Insights';
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.website-insights';

    public string $siteKey = '';

    public array $summary = [];

    public array $topClicks = [];

    public array $topPages = [];

    public array $recentEvents = [];

    public array $orderSummary = [];

    public array $deviceBreakdown = [];

    public array $diagnosis = [];

    public string $trackerSnippet = '';

    public function mount(): void
    {
        $this->loadInsights();
    }

    public function refreshInsights(): void
    {
        $this->loadInsights();
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    protected function getViewData(): array
    {
        return [
            'siteKey' => $this->siteKey,
            'summary' => $this->summary,
            'topClicks' => $this->topClicks,
            'topPages' => $this->topPages,
            'recentEvents' => $this->recentEvents,
            'orderSummary' => $this->orderSummary,
            'deviceBreakdown' => $this->deviceBreakdown,
            'diagnosis' => $this->diagnosis,
            'trackerSnippet' => $this->trackerSnippet,
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return false;
    }

    private function loadInsights(): void
    {
        $this->siteKey = (string) config('analytics.site_key', config('app.name', 'site'));
        $query = WebsiteAnalyticsEvent::query()->where('site_key', $this->siteKey);
        $orders = CodOrder::query();
        $today = now()->startOfDay();
        $week = now()->subDays(7);
        $month = now()->subDays(30);

        $visitsToday = (clone $query)->where('event_type', 'visit')->where('occurred_at', '>=', $today)->count();
        $uniqueVisitorsToday = (clone $query)->where('event_type', 'visit')->where('occurred_at', '>=', $today)->distinct()->count('visitor_key');
        $visitsLast7Days = (clone $query)->where('event_type', 'visit')->where('occurred_at', '>=', $week)->count();
        $uniqueVisitorsLast30Days = (clone $query)->where('event_type', 'visit')->where('occurred_at', '>=', $month)->distinct()->count('visitor_key');
        $clicksLast7Days = (clone $query)->where('event_type', 'click')->where('occurred_at', '>=', $week)->count();
        $checkoutStarted = (clone $query)->whereIn('event_type', ['begin_checkout', 'checkout_started'])->distinct()->count('session_key');
        $checkoutCompleted = (clone $query)->whereIn('event_type', ['purchase_completed', 'order_completed'])->distinct()->count('session_key');
        $checkoutAbandoned = max($checkoutStarted - $checkoutCompleted, 0);
        $conversionRate = $checkoutStarted > 0 ? round(($checkoutCompleted / $checkoutStarted) * 100, 1) : 0.0;
        $totalOrders = (clone $orders)->count();
        $ordersLast30Days = (clone $orders)->whereDate('created_at', '>=', $month)->count();
        $confirmedOrders = (clone $orders)->where('status', CodOrder::STATUS_CONFIRMED)->count();
        $deliveredOrders = (clone $orders)->where('status', CodOrder::STATUS_DELIVERED)->count();
        $returnedOrders = (clone $orders)->where('status', CodOrder::STATUS_RETURNED)->count();
        $cancelledOrders = (clone $orders)->where('status', CodOrder::STATUS_CANCELLED)->count();
        $grossSales = (float) (clone $orders)->sum('sale_amount');
        $averageOrderValue = $totalOrders > 0 ? round($grossSales / $totalOrders, 2) : 0.0;
        $deliveryRate = $totalOrders > 0 ? round(($deliveredOrders / $totalOrders) * 100, 1) : 0.0;
        $returnRate = $totalOrders > 0 ? round(($returnedOrders / $totalOrders) * 100, 1) : 0.0;
        $visitToOrderRate = $visitsLast7Days > 0 ? round(($ordersLast30Days / $visitsLast7Days) * 100, 1) : 0.0;

        $this->summary = [
            'visits_today' => $visitsToday,
            'unique_today' => $uniqueVisitorsToday,
            'visits_week' => $visitsLast7Days,
            'unique_month' => $uniqueVisitorsLast30Days,
            'clicks_week' => $clicksLast7Days,
            'checkout_started' => $checkoutStarted,
            'checkout_completed' => $checkoutCompleted,
            'checkout_abandoned' => $checkoutAbandoned,
            'conversion_rate' => $conversionRate,
            'total_orders' => $totalOrders,
            'orders_last_30_days' => $ordersLast30Days,
            'gross_sales' => $grossSales,
            'average_order_value' => $averageOrderValue,
            'delivery_rate' => $deliveryRate,
            'return_rate' => $returnRate,
            'visit_to_order_rate' => $visitToOrderRate,
            'confirmed_orders' => $confirmedOrders,
            'delivered_orders' => $deliveredOrders,
            'returned_orders' => $returnedOrders,
            'cancelled_orders' => $cancelledOrders,
            'most_clicked_area' => 'No data yet',
            'most_viewed_page' => 'No data yet',
            'main_issue' => 'No data yet',
        ];

        $this->topClicks = (clone $query)
            ->where('event_type', 'click')
            ->selectRaw('COALESCE(NULLIF(label, \'\'), \'Unlabelled area\') as name, COUNT(*) as total')
            ->groupByRaw('COALESCE(NULLIF(label, \'\'), \'Unlabelled area\')')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn (WebsiteAnalyticsEvent $event): array => [
                'name' => $event->getAttribute('name'),
                'total' => (int) $event->getAttribute('total'),
            ])
            ->values()
            ->all();

        $this->topPages = (clone $query)
            ->where('event_type', 'visit')
            ->selectRaw('COALESCE(NULLIF(page_path, \'\'), COALESCE(NULLIF(page_url, \'\'), \'Unknown page\')) as name, COUNT(*) as total')
            ->groupByRaw('COALESCE(NULLIF(page_path, \'\'), COALESCE(NULLIF(page_url, \'\'), \'Unknown page\'))')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn (WebsiteAnalyticsEvent $event): array => [
                'name' => $event->getAttribute('name'),
                'total' => (int) $event->getAttribute('total'),
            ])
            ->values()
            ->all();

        $this->recentEvents = (clone $query)
            ->latest('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (WebsiteAnalyticsEvent $event): array => [
                'event_type' => $event->event_type,
                'label' => $event->label ?: $event->event_type,
                'page_path' => $event->page_path ?: $event->page_url,
                'occurred_at' => optional($event->occurred_at)->diffForHumans(),
            ])
            ->values()
            ->all();

        $this->deviceBreakdown = (clone $query)
            ->where('event_type', 'visit')
            ->selectRaw('COALESCE(NULLIF(device_type, \'\'), \'unknown\') as name, COUNT(*) as total')
            ->groupByRaw('COALESCE(NULLIF(device_type, \'\'), \'unknown\')')
            ->orderByDesc('total')
            ->get()
            ->map(fn (WebsiteAnalyticsEvent $event): array => [
                'name' => $event->getAttribute('name'),
                'total' => (int) $event->getAttribute('total'),
            ])
            ->values()
            ->all();

        if (! empty($this->topClicks)) {
            $this->summary['most_clicked_area'] = $this->topClicks[0]['name'] ?? 'No data yet';
        }

        if (! empty($this->topPages)) {
            $this->summary['most_viewed_page'] = $this->topPages[0]['name'] ?? 'No data yet';
        }

        $this->diagnosis = $this->buildDiagnosis([
            'visits' => $visitsLast7Days,
            'unique' => $uniqueVisitorsLast30Days,
            'clicks' => $clicksLast7Days,
            'started' => $checkoutStarted,
            'completed' => $checkoutCompleted,
            'orders' => $totalOrders,
            'returned' => $returnedOrders,
            'delivery_rate' => $deliveryRate,
        ]);

        if (! empty($this->diagnosis)) {
            $this->summary['main_issue'] = $this->diagnosis[0]['title'] ?? 'No data yet';
        }

        $this->trackerSnippet = sprintf(
            '<script defer src="%s"></script>',
            e(url('/analytics/tracker.js'))
        );
    }

    private function buildDiagnosis(array $stats): array
    {
        $visits = (int) ($stats['visits'] ?? 0);
        $clicks = (int) ($stats['clicks'] ?? 0);
        $started = (int) ($stats['started'] ?? 0);
        $completed = (int) ($stats['completed'] ?? 0);
        $orders = (int) ($stats['orders'] ?? 0);
        $returned = (int) ($stats['returned'] ?? 0);
        $deliveryRate = (float) ($stats['delivery_rate'] ?? 0);

        $items = [];

        if ($visits > 0 && $orders === 0) {
            $items[] = [
                'title' => 'Traffic is coming, but orders are not converting',
                'body' => 'Your ads may be driving visits, but customers are not completing the purchase flow yet.',
                'severity' => 'danger',
            ];
        }

        if ($clicks > 0 && $started === 0) {
            $items[] = [
                'title' => 'People are clicking, but not starting checkout',
                'body' => 'Product pages may need clearer size selection, trust cues, or stronger add-to-cart prompts.',
                'severity' => 'warning',
            ];
        }

        if ($started > 0 && $completed === 0) {
            $items[] = [
                'title' => 'Checkout is starting, but not finishing',
                'body' => 'This usually means a payment, shipping, or form-friction issue.',
                'severity' => 'warning',
            ];
        }

        if ($returned > 0) {
            $items[] = [
                'title' => 'Returns need attention',
                'body' => 'Wrong size, courier problems, or expectation mismatch may be hurting profit.',
                'severity' => 'danger',
            ];
        }

        if ($orders > 0 && $deliveryRate >= 80) {
            $items[] = [
                'title' => 'Delivery is healthy',
                'body' => 'Your fulfilment side looks steady, so the bigger opportunity is earlier in the funnel.',
                'severity' => 'success',
            ];
        }

        if (empty($items)) {
            $items[] = [
                'title' => 'Not enough data yet',
                'body' => 'Once the tracker is added to the public store, the dashboard will show where people are dropping off.',
                'severity' => 'neutral',
            ];
        }

        return $items;
    }
}

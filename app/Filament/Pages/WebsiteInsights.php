<?php

namespace App\Filament\Pages;

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
            'trackerSnippet' => $this->trackerSnippet,
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->isInternalAdmin() ?? false;
    }

    public static function canAccess(): bool
    {
        return Auth::check() && (Auth::user()?->isInternalAdmin() ?? false);
    }

    private function loadInsights(): void
    {
        $this->siteKey = (string) config('analytics.site_key', config('app.name', 'site'));
        $query = WebsiteAnalyticsEvent::query()->where('site_key', $this->siteKey);
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
            'most_clicked_area' => 'No data yet',
            'most_viewed_page' => 'No data yet',
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

        if (! empty($this->topClicks)) {
            $this->summary['most_clicked_area'] = $this->topClicks[0]['name'] ?? 'No data yet';
        }

        if (! empty($this->topPages)) {
            $this->summary['most_viewed_page'] = $this->topPages[0]['name'] ?? 'No data yet';
        }

        $this->trackerSnippet = sprintf(
            '<script defer src="%s"></script>',
            e(url('/analytics/tracker.js'))
        );
    }
}

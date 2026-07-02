<x-filament-panels::page>
    <div class="grid gap-6">
        <x-filament::section>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Admin only</div>
                    <h1 class="mt-1 text-2xl font-black text-gray-950 dark:text-white">Website Insights</h1>
                    <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                        A small read-only dashboard for traffic, click heat, and checkout drop-off. It starts collecting data once the tracker script is added to the public store.
                    </p>
                </div>

                <x-filament::button wire:click="refreshInsights" icon="heroicon-o-arrow-path">
                    Refresh
                </x-filament::button>
            </div>
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-filament::section>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Visits today</div>
                <div class="mt-2 text-3xl font-black text-gray-950 dark:text-white">{{ number_format($summary['visits_today'] ?? 0) }}</div>
                <div class="mt-1 text-sm text-gray-500">{{ number_format($summary['unique_today'] ?? 0) }} unique visitors</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Visits last 7 days</div>
                <div class="mt-2 text-3xl font-black text-gray-950 dark:text-white">{{ number_format($summary['visits_week'] ?? 0) }}</div>
                <div class="mt-1 text-sm text-gray-500">{{ number_format($summary['clicks_week'] ?? 0) }} tracked clicks</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Orders started</div>
                <div class="mt-2 text-3xl font-black text-gray-950 dark:text-white">{{ number_format($summary['checkout_started'] ?? 0) }}</div>
                <div class="mt-1 text-sm text-gray-500">{{ number_format($summary['checkout_completed'] ?? 0) }} completed</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Drop-off</div>
                <div class="mt-2 text-3xl font-black text-gray-950 dark:text-white">{{ number_format($summary['checkout_abandoned'] ?? 0) }}</div>
                <div class="mt-1 text-sm text-gray-500">{{ number_format($summary['conversion_rate'] ?? 0, 1) }}% conversion</div>
            </x-filament::section>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-filament::section>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total orders</div>
                <div class="mt-2 text-3xl font-black text-gray-950 dark:text-white">{{ number_format($orderSummary['total_orders'] ?? 0) }}</div>
                <div class="mt-1 text-sm text-gray-500">{{ number_format($orderSummary['orders_last_30_days'] ?? 0) }} in the last 30 days</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Gross sales</div>
                <div class="mt-2 text-3xl font-black text-gray-950 dark:text-white">LKR {{ number_format($orderSummary['gross_sales'] ?? 0, 2) }}</div>
                <div class="mt-1 text-sm text-gray-500">Average order value LKR {{ number_format($orderSummary['average_order_value'] ?? 0, 2) }}</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Delivery rate</div>
                <div class="mt-2 text-3xl font-black text-gray-950 dark:text-white">{{ number_format($orderSummary['delivery_rate'] ?? 0, 1) }}%</div>
                <div class="mt-1 text-sm text-gray-500">{{ number_format($orderSummary['delivered_orders'] ?? 0) }} delivered / {{ number_format($orderSummary['returned_orders'] ?? 0) }} returned</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Visit to order</div>
                <div class="mt-2 text-3xl font-black text-gray-950 dark:text-white">{{ number_format($orderSummary['visit_to_order_rate'] ?? 0, 1) }}%</div>
                <div class="mt-1 text-sm text-gray-500">Visits are turning into orders at this rate</div>
            </x-filament::section>
        </div>

        <x-filament::section>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">What the dashboard is telling you</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">These are the first reasons your ads may not be turning into orders.</p>
                </div>
                <div class="text-sm text-gray-500">
                    Main issue: <span class="font-medium text-gray-900 dark:text-white">{{ $summary['main_issue'] ?? 'No data yet' }}</span>
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($diagnosis as $item)
                    @php
                        $severity = $item['severity'] ?? 'neutral';
                        $cardClass = match ($severity) {
                            'danger' => 'border-red-200 bg-red-50 dark:border-red-900/60 dark:bg-red-950/40',
                            'warning' => 'border-amber-200 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/40',
                            'success' => 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/40',
                            default => 'border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950',
                        };
                    @endphp
                    <div class="rounded-2xl border p-4 shadow-sm {{ $cardClass }}">
                        <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item['title'] ?? 'Insight' }}</div>
                        <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $item['body'] ?? '' }}</div>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-400">
                        No diagnosis yet. Add the tracker script and let the data build up.
                    </div>
                @endforelse
            </div>
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <x-filament::section>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Most clicked areas</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Buttons, links, and marked areas that get the most attention.</p>
                    </div>
                    <div class="text-sm text-gray-500">
                        Top area: <span class="font-medium text-gray-900 dark:text-white">{{ $summary['most_clicked_area'] ?? 'No data yet' }}</span>
                    </div>
                </div>

                <div class="mt-4 grid gap-3">
                    @forelse ($topClicks as $item)
                        @php $width = min(100, max(5, (int) (($item['total'] ?? 0) / max(1, $topClicks[0]['total'] ?? 1) * 100))); @endphp
                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['name'] ?? 'Unlabelled area' }}</div>
                                <div class="text-sm text-gray-500">{{ number_format($item['total'] ?? 0) }} clicks</div>
                            </div>
                            <div class="mt-3 h-2 rounded-full bg-gray-100 dark:bg-gray-800">
                                <div class="h-2 rounded-full bg-emerald-500" style="width: {{ $width }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-400">
                            No click data yet. Add the tracker script to the store and mark key buttons with <code>data-track-click</code>.
                        </div>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Top landing pages</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Where customers enter most often.</p>
                    </div>
                    <div class="text-sm text-gray-500">
                        Top page: <span class="font-medium text-gray-900 dark:text-white">{{ $summary['most_viewed_page'] ?? 'No data yet' }}</span>
                    </div>
                </div>

                <div class="mt-4 grid gap-3">
                    @forelse ($topPages as $item)
                        @php $width = min(100, max(5, (int) (($item['total'] ?? 0) / max(1, $topPages[0]['total'] ?? 1) * 100))); @endphp
                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['name'] ?? 'Unknown page' }}</div>
                                <div class="text-sm text-gray-500">{{ number_format($item['total'] ?? 0) }} visits</div>
                            </div>
                            <div class="mt-3 h-2 rounded-full bg-gray-100 dark:bg-gray-800">
                                <div class="h-2 rounded-full bg-sky-500" style="width: {{ $width }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-400">
                            No page data yet. Visits will appear here after the tracker script is loaded.
                        </div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>

        <div class="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
            <x-filament::section>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Device mix</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Useful for spotting mobile friction in product pages and checkout.</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3">
                    @forelse ($deviceBreakdown as $item)
                        @php $width = min(100, max(5, (int) (($item['total'] ?? 0) / max(1, $deviceBreakdown[0]['total'] ?? 1) * 100))); @endphp
                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ ucfirst($item['name'] ?? 'unknown') }}</div>
                                <div class="text-sm text-gray-500">{{ number_format($item['total'] ?? 0) }} visits</div>
                            </div>
                            <div class="mt-3 h-2 rounded-full bg-gray-100 dark:bg-gray-800">
                                <div class="h-2 rounded-full bg-violet-500" style="width: {{ $width }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-400">
                            Device data will appear after the tracker starts recording visits.
                        </div>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Order health</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">A quick view of sales quality and fulfilment.</p>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Confirmed</div>
                        <div class="mt-2 text-2xl font-black text-gray-950 dark:text-white">{{ number_format($orderSummary['confirmed_orders'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Delivered</div>
                        <div class="mt-2 text-2xl font-black text-gray-950 dark:text-white">{{ number_format($orderSummary['delivered_orders'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Returned</div>
                        <div class="mt-2 text-2xl font-black text-gray-950 dark:text-white">{{ number_format($orderSummary['returned_orders'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Cancelled</div>
                        <div class="mt-2 text-2xl font-black text-gray-950 dark:text-white">{{ number_format($orderSummary['cancelled_orders'] ?? 0) }}</div>
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-5 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                    If visits are high but orders stay low, the next things to inspect are size selection, product photos, shipping cost clarity, and checkout trust.
                </div>
            </x-filament::section>
        </div>

        <div class="grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
            <x-filament::section>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Install snippet</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Put this in the public theme footer or header. It records visits and clicks for the dashboard.
                </p>

                <div class="mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-gray-950 p-4 text-sm text-gray-100 dark:border-gray-800">
                    <code>{{ $trackerSnippet }}</code>
                </div>

                <div class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    Use <code>data-track-click="1"</code> and optional <code>data-track-label="Size 42"</code> on buttons or product areas you want counted.
                </div>
            </x-filament::section>

            <x-filament::section>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Latest activity</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Recent visits and interaction events.</p>

                <div class="mt-4 grid gap-3">
                    @forelse ($recentEvents as $event)
                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $event['label'] ?? 'Event' }}</div>
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $event['event_type'] ?? 'event' }}</div>
                            </div>
                            <div class="mt-1 text-sm text-gray-500">{{ $event['page_path'] ?? 'Unknown page' }}</div>
                            <div class="mt-2 text-xs text-gray-400">{{ $event['occurred_at'] ?? '' }}</div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-400">
                            No recent activity yet.
                        </div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>

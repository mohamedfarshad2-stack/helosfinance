<x-filament-panels::page>
    <div class="grid gap-6">
        <x-filament::section>
            <form wire:submit.prevent="refreshReport">
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                    {{ $this->form }}
                    <x-filament::button type="submit" icon="heroicon-o-funnel">
                        Show dashboard
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        @if (! $business || ! $snapshot)
            <x-filament::section>
                <div class="text-sm text-gray-500">Select a business and date range to view parcel performance.</div>
            </x-filament::section>
        @else
            @php
                $metrics = is_array($snapshot->metrics) ? $snapshot->metrics : [];
                $money = fn (float|int|string|null $value): string => 'LKR '.number_format((float) $value, 2);
                $number = fn (float|int|string|null $value): string => number_format((float) $value, 0);
                $start = \Illuminate\Support\Carbon::parse($snapshot->period_start)->format('M j, Y');
                $end = \Illuminate\Support\Carbon::parse($snapshot->period_end)->format('M j, Y');
                $dispatchedValue = (float) data_get($metrics, 'dispatched_parcel_value', 0);
                $deliveredRevenue = (float) $snapshot->revenue_total;
                $pendingValue = (float) data_get($metrics, 'pending_dispatch_value', 0);
                $productCosts = (float) data_get($metrics, 'production_costs', data_get($metrics, 'product_costs', 0));
                $productionPendingPay = (float) data_get($metrics, 'production_pending_pay', 0);
                $missingEstimatedProductionCosts = (float) data_get($metrics, 'missing_estimated_production_costs', 0);
                $productionCostForOwner = $productCosts + $missingEstimatedProductionCosts;
                $profitForOwner = (float) data_get($metrics, 'profit_after_estimated_production_costs', $snapshot->estimated_profit);
                $productionCostTrusted = (bool) data_get($metrics, 'production_cost_trusted', true);
                $courierCosts = (float) data_get($metrics, 'total_courier_costs', 0);
                $grossProfit = (float) data_get($metrics, 'parcel_gross_profit', 0);
                $netProfit = (float) $snapshot->estimated_profit;
                $allCosts = (float) $snapshot->cost_total + $missingEstimatedProductionCosts;
                $deliveredCount = (int) data_get($metrics, 'order_counts.delivered', 0);
                $pendingCount = (int) data_get($metrics, 'pending_dispatch_count', 0);
                $returnedCount = (int) data_get($metrics, 'order_counts.returned', 0);
                $dispatchCount = (int) data_get($metrics, 'dispatched_parcel_count', 0);
                $deliveredCohorts = (array) data_get($metrics, 'delivered_cohorts', []);
                $currentMonthDeliveredCount = (int) data_get($deliveredCohorts, 'confirmed_this_month.count', 0);
                $currentMonthDeliveredValue = (float) data_get($deliveredCohorts, 'confirmed_this_month.value', 0);
                $carryoverDeliveredCount = (int) data_get($deliveredCohorts, 'carryover_from_earlier_months.count', 0);
                $carryoverDeliveredValue = (float) data_get($deliveredCohorts, 'carryover_from_earlier_months.value', 0);
                $missingConfirmationDeliveredCount = (int) data_get($deliveredCohorts, 'confirmation_missing.count', 0);
                $missingConfirmationDeliveredValue = (float) data_get($deliveredCohorts, 'confirmation_missing.value', 0);
                $invalidConfirmationDeliveredCount = (int) data_get($deliveredCohorts, 'invalid_confirmation_sequence.count', 0);
                $invalidConfirmationDeliveredValue = (float) data_get($deliveredCohorts, 'invalid_confirmation_sequence.value', 0);
                $unknownMonthDeliveredCount = $missingConfirmationDeliveredCount + $invalidConfirmationDeliveredCount;
                $unknownMonthDeliveredValue = $missingConfirmationDeliveredValue + $invalidConfirmationDeliveredValue;
                $deliveryRate = $dispatchCount > 0 ? min(100, max(0, ($deliveredCount / $dispatchCount) * 100)) : 0;
                $pendingRate = $dispatchCount > 0 ? min(100, max(0, ($pendingCount / $dispatchCount) * 100)) : 0;
                $returnRate = $dispatchCount > 0 ? min(100, max(0, ($returnedCount / $dispatchCount) * 100)) : 0;
                $profitTone = $profitForOwner >= 0 ? 'emerald' : 'rose';
                $profitLabel = 'Estimated profit from known costs';
                $profitHelper = $productionCostTrusted ? 'Delivered sales - recorded costs in HELOS' : 'Includes estimated missing production cost';
                $profitTrustNote = $productionCostTrusted
                    ? 'Not final company net profit. HELOS can only subtract overheads, salaries, marketing, utilities, rent, expenses, leakage and recoveries that are entered.'
                    : 'Not fully trusted until Nifras enters production data for this period.';
                $decisionText = ! $productionCostTrusted
                    ? 'Production cost is not fully entered yet. HELOS is reducing owner profit using estimated missing production cost until daily entries catch up.'
                    : ($profitForOwner >= 0
                        ? 'The selected period is positive after known HELOS costs. It is not final company net profit until every company expense is entered.'
                        : 'The selected period is negative after known HELOS costs. This can happen when delivered sales are lower than recorded courier, production, salary, overhead, expense, leakage, and estimated missing production costs.');
                $heroStats = [
                    [
                        'label' => 'Delivered sales',
                        'value' => $money($deliveredRevenue),
                        'helper' => $number($currentMonthDeliveredCount).' this month / '.$number($carryoverDeliveredCount).' carryover / '.$number($unknownMonthDeliveredCount).' unknown',
                        'icon' => 'heroicon-o-check-circle',
                        'style' => 'from-emerald-500 to-teal-500',
                    ],
                    [
                        'label' => 'Pending delivery',
                        'value' => $money($pendingValue),
                        'helper' => $number($pendingCount).' parcel(s) not revenue yet',
                        'icon' => 'heroicon-o-clock',
                        'style' => 'from-amber-400 to-orange-500',
                    ],
                    [
                        'label' => $profitLabel,
                        'value' => $money($profitForOwner),
                        'helper' => $profitHelper,
                        'icon' => 'heroicon-o-arrow-trending-up',
                        'style' => $profitForOwner >= 0 && $productionCostTrusted ? 'from-lime-400 to-emerald-500' : 'from-rose-500 to-red-500',
                    ],
                ];
                $rolePanels = [
                    [
                        'title' => 'Owner view',
                        'kicker' => 'Decision',
                        'value' => ! $productionCostTrusted ? 'Profit not trusted yet' : ($profitForOwner >= 0 ? 'Protect profit' : 'Recover margin'),
                        'body' => $decisionText,
                        'icon' => 'heroicon-o-sparkles',
                        'style' => 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100',
                    ],
                    [
                        'title' => 'Manager view',
                        'kicker' => 'Team focus',
                        'value' => $number($pendingCount).' pending',
                        'body' => 'Push pending parcels to delivered, reduce returns, and keep parcel statuses accurate every day.',
                        'icon' => 'heroicon-o-user-group',
                        'style' => 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100',
                    ],
                    [
                        'title' => 'Finance view',
                        'kicker' => 'Cost control',
                        'value' => $money($productionCostForOwner + $courierCosts),
                        'body' => 'Production cost comes from Nifras daily entries. Delivery only reduces courier cost when the parcel is delivered. Company expenses must be entered before final net profit is trusted.',
                        'icon' => 'heroicon-o-banknotes',
                        'style' => 'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-950 dark:border-fuchsia-900 dark:bg-fuchsia-950/30 dark:text-fuchsia-100',
                    ],
                ];
                $cards = [
                    [
                        'label' => 'Dispatched this period',
                        'count' => $number($dispatchCount).' parcels',
                        'value' => $money($dispatchedValue),
                        'hint' => 'Parcel value sent to courier during this period. This is not revenue yet.',
                        'icon' => 'heroicon-o-truck',
                        'style' => 'border-cyan-200 bg-cyan-50 text-cyan-950 dark:border-cyan-900 dark:bg-cyan-950/30 dark:text-cyan-100',
                    ],
                    [
                        'label' => 'Production costs',
                        'count' => $productionCostTrusted ? 'Raw material + labour entered daily' : 'Daily entries missing - using estimate',
                        'value' => $money($productionCostForOwner),
                        'hint' => $productionCostTrusted
                            ? 'Monthly factory cost from production entries. Pending piece-pay: '.$money($productionPendingPay).'.'
                            : 'Recorded production: '.$money($productCosts).'. Estimated missing production cost: '.$money($missingEstimatedProductionCosts).'.',
                        'icon' => 'heroicon-o-cube',
                        'style' => 'border-violet-200 bg-violet-50 text-violet-950 dark:border-violet-900 dark:bg-violet-950/30 dark:text-violet-100',
                    ],
                    [
                        'label' => 'Courier costs',
                        'count' => 'Delivered + returned charges',
                        'value' => $money($courierCosts),
                        'hint' => $money(data_get($metrics, 'delivered_courier_costs', 0)).' delivered / '.$money(data_get($metrics, 'return_courier_costs', 0)).' returned',
                        'icon' => 'heroicon-o-map-pin',
                        'style' => 'border-orange-200 bg-orange-50 text-orange-950 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-100',
                    ],
                    [
                        'label' => 'Pending delivery',
                        'count' => $number($pendingCount).' parcels',
                        'value' => $money($pendingValue),
                        'hint' => 'The team must push these parcels toward successful delivery.',
                        'icon' => 'heroicon-o-clock',
                        'style' => 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100',
                    ],
                    [
                        'label' => 'Delivered sales',
                        'count' => $number($deliveredCount).' parcels',
                        'value' => $money($deliveredRevenue),
                        'hint' => 'All parcels delivered in this period: current-month, carryover, and any delivered parcels missing original order dates.',
                        'icon' => 'heroicon-o-check-badge',
                        'style' => 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100',
                    ],
                    [
                        'label' => 'This month orders delivered',
                        'count' => $number($currentMonthDeliveredCount).' parcels',
                        'value' => $money($currentMonthDeliveredValue),
                        'hint' => 'Pure current-month orders: confirmed in this period and delivered in this period.',
                        'icon' => 'heroicon-o-calendar-days',
                        'style' => 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100',
                    ],
                    [
                        'label' => 'Carryover delivered',
                        'count' => $number($carryoverDeliveredCount).' parcels',
                        'value' => $money($carryoverDeliveredValue),
                        'hint' => 'Older orders confirmed before this period but delivered now. Keep separate from pure current-month delivery.',
                        'icon' => 'heroicon-o-arrow-path',
                        'style' => 'border-indigo-200 bg-indigo-50 text-indigo-950 dark:border-indigo-900 dark:bg-indigo-950/30 dark:text-indigo-100',
                    ],
                    [
                        'label' => 'Delivered but month unknown',
                        'count' => $number($unknownMonthDeliveredCount).' parcels',
                        'value' => $money($unknownMonthDeliveredValue),
                        'hint' => 'Delivered now, but HELOS cannot find the original confirmed/order date, so it cannot call these pure this-month or carryover yet.',
                        'icon' => 'heroicon-o-question-mark-circle',
                        'style' => $unknownMonthDeliveredCount > 0
                            ? 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100'
                            : 'border-gray-200 bg-gray-50 text-gray-950 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100',
                    ],
                    [
                        'label' => $profitLabel,
                        'count' => $profitHelper,
                        'value' => $money($profitForOwner),
                        'hint' => $profitTrustNote,
                        'icon' => $profitForOwner >= 0 && $productionCostTrusted ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-exclamation-triangle',
                        'style' => $profitForOwner >= 0 && $productionCostTrusted
                            ? 'border-lime-200 bg-lime-50 text-lime-950 dark:border-lime-900 dark:bg-lime-950/30 dark:text-lime-100'
                            : 'border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100',
                    ],
                ];
                $bridge = [
                    ['label' => 'Delivered sales', 'value' => $deliveredRevenue, 'color' => 'bg-emerald-500'],
                    ['label' => 'Known HELOS costs', 'value' => -$allCosts, 'color' => 'bg-orange-500'],
                    ['label' => $profitLabel, 'value' => $profitForOwner, 'color' => $profitForOwner >= 0 && $productionCostTrusted ? 'bg-lime-500' : 'bg-rose-500'],
                    ['label' => 'Parcel gross before overhead', 'value' => $grossProfit, 'color' => $grossProfit >= 0 ? 'bg-cyan-500' : 'bg-rose-400'],
                ];
                $maxBridge = max(abs($deliveredRevenue), abs($allCosts), abs($profitForOwner), abs($grossProfit), 1);
            @endphp

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-950">
                <div class="grid gap-0 xl:grid-cols-[1.15fr_0.85fr]">
                    <div class="bg-gradient-to-br from-gray-950 via-emerald-950 to-cyan-950 p-6 text-white">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-wide text-emerald-100">
                                    <x-filament::icon icon="heroicon-o-chart-bar-square" class="h-4 w-4" />
                                    Owner parcel dashboard
                                </div>
                                <h2 class="mt-4 text-3xl font-black leading-tight md:text-4xl">{{ $business->name }}</h2>
                                <p class="mt-2 text-sm font-medium text-cyan-100">Report period: {{ $start }} to {{ $end }}</p>
                            </div>
                            <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 text-right backdrop-blur">
                                <div class="text-xs uppercase tracking-wide text-cyan-100">Owner action</div>
                                <div class="mt-1 text-sm font-bold">{{ ! $productionCostTrusted ? 'Enter production data first' : ($profitForOwner >= 0 ? 'Scale what is working' : 'Fix margin leakage first') }}</div>
                            </div>
                        </div>

                        <div class="mt-6 grid gap-3 md:grid-cols-3">
                            @foreach ($heroStats as $stat)
                                <div class="rounded-xl border border-white/15 bg-white/10 p-4 backdrop-blur">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="text-xs font-bold uppercase tracking-wide text-white/70">{{ $stat['label'] }}</div>
                                        <div class="rounded-lg bg-gradient-to-br {{ $stat['style'] }} p-2 text-white shadow-sm">
                                            <x-filament::icon :icon="$stat['icon']" class="h-5 w-5" />
                                        </div>
                                    </div>
                                    <div class="mt-4 text-2xl font-black">{{ $stat['value'] }}</div>
                                    <div class="mt-1 text-xs leading-5 text-white/75">{{ $stat['helper'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid content-between gap-5 bg-gray-50 p-6 dark:bg-gray-900">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Parcel conversion</div>
                            <div class="mt-2 text-lg font-black text-gray-950 dark:text-white">Where dispatched value is sitting now</div>
                            <div class="mt-5 grid gap-4">
                                <div>
                                    <div class="flex justify-between text-xs font-semibold text-gray-600 dark:text-gray-300">
                                        <span>Delivered</span><span>{{ number_format($deliveryRate, 1) }}%</span>
                                    </div>
                                    <div class="mt-2 h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                                        <div class="h-full rounded-full bg-emerald-500" style="width: {{ $deliveryRate }}%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-xs font-semibold text-gray-600 dark:text-gray-300">
                                        <span>Pending</span><span>{{ number_format($pendingRate, 1) }}%</span>
                                    </div>
                                    <div class="mt-2 h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                                        <div class="h-full rounded-full bg-amber-400" style="width: {{ $pendingRate }}%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-xs font-semibold text-gray-600 dark:text-gray-300">
                                        <span>Returned</span><span>{{ number_format($returnRate, 1) }}%</span>
                                    </div>
                                    <div class="mt-2 h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                                        <div class="h-full rounded-full bg-rose-500" style="width: {{ $returnRate }}%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-200">
                            <div class="font-black text-gray-950 dark:text-white">Simple owner rule</div>
                            <p class="mt-2 leading-6">Delivered is revenue. Pending is opportunity. Returned is loss pressure. Pure this-month delivery is separated from carryover delivery so old parcel recovery does not hide current-month performance.</p>
                            @if ($missingConfirmationDeliveredCount > 0)
                                <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                    {{ $number($missingConfirmationDeliveredCount) }} delivered parcel(s) are missing confirmation dates, so HELOS cannot classify them as this-month or carryover yet.
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-3">
                @foreach ($rolePanels as $panel)
                    <div class="rounded-2xl border p-5 shadow-sm {{ $panel['style'] }}">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-xs font-bold uppercase tracking-wide opacity-70">{{ $panel['kicker'] }}</div>
                                <div class="mt-1 text-lg font-black">{{ $panel['title'] }}</div>
                            </div>
                            <div class="rounded-xl bg-white/80 p-2 shadow-sm dark:bg-gray-950/60">
                                <x-filament::icon :icon="$panel['icon']" class="h-6 w-6" />
                            </div>
                        </div>
                        <div class="mt-5 text-2xl font-black">{{ $panel['value'] }}</div>
                        <p class="mt-3 text-sm leading-6 opacity-80">{{ $panel['body'] }}</p>
                    </div>
                @endforeach
            </div>

            @if (! empty($serviceClientNames))
                <div class="rounded-2xl border border-teal-200 bg-gradient-to-r from-teal-50 via-white to-cyan-50 p-5 shadow-sm dark:border-teal-900 dark:from-teal-950/30 dark:via-gray-950 dark:to-cyan-950/30">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wide text-teal-700 dark:text-teal-200">Service collection watch</div>
                            <div class="mt-1 text-xl font-black text-gray-950 dark:text-white">Clients that still matter in this period</div>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">For service businesses, owner revenue depends on billing records and collection follow-up, not parcel delivery.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($serviceClientNames as $clientName)
                                <span class="rounded-full border border-teal-200 bg-white px-3 py-1 text-sm font-semibold text-teal-900 shadow-sm dark:border-teal-900 dark:bg-gray-950 dark:text-teal-100">{{ $clientName }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <div class="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Profit bridge</div>
                            <div class="mt-1 text-xl font-black text-gray-950 dark:text-white">How the parcel result is built</div>
                        </div>
                        <div class="rounded-xl bg-gray-100 p-2 text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <x-filament::icon icon="heroicon-o-presentation-chart-line" class="h-6 w-6" />
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4">
                        @foreach ($bridge as $row)
                            @php
                                $width = min(100, max(4, (abs($row['value']) / $maxBridge) * 100));
                            @endphp
                            <div>
                                <div class="flex justify-between gap-4 text-sm">
                                    <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $row['label'] }}</span>
                                    <strong class="{{ $row['value'] < 0 ? 'text-rose-600' : 'text-gray-950 dark:text-white' }}">{{ $money($row['value']) }}</strong>
                                </div>
                                <div class="mt-2 h-3 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-900">
                                    <div class="h-full rounded-full {{ $row['color'] }}" style="width: {{ $width }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($cards as $card)
                        <div class="rounded-2xl border p-5 shadow-sm {{ $card['style'] }}">
                            <div class="flex items-start justify-between gap-4">
                                <div class="text-sm font-black uppercase tracking-wide">{{ $card['label'] }}</div>
                                <div class="rounded-xl bg-white/80 p-2 shadow-sm dark:bg-gray-950/60">
                                    <x-filament::icon :icon="$card['icon']" class="h-5 w-5" />
                                </div>
                            </div>
                            <div class="mt-5 text-2xl font-black text-gray-950 dark:text-white">{{ $card['value'] }}</div>
                            <div class="mt-2 text-sm font-bold">{{ $card['count'] }}</div>
                            <div class="mt-4 border-t border-current/10 pt-3 text-xs leading-5 opacity-75">{{ $card['hint'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 via-white to-emerald-50 p-5 shadow-sm dark:border-amber-900 dark:from-amber-950/30 dark:via-gray-950 dark:to-emerald-950/30">
                <div class="flex gap-3">
                    <div class="rounded-xl bg-amber-400 p-2 text-amber-950">
                        <x-filament::icon icon="heroicon-o-light-bulb" class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="font-black text-gray-950 dark:text-white">HELOS focus for this stage</div>
                        <p class="mt-1 text-sm leading-6 text-gray-700 dark:text-gray-200">Keep parcel statuses accurate every day, convert pending deliveries into delivered revenue, and reduce returns before adding more spending. This is the owner view first; manager and finance signals are separated above.</p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>

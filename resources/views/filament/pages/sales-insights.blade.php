<x-filament-panels::page>
    <div class="grid gap-6">
        @if (filled($pageWarning ?? null))
            <x-filament::section>
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                    {{ $pageWarning }}
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div class="grid gap-4 lg:grid-cols-[1fr_0.35fr] lg:items-start">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Sales command view</div>
                    <h2 class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">Selected day, month, and all data</h2>
                    <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                        The first cards are only for the open date. Month and all-data cards below show the bigger picture. Delivered orders count as sales; dispatched parcels are still money at risk until delivered or returned.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-medium">
                        <button type="button" wire:click="moveDay(-1)" class="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">Previous day</button>
                        <button type="button" wire:click="selectDate('{{ now()->toDateString() }}')" class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">Today: {{ now()->format('M j, Y') }}</button>
                        <button type="button" wire:click="selectDate('{{ now()->subDay()->toDateString() }}')" class="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">Yesterday: {{ now()->subDay()->format('M j, Y') }}</button>
                        <button type="button" wire:click="moveDay(1)" class="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">Next day</button>
                        <span class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">This week: {{ $weekLabel }}</span>
                        <label class="ml-2 inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <span>Open date</span>
                            <input wire:model.live="selectedDate" type="date" class="fi-input rounded-lg border-gray-300 bg-white px-2 py-1 text-xs text-gray-950 shadow-sm outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-white/5 dark:text-white" />
                        </label>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs font-medium">
                        @foreach ($weekDates as $weekDate)
                            @php
                                $weekDateTone = $weekDate['is_selected']
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100'
                                    : ($weekDate['is_today']
                                        ? 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100'
                                        : 'border-gray-200 bg-white text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200');
                            @endphp
                            <button type="button" wire:click="selectDate('{{ $weekDate['date'] }}')" class="rounded-full border px-3 py-1 {{ $weekDateTone }}">
                                {{ $weekDate['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @if ($businesses->count() > 1)
                    <label class="grid gap-1 text-sm">
                        <span class="font-medium text-gray-950 dark:text-white">Business</span>
                        <select wire:model.live="businessId" class="fi-input block w-full rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-950 shadow-sm outline-none transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-white/5 dark:text-white">
                            @foreach ($businesses as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @else
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-medium text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                        {{ $business?->name ?? 'No business selected' }}
                    </div>
                @endif
            </div>
        </x-filament::section>

        @php
            $todayProfitTone = (float) $today['profit_after_marketing'] >= 0 ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100' : 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100';
            $dispatchPotentialTone = (float) $today['dispatch_potential_profit'] >= 0 ? 'border-violet-200 bg-violet-50 text-violet-900 dark:border-violet-900 dark:bg-violet-950/30 dark:text-violet-100' : 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100';
        @endphp

        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xs uppercase tracking-wide text-gray-500">Owner answer for {{ $todayLabel }}</div>
            <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">
                Delivered sales were LKR {{ number_format((float) $today['delivered_revenue'], 2) }} before costs.
                Estimated result after recorded costs is LKR {{ number_format((float) $today['profit_after_marketing'], 2) }}.
            </div>
            <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                LKR {{ number_format((float) $today['dispatch_moved_value'], 2) }} was dispatched on this date, but it is not sales yet. It becomes real sales only after delivery.
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">
                <div class="text-xs uppercase tracking-wide opacity-70">Gross delivered sales</div>
                <div class="mt-1 text-xs opacity-70">{{ $todayLabel }}</div>
                <div class="mt-2 text-2xl font-semibold">LKR {{ number_format((float) $today['delivered_revenue'], 2) }}</div>
                <div class="mt-1 text-sm opacity-80">{{ (int) $today['delivered_count'] }} delivered parcel(s). Before product, courier, return, resend, and marketing costs.</div>
            </div>

            <div class="rounded-lg border p-4 {{ $todayProfitTone }}">
                <div class="text-xs uppercase tracking-wide opacity-70">Estimated result after recorded costs</div>
                <div class="mt-1 text-xs opacity-70">{{ $todayLabel }}</div>
                <div class="mt-2 text-2xl font-semibold">LKR {{ number_format((float) $today['profit_after_marketing'], 2) }}</div>
                <div class="mt-1 text-xs opacity-80">
                    Gross sales - direct costs LKR {{ number_format((float) $today['recorded_direct_costs'], 2) }}
                    - leakage LKR {{ number_format((float) $today['recorded_leakage'], 2) }}
                    + recovery LKR {{ number_format((float) $today['recorded_recovery'], 2) }}
                    - marketing LKR {{ number_format((float) $today['marketing_spend'], 2) }}.
                </div>
            </div>

            <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
                <div class="text-xs uppercase tracking-wide opacity-70">Dispatched on selected day</div>
                <div class="mt-1 text-xs opacity-70">{{ $todayLabel }}</div>
                <div class="mt-2 text-2xl font-semibold">LKR {{ number_format((float) $today['dispatch_moved_value'], 2) }}</div>
                <div class="mt-1 text-sm opacity-80">{{ (int) $today['dispatch_moved_count'] }} parcel(s) sent on this date. Not included in delivered sales.</div>
                @if ((int) ($today['dispatch_moved_hidden_count'] ?? 0) > 0)
                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                        <div class="font-semibold uppercase tracking-wide">Needs date check</div>
                        <div class="mt-1">{{ (int) $today['dispatch_moved_hidden_count'] }} synced row(s) still need a trusted real dispatch date.</div>
                    </div>
                @endif
            </div>

            <div class="rounded-lg border p-4 {{ $dispatchPotentialTone }}">
                <div class="text-xs uppercase tracking-wide opacity-70">Best case if dispatch delivers</div>
                <div class="mt-1 text-xs opacity-70">{{ $todayLabel }}</div>
                <div class="mt-2 text-2xl font-semibold">LKR {{ number_format((float) $today['dispatch_potential_profit'], 2) }}</div>
                <div class="mt-2 text-xs opacity-80">
                    Not real profit yet. Assumes all {{ (int) $today['dispatch_moved_count'] }} dispatched parcel(s) are delivered.
                    Product cost: LKR {{ number_format((float) $today['dispatch_product_cost'], 2) }}.
                    Expected courier: LKR {{ number_format((float) $today['dispatch_expected_delivery_cost'], 2) }}.
                </div>
                @if ((int) ($today['dispatch_missing_delivery_cost_count'] ?? 0) > 0)
                    <div class="mt-2 text-xs font-medium text-amber-900 dark:text-amber-100">
                        {{ (int) $today['dispatch_missing_delivery_cost_count'] }} parcel(s) do not have delivery cost truth yet.
                    </div>
                @endif
            </div>
        </div>

        <div class="grid gap-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500">Main month view</div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $monthLabel }}</h2>
                    </div>
                    <div class="text-xs text-gray-500">Month-to-date only</div>
                </div>
                <div class="mt-4 grid gap-3 lg:grid-cols-3">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">
                        <div class="text-xs uppercase tracking-wide opacity-70">Gross delivered sales this month</div>
                        <div class="mt-1 text-lg font-semibold">LKR {{ number_format((float) $monthToDate['delivered_revenue'], 2) }}</div>
                        <div class="text-xs opacity-80">{{ (int) $monthToDate['delivered_count'] }} delivered parcel(s), before costs</div>
                    </div>
                    <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
                        <div class="text-xs uppercase tracking-wide opacity-70">Dispatched this month, not sales</div>
                        <div class="mt-1 text-lg font-semibold">LKR {{ number_format((float) $monthToDate['dispatch_moved_value'], 2) }}</div>
                        <div class="text-xs opacity-80">{{ (int) $monthToDate['dispatch_moved_count'] }} parcel(s) still need delivered or returned result</div>
                    </div>
                    <div class="rounded-lg border p-3 {{ (float) $monthToDate['profit_after_marketing'] >= 0 ? 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100' : 'border-red-200 bg-red-50 text-red-950 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100' }}">
                        <div class="text-xs uppercase tracking-wide opacity-70">Estimated result this month</div>
                        <div class="mt-1 text-lg font-semibold">LKR {{ number_format((float) $monthToDate['profit_after_marketing'], 2) }}</div>
                        <div class="text-xs opacity-80">
                            After recorded costs and marketing. Direct costs: LKR {{ number_format((float) $monthToDate['recorded_direct_costs'], 2) }}.
                        </div>
                    </div>
                </div>
            </div>

            <details class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <summary class="cursor-pointer text-sm font-semibold text-gray-950 dark:text-white">
                    All data reference only: {{ $allTimeLabel }}
                    <span class="ml-2 text-xs font-normal text-gray-500">Open only when checking lifetime totals</span>
                </summary>
                <div class="mt-4 grid gap-3 lg:grid-cols-3">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">
                        <div class="text-xs uppercase tracking-wide opacity-70">Lifetime gross delivered sales</div>
                        <div class="mt-1 text-lg font-semibold">LKR {{ number_format((float) $allTime['delivered_revenue'], 2) }}</div>
                        <div class="text-xs opacity-80">{{ (int) $allTime['delivered_count'] }} delivered parcel(s), before costs</div>
                    </div>
                    <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
                        <div class="text-xs uppercase tracking-wide opacity-70">Lifetime dispatched, not sales</div>
                        <div class="mt-1 text-lg font-semibold">LKR {{ number_format((float) $allTime['dispatch_moved_value'], 2) }}</div>
                        <div class="text-xs opacity-80">{{ (int) $allTime['dispatch_moved_count'] }} parcel(s)</div>
                    </div>
                    <div class="rounded-lg border p-3 {{ (float) $allTime['profit_after_marketing'] >= 0 ? 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100' : 'border-red-200 bg-red-50 text-red-950 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100' }}">
                        <div class="text-xs uppercase tracking-wide opacity-70">Lifetime estimated result</div>
                        <div class="mt-1 text-lg font-semibold">LKR {{ number_format((float) $allTime['profit_after_marketing'], 2) }}</div>
                        <div class="text-xs opacity-80">After recorded costs and marketing</div>
                    </div>
                </div>
            </details>
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="text-xs uppercase tracking-wide opacity-70">Open dispatch risk</div>
                    <div class="mt-1 text-sm opacity-80">Parcels in dispatch status are not sales yet. They still need delivered or returned result.</div>
                </div>
                <div class="grid gap-2 text-sm sm:grid-cols-2 lg:min-w-[28rem]">
                    <div class="rounded-lg bg-white/70 p-3 dark:bg-gray-950/30">
                        <div class="text-xs uppercase tracking-wide opacity-70">Parcels in dispatch status</div>
                        <div class="mt-1 text-lg font-semibold">LKR {{ number_format((float) $today['dispatch_signal_value'], 2) }}</div>
                        <div class="text-xs opacity-80">{{ (int) $today['dispatch_signal_count'] }} waiting result</div>
                    </div>
                    <div class="rounded-lg bg-white/70 p-3 dark:bg-gray-950/30">
                        <div class="text-xs uppercase tracking-wide opacity-70">Trusted stage-date portion</div>
                        <div class="mt-1 text-lg font-semibold">LKR {{ number_format((float) $today['dispatch_value'], 2) }}</div>
                        <div class="text-xs opacity-80">{{ (int) $today['dispatch_count'] }} verified parcel(s)</div>
                    </div>
                </div>
            </div>
            @if ((int) ($today['dispatch_hidden_count'] ?? 0) > 0)
                <div class="mt-3 text-xs font-medium">
                    {{ (int) $today['dispatch_hidden_count'] }} dispatch status row(s) still need trusted stage dates, worth LKR {{ number_format((float) $today['dispatch_hidden_value'], 2) }}.
                </div>
            @endif
        </div>

        <div class="grid gap-6 xl:grid-cols-[1fr_0.75fr]">
            <x-filament::section>
                <div class="grid gap-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Selected day vs previous day</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Use this to see whether the selected day is moving forward or getting stuck. Selected day is {{ $todayLabel }} and previous day is {{ $yesterdayLabel }}.</p>
                        </div>
                        <a href="{{ \App\Filament\Pages\MissingSkuMapping::getUrl() }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            Fix product links
                        </a>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                        <div class="grid grid-cols-3 bg-gray-50 text-sm font-semibold text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <div class="p-3">Signal</div>
                            <div class="p-3">Selected day</div>
                            <div class="p-3">Previous day</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Delivered sales</div>
                            <div class="p-3">LKR {{ number_format((float) $today['delivered_revenue'], 2) }}</div>
                            <div class="p-3">LKR {{ number_format((float) $yesterday['delivered_revenue'], 2) }}</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Delivered parcels</div>
                            <div class="p-3">{{ (int) $today['delivered_count'] }}</div>
                            <div class="p-3">{{ (int) $yesterday['delivered_count'] }}</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Stock App dispatch value</div>
                            <div class="p-3">
                                LKR {{ number_format((float) $today['stock_app_dispatch_value'], 2) }} / {{ (int) $today['stock_app_dispatch_count'] }} dispatch signal(s)
                            </div>
                            <div class="p-3">
                                LKR {{ number_format((float) $yesterday['stock_app_dispatch_value'], 2) }} / {{ (int) $yesterday['stock_app_dispatch_count'] }} dispatch signal(s)
                            </div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Moved to dispatch</div>
                            <div class="p-3">
                                LKR {{ number_format((float) $today['dispatch_moved_value'], 2) }} / {{ (int) $today['dispatch_moved_count'] }} verified parcel(s)
                                @if ((int) ($today['dispatch_moved_hidden_count'] ?? 0) > 0)
                                    <div class="text-xs text-amber-700 dark:text-amber-300">
                                        {{ (int) $today['dispatch_moved_hidden_count'] }} unverified same-day row(s)
                                    </div>
                                @endif
                            </div>
                            <div class="p-3">
                                LKR {{ number_format((float) $yesterday['dispatch_moved_value'], 2) }} / {{ (int) $yesterday['dispatch_moved_count'] }} verified parcel(s)
                                @if ((int) ($yesterday['dispatch_moved_hidden_count'] ?? 0) > 0)
                                    <div class="text-xs text-amber-700 dark:text-amber-300">
                                        {{ (int) $yesterday['dispatch_moved_hidden_count'] }} unverified same-day row(s)
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Dispatched value</div>
                            <div class="p-3">
                                LKR {{ number_format((float) $today['dispatch_signal_value'], 2) }}
                                @if ((int) ($today['dispatch_hidden_count'] ?? 0) > 0)
                                    <div class="text-xs text-amber-700 dark:text-amber-300">
                                        {{ (int) $today['dispatch_hidden_count'] }} unverified row(s)
                                    </div>
                                @endif
                            </div>
                            <div class="p-3">
                                LKR {{ number_format((float) $yesterday['dispatch_signal_value'], 2) }}
                                @if ((int) ($yesterday['dispatch_hidden_count'] ?? 0) > 0)
                                    <div class="text-xs text-amber-700 dark:text-amber-300">
                                        {{ (int) $yesterday['dispatch_hidden_count'] }} unverified row(s)
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Returns</div>
                            <div class="p-3">{{ (int) $today['returned_count'] }}</div>
                            <div class="p-3">{{ (int) $yesterday['returned_count'] }}</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Marketing spend</div>
                            <div class="p-3">LKR {{ number_format((float) $today['marketing_spend'], 2) }}</div>
                            <div class="p-3">LKR {{ number_format((float) $yesterday['marketing_spend'], 2) }}</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Profit after direct + marketing</div>
                            <div class="p-3">LKR {{ number_format((float) $today['profit_after_marketing'], 2) }}</div>
                            <div class="p-3">LKR {{ number_format((float) $yesterday['profit_after_marketing'], 2) }}</div>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="grid gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">This week</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">A simple week picture for {{ $weekLabel }} without treating dispatched parcels as final sales.</p>
                        </div>

                    <div class="grid gap-3">
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Delivered sales</span>
                            <span class="font-semibold text-gray-950 dark:text-white">LKR {{ number_format((float) $week['delivered_revenue'], 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Pipeline value</span>
                            <span class="font-semibold text-gray-950 dark:text-white">LKR {{ number_format((float) $week['dispatch_signal_value'], 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Returns</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ (int) $week['returned_count'] }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Marketing spend</span>
                            <span class="font-semibold text-gray-950 dark:text-white">LKR {{ number_format((float) $week['marketing_spend'], 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Profit after direct costs</span>
                            <span class="font-semibold text-gray-950 dark:text-white">LKR {{ number_format((float) $week['profit_after_direct_costs'], 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Profit after direct + marketing</span>
                            <span class="font-semibold text-gray-950 dark:text-white">LKR {{ number_format((float) $week['profit_after_marketing'], 2) }}</span>
                        </div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <div class="grid gap-4 xl:grid-cols-[1fr_0.8fr]">
            <x-filament::section>
                <div class="grid gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Trust check</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Two things usually distort daily sales profit the fastest: missing SKU links and missing marketing rows.</p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <a href="{{ \App\Filament\Pages\MissingSkuMapping::getUrl() }}" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-left text-amber-950 transition hover:bg-amber-100 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                            <div class="text-xs uppercase tracking-wide opacity-70">Missing SKU links</div>
                            <div class="mt-2 text-2xl font-semibold">{{ number_format((int) $missingProductLinks) }}</div>
                            <div class="mt-1 text-sm opacity-80">Fix this before trusting product-level profit.</div>
                        </a>

                        <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
                            <div class="text-xs uppercase tracking-wide opacity-70">Marketing entered on selected day</div>
                            <div class="mt-2 text-2xl font-semibold">LKR {{ number_format((float) $today['marketing_spend'], 2) }}</div>
                            <div class="mt-1 text-sm opacity-80">If boosting was spent but this stays zero, the selected day's parcel profit still looks too high.</div>
                        </div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section>
            <div class="grid gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Top delivered products on selected day</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Only delivered rows with product links appear here.</p>
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    @forelse ($topProducts as $product)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $product['sku'] }}</div>
                            <div class="mt-1 truncate text-xs text-gray-500">{{ $product['name'] }}</div>
                            <div class="mt-3 text-lg font-semibold text-gray-950 dark:text-white">LKR {{ number_format((float) $product['revenue'], 2) }}</div>
                            <div class="text-sm text-gray-500">{{ (int) $product['count'] }} delivered</div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                            No delivered product rows with SKU links on this date.
                        </div>
                    @endforelse
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

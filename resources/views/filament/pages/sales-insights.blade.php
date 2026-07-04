<x-filament-panels::page>
    <div class="grid gap-6">
        <x-filament::section>
            <div class="grid gap-4 lg:grid-cols-[1fr_0.35fr] lg:items-start">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Sales command view</div>
                    <h2 class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">Today, yesterday, and what needs attention</h2>
                    <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                        Delivered orders count as sales. Finance starts once a COD parcel gets a tracking number or a wholesale parcel is sent. Marketing only shows here after you record it in Expenses or Bank Review with the Marketing category.
                    </p>
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
            $money = fn (float|int $amount): string => 'LKR '.number_format((float) $amount, 2);
            $card = function (string $label, string $value, string $note, string $tone): string {
                $classes = match ($tone) {
                    'green' => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100',
                    'blue' => 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100',
                    'amber' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100',
                    'red' => 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100',
                    default => 'border-gray-200 bg-white text-gray-950 dark:border-gray-800 dark:bg-gray-900 dark:text-white',
                };

                return '<div class="rounded-lg border p-4 '.$classes.'">'
                    .'<div class="text-xs uppercase tracking-wide opacity-70">'.$label.'</div>'
                    .'<div class="mt-2 text-2xl font-semibold">'.$value.'</div>'
                    .'<div class="mt-1 text-sm opacity-80">'.$note.'</div>'
                    .'</div>';
            };
        @endphp

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            {!! $card('Today delivered sales', $money($today['delivered_revenue']), (int) $today['delivered_count'].' delivered parcel(s)', 'green') !!}
            {!! $card('Today dispatched value', $money($today['dispatch_value']), (int) $today['dispatch_count'].' parcel(s) still waiting result', 'blue') !!}
            {!! $card('Today marketing spend', $money($today['marketing_spend']), ((int) $today['delivered_count'] > 0 ? 'About '.$money($today['marketing_per_delivered_order']).' per delivered parcel' : 'No delivered parcel yet to spread this over'), ((float) $today['marketing_spend'] > 0 ? 'amber' : 'gray')) !!}
            {!! $card('Today profit after direct + marketing', $money($today['profit_after_marketing']), 'After courier, returns, resend impact, and marketing rows already recorded today', ((float) $today['profit_after_marketing'] >= 0 ? 'green' : 'red')) !!}
        </div>

        <div class="grid gap-6 xl:grid-cols-[1fr_0.75fr]">
            <x-filament::section>
                <div class="grid gap-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Today vs yesterday</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Use this to see whether the day is moving forward or getting stuck.</p>
                        </div>
                        <a href="{{ \App\Filament\Pages\MissingSkuMapping::getUrl() }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            Fix product links
                        </a>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                        <div class="grid grid-cols-3 bg-gray-50 text-sm font-semibold text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <div class="p-3">Signal</div>
                            <div class="p-3">Today</div>
                            <div class="p-3">Yesterday</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Delivered sales</div>
                            <div class="p-3">{{ $money($today['delivered_revenue']) }}</div>
                            <div class="p-3">{{ $money($yesterday['delivered_revenue']) }}</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Delivered parcels</div>
                            <div class="p-3">{{ (int) $today['delivered_count'] }}</div>
                            <div class="p-3">{{ (int) $yesterday['delivered_count'] }}</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Dispatched value</div>
                            <div class="p-3">{{ $money($today['dispatch_value']) }}</div>
                            <div class="p-3">{{ $money($yesterday['dispatch_value']) }}</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Returns</div>
                            <div class="p-3">{{ (int) $today['returned_count'] }}</div>
                            <div class="p-3">{{ (int) $yesterday['returned_count'] }}</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Marketing spend</div>
                            <div class="p-3">{{ $money($today['marketing_spend']) }}</div>
                            <div class="p-3">{{ $money($yesterday['marketing_spend']) }}</div>
                        </div>
                        <div class="grid grid-cols-3 border-t border-gray-200 text-sm dark:border-gray-800">
                            <div class="p-3 font-medium">Profit after direct + marketing</div>
                            <div class="p-3">{{ $money($today['profit_after_marketing']) }}</div>
                            <div class="p-3">{{ $money($yesterday['profit_after_marketing']) }}</div>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">This week</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">A simple week picture without treating dispatched parcels as final sales.</p>
                    </div>

                    <div class="grid gap-3">
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Delivered sales</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ $money($week['delivered_revenue']) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Pipeline value</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ $money($week['pending_value']) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Returns</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ (int) $week['returned_count'] }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Marketing spend</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ $money($week['marketing_spend']) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Profit after direct costs</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ $money($week['profit_after_direct_costs']) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <span class="text-sm text-gray-500">Profit after direct + marketing</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ $money($week['profit_after_marketing']) }}</span>
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
                            <div class="text-xs uppercase tracking-wide opacity-70">Marketing entered today</div>
                            <div class="mt-2 text-2xl font-semibold">{{ $money($today['marketing_spend']) }}</div>
                            <div class="mt-1 text-sm opacity-80">If boosting was spent but this stays zero, today’s parcel profit still looks too high.</div>
                        </div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section>
            <div class="grid gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Top delivered products today</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Only delivered rows with product links appear here.</p>
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    @forelse ($topProducts as $product)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $product['sku'] }}</div>
                            <div class="mt-1 truncate text-xs text-gray-500">{{ $product['name'] }}</div>
                            <div class="mt-3 text-lg font-semibold text-gray-950 dark:text-white">{{ $money($product['revenue']) }}</div>
                            <div class="text-sm text-gray-500">{{ (int) $product['count'] }} delivered</div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                            No delivered product rows with SKU links today.
                        </div>
                    @endforelse
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

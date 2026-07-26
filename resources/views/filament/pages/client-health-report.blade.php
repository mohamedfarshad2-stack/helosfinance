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
                $money = fn (float|int $value): string => 'LKR '.number_format((float) $value, 2);
                $start = \Illuminate\Support\Carbon::parse($snapshot->period_start)->format('M j, Y');
                $end = \Illuminate\Support\Carbon::parse($snapshot->period_end)->format('M j, Y');
                $cards = [
                    [
                        'label' => 'Total parcels dispatched',
                        'count' => number_format((int) data_get($metrics, 'dispatched_parcel_count', 0)).' parcels',
                        'value' => $money(data_get($metrics, 'dispatched_parcel_value', 0)),
                        'hint' => 'Parcel value sent to courier; this is not revenue yet.',
                        'icon' => 'heroicon-o-truck',
                        'tone' => 'blue',
                    ],
                    [
                        'label' => 'Product costs',
                        'count' => 'Cost of dispatched products',
                        'value' => $money(data_get($metrics, 'product_costs', 0)),
                        'hint' => 'Product cost attached to parcels dispatched in this period.',
                        'icon' => 'heroicon-o-cube',
                        'tone' => 'violet',
                    ],
                    [
                        'label' => 'Courier costs',
                        'count' => 'Delivered + returned charges',
                        'value' => $money(data_get($metrics, 'total_courier_costs', 0)),
                        'hint' => $money(data_get($metrics, 'delivered_courier_costs', 0)).' delivered · '.$money(data_get($metrics, 'return_courier_costs', 0)).' returned',
                        'icon' => 'heroicon-o-banknotes',
                        'tone' => 'orange',
                    ],
                    [
                        'label' => 'Pending delivery',
                        'count' => number_format((int) data_get($metrics, 'pending_dispatch_count', 0)).' parcels',
                        'value' => $money(data_get($metrics, 'pending_dispatch_value', 0)),
                        'hint' => 'The team must push these parcels toward successful delivery.',
                        'icon' => 'heroicon-o-clock',
                        'tone' => 'amber',
                    ],
                    [
                        'label' => 'Delivered sales',
                        'count' => number_format((int) data_get($metrics, 'order_counts.delivered', 0)).' parcels',
                        'value' => $money($snapshot->revenue_total),
                        'hint' => 'Only successfully delivered parcels are counted as sales.',
                        'icon' => 'heroicon-o-check-circle',
                        'tone' => 'emerald',
                    ],
                    [
                        'label' => 'Gross profit',
                        'count' => 'Delivered sales − product costs − courier costs',
                        'value' => $money(data_get($metrics, 'parcel_gross_profit', 0)),
                        'hint' => 'First-stage parcel profit before salaries, overheads and other company costs.',
                        'icon' => 'heroicon-o-arrow-trending-up',
                        'tone' => data_get($metrics, 'parcel_gross_profit', 0) >= 0 ? 'green' : 'red',
                    ],
                ];
                $tones = [
                    'blue' => 'border-blue-200 bg-gradient-to-br from-blue-50 to-white text-blue-700 dark:border-blue-900 dark:from-blue-950/50 dark:to-gray-950 dark:text-blue-200',
                    'violet' => 'border-violet-200 bg-gradient-to-br from-violet-50 to-white text-violet-700 dark:border-violet-900 dark:from-violet-950/50 dark:to-gray-950 dark:text-violet-200',
                    'orange' => 'border-orange-200 bg-gradient-to-br from-orange-50 to-white text-orange-700 dark:border-orange-900 dark:from-orange-950/50 dark:to-gray-950 dark:text-orange-200',
                    'amber' => 'border-amber-200 bg-gradient-to-br from-amber-50 to-white text-amber-700 dark:border-amber-900 dark:from-amber-950/50 dark:to-gray-950 dark:text-amber-200',
                    'emerald' => 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-white text-emerald-700 dark:border-emerald-900 dark:from-emerald-950/50 dark:to-gray-950 dark:text-emerald-200',
                    'green' => 'border-green-200 bg-gradient-to-br from-green-50 to-white text-green-700 dark:border-green-900 dark:from-green-950/50 dark:to-gray-950 dark:text-green-200',
                    'red' => 'border-red-200 bg-gradient-to-br from-red-50 to-white text-red-700 dark:border-red-900 dark:from-red-950/50 dark:to-gray-950 dark:text-red-200',
                ];
            @endphp

            <div class="overflow-hidden rounded-3xl bg-gradient-to-r from-slate-950 via-blue-950 to-indigo-950 p-6 text-white shadow-xl">
                <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-[0.2em] text-blue-200">Owner parcel dashboard</div>
                        <h2 class="mt-2 text-3xl font-black">{{ $business->name }}</h2>
                        <p class="mt-2 text-sm text-blue-100">{{ $start }} to {{ $end }}</p>
                    </div>
                    <div class="max-w-xl rounded-2xl border border-white/15 bg-white/10 px-4 py-3 text-sm text-blue-50 backdrop-blur">
                        Priority: convert pending parcels into deliveries and prevent avoidable returns. HELOAS will guide the responsible team from these numbers.
                    </div>
                </div>
            </div>

            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($cards as $card)
                    <div class="rounded-3xl border p-5 shadow-sm {{ $tones[$card['tone']] }}">
                        <div class="flex items-start justify-between gap-4">
                            <div class="text-sm font-bold uppercase tracking-wide">{{ $card['label'] }}</div>
                            <div class="rounded-2xl bg-white/80 p-2 shadow-sm dark:bg-gray-950/60">
                                <x-filament::icon :icon="$card['icon']" class="h-6 w-6" />
                            </div>
                        </div>
                        <div class="mt-5 text-3xl font-black text-gray-950 dark:text-white">{{ $card['value'] }}</div>
                        <div class="mt-2 text-sm font-bold">{{ $card['count'] }}</div>
                        <div class="mt-4 border-t border-current/10 pt-3 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $card['hint'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="rounded-3xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30">
                <div class="flex gap-3">
                    <x-filament::icon icon="heroicon-o-light-bulb" class="h-7 w-7 shrink-0 text-amber-600" />
                    <div>
                        <div class="font-black text-gray-950 dark:text-white">HELOAS focus for this stage</div>
                        <p class="mt-1 text-sm leading-6 text-gray-700 dark:text-gray-200">Arafath keeps parcel statuses accurate. CSR staff follow up pending deliveries and prevent returns. Nifras monitors their assigned work and pushes the team to increase successful deliveries.</p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>

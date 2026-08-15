<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-2">
                    <div class="text-xs font-black uppercase tracking-wide text-violet-600 dark:text-violet-300">Arafath parcel command board</div>
                    <h1 class="text-2xl font-black text-gray-950 dark:text-white">Confirmed parcels to dispatch</h1>
                    <p class="max-w-3xl text-sm font-medium text-gray-600 dark:text-gray-300">
                        This screen shows only the confirmed parcels Arafath needs to push out one by one. Use it as the live command list for dispatch.
                    </p>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <label class="grid gap-1 text-xs font-bold text-gray-600 dark:text-gray-300">
                        From
                        <input type="date" wire:model.live="parcelStartDate" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                    </label>
                    <label class="grid gap-1 text-xs font-bold text-gray-600 dark:text-gray-300">
                        To
                        <input type="date" wire:model.live="parcelEndDate" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                    </label>
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <div class="rounded-2xl border border-violet-200 bg-violet-50 p-4 dark:border-violet-900 dark:bg-violet-950/30">
                    <div class="text-xs font-black uppercase text-violet-700 dark:text-violet-300">Confirmed parcels</div>
                    <div class="mt-2 text-3xl font-black text-violet-950 dark:text-violet-100">
                        {{ number_format((int) ($parcelMovement['confirmed_waiting_dispatch_live_count'] ?? $parcelMovement['confirmed_waiting_dispatch_count'] ?? 0)) }}
                    </div>
                    <div class="mt-1 text-sm font-semibold text-violet-900/80 dark:text-violet-100/80">
                        Live in Stock App
                    </div>
                </div>

                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
                    <div class="text-xs font-black uppercase text-emerald-700 dark:text-emerald-300">Live value</div>
                    <div class="mt-2 text-3xl font-black text-emerald-950 dark:text-emerald-100">
                        @if (! is_null($parcelMovement['confirmed_waiting_dispatch_live_value'] ?? null))
                            LKR {{ number_format((float) $parcelMovement['confirmed_waiting_dispatch_live_value'], 2) }}
                        @else
                            Loading
                        @endif
                    </div>
                    <div class="mt-1 text-sm font-semibold text-emerald-900/80 dark:text-emerald-100/80">
                        {{ $parcelMovement['confirmed_waiting_dispatch_live_note'] ?? 'Open the live queue to load the parcel value.' }}
                    </div>
                </div>

                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
                    <div class="text-xs font-black uppercase text-amber-700 dark:text-amber-300">Age split</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-sm font-black">
                        <span class="rounded-full bg-white px-3 py-1 text-amber-900 ring-1 ring-amber-200 dark:bg-gray-900 dark:text-amber-100 dark:ring-amber-900">
                            {{ number_format((int) ($parcelMovement['confirmed_waiting_dispatch_due_soon_count'] ?? 0)) }} within 2 days
                        </span>
                        <span class="rounded-full bg-white px-3 py-1 text-amber-900 ring-1 ring-amber-200 dark:bg-gray-900 dark:text-amber-100 dark:ring-amber-900">
                            {{ number_format((int) ($parcelMovement['confirmed_waiting_dispatch_overdue_count'] ?? 0)) }} older than 2 days
                        </span>
                    </div>
                    <div class="mt-1 text-sm font-semibold text-amber-900/80 dark:text-amber-100/80">
                        {{ $parcelMovement['confirmed_waiting_dispatch_live_warning'] ? 'Live count differs from HELOS synced rows.' : 'Live count and HELOS synced rows are aligned.' }}
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="refreshLivePending"
                    wire:loading.attr="disabled"
                    wire:target="refreshLivePending"
                    class="rounded-lg bg-violet-600 px-3 py-2 text-xs font-black text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="refreshLivePending">Refresh live queue</span>
                    <span wire:loading wire:target="refreshLivePending">Checking Stock App...</span>
                </button>

                @if (filled($parcelMovement['confirmed_waiting_dispatch_live_url'] ?? null))
                    <a
                        href="{{ $parcelMovement['confirmed_waiting_dispatch_live_url'] }}"
                        target="_blank"
                        rel="noreferrer"
                        class="rounded-lg border border-violet-200 bg-white px-3 py-2 text-xs font-black text-violet-700 shadow-sm transition hover:bg-violet-50 dark:border-violet-900 dark:bg-gray-950 dark:text-violet-200 dark:hover:bg-gray-900"
                    >
                        Open live confirmed queue
                    </a>
                @endif
            </div>

            <div class="mt-3 text-sm font-semibold text-gray-600 dark:text-gray-300">
                {{ $parcelMovement['confirmed_waiting_dispatch_live_note'] ?? 'HELOS will show each confirmed parcel below.' }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-black text-gray-950 dark:text-white">Confirmed parcel list</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Each row is one parcel. Use this list to add tracking, dispatch, and follow up without hunting through other dashboard blocks.</p>
                </div>
                <div class="text-sm font-black text-gray-700 dark:text-gray-200">Open any row to see the rest.</div>
            </div>

            <div class="mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse (($parcelMovement['confirmed_waiting_dispatch_items'] ?? []) as $item)
                        <details class="group px-4 py-3">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm">
                                <div class="min-w-0">
                                    <div class="font-black text-gray-950 dark:text-white">
                                        {{ $item['reference'] ?? 'Confirmed parcel' }}
                                    </div>
                                </div>
                                <div class="shrink-0 font-black text-gray-950 dark:text-white">
                                    LKR {{ number_format((float) ($item['value'] ?? 0), 2) }}
                                </div>
                            </summary>
                            <div class="mt-3 grid gap-2 rounded-xl bg-gray-50 p-3 text-sm dark:bg-gray-900">
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <div class="text-xs font-black uppercase text-gray-500">Customer</div>
                                        <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $item['customer'] ?? 'Customer not shown' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-black uppercase text-gray-500">Phone</div>
                                        <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $item['phone'] ?? 'Phone not shown' }}</div>
                                    </div>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-3">
                                    <div>
                                        <div class="text-xs font-black uppercase text-gray-500">Date</div>
                                        <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $item['date'] ?? 'No date' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-black uppercase text-gray-500">Age</div>
                                        <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $item['age_label'] ?? 'Age unknown' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-black uppercase text-gray-500">Status</div>
                                        <div class="mt-1 font-semibold capitalize text-gray-900 dark:text-gray-100">{{ $item['status'] ?? 'confirmed' }}</div>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs font-black uppercase text-gray-500">Next action</div>
                                    <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $item['next_action'] ?? 'Add tracking and send this parcel to courier.' }}</div>
                                </div>
                            </div>
                        </details>
                    @empty
                        <div class="px-4 py-8 text-sm text-gray-500 dark:text-gray-400">
                            HELOS has not loaded the confirmed parcel rows yet. Refresh the live queue or open Stock App to inspect the parcels directly.
                        </div>
                    @endforelse
                </div>
            </div>
        </x-filament::section>

        @if (! empty($workQueue['employee_guide']))
            @php
                $guide = $workQueue['employee_guide'];
                $summary = $workQueue['summary'] ?? [];
                $metricCards = [
                    ['label' => 'Due today', 'value' => $summary['Tasks due today'] ?? 0, 'href' => '#todays-work-summary'],
                    ['label' => 'High priority', 'value' => $summary['High priority'] ?? 0, 'href' => '#todays-work-summary'],
                    ['label' => 'Overdue', 'value' => $summary['Overdue'] ?? 0, 'href' => '#todays-work-summary'],
                    ['label' => 'Done today', 'value' => $summary['Completed today'] ?? 0, 'href' => '#todays-work-summary'],
                    ['label' => 'Waiting review', 'value' => $summary['Waiting for review'] ?? 0, 'href' => '#todays-work-summary'],
                    ['label' => 'Progress', 'value' => $summary['Completion rate'] ?? '0%', 'href' => '#todays-work-summary'],
                ];
            @endphp
            <x-filament::section>
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-xs font-black uppercase tracking-wide text-emerald-600">My work dashboard</div>
                        <h2 class="mt-1 text-2xl font-black text-gray-950 dark:text-white">Welcome, {{ $guide['name'] }}</h2>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            Report to <span class="font-semibold text-gray-900 dark:text-white">{{ $guide['reports_to'] }}</span>. Start with the strongest color, finish the task, then move to the next one.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @forelse ($guide['responsibilities'] as $responsibility)
                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">
                                {{ $responsibility['label'] }}
                            </span>
                        @empty
                            <span class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-bold text-amber-800">
                                No responsibility areas assigned yet
                            </span>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                    @foreach ($metricCards as $card)
                        <a href="{{ $card['href'] }}" class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-gray-800 dark:bg-gray-950">
                            <div class="text-[11px] font-black uppercase text-gray-500">{{ $card['label'] }}</div>
                            <div class="mt-2 text-2xl font-black text-gray-950 dark:text-white">{{ $card['value'] }}</div>
                        </a>
                    @endforeach
                </div>

                <div id="todays-work-summary" class="mt-4 grid gap-3 lg:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="text-xs font-black uppercase text-gray-500">Today's priority</div>
                        <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">{{ $workQueue['headline'] ?? 'Today\'s work is ready.' }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="text-xs font-black uppercase text-gray-500">Quick summary</div>
                        <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                            @foreach (($workQueue['summary'] ?? []) as $label => $value)
                                <div class="rounded-lg bg-white px-3 py-2 dark:bg-gray-950">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                                    <div class="mt-1 font-black text-gray-950 dark:text-white">{{ $value }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        @if (! empty($employeeContribution))
            <x-filament::section>
                <div class="grid gap-4 rounded-2xl border border-emerald-200 bg-white p-4 shadow-sm dark:border-emerald-900 dark:bg-gray-900">
                    <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-center">
                        <div>
                            <div class="text-xs font-black uppercase text-emerald-600">Your contribution today</div>
                            <h2 class="mt-1 text-xl font-black text-gray-950 dark:text-white">{{ $employeeContribution['status'] }}</h2>
                            <p class="mt-1 max-w-2xl text-sm text-gray-600 dark:text-gray-300">Only your work numbers are shown here. Owner finance stays private.</p>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-xl bg-sky-50 px-4 py-3 text-sky-900 ring-1 ring-sky-100">
                                <div class="text-2xl font-black">{{ $employeeContribution['target'] }}</div>
                                <div class="text-xs font-bold">Actions</div>
                            </div>
                            <div class="rounded-xl bg-emerald-50 px-4 py-3 text-emerald-900 ring-1 ring-emerald-100">
                                <div class="text-2xl font-black">{{ $employeeContribution['completed'] }}</div>
                                <div class="text-xs font-bold">Done</div>
                            </div>
                            <div class="rounded-xl bg-amber-50 px-4 py-3 text-amber-900 ring-1 ring-amber-100">
                                <div class="text-2xl font-black">{{ $employeeContribution['remaining'] }}</div>
                                <div class="text-xs font-bold">Left</div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between text-xs font-bold text-gray-600 dark:text-gray-300">
                            <span>Daily progress</span>
                            <span>{{ $employeeContribution['progress'] }}%</span>
                        </div>
                        <div class="h-3 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 via-lime-400 to-yellow-300 transition-all" style="width: {{ $employeeContribution['progress'] }}%"></div>
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div class="grid gap-5 rounded-2xl bg-gray-950 p-5 text-white shadow-xl ring-1 ring-black/10 dark:bg-gray-900">
                @if (! empty($managerProfit))
                    <div class="grid gap-5 xl:grid-cols-[1fr_auto] xl:items-start">
                        <div>
                            <div class="text-xs font-black uppercase text-rose-300">Manager target recovery - {{ $managerProfit['period'] }}</div>
                            <h2 class="mt-2 text-2xl font-black leading-tight">{{ $managerProfit['headline'] }}</h2>
                            <p class="mt-2 max-w-3xl text-sm font-medium text-gray-300">
                                As of {{ $managerProfit['as_of'] }}. This is the manager action plan. Owner-only finance stays protected, but the recovery pressure and daily work commands are visible.
                            </p>
                        </div>
                        <div class="rounded-xl bg-gradient-to-br from-rose-500 to-red-600 px-5 py-4 text-white shadow-lg">
                            <div class="text-xs font-black uppercase text-rose-100">{{ $managerProfit['recovery_status'] }}</div>
                            <div class="mt-1 text-3xl font-black">LKR {{ number_format((float) $managerProfit['recovery_pressure'], 2) }}</div>
                            <div class="mt-1 text-xs font-semibold text-rose-100">Operational pressure HELOS can assign today</div>
                        </div>
                    </div>
                @else
                    <div class="grid gap-5 xl:grid-cols-[1fr_auto] xl:items-start">
                        <div>
                            <div class="text-xs font-black uppercase text-rose-300">Manager target recovery</div>
                            <h2 class="mt-2 text-2xl font-black leading-tight">Finance summary is not loaded for this role yet.</h2>
                            <p class="mt-2 max-w-3xl text-sm font-medium text-gray-300">
                                The parcel board and work dashboard stay visible. Manager recovery values appear automatically for supervisor roles or when the saved month snapshot is ready.
                            </p>
                        </div>
                        <div class="rounded-xl bg-gradient-to-br from-rose-500 to-red-600 px-5 py-4 text-white shadow-lg">
                            <div class="text-xs font-black uppercase text-rose-100">Loading</div>
                            <div class="mt-1 text-3xl font-black">LKR 0.00</div>
                            <div class="mt-1 text-xs font-semibold text-rose-100">Operational pressure will appear here when available</div>
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

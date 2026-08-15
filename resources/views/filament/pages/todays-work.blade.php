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

            <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                <button type="button" wire:click="openParcelItems('pending_confirmation')" class="rounded-xl border border-violet-200 bg-white px-3 py-2 text-left transition hover:border-violet-400 hover:bg-violet-50 dark:border-violet-900 dark:bg-gray-950 dark:hover:bg-violet-950/30">
                    <div class="text-[11px] font-black uppercase tracking-wide text-violet-700 dark:text-violet-300">Pending confirmation</div>
                    <div class="mt-1 text-lg font-black text-gray-950 dark:text-white">
                        {{ number_format((int) ($parcelMovement['pending_confirmation_count'] ?? 0)) }}
                    </div>
                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                        LKR {{ number_format((float) ($parcelMovement['pending_confirmation_value'] ?? 0), 2) }}
                    </div>
                </button>

                <button type="button" wire:click="openParcelItems('confirmed_live')" class="rounded-xl border border-emerald-200 bg-white px-3 py-2 text-left transition hover:border-emerald-400 hover:bg-emerald-50 dark:border-emerald-900 dark:bg-gray-950 dark:hover:bg-emerald-950/30">
                    <div class="text-[11px] font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Confirmed live</div>
                    <div class="mt-1 text-lg font-black text-gray-950 dark:text-white">
                        {{ number_format((int) ($parcelMovement['confirmed_waiting_dispatch_live_count'] ?? $parcelMovement['confirmed_waiting_dispatch_count'] ?? 0)) }}
                    </div>
                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                        @if (! is_null($parcelMovement['confirmed_waiting_dispatch_live_value'] ?? null))
                            LKR {{ number_format((float) $parcelMovement['confirmed_waiting_dispatch_live_value'], 2) }}
                        @else
                            LKR {{ number_format((float) ($parcelMovement['confirmed_waiting_dispatch_value'] ?? 0), 2) }}
                        @endif
                    </div>
                </button>

                <button type="button" wire:click="openParcelItems('dispatched_today')" class="rounded-xl border border-sky-200 bg-white px-3 py-2 text-left transition hover:border-sky-400 hover:bg-sky-50 dark:border-sky-900 dark:bg-gray-950 dark:hover:bg-sky-950/30">
                    <div class="text-[11px] font-black uppercase tracking-wide text-sky-700 dark:text-sky-300">Dispatching today</div>
                    <div class="mt-1 text-lg font-black text-gray-950 dark:text-white">
                        {{ number_format((int) ($parcelMovement['dispatched_count'] ?? 0)) }}
                    </div>
                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                        LKR {{ number_format((float) ($parcelMovement['dispatched_value'] ?? 0), 2) }}
                    </div>
                </button>

                <button type="button" wire:click="openParcelItems('dispatched_waiting_delivery')" class="rounded-xl border border-amber-200 bg-white px-3 py-2 text-left transition hover:border-amber-400 hover:bg-amber-50 dark:border-amber-900 dark:bg-gray-950 dark:hover:bg-amber-950/30">
                    <div class="text-[11px] font-black uppercase tracking-wide text-amber-700 dark:text-amber-300">Dispatched not delivered</div>
                    <div class="mt-1 text-lg font-black text-gray-950 dark:text-white">
                        {{ number_format((int) ($parcelMovement['dispatched_waiting_delivery_count'] ?? 0)) }}
                    </div>
                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                        LKR {{ number_format((float) ($parcelMovement['dispatched_waiting_delivery_value'] ?? 0), 2) }}
                    </div>
                </button>

                <button type="button" wire:click="openParcelItems('delivered_so_far')" class="rounded-xl border border-rose-200 bg-white px-3 py-2 text-left transition hover:border-rose-400 hover:bg-rose-50 dark:border-rose-900 dark:bg-gray-950 dark:hover:bg-rose-950/30">
                    <div class="text-[11px] font-black uppercase tracking-wide text-rose-700 dark:text-rose-300">Delivered so far</div>
                    <div class="mt-1 text-lg font-black text-gray-950 dark:text-white">
                        {{ number_format((int) ($parcelMovement['delivered_so_far_count'] ?? 0)) }}
                    </div>
                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                        LKR {{ number_format((float) ($parcelMovement['delivered_so_far_value'] ?? 0), 2) }}
                    </div>
                </button>
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
            <div class="text-sm text-gray-600 dark:text-gray-300">
                Use the cards above to open the order IDs and amounts you need.
            </div>
        </x-filament::section>

        @if ($showParcelItemsModal)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4" wire:click.self="closeParcelItems">
                <div class="w-full max-w-2xl rounded-2xl bg-white p-4 shadow-2xl dark:bg-gray-950">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-black text-gray-950 dark:text-white">{{ $selectedParcelItemsTitle ?? 'Parcel items' }}</h2>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Order ID and amount only.</p>
                        </div>
                        <button
                            type="button"
                            wire:click="closeParcelItems"
                            class="rounded-lg border border-gray-200 px-3 py-1 text-xs font-black text-gray-700 dark:border-gray-700 dark:text-gray-200"
                        >
                            Close
                        </button>
                    </div>

                    <div class="mt-4 max-h-[70vh] overflow-auto rounded-xl border border-gray-200 dark:border-gray-800">
                        <div class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($selectedParcelItems as $item)
                                <div class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                                    <div class="min-w-0">
                                        <div class="font-black text-gray-950 dark:text-white">{{ $item['reference'] ?? 'Confirmed parcel' }}</div>
                                    </div>
                                    <div class="shrink-0 font-black text-gray-950 dark:text-white">LKR {{ number_format((float) ($item['value'] ?? 0), 2) }}</div>
                                </div>
                            @empty
                                <div class="px-4 py-8 text-sm text-gray-500 dark:text-gray-400">No rows to show yet.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @endif

    </div>
</x-filament-panels::page>

<x-filament-panels::page>
    <div class="grid gap-6">
        <x-filament::section>
            <div class="grid gap-4 lg:grid-cols-[1fr_0.45fr] lg:items-start">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Product repair</div>
                    <h2 class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">Fix orders that cannot calculate product profit</h2>
                    <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                        HELOS now separates older backlog from today’s new inflow so you can see whether the team is really reducing the pile.
                    </p>
                </div>

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
                    <div class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Showing now</div>
                    <div class="mt-1 text-3xl font-semibold text-amber-900 dark:text-amber-100">{{ number_format((int) $showingCount) }}</div>
                    <div class="mt-1 text-sm text-amber-800 dark:text-amber-200">This is the current queue for the selected scope below, not the whole business forever.</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/20">
                    <div class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Older backlog</div>
                    <div class="mt-1 text-3xl font-semibold text-emerald-900 dark:text-emerald-100">{{ number_format((int) $backlogCount) }}</div>
                    <div class="mt-1 text-sm text-emerald-800 dark:text-emerald-200">Rows from before today. This is the real burn-down number.</div>
                </div>

                <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 dark:border-sky-900 dark:bg-sky-950/20">
                    <div class="text-xs uppercase tracking-wide text-sky-700 dark:text-sky-300">New today</div>
                    <div class="mt-1 text-3xl font-semibold text-sky-900 dark:text-sky-100">{{ number_format((int) $todayCount) }}</div>
                    <div class="mt-1 text-sm text-sky-800 dark:text-sky-200">Fresh rows still arriving today. This can hide progress if you look only at one total.</div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-900/60">
                    <div class="text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">Total unresolved</div>
                    <div class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">{{ number_format((int) $totalMissingCount) }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">Everything still unresolved for this business across backlog and today together.</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="grid gap-4 md:grid-cols-[0.38fr_0.38fr_1fr]">
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
                    <div>
                        <div class="text-sm font-medium text-gray-950 dark:text-white">Business</div>
                        <div class="mt-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                            {{ $business?->name ?? 'No business selected' }}
                        </div>
                    </div>
                @endif

                <label class="grid gap-1 text-sm">
                    <span class="font-medium text-gray-950 dark:text-white">Queue view</span>
                    <select wire:model.live="scope" class="fi-input block w-full rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-950 shadow-sm outline-none transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-white/5 dark:text-white">
                        @foreach ($scopeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-1 text-sm">
                    <span class="font-medium text-gray-950 dark:text-white">Find an order or product hint</span>
                    <input wire:model.live.debounce.400ms="search" type="search" placeholder="Search order ID, customer, phone, tracking, product hint" class="fi-input block w-full rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-950 shadow-sm outline-none transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-white/5 dark:text-white" />
                </label>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="grid gap-4">
                @if (session('missing_product_repair_success'))
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">
                        {{ session('missing_product_repair_success') }}
                    </div>
                @endif

                @if (session('missing_product_repair_warning'))
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                        {{ session('missing_product_repair_warning') }}
                    </div>
                @endif

                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Fix repeated product hints first</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Choose one SKU for a repeated Stock App product hint. HELOS repairs up to 500 matching rows in the selected queue view, recalculates them, and removes the group once fixed.</p>
                </div>

                <div class="rounded-lg border border-sky-200 bg-sky-50/70 p-4 dark:border-sky-900 dark:bg-sky-950/20">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-sky-700 dark:text-sky-300">Quick clean-up</div>
                            <div class="mt-1 font-semibold text-gray-950 dark:text-white">Auto-fix only the obvious rows</div>
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                This checks for exact SKU code matches and exact normalized product-name matches only. It skips anything doubtful.
                            </div>
                        </div>

                        <x-filament::button wire:click="autoFixObviousMatches" icon="heroicon-o-sparkles" color="info">
                            Auto-fix obvious matches
                        </x-filament::button>
                    </div>
                </div>

                <div class="grid gap-3">
                    @forelse ($groups as $group)
                        <div wire:key="group-{{ $group['key'] }}" class="rounded-lg border border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-900 dark:bg-emerald-950/20">
                            <div class="grid gap-4 lg:grid-cols-[1fr_0.45fr_0.3fr] lg:items-end">
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Repeated Stock App hint</div>
                                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $group['hint'] }}</div>
                                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                        {{ number_format((int) $group['count']) }} row(s) match this hint. Example: {{ $group['example'] ?? 'No order ID' }}
                                    </div>
                                </div>

                                <label class="grid gap-1 text-sm">
                                    <span class="font-medium text-gray-950 dark:text-white">Correct product</span>
                                    <input
                                        wire:model.live.debounce.300ms="bulkSkuSearches.{{ $group['key'] }}"
                                        type="search"
                                        placeholder="Search SKU code or name"
                                        class="fi-input block w-full rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-950 shadow-sm outline-none transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-white/5 dark:text-white"
                                    />
                                    <select wire:model="bulkSkuSelections.{{ $group['key'] }}" class="fi-input block w-full rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-950 shadow-sm outline-none transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-white/5 dark:text-white">
                                        <option value="">Choose product / SKU</option>
                                        @foreach ($this->skuOptionsForSearch($bulkSkuSearches[$group['key']] ?? '', $bulkSkuSelections[$group['key']] ?? null) as $id => $label)
                                            <option value="{{ $id }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <x-filament::button wire:click="assignGroup('{{ $group['key'] }}', null)" icon="heroicon-o-check-circle">
                                    Repair and recalculate
                                </x-filament::button>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                            No repeated product hints found in this search. Use manual repair for rows where Stock App did not send the product.
                        </div>
                    @endforelse
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="grid gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Manual repair for unclear rows</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Use this when Stock App did not send a usable product hint, or when one row needs a different product.</p>
                </div>

                @forelse ($rows as $row)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="grid gap-4 xl:grid-cols-[1fr_0.8fr_0.8fr_0.9fr] xl:items-start">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-500">Order row</div>
                                <div class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $row['external_id'] }}</div>
                                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $row['occurred_at'] }} / {{ ucfirst($row['event_type']) }}</div>
                                <div class="mt-2 inline-flex rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ strtoupper((string) $row['channel']) }}</div>
                            </div>

                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-500">Customer</div>
                                <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $row['customer'] }}</div>
                                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $row['phone'] ?: 'No phone sent' }}</div>
                                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $row['tracking_number'] }}</div>
                            </div>

                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-500">What Stock App sent</div>
                                <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $row['product_hint'] }}</div>
                                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">Qty {{ $row['quantity'] }} / LKR {{ number_format((float) $row['sale_amount'], 2) }}</div>
                            </div>

                            <div>
                                <label class="grid gap-1 text-sm">
                                    <span class="font-medium text-gray-950 dark:text-white">Correct product</span>
                                    <input
                                        wire:model.live.debounce.300ms="skuSearches.{{ $row['id'] }}"
                                        type="search"
                                        placeholder="Search SKU code or name"
                                        class="fi-input block w-full rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-950 shadow-sm outline-none transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-white/5 dark:text-white"
                                    />
                                    <select wire:model="skuSelections.{{ $row['id'] }}" class="fi-input block w-full rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-950 shadow-sm outline-none transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-white/5 dark:text-white">
                                        <option value="">Choose product / SKU</option>
                                        @foreach ($this->skuOptionsForSearch($skuSearches[$row['id']] ?? '', $skuSelections[$row['id']] ?? null) as $id => $label)
                                            <option value="{{ $id }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <div class="mt-3">
                                    <x-filament::button wire:click="assignSku({{ $row['id'] }})" icon="heroicon-o-check">
                                        Save and recalculate
                                    </x-filament::button>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-700">
                        No missing product links found for this business.
                    </div>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

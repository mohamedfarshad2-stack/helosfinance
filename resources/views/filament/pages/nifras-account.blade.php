<x-filament-panels::page>
    <div class="space-y-6" wire:poll.60s="refreshAccount">
        @if (! $isNifras)
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
                This account is restricted to Nifras.
            </div>
        @elseif (! $hasBusiness)
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
                No accessible business was found for this account.
            </div>
        @else
            @php
                $wholesale = $pipeline['wholesale'] ?? [];
                $teamOverdue = collect($teamRows)->sum('overdue');
            @endphp

            <div class="flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Nifras account</h1>
                    <p class="text-sm text-gray-500">
                        Live wholesale money and direct reports for {{ $business?->name ?? 'this business' }}.
                    </p>
                </div>
                <x-filament::button tag="a" href="{{ \App\Filament\Resources\OperationalEventResource::getUrl('index') }}" color="gray" icon="heroicon-o-bolt">
                    Open order events
                </x-filament::button>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Wholesale collection target</div>
                    <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($wholesale['expected_revenue'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500">{{ (int) ($wholesale['pending_orders'] ?? 0) }} order(s) still need collection.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Collected this month</div>
                    <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($wholesale['collected_revenue'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500">{{ (int) ($wholesale['delivered_orders'] ?? 0) }} closed order(s).</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Return pressure</div>
                    <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($wholesale['returned_revenue'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500">{{ (int) ($wholesale['returned_orders'] ?? 0) }} returned order(s).</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Team overdue</div>
                    <div class="mt-1 text-3xl font-semibold">{{ $teamOverdue }}</div>
                    <div class="mt-1 text-sm text-gray-500">Direct reports need follow-up or review.</div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-4 py-3">
                    <div class="text-sm font-semibold text-gray-900">Team summary</div>
                    <div class="text-xs text-gray-500">Only direct reports linked to Nifras are shown here.</div>
                </div>

                <div class="divide-y divide-gray-100">
                    @forelse ($teamRows as $row)
                        <div class="grid gap-2 px-4 py-3 md:grid-cols-12 md:items-center">
                            <div class="md:col-span-4">
                                <div class="font-medium text-gray-900">{{ $row['name'] }}</div>
                                <div class="text-xs text-gray-500">{{ implode(' | ', $row['responsibilities']) ?: 'No active responsibilities' }}</div>
                            </div>
                            <div class="md:col-span-2 text-sm text-gray-700">
                                <div class="text-xs uppercase text-gray-500">Open</div>
                                {{ $row['open'] }}
                            </div>
                            <div class="md:col-span-2 text-sm text-gray-700">
                                <div class="text-xs uppercase text-gray-500">Overdue</div>
                                {{ $row['overdue'] }}
                            </div>
                            <div class="md:col-span-2 text-sm text-gray-700">
                                <div class="text-xs uppercase text-gray-500">Status</div>
                                {{ $row['status'] }}
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-sm text-gray-500">No direct reports are linked to this account yet.</div>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>

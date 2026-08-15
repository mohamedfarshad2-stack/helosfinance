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
                    <div class="text-sm font-medium text-gray-600">Team work</div>
                    <div class="mt-1 text-base font-semibold text-gray-900">Open in My Work</div>
                    <div class="mt-1 text-sm text-gray-500">Direct-report review now lives on the team work page.</div>
                    <div class="mt-3">
                        <x-filament::button tag="a" href="{{ \App\Filament\Pages\TodaysWork::getUrl() }}" color="gray" size="sm" icon="heroicon-o-clipboard-document-check">
                            Open My Work
                        </x-filament::button>
                    </div>
                </div>
            </div>

        @endif
    </div>
</x-filament-panels::page>

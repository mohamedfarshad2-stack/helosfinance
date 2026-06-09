<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
        <x-filament::section>
            <div class="mb-4 flex items-center justify-end gap-3">
                <x-filament::button tag="a" href="{{ url('/admin/bank-transactions') }}" color="gray" icon="heroicon-o-clipboard-document-list">
                    Open bank review
                </x-filament::button>
                @if (auth()->user()?->seesAllBusinesses())
                    <x-filament::button tag="a" href="{{ url('/admin/bank-transaction-rules') }}" color="gray" icon="heroicon-o-adjustments-horizontal">
                        Bank rules
                    </x-filament::button>
                @endif
            </div>
            <form wire:submit="import" class="grid gap-6">
                {{ $this->form }}

                <div class="flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">
                        Import statement
                    </x-filament::button>
                </div>
            </form>

            <x-filament-actions::modals />
        </x-filament::section>

        <x-filament::section>
            <div class="grid gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Recent imported rows</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Rows waiting for review or already classified.</p>
                </div>

                <div class="grid gap-3">
                    @forelse ($recentTransactions as $transaction)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-gray-950 dark:text-white">{{ $transaction->description }}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ optional($transaction->transaction_date)->format('Y-m-d') }} • {{ $transaction->classification }} • {{ $transaction->status }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-gray-950 dark:text-white">
                                        @if ((float) $transaction->credit > 0)
                                            + LKR {{ number_format((float) $transaction->credit, 2) }}
                                        @else
                                            - LKR {{ number_format((float) $transaction->debit, 2) }}
                                        @endif
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">Conf. {{ number_format((float) $transaction->confidence, 2) }}</div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            No bank transactions imported yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

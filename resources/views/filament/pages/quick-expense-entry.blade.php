<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
        <x-filament::section>
            <div class="mb-4 grid gap-3 md:grid-cols-3">
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                    <div class="text-sm font-medium text-gray-600 dark:text-gray-400">Petty cash loaded</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">LKR {{ number_format((float) ($cashSummary['loaded'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">Transferred into petty cash / store cash.</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                    <div class="text-sm font-medium text-gray-600 dark:text-gray-400">Quick spends recorded</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">LKR {{ number_format((float) ($cashSummary['spent'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">Only quick petty-cash spends are counted here.</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                    <div class="text-sm font-medium text-gray-600 dark:text-gray-400">Cash left</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">LKR {{ number_format((float) ($cashSummary['remaining'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">This is what remains after quick expense entries.</div>
                </div>
            </div>

            <div class="mb-4 flex justify-end">
                <x-filament::button tag="a" href="{{ url('/admin/expenses') }}" color="gray" icon="heroicon-o-rectangle-stack">
                    Open expense review
                </x-filament::button>
            </div>
            <form wire:submit="save" class="grid gap-6">
                {{ $this->form }}

                <div class="flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-o-plus-circle">
                        Save expense
                    </x-filament::button>
                </div>
            </form>

            <x-filament-actions::modals />
        </x-filament::section>

        <x-filament::section>
            <div class="grid gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Recent spend</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">A quick look at what was entered most recently.</p>
                </div>

                <div class="grid gap-3">
                    @forelse ($recentExpenses as $expense)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-gray-950 dark:text-white">{{ $expense->category }}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ optional($expense->spent_on)->format('Y-m-d') }} • {{ match ($expense->payment_status) {
                                            'paid' => 'Paid',
                                            'partial' => 'Part paid',
                                            'cheque_pending' => 'Cheque waiting',
                                            'credit_due' => 'Pay later',
                                            default => ucfirst((string) $expense->payment_status),
                                        } }}
                                        @if ($expense->payee)
                                            • {{ $expense->payee }}
                                        @endif
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-gray-950 dark:text-white">LKR {{ number_format((float) $expense->amount, 2) }}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">Paid today {{ number_format((float) $expense->paid_amount, 2) }}</div>
                                    @if ($expense->cheque_number)
                                        <div class="text-sm text-gray-500 dark:text-gray-400">Cheque {{ $expense->cheque_number }}</div>
                                    @endif
                                </div>
                            </div>
                            @if ($expense->description)
                                <div class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $expense->description }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            No quick petty-cash spend recorded yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

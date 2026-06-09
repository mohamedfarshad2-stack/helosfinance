<x-filament-widgets::widget>
    <x-filament::section>
        <div class="grid gap-6">
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">What to do next</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">One decision story for the current month, with the supporting numbers underneath.</p>
            </div>

            @if (! $hasBusiness)
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    No client business is linked to this login yet.
                </div>
            @else
                @php
                    $decisionStory = $insights['decision_story'] ?? $insights['briefing']['decision_story'] ?? [];
                @endphp

                <div class="grid gap-4 lg:grid-cols-[1.3fr_0.7fr]">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="text-xs uppercase tracking-wide text-gray-500">What to do next</div>
                        <div class="mt-2 text-xl font-semibold text-gray-950 dark:text-white">
                            {{ $decisionStory['headline'] ?? 'The month is still being read from the current numbers.' }}
                        </div>

                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">What happened</div>
                                <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                    {{ $decisionStory['what_happened'] ?? 'The month has been measured across money coming in, money left after running the business, money still waiting to settle, returns, stock, and tied-up money.' }}
                                </div>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Why it matters</div>
                                <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                    {{ $decisionStory['why_it_matters'] ?? 'It changes the business picture for money left after running the business, money still waiting to settle, and running pressure.' }}
                                </div>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Risk</div>
                                <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                    {{ $decisionStory['risk'] ?? 'No major risk surfaced yet.' }}
                                </div>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Opportunity</div>
                                <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                    {{ $decisionStory['opportunity'] ?? 'No clear opportunity surfaced yet.' }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">First action</div>
                            <div class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                {{ $decisionStory['first_action'] ?? 'Review the briefing before changing anything major.' }}
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-4">
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Money left after running the business</div>
                            <div class="mt-2 text-2xl font-bold {{ ($insights['profit'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                LKR {{ number_format($insights['profit'] ?? 0, 2) }}
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Money coming in</div>
                            <div class="mt-2 text-2xl font-bold text-sky-600">
                                LKR {{ number_format($insights['revenue'] ?? 0, 2) }}
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Money still waiting to settle</div>
                            <div class="mt-2 text-2xl font-bold text-amber-600">
                                LKR {{ number_format($insights['cash_due'] ?? 0, 2) }}
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">People cost</div>
                            <div class="mt-2 text-2xl font-bold text-violet-600">
                                LKR {{ number_format($insights['salary_pressure'] ?? 0, 2) }}
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

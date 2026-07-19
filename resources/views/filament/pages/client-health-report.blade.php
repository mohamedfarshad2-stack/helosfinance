<x-filament-panels::page>
    <!-- owner-dashboard-build: delivered-revenue-only-2026-07-19 -->
    <div class="grid gap-6">
        <x-filament::section>
            <div class="text-sm text-gray-600 dark:text-gray-300">
                HELOS guides setup first, then reads the business. Start with the setup guide, fix red flags, and use the owner map to see what needs attention.
            </div>
        </x-filament::section>

        <x-filament::section>
            <form wire:submit.prevent="refreshReport">
                <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    {{ $this->form }}
                    <div>
                        <x-filament::button type="submit" icon="heroicon-o-arrow-path">
                            Refresh report
                        </x-filament::button>
                    </div>
                </div>
            </form>
        </x-filament::section>

        @if (! $business)
            <x-filament::section>
                <div class="text-sm text-gray-500">No client found for this report.</div>
            </x-filament::section>
        @else
            @php
                $trustSummary = $trustStatus['headline'] ?? 'Trust status not ready yet.';
                $trustLabel = $trustStatus['status_label'] ?? 'Estimated';
                $trustLabelClasses = match ($trustLabel) {
                    'Verified' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
                    'Pending Validation' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200',
                    default => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
                };
                $trustWarnings = $trustStatus['warnings'] ?? ['critical' => [], 'important' => [], 'informational' => []];
                $trustCenter = $trustStatus['trust_center'] ?? ['headline' => '', 'cards' => []];
                $ownerMap = $ownerBusinessMap ?? ['headline' => '', 'default_key' => 'trust', 'nodes' => [], 'top_actions' => []];
                $setupGuide = $ownerSetupGuide ?? ['steps' => [], 'progress' => 0, 'completed' => 0, 'total' => 0];
                $coach = $ownerCoach ?? ['coach_cards' => [], 'next_action' => null, 'do_first' => []];
                $setupSteps = collect($setupGuide['steps'] ?? []);
                $pendingSetupSteps = $setupSteps->reject(fn (array $step): bool => (bool) ($step['done'] ?? false))->values();
                $supportsService = $business?->supportsBusinessType(\App\Domains\Shared\Models\Business::TYPE_SERVICE) ?? false;
                $supportsTradeOrManufacturing = ($business?->supportsBusinessType(\App\Domains\Shared\Models\Business::TYPE_TRADING) ?? false)
                    || ($business?->supportsBusinessType(\App\Domains\Shared\Models\Business::TYPE_MANUFACTURING) ?? false);
                $supportsCapital = $business?->supportsCapitalIntelligence() ?? false;
                $supportsInventory = $business?->supportsInventoryIntelligence() ?? false;
            @endphp

            <x-filament::section>
                <div
                    x-data="{ selected: @js($ownerMap['default_key'] ?? 'trust') }"
                    class="grid gap-4"
                >
                    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-950">
                        <div class="border-b border-gray-100 bg-gray-50 px-5 py-4 dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Owner Home</div>
                                    <div class="mt-1 text-xl font-black text-gray-950 dark:text-white">Whole business at a glance</div>
                                </div>
                                <div class="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                                    Click any flag to see what to fix next
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-5 bg-white p-5 dark:bg-gray-950 xl:grid-cols-[280px_1fr_400px]">
                            <div class="rounded-2xl border border-sky-100 bg-sky-50 p-5 dark:border-sky-900 dark:bg-sky-950/30">
                                <div class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-200">Control Score</div>
                                <div class="mt-4 flex justify-center">
                                    <div
                                        class="grid h-36 w-36 place-items-center rounded-full"
                                        style="background: conic-gradient(#0ea5e9 {{ (int) ($ownerMap['overall_score'] ?? 0) }}%, #e5e7eb 0);"
                                    >
                                        <div class="grid h-28 w-28 place-items-center rounded-full bg-white text-center shadow-sm dark:bg-gray-950">
                                            <div>
                                                <div class="text-4xl font-black text-gray-950 dark:text-white">{{ (int) ($ownerMap['overall_score'] ?? 0) }}%</div>
                                                <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Control</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4 grid grid-cols-4 gap-2 text-center">
                                    <div class="rounded-lg bg-red-100 p-2 text-red-800 dark:bg-red-950/60 dark:text-red-100">
                                        <div class="text-xl font-bold">{{ (int) ($ownerMap['counts']['red'] ?? 0) }}</div>
                                        <div class="text-[10px] uppercase opacity-70">Red</div>
                                    </div>
                                    <div class="rounded-lg bg-amber-100 p-2 text-amber-800 dark:bg-amber-950/60 dark:text-amber-100">
                                        <div class="text-xl font-bold">{{ (int) ($ownerMap['counts']['amber'] ?? 0) }}</div>
                                        <div class="text-[10px] uppercase opacity-70">Amber</div>
                                    </div>
                                    <div class="rounded-lg bg-emerald-100 p-2 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-100">
                                        <div class="text-xl font-bold">{{ (int) ($ownerMap['counts']['green'] ?? 0) }}</div>
                                        <div class="text-[10px] uppercase opacity-70">Green</div>
                                    </div>
                                    <div class="rounded-lg bg-gray-100 p-2 text-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                        <div class="text-xl font-bold">{{ (int) ($ownerMap['counts']['gray'] ?? 0) }}</div>
                                        <div class="text-[10px] uppercase opacity-70">Ready?</div>
                                    </div>
                                </div>
                                <div class="mt-4 rounded-xl bg-white p-3 text-sm leading-6 text-gray-700 dark:bg-gray-950 dark:text-gray-300">{{ $ownerMap['headline'] ?? 'The business map is being prepared.' }}</div>
                            </div>

                            <div class="grid gap-3">
                                <div class="grid gap-3 md:grid-cols-4">
                                    @foreach (($ownerMap['key_metrics'] ?? []) as $metric)
                                        @php
                                            $metricTone = $metric['tone'] ?? 'amber';
                                            $metricBar = match ($metricTone) {
                                                'red' => 'bg-red-400',
                                                'green' => 'bg-emerald-400',
                                                'gray' => 'bg-gray-400',
                                                default => 'bg-amber-300',
                                            };
                                        @endphp
                                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                                            <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $metric['label'] ?? 'Metric' }}</div>
                                            <div class="mt-2 text-xl font-black text-gray-950 dark:text-white">{{ $metric['value'] ?? '-' }}</div>
                                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                                                <div class="h-full rounded-full {{ $metricBar }}" style="width: {{ (int) ($metric['percent'] ?? 0) }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="grid gap-3 md:grid-cols-[1fr_220px]">
                                    <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                                        @foreach (($ownerMap['nodes'] ?? []) as $node)
                                            @php
                                                $tone = $node['tone'] ?? 'amber';
                                                $nodeStyle = match ($tone) {
                                                    'red' => 'border-red-200 bg-red-50 text-red-900 hover:bg-red-100 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100',
                                                    'green' => 'border-emerald-200 bg-emerald-50 text-emerald-900 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100',
                                                    'gray' => 'border-gray-200 bg-gray-50 text-gray-900 hover:bg-gray-100 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100',
                                                    default => 'border-amber-200 bg-amber-50 text-amber-900 hover:bg-amber-100 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100',
                                                };
                                                $dotClasses = match ($tone) {
                                                    'red' => 'bg-red-400',
                                                    'green' => 'bg-emerald-400',
                                                    'gray' => 'bg-gray-300',
                                                    default => 'bg-amber-300',
                                                };
                                            @endphp
                                            <button
                                                type="button"
                                                x-on:click="selected = @js($node['key'])"
                                                x-bind:class="selected === @js($node['key']) ? 'ring-2 ring-gray-950 ring-offset-2 dark:ring-white dark:ring-offset-gray-950' : ''"
                                                class="min-h-[104px] rounded-2xl border p-3 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md {{ $nodeStyle }}"
                                            >
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="h-2.5 w-2.5 rounded-full {{ $dotClasses }}"></span>
                                                    <span class="text-[10px] font-bold uppercase tracking-wide opacity-75">{{ (int) ($node['progress'] ?? 0) }}%</span>
                                                </div>
                                                <div class="mt-3 text-xs font-semibold uppercase tracking-wide opacity-70">{{ $node['title'] ?? 'Map area' }}</div>
                                                <div class="mt-1 text-base font-black">{{ $node['value'] ?? '-' }}</div>
                                            </button>
                                        @endforeach
                                    </div>

                                    <div class="grid gap-2">
                                        @foreach (($ownerMap['mini_graphs'] ?? []) as $graph)
                                            @php
                                                $graphTone = $graph['tone'] ?? 'amber';
                                                $graphBar = match ($graphTone) {
                                                    'red' => 'bg-red-400',
                                                    'green' => 'bg-emerald-400',
                                                    'gray' => 'bg-gray-400',
                                                    default => 'bg-amber-300',
                                            };
                                        @endphp
                                            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
                                                <div class="flex items-center justify-between gap-2 text-xs">
                                                    <span class="font-semibold text-gray-600 dark:text-gray-300">{{ $graph['label'] ?? 'Graph' }}</span>
                                                    <span class="font-bold text-gray-950 dark:text-white">{{ $graph['value'] ?? '-' }}</span>
                                                </div>
                                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                                                    <div class="h-full rounded-full {{ $graphBar }}" style="width: {{ (int) ($graph['percent'] ?? 0) }}%"></div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-800 dark:bg-gray-900">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Selected flag repair path</div>
                                @foreach (($ownerMap['nodes'] ?? []) as $node)
                                    <div x-show="selected === @js($node['key'])" x-cloak class="mt-3 grid gap-3">
                                        @php
                                            $panelTone = $node['tone'] ?? 'amber';
                                            $panelBadge = match ($panelTone) {
                                                'red' => 'bg-red-400 text-red-950',
                                                'green' => 'bg-emerald-400 text-emerald-950',
                                                'gray' => 'bg-gray-300 text-gray-950',
                                                default => 'bg-amber-300 text-amber-950',
                                            };
                                        @endphp
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="text-xl font-black text-gray-950 dark:text-white">{{ $node['title'] ?? 'Map area' }}</div>
                                                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $node['heading'] ?? '' }}</div>
                                            </div>
                                            <div class="rounded-full px-3 py-1 text-[11px] font-black uppercase tracking-wide {{ $panelBadge }}">{{ $node['flag'] ?? 'Flag' }}</div>
                                        </div>
                                        <div class="rounded-xl bg-white p-3 text-sm text-gray-700 shadow-sm dark:bg-gray-950 dark:text-gray-300">
                                            <div class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Why this happened</div>
                                            <div class="mt-1">{{ $node['why'] ?? '' }}</div>
                                        </div>
                                        <div class="rounded-xl bg-white p-3 text-sm text-gray-700 shadow-sm dark:bg-gray-950 dark:text-gray-300">
                                            <div class="text-[11px] font-bold uppercase tracking-wide text-gray-500">How to turn it green</div>
                                            <div class="mt-1">{{ $node['make_green'] ?? '' }}</div>
                                        </div>
                                        <div class="rounded-xl bg-white p-3 text-sm text-gray-700 shadow-sm dark:bg-gray-950 dark:text-gray-300">
                                            <div class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Next action</div>
                                            <div class="mt-1">{{ $node['next_step'] ?? '' }}</div>
                                        </div>
                                        <a href="{{ $node['anchor'] ?? '#helos-details' }}" class="inline-flex items-center justify-center rounded-lg bg-gray-950 px-4 py-2 text-sm font-bold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200">
                                            Go deeper
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="grid gap-3 border-t border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-900 md:grid-cols-3">
                            @foreach (($ownerMap['top_actions'] ?? []) as $action)
                                @php
                                    $miniTone = $action['tone'] ?? 'amber';
                                    $miniClasses = match ($miniTone) {
                                        'red' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
                                        'green' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
                                        'gray' => 'border-gray-200 bg-gray-50 text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100',
                                        default => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
                                    };
                                @endphp
                                <button
                                    type="button"
                                    x-on:click="selected = @js($action['key'])"
                                    class="rounded-lg border p-3 text-left transition hover:-translate-y-0.5 hover:shadow-sm {{ $miniClasses }}"
                                >
                                    <div class="text-[11px] font-semibold uppercase tracking-wide">{{ $action['flag'] ?? 'Watch' }}</div>
                                    <div class="mt-1 text-sm font-bold">{{ $action['title'] ?? 'Area' }}</div>
                                    <div class="mt-1 text-xs opacity-80">{{ $action['next_step'] ?? '' }}</div>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="grid gap-4">
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900 dark:bg-emerald-950/30">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <div class="text-xs font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-200">HELOS Coach</div>
                                <div class="mt-2 text-2xl font-black text-gray-950 dark:text-white">{{ $coach['headline'] ?? 'HELOS is checking what to do next.' }}</div>
                                <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                                    Mode: <strong>{{ $coach['mode'] ?? 'Setup mode' }}</strong>
                                    <span class="mx-2">|</span>
                                    New user verdict: <strong>{{ $coach['new_user_verdict'] ?? 'Needs guided onboarding' }}</strong>
                                </div>
                            </div>
                            <div class="min-w-[160px] rounded-xl bg-white p-3 text-right shadow-sm dark:bg-gray-950">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Setup Ready</div>
                                <div class="mt-1 text-3xl font-black text-gray-950 dark:text-white">{{ (int) ($coach['setup_progress'] ?? 0) }}%</div>
                                <div class="text-xs text-gray-500">{{ $coach['trust_label'] ?? 'Estimated' }}</div>
                            </div>
                        </div>

                        @if (! empty($coach['next_action'] ?? null))
                            @php
                                $coachNext = $coach['next_action'];
                            @endphp
                            <div class="mt-4 rounded-xl border border-emerald-200 bg-white p-4 shadow-sm dark:border-emerald-900 dark:bg-gray-950">
                                <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-center">
                                    <div>
                                        <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Do this next</div>
                                        <div class="mt-1 text-lg font-black text-gray-950 dark:text-white">{{ $coachNext['title'] ?? 'Next step' }}</div>
                                        <div class="mt-2 grid gap-2 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-3">
                                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                                <div class="text-[11px] font-bold uppercase tracking-wide text-gray-500">What to enter</div>
                                                <div class="mt-1">{{ $coachNext['what_to_enter'] ?? 'Open the screen and complete the missing information.' }}</div>
                                            </div>
                                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                                <div class="text-[11px] font-bold uppercase tracking-wide text-gray-500">When done</div>
                                                <div class="mt-1">{{ $coachNext['when_done'] ?? 'HELOS can trust this part more.' }}</div>
                                            </div>
                                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                                <div class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Unsafe if skipped</div>
                                                <div class="mt-1">{{ $coachNext['numbers_at_risk'] ?? 'Owner numbers' }}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <a href="{{ $coachNext['url'] ?? '#' }}" class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-500">
                                        {{ $coachNext['action'] ?? 'Open next step' }}
                                    </a>
                                </div>
                            </div>
                        @endif

                        @if (! empty($coach['focus_cards'] ?? []))
                            <div class="mt-4 grid gap-3 md:grid-cols-3">
                                @foreach (($coach['focus_cards'] ?? []) as $card)
                                    @php
                                        $focusTone = $card['tone'] ?? 'amber';
                                        $focusClasses = match ($focusTone) {
                                            'red' => 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
                                            'green' => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
                                            default => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
                                        };
                                    @endphp
                                    <div class="rounded-xl border p-4 {{ $focusClasses }}">
                                        <div class="text-[11px] font-bold uppercase tracking-wide opacity-75">{{ $card['title'] ?? 'Focus' }}</div>
                                        <div class="mt-2 text-lg font-black">{{ $card['value'] ?? '-' }}</div>
                                        <div class="mt-2 text-sm leading-6 opacity-85">{{ $card['note'] ?? '' }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="grid gap-3 md:grid-cols-3">
                        @foreach (($coach['coach_cards'] ?? []) as $card)
                            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-950">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $card['title'] ?? 'Coach item' }}</div>
                                <div class="mt-2 text-lg font-black text-gray-950 dark:text-white">{{ $card['value'] ?? '-' }}</div>
                                <div class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $card['note'] ?? '' }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="grid gap-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Start Here</div>
                            <div class="mt-1 text-xl font-black text-gray-950 dark:text-white">
                                {{ $pendingSetupSteps->isNotEmpty() ? ($setupGuide['headline'] ?? 'Setup path is being prepared.') : 'Setup basics are complete.' }}
                            </div>
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $setupGuide['subheadline'] ?? 'HELOS will guide the owner through the first safe setup steps.' }}</div>
                        </div>
                        <div class="min-w-[180px] rounded-xl border border-gray-200 bg-gray-50 p-3 text-right dark:border-gray-800 dark:bg-gray-900">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Setup Progress</div>
                            <div class="mt-1 text-3xl font-black text-gray-950 dark:text-white">{{ (int) ($setupGuide['progress'] ?? 0) }}%</div>
                            <div class="text-xs text-gray-500">{{ (int) ($setupGuide['completed'] ?? 0) }} of {{ (int) ($setupGuide['total'] ?? 0) }} done</div>
                        </div>
                    </div>

                    @if ($pendingSetupSteps->isNotEmpty() && ! empty($setupGuide['next_step'] ?? null))
                        @php
                            $next = $setupGuide['next_step'];
                        @endphp
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div class="text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-200">Next owner setup action</div>
                                    <div class="mt-1 text-lg font-black text-gray-950 dark:text-white">{{ $next['title'] ?? 'Next step' }}</div>
                                    <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $next['why'] ?? '' }}</div>
                                </div>
                                <a href="{{ $next['url'] ?? '#' }}" class="inline-flex items-center justify-center rounded-lg bg-gray-950 px-4 py-2 text-sm font-bold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200">
                                    {{ $next['action'] ?? 'Open' }}
                                </a>
                            </div>
                        </div>
                    @endif

                    @if ((int) ($setupGuide['completed'] ?? 0) > 0)
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-200">Completed setup work</div>
                                    <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                        {{ (int) ($setupGuide['completed'] ?? 0) }} setup item(s) are already done, so HELOS has removed them from the live checklist and increased your setup readiness.
                                    </div>
                                </div>
                                <div class="rounded-full bg-white px-3 py-1 text-xs font-black uppercase tracking-wide text-emerald-700 shadow-sm dark:bg-gray-950 dark:text-emerald-200">
                                    {{ (int) ($setupGuide['progress'] ?? 0) }}% ready
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($pendingSetupSteps->isNotEmpty())
                        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($pendingSetupSteps as $step)
                            @php
                                $done = (bool) ($step['done'] ?? false);
                                $stepClasses = $done
                                    ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30'
                                    : 'border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950';
                                $badgeClasses = $done
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100'
                                    : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100';
                            @endphp
                            <div class="rounded-xl border p-4 {{ $stepClasses }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="font-bold text-gray-950 dark:text-white">{{ $step['title'] ?? 'Setup step' }}</div>
                                    <div class="rounded-full px-2 py-1 text-[10px] font-black uppercase tracking-wide {{ $badgeClasses }}">{{ $step['status'] ?? 'Needed' }}</div>
                                </div>
                                <div class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $step['why'] ?? '' }}</div>
                                <div class="mt-3 grid gap-2 text-xs text-gray-600 dark:text-gray-300">
                                    <div class="rounded-lg bg-white/70 px-3 py-2 dark:bg-gray-900/70">
                                        <span class="font-bold text-gray-800 dark:text-gray-100">Enter: </span>{{ $step['what_to_enter'] ?? 'Complete the required fields on this screen.' }}
                                    </div>
                                    <div class="rounded-lg bg-white/70 px-3 py-2 dark:bg-gray-900/70">
                                        <span class="font-bold text-gray-800 dark:text-gray-100">Then HELOS can trust: </span>{{ $step['when_done'] ?? 'This setup area.' }}
                                    </div>
                                    <div class="rounded-lg bg-white/70 px-3 py-2 dark:bg-gray-900/70">
                                        <span class="font-bold text-gray-800 dark:text-gray-100">Unsafe if skipped: </span>{{ $step['numbers_at_risk'] ?? 'Owner metrics.' }}
                                    </div>
                                </div>
                                <a href="{{ $step['url'] ?? '#' }}" class="mt-3 inline-flex text-sm font-bold text-emerald-700 hover:text-emerald-900 dark:text-emerald-300 dark:hover:text-emerald-100">
                                    {{ $step['action'] ?? 'Open' }}
                                </a>
                            </div>
                        @endforeach
                        </div>
                    @else
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900 dark:bg-emerald-950/30">
                            <div class="text-lg font-black text-gray-950 dark:text-white">No setup tasks left in this checklist.</div>
                            <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                                The foundation steps on this page are complete. HELOS can now focus on trust warnings, daily work, profitability, and owner guidance instead of basic setup.
                            </div>
                        </div>
                    @endif
                </div>
            </x-filament::section>


            <x-filament::section>
                <div id="helos-trust-center" class="grid gap-4 scroll-mt-24">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-500">HELOS Trust Center</div>
                                <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">{{ $trustSummary }}</div>
                                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">Use this first to decide whether the month is verified, estimated, or still missing key information.</div>
                            </div>
                            <div class="rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wide {{ $trustLabelClasses }}">
                                {{ $trustLabel }}
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Data Quality</div>
                                <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ (int) ($trustStatus['data_quality_percent'] ?? 0) }}%</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Validation Issues</div>
                                <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ (int) ($trustStatus['validation_issue_count'] ?? 0) }}</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Missing Information</div>
                                <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ (int) ($trustStatus['missing_information_count'] ?? 0) }}</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Estimated Numbers</div>
                                <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ (int) ($trustStatus['estimated_numbers_count'] ?? 0) }}</div>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                            @foreach ([
                                ['label' => 'Data Quality', 'value' => (int) ($trustStatus['data_quality_percent'] ?? 0).'%'],
                                ['label' => 'Business Completeness', 'value' => (int) ($trustStatus['business_completeness_percent'] ?? 0).'%'],
                                ['label' => 'Calculation Validation', 'value' => strtoupper((string) ($trustStatus['calculation_validation']['overall_status'] ?? 'PASS'))],
                                ['label' => 'Integration Health', 'value' => ucfirst((string) ($trustStatus['integration_health']['status'] ?? 'never synced'))],
                                ['label' => 'Allocation Quality', 'value' => (int) ($trustStatus['allocation_quality']['allocation_quality_percent'] ?? 0).'%'],
                                ['label' => 'Hosting Readiness', 'value' => (int) ($trustStatus['hosting_readiness']['score'] ?? 0).'%'],
                            ] as $card)
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['label'] }}</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ $card['value'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-3">
                        @foreach (['critical' => 'Critical', 'important' => 'Important', 'informational' => 'Informational'] as $severity => $label)
                            @php
                                $warningTone = match ($severity) {
                                    'critical' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30',
                                    'important' => 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
                                    default => 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/30',
                                };
                                $warningAccent = match ($severity) {
                                    'critical' => 'text-red-700 dark:text-red-200',
                                    'important' => 'text-amber-700 dark:text-amber-200',
                                    default => 'text-sky-700 dark:text-sky-200',
                                };
                                $items = $trustWarnings[$severity] ?? [];
                            @endphp
                            <div class="rounded-lg border p-4 dark:border-gray-800 {{ $warningTone }}">
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }} warnings</div>
                                <div class="mt-2 text-sm font-semibold {{ $warningAccent }}">{{ count($items) }} item(s)</div>
                                <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                                    @forelse ($items as $item)
                                        @php
                                            $warningUrl = $item['action_url'] ?? null;
                                        @endphp

                                        @if ($warningUrl)
                                            <a href="{{ $warningUrl }}" class="block rounded-lg bg-white/80 px-3 py-2 ring-1 ring-transparent transition hover:-translate-y-0.5 hover:bg-white hover:shadow-sm hover:ring-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:bg-gray-900/70 dark:hover:bg-gray-900 dark:hover:ring-gray-700">
                                                <div class="font-medium text-gray-950 dark:text-white">{{ $item['title'] ?? 'Warning' }}</div>
                                                <div class="mt-1 text-xs text-gray-500">{{ $item['what_is_missing'] ?? '' }}</div>
                                                <div class="mt-1 text-xs text-gray-500">{{ $item['why_it_matters'] ?? '' }}</div>
                                                <div class="mt-2 rounded-md bg-gray-50 px-2 py-1.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                    {{ $item['fix_guidance'] ?? 'Open the related screen and complete the missing information.' }}
                                                </div>
                                                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                                    <div class="text-xs uppercase tracking-wide text-gray-500">May affect: {{ $item['affected_numbers'] ?? 'Owner metrics' }}</div>
                                                    <div class="text-xs font-semibold {{ $warningAccent }}">{{ $item['action_label'] ?? 'Review warning' }} &rarr;</div>
                                                </div>
                                            </a>
                                        @else
                                            <div class="rounded-lg bg-white/70 px-3 py-2 dark:bg-gray-900/70">
                                                <div class="font-medium text-gray-950 dark:text-white">{{ $item['title'] ?? 'Warning' }}</div>
                                                <div class="mt-1 text-xs text-gray-500">{{ $item['what_is_missing'] ?? '' }}</div>
                                                <div class="mt-1 text-xs text-gray-500">{{ $item['why_it_matters'] ?? '' }}</div>
                                                <div class="mt-2 rounded-md bg-gray-50 px-2 py-1.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                    {{ $item['fix_guidance'] ?? 'Open the related screen and complete the missing information.' }}
                                                </div>
                                                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                                    <div class="text-xs uppercase tracking-wide text-gray-500">May affect: {{ $item['affected_numbers'] ?? 'Owner metrics' }}</div>
                                                    <div class="text-xs font-semibold {{ $warningAccent }}">{{ $item['action_label'] ?? 'Review warning' }} &rarr;</div>
                                                </div>
                                            </div>
                                        @endif
                                    @empty
                                        <div class="rounded-lg bg-white/70 px-3 py-2 dark:bg-gray-900/70">No {{ strtolower($label) }} warnings right now.</div>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-500">What HELOS trusts today</div>
                                <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">{{ $trustCenter['headline'] ?? 'Trust center not ready yet.' }}</div>
                            </div>
                            <div class="rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wide {{ $trustLabelClasses }}">
                                {{ $trustLabel }}
                            </div>
                        </div>
                        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            @forelse (($trustCenter['cards'] ?? []) as $card)
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['title'] ?? 'Trust item' }}</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ $card['value'] ?? '-' }}</div>
                                    <div class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $card['status'] ?? '' }}</div>
                                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $card['note'] ?? '' }}</div>
                                </div>
                            @empty
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">Trust center is not ready yet.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div id="helos-business-picture" class="grid gap-4 scroll-mt-24">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        @php
                            $healthSectionTrust = $trustStatus['section_statuses']['health'] ?? 'Estimated';
                            $healthSectionClass = match ($healthSectionTrust) {
                                'Verified' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
                                'Pending Validation' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200',
                                default => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
                            };
                        @endphp
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Business picture</div>
                            <div class="rounded-full border px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $healthSectionClass }}">{{ $healthSectionTrust }}</div>
                        </div>
                        <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">{{ $healthStory['headline'] ?? 'Business picture not ready yet.' }}</div>
                        <div class="mt-2 grid gap-2 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-2 xl:grid-cols-3">
                            @forelse (($healthStory['summary'] ?? []) as $line)
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">{{ $line }}</div>
                            @empty
                            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">Refresh the report to build the current month picture.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @forelse (($healthStory['cards'] ?? []) as $card)
                            @php
                                $tone = $card['tone'] ?? 'info';
                                $trust = $card['trust_status'] ?? null;
                                $toneClasses = match ($tone) {
                                    'success' => 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30',
                                    'warning' => 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
                                    'danger' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30',
                                    default => 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/30',
                                };
                                $valueClasses = match ($tone) {
                                    'success' => 'text-emerald-600',
                                    'warning' => 'text-amber-600',
                                    'danger' => 'text-red-600',
                                    default => 'text-sky-600',
                                };
                                $trustClasses = match ($trust) {
                                    'Verified' => 'border-emerald-200 bg-emerald-100 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200',
                                    'Pending Validation' => 'border-red-200 bg-red-100 text-red-700 dark:border-red-900 dark:bg-red-900/30 dark:text-red-200',
                                    default => 'border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200',
                                };
                            @endphp
                            <div class="rounded-lg border p-4 dark:border-gray-800 {{ $toneClasses }}">
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['title'] }}</div>
                                @if ($trust)
                                    <div class="mt-1 inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $trustClasses }}">
                                        {{ $trust }}
                                    </div>
                                @endif
                                <div class="mt-2 text-2xl font-bold {{ $valueClasses }}">{{ $card['value'] }}</div>
                                <div class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $card['status'] }}</div>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $card['note'] }}</div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No health story available yet.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div id="helos-money-safety" class="grid gap-4 scroll-mt-24">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        @php
                            $bucketSectionTrust = $trustStatus['section_statuses']['bucket'] ?? 'Estimated';
                            $bucketSectionClass = match ($bucketSectionTrust) {
                                'Verified' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
                                'Pending Validation' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200',
                                default => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
                            };
                        @endphp
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Money safe to use</div>
                            <div class="rounded-full border px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $bucketSectionClass }}">{{ $bucketSectionTrust }}</div>
                        </div>
                        <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">{{ $bucketStory['headline'] ?? 'Money safe to use not ready yet.' }}</div>
                        <div class="mt-2 grid gap-2 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-3">
                            @forelse (($bucketStory['summary'] ?? []) as $line)
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">{{ $line }}</div>
                            @empty
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">Refresh the report to read the money safety view.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @forelse (($bucketStory['cards'] ?? []) as $card)
                            @php
                                $tone = $card['tone'] ?? 'info';
                                $trust = $card['trust_status'] ?? null;
                                $toneClasses = match ($tone) {
                                    'success' => 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30',
                                    'warning' => 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
                                    'danger' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30',
                                    default => 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/30',
                                };
                                $valueClasses = match ($tone) {
                                    'success' => 'text-emerald-600',
                                    'warning' => 'text-amber-600',
                                    'danger' => 'text-red-600',
                                    default => 'text-sky-600',
                                };
                                $trustClasses = match ($trust) {
                                    'Verified' => 'border-emerald-200 bg-emerald-100 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200',
                                    'Pending Validation' => 'border-red-200 bg-red-100 text-red-700 dark:border-red-900 dark:bg-red-900/30 dark:text-red-200',
                                    default => 'border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200',
                                };
                            @endphp
                            <div class="rounded-lg border p-4 dark:border-gray-800 {{ $toneClasses }}">
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['title'] }}</div>
                                @if ($trust)
                                    <div class="mt-1 inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $trustClasses }}">
                                        {{ $trust }}
                                    </div>
                                @endif
                                <div class="mt-2 text-2xl font-bold {{ $valueClasses }}">{{ $card['value'] }}</div>
                                <div class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $card['status'] }}</div>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $card['note'] }}</div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No money safety view available yet.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div id="helos-break-even" class="grid gap-4 scroll-mt-24">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        @php
                            $breakEvenSectionTrust = $trustStatus['section_statuses']['break_even'] ?? 'Estimated';
                            $breakEvenSectionClass = match ($breakEvenSectionTrust) {
                                'Verified' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
                                'Pending Validation' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200',
                                default => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
                            };
                        @endphp
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Money needed to cover the month</div>
                            <div class="rounded-full border px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $breakEvenSectionClass }}">{{ $breakEvenSectionTrust }}</div>
                        </div>
                        <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">{{ $breakEvenStory['headline'] ?? 'Break-even is not ready yet.' }}</div>
                        <div class="mt-2 grid gap-2 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-2 xl:grid-cols-3">
                            @forelse (($breakEvenStory['summary'] ?? []) as $line)
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">{{ $line }}</div>
                            @empty
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">Refresh the report to read the break-even view.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @forelse (($breakEvenStory['cards'] ?? []) as $card)
                            @php
                                $tone = $card['tone'] ?? 'info';
                                $trust = $card['trust_status'] ?? null;
                                $toneClasses = match ($tone) {
                                    'success' => 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30',
                                    'warning' => 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
                                    'danger' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30',
                                    default => 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/30',
                                };
                                $valueClasses = match ($tone) {
                                    'success' => 'text-emerald-600',
                                    'warning' => 'text-amber-600',
                                    'danger' => 'text-red-600',
                                    default => 'text-sky-600',
                                };
                                $trustClasses = match ($trust) {
                                    'Verified' => 'border-emerald-200 bg-emerald-100 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200',
                                    'Pending Validation' => 'border-red-200 bg-red-100 text-red-700 dark:border-red-900 dark:bg-red-900/30 dark:text-red-200',
                                    default => 'border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200',
                                };
                            @endphp
                            <div class="rounded-lg border p-4 dark:border-gray-800 {{ $toneClasses }}">
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['title'] }}</div>
                                @if ($trust)
                                    <div class="mt-1 inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $trustClasses }}">
                                        {{ $trust }}
                                    </div>
                                @endif
                                <div class="mt-2 text-2xl font-bold {{ $valueClasses }}">{{ $card['value'] }}</div>
                                <div class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $card['status'] }}</div>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $card['note'] }}</div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No break-even view available yet.
                            </div>
                        @endforelse
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">What is making it harder</div>
                            <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $breakEvenStory['pressure']['headline'] ?? 'Break-even pressure is light for now.' }}</div>
                            <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                                @forelse (($breakEvenStory['pressure']['top_obstacles'] ?? []) as $item)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                        <div class="font-medium text-gray-900 dark:text-white">{{ $item['label'] ?? 'Obstacle' }}</div>
                                        <div class="text-xs text-gray-500">LKR {{ number_format((float) ($item['amount'] ?? 0), 2) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $item['note'] ?? '' }}</div>
                                    </div>
                                @empty
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">No major break-even pressure is showing yet.</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">What helps most</div>
                            <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">These are the products and sales streams carrying the month forward.</div>
                            <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                                @forelse (($breakEvenStory['pressure']['what_helps_most'] ?? []) as $item)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                        <div class="font-medium text-gray-900 dark:text-white">{{ $item['label'] ?? 'Helping driver' }}</div>
                                        <div class="text-xs text-gray-500">LKR {{ number_format((float) ($item['amount'] ?? 0), 2) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $item['note'] ?? '' }}</div>
                                    </div>
                                @empty
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">No strong helping driver has been recorded yet.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div id="helos-goal" class="grid gap-4 scroll-mt-24">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        @php
                            $goalSectionTrust = $trustStatus['section_statuses']['goal'] ?? 'Estimated';
                            $goalSectionClass = match ($goalSectionTrust) {
                                'Verified' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
                                'Pending Validation' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200',
                                default => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
                            };
                        @endphp
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Your Goal</div>
                            <div class="rounded-full border px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $goalSectionClass }}">{{ $goalSectionTrust }}</div>
                        </div>
                        <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">{{ $goalStory['headline'] ?? 'Set a monthly goal to start tracking progress.' }}</div>
                        <div class="mt-2 grid gap-2 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-2 xl:grid-cols-3">
                            @forelse (($goalStory['summary'] ?? []) as $line)
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">{{ $line }}</div>
                            @empty
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">Choose a goal type and amount above, then refresh the report.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @forelse (($goalStory['cards'] ?? []) as $card)
                            @php
                                $tone = $card['tone'] ?? 'info';
                                $trust = $card['trust_status'] ?? null;
                                $toneClasses = match ($tone) {
                                    'success' => 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30',
                                    'warning' => 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
                                    'danger' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30',
                                    default => 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/30',
                                };
                                $valueClasses = match ($tone) {
                                    'success' => 'text-emerald-600',
                                    'warning' => 'text-amber-600',
                                    'danger' => 'text-red-600',
                                    default => 'text-sky-600',
                                };
                                $trustClasses = match ($trust) {
                                    'Verified' => 'border-emerald-200 bg-emerald-100 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200',
                                    'Pending Validation' => 'border-red-200 bg-red-100 text-red-700 dark:border-red-900 dark:bg-red-900/30 dark:text-red-200',
                                    default => 'border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200',
                                };
                            @endphp
                            <div class="rounded-lg border p-4 dark:border-gray-800 {{ $toneClasses }}">
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['title'] }}</div>
                                @if ($trust)
                                    <div class="mt-1 inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $trustClasses }}">
                                        {{ $trust }}
                                    </div>
                                @endif
                                <div class="mt-2 text-2xl font-bold {{ $valueClasses }}">{{ $card['value'] }}</div>
                                <div class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $card['status'] }}</div>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $card['note'] }}</div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No goal has been set yet.
                            </div>
                        @endforelse
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">What Is Slowing You Down</div>
                            <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $goalStory['pressure']['headline'] ?? 'Goal pressure is light for now.' }}</div>
                            <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                                @forelse (($goalStory['pressure']['top_obstacles'] ?? []) as $item)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                        <div class="font-medium text-gray-900 dark:text-white">{{ $item['label'] ?? 'Obstacle' }}</div>
                                        <div class="text-xs text-gray-500">LKR {{ number_format((float) ($item['amount'] ?? 0), 2) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $item['note'] ?? '' }}</div>
                                    </div>
                                @empty
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">Set a goal to see what is slowing it down.</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Fastest Path Forward</div>
                            <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">Use the strongest helping driver and reduce the biggest obstacle first.</div>
                            <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $goalStory['pressure']['fastest_path']['value'] ?? 'Set a goal first' }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $goalStory['pressure']['fastest_path']['note'] ?? 'No goal is being tracked yet.' }}</div>
                                </div>
                                @forelse (($goalStory['pressure']['what_helps_most'] ?? []) as $item)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                        <div class="font-medium text-gray-900 dark:text-white">{{ $item['label'] ?? 'Helping driver' }}</div>
                                        <div class="text-xs text-gray-500">LKR {{ number_format((float) ($item['amount'] ?? 0), 2) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $item['note'] ?? '' }}</div>
                                    </div>
                                @empty
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">No helping driver is visible yet.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div id="helos-business-flow" class="grid gap-4 scroll-mt-24">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Operational completion summary</div>
                        <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">What the team finished today and what is still open</div>
                        <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">This is a quick work signal for the owner. It stays small so the morning dashboard still feels like the same dashboard.</div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @forelse (($operationalSummary ?? []) as $label => $value)
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ is_string($value) ? $value : (string) $value }}</div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No operational completion summary is ready yet.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="grid gap-4">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Business flow</div>
                        <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">{{ $lifecycleStory['headline'] ?? 'Business flow not ready yet.' }}</div>
                        <div class="mt-2 grid gap-2 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-2 xl:grid-cols-3">
                            @forelse (($lifecycleStory['summary'] ?? []) as $line)
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">{{ $line }}</div>
                            @empty
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">Refresh the report to build the business flow.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        @forelse (($lifecycleStory['cards'] ?? []) as $card)
                            @php
                                $tone = $card['tone'] ?? 'info';
                                $toneClasses = match ($tone) {
                                    'success' => 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30',
                                    'warning' => 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
                                    'danger' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30',
                                    default => 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/30',
                                };
                                $valueClasses = match ($tone) {
                                    'success' => 'text-emerald-600',
                                    'warning' => 'text-amber-600',
                                    'danger' => 'text-red-600',
                                    default => 'text-sky-600',
                                };
                            @endphp
                            <div class="rounded-lg border p-4 dark:border-gray-800 {{ $toneClasses }}">
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['title'] }}</div>
                                <div class="mt-2 text-2xl font-bold {{ $valueClasses }}">{{ $card['value'] }}</div>
                                <div class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $card['status'] }}</div>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $card['note'] }}</div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No lifecycle story available yet.
                            </div>
                        @endforelse
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Money movement</div>
                            <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                                {{ $lifecycleStory['money']['headline'] ?? 'Money movement is still quiet.' }}
                            </div>
                            <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-2">
                                <div>Expense paid: {{ (int) ($lifecycleStory['money']['expense_paid_count'] ?? 0) }}</div>
                                <div>Expense partial: {{ (int) ($lifecycleStory['money']['expense_partial_count'] ?? 0) }}</div>
                                <div>Cheque pending: {{ (int) ($lifecycleStory['money']['expense_cheque_pending_count'] ?? 0) }}</div>
                                <div>Credit due: {{ (int) ($lifecycleStory['money']['expense_credit_due_count'] ?? 0) }}</div>
                                <div>Bank reviewed: {{ (int) ($lifecycleStory['money']['bank_reviewed_count'] ?? 0) }}</div>
                                <div>Bank matched: {{ (int) ($lifecycleStory['money']['bank_matched_count'] ?? 0) }}</div>
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Stock-app connection</div>
                            <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                                {{ $lifecycleStory['integration']['headline'] ?? 'No active integration has been linked yet.' }}
                            </div>
                            <div class="mt-2 text-sm text-gray-500">
                                Status: {{ $lifecycleStory['integration']['status'] ?? 'missing' }}
                                | Last synced: {{ $lifecycleStory['integration']['last_synced_at'] ?? 'not synced yet' }}
                            </div>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div id="helos-revenue" class="grid gap-4 scroll-mt-24 lg:grid-cols-{{ $supportsTradeOrManufacturing && $supportsService ? '3' : (($supportsTradeOrManufacturing || $supportsService) ? '2' : '1') }}">
                    @if ($supportsTradeOrManufacturing)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">COD money coming in</div>
                            <div class="text-xs text-gray-500">COD sales truth is separate from bank settlement cash, so HELOS does not count the same money twice.</div>
                            <div class="mt-4 grid gap-3 md:grid-cols-5">
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Expected COD</div>
                                    <div class="mt-1 font-semibold text-amber-600">LKR {{ number_format((float) ($revenuePipeline['cod']['expected_revenue'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">{{ (int) ($revenuePipeline['cod']['pending_orders'] ?? 0) }} pending orders</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Delivered revenue</div>
                                    <div class="mt-1 font-semibold text-emerald-600">LKR {{ number_format((float) ($revenuePipeline['cod']['collected_revenue'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">Sales truth from {{ (int) ($revenuePipeline['cod']['delivered_orders'] ?? 0) }} delivered orders</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Bank COD cash</div>
                                    <div class="mt-1 font-semibold text-sky-600">LKR {{ number_format((float) ($revenuePipeline['cod']['cash_received'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">{{ (int) ($revenuePipeline['cod_settlement']['row_count'] ?? 0) }} settlement rows</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Settlement gap</div>
                                    <div class="mt-1 font-semibold {{ ((float) ($revenuePipeline['cod']['settlement_gap'] ?? 0)) > 0 ? 'text-amber-600' : 'text-emerald-600' }}">LKR {{ number_format((float) ($revenuePipeline['cod']['settlement_gap'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">Delivered revenue minus bank COD cash</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Returned</div>
                                    <div class="mt-1 font-semibold text-red-600">LKR {{ number_format((float) ($revenuePipeline['cod']['returned_revenue'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">{{ (int) ($revenuePipeline['cod']['returned_orders'] ?? 0) }} returned orders</div>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($supportsService)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">Service business truth</div>
                            <div class="text-xs text-gray-500">Read this as: who is active, how much this service business should bring this month, what was collected, and whether it is covering its own monthly load.</div>
                            <div class="mt-4 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Active clients</div>
                                    <div class="mt-1 font-semibold text-sky-600">{{ (int) ($revenuePipeline['service']['active_clients'] ?? 0) }}</div>
                                    <div class="text-xs text-gray-500">{{ (int) ($revenuePipeline['service']['paused_clients'] ?? 0) }} paused</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Should come this month</div>
                                    <div class="mt-1 font-semibold text-sky-600">LKR {{ number_format((float) ($revenuePipeline['service']['expected_monthly_revenue'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">{{ (int) ($revenuePipeline['service']['fixed_clients'] ?? 0) }} fixed monthly clients</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Billed this month</div>
                                    <div class="mt-1 font-semibold text-gray-900 dark:text-white">LKR {{ number_format((float) ($revenuePipeline['service']['billed_this_month'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">{{ (int) ($revenuePipeline['service']['variable_clients'] ?? 0) }} variable clients</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Collected</div>
                                    <div class="mt-1 font-semibold text-emerald-600">LKR {{ number_format((float) ($revenuePipeline['service']['collected_revenue'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">{{ (int) ($revenuePipeline['service']['delivered_orders'] ?? 0) }} fully paid rows</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Still to collect</div>
                                    <div class="mt-1 font-semibold text-amber-600">LKR {{ number_format((float) ($revenuePipeline['service']['expected_revenue'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">Overdue LKR {{ number_format((float) ($revenuePipeline['service']['overdue_amount'] ?? 0), 2) }}</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Monthly self-cover</div>
                                    @if ((float) ($revenuePipeline['service']['coverage_gap_collected'] ?? 0) > 0)
                                        <div class="mt-1 font-semibold text-red-600">Short by LKR {{ number_format((float) ($revenuePipeline['service']['coverage_gap_collected'] ?? 0), 2) }}</div>
                                        <div class="text-xs text-gray-500">Fixed monthly load LKR {{ number_format((float) ($revenuePipeline['service']['fixed_monthly_costs'] ?? 0), 2) }}</div>
                                    @else
                                        <div class="mt-1 font-semibold text-emerald-600">Covered</div>
                                        <div class="text-xs text-gray-500">Surplus LKR {{ number_format((float) ($revenuePipeline['service']['coverage_surplus_collected'] ?? 0), 2) }}</div>
                                    @endif
                                </div>
                            </div>
                            <div class="mt-3 grid gap-2 text-sm">
                                @forelse (($revenuePipeline['service']['records'] ?? []) as $record)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $record['client_name'] ?? 'Service client' }}</div>
                                        <div class="text-xs text-gray-500">
                                            {{ \App\Domains\Shared\Models\ServiceBillingRecord::billingTypeOptions()[$record['billing_type'] ?? ''] ?? 'Service fee' }}
                                            | Due LKR {{ number_format((float) ($record['amount_due'] ?? 0), 2) }}
                                            | Paid LKR {{ number_format((float) ($record['paid_amount'] ?? 0), 2) }}
                                            | Still LKR {{ number_format((float) ($record['remaining_amount'] ?? 0), 2) }}
                                            @if (! empty($record['due_on'])) | Due {{ $record['due_on'] }} @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-lg border border-dashed border-gray-300 p-3 text-sm text-gray-500 dark:border-gray-700">
                                        No service billing rows for this month yet.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @endif

                    @if ($supportsTradeOrManufacturing)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">Wholesale money coming in</div>
                            <div class="text-xs text-gray-500">Wholesale sales are visible here, but exact cheque or credit clearing still needs bank matching.</div>
                            <div class="mt-4 grid gap-3 md:grid-cols-3">
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Pending</div>
                                    <div class="mt-1 font-semibold text-amber-600">LKR {{ number_format((float) ($revenuePipeline['wholesale']['expected_revenue'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">{{ (int) ($revenuePipeline['wholesale']['pending_orders'] ?? 0) }} pending orders</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Delivered sales</div>
                                    <div class="mt-1 font-semibold text-sky-600">LKR {{ number_format((float) ($revenuePipeline['wholesale']['collected_revenue'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">{{ (int) ($revenuePipeline['wholesale']['delivered_orders'] ?? 0) }} delivered orders</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Returned</div>
                                    <div class="mt-1 font-semibold text-red-600">LKR {{ number_format((float) ($revenuePipeline['wholesale']['returned_revenue'] ?? 0), 2) }}</div>
                                    <div class="text-xs text-gray-500">{{ (int) ($revenuePipeline['wholesale']['returned_orders'] ?? 0) }} returned orders</div>
                                </div>
                            </div>
                            <div class="mt-3 rounded-lg border border-dashed border-gray-300 p-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                                {{ $revenuePipeline['wholesale']['note'] ?? 'Wholesale money will be matched from bank and collection records.' }}
                            </div>
                        </div>
                    @endif
                </div>
                <div class="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Money coming in headline</div>
                    <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $revenuePipeline['headline'] ?? 'Money coming in not ready yet.' }}</div>
                    <div class="mt-3 text-sm text-gray-500">
                        Total expected: LKR {{ number_format((float) ($revenuePipeline['total_expected_revenue'] ?? 0), 2) }}
                        | Delivered/service revenue: LKR {{ number_format((float) ($revenuePipeline['total_collected_revenue'] ?? 0), 2) }}
                        | Cash confirmed: LKR {{ number_format((float) ($revenuePipeline['total_cash_confirmed'] ?? 0), 2) }}
                        | Total returned: LKR {{ number_format((float) ($revenuePipeline['total_returned_revenue'] ?? 0), 2) }}
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                @php
                    $decisionStory = $briefing['decision_story'] ?? $advisor['decision_story'] ?? [];
                @endphp

                <div id="helos-next-action" class="grid gap-4 scroll-mt-24 lg:grid-cols-[1.3fr_0.7fr]">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="text-xs uppercase tracking-wide text-gray-500">What to do next</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">
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
                                    {{ $decisionStory['why_it_matters'] ?? 'It changes the business picture for money left after running the business, money still waiting to settle, and running pressure together.' }}
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

                    <div class="grid gap-3">
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Money still waiting to settle</div>
                            <div class="mt-1 font-semibold text-amber-600">{{ $briefing['cash_position']['headline'] ?? 'Money still waiting to settle not ready yet.' }}</div>
                            <div class="mt-2 text-sm text-gray-500">
                                Bank movement: LKR {{ number_format((float) ($briefing['cash_position']['bank_net_movement'] ?? 0), 2) }}
                                | Money waiting to settle: LKR {{ number_format((float) ($briefing['cash_position']['cash_due'] ?? 0), 2) }}
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Money left after running the business</div>
                            <div class="mt-1 font-semibold {{ (float) ($briefing['profit_position']['profit'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                LKR {{ number_format((float) ($briefing['profit_position']['profit'] ?? 0), 2) }}
                            </div>
                            <div class="mt-2 text-sm text-gray-500">{{ $briefing['profit_position']['headline'] ?? 'Money left after running the business not ready yet.' }}</div>
                        </div>

                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Support signal</div>
                            <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                                {{ implode(' ', array_slice($decisionStory['supporting_signals'] ?? [], 0, 2)) ?: 'Supporting signals are still building from the current month.' }}
                            </div>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div id="helos-cash-movement" class="grid gap-4 scroll-mt-24 lg:grid-cols-2">
                    <div class="grid gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Money movement</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Bank movement and near-term commitments in one place.</p>
                        </div>
                        <div class="grid gap-3 md:grid-cols-3">
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Inflow</div>
                                <div class="mt-1 font-semibold text-emerald-600">LKR {{ number_format((float) ($cashIntelligence['bank_inflow'] ?? 0), 2) }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Outflow</div>
                                <div class="mt-1 font-semibold text-red-600">LKR {{ number_format((float) ($cashIntelligence['bank_outflow'] ?? 0), 2) }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Net movement</div>
                                <div class="mt-1 font-semibold {{ (float) ($cashIntelligence['net_movement'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                    LKR {{ number_format((float) ($cashIntelligence['net_movement'] ?? 0), 2) }}
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Headline</div>
                            <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $cashIntelligence['headline'] ?? 'Import bank statements to see the cash timeline.' }}</div>
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Upcoming commitments</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Expenses, people cost, and production pay that still need money.</p>
                        </div>
                        <div class="grid gap-3">
                            @forelse (($cashIntelligence['due_soon_obligations'] ?? []) as $obligation)
                                <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-800">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <div class="font-semibold text-gray-950 dark:text-white">{{ $obligation['title'] }}</div>
                                            <div class="text-xs uppercase tracking-wide text-gray-500">{{ $obligation['label'] }} | {{ $obligation['type'] }}</div>
                                        </div>
                                        <div class="text-sm text-gray-500">LKR {{ number_format((float) $obligation['amount'], 2) }}</div>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">Due on {{ $obligation['due_on'] ?? 'not set' }}</div>
                                </div>
                            @empty
                                <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                    No due-soon obligations found yet.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                @if ($supportsCapital || $supportsInventory)
                    <div class="mt-6 grid gap-4 lg:grid-cols-2">
                        @if ($supportsCapital)
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                                <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Money tied up</div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                        <div class="text-xs uppercase tracking-wide text-gray-500">Money tied up</div>
                                        <div class="mt-1 font-semibold text-violet-600">LKR {{ number_format((float) ($capitalIntelligence['capital_committed'] ?? 0), 2) }}</div>
                                    </div>
                                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                        <div class="text-xs uppercase tracking-wide text-gray-500">Value signal</div>
                                        <div class="mt-1 font-semibold {{ (float) ($capitalIntelligence['value_signal'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                            LKR {{ number_format((float) ($capitalIntelligence['value_signal'] ?? 0), 2) }}
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-800">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Headline</div>
                                    <div class="mt-1 text-gray-700 dark:text-gray-300">{{ $capitalIntelligence['headline'] ?? 'Money tied up signal not ready yet.' }}</div>
                                </div>
                                <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                                    <div>Money after commitments proxy: LKR {{ number_format((float) ($capitalIntelligence['cash_after_obligations_proxy'] ?? 0), 2) }}</div>
                                    <div>Material spend: LKR {{ number_format((float) ($capitalIntelligence['material_spend'] ?? 0), 2) }}</div>
                                    <div>Production commitment: LKR {{ number_format((float) ($capitalIntelligence['production_commitment'] ?? 0), 2) }}</div>
                                </div>
                            </div>
                        @endif

                        @if ($supportsInventory)
                            <div id="helos-stock" class="rounded-lg border border-gray-200 p-4 scroll-mt-24 dark:border-gray-800">
                                <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Stock holding cash</div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                        <div class="text-xs uppercase tracking-wide text-gray-500">Recipe coverage</div>
                                        <div class="mt-1 font-semibold text-sky-600">{{ (int) ($inventoryIntelligence['recipe_coverage'] ?? 0) }}%</div>
                                    </div>
                                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                        <div class="text-xs uppercase tracking-wide text-gray-500">Waste ratio</div>
                                        <div class="mt-1 font-semibold text-red-600">{{ number_format((float) ($inventoryIntelligence['waste_ratio'] ?? 0), 2) }}%</div>
                                    </div>
                                </div>
                                <div class="mt-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-800">
                                    <div class="text-xs uppercase tracking-wide text-gray-500">Headline</div>
                                    <div class="mt-1 text-gray-700 dark:text-gray-300">{{ $inventoryIntelligence['headline'] ?? 'Inventory signal not ready yet.' }}</div>
                                </div>
                                <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                                    <div>Material purchases: LKR {{ number_format((float) ($inventoryIntelligence['material_purchase_total'] ?? 0), 2) }}</div>
                                    <div>Material consumption: LKR {{ number_format((float) ($inventoryIntelligence['material_consumption_total'] ?? 0), 2) }}</div>
                                    <div>Flow gap: LKR {{ number_format((float) ($inventoryIntelligence['flow_gap'] ?? 0), 2) }}</div>
                                    <div>Finished goods dispatched: {{ (int) ($inventoryIntelligence['finished_goods_dispatches'] ?? 0) }}</div>
                                    <div>Returned and restocked: {{ (int) ($inventoryIntelligence['finished_goods_return_restocked'] ?? 0) }}</div>
                                    <div>Returned and damaged: {{ (int) ($inventoryIntelligence['finished_goods_return_damaged'] ?? 0) }}</div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="mt-6 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Money movement timeline</div>
                        <div class="grid gap-2">
                            @forelse (($cashIntelligence['timeline'] ?? []) as $day)
                                <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900">
                                    <div>
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $day['date'] }}</div>
                                        <div class="text-xs text-gray-500">{{ $day['count'] }} bank rows</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs text-gray-500">Net</div>
                                        <div class="{{ (float) ($day['net'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }} font-semibold">
                                            LKR {{ number_format((float) ($day['net'] ?? 0), 2) }}
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">No bank rows imported yet for this month.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Money actions</div>
                        @if (empty($cashIntelligence['actions'] ?? []))
                            <div class="text-sm text-gray-500">No immediate cash action needed from the current numbers.</div>
                        @else
                            <ul class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                                @foreach ($cashIntelligence['actions'] as $action)
                                    <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                        {{ $action }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div id="helos-weekly-reminders" class="grid gap-4 scroll-mt-24">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Weekly reminders</div>
                        <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">These are the items that usually need action this week.</div>
                        <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            Weekly COD settlements, salaries, cheque dates, supplier payments, and service collections show up here so you can handle them before they turn into a fire drill.
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Due soon</div>
                            <div class="grid gap-2">
                                @forelse (($cashIntelligence['due_soon_obligations'] ?? []) as $obligation)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $obligation['title'] ?? 'Upcoming obligation' }}</div>
                                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $obligation['label'] ?? 'Due soon' }} | {{ $obligation['type'] ?? 'Pending' }}</div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            LKR {{ number_format((float) ($obligation['amount'] ?? 0), 2) }}
                                            @if (! empty($obligation['due_on']))
                                                | Due on {{ $obligation['due_on'] }}
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                        No due-soon reminders right now.
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Overdue</div>
                            <div class="grid gap-2">
                                @forelse (($cashIntelligence['overdue_obligations'] ?? []) as $obligation)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $obligation['title'] ?? 'Overdue obligation' }}</div>
                                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $obligation['label'] ?? 'Overdue' }} | {{ $obligation['type'] ?? 'Pending' }}</div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            LKR {{ number_format((float) ($obligation['amount'] ?? 0), 2) }}
                                            @if (! empty($obligation['due_on']))
                                                | Due on {{ $obligation['due_on'] }}
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                        No overdue reminders right now.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    @if (! empty($cashIntelligence['incoming_due_soon'] ?? []) || ! empty($cashIntelligence['incoming_overdue'] ?? []))
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
                                <div class="mb-3 text-sm font-semibold text-emerald-900 dark:text-emerald-100">Service money due soon</div>
                                <div class="grid gap-2">
                                    @forelse (($cashIntelligence['incoming_due_soon'] ?? []) as $item)
                                        <div class="rounded-lg bg-white px-3 py-2 text-sm dark:bg-gray-950">
                                            <div class="font-medium text-gray-950 dark:text-white">{{ $item['title'] ?? 'Service collection' }}</div>
                                            <div class="text-xs text-gray-500">LKR {{ number_format((float) ($item['amount'] ?? 0), 2) }} @if (! empty($item['due_on'])) | Due on {{ $item['due_on'] }} @endif</div>
                                        </div>
                                    @empty
                                        <div class="rounded-lg border border-dashed border-emerald-300 p-4 text-sm text-emerald-700 dark:border-emerald-800 dark:text-emerald-200">No service collections due soon.</div>
                                    @endforelse
                                </div>
                            </div>
                            <div class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/30">
                                <div class="mb-3 text-sm font-semibold text-red-900 dark:text-red-100">Service money overdue</div>
                                <div class="grid gap-2">
                                    @forelse (($cashIntelligence['incoming_overdue'] ?? []) as $item)
                                        <div class="rounded-lg bg-white px-3 py-2 text-sm dark:bg-gray-950">
                                            <div class="font-medium text-gray-950 dark:text-white">{{ $item['title'] ?? 'Service collection' }}</div>
                                            <div class="text-xs text-gray-500">LKR {{ number_format((float) ($item['amount'] ?? 0), 2) }} @if (! empty($item['due_on'])) | Due on {{ $item['due_on'] }} @endif</div>
                                        </div>
                                    @empty
                                        <div class="rounded-lg border border-dashed border-red-300 p-4 text-sm text-red-700 dark:border-red-800 dark:text-red-200">No overdue service collections.</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section>
                <div id="helos-treasury" class="grid gap-4 scroll-mt-24">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        @php
                            $treasurySectionTrust = $trustStatus['section_statuses']['treasury'] ?? 'Estimated';
                            $treasurySectionClass = match ($treasurySectionTrust) {
                                'Verified' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
                                'Pending Validation' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200',
                                default => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
                            };
                        @endphp
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Treasury picture</div>
                            <div class="rounded-full border px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $treasurySectionClass }}">{{ $treasurySectionTrust }}</div>
                        </div>
                        <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">{{ $treasuryStory['headline'] ?? 'Treasury picture not ready yet.' }}</div>
                        <div class="mt-2 grid gap-2 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-2 xl:grid-cols-3">
                            @forelse (($treasuryStory['summary'] ?? []) as $line)
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">{{ $line }}</div>
                            @empty
                                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">Refresh the report to read the treasury view.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-3">
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Cash by account</div>
                            <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                                @forelse (($treasuryStory['accounts'] ?? []) as $account)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                        <div class="font-medium text-gray-900 dark:text-white">{{ $account['container'] ?? 'Shared / Unallocated' }}</div>
                                        <div class="text-xs text-gray-500">Rows: {{ (int) ($account['count'] ?? 0) }} | Review: {{ (int) ($account['review_count'] ?? 0) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">In: LKR {{ number_format((float) ($account['inflow'] ?? 0), 2) }} | Out: LKR {{ number_format((float) ($account['outflow'] ?? 0), 2) }}</div>
                                        <div class="mt-1 text-sm font-semibold {{ (float) ($account['net'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                            Net: LKR {{ number_format((float) ($account['net'] ?? 0), 2) }}
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">No cash account rows recorded yet.</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Business allocation</div>
                            <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                                @forelse (($treasuryStory['businesses'] ?? []) as $businessRow)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                        <div class="font-medium text-gray-900 dark:text-white">{{ $businessRow['business'] ?? 'Business' }}</div>
                                        <div class="text-xs text-gray-500">Rows: {{ (int) ($businessRow['count'] ?? 0) }}</div>
                                        <div class="mt-1 text-xs text-gray-500">In: LKR {{ number_format((float) ($businessRow['inflow'] ?? 0), 2) }} | Out: LKR {{ number_format((float) ($businessRow['outflow'] ?? 0), 2) }}</div>
                                        <div class="mt-1 text-sm font-semibold {{ (float) ($businessRow['net'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                            Net: LKR {{ number_format((float) ($businessRow['net'] ?? 0), 2) }}
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">No business allocation rows recorded yet.</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Shared / unallocated</div>
                            <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                                @forelse (($treasuryStory['shared_rows'] ?? []) as $row)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                        <div class="font-medium text-gray-900 dark:text-white">{{ $row['title'] ?? 'Bank row' }}</div>
                                        <div class="text-xs text-gray-500">{{ $row['container'] ?? 'Shared / Unallocated' }} | {{ $row['type'] ?? 'Needs review' }}</div>
                                        <div class="mt-1 text-xs text-gray-500">LKR {{ number_format((float) ($row['amount'] ?? 0), 2) }} | {{ $row['status'] ?? 'review' }}</div>
                                    </div>
                                @empty
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">No shared or unallocated rows need attention right now.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            <div id="helos-profitability" class="grid gap-6 scroll-mt-24 lg:grid-cols-2">
                <x-filament::section>
                    <div class="grid gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">What happened</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Period-over-period change summaries for the current month.</p>
                        </div>
                        @forelse (($advisor['what_happened'] ?? []) as $item)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $item['metric'] }}</div>
                                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $item['direction'] }} | {{ $item['confidence'] }}</div>
                                    </div>
                                    <div class="text-sm font-semibold {{ (float) ($item['delta'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                        LKR {{ number_format((float) ($item['delta'] ?? 0), 2) }}
                                    </div>
                                </div>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $item['summary'] }}</div>
                                <div class="mt-2 grid gap-2 text-xs text-gray-500 md:grid-cols-2">
                                    <div>Current: LKR {{ number_format((float) ($item['current'] ?? 0), 2) }}</div>
                                    <div>Previous: LKR {{ number_format((float) ($item['previous'] ?? 0), 2) }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No change summary available yet.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="grid gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Why it happened</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Primary and secondary drivers with supporting evidence.</p>
                        </div>
                        @forelse (($advisor['why_it_happened'] ?? []) as $item)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $item['metric'] }}</div>
                                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $item['confidence'] }}</div>
                                    </div>
                                </div>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                    <strong>Primary:</strong> {{ $item['primary_driver'] }}
                                </div>
                                @if (! empty($item['secondary_drivers']))
                                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                        <strong>Secondary:</strong> {{ implode(' | ', $item['secondary_drivers']) }}
                                    </div>
                                @endif
                                @if (! empty($item['supporting_evidence']))
                                    <div class="mt-2 text-xs text-gray-500">
                                        {{ implode(' | ', $item['supporting_evidence']) }}
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No driver analysis available yet.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <x-filament::section>
                    <div class="grid gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Top expense categories</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Live review detail for the current month. The headline health numbers stay snapshot-backed.</p>
                        </div>
                        @forelse ($topExpenses as $expense)
                            <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                                <div class="font-medium text-gray-950 dark:text-white">{{ $expense->category }}</div>
                                <div class="text-sm text-gray-500">LKR {{ number_format((float) $expense->total_amount, 2) }}</div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No expense categories found yet.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="grid gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Snapshot trend</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Saved monthly snapshots. Refresh the current month to update the latest view.</p>
                        </div>
                        @forelse ($trend as $month)
                            <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                                <div>
                                    <div class="font-medium text-gray-950 dark:text-white">{{ optional($month->period_end)->format('M Y') }}</div>
                                    <div class="text-xs text-gray-500">Revenue LKR {{ number_format((float) $month->revenue_total, 2) }}</div>
                                </div>
                                <div class="text-sm font-semibold {{ (float) $month->estimated_profit >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                    LKR {{ number_format((float) $month->estimated_profit, 2) }}
                                </div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No snapshots saved yet. Use Refresh report to create the current month snapshot.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>

            <x-filament::section>
                <div id="helos-returns" class="grid gap-3 scroll-mt-24">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Returns hurting profits</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Return, courier and SKU pressure in one place.</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Return impact</div>
                        <div class="mt-1 font-semibold text-red-600">LKR {{ number_format((float) ($impact['returns'] ?? 0), 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Courier impact</div>
                        <div class="mt-1 font-semibold text-amber-600">LKR {{ number_format((float) ($impact['courier'] ?? 0), 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Product pressure</div>
                        <div class="mt-1 font-semibold text-violet-600">{{ $impact['sku'] ?? 'No product pressure found yet' }}</div>
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>

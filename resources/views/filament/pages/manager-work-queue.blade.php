<x-filament-panels::page>
    <div class="grid gap-6">
        <x-filament::section>
            <div class="grid gap-4 lg:grid-cols-[1.3fr_0.7fr] lg:items-start">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Work queue</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">
                        {{ $workQueue['headline'] ?? 'Open tasks are ready.' }}
                    </div>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        This view is for execution. It shows what is open, what is overdue, and who needs to move first.
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Quick summary</div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Open tasks</div>
                            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ (int) ($workQueue['open_count'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Overdue</div>
                            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ (int) ($workQueue['blocked_count'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Completed today</div>
                            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ (int) ($workQueue['completed_today_count'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Completion rate</div>
                            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $workQueue['summary']['Completion rate'] ?? '0%' }}</div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <x-filament::button wire:click="$refresh" icon="heroicon-o-arrow-path" color="gray">
                            Refresh queue
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </x-filament::section>

        @if ($showAdminShortcuts ?? false)
            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Client and employee shortcuts</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Owner-only links for client setup, staff, and operational review.</p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach (($adminShortcuts ?? []) as $shortcut)
                            <a href="{{ $shortcut['url'] }}" class="rounded-lg border border-gray-200 p-4 transition hover:border-emerald-300 hover:bg-emerald-50/50 dark:border-gray-800 dark:hover:border-emerald-800 dark:hover:bg-emerald-950/20">
                                <div class="flex items-start gap-3">
                                    <x-filament::icon :icon="$shortcut['icon']" class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                                    <div class="min-w-0">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $shortcut['label'] }}</div>
                                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $shortcut['description'] }}</div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            </x-filament::section>
        @endif

        @php
            $taskTone = function (string $priority): string {
                return match ($priority) {
                    'high' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30',
                    'medium' => 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
                    default => 'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900/30',
                };
            };

            $taskValue = function (string $priority): string {
                return match ($priority) {
                    'high' => 'text-red-600',
                    'medium' => 'text-amber-600',
                    default => 'text-gray-600',
                };
            };

            $renderTask = function (array $task) use ($taskTone, $taskValue): void {
                $tone = $taskTone($task['priority'] ?? 'medium');
                $value = $taskValue($task['priority'] ?? 'medium');
                $related = $task['related_record'] ?? null;
                $hasLink = is_array($related) && ! empty($related['url']);
                ?>
                <div class="rounded-lg border p-4 dark:border-gray-800 {{ $tone }}">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-500">{{ $task['status_label'] ?? 'Waiting' }}</div>
                            <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">{{ $task['title'] ?? 'Work item' }}</div>
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $task['why_it_matters'] ?? '' }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Priority</div>
                            <div class="mt-1 text-sm font-semibold {{ $value }}">{{ ucfirst((string) ($task['priority'] ?? 'medium')) }}</div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-2">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-500">What to do</div>
                            <div class="mt-1">{{ $task['recommended_action'] ?? 'Open the item and finish the next step.' }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-500">Who should take it</div>
                            <div class="mt-1">{{ $task['assigned_user'] ?? 'Unassigned' }}</div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
                        @if ($hasLink)
                            <x-filament::button tag="a" href="{{ $related['url'] }}" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square">
                                Open record
                            </x-filament::button>
                        @endif
                        @if (is_array($related))
                            <div class="text-gray-500 dark:text-gray-400">
                                {{ $related['label'] ?? 'Related record' }}
                            </div>
                        @endif
                    </div>
                </div>
                <?php
            };
        @endphp

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Blocked Work</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">These are the tasks that are already overdue and need attention first.</p>
                    </div>
                    <div class="grid gap-4">
                        @forelse (collect($workQueue['sections']['due_today'] ?? [])->filter(fn (array $task): bool => ($task['priority'] ?? 'medium') === 'high' && filled($task['due_on']) && \Illuminate\Support\Carbon::parse($task['due_on'])->lt(today()))->values() as $task)
                            {!! $renderTask($task) !!}
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No blocked work right now.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Team Workload</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">See which team is carrying the most open work.</p>
                    </div>
                    <div class="grid gap-3">
                        @forelse (($workQueue['team_workload'] ?? []) as $team)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="font-semibold text-gray-950 dark:text-white">{{ $team['team'] }}</div>
                                    <div class="text-sm text-gray-500">{{ (int) $team['count'] }} open</div>
                                </div>
                                <div class="mt-1 text-xs uppercase tracking-wide text-gray-500">{{ (int) ($team['high_priority'] ?? 0) }} high priority</div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No team workload found yet.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Open Tasks</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">The items the team still needs to finish.</p>
                    </div>
                    <div class="grid gap-4">
                        @forelse (($workQueue['sections']['high_priority'] ?? []) as $task)
                            {!! $renderTask($task) !!}
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No high-priority open tasks.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Waiting For Review</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Items here need someone to look at them and finish the next step.</p>
                    </div>
                    <div class="grid gap-4">
                        @forelse (($workQueue['sections']['waiting_review'] ?? []) as $task)
                            {!! $renderTask($task) !!}
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                Nothing is waiting for review.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section>
            <div class="grid gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Completed Today</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">What the team already finished today.</p>
                </div>
                <div class="grid gap-4">
                    @forelse (($workQueue['sections']['completed_today'] ?? []) as $task)
                        {!! $renderTask($task) !!}
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                            No work has been completed today yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

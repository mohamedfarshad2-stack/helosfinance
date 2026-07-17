<x-filament-panels::page>
    <div class="grid gap-6">
        <x-filament::section>
            <div class="grid gap-4 lg:grid-cols-[1.3fr_0.7fr] lg:items-start">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Today's work</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">
                        {{ $workQueue['headline'] ?? 'Today\'s work is ready.' }}
                    </div>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        This screen is for action, not reports. Start with the first task, open the record, do the work, then come back here for the next item.
                    </div>
                    <div class="mt-3 grid gap-2 text-sm text-gray-600 dark:text-gray-300 sm:grid-cols-3">
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-900">1. Open the task</div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-900">2. Finish the next real step</div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-900">3. Come back here for what is next</div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Quick summary</div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach (($workQueue['summary'] ?? []) as $label => $value)
                            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                                <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-filament::section>

        @php
            $taskTone = function (string $priority): string {
                return match ($priority) {
                    'critical' => 'border-red-300 bg-red-100 dark:border-red-900 dark:bg-red-950/40',
                    'high' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30',
                    'medium' => 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
                    'normal' => 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
                    default => 'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900/30',
                };
            };

            $taskValue = function (string $priority): string {
                return match ($priority) {
                    'critical' => 'text-red-700',
                    'high' => 'text-red-600',
                    'medium' => 'text-amber-600',
                    'normal' => 'text-amber-600',
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
                        @if (($task['state'] ?? 'open') === 'open')
                            <x-filament::button wire:click="startMission({{ (int) $task['id'] }})" color="gray" size="sm" icon="heroicon-o-play">
                                Start
                            </x-filament::button>
                        @endif
                        @if (($task['state'] ?? 'open') !== 'completed')
                            <x-filament::button wire:click="completeMission({{ (int) $task['id'] }})" color="success" size="sm" icon="heroicon-o-check-circle">
                                Complete
                            </x-filament::button>
                            <x-filament::button wire:click="blockMission({{ (int) $task['id'] }})" color="warning" size="sm" icon="heroicon-o-exclamation-triangle">
                                Blocked
                            </x-filament::button>
                            <x-filament::button wire:click="escalateMission({{ (int) $task['id'] }})" color="danger" size="sm" icon="heroicon-o-arrow-up-circle">
                                Escalate
                            </x-filament::button>
                        @endif
                        @if ($hasLink)
                            <x-filament::button tag="a" href="{{ $related['url'] }}" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square">
                                Open record
                            </x-filament::button>
                        @endif
                        @if (filled($task['impact_type'] ?? null))
                            <div class="text-gray-500 dark:text-gray-400">
                                Impact: {{ str_replace('_', ' ', $task['impact_type']) }}
                                @if (filled($task['estimated_impact'] ?? null))
                                    / LKR {{ number_format((float) $task['estimated_impact'], 2) }}
                                @endif
                            </div>
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

        <div class="grid gap-6 xl:grid-cols-[0.75fr_1.25fr]">
            <x-filament::section>
                <div class="grid gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">My Responsibilities</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">HELOS only shows work connected to these areas.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @forelse (($workQueue['my_responsibilities'] ?? []) as $responsibility)
                            <span class="rounded-full border border-gray-200 bg-white px-3 py-1 text-sm font-medium text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                                {{ $responsibility }}
                            </span>
                        @empty
                            <span class="text-sm text-gray-500 dark:text-gray-400">No responsibility areas assigned yet.</span>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Mission Areas</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Start with the area that has the most urgent open work.</p>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        @forelse (($workQueue['responsibility_groups'] ?? []) as $group)
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                                <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $group['label'] }}</div>
                                <div class="mt-2 flex items-center gap-4 text-sm text-gray-600 dark:text-gray-300">
                                    <span>{{ $group['count'] }} open</span>
                                    <span>{{ $group['high_priority'] }} high priority</span>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No open mission areas right now.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section>
            <div class="grid gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Tasks Due Today</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Finish these first so nothing important gets delayed.</p>
                </div>

                <div class="grid gap-4">
                    @forelse (($workQueue['sections']['due_today'] ?? []) as $task)
                        {!! $renderTask($task) !!}
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                            No tasks are due today.
                        </div>
                    @endforelse
                </div>
            </div>
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">High Priority</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">These tasks should not wait.</p>
                    </div>
                    <div class="grid gap-4">
                        @forelse (($workQueue['sections']['high_priority'] ?? []) as $task)
                            {!! $renderTask($task) !!}
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No high-priority tasks right now.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Waiting For Review</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">These items need a person to look at them and finish the next step.</p>
                    </div>
                    <div class="grid gap-4">
                        @forelse (($workQueue['sections']['waiting_review'] ?? []) as $task)
                            {!! $renderTask($task) !!}
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No review work waiting right now.
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
                    <p class="text-sm text-gray-500 dark:text-gray-400">A quick look at what the team already finished today.</p>
                </div>
                <div class="grid gap-4">
                    @forelse (($workQueue['sections']['completed_today'] ?? []) as $task)
                        {!! $renderTask($task) !!}
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                            Nothing has been completed today yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

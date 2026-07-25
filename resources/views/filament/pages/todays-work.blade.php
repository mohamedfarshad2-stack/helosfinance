<x-filament-panels::page>
    <div class="grid gap-6">
        @if (! empty($workQueue['employee_guide']))
            @php
                $guide = $workQueue['employee_guide'];
            @endphp
            <x-filament::section>
                @if ($guide['is_supervisor'])
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Manager control</div>
                            <h1 class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $guide['name'] }}, focus the team on today&apos;s gap.</h1>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">You report to {{ $guide['reports_to'] }}. Review the target, act on the highest overdue employee, and escalate only decisions the owner must make.</p>
                        </div>
                        <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 dark:border-blue-900 dark:bg-blue-950/30">
                            <div class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Your direct team</div>
                            <div class="mt-1 text-sm font-medium text-blue-950 dark:text-blue-100">{{ implode(', ', $guide['direct_reports']) }}</div>
                        </div>
                    </div>
                @else
                <div class="grid gap-5 xl:grid-cols-[0.8fr_1.2fr]">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-emerald-600">My guided dashboard</div>
                        <h1 class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">Welcome, {{ $guide['name'] }}</h1>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            You report to <span class="font-semibold text-gray-950 dark:text-white">{{ $guide['reports_to'] }}</span>.
                            HELOAS ranks your work by urgency, business impact, and due date.
                        </p>

                        @if (! empty($guide['direct_reports']))
                            <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-900 dark:bg-blue-950/30">
                                <div class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">People you guide</div>
                                <div class="mt-1 text-sm text-blue-900 dark:text-blue-100">{{ implode(', ', $guide['direct_reports']) }}</div>
                            </div>
                        @endif
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        @forelse ($guide['responsibilities'] as $responsibility)
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                                <div class="font-semibold text-gray-950 dark:text-white">{{ $responsibility['label'] }}</div>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $responsibility['direction'] }}</div>
                                <div class="mt-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">
                                    <span class="font-semibold">Profit outcome:</span> {{ $responsibility['profit_outcome'] }}
                                </div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 sm:col-span-2">
                                Your owner or team leader still needs to assign your responsibility areas.
                            </div>
                        @endforelse
                    </div>
                </div>
                @endif
            </x-filament::section>

            @if (! $guide['is_supervisor'])
                <x-filament::section>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                        <div class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Operational work - do it in Stock App</div>
                        <div class="mt-2 text-sm text-blue-900 dark:text-blue-100">Customer calls, order confirmation, no-answer follow-up, dispatch, tracking, returns, and resends are completed in Stock App. HELOAS prioritizes and monitors them.</div>
                    </div>
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
                        <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Control work - do it in HELOAS</div>
                        <div class="mt-2 text-sm text-emerald-900 dark:text-emerald-100">Finance, expenses, SKU mapping, cost truth, material stock, production records, management review, blockers, and escalation are handled in HELOAS.</div>
                    </div>
                </div>
                </x-filament::section>
            @endif

            @if (! empty($managerProfit))
                <x-filament::section>
                    <div class="grid gap-5">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-red-600">Manager target recovery - {{ $managerProfit['period'] }}</div>
                            <h2 class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $managerProfit['headline'] }}</h2>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">As of {{ $managerProfit['as_of'] }}. HELOAS keeps owner financial totals private and converts them into operational targets for the manager.</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                            <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $managerProfit['goal_label'] }}</div>
                                <div class="mt-1 text-lg font-semibold text-red-700">
                                    @if (! $managerProfit['configured'])
                                        Owner must set target
                                    @elseif ($managerProfit['gap'] === null)
                                        Waiting for trusted source data
                                    @elseif ($managerProfit['gap_is_count'])
                                        {{ number_format((float) $managerProfit['gap'], 0) }} deliveries
                                    @else
                                        LKR {{ number_format((float) $managerProfit['gap'], 2) }}
                                    @endif
                                </div>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Additional deliveries required</div>
                                <div class="mt-1 text-lg font-semibold text-blue-700">{{ $managerProfit['required_deliveries'] === null ? 'Waiting for target' : number_format($managerProfit['required_deliveries']) }}</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Daily pace for remaining {{ $managerProfit['days_remaining'] }} day(s)</div>
                                <div class="mt-1 text-lg font-semibold text-blue-700">{{ $managerProfit['daily_deliveries'] === null ? 'Waiting for target' : number_format($managerProfit['daily_deliveries']).' deliveries/day' }}</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Leakage to recover or prevent</div>
                                <div class="mt-1 text-lg font-semibold text-red-700">LKR {{ number_format((float) $managerProfit['leakage'], 2) }}</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-900">
                                <div class="text-xs uppercase tracking-wide text-gray-500">Overdue team missions</div>
                                <div class="mt-1 text-lg font-semibold text-amber-700">{{ number_format($managerProfit['team_overdue']) }}</div>
                            </div>
                        </div>

                        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                            <div class="grid grid-cols-[minmax(9rem,0.7fr)_minmax(12rem,1.3fr)] gap-4 bg-gray-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-900">
                                <span>Person</span><span>What HELOAS expects</span>
                            </div>
                            @foreach ($managerProfit['employees'] as $employee)
                                <div class="grid grid-cols-[minmax(9rem,0.7fr)_minmax(12rem,1.3fr)] gap-4 border-t border-gray-200 px-4 py-3 text-sm dark:border-gray-800">
                                    <div>
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $employee['name'] }}{{ $employee['is_manager'] ? ' (Manager)' : '' }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ implode(' / ', $employee['responsibilities']) }}</div>
                                    </div>
                                    <div class="grid gap-1 text-gray-700 dark:text-gray-300">
                                        @forelse ($employee['signals'] as $signal)
                                            <div><span class="font-medium text-gray-950 dark:text-white">{{ $signal['label'] }}:</span> {{ $signal['display'] }}</div>
                                        @empty
                                            <div class="text-sm text-amber-700">Assign a profit-linked responsibility to this employee.</div>
                                        @endforelse
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                            {{ $managerProfit['warning'] }}
                        </div>
                    </div>
                </x-filament::section>
            @endif

            @if (! empty($workQueue['team_summary']))
                <x-filament::section>
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Who needs your attention</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Start with the employee carrying the most overdue work. Reassign, unblock, or set today&apos;s expected result.</p>
                    </div>
                    @if (! empty($workQueue['team_summary']['members']))
                        <div class="mt-4 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                            <div class="grid grid-cols-[1fr_auto_auto_auto] gap-3 bg-gray-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-900">
                                <span>Employee</span><span>Open</span><span>Overdue</span><span>Blocked</span>
                            </div>
                            @foreach ($workQueue['team_summary']['members'] as $member)
                                <div class="grid grid-cols-[1fr_auto_auto_auto] gap-3 border-t border-gray-200 px-4 py-3 text-sm dark:border-gray-800">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $member['name'] }}</span>
                                    <span>{{ $member['open'] }}</span>
                                    <span class="{{ $member['overdue'] > 0 ? 'font-semibold text-red-600' : '' }}">{{ $member['overdue'] }}</span>
                                    <span>{{ $member['blocked'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
            @endif

            <x-filament::section>
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $guide['is_supervisor'] ? 'Your three actions today' : 'How to run your day' }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $guide['is_supervisor'] ? 'Do these in order. Do not work through every card yourself.' : 'Use this sequence every working day.' }}</p>
                </div>
                @php
                    $routine = $guide['is_supervisor']
                        ? [
                            'Confirm the target gap or ask the owner to set the monthly target.',
                            'Start with the employee at the top of Who needs your attention and agree today\'s result.',
                            'Review blocked work at the end of the day; solve team blockers and escalate owner-only decisions.',
                        ]
                        : $guide['daily_routine'];
                @endphp
                <ol class="mt-4 grid gap-3 {{ $guide['is_supervisor'] ? 'lg:grid-cols-3' : 'md:grid-cols-2 xl:grid-cols-4' }}">
                    @foreach ($routine as $step)
                        <li class="rounded-lg border border-gray-200 p-3 text-sm text-gray-700 dark:border-gray-800 dark:text-gray-300">
                            <span class="mr-2 font-semibold text-emerald-600">{{ $loop->iteration }}.</span>{{ $step }}
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        @endif

        @if (! (($guide['is_supervisor'] ?? false) && ($workQueue['open_count'] ?? 0) === 0))
        <x-filament::section>
            <div class="grid gap-4 lg:grid-cols-[1.3fr_0.7fr] lg:items-start">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Today's work</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">
                        {{ $workQueue['headline'] ?? 'Today\'s work is ready.' }}
                    </div>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        This screen is for action, not reports. Start with today&apos;s priority, complete the source action here, then move to the next mission.
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

            $renderTask = function (array $task) use ($taskTone, $taskValue): string {
                $tone = $taskTone($task['priority'] ?? 'medium');
                $value = $taskValue($task['priority'] ?? 'medium');
                $related = $task['related_record'] ?? null;
                $hasLink = is_array($related) && ! empty($related['url']);
                $id = (int) ($task['id'] ?? 0);
                $state = (string) ($task['state'] ?? 'open');

                $status = e($task['status_label'] ?? 'Waiting');
                $title = e($task['title'] ?? 'Work item');
                $why = e($task['why_it_matters'] ?? '');
                $priority = e(ucfirst((string) ($task['priority'] ?? 'medium')));
                $action = e($task['recommended_action'] ?? 'Open the item and finish the next step.');
                $assigned = e($task['assigned_user'] ?? 'Unassigned');
                $workplace = (string) ($task['workplace'] ?? 'helos');
                $workplaceLabel = e($task['workplace_label'] ?? 'HELOAS');
                $workplaceInstruction = e($task['workplace_instruction'] ?? 'Complete the next step in HELOAS.');
                $workplaceUrl = filled($task['workplace_url'] ?? null) ? e($task['workplace_url']) : null;

                $startButton = $state === 'open'
                    ? '<button type="button" wire:click="startMission('.$id.')" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">Start</button>'
                    : '';

                if ($state !== 'completed' && $workplace === 'stock_app') {
                    $actionButtons = ($workplaceUrl ? '<a href="'.$workplaceUrl.'" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-500">Open Stock App</a>' : '')
                        .'<button type="button" wire:click="completeMission('.$id.')" class="inline-flex items-center justify-center rounded-md border border-blue-300 bg-white px-3 py-1.5 text-sm font-semibold text-blue-700 shadow-sm transition hover:bg-blue-50">Check Stock sync</button>
                        <button type="button" wire:click="blockMission('.$id.')" class="inline-flex items-center justify-center rounded-md border border-amber-300 bg-white px-3 py-1.5 text-sm font-semibold text-amber-700 shadow-sm transition hover:bg-amber-50">Blocked</button>
                        <button type="button" wire:click="escalateMission('.$id.')" class="inline-flex items-center justify-center rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-semibold text-red-700 shadow-sm transition hover:bg-red-50">Escalate</button>';
                } elseif ($state !== 'completed') {
                    $actionButtons = '<button type="button" wire:click="openMissionAction('.$id.')" class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-500">Do in HELOAS</button>
                        <button type="button" wire:click="completeMission('.$id.')" class="inline-flex items-center justify-center rounded-md border border-emerald-300 bg-white px-3 py-1.5 text-sm font-semibold text-emerald-700 shadow-sm transition hover:bg-emerald-50">Check complete</button>
                        <button type="button" wire:click="blockMission('.$id.')" class="inline-flex items-center justify-center rounded-md border border-amber-300 bg-white px-3 py-1.5 text-sm font-semibold text-amber-700 shadow-sm transition hover:bg-amber-50">Blocked</button>
                        <button type="button" wire:click="escalateMission('.$id.')" class="inline-flex items-center justify-center rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-semibold text-red-700 shadow-sm transition hover:bg-red-50">Escalate</button>';
                } else {
                    $actionButtons = '';
                }

                $recordLink = $hasLink && $workplace !== 'stock_app'
                    ? '<a href="'.e($related['url']).'" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">Open record</a>'
                    : '';

                $impact = '';
                if (filled($task['impact_type'] ?? null)) {
                    $impactAmount = filled($task['estimated_impact'] ?? null)
                        ? ' / LKR '.e(number_format((float) $task['estimated_impact'], 2))
                        : '';
                    $impact = '<div class="text-gray-500 dark:text-gray-400">Impact: '.e(str_replace('_', ' ', $task['impact_type'])).$impactAmount.'</div>';
                }

                $relatedLabel = is_array($related)
                    ? '<div class="text-gray-500 dark:text-gray-400">'.e($related['label'] ?? 'Related record').'</div>'
                    : '';

                return <<<HTML
                <div class="rounded-lg border p-4 dark:border-gray-800 {$tone}">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-500">{$status}</div>
                            <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">{$title}</div>
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{$why}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Priority</div>
                            <div class="mt-1 text-sm font-semibold {$value}">{$priority}</div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-2">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-500">What to do</div>
                            <div class="mt-1">{$action}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-500">Who should take it</div>
                            <div class="mt-1">{$assigned}</div>
                        </div>
                    </div>

                    <div class="mt-4 rounded-md border border-gray-200 bg-white/70 px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950/30">
                        <span class="font-semibold">Where to work: {$workplaceLabel}.</span> {$workplaceInstruction}
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
                        {$startButton}
                        {$actionButtons}
                        {$recordLink}
                        {$impact}
                        {$relatedLabel}
                    </div>
                </div>
HTML;
            };

        @endphp

        @if (! empty($workQueue['todays_priority']))
            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500">Today's Priority</div>
                        <h2 class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">Do this first</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">HELOS ranked this mission first using priority, due date, and trusted impact where available.</p>
                    </div>
                    {!! $renderTask($workQueue['todays_priority']) !!}
                </div>
            </x-filament::section>
        @endif

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
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Today's Missions</h2>
                    <span class="sr-only">Tasks Due Today</span>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Ranked work for today. Use the action button so HELOS updates the real source record.</p>
                </div>

                <div class="grid gap-4">
                    @forelse (($workQueue['ranked_missions'] ?? []) as $task)
                        {!! $renderTask($task) !!}
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                            No open missions right now.
                        </div>
                    @endforelse
                </div>
            </div>
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Problems</h2>
                        <span class="sr-only">High Priority</span>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Blocked or escalated work that needs attention.</p>
                    </div>
                    <div class="grid gap-4">
                        @forelse (($workQueue['sections']['problems'] ?? []) as $task)
                            {!! $renderTask($task) !!}
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No blocked or escalated problems right now.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="grid gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Missing Information</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">These items block trusted numbers until the missing source truth is supplied.</p>
                    </div>
                    <div class="grid gap-4">
                        @forelse (($workQueue['sections']['missing_information'] ?? []) as $task)
                            {!! $renderTask($task) !!}
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                                No missing-information missions right now.
                            </div>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section>
            <div class="grid gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Waiting For Review</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Submitted work waiting for a supervisor or owner decision.</p>
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

        @endif

        @if ($activeMission)
            @php
                $sourceType = (string) $activeMission->source_type;
                $missionType = (string) $activeMission->mission_type;
            @endphp
            <div class="fixed inset-0 z-50 grid place-items-center bg-gray-950/50 p-4">
                <div class="w-full max-w-3xl rounded-xl bg-white p-6 shadow-xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-500">Mission action</div>
                            <h2 class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $activeMission->title }}</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $activeMission->summary }}</p>
                        </div>
                        <button type="button" wire:click="closeMissionAction" class="rounded-md px-2 py-1 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800">Close</button>
                    </div>

                    @if ($missionActionError)
                        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {{ $missionActionError }}
                        </div>
                    @endif

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        @if ($sourceType === 'bank_transaction')
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">What happened?</span>
                                <select wire:model.defer="missionActionData.classification" class="rounded-lg border-gray-300">
                                    @foreach (($actionOptions['classifications'] ?? []) as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Money effect</span>
                                <select wire:model.defer="missionActionData.transaction_type" class="rounded-lg border-gray-300">
                                    <option value="">Let HELOS infer</option>
                                    @foreach (($actionOptions['transactionTypes'] ?? []) as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Business</span>
                                <select wire:model.defer="missionActionData.allocated_business_id" class="rounded-lg border-gray-300">
                                    <option value="">Shared / unallocated</option>
                                    @foreach (($actionOptions['businesses'] ?? []) as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Bank or cash box</span>
                                <input wire:model.defer="missionActionData.money_container" class="rounded-lg border-gray-300" />
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Transfer destination</span>
                                <input wire:model.defer="missionActionData.counter_money_container" class="rounded-lg border-gray-300" />
                            </label>
                        @elseif ($sourceType === 'expense')
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Supplier / payee</span>
                                <input wire:model.defer="missionActionData.payee" class="rounded-lg border-gray-300" />
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Due date</span>
                                <input type="date" wire:model.defer="missionActionData.due_on" class="rounded-lg border-gray-300" />
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Paid amount</span>
                                <input type="number" step="0.01" wire:model.defer="missionActionData.paid_amount" class="rounded-lg border-gray-300" />
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Payment status</span>
                                <select wire:model.defer="missionActionData.payment_status" class="rounded-lg border-gray-300">
                                    <option value="unpaid">Unpaid</option>
                                    <option value="partial">Part paid</option>
                                    <option value="credit_due">Credit due</option>
                                    <option value="cheque_pending">Cheque pending</option>
                                    <option value="paid">Paid</option>
                                    <option value="settled">Settled</option>
                                </select>
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Payment method</span>
                                <input wire:model.defer="missionActionData.payment_method" class="rounded-lg border-gray-300" />
                            </label>
                        @elseif ($sourceType === 'service_billing_record')
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Paid amount</span>
                                <input type="number" step="0.01" wire:model.defer="missionActionData.paid_amount" class="rounded-lg border-gray-300" />
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Payment status</span>
                                <select wire:model.defer="missionActionData.payment_status" class="rounded-lg border-gray-300">
                                    <option value="unpaid">Unpaid</option>
                                    <option value="partial">Part paid</option>
                                    <option value="paid">Paid</option>
                                    <option value="overdue">Overdue</option>
                                </select>
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Payment method</span>
                                <input wire:model.defer="missionActionData.payment_method" class="rounded-lg border-gray-300" />
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Reference</span>
                                <input wire:model.defer="missionActionData.reference" class="rounded-lg border-gray-300" />
                            </label>
                        @elseif ($sourceType === 'production_entry')
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Payment status</span>
                                <select wire:model.defer="missionActionData.payment_status" class="rounded-lg border-gray-300">
                                    <option value="pending">Pending</option>
                                    <option value="paid">Paid</option>
                                    <option value="settled">Settled</option>
                                </select>
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Paid date</span>
                                <input type="date" wire:model.defer="missionActionData.paid_on" class="rounded-lg border-gray-300" />
                            </label>
                        @elseif (in_array($sourceType, ['material_ledger_entry', 'operational_event'], true) || $missionType === 'missing_product_links')
                            <label class="grid gap-1 text-sm md:col-span-2">
                                <span class="font-medium">Correct product / SKU</span>
                                <select wire:model.defer="missionActionData.sku_id" class="rounded-lg border-gray-300">
                                    <option value="">Choose SKU</option>
                                    @foreach (($actionOptions['skus'] ?? []) as $id => $label)
                                        <option value="{{ $id }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @if ($sourceType === 'operational_event')
                                <label class="grid gap-1 text-sm">
                                    <span class="font-medium">Return outcome</span>
                                    <select wire:model.defer="missionActionData.return_outcome" class="rounded-lg border-gray-300">
                                        <option value="">Choose if relevant</option>
                                        <option value="restock">Restock</option>
                                        <option value="damaged">Damaged</option>
                                        <option value="resend">Resend</option>
                                        <option value="unresolved">Unresolved</option>
                                    </select>
                                </label>
                                <label class="grid gap-1 text-sm">
                                    <span class="font-medium">Return reason / follow up</span>
                                    <input wire:model.defer="missionActionData.return_reason" class="rounded-lg border-gray-300" />
                                </label>
                            @endif
                        @elseif ($sourceType === 'cod_order')
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Tracking number</span>
                                <input wire:model.defer="missionActionData.tracking_number" class="rounded-lg border-gray-300" />
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Courier</span>
                                <input wire:model.defer="missionActionData.courier_name" class="rounded-lg border-gray-300" />
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Status</span>
                                <input wire:model.defer="missionActionData.status" class="rounded-lg border-gray-300" />
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium">Return / resend reason</span>
                                <input wire:model.defer="missionActionData.return_reason" class="rounded-lg border-gray-300" />
                            </label>
                        @else
                            <div class="md:col-span-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                                This mission still needs the full record page. Use Open record if more fields are required.
                            </div>
                        @endif

                        <label class="grid gap-1 text-sm md:col-span-2">
                            <span class="font-medium">Note</span>
                            <textarea wire:model.defer="missionActionData.note" rows="3" class="rounded-lg border-gray-300"></textarea>
                        </label>
                    </div>

                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <x-filament::button wire:click="closeMissionAction" color="gray">Cancel</x-filament::button>
                        <x-filament::button wire:click="saveMissionAction" color="success" icon="heroicon-o-check-circle">Save source action</x-filament::button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>

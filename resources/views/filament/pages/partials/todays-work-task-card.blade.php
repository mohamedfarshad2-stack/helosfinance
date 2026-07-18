@php
    $tone = $taskTone($task['priority'] ?? 'medium');
    $value = $taskValue($task['priority'] ?? 'medium');
    $related = $task['related_record'] ?? null;
    $hasLink = is_array($related) && ! empty($related['url']);
@endphp

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
            <x-filament::button wire:click="openMissionAction({{ (int) $task['id'] }})" color="primary" size="sm" icon="heroicon-o-pencil-square">
                Do action
            </x-filament::button>
            <x-filament::button wire:click="completeMission({{ (int) $task['id'] }})" color="success" size="sm" icon="heroicon-o-check-circle">
                Check complete
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

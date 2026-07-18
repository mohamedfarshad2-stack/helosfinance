<x-filament-panels::page>
    <div class="grid gap-6">
        <x-filament::section>
            <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-start">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Responsibility migration</div>
                    <h1 class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">Legacy Migration Status</h1>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        Use this before removing old staff profile access. Configured users never regain legacy access just because their responsibilities are empty.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-filament::button tag="a" href="{{ $responsibilityManagerUrl }}" icon="heroicon-o-user-plus">Configure responsibilities</x-filament::button>
                    <x-filament::button tag="a" href="{{ $auditUrl }}" color="gray" icon="heroicon-o-clock">Audit history</x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <div class="grid gap-3 md:grid-cols-4">
            @foreach ($summary as $label => $value)
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <x-filament::section>
            <div class="grid gap-4">
                @forelse ($rows as $row)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="grid gap-4 xl:grid-cols-[1fr_1fr_auto] xl:items-start">
                            <div>
                                <div class="text-base font-semibold text-gray-950 dark:text-white">{{ $row['name'] }}</div>
                                <div class="text-sm text-gray-500">{{ $row['email'] }} / {{ $row['business'] }}</div>
                                <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">Profile: {{ $row['profile'] }}</div>
                                <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $row['action_needed'] }}</div>
                            </div>

                            <div class="grid gap-2">
                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $row['configured'] ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">
                                        {{ $row['configured'] ? 'Configured' : 'Not configured' }}
                                    </span>
                                    @foreach ([
                                        'uses_fallback' => 'Using fallback',
                                        'no_access' => 'No access',
                                        'temporary_active' => 'Temporary active',
                                        'temporary_expiring' => 'Expiring soon',
                                        'temporary_expired' => 'Temporary expired',
                                        'supervisor' => 'Supervisor',
                                        'broad_legacy_profile' => 'Broad legacy profile',
                                    ] as $key => $label)
                                        @if ($row[$key])
                                            <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $label }}</span>
                                        @endif
                                    @endforeach
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-300">
                                    Responsibilities:
                                    @forelse ($row['responsibilities'] as $responsibility)
                                        <span class="font-medium">{{ $responsibility }}</span>@if (! $loop->last), @endif
                                    @empty
                                        <span class="font-medium">None</span>
                                    @endforelse
                                </div>
                                <div class="text-xs text-gray-500">Last change: {{ $row['last_change'] }}</div>
                            </div>

                            <div class="flex flex-wrap gap-2 xl:justify-end">
                                @if ($row['uses_fallback'])
                                    <x-filament::button wire:click="retireFallback({{ $row['id'] }})" color="warning" size="sm" icon="heroicon-o-lock-closed" wire:confirm="This freezes the current fallback responsibilities and stops this employee from depending on legacy profile access. Continue?">
                                        Remove fallback
                                    </x-filament::button>
                                @endif
                                <x-filament::button wire:click="assignNoAccess({{ $row['id'] }})" color="danger" size="sm" icon="heroicon-o-no-symbol" wire:confirm="This removes active responsibility assignments and makes this employee explicit no-access. Continue?">
                                    Assign no access
                                </x-filament::button>
                                @if ($row['temporary_assignment_id'])
                                    <x-filament::button wire:click="extendTemporaryAccess({{ $row['temporary_assignment_id'] }})" color="gray" size="sm" icon="heroicon-o-calendar-days">
                                        Extend 7 days
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700">
                        No staff users found for your business scope.
                    </div>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

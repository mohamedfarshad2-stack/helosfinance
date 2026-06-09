<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <x-filament::section>
            <form wire:submit="save" class="grid gap-6">
                {{ $this->form }}

                <div class="flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-o-check-circle">
                        Create client
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <x-filament::section>
            <div class="grid gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Why this helps</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">One flow creates the client business, login, and stock-app link together.</p>
                </div>
                <ul class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                    <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">No manual business creation first.</li>
                    <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">No separate user setup step later.</li>
                    <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">Stock-app integration is ready from day one.</li>
                    <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">The client lands in the right business scope immediately.</li>
                </ul>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

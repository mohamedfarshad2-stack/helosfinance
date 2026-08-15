<x-filament-panels::page>
    <div class="space-y-6" wire:poll.60s="refreshWorkspace">
        @if (! $isSandhamali)
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
                This account is restricted to Sandhamali.
            </div>
        @elseif (! $hasBusiness)
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
                No accessible service business was found for this account.
            </div>
        @else
            @php
                $service = $pipeline['service'] ?? [];
            @endphp

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Sandhamali account</h1>
                    <p class="text-sm text-gray-500">
                        Service client follow-up, quoting, billing, payment reminders, and retention work for {{ $business?->name ?? 'this service business' }}.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-filament::button wire:click="toggleLeadDesk" color="gray" icon="{{ $showLeadDesk ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down' }}">
                        {{ $showLeadDesk ? 'Close lead desk' : 'Open lead desk' }}
                    </x-filament::button>
                    <x-filament::button wire:click="downloadLeadSample" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-down-tray">
                        Download sample
                    </x-filament::button>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Active clients</div>
                    <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($clientSummary['active_clients'] ?? 0)) }}</div>
                    <div class="mt-1 text-sm text-gray-500">Service accounts currently active.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Today’s client work</div>
                    <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($teamExceptionSummary['client_work_open'] ?? 0)) }}</div>
                    <div class="mt-1 text-sm text-gray-500">Open service billing tasks that need action.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Leads / prospects</div>
                    <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($leadSummary['open_leads'] ?? 0)) }}</div>
                    <div class="mt-1 text-sm text-gray-500">Prospects waiting for Sandhamali to move forward.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Payments due</div>
                    <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($billingSummary['open_balance'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500">{{ number_format((int) ($billingSummary['open_bills'] ?? 0)) }} open billing rows.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Clients needing attention</div>
                    <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($teamExceptionSummary['attention_clients'] ?? 0) + (int) ($teamExceptionSummary['overdue_billing'] ?? 0)) }}</div>
                    <div class="mt-1 text-sm text-gray-500">Paused clients and overdue billing rows.</div>
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-[minmax(320px,0.95fr)_minmax(0,1.05fr)]">
                <x-filament::section>
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Today’s client work</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Open billing rows with assigned work context, due dates, and next actions.</p>
                    </div>

                    <div class="grid gap-3">
                        @forelse ($clientWorkQueue as $task)
                            <div class="rounded-xl border border-gray-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-950">{{ $task['title'] ?? 'Service work' }}</div>
                                        <div class="text-sm text-gray-500">{{ $task['related_record']['title'] ?? ($task['related_record']['label'] ?? 'Open service record') }}</div>
                                        <div class="mt-1 text-sm text-gray-500">{{ $task['why_it_matters'] ?? '' }}</div>
                                    </div>
                                    <div class="text-right text-sm text-gray-500">
                                        <div class="font-medium text-gray-900">{{ $task['assigned_user'] ?? 'Unassigned' }}</div>
                                        <div>{{ $task['assigned_team'] ?? 'Team' }}</div>
                                        <div class="mt-1">{{ $task['status_label'] ?? 'Open' }}</div>
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2 text-sm">
                                    <span class="rounded-full border border-gray-200 px-3 py-1">Due {{ $task['due_on'] ?? 'soon' }}</span>
                                    <span class="rounded-full border border-gray-200 px-3 py-1">{{ $task['priority'] ?? 'normal' }}</span>
                                    @if (filled($task['amount'] ?? null))
                                        <span class="rounded-full border border-gray-200 px-3 py-1">LKR {{ number_format((float) $task['amount'], 2) }}</span>
                                    @endif
                                </div>
                                @if (filled($task['recommended_action'] ?? null))
                                    <div class="mt-3 text-sm text-gray-600">{{ $task['recommended_action'] }}</div>
                                @endif
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                                No open service billing tasks yet.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Team exceptions</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Work that needs attention because a client, payment, or assignment is slipping.</p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 p-4">
                            <div class="text-sm font-medium text-gray-600">Due today</div>
                            <div class="mt-1 text-2xl font-semibold">{{ number_format((int) ($billingSummary['due_today'] ?? 0)) }}</div>
                            <div class="mt-1 text-sm text-gray-500">Billing rows that should be reviewed now.</div>
                        </div>
                        <div class="rounded-xl border border-gray-200 p-4">
                            <div class="text-sm font-medium text-gray-600">Paused clients</div>
                            <div class="mt-1 text-2xl font-semibold">{{ number_format((int) ($clientSummary['paused_clients'] ?? 0)) }}</div>
                            <div class="mt-1 text-sm text-gray-500">Active accounts that need reopening or review.</div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3">
                        @forelse ($billingDueRecords as $record)
                            <div class="rounded-xl border border-gray-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-950">{{ $record->client_name }}</div>
                                        <div class="text-sm text-gray-500">
                                            {{ \App\Domains\Shared\Models\ServiceBillingRecord::billingTypeOptions()[$record->billing_type] ?? 'Service billing' }}
                                            @if ($record->serviceClient)
                                                &bull; {{ $record->serviceClient->billing_style ? \App\Domains\Shared\Models\ServiceClient::billingStyleOptions()[$record->serviceClient->billing_style] ?? $record->serviceClient->billing_style : '' }}
                                            @endif
                                        </div>
                                        <div class="mt-1 text-sm text-gray-500">Due {{ optional($record->due_on)->format('Y-m-d') ?? 'soon' }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-gray-950">LKR {{ number_format((float) $record->balanceDue(), 2) }}</div>
                                        <div class="text-sm text-gray-500">Balance</div>
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <x-filament::button size="sm" color="success" wire:click="markBillingPaid({{ $record->id }})" icon="heroicon-o-check-circle">
                                        Mark paid
                                    </x-filament::button>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                                No open billing rows yet.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>

            @if ($showLeadDesk)
                <div class="grid gap-6 xl:grid-cols-[minmax(320px,0.95fr)_minmax(0,1.05fr)]">
                    <x-filament::section>
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Lead desk</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Capture a new service lead or import a sheet. Use the funnel from lead to paying client to retained account.</p>
                        </div>

                        <div class="space-y-4">
                            <div class="grid gap-3 rounded-xl border border-gray-200 p-4">
                                <div class="grid gap-3 md:grid-cols-2">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-gray-700">Excel / CSV file</label>
                                        <input wire:model="leadImportFile" type="file" accept=".xlsx,.xls,.csv,.txt" class="block w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                                        @error('leadImportFile')
                                            <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div class="flex items-end justify-end gap-2">
                                        <x-filament::button wire:click="importLeads" wire:loading.attr="disabled" icon="heroicon-o-arrow-up-tray">
                                            Import leads
                                        </x-filament::button>
                                    </div>
                                </div>
                                <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-600">
                                    Use the sample sheet columns: prospect name, contact person, phone, WhatsApp, source, stage, billing terms, expected monthly amount, next follow-up, notes.
                                </div>
                            </div>

                            <div class="grid gap-3">
                                @forelse ($recentServiceLeads->where('status', '!=', \App\Domains\Shared\Models\ServiceLead::STATUS_LOST)->take(8) as $lead)
                                    @php
                                        $statusLabel = \App\Domains\Shared\Models\ServiceLead::statusOptions()[$lead->status] ?? $lead->status;
                                        $sourceLabel = \App\Domains\Shared\Models\ServiceLead::sourceOptions()[$lead->source] ?? $lead->source;
                                    @endphp
                                    <div class="rounded-xl border border-gray-200 p-4">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div class="font-semibold text-gray-950">{{ $lead->displayLabel() }}</div>
                                                <div class="text-sm text-gray-500">{{ $statusLabel }} &bull; {{ $sourceLabel }}</div>
                                                <div class="mt-1 text-sm text-gray-500">
                                                    {{ $lead->contact_person ?: 'No contact person' }}
                                                    @if ($lead->next_follow_up_at)
                                                        &bull; follow-up {{ $lead->next_follow_up_at->format('Y-m-d') }}
                                                    @endif
                                                </div>
                                                <div class="mt-1 text-sm text-gray-500">
                                                    Billing: {{ \App\Domains\Shared\Models\ServiceLead::billingTermsOptions()[$lead->billing_terms] ?? $lead->billing_terms }}
                                                    @if ((float) $lead->expected_monthly_amount > 0)
                                                        &bull; LKR {{ number_format((float) $lead->expected_monthly_amount, 2) }}
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <x-filament::button size="sm" color="gray" tag="a" href="{{ $lead->callLink() }}" icon="heroicon-o-phone">
                                                    Call
                                                </x-filament::button>
                                                <x-filament::button size="sm" color="gray" tag="a" href="{{ $lead->whatsappLink() }}" target="_blank" icon="heroicon-o-chat-bubble-left-right">
                                                    WhatsApp
                                                </x-filament::button>
                                                <x-filament::button size="sm" color="success" wire:click="convertLead({{ $lead->id }})" icon="heroicon-o-user-plus">
                                                    Convert
                                                </x-filament::button>
                                                <x-filament::button size="sm" color="gray" wire:click="touchLead({{ $lead->id }}, '{{ \App\Domains\Shared\Models\ServiceLead::STATUS_CONTACTED }}')" icon="heroicon-o-check">
                                                    Contacted
                                                </x-filament::button>
                                            </div>
                                        </div>
                                        @if ($lead->notes)
                                            <div class="mt-3 text-sm text-gray-600">{{ $lead->notes }}</div>
                                        @endif
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                                        No service leads yet.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </x-filament::section>

                    <x-filament::section>
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Save a new lead</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Capture the prospect, the terms they want, and the follow-up date in one pass.</p>
                        </div>

                        <div class="space-y-4">
                            {{ $this->form }}

                            <div class="flex justify-end">
                                <x-filament::button wire:click="saveLead" wire:loading.attr="disabled" icon="heroicon-o-plus">
                                    Save lead
                                </x-filament::button>
                            </div>
                        </div>
                    </x-filament::section>
                </div>
            @endif

            <div class="grid gap-6 xl:grid-cols-2">
                <x-filament::section>
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Active clients</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">The service accounts already paying or ready to pay.</p>
                    </div>

                    <div class="grid gap-3">
                        @forelse ($activeServiceClients as $client)
                            <div class="rounded-xl border border-gray-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-950">{{ $client->name }}</div>
                                        <div class="text-sm text-gray-500">{{ \App\Domains\Shared\Models\ServiceClient::statusOptions()[$client->status] ?? $client->status }}</div>
                                        <div class="mt-1 text-sm text-gray-500">
                                            {{ \App\Domains\Shared\Models\ServiceClient::billingStyleOptions()[$client->billing_style] ?? $client->billing_style }}
                                            @if (filled($client->default_due_day))
                                                &bull; due day {{ $client->default_due_day }}
                                            @endif
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-gray-950">LKR {{ number_format((float) $client->default_monthly_amount, 2) }}</div>
                                        <div class="text-sm text-gray-500">Usual monthly amount</div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                                No active service clients yet.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Billing reminders</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Current service invoices and balances that still need collection.</p>
                    </div>

                    <div class="grid gap-3">
                        @forelse ($billingDueRecords as $record)
                            <div class="rounded-xl border border-gray-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-950">{{ $record->client_name }}</div>
                                        <div class="text-sm text-gray-500">{{ \App\Domains\Shared\Models\ServiceBillingRecord::billingTypeOptions()[$record->billing_type] ?? $record->billing_type }}</div>
                                        <div class="mt-1 text-sm text-gray-500">
                                            Due {{ optional($record->due_on)->format('Y-m-d') ?? 'soon' }}
                                            &bull; {{ \App\Domains\Shared\Models\ServiceBillingRecord::paymentStatusOptions()[$record->payment_status] ?? $record->payment_status }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-gray-950">LKR {{ number_format((float) $record->balanceDue(), 2) }}</div>
                                        <div class="text-sm text-gray-500">Balance due</div>
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <x-filament::button size="sm" color="success" wire:click="markBillingPaid({{ $record->id }})" icon="heroicon-o-check-circle">
                                        Mark paid
                                    </x-filament::button>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                                No open billing reminders right now.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>

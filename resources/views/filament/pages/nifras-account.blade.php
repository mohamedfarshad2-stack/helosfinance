<x-filament-panels::page>
    <div class="space-y-6" wire:poll.60s="refreshAccount">
        @if (! $isNifras)
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
                This account is restricted to Nifras.
            </div>
        @elseif (! $hasBusiness)
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
                No accessible business was found for this account.
            </div>
        @else
            @php
                $wholesale = $wholesaleSummary ?? [];
                $leads = $leadSummary ?? [];
                $wholesalePipeline = $pipeline['wholesale'] ?? [];
            @endphp

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Nifras account</h1>
                    <p class="text-sm text-gray-500">
                        Wholesale lead follow-up, order booking, profit preview, and repeat-customer management for {{ $business?->name ?? 'this business' }}.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-filament::button tag="a" href="{{ \App\Filament\Pages\TodaysWork::getUrl() }}" color="gray" icon="heroicon-o-clipboard-document-check">
                        Open My Work
                    </x-filament::button>
                    <x-filament::button tag="a" href="{{ \App\Filament\Resources\OperationalEventResource::getUrl('index') }}" color="gray" icon="heroicon-o-bolt">
                        Open order events
                    </x-filament::button>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Open leads</div>
                    <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($leads['open_leads'] ?? 0)) }}</div>
                    <div class="mt-1 text-sm text-gray-500">Lead records waiting for Nifras to move forward.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Follow-ups due</div>
                    <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($leads['contact_due'] ?? 0)) }}</div>
                    <div class="mt-1 text-sm text-gray-500">These leads need a call or WhatsApp today.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Converted customers</div>
                    <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($leads['converted_customers'] ?? 0)) }}</div>
                    <div class="mt-1 text-sm text-gray-500">Records already turned into customer relationships.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Open collections</div>
                    <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($wholesale['outstanding_collections'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500">{{ number_format((int) ($wholesale['open_collection_orders'] ?? 0)) }} order(s) still have money to collect.</div>
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-2">
                <x-filament::section>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Products and production</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Record how many slippers were made today, which worker did the labour, and what still needs payroll attention.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-filament::button tag="a" href="{{ \App\Filament\Resources\ProductionEntryResource::getUrl('create') }}" color="primary" icon="heroicon-o-plus-circle">
                                New production
                            </x-filament::button>
                            <x-filament::button tag="a" href="{{ \App\Filament\Resources\ProductionEntryResource::getUrl('index') }}" color="gray" icon="heroicon-o-clipboard-document-list">
                                Open board
                            </x-filament::button>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-sm font-medium text-gray-600">Entries today</div>
                            <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($productionSummary['entries_today'] ?? 0)) }}</div>
                            <div class="mt-1 text-sm text-gray-500">{{ number_format((int) ($productionSummary['units_today'] ?? 0)) }} units completed today.</div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-sm font-medium text-gray-600">This month</div>
                            <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($productionSummary['entries_this_month'] ?? 0)) }}</div>
                            <div class="mt-1 text-sm text-gray-500">{{ number_format((int) ($productionSummary['units_this_month'] ?? 0)) }} units recorded.</div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-sm font-medium text-gray-600">Unpaid labour</div>
                            <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($productionSummary['unpaid_labour'] ?? 0), 2) }}</div>
                            <div class="mt-1 text-sm text-gray-500">{{ number_format((int) ($productionSummary['active_workers'] ?? 0)) }} active worker(s) this month.</div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-sm font-medium text-gray-600">Paid labour</div>
                            <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($productionSummary['paid_labour'] ?? 0), 2) }}</div>
                            <div class="mt-1 text-sm text-gray-500">Only the rows already marked paid this month.</div>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-3">
                        @forelse ($recentProductionEntries as $entry)
                            <div class="rounded-xl border border-gray-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-950">{{ $entry->employee_name ?: 'Production row' }}</div>
                                        <div class="text-sm text-gray-500">
                                            {{ $entry->sku?->code ?? 'No SKU' }}
                                            @if ($entry->production_step)
                                                &bull; {{ $entry->production_step }}
                                            @endif
                                        </div>
                                        <div class="mt-1 text-sm text-gray-500">
                                            {{ $entry->production_kind === 'finished_product' ? 'Finished product' : 'Part production' }}
                                            @if ($entry->part_name)
                                                &bull; {{ $entry->part_name }}
                                            @endif
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-gray-950">{{ number_format((int) $entry->quantity_produced) }} unit(s)</div>
                                        <div class="text-sm text-gray-500">Net pay LKR {{ number_format((float) $entry->net_payable, 2) }}</div>
                                    </div>
                                </div>

                                <div class="mt-3 grid gap-2 text-sm text-gray-600 md:grid-cols-4">
                                    <div>Rate: <span class="font-medium text-gray-900">LKR {{ number_format((float) $entry->piece_rate, 2) }}</span></div>
                                    <div>Gross pay: <span class="font-medium text-gray-900">LKR {{ number_format((float) $entry->employee_payout, 2) }}</span></div>
                                    <div>Status: <span class="font-medium text-gray-900">{{ $entry->payment_status }}</span></div>
                                    <div>Date: <span class="font-medium text-gray-900">{{ optional($entry->produced_on)->format('Y-m-d') }}</span></div>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                                No production rows have been recorded yet.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Petty cash</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Record the cash that already left the drawer for fuel, packing, lunch, repairs, and similar small spends.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-filament::button tag="a" href="{{ \App\Filament\Pages\QuickExpenseEntry::getUrl() }}" color="primary" icon="heroicon-o-banknotes">
                                New petty cash row
                            </x-filament::button>
                            <x-filament::button tag="a" href="{{ \App\Filament\Resources\ExpenseResource::getUrl('index') }}" color="gray" icon="heroicon-o-clipboard-document-list">
                                Open expenses
                            </x-filament::button>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-sm font-medium text-gray-600">Rows this month</div>
                            <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($cashSummary['rows_this_month'] ?? 0)) }}</div>
                            <div class="mt-1 text-sm text-gray-500">{{ number_format((int) ($cashSummary['rows_today'] ?? 0)) }} row(s) recorded today.</div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-sm font-medium text-gray-600">Cash spent</div>
                            <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($cashSummary['spent_this_month'] ?? 0), 2) }}</div>
                            <div class="mt-1 text-sm text-gray-500">All petty cash rows for the month.</div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-sm font-medium text-gray-600">Paid today</div>
                            <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($cashSummary['paid_this_month'] ?? 0), 2) }}</div>
                            <div class="mt-1 text-sm text-gray-500">Money already settled from the petty cash record.</div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-sm font-medium text-gray-600">Balance due</div>
                            <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($cashSummary['balance_due'] ?? 0), 2) }}</div>
                            <div class="mt-1 text-sm text-gray-500">Any part-paid petty cash still waiting.</div>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-3">
                        @forelse ($recentCashExpenses as $expense)
                            <div class="rounded-xl border border-gray-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-950">{{ $expense->category ?: 'Petty cash' }}</div>
                                        <div class="text-sm text-gray-500">
                                            {{ $expense->payee ?: 'No payee' }}
                                            @if ($expense->description)
                                                &bull; {{ $expense->description }}
                                            @endif
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-gray-950">LKR {{ number_format((float) $expense->amount, 2) }}</div>
                                        <div class="text-sm text-gray-500">{{ $expense->payment_status }}</div>
                                    </div>
                                </div>

                                <div class="mt-3 grid gap-2 text-sm text-gray-600 md:grid-cols-4">
                                    <div>Paid: <span class="font-medium text-gray-900">LKR {{ number_format((float) $expense->paid_amount, 2) }}</span></div>
                                    <div>Spent on: <span class="font-medium text-gray-900">{{ optional($expense->spent_on)->format('Y-m-d') }}</span></div>
                                    <div>Method: <span class="font-medium text-gray-900">{{ $expense->payment_method ?: '—' }}</span></div>
                                    <div>Reported by: <span class="font-medium text-gray-900">{{ $expense->reported_by ?: '—' }}</span></div>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                                No petty cash rows have been recorded yet.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Lead desk</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Open this desk to import a sample Excel file and work wholesale leads in one place.</p>
                    </div>
                    <x-filament::button wire:click="toggleLeadDesk" color="gray" icon="{{ $showLeadDesk ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down' }}">
                        {{ $showLeadDesk ? 'Close lead desk' : 'Open lead desk' }}
                    </x-filament::button>
                </div>

                @if ($showLeadDesk)
                    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(320px,0.95fr)_minmax(0,1.05fr)]">
                        <x-filament::section>
                        <div class="mb-4">
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Import leads from Excel</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Download the sample sheet, fill it, and upload it here.</p>
                        </div>

                        <div class="space-y-4">
                            <div class="flex flex-wrap gap-2">
                                <x-filament::button wire:click="downloadLeadSample" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-down-tray">
                                    Download sample
                                </x-filament::button>
                            </div>

                            <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-600">
                                Use the same columns as the sample file. HELOS will import each row as one lead.
                            </div>

                                <div>
                                    <label class="mb-2 block text-sm font-medium text-gray-700">Excel / CSV file</label>
                                    <input wire:model="leadImportFile" type="file" accept=".xlsx,.xls,.csv,.txt" class="block w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                                    @error('leadImportFile')
                                        <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="flex justify-end">
                                    <x-filament::button wire:click="importWholesaleLeads" wire:loading.attr="disabled" icon="heroicon-o-arrow-up-tray">
                                        Import leads
                                    </x-filament::button>
                                </div>
                            </div>
                        </x-filament::section>

                        <x-filament::section>
                            <div class="mb-4">
                                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Lead action queue</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Call first, WhatsApp second, then convert the buyer into a lifetime customer.</p>
                            </div>

                            <div class="grid gap-3">
                                @forelse ($recentWholesaleLeads as $lead)
                                    @php
                                        $callLink = $lead->callLink();
                                        $whatsappLink = $lead->whatsappLink($lead->whatsappMessage());
                                        $statusLabel = \App\Domains\Shared\Models\WholesaleLead::statusOptions()[$lead->status] ?? $lead->status;
                                    @endphp
                                    <div class="rounded-xl border border-gray-200 p-4">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div class="font-semibold text-gray-950">{{ $lead->displayLabel() }}</div>
                                                <div class="text-sm text-gray-500">
                                                    {{ $statusLabel }}
                                                    @if ($lead->location)
                                                        &bull; {{ $lead->location }}
                                                    @endif
                                                </div>
                                                <div class="mt-1 text-sm text-gray-500">
                                                    {{ $lead->products_of_interest ?: 'No product notes yet' }}
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div class="font-semibold text-gray-950">{{ optional($lead->next_follow_up_at)->format('Y-m-d') ?? '—' }}</div>
                                                <div class="text-sm text-gray-500">{{ $lead->nextActionLabel() }}</div>
                                            </div>
                                        </div>

                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if ($callLink)
                                                <x-filament::button tag="a" href="{{ $callLink }}" color="gray" icon="heroicon-o-phone">
                                                    Call
                                                </x-filament::button>
                                            @endif
                                            @if ($whatsappLink)
                                                <x-filament::button tag="a" href="{{ $whatsappLink }}" target="_blank" color="success" icon="heroicon-o-chat-bubble-left-right">
                                                    WhatsApp
                                                </x-filament::button>
                                            @endif
                                            <x-filament::button wire:click="touchLead({{ $lead->id }}, '{{ \App\Domains\Shared\Models\WholesaleLead::STATUS_CONTACTED }}')" color="gray" icon="heroicon-o-check">
                                                Contacted
                                            </x-filament::button>
                                            <x-filament::button wire:click="touchLead({{ $lead->id }}, '{{ \App\Domains\Shared\Models\WholesaleLead::STATUS_CUSTOMER }}')" color="primary" icon="heroicon-o-user-plus">
                                                Convert
                                            </x-filament::button>
                                            <x-filament::button wire:click="touchLead({{ $lead->id }}, '{{ \App\Domains\Shared\Models\WholesaleLead::STATUS_LOST }}')" color="gray" icon="heroicon-o-x-mark">
                                                Lost
                                            </x-filament::button>
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                                        No wholesale leads have been imported yet.
                                    </div>
                                @endforelse
                            </div>
                        </x-filament::section>
                    </div>
                @endif
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Orders booked this month</div>
                    <div class="mt-1 text-3xl font-semibold">{{ number_format((int) ($wholesale['booked_orders'] ?? 0)) }}</div>
                    <div class="mt-1 text-sm text-gray-500">{{ number_format((float) ($wholesale['average_order_value'] ?? 0), 2) }} average order value.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Recognized wholesale sales</div>
                    <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($wholesale['recognized_sales'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500">{{ number_format((int) ($wholesale['repeat_customers'] ?? 0)) }} repeat customer(s) in the current history.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Gross contribution</div>
                    <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($wholesale['gross_contribution'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500">This is sales after product cost and the manually entered transport cost.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Open collections</div>
                    <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($wholesale['outstanding_collections'] ?? 0), 2) }}</div>
                    <div class="mt-1 text-sm text-gray-500">{{ number_format((int) ($wholesale['open_collection_orders'] ?? 0)) }} order(s) still have money to collect.</div>
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(340px,0.85fr)]">
                <x-filament::section>
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Create wholesale order</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Book the customer order, let HELOS calculate profit, and save the next follow-up date.</p>
                    </div>

                    <form wire:submit="saveWholesaleOrder" class="grid gap-6">
                        {{ $this->form }}

                        <div class="flex justify-end">
                            <x-filament::button type="submit" icon="heroicon-o-plus-circle">
                                Save wholesale order
                            </x-filament::button>
                        </div>
                    </form>
                </x-filament::section>

                <div class="grid gap-6">
                    <x-filament::section>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="rounded-xl border border-gray-200 bg-white p-4">
                                <div class="text-sm font-medium text-gray-600">Live wholesale pipeline</div>
                                <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($wholesalePipeline['expected_revenue'] ?? 0), 2) }}</div>
                                <div class="mt-1 text-sm text-gray-500">{{ number_format((int) ($wholesalePipeline['pending_orders'] ?? 0)) }} open order(s) in the live event pipeline.</div>
                            </div>
                            <div class="rounded-xl border border-gray-200 bg-white p-4">
                                <div class="text-sm font-medium text-gray-600">Collected through events</div>
                                <div class="mt-1 text-3xl font-semibold">LKR {{ number_format((float) ($wholesalePipeline['collected_revenue'] ?? 0), 2) }}</div>
                                <div class="mt-1 text-sm text-gray-500">{{ number_format((int) ($wholesalePipeline['delivered_orders'] ?? 0)) }} delivered event(s) this month.</div>
                            </div>
                        </div>
                    </x-filament::section>

                    <x-filament::section>
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Customer repeat pattern</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">These are the buyers with the highest outstanding balance or repeated business.</p>
                        </div>

                        <div class="grid gap-3">
                            @forelse ($customerSummaries as $customer)
                                <div class="rounded-xl border border-gray-200 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-semibold text-gray-950">{{ $customer['customer_label'] }}</div>
                                            <div class="text-sm text-gray-500">
                                                {{ $customer['order_count'] }} order(s) &bull; {{ $customer['delivered_count'] }} delivered
                                                @if (! empty($customer['last_status']))
                                                    &bull; {{ $customer['last_status'] }}
                                                @endif
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="font-semibold text-gray-950">LKR {{ number_format((float) ($customer['outstanding_amount'] ?? 0), 2) }}</div>
                                            <div class="text-sm text-gray-500">Outstanding</div>
                                        </div>
                                    </div>

                                    <div class="mt-3 grid gap-2 text-sm text-gray-600 md:grid-cols-2">
                                        <div>Recognized sales: <span class="font-medium text-gray-900">LKR {{ number_format((float) ($customer['recognized_sales'] ?? 0), 2) }}</span></div>
                                        <div>Gross contribution: <span class="font-medium text-gray-900">LKR {{ number_format((float) ($customer['gross_contribution'] ?? 0), 2) }}</span></div>
                                        <div>Last purchase: <span class="font-medium text-gray-900">{{ $customer['last_purchase_at'] ?? '—' }}</span></div>
                                        <div>Next follow-up: <span class="font-medium text-gray-900">{{ $customer['next_follow_up_at'] ?? '—' }}</span></div>
                                        <div>Reorder due: <span class="font-medium text-gray-900">{{ $customer['reorder_due_at'] ?? '—' }}</span></div>
                                        <div>Pattern: <span class="font-medium text-gray-900">{{ $customer['average_reorder_days'] ? $customer['average_reorder_days'].' days' : 'No repeat pattern yet' }}</span></div>
                                    </div>

                                    <div class="mt-3 text-sm text-gray-500">
                                        Top items: {{ $customer['top_items'] ?? 'No SKU history' }}
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                                    No wholesale orders have been entered yet.
                                </div>
                            @endforelse
                        </div>
                    </x-filament::section>
                </div>
            </div>

            <x-filament::section>
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Recent wholesale orders</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Use this list to verify the last order, profit, and outstanding amount without opening another page.</p>
                </div>

                <div class="grid gap-3">
                    @forelse ($recentWholesaleOrders as $order)
                        <div class="rounded-xl border border-gray-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-gray-950">{{ $order->order_number ?? 'Wholesale order' }}</div>
                                    <div class="text-sm text-gray-500">
                                        {{ $order->customerLabel() }}
                                        @if ($order->customer_location)
                                            &bull; {{ $order->customer_location }}
                                        @endif
                                    </div>
                                    <div class="mt-1 text-sm text-gray-500">{{ $order->lineItemSummary() }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-semibold text-gray-950">LKR {{ number_format($order->netSalesAmount(), 2) }}</div>
                                    <div class="text-sm text-gray-500">Profit LKR {{ number_format($order->grossProfitAmount(), 2) }}</div>
                                </div>
                            </div>

                            <div class="mt-3 grid gap-2 text-sm text-gray-600 md:grid-cols-4">
                                <div>Status: <span class="font-medium text-gray-900">{{ \App\Domains\Shared\Models\WholesaleOrder::statusOptions()[$order->status] ?? $order->status }}</span></div>
                                <div>Payment: <span class="font-medium text-gray-900">{{ \App\Domains\Shared\Models\WholesaleOrder::paymentStatusOptions()[$order->payment_status] ?? $order->payment_status }}</span></div>
                                <div>Paid: <span class="font-medium text-gray-900">LKR {{ number_format($order->paidAmount(), 2) }}</span></div>
                                <div>Outstanding: <span class="font-medium text-gray-900">LKR {{ number_format($order->outstandingAmount(), 2) }}</span></div>
                                <div>Order date: <span class="font-medium text-gray-900">{{ optional($order->order_date)->format('Y-m-d') }}</span></div>
                                <div>Follow-up: <span class="font-medium text-gray-900">{{ optional($order->next_follow_up_at)->format('Y-m-d') ?? '—' }}</span></div>
                                <div>Reorder due: <span class="font-medium text-gray-900">{{ optional($order->reorder_due_at)->format('Y-m-d') ?? '—' }}</span></div>
                                <div>Margin: <span class="font-medium text-gray-900">{{ $order->profitMarginPercent() }}%</span></div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                            No recent wholesale orders yet.
                        </div>
                    @endforelse
                </div>
            </x-filament::section>

        @endif
    </div>
</x-filament-panels::page>

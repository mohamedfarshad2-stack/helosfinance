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

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                <x-filament::section>
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Lead desk</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Record the lead once, then use the call and WhatsApp buttons to move it toward a customer fast.</p>
                    </div>

                    <form wire:submit="saveWholesaleLead" class="space-y-6">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Business / buyer name</label>
                                <input wire:model.live="leadData.customer_name" type="text" class="w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" placeholder="Example: Dilshan Footwear">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Contact person</label>
                                <input wire:model.live="leadData.contact_name" type="text" class="w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" placeholder="Example: Dilshan">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Phone</label>
                                <input wire:model.live="leadData.phone" type="tel" class="w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" placeholder="0771234567">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">WhatsApp number</label>
                                <input wire:model.live="leadData.whatsapp_phone" type="tel" class="w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" placeholder="0771234567">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Location</label>
                                <input wire:model.live="leadData.location" type="text" class="w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" placeholder="Kurunegala">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Source</label>
                                <select wire:model.live="leadData.source" class="w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                                    @foreach (\App\Domains\Shared\Models\WholesaleLead::sourceOptions() as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Lead status</label>
                                <select wire:model.live="leadData.status" class="w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                                    @foreach (\App\Domains\Shared\Models\WholesaleLead::statusOptions() as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Next follow-up</label>
                                <input wire:model.live="leadData.next_follow_up_at" type="date" class="w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Products of interest</label>
                                <textarea wire:model.live="leadData.products_of_interest" rows="4" class="w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" placeholder="Sizes, products, target price, quantity, or style."></textarea>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Notes</label>
                                <textarea wire:model.live="leadData.notes" rows="4" class="w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" placeholder="What should Nifras remember before the next call?"></textarea>
                            </div>
                        </div>

                        <div class="flex flex-wrap justify-end gap-2">
                            <x-filament::button type="submit" icon="heroicon-o-plus-circle">
                                Save lead
                            </x-filament::button>
                        </div>
                    </form>
                </x-filament::section>

                <x-filament::section>
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Lead action queue</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Call first, WhatsApp second, and use the conversion button once the buyer is ready to become a customer.</p>
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
                                                • {{ $lead->location }}
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
                                No wholesale leads have been entered yet.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>
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
                                                {{ $customer['order_count'] }} order(s) • {{ $customer['delivered_count'] }} delivered
                                                @if (! empty($customer['last_status']))
                                                    • {{ $customer['last_status'] }}
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
                                            • {{ $order->customer_location }}
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

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
                $wholesalePipeline = $pipeline['wholesale'] ?? [];
            @endphp

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Nifras account</h1>
                    <p class="text-sm text-gray-500">
                        Wholesale order booking, profit preview, and customer follow-up for {{ $business?->name ?? 'this business' }}.
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
                    <div class="mt-1 text-sm text-gray-500">This is sales after product cost and courier cost.</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm font-medium text-gray-600">Outstanding collections</div>
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

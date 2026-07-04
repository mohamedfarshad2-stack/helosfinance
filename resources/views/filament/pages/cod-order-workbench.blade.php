<x-filament-panels::page>
    <style>
        .cod-workbench {
            display: grid;
            gap: 14px;
        }

        .cod-toolbar {
            display: grid;
            grid-template-columns: minmax(180px, 260px) minmax(160px, 220px) minmax(180px, 1fr);
            gap: 10px;
            align-items: end;
        }

        .cod-order-card {
            border: 1px solid rgb(229 231 235);
            border-radius: 8px;
            background: white;
            padding: 8px;
        }

        .dark .cod-order-card {
            border-color: rgb(31 41 55);
            background: rgb(17 24 39);
        }

        .cod-card-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 7px;
            align-items: stretch;
        }

        .cod-group {
            border: 1px solid rgb(229 231 235);
            border-radius: 8px;
            background: rgb(249 250 251);
            padding: 6px;
            min-width: 0;
        }

        .cod-group-customer {
            grid-column: span 4;
        }

        .cod-group-calling {
            grid-column: span 3;
        }

        .cod-group-product {
            grid-column: span 5;
        }

        .dark .cod-group {
            border-color: rgb(55 65 81);
            background: rgb(31 41 55);
        }

        .cod-group-title {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 5px;
            color: rgb(17 24 39);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .dark .cod-group-title {
            color: white;
        }

        .cod-group-title span {
            display: inline-flex;
            width: 8px;
            height: 8px;
            border-radius: 999px;
        }

        .cod-group-customer .cod-group-title span {
            background: rgb(59 130 246);
        }

        .cod-group-calling .cod-group-title span {
            background: rgb(245 158 11);
        }

        .cod-group-product .cod-group-title span {
            background: rgb(16 185 129);
        }

        .cod-group-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 5px;
        }

        .cod-group-customer .cod-group-fields {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .cod-group-product .cod-group-fields {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .cod-cell {
            min-width: 0;
        }

        .cod-cell label {
            display: block;
            margin-bottom: 2px;
            color: rgb(107 114 128);
            font-size: 10px;
            line-height: 1.1;
            white-space: nowrap;
        }

        .dark .cod-cell label {
            color: rgb(156 163 175);
        }

        .cod-cell input,
        .cod-cell select,
        .cod-metric {
            width: 100%;
            min-width: 0;
            height: 29px;
            border: 1px solid rgb(209 213 219);
            border-radius: 6px;
            background: white;
            padding: 4px 6px;
            color: rgb(17 24 39);
            font-size: 12px;
            line-height: 1.2;
        }

        .dark .cod-cell input,
        .dark .cod-cell select,
        .dark .cod-metric {
            border-color: rgb(55 65 81);
            background: rgb(31 41 55);
            color: white;
        }

        .cod-metric {
            display: flex;
            align-items: center;
            font-weight: 700;
            background: rgb(243 244 246);
        }

        .cod-cell input[type="checkbox"] {
            width: 22px;
            height: 22px;
            padding: 0;
        }

        .cod-status-new {
            border-left: 4px solid rgb(156 163 175);
        }

        .cod-status-confirmed {
            border-left: 4px solid rgb(59 130 246);
        }

        .cod-status-dispatched,
        .cod-status-courier_pending,
        .cod-status-resent {
            border-left: 4px solid rgb(245 158 11);
        }

        .cod-status-delivered {
            border-left: 4px solid rgb(16 185 129);
        }

        .cod-status-returned,
        .cod-status-cancelled,
        .cod-status-no_answer {
            border-left: 4px solid rgb(239 68 68);
        }

        @media (max-width: 1280px) {
            .cod-card-grid {
                grid-template-columns: repeat(6, minmax(0, 1fr));
            }

            .cod-group-customer,
            .cod-group-calling,
            .cod-group-product {
                grid-column: span 3;
            }
        }

        @media (max-width: 900px) {
            .cod-toolbar {
                grid-template-columns: 1fr;
            }

            .cod-card-grid {
                grid-template-columns: 1fr;
            }

            .cod-group-customer,
            .cod-group-calling,
            .cod-group-product {
                grid-column: span 1;
            }
        }
    </style>

    <div class="cod-workbench">
        <x-filament::section>
            <div class="grid gap-3 lg:grid-cols-[1.2fr_0.8fr] lg:items-start">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">COD daily work</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">Work order by order without opening extra pages</div>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        Simple flow: fill customer details, call and update status, add tracking when dispatched, then update delivered or returned later.
                    </div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                    <div class="font-semibold text-gray-950 dark:text-white">When to use this screen</div>
                    <div class="mt-2">Use this for real daily COD work. One row should move from calling to dispatched to delivered or returned over time.</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="cod-toolbar">
                @if (count($businessOptions) > 1)
                    <div class="cod-cell">
                        <label>Business</label>
                        <select wire:model.live="businessId">
                            @foreach ($businessOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="cod-cell">
                    <label>Show</label>
                    <select wire:model.live="statusFilter">
                        <option value="">All statuses</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="cod-cell">
                    <label>Search</label>
                    <input type="search" wire:model.live.debounce.350ms="search" placeholder="Tracking, customer, phone, address, city, district">
                </div>
            </div>
        </x-filament::section>

        <div class="grid gap-2">
            @forelse ($orders as $order)
                <div class="cod-order-card cod-status-{{ str_replace('_', '-', $order->status) }}" wire:key="cod-order-{{ $order->id }}">
                    <div class="cod-card-grid">
                        <div class="cod-group cod-group-customer">
                            <div class="cod-group-title"><span></span>Customer</div>
                            <div class="cod-group-fields">
                                <div class="cod-cell">
                                    <label>Date</label>
                                    <input type="date" value="{{ optional($order->order_date)->format('Y-m-d') }}" title="Order received date" wire:change="updateField({{ $order->id }}, 'order_date', $event.target.value)">
                                </div>
                                <div class="cod-cell">
                                    <label>Tracking no</label>
                                    <input value="{{ $order->tracking_number }}" placeholder="Add after dispatch" title="Tracking number starts dispatch tracking and courier cost timing" wire:change="updateField({{ $order->id }}, 'tracking_number', $event.target.value)">
                                </div>
                                <div class="cod-cell">
                                    <label>Name</label>
                                    <input value="{{ $order->customer_name }}" placeholder="Customer name" title="Customer name to call" wire:change="updateField({{ $order->id }}, 'customer_name', $event.target.value)">
                                </div>
                                <div class="cod-cell">
                                    <label>Phone</label>
                                    <input value="{{ $order->customer_phone }}" placeholder="0771234567" title="Phone number to call" wire:change="updateField({{ $order->id }}, 'customer_phone', $event.target.value)">
                                </div>
                                <div class="cod-cell">
                                    <label>Alt phone</label>
                                    <input value="{{ $order->customer_alt_phone }}" placeholder="Optional" title="Second phone number if available" wire:change="updateField({{ $order->id }}, 'customer_alt_phone', $event.target.value)">
                                </div>
                                <div class="cod-cell">
                                    <label>Address</label>
                                    <input value="{{ $order->address }}" placeholder="Street / house / landmark" title="Delivery address" wire:change="updateField({{ $order->id }}, 'address', $event.target.value)">
                                </div>
                                <div class="cod-cell">
                                    <label>City</label>
                                    <input value="{{ $order->city }}" placeholder="City / area" title="Delivery city or area" wire:change="updateField({{ $order->id }}, 'city', $event.target.value)">
                                </div>
                                <div class="cod-cell">
                                    <label>District</label>
                                    <input value="{{ $order->district }}" placeholder="District" title="District for return analysis" wire:change="updateField({{ $order->id }}, 'district', $event.target.value)">
                                </div>
                            </div>
                        </div>

                        <div class="cod-group cod-group-calling">
                            <div class="cod-group-title"><span></span>Calling</div>
                            <div class="cod-group-fields">
                                <div class="cod-cell">
                                    <label>Calls</label>
                                    <input type="number" min="0" value="{{ $order->call_attempts }}" placeholder="0" title="How many times customer was called" wire:change="updateField({{ $order->id }}, 'call_attempts', $event.target.value)">
                                </div>
                                <div class="cod-cell">
                                    <label>Status</label>
                                    <select title="Choose the current order stage" wire:change="updateField({{ $order->id }}, 'status', $event.target.value)">
                                        @foreach ($statusOptions as $value => $label)
                                            <option value="{{ $value }}" @selected($order->status === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="cod-cell">
                                    <label>Source</label>
                                    <select title="How this order was received" wire:change="updateField({{ $order->id }}, 'cod_order_source_id', $event.target.value)">
                                        <option value="">Choose source</option>
                                        @foreach ($sourceOptions as $id => $name)
                                            <option value="{{ $id }}" @selected((int) $order->cod_order_source_id === (int) $id)>{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="cod-cell">
                                    <label>CSR</label>
                                    <select title="Employee responsible for this order" wire:change="updateField({{ $order->id }}, 'csr_employee_id', $event.target.value)">
                                        <option value="">Choose CSR</option>
                                        @foreach ($csrOptions as $id => $name)
                                            <option value="{{ $id }}" @selected((int) $order->csr_employee_id === (int) $id)>{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="cod-group cod-group-product">
                            <div class="cod-group-title"><span></span>Product</div>
                            <div class="cod-group-fields">
                                <div class="cod-cell">
                                    <label>SKU</label>
                                    <select title="Select the product sold" wire:change="updateField({{ $order->id }}, 'sku_id', $event.target.value)">
                                        <option value="">Choose SKU</option>
                                        @foreach ($skuOptions as $id => $code)
                                            <option value="{{ $id }}" @selected((int) $order->sku_id === (int) $id)>{{ $code }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="cod-cell">
                                    <label>Size</label>
                                    <input value="{{ $order->size }}" placeholder="Size / variant" title="Customer requested size or variant" wire:change="updateField({{ $order->id }}, 'size', $event.target.value)">
                                </div>
                                <div class="cod-cell">
                                    <label>Sale Rs</label>
                                    <input type="number" step="0.01" value="{{ $order->sale_amount }}" placeholder="Product price" title="Product price before delivery charge" wire:change="updateField({{ $order->id }}, 'sale_amount', $event.target.value)">
                                </div>
                                <div class="cod-cell">
                                    <label>From stock?</label>
                                    <input type="checkbox" title="Tick if resend uses already produced stock so COGS is not added again" @checked($order->resend_from_stock) wire:change="updateField({{ $order->id }}, 'resend_from_stock', $event.target.checked ? '1' : '0')">
                                </div>
                                <div class="cod-cell" style="grid-column: span 4;">
                                    <label>Remark</label>
                                    <input value="{{ $order->confirmation_remark }}" placeholder="Call note / customer request / delivery instruction" title="Calling note or customer instruction" wire:change="updateField({{ $order->id }}, 'confirmation_remark', $event.target.value)">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        No COD orders found. Upload today’s Excel sheet to start calling work.
                    </div>
                </x-filament::section>
            @endforelse
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>

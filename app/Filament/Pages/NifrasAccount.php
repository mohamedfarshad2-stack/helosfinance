<?php

namespace App\Filament\Pages;

use App\Domains\FinancialClarity\Services\RevenuePipelineService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\WholesaleOrder;
use App\Domains\Shared\Models\WholesaleLead;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

class NifrasAccount extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'nifras-account';

    protected static ?string $navigationGroup = 'My Work';

    protected static ?string $navigationLabel = 'Nifras Account';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.nifras-account';

    public ?Business $business = null;

    public array $pipeline = [];

    public array $wholesaleSummary = [];

    public array $leadSummary = [];

    public SupportCollection $customerSummaries;

    public SupportCollection $recentWholesaleLeads;

    public Collection $recentWholesaleOrders;

    public ?array $data = [];

    public ?array $leadData = [];

    public static function shouldRegisterNavigation(): bool
    {
        return static::isNifrasAccount();
    }

    public static function canAccess(): bool
    {
        return static::isNifrasAccount();
    }

    public function mount(RevenuePipelineService $revenuePipeline): void
    {
        $this->loadAccount($revenuePipeline);
        $this->form->fill($this->defaultWholesaleFormState());
        $this->leadData = $this->defaultLeadFormState();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('tip')
                    ->hiddenLabel()
                    ->content('Use this desk to book wholesale orders, see the profit preview, and keep the customer follow-up dates in one place. HELOS calculates product cost from SKU cost and delivery cost from courier rates when they are available.'),
                Hidden::make('business_id'),
                TextInput::make('order_number')
                    ->label('Wholesale reference')
                    ->placeholder('Optional')
                    ->helperText('Leave blank and HELOS will create a reference for you.'),
                TextInput::make('customer_name')
                    ->label('Customer name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('customer_phone')
                    ->label('Customer phone')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('customer_location')
                    ->label('Customer location')
                    ->maxLength(255),
                DatePicker::make('order_date')
                    ->label('Order date')
                    ->default(now())
                    ->required(),
                Select::make('status')
                    ->label('Order status')
                    ->options(WholesaleOrder::statusOptions())
                    ->default(WholesaleOrder::STATUS_BOOKED)
                    ->required(),
                Select::make('payment_status')
                    ->label('Payment status')
                    ->options(WholesaleOrder::paymentStatusOptions())
                    ->default(WholesaleOrder::PAYMENT_PENDING)
                    ->required(),
                Select::make('payment_method')
                    ->label('Payment method')
                    ->options([
                        'cash' => 'Cash',
                        'bank' => 'Bank transfer',
                        'cheque' => 'Cheque',
                        'credit' => 'Credit',
                        'mixed' => 'Mixed',
                        'other' => 'Other',
                    ])
                    ->default('cash')
                    ->required(),
                Select::make('delivery_method')
                    ->label('Delivery method')
                    ->options(WholesaleOrder::deliveryMethodOptions())
                    ->default(WholesaleOrder::DELIVERY_COURIER)
                    ->live()
                    ->required(),
                TextInput::make('courier_name')
                    ->label('Courier / transport')
                    ->placeholder('Optional')
                    ->visible(fn (Get $get): bool => $get('delivery_method') !== WholesaleOrder::DELIVERY_PICKUP)
                    ->helperText('HELOS uses this to estimate delivery cost when a courier rate exists.'),
                TextInput::make('discount_amount')
                    ->label('Discount')
                    ->numeric()
                    ->prefix('LKR')
                    ->default(0)
                    ->live(),
                TextInput::make('delivery_charge_charged')
                    ->label('Delivery charge charged to customer')
                    ->numeric()
                    ->prefix('LKR')
                    ->default(0)
                    ->live(),
                TextInput::make('transport_cost_amount')
                    ->label('Transport cost')
                    ->numeric()
                    ->prefix('LKR')
                    ->default(0)
                    ->live()
                    ->helperText('Enter the real transport amount manually. Do not guess it.'),
                TextInput::make('paid_amount')
                    ->label('Paid now')
                    ->numeric()
                    ->prefix('LKR')
                    ->default(0)
                    ->live(),
                Repeater::make('line_items')
                    ->label('Order items')
                    ->defaultItems(1)
                    ->addActionLabel('Add another product')
                    ->schema([
                        Hidden::make('sku_code'),
                        Hidden::make('sku_name'),
                        Hidden::make('unit_cost'),
                        Select::make('sku_id')
                            ->label('SKU')
                            ->options(fn () => $this->wholesaleSkuOptions())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required()
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                $sku = $this->wholesaleSkuRecord($state);

                                if (! $sku instanceof Sku) {
                                    return;
                                }

                                $set('sku_code', $sku->code);
                                $set('sku_name', $sku->name);
                                $set('unit_cost', round($sku->productionCostPerUnit(), 2));

                                if ((float) ($get('unit_price') ?? 0) <= 0) {
                                    $set('unit_price', round(max((float) $sku->expected_sale_price, $sku->productionCostPerUnit() * 1.25), 2));
                                }
                            }),
                        TextInput::make('size')
                            ->label('Size / variant')
                            ->maxLength(50),
                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        TextInput::make('unit_price')
                            ->label('Selling price')
                            ->numeric()
                            ->prefix('LKR')
                            ->required(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Placeholder::make('profit_preview')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => $this->profitPreview($get))
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->statePath('data')
            ->columns(2);
    }

    public function saveWholesaleOrder(): void
    {
        $data = $this->form->getState();

        if (! $this->business instanceof Business) {
            Notification::make()
                ->title('No business found')
                ->body('Nifras needs an accessible business before a wholesale order can be saved.')
                ->danger()
                ->send();

            return;
        }

        $lineItems = $this->normalizeLineItems((array) ($data['line_items'] ?? []));

        if ($lineItems === []) {
            Notification::make()
                ->title('Add at least one item')
                ->body('Wholesale orders need at least one SKU line.')
                ->warning()
                ->send();

            return;
        }

        $grossSaleAmount = round(collect($lineItems)->sum(fn (array $item): float => $this->lineItemGrossAmount($item)), 2);
        $productCostAmount = round(collect($lineItems)->sum(fn (array $item): float => $this->lineItemCostAmount($item)), 2);
        $discountAmount = max((float) ($data['discount_amount'] ?? 0), 0);
        $deliveryChargeCharged = max((float) ($data['delivery_charge_charged'] ?? 0), 0);
        $transportCostAmount = max((float) ($data['transport_cost_amount'] ?? 0), 0);
        $netSalesAmount = round(max($grossSaleAmount + $deliveryChargeCharged - $discountAmount, 0), 2);
        $paidAmount = max((float) ($data['paid_amount'] ?? 0), 0);
        $status = $this->normaliseStatus((string) ($data['status'] ?? WholesaleOrder::STATUS_BOOKED));

        if (in_array($status, [WholesaleOrder::STATUS_DELIVERED, WholesaleOrder::STATUS_CLOSED], true) && $paidAmount <= 0) {
            $paidAmount = $netSalesAmount;
        }

        $paymentStatus = $this->normalisePaymentStatus((string) ($data['payment_status'] ?? WholesaleOrder::PAYMENT_PENDING), $paidAmount, $netSalesAmount);

        if ($paymentStatus === WholesaleOrder::PAYMENT_PAID && $paidAmount <= 0) {
            $paidAmount = $netSalesAmount;
        }

        $paidAmount = min($paidAmount, $netSalesAmount);
        $customerName = (string) ($data['customer_name'] ?? '');
        $customerPhone = (string) ($data['customer_phone'] ?? '');
        $orderDate = Carbon::parse($data['order_date'] ?? now());
        $deliveredAt = filled($data['delivered_at'] ?? null)
            ? Carbon::parse($data['delivered_at'])
            : (in_array($status, [WholesaleOrder::STATUS_DELIVERED, WholesaleOrder::STATUS_CLOSED], true) ? now() : null);
        [$nextFollowUpAt, $reorderDueAt] = $this->followUpDatesForCustomer($customerName, $customerPhone, $status, $orderDate, $deliveredAt);

        WholesaleOrder::query()->create([
            'business_id' => $this->business->id,
            'order_number' => filled($data['order_number'] ?? null)
                ? (string) $data['order_number']
                : $this->generateWholesaleOrderNumber($orderDate),
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_location' => $data['customer_location'] ?? null,
            'order_date' => $orderDate->toDateString(),
            'status' => $status,
            'payment_status' => $paymentStatus,
            'payment_method' => $data['payment_method'] ?? null,
            'delivery_method' => $data['delivery_method'] ?? null,
            'courier_name' => $data['courier_name'] ?? null,
            'line_items' => $lineItems,
            'gross_sale_amount' => $grossSaleAmount,
            'discount_amount' => $discountAmount,
            'delivery_charge_charged' => $deliveryChargeCharged,
            'courier_cost_amount' => $transportCostAmount,
            'product_cost_amount' => $productCostAmount,
            'net_sales_amount' => $netSalesAmount,
            'paid_amount' => $paidAmount,
            'gross_profit_amount' => round($netSalesAmount - $productCostAmount - $transportCostAmount, 2),
            'next_follow_up_at' => $nextFollowUpAt,
            'reorder_due_at' => $reorderDueAt,
            'delivered_at' => $deliveredAt,
            'notes' => $data['notes'] ?? null,
        ]);

        Notification::make()
            ->title('Wholesale order saved')
            ->body('HELOS saved the order, costed it, and updated the follow-up date for Nifras.')
            ->success()
            ->send();

        $this->form->fill($this->defaultWholesaleFormState());
        $this->loadWholesaleWorkspace();
    }

    public function refreshAccount(RevenuePipelineService $revenuePipeline): void
    {
        $this->loadAccount($revenuePipeline);
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    protected function getViewData(): array
    {
        return [
            'business' => $this->business,
            'pipeline' => $this->pipeline,
            'wholesaleSummary' => $this->wholesaleSummary,
            'leadSummary' => $this->leadSummary,
            'customerSummaries' => $this->customerSummaries,
            'recentWholesaleLeads' => $this->recentWholesaleLeads,
            'recentWholesaleOrders' => $this->recentWholesaleOrders,
            'hasBusiness' => $this->business instanceof Business,
            'isNifras' => static::isNifrasAccount(),
        ];
    }

    private static function isNifrasAccount(): bool
    {
        $user = Auth::user();

        return Auth::check()
            && $user instanceof User
            && strtolower((string) $user->email) === 'nifras@helos.com';
    }

    private function loadAccount(RevenuePipelineService $revenuePipeline): void
    {
        $user = Auth::user();
        $businessId = $user?->defaultBusinessId() ?: ($user?->accessibleBusinessIds()[0] ?? null);
        $this->business = $businessId ? Business::query()->find($businessId) : null;

        if (! $this->business instanceof Business) {
            $this->pipeline = [];
            $this->wholesaleSummary = [];
            $this->leadSummary = [];
            $this->customerSummaries = collect();
            $this->recentWholesaleLeads = collect();
            $this->recentWholesaleOrders = collect();

            return;
        }

        $this->pipeline = $revenuePipeline->forCurrentMonth($this->business);
        $this->loadWholesaleWorkspace();
    }

    private function loadWholesaleWorkspace(): void
    {
        if (! $this->business instanceof Business) {
            $this->wholesaleSummary = [];
            $this->leadSummary = [];
            $this->customerSummaries = collect();
            $this->recentWholesaleLeads = collect();
            $this->recentWholesaleOrders = collect();

            return;
        }

        $leads = WholesaleLead::query()
            ->forBusiness($this->business)
            ->orderByRaw('coalesce(next_follow_up_at, converted_at, created_at) desc')
            ->orderByDesc('id')
            ->get();

        $orders = WholesaleOrder::query()
            ->forBusiness($this->business)
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->get();

        $monthOrders = $orders->filter(fn (WholesaleOrder $order): bool => $order->order_date?->isCurrentMonth() ?? false);
        $openLeads = $leads->filter(fn (WholesaleLead $lead): bool => $lead->isOpen());
        $contactDueLeads = $openLeads->filter(fn (WholesaleLead $lead): bool => $lead->next_follow_up_at?->isPast() ?? false);
        $convertedLeads = $leads->filter(fn (WholesaleLead $lead): bool => $lead->isConverted());

        $this->wholesaleSummary = [
            'booked_orders' => $monthOrders->whereNotIn('status', [WholesaleOrder::STATUS_CANCELLED])->count(),
            'recognized_sales' => round($monthOrders->sum(fn (WholesaleOrder $order): float => $order->recognizedRevenueAmount()), 2),
            'gross_contribution' => round($monthOrders->sum(fn (WholesaleOrder $order): float => $order->isRecognizedRevenue() ? $order->grossProfitAmount() : 0.0), 2),
            'outstanding_collections' => round($orders->sum(fn (WholesaleOrder $order): float => $order->outstandingAmount()), 2),
            'open_collection_orders' => $orders->filter(fn (WholesaleOrder $order): bool => $order->outstandingAmount() > 0)->count(),
            'repeat_customers' => $this->repeatCustomerCount($orders),
            'average_order_value' => $monthOrders->count() > 0 ? round($monthOrders->sum(fn (WholesaleOrder $order): float => $order->netSalesAmount()) / $monthOrders->count(), 2) : 0.0,
        ];

        $this->leadSummary = [
            'open_leads' => $openLeads->count(),
            'contact_due' => $contactDueLeads->count(),
            'converted_customers' => $convertedLeads->count(),
            'due_today' => $this->leadQueueCount($leads),
            'last_touch' => $this->formatDate($leads->first()?->last_contacted_at),
        ];

        $this->recentWholesaleOrders = $orders->take(8)->values();
        $this->customerSummaries = $this->customerSummariesFor($orders)->take(8)->values();
        $this->recentWholesaleLeads = $leads->take(8)->values();
    }

    private function customerSummariesFor(Collection $orders): SupportCollection
    {
        return $orders
            ->groupBy(fn (WholesaleOrder $order): string => $order->customerKey())
            ->map(function (Collection $group): array {
                /** @var WholesaleOrder $latest */
                $latest = $group->sortByDesc('order_date')->sortByDesc('id')->first();
                $deliveredOrders = $group->filter(fn (WholesaleOrder $order): bool => $order->isRecognizedRevenue())->sortBy('delivered_at')->values();
                $history = $deliveredOrders->count() >= 2
                    ? $this->averageGapDays($deliveredOrders->pluck('delivered_at')->filter()->values())
                    : null;

                return [
                    'customer_label' => $latest?->customerLabel() ?? 'Unnamed customer',
                    'customer_name' => $latest?->customer_name,
                    'customer_phone' => $latest?->customer_phone,
                    'order_count' => $group->count(),
                    'delivered_count' => $deliveredOrders->count(),
                    'recognized_sales' => round($group->sum(fn (WholesaleOrder $order): float => $order->recognizedRevenueAmount()), 2),
                    'gross_contribution' => round($group->sum(fn (WholesaleOrder $order): float => $order->isRecognizedRevenue() ? $order->grossProfitAmount() : 0.0), 2),
                    'outstanding_amount' => round($group->sum(fn (WholesaleOrder $order): float => $order->outstandingAmount()), 2),
                    'last_purchase_at' => $this->formatDate($group->max(fn (WholesaleOrder $order) => $order->order_date)),
                    'last_status' => $latest ? WholesaleOrder::statusOptions()[$latest->status] ?? Str::headline($latest->status) : 'Unknown',
                    'next_follow_up_at' => $this->formatDate($group->sortBy(fn (WholesaleOrder $order): string => (string) ($order->next_follow_up_at?->toDateTimeString() ?? '9999-12-31 23:59:59'))->first()?->next_follow_up_at),
                    'reorder_due_at' => $this->formatDate($group->sortBy(fn (WholesaleOrder $order): string => (string) ($order->reorder_due_at?->toDateTimeString() ?? '9999-12-31 23:59:59'))->first()?->reorder_due_at),
                    'average_reorder_days' => $history,
                    'top_items' => $this->topItemsFor($group),
                ];
            })
            ->sortByDesc('outstanding_amount')
            ->sortByDesc('recognized_sales')
            ->values();
    }

    private function topItemsFor(Collection $orders): string
    {
        $items = collect();

        foreach ($orders as $order) {
            foreach ($order->lineItemLines() as $item) {
                $label = trim((string) ($item['sku_code'] ?? $item['sku_name'] ?? 'Item'));
                if ($label === '') {
                    continue;
                }

                $items->push($label);
            }
        }

        return $items->count() > 0 ? $items->unique()->take(3)->implode(', ') : 'No SKU history';
    }

    private function repeatCustomerCount(Collection $orders): int
    {
        return $orders
            ->groupBy(fn (WholesaleOrder $order): string => $order->customerKey())
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->count();
    }

    private function averageGapDays(\Illuminate\Support\Collection $dates): ?int
    {
        if ($dates->count() < 2) {
            return null;
        }

        $sorted = $dates
            ->map(fn ($date): Carbon => Carbon::parse($date))
            ->sort()
            ->values();

        $gaps = collect();

        for ($i = 1; $i < $sorted->count(); $i++) {
            $gaps->push($sorted[$i - 1]->diffInDays($sorted[$i]));
        }

        return $gaps->isEmpty() ? null : (int) round($gaps->avg());
    }

    private function formatDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    public function saveWholesaleLead(): void
    {
        if (! $this->business instanceof Business) {
            Notification::make()
                ->title('No business found')
                ->body('Nifras needs an accessible business before a wholesale lead can be saved.')
                ->danger()
                ->send();

            return;
        }

        $data = validator($this->leadData ?? [], [
            'customer_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp_phone' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:255'],
            'products_of_interest' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'max:50'],
            'status' => ['required', 'string', 'max:50'],
            'next_follow_up_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ])->validate();

        $status = $this->normaliseLeadStatus((string) ($data['status'] ?? WholesaleLead::STATUS_LEAD));
        $nextFollowUpAt = filled($data['next_follow_up_at'] ?? null)
            ? Carbon::parse($data['next_follow_up_at'])
            : $this->leadNextFollowUpDate($status);

        WholesaleLead::query()->create([
            'business_id' => $this->business->id,
            'captured_by_user_id' => Auth::id(),
            'source' => $this->normaliseLeadSource((string) ($data['source'] ?? 'owner_referral')),
            'status' => $status,
            'customer_name' => $data['customer_name'],
            'contact_name' => $data['contact_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'whatsapp_phone' => $data['whatsapp_phone'] ?? null,
            'location' => $data['location'] ?? null,
            'products_of_interest' => $data['products_of_interest'] ?? null,
            'last_contacted_at' => now(),
            'next_follow_up_at' => $nextFollowUpAt,
            'converted_at' => $status === WholesaleLead::STATUS_CUSTOMER ? now() : null,
            'notes' => $data['notes'] ?? null,
        ]);

        Notification::make()
            ->title('Wholesale lead saved')
            ->body('HELOS recorded the lead and set the next action date for Nifras.')
            ->success()
            ->send();

        $this->leadData = $this->defaultLeadFormState();
        $this->loadWholesaleWorkspace();
    }

    public function touchLead(int $leadId, string $status): void
    {
        if (! $this->business instanceof Business) {
            return;
        }

        $lead = WholesaleLead::query()
            ->forBusiness($this->business)
            ->findOrFail($leadId);

        $lead->forceFill([
            'status' => $this->normaliseLeadStatus($status),
            'last_contacted_at' => now(),
            'next_follow_up_at' => $this->leadNextFollowUpDate($status),
            'converted_at' => $status === WholesaleLead::STATUS_CUSTOMER && blank($lead->converted_at) ? now() : $lead->converted_at,
        ])->save();

        $this->loadWholesaleWorkspace();

        Notification::make()
            ->title('Lead updated')
            ->body('HELOS saved the status change and the next follow-up date.')
            ->success()
            ->send();
    }

    private function defaultLeadFormState(): array
    {
        return [
            'customer_name' => '',
            'contact_name' => '',
            'phone' => '',
            'whatsapp_phone' => '',
            'location' => '',
            'products_of_interest' => '',
            'source' => 'owner_referral',
            'status' => WholesaleLead::STATUS_LEAD,
            'next_follow_up_at' => now()->addDay()->toDateString(),
            'notes' => '',
        ];
    }

    private function leadQueueCount(Collection $leads): int
    {
        return $leads->filter(fn (WholesaleLead $lead): bool => $lead->isOpen() && ($lead->next_follow_up_at?->isPast() ?? false))->count();
    }

    private function leadNextFollowUpDate(string $status): Carbon
    {
        return match ($this->normaliseLeadStatus($status)) {
            WholesaleLead::STATUS_CONTACT_DUE => now(),
            WholesaleLead::STATUS_CONTACTED => now()->addDay(),
            WholesaleLead::STATUS_INTERESTED, WholesaleLead::STATUS_QUOTE_SHARED => now()->addDay(),
            WholesaleLead::STATUS_FOLLOW_UP_DUE, WholesaleLead::STATUS_NEGOTIATING => now()->addDay(),
            WholesaleLead::STATUS_CUSTOMER, WholesaleLead::STATUS_REORDER_DUE => now()->addDays(14),
            WholesaleLead::STATUS_DORMANT => now()->addDays(21),
            WholesaleLead::STATUS_LOST => now()->addDays(30),
            default => now()->addDay(),
        };
    }

    private function normaliseLeadStatus(string $status): string
    {
        return array_key_exists($status, WholesaleLead::statusOptions())
            ? $status
            : WholesaleLead::STATUS_LEAD;
    }

    private function normaliseLeadSource(string $source): string
    {
        return array_key_exists($source, WholesaleLead::sourceOptions())
            ? $source
            : 'other';
    }

    private function defaultWholesaleFormState(): array
    {
        return [
            'business_id' => $this->business?->id,
            'order_date' => now()->toDateString(),
            'status' => WholesaleOrder::STATUS_BOOKED,
            'payment_status' => WholesaleOrder::PAYMENT_PENDING,
            'payment_method' => 'cash',
            'delivery_method' => WholesaleOrder::DELIVERY_COURIER,
            'discount_amount' => 0,
            'delivery_charge_charged' => 0,
            'transport_cost_amount' => 0,
            'paid_amount' => 0,
            'line_items' => [
                [
                    'quantity' => 1,
                    'unit_price' => null,
                ],
            ],
        ];
    }

    private function wholesaleSkuOptions(): array
    {
        if (! $this->business instanceof Business) {
            return [];
        }

        return Sku::query()
            ->where('business_id', $this->business->id)
            ->where('active', true)
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }

    private function wholesaleSkuRecord(mixed $state): ?Sku
    {
        if (! $this->business instanceof Business || blank($state)) {
            return null;
        }

        return Sku::query()
            ->where('business_id', $this->business->id)
            ->whereKey($state)
            ->first();
    }

    private function normalizeLineItems(array $items): array
    {
        if (! $this->business instanceof Business) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sku = $this->wholesaleSkuRecord($item['sku_id'] ?? null);

            if (! $sku instanceof Sku) {
                continue;
            }

            $quantity = max((int) ($item['quantity'] ?? 1), 1);
            $unitPrice = max((float) ($item['unit_price'] ?? $sku->expected_sale_price ?? 0), 0);
            $unitCost = round($sku->productionCostPerUnit(), 2);

            $normalized[] = [
                'sku_id' => $sku->id,
                'sku_code' => $sku->code,
                'sku_name' => $sku->name,
                'size' => trim((string) ($item['size'] ?? '')),
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'unit_cost' => $unitCost,
            ];
        }

        return $normalized;
    }

    private function lineItemGrossAmount(array $item): float
    {
        $quantity = max((int) ($item['quantity'] ?? 1), 1);
        $unitPrice = max((float) ($item['unit_price'] ?? 0), 0);

        return $quantity * $unitPrice;
    }

    private function lineItemCostAmount(array $item): float
    {
        $quantity = max((int) ($item['quantity'] ?? 1), 1);
        $unitCost = max((float) ($item['unit_cost'] ?? 0), 0);

        return $quantity * $unitCost;
    }

    private function normaliseStatus(string $status): string
    {
        return array_key_exists($status, WholesaleOrder::statusOptions()) ? $status : WholesaleOrder::STATUS_BOOKED;
    }

    private function normalisePaymentStatus(string $paymentStatus, float $paidAmount, float $netSalesAmount): string
    {
        if ($paidAmount >= $netSalesAmount && $netSalesAmount > 0) {
            return WholesaleOrder::PAYMENT_PAID;
        }

        return array_key_exists($paymentStatus, WholesaleOrder::paymentStatusOptions())
            ? $paymentStatus
            : WholesaleOrder::PAYMENT_PENDING;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function followUpDatesForCustomer(string $customerName, string $customerPhone, string $status, Carbon $orderDate, ?Carbon $deliveredAt): array
    {
        if (! $this->business instanceof Business) {
            return [null, null];
        }

        if (! in_array($status, [WholesaleOrder::STATUS_DELIVERED, WholesaleOrder::STATUS_CLOSED], true)) {
            return [
                $orderDate->copy()->addDays(3)->toDateTimeString(),
                null,
            ];
        }

        $historyQuery = WholesaleOrder::query()
            ->forBusiness($this->business)
            ->where('customer_name', $customerName)
            ->whereIn('status', [WholesaleOrder::STATUS_DELIVERED, WholesaleOrder::STATUS_CLOSED]);

        if (blank($customerPhone)) {
            $historyQuery->where(function ($query): void {
                $query->whereNull('customer_phone')->orWhere('customer_phone', '');
            });
        } else {
            $historyQuery->where('customer_phone', $customerPhone);
        }

        $history = $historyQuery
            ->orderBy('delivered_at')
            ->get()
            ->pluck('delivered_at')
            ->filter()
            ->map(fn ($date): Carbon => Carbon::parse($date))
            ->values();

        $historyDays = $this->averageGapDays($history);
        $followUpDays = $historyDays !== null
            ? max(min((int) round($historyDays * 0.5), 21), 14)
            : 14;
        $reorderDays = $historyDays !== null
            ? max($historyDays, 21)
            : 21;

        $base = $deliveredAt ?? $orderDate->copy();

        return [
            $base->copy()->addDays($followUpDays)->toDateTimeString(),
            $base->copy()->addDays($reorderDays)->toDateTimeString(),
        ];
    }

    private function generateWholesaleOrderNumber(Carbon $orderDate): string
    {
        if (! $this->business instanceof Business) {
            return 'WHO-'.Str::upper($orderDate->format('Ymd')).'-'.Str::upper(Str::random(4));
        }

        $sequence = WholesaleOrder::query()
            ->forBusiness($this->business)
            ->whereDate('order_date', $orderDate->toDateString())
            ->count() + 1;

        return 'WHO-'.$orderDate->format('Ymd').'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function customerKey(string $customerName, string $customerPhone): string
    {
        return Str::lower(trim($customerName).'|'.trim($customerPhone));
    }

    private function profitPreview(Get $get): HtmlString
    {
        $items = $this->normalizeLineItems((array) ($get('line_items') ?? []));
        $grossSaleAmount = round(collect($items)->sum(fn (array $item): float => $this->lineItemGrossAmount($item)), 2);
        $productCostAmount = round(collect($items)->sum(fn (array $item): float => $this->lineItemCostAmount($item)), 2);
        $discountAmount = max((float) ($get('discount_amount') ?? 0), 0);
        $deliveryChargeCharged = max((float) ($get('delivery_charge_charged') ?? 0), 0);
        $paidAmount = max((float) ($get('paid_amount') ?? 0), 0);
        $transportCostAmount = max((float) ($get('transport_cost_amount') ?? 0), 0);
        $netSalesAmount = round(max($grossSaleAmount + $deliveryChargeCharged - $discountAmount, 0), 2);
        $outstandingAmount = round(max($netSalesAmount - $paidAmount, 0), 2);
        $grossProfitAmount = round($netSalesAmount - $productCostAmount - $transportCostAmount, 2);
        $margin = $netSalesAmount > 0 ? round(($grossProfitAmount / $netSalesAmount) * 100, 1) : 0.0;

        return new HtmlString(
            '<div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">'
            .'<div class="grid gap-2 md:grid-cols-2 xl:grid-cols-4">'
            .'<div><div class="text-xs uppercase tracking-wide text-gray-500">Gross sales</div><div class="text-base font-semibold">LKR '.number_format($grossSaleAmount, 2).'</div></div>'
            .'<div><div class="text-xs uppercase tracking-wide text-gray-500">Net sales</div><div class="text-base font-semibold">LKR '.number_format($netSalesAmount, 2).'</div></div>'
            .'<div><div class="text-xs uppercase tracking-wide text-gray-500">Profit preview</div><div class="text-base font-semibold">LKR '.number_format($grossProfitAmount, 2).'</div></div>'
            .'<div><div class="text-xs uppercase tracking-wide text-gray-500">Outstanding</div><div class="text-base font-semibold">LKR '.number_format($outstandingAmount, 2).'</div></div>'
            .'</div>'
            .'<div class="mt-3 text-xs text-gray-500">Product cost uses SKU production cost. Transport cost is entered manually so the preview matches the real freight amount.</div>'
            .'<div class="mt-2 text-xs text-gray-500">Transport cost: LKR '.number_format($transportCostAmount, 2).' • Margin: '.$margin.'%</div>'
            .'</div>'
        );
    }
}

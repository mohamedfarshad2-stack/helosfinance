<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;

class BusinessCompletenessService
{
    public function forCurrentMonth(Business $business): array
    {
        $issues = [
            'critical' => [],
            'important' => [],
            'informational' => [],
        ];

        $score = 100;

        $activeSkus = Sku::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->withCount(['recipeItems as active_raw_material_items_count' => fn ($query) => $query->where('active', true)->where('line_type', SkuRecipeItem::TYPE_RAW_MATERIAL)])
            ->get();

        $missingRecipeSkus = $activeSkus
            ->filter(fn (Sku $sku): bool => (int) ($sku->active_raw_material_items_count ?? 0) === 0)
            ->values();

        if ($missingRecipeSkus->isNotEmpty()) {
            $issues['critical'][] = $this->issue(
                'Missing SKU recipe',
                $missingRecipeSkus->count().' active SKU(s) have no active raw material recipe.',
                'Product cost and break-even can stay incomplete without the recipe.',
                'Profit, break-even, goal progress.',
                $missingRecipeSkus->take(3)->map(fn (Sku $sku): string => $sku->code ?: $sku->name ?: 'SKU')->all()
            );
            $score -= min(28, $missingRecipeSkus->count() * 9);
        }

        $missingMaterialCostSkus = $activeSkus
            ->filter(fn (Sku $sku): bool => (float) $sku->materialCostPerUnit() <= 0)
            ->values();

        if ($missingMaterialCostSkus->isNotEmpty()) {
            $issues['critical'][] = $this->issue(
                'Missing material cost',
                $missingMaterialCostSkus->count().' active SKU(s) still resolve to zero material cost.',
                'Profit and production costing can stay incomplete without material cost.',
                'Profit, break-even, and production costing.',
                $missingMaterialCostSkus->take(3)->map(fn (Sku $sku): string => $sku->code ?: $sku->name ?: 'SKU')->all()
            );
            $score -= min(24, $missingMaterialCostSkus->count() * 8);
        }

        if ($business->supportsSkuManagement()) {
            $missingStockMappings = OperationalEvent::query()
                ->where('business_id', $business->id)
                ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->whereIn('event_type', [
                    OperationalEvent::ORDER_CREATED,
                    OperationalEvent::ORDER_CONFIRMED,
                    OperationalEvent::TRACKING_NUMBER_ADDED,
                    OperationalEvent::WHOLESALE_PARCEL_SENT,
                    OperationalEvent::ORDER_DELIVERED,
                    OperationalEvent::ORDER_RETURNED,
                    OperationalEvent::ORDER_RESENT,
                    OperationalEvent::FAKE_ORDER_DETECTED,
                    OperationalEvent::SKU_PRODUCED,
                    OperationalEvent::PRODUCTION_WASTE,
                    OperationalEvent::PAYOUT_GENERATED,
                ])
                ->whereNull('sku_id')
                ->count();

            if ($missingStockMappings > 0) {
                $issues['critical'][] = $this->issue(
                    'Missing stock mapping',
                    $missingStockMappings.' operational row(s) still do not point to a SKU.',
                    'Profitability and inventory truth become weaker when stock rows are not linked.',
                    'Profit, stock holding cash, break-even.',
                    ['Operational events and stock movements']
                );
                $score -= min(24, $missingStockMappings * 8);
            }

            $zeroCostSkusUsedInOrders = OperationalEvent::query()
                ->where('business_id', $business->id)
                ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->whereIn('event_type', [
                    OperationalEvent::TRACKING_NUMBER_ADDED,
                    OperationalEvent::WHOLESALE_PARCEL_SENT,
                    OperationalEvent::ORDER_DELIVERED,
                ])
                ->whereNotNull('sku_id')
                ->with('sku')
                ->get()
                ->map(fn (OperationalEvent $event): ?Sku => $event->sku instanceof Sku ? $event->sku : null)
                ->filter(fn (?Sku $sku): bool => $sku instanceof Sku && (float) $sku->productionCostPerUnit() <= 0)
                ->unique(fn (Sku $sku): string => (string) $sku->id)
                ->values();

            if ($zeroCostSkusUsedInOrders->isNotEmpty()) {
                $issues['critical'][] = $this->issue(
                    'Zero-cost SKU used by orders',
                    $zeroCostSkusUsedInOrders->count().' SKU(s) used by current-month order rows still have zero product cost.',
                    'Product profit can be overstated when an active sales SKU has no material, labour, or product cost truth.',
                    'Profit, break-even, product contribution, and goal progress.',
                    $zeroCostSkusUsedInOrders->take(3)->map(fn (Sku $sku): string => $sku->code ?: $sku->name ?: 'SKU')->all()
                );
                $score -= min(28, $zeroCostSkusUsedInOrders->count() * 10);
            }

            $incompleteProductCostEvents = OperationalEvent::query()
                ->where('business_id', $business->id)
                ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->whereIn('event_type', [
                    OperationalEvent::TRACKING_NUMBER_ADDED,
                    OperationalEvent::WHOLESALE_PARCEL_SENT,
                ])
                ->whereNotNull('sku_id')
                ->with('sku')
                ->get()
                ->filter(function (OperationalEvent $event): bool {
                    $sku = $event->sku;

                    if (! $sku instanceof Sku) {
                        return false;
                    }

                    $economics = is_array($event->payload ?? null)
                        ? (array) data_get($event->payload, 'economics', [])
                        : [];

                    $productCostSkipped = filter_var($economics['product_cost_skipped'] ?? false, FILTER_VALIDATE_BOOL);

                    if ($productCostSkipped) {
                        return false;
                    }

                    $expectedProductCost = (float) $sku->productionCostPerUnit() * max((int) ($event->quantity ?? 1), 1);
                    $eventProductCost = (float) ($economics['product_cost_amount'] ?? $event->direct_cost_amount ?? 0);

                    return $expectedProductCost > 0 && $eventProductCost <= 0;
                })
                ->values();

            if ($incompleteProductCostEvents->isNotEmpty()) {
                $issues['critical'][] = $this->issue(
                    'Incomplete event product cost',
                    $incompleteProductCostEvents->count().' current-month dispatch or wholesale row(s) are linked to SKUs but still carry zero product cost.',
                    'Profit can be overstated when older order rows are not recalculated after product costs are fixed.',
                    'Profit, break-even, product contribution, and goal progress.',
                    $incompleteProductCostEvents->take(3)->map(fn (OperationalEvent $event): string => $event->external_id ?: 'Operational event '.$event->id)->all()
                );
                $score -= min(28, $incompleteProductCostEvents->count() * 9);
            }
        }

        $missingSupplier = Expense::query()
            ->where('business_id', $business->id)
            ->whereBetween('spent_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->where(function ($query): void {
                $query->whereNull('payee')
                    ->orWhere('payee', '');
            })
            ->count();

        if ($missingSupplier > 0) {
            $issues['important'][] = $this->issue(
                'Missing supplier name',
                $missingSupplier.' expense row(s) still do not say who was paid.',
                'Repeated payees should be selected so names stay consistent.',
                'Expense review and supplier settlements.',
                ['Expense review', 'Quick spend']
            );
            $score -= min(14, $missingSupplier * 3);
        }

        $activeEmployeesMissingSalary = Employee::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->where('pay_cycle', '!=', 'weekly_piece')
            ->where(function ($query): void {
                $query->whereNull('monthly_salary')
                    ->orWhere('monthly_salary', '<=', 0);
            })
            ->count();

        if ($activeEmployeesMissingSalary > 0) {
            $issues['important'][] = $this->issue(
                'Missing salary mapping',
                $activeEmployeesMissingSalary.' active employee(s) still have no salary amount.',
                'Fixed salary pressure needs a salary figure to stay honest. Weekly production workers are checked through production pay entries.',
                'Salary obligations and cash pressure.',
                ['Team salaries']
            );
            $score -= min(18, $activeEmployeesMissingSalary * 4);
        }

        $missingAllocations = BankTransaction::query()
            ->where('business_id', $business->id)
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->whereIn('transaction_type', ['revenue', 'cod_settlement', 'expense', 'loan', 'owner_contribution', 'owner_withdrawal'])
            ->whereNull('allocated_business_id')
            ->count();

        if ($missingAllocations > 0) {
            $issues['critical'][] = $this->issue(
                'Missing business allocation',
                $missingAllocations.' bank row(s) still need a business attached.',
                'Revenue and spend can blur across businesses without allocation.',
                'Profit, treasury, and break-even.',
                ['Bank review']
            );
            $score -= min(24, $missingAllocations * 8);
        }

        $missingTreasuryAssignments = BankTransaction::query()
            ->where('business_id', $business->id)
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->where(function ($query): void {
                $query->whereNull('money_container')
                    ->orWhere('money_container', '')
                    ->orWhere(function ($query): void {
                        $query->where('transaction_type', 'transfer')
                            ->where(function ($query): void {
                                $query->whereNull('counter_money_container')
                                    ->orWhere('counter_money_container', '');
                            });
                    });
            })
            ->count();

        if ($missingTreasuryAssignments > 0) {
            $issues['critical'][] = $this->issue(
                'Missing treasury allocation',
                $missingTreasuryAssignments.' bank row(s) still need the money container named.',
                'The treasury picture cannot stay clean when a row has no account or transfer destination.',
                'Treasury, cash pressure, and withdrawal safety.',
                ['Bank review']
            );
            $score -= min(24, $missingTreasuryAssignments * 8);
        }

        $missingMaterialSku = MaterialLedgerEntry::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->whereNull('sku_id')
            ->count();

        if ($missingMaterialSku > 0) {
            $issues['important'][] = $this->issue(
                'Missing material SKU link',
                $missingMaterialSku.' material row(s) still do not point to a SKU.',
                'Material flow is harder to read when entries are detached from products.',
                'Stock holding cash and production costing.',
                ['Material ledger']
            );
            $score -= min(16, $missingMaterialSku * 4);
        }

        $missingDueDates = Expense::query()
            ->where('business_id', $business->id)
            ->whereBetween('spent_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->whereIn('payment_status', ['partial', 'cheque_pending', 'credit_due'])
            ->whereNull('due_on')
            ->count();

        if ($missingDueDates > 0) {
            $issues['important'][] = $this->issue(
                'Missing due date',
                $missingDueDates.' expense row(s) still need a due date.',
                'Weekly obligation reminders need a date to work properly.',
                'Weekly obligations and cheque handling.',
                ['Expense review', 'Weekly reminders']
            );
            $score -= min(14, $missingDueDates * 3);
        }

        $missingLifecycleStages = $this->missingLifecycleStages($business);
        if ($missingLifecycleStages['count'] > 0) {
            $issues['informational'][] = $this->issue(
                'Missing lifecycle stages',
                $missingLifecycleStages['message'],
                'Order flow becomes easier to trust when the delivery path is visible end to end.',
                'Profitability, revenue timing, and dispatch visibility.',
                $missingLifecycleStages['examples']
            );
            $score -= min(12, $missingLifecycleStages['count'] * 3);
        }

        $settings = is_array($business->settings ?? null) ? $business->settings : [];
        $noGoal = ! filled(data_get($settings, 'goal.type'))
            && ! filled(data_get($settings, 'goal.amount'));

        if ($noGoal) {
            $issues['informational'][] = $this->issue(
                'No monthly goal set',
                'The owner has not saved a monthly goal yet.',
                'Goal progress needs a target before it can be trusted.',
                'Goal progress and fastest path guidance.',
                ['Goal engine']
            );
            $score -= 2;
        }

        $score = max(min($score, 100), 0);
        $criticalCount = count($issues['critical']);
        $importantCount = count($issues['important']);
        $informationalCount = count($issues['informational']);

        return [
            'score' => $score,
            'headline' => match (true) {
                $criticalCount > 0 => 'Some owner numbers still need validation before they are treated as reliable.',
                $importantCount > 0 => 'The numbers are readable, but a few important inputs still need attention.',
                default => 'The current month is reading cleanly enough for owner review.',
            },
            'status_label' => $criticalCount > 0 ? 'Pending Validation' : ($importantCount > 0 ? 'Estimated' : 'Verified'),
            'critical_count' => $criticalCount,
            'important_count' => $importantCount,
            'informational_count' => $informationalCount,
            'missing_information_count' => $criticalCount + $importantCount,
            'issue_count' => $criticalCount + $importantCount + $informationalCount,
            'issues' => $issues,
            'missing_counts' => [
                'recipes' => $missingRecipeSkus->count(),
                'material_costs' => $missingMaterialCostSkus->count(),
                'suppliers' => $missingSupplier,
                'salaries' => $activeEmployeesMissingSalary,
                'allocations' => $missingAllocations,
                'treasury' => $missingTreasuryAssignments,
                'materials' => $missingMaterialSku,
                'due_dates' => $missingDueDates,
                'lifecycle' => $missingLifecycleStages['count'],
                'zero_cost_skus_used_in_orders' => isset($zeroCostSkusUsedInOrders) ? $zeroCostSkusUsedInOrders->count() : 0,
                'incomplete_event_product_costs' => isset($incompleteProductCostEvents) ? $incompleteProductCostEvents->count() : 0,
            ],
        ];
    }

    private function missingLifecycleStages(Business $business): array
    {
        $stageEvents = [
            OperationalEvent::ORDER_CREATED,
            OperationalEvent::ORDER_CONFIRMED,
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
            OperationalEvent::ORDER_DELIVERED,
        ];

        $counts = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereIn('event_type', $stageEvents)
            ->selectRaw('event_type, count(*) as total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->all();

        if (array_sum($counts) <= 0) {
            return [
                'count' => 0,
                'message' => 'No order lifecycle activity has been recorded yet.',
                'examples' => ['Stock-app integration'],
            ];
        }

        $missing = [];

        if ((int) ($counts[OperationalEvent::ORDER_CREATED] ?? 0) <= 0) {
            $missing[] = 'created';
        }

        if ((int) ($counts[OperationalEvent::ORDER_CONFIRMED] ?? 0) <= 0) {
            $missing[] = 'confirmed';
        }

        $dispatchCount = (int) ($counts[OperationalEvent::TRACKING_NUMBER_ADDED] ?? 0)
            + (int) ($counts[OperationalEvent::WHOLESALE_PARCEL_SENT] ?? 0);

        if ($dispatchCount <= 0) {
            $missing[] = 'dispatch or transport';
        }

        if ((int) ($counts[OperationalEvent::ORDER_DELIVERED] ?? 0) <= 0) {
            $missing[] = 'delivered';
        }

        if (empty($missing)) {
            return [
                'count' => 0,
                'message' => 'The main order lifecycle stages are all visible for the month.',
                'examples' => [],
            ];
        }

        return [
            'count' => count($missing),
            'message' => 'Missing stages: '.implode(', ', $missing).'.',
            'examples' => array_map(static fn (string $label): string => ucfirst($label), array_slice($missing, 0, 3)),
        ];
    }

    private function issue(string $title, string $missing, string $whyItMatters, string $affected, array $examples = []): array
    {
        return [
            'title' => $title,
            'what_is_missing' => $missing,
            'why_it_matters' => $whyItMatters,
            'affected_numbers' => $affected,
            'examples' => array_values($examples),
        ];
    }
}

<x-filament-panels::page>
    <div class="grid gap-6">
        <x-filament::section>
            <form wire:submit.prevent="refreshReport">
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                    {{ $this->form }}
                    <x-filament::button type="submit" icon="heroicon-o-funnel">
                        Show dashboard
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        @if (! $business || ! $snapshot)
            <x-filament::section>
                <div class="text-sm text-gray-500">Select a business and date range to view parcel performance.</div>
            </x-filament::section>
        @else
            @php
                $metrics = is_array($snapshot->metrics) ? $snapshot->metrics : [];
                $money = fn (float|int|string|null $value): string => 'LKR '.number_format((float) $value, 2);
                $number = fn (float|int|string|null $value): string => number_format((float) $value, 0);
                $start = \Illuminate\Support\Carbon::parse($snapshot->period_start)->format('M j, Y');
                $end = \Illuminate\Support\Carbon::parse($snapshot->period_end)->format('M j, Y');
                $dispatchedValue = (float) data_get($metrics, 'dispatched_parcel_value', 0);
                $deliveredRevenue = (float) $snapshot->revenue_total;
                $currentOrderCohort = (array) data_get($metrics, 'current_order_cohort', []);
                $pendingConfirmationCount = (int) data_get($currentOrderCohort, 'pending_confirmation.count', 0);
                $pendingConfirmationValue = (float) data_get($currentOrderCohort, 'pending_confirmation.value', 0);
                $pendingValue = (float) data_get($currentOrderCohort, 'dispatched_waiting_delivery.value', data_get($metrics, 'pending_dispatch_value', 0));
                $productCosts = (float) data_get($metrics, 'production_costs', data_get($metrics, 'product_costs', 0));
                $productionPendingPay = (float) data_get($metrics, 'production_pending_pay', 0);
                $missingEstimatedProductionCosts = (float) data_get($metrics, 'missing_estimated_production_costs', 0);
                $productionCostForOwner = $productCosts + $missingEstimatedProductionCosts;
                $profitForOwner = (float) data_get($metrics, 'profit_after_estimated_production_costs', $snapshot->estimated_profit);
                $productionCostTrusted = (bool) data_get($metrics, 'production_cost_trusted', true);
                $courierCosts = (float) data_get($metrics, 'total_courier_costs', 0);
                $grossProfit = (float) data_get($metrics, 'parcel_gross_profit', 0);
                $netProfit = (float) $snapshot->estimated_profit;
                $allCosts = (float) $snapshot->cost_total + $missingEstimatedProductionCosts;
                $otherKnownCosts = max($allCosts - $productionCostForOwner, 0);
                $fixedExpenses = (float) data_get($metrics, 'fixed_expenses', 0);
                $variableExpenses = (float) data_get($metrics, 'variable_expenses', 0);
                $manualOverheadCosts = (float) data_get($metrics, 'manual_overhead_costs', 0);
                $salaryPressure = (float) data_get($metrics, 'salary_pressure', 0);
                $packagingCosts = (float) data_get($metrics, 'packaging_costs', 0);
                $initialPackagingReferenceCosts = (float) data_get($metrics, 'initial_packaging_reference_costs', 0);
                $returnPackagingCosts = (float) data_get($metrics, 'return_packaging_costs', 0);
                $resendPackagingCosts = (float) data_get($metrics, 'resend_packaging_costs', 0);
                $marketingSpend = (float) data_get($metrics, 'marketing_spend', 0);
                $bankPaymentCharges = (float) data_get($metrics, 'bank_payment_charges', 0);
                $bankPaymentChargeExpenses = (float) data_get($metrics, 'bank_payment_charge_expenses', 0);
                $bankPaymentChargeBankRows = (float) data_get($metrics, 'bank_payment_charge_bank_rows', 0);
                $toSettle = (float) data_get($metrics, 'to_settle', 0);
                $companyCostsEntered = $manualOverheadCosts + $salaryPressure + $bankPaymentChargeBankRows;
                $deliveredCount = (int) data_get($metrics, 'order_counts.delivered', 0);
                $pendingCount = (int) data_get($currentOrderCohort, 'dispatched_waiting_delivery.count', data_get($metrics, 'pending_dispatch_count', 0));
                $returnedCount = (int) data_get($metrics, 'order_counts.returned', 0);
                $returnCourierCosts = (float) data_get($metrics, 'return_courier_costs', 0);
                $averageReturnCourierCost = $returnedCount > 0 ? $returnCourierCosts / $returnedCount : 0;
                $dispatchCount = (int) data_get($metrics, 'dispatched_parcel_count', 0);
                $deliveredDispatchCohorts = (array) data_get($metrics, 'delivered_dispatch_cohorts', data_get($metrics, 'delivered_cohorts', []));
                $currentMonthDeliveredCount = (int) data_get($deliveredDispatchCohorts, 'dispatched_this_period.count', data_get($deliveredDispatchCohorts, 'confirmed_this_month.count', 0));
                $currentMonthDeliveredValue = (float) data_get($deliveredDispatchCohorts, 'dispatched_this_period.value', data_get($deliveredDispatchCohorts, 'confirmed_this_month.value', 0));
                $carryoverDeliveredCount = (int) data_get($deliveredDispatchCohorts, 'dispatched_before_period.count', data_get($deliveredDispatchCohorts, 'carryover_from_earlier_months.count', 0));
                $carryoverDeliveredValue = (float) data_get($deliveredDispatchCohorts, 'dispatched_before_period.value', data_get($deliveredDispatchCohorts, 'carryover_from_earlier_months.value', 0));
                $missingDispatchDeliveredCount = (int) data_get($deliveredDispatchCohorts, 'dispatch_missing.count', data_get($deliveredDispatchCohorts, 'confirmation_missing.count', 0));
                $missingDispatchDeliveredValue = (float) data_get($deliveredDispatchCohorts, 'dispatch_missing.value', data_get($deliveredDispatchCohorts, 'confirmation_missing.value', 0));
                $invalidDispatchDeliveredCount = (int) data_get($deliveredDispatchCohorts, 'invalid_dispatch_sequence.count', data_get($deliveredDispatchCohorts, 'invalid_confirmation_sequence.count', 0));
                $invalidDispatchDeliveredValue = (float) data_get($deliveredDispatchCohorts, 'invalid_dispatch_sequence.value', data_get($deliveredDispatchCohorts, 'invalid_confirmation_sequence.value', 0));
                $unknownMonthDeliveredCount = $missingDispatchDeliveredCount + $invalidDispatchDeliveredCount;
                $unknownMonthDeliveredValue = $missingDispatchDeliveredValue + $invalidDispatchDeliveredValue;
                $currentMonthDeliveredCourierCosts = (float) data_get($deliveredDispatchCohorts, 'dispatched_this_period.courier_cost', 0);
                $ownerDeliveredRevenue = $currentMonthDeliveredValue;
                $ownerDeliveredCount = $currentMonthDeliveredCount;
                $deliveredDataCheckValue = max($deliveredRevenue - $ownerDeliveredRevenue, 0);
                $deliveredDataCheckCount = max($deliveredCount - $ownerDeliveredCount, 0);
                $deliveryRate = $dispatchCount > 0 ? min(100, max(0, ($ownerDeliveredCount / $dispatchCount) * 100)) : 0;
                $pendingRate = $dispatchCount > 0 ? min(100, max(0, ($pendingCount / $dispatchCount) * 100)) : 0;
                $returnRate = $dispatchCount > 0 ? min(100, max(0, ($returnedCount / $dispatchCount) * 100)) : 0;
                $ownerCourierCosts = $currentMonthDeliveredCourierCosts + $returnCourierCosts;
                $salesAfterDeliveryCourier = $ownerDeliveredRevenue - $currentMonthDeliveredCourierCosts;
                $deliveredAfterCourier = $ownerDeliveredRevenue - $ownerCourierCosts;
                $grossAfterProduction = $deliveredAfterCourier - $productionCostForOwner;
                $otherParcelAdjustments = (float) $snapshot->leakage_total - (float) data_get($metrics, 'recovered_value', 0);
                $profitForOwner = $grossAfterProduction - $companyCostsEntered - $otherParcelAdjustments;
                $otherParcelAdjustmentLabel = $otherParcelAdjustments >= 0 ? 'Other parcel losses / adjustments' : 'Recoveries reducing costs';
                $profitTrustNote = $productionCostTrusted
                    ? 'Not final company net profit. HELOS can only subtract overheads, salaries, marketing, utilities, rent, expenses, leakage and recoveries that are entered.'
                    : 'Not fully trusted until Nifras enters production data for this period.';
                $profitStatementRows = [
                    [
                        'label' => 'Verified period delivered sales',
                        'amount' => $ownerDeliveredRevenue,
                        'display' => $money($ownerDeliveredRevenue),
                        'note' => $number($ownerDeliveredCount).' parcel(s) dispatched in this period and delivered in this period. This is the owner revenue input.',
                        'tone' => 'text-emerald-700 dark:text-emerald-300',
                        'icon' => 'heroicon-o-check-circle',
                    ],
                    [
                        'label' => 'Delivery courier cost',
                        'amount' => -$currentMonthDeliveredCourierCosts,
                        'display' => '- '.$money($currentMonthDeliveredCourierCosts),
                        'note' => 'Courier cost attached to verified delivered parcels only.',
                        'tone' => 'text-orange-700 dark:text-orange-300',
                        'icon' => 'heroicon-o-truck',
                    ],
                    [
                        'label' => 'Sales after delivery courier',
                        'amount' => $salesAfterDeliveryCourier,
                        'display' => $money($salesAfterDeliveryCourier),
                        'note' => 'Verified delivered sales minus delivery courier cost. Return loss is shown separately below.',
                        'tone' => $salesAfterDeliveryCourier >= 0 ? 'text-cyan-700 dark:text-cyan-300' : 'text-rose-700 dark:text-rose-300',
                        'icon' => 'heroicon-o-banknotes',
                        'total' => true,
                    ],
                    [
                        'label' => 'Return courier loss',
                        'amount' => -$returnCourierCosts,
                        'display' => '- '.$money($returnCourierCosts),
                        'note' => $returnedCount > 0
                            ? $number($returnedCount).' returned parcel(s) x '.$money($averageReturnCourierCost).' average return charge.'
                            : 'Courier loss from returned parcels in this selected period.',
                        'tone' => 'text-rose-700 dark:text-rose-300',
                        'icon' => 'heroicon-o-arrow-uturn-left',
                    ],
                    [
                        'label' => 'Net parcel sales after courier',
                        'amount' => $deliveredAfterCourier,
                        'display' => $money($deliveredAfterCourier),
                        'note' => 'Verified delivered sales minus delivery courier and return courier loss.',
                        'tone' => $deliveredAfterCourier >= 0 ? 'text-cyan-700 dark:text-cyan-300' : 'text-rose-700 dark:text-rose-300',
                        'icon' => 'heroicon-o-calculator',
                        'total' => true,
                    ],
                    [
                        'label' => 'Production cost',
                        'amount' => -$productionCostForOwner,
                        'display' => '- '.$money($productionCostForOwner),
                        'note' => $productionCostTrusted ? 'Recorded production entries.' : 'Recorded production plus missing estimate until Nifras enters daily production.',
                        'tone' => 'text-violet-700 dark:text-violet-300',
                        'icon' => 'heroicon-o-cube',
                    ],
                    [
                        'label' => 'Gross profit after production',
                        'amount' => $grossAfterProduction,
                        'display' => $money($grossAfterProduction),
                        'note' => 'Sales after courier minus production cost.',
                        'tone' => $grossAfterProduction >= 0 ? 'text-lime-700 dark:text-lime-300' : 'text-rose-700 dark:text-rose-300',
                        'icon' => 'heroicon-o-arrow-trending-up',
                        'total' => true,
                    ],
                    [
                        'label' => 'Company costs entered',
                        'amount' => -$companyCostsEntered,
                        'display' => '- '.$money($companyCostsEntered),
                        'note' => 'Expenses, staff salary pressure, and bank-review-only charges.',
                        'tone' => 'text-slate-700 dark:text-slate-300',
                        'icon' => 'heroicon-o-building-office-2',
                    ],
                    [
                        'label' => $otherParcelAdjustmentLabel,
                        'amount' => -$otherParcelAdjustments,
                        'display' => ($otherParcelAdjustments >= 0 ? '- ' : '+ ').$money(abs($otherParcelAdjustments)),
                        'note' => 'Leakage, resend costs, recoveries, and any remaining event costs not listed above.',
                        'tone' => $otherParcelAdjustments >= 0 ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300',
                        'icon' => $otherParcelAdjustments >= 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-arrow-path',
                    ],
                    [
                        'label' => 'Estimated profit',
                        'amount' => $profitForOwner,
                        'display' => $money($profitForOwner),
                        'note' => $profitTrustNote,
                        'tone' => $profitForOwner >= 0 && $productionCostTrusted ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300',
                        'icon' => $profitForOwner >= 0 && $productionCostTrusted ? 'heroicon-o-trophy' : 'heroicon-o-exclamation-circle',
                        'final' => true,
                    ],
                ];
                $profitTone = $profitForOwner >= 0 ? 'emerald' : 'rose';
                $profitLabel = 'Estimated profit from known costs';
                $profitHelper = $productionCostTrusted ? 'Delivered sales minus recorded costs' : 'Includes estimated missing production cost';
                $decisionText = ! $productionCostTrusted
                    ? 'Production cost is not fully entered yet. HELOS is reducing owner profit using estimated missing production cost until daily entries catch up.'
                    : ($profitForOwner >= 0
                        ? 'The selected period is positive after known HELOS costs. It is not final company net profit until every company expense is entered.'
                        : 'The selected period is negative after known HELOS costs. This can happen when delivered sales are lower than recorded courier, production, salary, overhead, expense, leakage, and estimated missing production costs.');
                $heroStats = [
                    [
                        'label' => 'Pending confirmation',
                        'value' => $money($pendingConfirmationValue),
                        'helper' => $number($pendingConfirmationCount).' order(s) need CSR action',
                        'icon' => 'heroicon-o-phone',
                        'style' => 'from-fuchsia-500 to-violet-500',
                    ],
                    [
                        'label' => 'Verified delivered sales',
                        'value' => $money($ownerDeliveredRevenue),
                        'helper' => 'Courier is deducted below',
                        'icon' => 'heroicon-o-check-circle',
                        'style' => 'from-emerald-500 to-teal-500',
                    ],
                    [
                        'label' => 'Dispatched awaiting delivery',
                        'value' => $money($pendingValue),
                        'helper' => $number($pendingCount).' parcel(s) not revenue yet',
                        'icon' => 'heroicon-o-clock',
                        'style' => 'from-amber-400 to-orange-500',
                    ],
                    [
                        'label' => $profitLabel,
                        'value' => $money($profitForOwner),
                        'helper' => $profitHelper,
                        'icon' => 'heroicon-o-arrow-trending-up',
                        'style' => $profitForOwner >= 0 && $productionCostTrusted ? 'from-lime-400 to-emerald-500' : 'from-rose-500 to-red-500',
                    ],
                ];
                $rolePanels = [
                    [
                        'title' => 'Owner view',
                        'kicker' => 'Decision',
                        'value' => ! $productionCostTrusted ? 'Profit not trusted yet' : ($profitForOwner >= 0 ? 'Protect profit' : 'Recover margin'),
                        'body' => $decisionText,
                        'icon' => 'heroicon-o-sparkles',
                        'style' => 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100',
                    ],
                    [
                        'title' => 'Manager view',
                        'kicker' => 'Team focus',
                        'value' => $number($pendingConfirmationCount).' to confirm · '.$number($pendingCount).' with courier',
                        'body' => 'Push CSR staff to confirm genuine pending orders, then follow dispatched parcels until delivery and reduce returns.',
                        'icon' => 'heroicon-o-user-group',
                        'style' => 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100',
                    ],
                    [
                        'title' => 'Finance view',
                        'kicker' => 'Cost control',
                        'value' => $money($companyCostsEntered + $ownerCourierCosts + $productionCostForOwner),
                        'body' => 'Owner costs are split into expenses, salaries, courier, packaging, marketing, bank charges, recorded production, and estimated missing production. Final profit is trusted only when entries are complete.',
                        'icon' => 'heroicon-o-banknotes',
                        'style' => 'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-950 dark:border-fuchsia-900 dark:bg-fuchsia-950/30 dark:text-fuchsia-100',
                    ],
                ];
                $cards = [
                    [
                        'label' => 'Pending confirmation',
                        'count' => $number($pendingConfirmationCount).' orders',
                        'value' => $money($pendingConfirmationValue),
                        'hint' => 'Current Stock App pending orders for the selected order-date range. CSR must call and record the real result.',
                        'icon' => 'heroicon-o-phone',
                        'style' => 'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-950 dark:border-fuchsia-900 dark:bg-fuchsia-950/30 dark:text-fuchsia-100',
                    ],
                    [
                        'label' => 'Dispatched this period',
                        'count' => $number($dispatchCount).' parcels',
                        'value' => $money($dispatchedValue),
                        'hint' => 'Parcel value sent to courier during this period. This is not revenue yet.',
                        'icon' => 'heroicon-o-truck',
                        'style' => 'border-cyan-200 bg-cyan-50 text-cyan-950 dark:border-cyan-900 dark:bg-cyan-950/30 dark:text-cyan-100',
                    ],
                    [
                        'label' => 'Company costs entered',
                        'count' => 'Expenses + staff salary + bank review charges',
                        'value' => $money($companyCostsEntered),
                        'hint' => 'Costs entered in HELOS for running the company: expenses, active staff salaries, and bank charges classified in Bank Review.',
                        'icon' => 'heroicon-o-building-office-2',
                        'style' => 'border-slate-200 bg-slate-50 text-slate-950 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100',
                        'action_label' => 'Open expenses',
                        'action_url' => \App\Filament\Resources\ExpenseResource::getUrl('index'),
                    ],
                    [
                        'label' => 'Expenses entered',
                        'count' => $money($fixedExpenses).' fixed / '.$money($variableExpenses).' variable',
                        'value' => $money($manualOverheadCosts),
                        'hint' => 'Rent, marketing, utilities, supplier bills, admin costs, payment fees, and other expenses entered in HELOS.',
                        'icon' => 'heroicon-o-receipt-percent',
                        'style' => 'border-blue-200 bg-blue-50 text-blue-950 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-100',
                        'action_label' => 'Open expenses',
                        'action_url' => \App\Filament\Resources\ExpenseResource::getUrl('index'),
                    ],
                    [
                        'label' => 'Packaging cost',
                        'count' => $money($initialPackagingReferenceCosts).' parcel / '.$money($returnPackagingCosts + $resendPackagingCosts).' return-resend',
                        'value' => $money($packagingCosts),
                        'hint' => 'Packaging from SKU cost references plus return and resend packaging recorded on parcel events. Shown separately for owner clarity; HELOS avoids subtracting it twice.',
                        'icon' => 'heroicon-o-cube',
                        'style' => 'border-teal-200 bg-teal-50 text-teal-950 dark:border-teal-900 dark:bg-teal-950/30 dark:text-teal-100',
                        'action_label' => 'Open products',
                        'action_url' => \App\Filament\Resources\SkuResource::getUrl('index'),
                    ],
                    [
                        'label' => 'Marketing cost',
                        'count' => 'Expense rows tagged marketing',
                        'value' => $money($marketingSpend),
                        'hint' => 'Marketing and ad spend entered as expenses for this period. This is already included inside expenses entered.',
                        'icon' => 'heroicon-o-megaphone',
                        'style' => 'border-pink-200 bg-pink-50 text-pink-950 dark:border-pink-900 dark:bg-pink-950/30 dark:text-pink-100',
                        'action_label' => 'Open expenses',
                        'action_url' => \App\Filament\Resources\ExpenseResource::getUrl('index'),
                    ],
                    [
                        'label' => 'Bank/payment charges',
                        'count' => $money($bankPaymentChargeExpenses).' expenses / '.$money($bankPaymentChargeBankRows).' bank review',
                        'value' => $money($bankPaymentCharges),
                        'hint' => 'Payment gateway, COD handling, and bank charge rows. Expense rows are already in expenses; bank-review-only charges are added to owner cost.',
                        'icon' => 'heroicon-o-credit-card',
                        'style' => 'border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100',
                        'action_label' => 'Open bank review',
                        'action_url' => \App\Filament\Resources\BankTransactionResource::getUrl('index'),
                    ],
                    [
                        'label' => 'Staff salary pressure',
                        'count' => 'Active staff monthly salaries',
                        'value' => $money($salaryPressure),
                        'hint' => 'Salary pressure from active employees. Piece-work production pay is shown separately under recorded production.',
                        'icon' => 'heroicon-o-users',
                        'style' => 'border-purple-200 bg-purple-50 text-purple-950 dark:border-purple-900 dark:bg-purple-950/30 dark:text-purple-100',
                        'action_label' => 'Open staff',
                        'action_url' => \App\Filament\Resources\EmployeeResource::getUrl('index'),
                    ],
                    [
                        'label' => 'Recorded production cost',
                        'count' => $money($productionPendingPay).' pending piece-pay',
                        'value' => $money($productCosts),
                        'hint' => 'This is the real production cost entered in HELOS from daily production rows. If Nifras has not entered work, this stays zero.',
                        'icon' => 'heroicon-o-clipboard-document-check',
                        'style' => $productCosts > 0
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100'
                            : 'border-gray-200 bg-gray-50 text-gray-950 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100',
                        'action_label' => 'Open production',
                        'action_url' => \App\Filament\Resources\ProductionEntryResource::getUrl('index'),
                    ],
                    [
                        'label' => 'Estimated missing production',
                        'count' => $missingEstimatedProductionCosts > 0 ? 'Used only until daily entries catch up' : 'No missing estimate needed',
                        'value' => $money($missingEstimatedProductionCosts),
                        'hint' => $missingEstimatedProductionCosts > 0
                            ? 'HELOS is temporarily reducing owner profit by this estimate because production entries are missing.'
                            : 'Recorded production entries are enough for this period.',
                        'icon' => 'heroicon-o-exclamation-triangle',
                        'style' => $missingEstimatedProductionCosts > 0
                            ? 'border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100'
                            : 'border-lime-200 bg-lime-50 text-lime-950 dark:border-lime-900 dark:bg-lime-950/30 dark:text-lime-100',
                        'action_label' => 'Open production',
                        'action_url' => \App\Filament\Resources\ProductionEntryResource::getUrl('index'),
                    ],
                    [
                        'label' => 'Courier costs',
                        'count' => $number($ownerDeliveredCount).' delivered / '.$number($returnedCount).' returned',
                        'value' => $money($ownerCourierCosts),
                        'hint' => $money($currentMonthDeliveredCourierCosts).' verified delivery courier + '.$money($returnCourierCosts).' return courier. Return average: '.$money($averageReturnCourierCost).' per returned parcel.',
                        'icon' => 'heroicon-o-map-pin',
                        'style' => 'border-orange-200 bg-orange-50 text-orange-950 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-100',
                        'action_label' => 'Open courier setup',
                        'action_url' => \App\Filament\Resources\CourierRateResource::getUrl('index'),
                    ],
                    [
                        'label' => 'Costs still to settle',
                        'count' => 'Unpaid entered expenses',
                        'value' => $money($toSettle),
                        'hint' => 'Expenses entered in HELOS that are not fully paid yet. This affects cash planning, not delivered revenue.',
                        'icon' => 'heroicon-o-clock',
                        'style' => $toSettle > 0
                            ? 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100'
                            : 'border-lime-200 bg-lime-50 text-lime-950 dark:border-lime-900 dark:bg-lime-950/30 dark:text-lime-100',
                        'action_label' => 'Open unpaid expenses',
                        'action_url' => \App\Filament\Resources\ExpenseResource::getUrl('index'),
                    ],
                    [
                        'label' => 'Dispatched awaiting delivery',
                        'count' => $number($pendingCount).' parcels',
                        'value' => $money($pendingValue),
                        'hint' => 'The team must push these parcels toward successful delivery.',
                        'icon' => 'heroicon-o-clock',
                        'style' => 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100',
                    ],
                    [
                        'label' => 'This period dispatches delivered',
                        'count' => $number($currentMonthDeliveredCount).' parcels',
                        'value' => $money($currentMonthDeliveredValue),
                        'hint' => 'Parcels dispatched in this selected period and delivered in this selected period.',
                        'icon' => 'heroicon-o-calendar-days',
                        'style' => 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100',
                    ],
                    [
                        'label' => 'Earlier dispatches delivered',
                        'count' => $number($carryoverDeliveredCount).' parcels',
                        'value' => $money($carryoverDeliveredValue),
                        'hint' => 'Parcels dispatched before this selected period but delivered now.',
                        'icon' => 'heroicon-o-arrow-path',
                        'style' => 'border-indigo-200 bg-indigo-50 text-indigo-950 dark:border-indigo-900 dark:bg-indigo-950/30 dark:text-indigo-100',
                    ],
                    [
                        'label' => 'Delivered rows not used for owner profit',
                        'count' => $number($deliveredDataCheckCount).' parcels',
                        'value' => $money($deliveredDataCheckValue),
                        'hint' => 'Delivered status rows outside the verified period-dispatch rule. HELOS keeps them visible for audit, but does not use them as the owner profit revenue input.',
                        'icon' => 'heroicon-o-exclamation-triangle',
                        'style' => $deliveredDataCheckValue > 0
                            ? 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100'
                            : 'border-lime-200 bg-lime-50 text-lime-950 dark:border-lime-900 dark:bg-lime-950/30 dark:text-lime-100',
                    ],
                    [
                        'label' => $profitLabel,
                        'count' => $profitHelper,
                        'value' => $money($profitForOwner),
                        'hint' => $profitTrustNote,
                        'icon' => $profitForOwner >= 0 && $productionCostTrusted ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-exclamation-triangle',
                        'style' => $profitForOwner >= 0 && $productionCostTrusted
                            ? 'border-lime-200 bg-lime-50 text-lime-950 dark:border-lime-900 dark:bg-lime-950/30 dark:text-lime-100'
                            : 'border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100',
                    ],
                ];
                $cardsByLabel = collect($cards)->keyBy('label');
                $dashboardGroups = [
                    [
                        'title' => 'Revenue',
                        'label' => 'Verified period delivered sales used for owner profit',
                        'value' => $money($ownerDeliveredRevenue),
                        'helper' => $number($ownerDeliveredCount).' parcel(s) dispatched and delivered in this period',
                        'icon' => 'heroicon-o-banknotes',
                        'style' => 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100',
                        'accent' => 'from-emerald-500 to-teal-500',
                        'open' => false,
                        'details' => array_values(array_filter([
                            $cardsByLabel->get('This period dispatches delivered'),
                            $cardsByLabel->get('Earlier dispatches delivered'),
                            $cardsByLabel->get('Delivered rows not used for owner profit'),
                        ])),
                    ],
                    [
                        'title' => 'Parcel movement',
                        'label' => 'Dispatched, pending, and courier pressure',
                        'value' => $money($dispatchedValue),
                        'helper' => $number($dispatchCount).' dispatched parcels',
                        'icon' => 'heroicon-o-truck',
                        'style' => 'border-cyan-200 bg-cyan-50 text-cyan-950 dark:border-cyan-900 dark:bg-cyan-950/30 dark:text-cyan-100',
                        'accent' => 'from-cyan-500 to-sky-500',
                        'open' => false,
                        'details' => array_values(array_filter([
                            $cardsByLabel->get('Dispatched this period'),
                            $cardsByLabel->get('Pending confirmation'),
                            $cardsByLabel->get('Dispatched awaiting delivery'),
                            $cardsByLabel->get('Courier costs'),
                        ])),
                    ],
                    [
                        'title' => 'Company costs entered',
                        'label' => 'Total company costs HELOS is subtracting',
                        'value' => $money($companyCostsEntered),
                        'helper' => 'Expenses + salaries + bank-review-only charges',
                        'icon' => 'heroicon-o-building-office-2',
                        'style' => 'border-slate-200 bg-slate-50 text-slate-950 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100',
                        'accent' => 'from-slate-700 to-gray-500',
                        'open' => false,
                        'details' => array_values(array_filter([
                            $cardsByLabel->get('Expenses entered'),
                            $cardsByLabel->get('Marketing cost'),
                            $cardsByLabel->get('Bank/payment charges'),
                            $cardsByLabel->get('Staff salary pressure'),
                            $cardsByLabel->get('Costs still to settle'),
                        ])),
                    ],
                    [
                        'title' => 'Production and packaging',
                        'label' => 'Factory cost entered or estimated',
                        'value' => $money($productionCostForOwner),
                        'helper' => $productionCostTrusted ? 'Production entries are trusted' : 'Daily entries missing - estimate is used',
                        'icon' => 'heroicon-o-cube',
                        'style' => $productionCostTrusted
                            ? 'border-violet-200 bg-violet-50 text-violet-950 dark:border-violet-900 dark:bg-violet-950/30 dark:text-violet-100'
                            : 'border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100',
                        'accent' => $productionCostTrusted ? 'from-violet-500 to-fuchsia-500' : 'from-rose-500 to-orange-500',
                        'open' => ! $productionCostTrusted,
                        'details' => array_values(array_filter([
                            $cardsByLabel->get('Recorded production cost'),
                            $cardsByLabel->get('Estimated missing production'),
                            $cardsByLabel->get('Packaging cost'),
                        ])),
                    ],
                    [
                        'title' => 'Profit',
                        'label' => 'Owner result after known costs',
                        'value' => $money($profitForOwner),
                        'helper' => $profitHelper,
                        'icon' => $profitForOwner >= 0 && $productionCostTrusted ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-exclamation-triangle',
                        'style' => $profitForOwner >= 0 && $productionCostTrusted
                            ? 'border-lime-200 bg-lime-50 text-lime-950 dark:border-lime-900 dark:bg-lime-950/30 dark:text-lime-100'
                            : 'border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100',
                        'accent' => $profitForOwner >= 0 && $productionCostTrusted ? 'from-lime-500 to-emerald-500' : 'from-rose-500 to-red-500',
                        'open' => false,
                        'details' => array_values(array_filter([
                            $cardsByLabel->get($profitLabel),
                        ])),
                    ],
                ];
                $bridge = [
                    ['label' => 'Verified delivered sales', 'value' => $ownerDeliveredRevenue, 'color' => 'bg-emerald-500'],
                    ['label' => 'Recorded production cost', 'value' => -$productCosts, 'color' => 'bg-violet-500'],
                    ['label' => 'Estimated missing production', 'value' => -$missingEstimatedProductionCosts, 'color' => 'bg-rose-500'],
                    ['label' => 'Other known HELOS costs', 'value' => -$otherKnownCosts, 'color' => 'bg-orange-500'],
                    ['label' => $profitLabel, 'value' => $profitForOwner, 'color' => $profitForOwner >= 0 && $productionCostTrusted ? 'bg-lime-500' : 'bg-rose-500'],
                    ['label' => 'Parcel gross before overhead', 'value' => $grossProfit, 'color' => $grossProfit >= 0 ? 'bg-cyan-500' : 'bg-rose-400'],
                ];
                $maxBridge = max(abs($ownerDeliveredRevenue), abs($allCosts), abs($profitForOwner), abs($grossProfit), 1);
            @endphp

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm ring-1 ring-black/5 dark:border-gray-800 dark:bg-gray-950 dark:ring-white/5">
                <div class="border-b border-gray-200 bg-gradient-to-r from-gray-950 via-cyan-950 to-emerald-950 px-4 py-3 text-white dark:border-gray-800">
                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div class="min-w-0">
                            <div class="text-xs font-black uppercase tracking-wide text-cyan-100">Owner parcel dashboard</div>
                            <div class="truncate text-xl font-black">{{ $business->name }}</div>
                        </div>
                        <div class="rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-bold text-cyan-100">
                            {{ $start }} to {{ $end }}
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 bg-gradient-to-br from-slate-50 via-white to-cyan-50 p-4 xl:grid-cols-[0.95fr_1.35fr_0.9fr] dark:from-gray-950 dark:via-gray-950 dark:to-cyan-950/20">
                    <div class="rounded-2xl bg-gradient-to-br from-gray-950 via-slate-900 to-rose-950 p-4 text-white shadow-lg">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-black uppercase tracking-wide text-white/60">Estimated profit</div>
                                <div class="mt-2 text-3xl font-black leading-tight {{ $profitForOwner >= 0 && $productionCostTrusted ? 'text-emerald-200' : 'text-rose-200' }}">{{ $money($profitForOwner) }}</div>
                            </div>
                            <div class="rounded-xl bg-white/10 p-2">
                                <x-filament::icon :icon="$profitForOwner >= 0 && $productionCostTrusted ? 'heroicon-o-trophy' : 'heroicon-o-exclamation-triangle'" class="h-6 w-6" />
                            </div>
                        </div>
                        <div class="mt-3 text-xs font-semibold leading-5 text-white/70">{{ $profitTrustNote }}</div>
                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <div class="rounded-xl border border-white/10 bg-white/10 p-3">
                                <div class="text-xs font-black uppercase text-emerald-100">Revenue</div>
                                <div class="mt-1 text-lg font-black">{{ $money($ownerDeliveredRevenue) }}</div>
                            </div>
                            <div class="rounded-xl border border-white/10 bg-white/10 p-3">
                                <div class="text-xs font-black uppercase text-cyan-100">After courier</div>
                                <div class="mt-1 text-lg font-black">{{ $money($deliveredAfterCourier) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ([
                            ['label' => 'Delivered sales', 'value' => $money($ownerDeliveredRevenue), 'helper' => $number($ownerDeliveredCount).' parcels', 'icon' => 'heroicon-o-check-circle', 'style' => 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100'],
                            ['label' => 'Delivery courier', 'value' => $money($currentMonthDeliveredCourierCosts), 'helper' => 'Delivered only', 'icon' => 'heroicon-o-truck', 'style' => 'border-orange-200 bg-orange-50 text-orange-950 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-100'],
                            ['label' => 'Return courier', 'value' => $money($returnCourierCosts), 'helper' => $number($returnedCount).' returns x '.$money($averageReturnCourierCost), 'icon' => 'heroicon-o-arrow-uturn-left', 'style' => 'border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100'],
                            ['label' => 'Production', 'value' => $money($productionCostForOwner), 'helper' => $productionCostTrusted ? 'Recorded' : 'Estimate used', 'icon' => 'heroicon-o-cube', 'style' => $productionCostTrusted ? 'border-violet-200 bg-violet-50 text-violet-950 dark:border-violet-900 dark:bg-violet-950/30 dark:text-violet-100' : 'border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100'],
                            ['label' => 'Company costs', 'value' => $money($companyCostsEntered), 'helper' => 'Expenses + salary', 'icon' => 'heroicon-o-building-office-2', 'style' => 'border-slate-200 bg-slate-50 text-slate-950 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100'],
                            ['label' => 'Pending confirmation', 'value' => $money($pendingConfirmationValue), 'helper' => $number($pendingConfirmationCount).' orders', 'icon' => 'heroicon-o-phone', 'style' => 'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-950 dark:border-fuchsia-900 dark:bg-fuchsia-950/30 dark:text-fuchsia-100'],
                            ['label' => 'Awaiting delivery', 'value' => $money($pendingValue), 'helper' => $number($pendingCount).' dispatched parcels', 'icon' => 'heroicon-o-clock', 'style' => 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100'],
                        ] as $tile)
                            <div class="rounded-xl border p-3 shadow-sm {{ $tile['style'] }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="text-xs font-black uppercase tracking-wide opacity-70">{{ $tile['label'] }}</div>
                                    <div class="rounded-lg bg-white/75 p-1.5 shadow-sm dark:bg-gray-950/50">
                                        <x-filament::icon :icon="$tile['icon']" class="h-4 w-4" />
                                    </div>
                                </div>
                                <div class="mt-2 text-2xl font-black leading-tight">{{ $tile['value'] }}</div>
                                <div class="mt-1 truncate text-xs font-bold opacity-75">{{ $tile['helper'] }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                        <div class="text-xs font-black uppercase tracking-wide text-gray-500">Parcel conversion</div>
                        <div class="mt-1 text-sm font-black text-gray-950 dark:text-white">Dispatched value status</div>
                        <div class="mt-4 grid gap-3">
                            @foreach ([
                                ['label' => 'Delivered', 'rate' => $deliveryRate, 'color' => 'bg-emerald-500'],
                                ['label' => 'Pending', 'rate' => $pendingRate, 'color' => 'bg-amber-400'],
                                ['label' => 'Returned', 'rate' => $returnRate, 'color' => 'bg-rose-500'],
                            ] as $bar)
                                <div>
                                    <div class="flex justify-between text-xs font-black text-gray-600 dark:text-gray-300">
                                        <span>{{ $bar['label'] }}</span><span>{{ number_format($bar['rate'], 1) }}%</span>
                                    </div>
                                    <div class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                                        <div class="h-full rounded-full {{ $bar['color'] }}" style="width: {{ $bar['rate'] }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 rounded-xl border border-cyan-200 bg-cyan-50 p-3 text-xs font-semibold leading-5 text-cyan-950 dark:border-cyan-900 dark:bg-cyan-950/30 dark:text-cyan-100">
                            Delivered is revenue. Pending is opportunity. Returned is loss pressure.
                        </div>
                    </div>
                </div>

                <div class="grid gap-2 border-t border-gray-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5 dark:border-gray-800 dark:bg-gray-950">
                    @foreach ($profitStatementRows as $row)
                        <div class="rounded-xl border p-3 shadow-sm {{ ($row['final'] ?? false) ? 'border-gray-300 bg-gray-950 text-white dark:border-gray-700 dark:bg-white dark:text-gray-950' : (($row['total'] ?? false) ? 'border-cyan-200 bg-cyan-50 dark:border-cyan-900 dark:bg-cyan-950/25' : 'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900') }}">
                            <div class="flex items-center justify-between gap-2">
                                <div class="truncate text-xs font-black uppercase tracking-wide opacity-70">{{ $row['label'] }}</div>
                                <x-filament::icon :icon="$row['icon'] ?? 'heroicon-o-minus'" class="h-4 w-4 shrink-0 opacity-70" />
                            </div>
                            <div class="mt-2 text-lg font-black leading-tight {{ ($row['final'] ?? false) ? '' : $row['tone'] }}">{{ $row['display'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if (! empty($serviceClientNames))
                <div class="rounded-2xl border border-teal-200 bg-gradient-to-r from-teal-50 via-white to-cyan-50 p-5 shadow-sm dark:border-teal-900 dark:from-teal-950/30 dark:via-gray-950 dark:to-cyan-950/30">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wide text-teal-700 dark:text-teal-200">Service collection watch</div>
                            <div class="mt-1 text-xl font-black text-gray-950 dark:text-white">Clients that still matter in this period</div>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">For service businesses, owner revenue depends on billing records and collection follow-up, not parcel delivery.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($serviceClientNames as $clientName)
                                <span class="rounded-full border border-teal-200 bg-white px-3 py-1 text-sm font-semibold text-teal-900 shadow-sm dark:border-teal-900 dark:bg-gray-950 dark:text-teal-100">{{ $clientName }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <div class="rounded-2xl border border-gray-200 bg-gradient-to-br from-white via-slate-50 to-cyan-50 p-4 shadow-sm dark:border-gray-800 dark:from-gray-950 dark:via-gray-950 dark:to-cyan-950/20">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div class="text-xs font-black uppercase tracking-wide text-gray-500">More detail if needed</div>
                        <div class="mt-1 text-lg font-black text-gray-950 dark:text-white">Tap a tile only when you want the records behind the total</div>
                    </div>
                    <div class="rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1 text-xs font-black text-cyan-950 shadow-sm dark:border-cyan-900 dark:bg-cyan-950/30 dark:text-cyan-100">
                        Compact owner view
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    @foreach ($dashboardGroups as $group)
                        <div
                            x-data="{ open: {{ $group['open'] ? 'true' : 'false' }} }"
                            class="overflow-hidden rounded-2xl border bg-white shadow-md ring-1 ring-black/5 transition hover:-translate-y-0.5 hover:shadow-lg dark:bg-gray-950 dark:ring-white/5 {{ $group['style'] }}"
                        >
                            <div class="h-2 bg-gradient-to-r {{ $group['accent'] }}"></div>
                            <button
                                type="button"
                                x-on:click="open = ! open"
                                class="grid w-full gap-3 p-4 text-left"
                            >
                                <div class="flex min-w-0 items-start justify-between gap-3">
                                    <div class="rounded-xl bg-gradient-to-br {{ $group['accent'] }} p-2.5 text-white shadow-lg shadow-black/10">
                                        <x-filament::icon :icon="$group['icon']" class="h-5 w-5" />
                                    </div>
                                    <div class="rounded-full bg-white/70 p-1.5 shadow-sm dark:bg-gray-950/60">
                                        <x-filament::icon
                                            icon="heroicon-o-chevron-down"
                                            class="h-4 w-4 shrink-0 transition"
                                            x-bind:class="open ? 'rotate-180' : ''"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs font-black uppercase tracking-wide opacity-65">{{ $group['title'] }}</div>
                                    <div class="mt-1 text-2xl font-black leading-tight text-gray-950 dark:text-white">{{ $group['value'] }}</div>
                                    <div class="mt-1 min-h-8 text-xs font-bold leading-4 opacity-75">{{ $group['helper'] }}</div>
                                </div>
                            </button>

                            <div x-show="open" class="border-t border-current/10 bg-white/70 p-4 dark:bg-gray-950/45">
                                <div class="grid gap-2">
                                    @foreach ($group['details'] as $card)
                                        <div class="rounded-xl border p-3 shadow-sm {{ $card['style'] ?? 'border-gray-200 bg-white text-gray-950 dark:border-gray-800 dark:bg-gray-950 dark:text-white' }}">
                                            <div class="grid h-full gap-2">
                                                <div class="min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <div class="rounded-lg bg-white/75 p-1.5 text-gray-700 shadow-sm dark:bg-gray-950/60 dark:text-gray-200">
                                                            <x-filament::icon :icon="$card['icon']" class="h-4 w-4" />
                                                        </div>
                                                        <div class="truncate text-xs font-black">{{ $card['label'] }}</div>
                                                    </div>
                                                    <div class="mt-2 text-xs font-semibold opacity-75">{{ $card['count'] }}</div>
                                                </div>
                                                <div class="flex flex-col items-start justify-between gap-2">
                                                    <div class="text-lg font-black">{{ $card['value'] }}</div>
                                                    @if (filled($card['action_url'] ?? null))
                                                        <a
                                                            href="{{ $card['action_url'] }}"
                                                            class="inline-flex items-center gap-2 rounded-lg border border-current/20 bg-white/80 px-2.5 py-1.5 text-xs font-black shadow-sm transition hover:bg-white dark:bg-gray-950/70 dark:hover:bg-gray-950"
                                                        >
                                                            <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                                                            {{ $card['action_label'] ?? 'Open records' }}
                                                        </a>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 via-white to-emerald-50 p-5 shadow-sm dark:border-amber-900 dark:from-amber-950/30 dark:via-gray-950 dark:to-emerald-950/30">
                <div class="flex gap-3">
                    <div class="rounded-xl bg-amber-400 p-2 text-amber-950">
                        <x-filament::icon icon="heroicon-o-light-bulb" class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="font-black text-gray-950 dark:text-white">HELOS focus for this stage</div>
                        <p class="mt-1 text-sm leading-6 text-gray-700 dark:text-gray-200">Keep parcel statuses accurate every day, convert pending deliveries into delivered revenue, and reduce returns before adding more spending. This is the owner view first; manager and finance signals are separated above.</p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>

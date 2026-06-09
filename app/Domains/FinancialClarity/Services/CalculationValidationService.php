<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;

class CalculationValidationService
{
    public function __construct(
        private readonly BusinessHealthSnapshotService $snapshots,
        private readonly RevenuePipelineService $revenuePipeline,
        private readonly CashIntelligenceService $cashIntelligence,
        private readonly BreakEvenIntelligenceService $breakEvenIntelligence,
        private readonly InventoryIntelligenceService $inventoryIntelligence,
    ) {
    }

    public function forCurrentMonth(Business $business): array
    {
        $snapshot = $this->snapshots->currentMonthSummary($business);
        $pipeline = $this->revenuePipeline->forCurrentMonth($business);
        $cash = $this->cashIntelligence->forCurrentMonth($business);
        $breakEven = $this->breakEvenIntelligence->forCurrentMonth($business);
        $inventory = $this->inventoryIntelligence->forCurrentMonth($business);

        $results = [
            'revenue' => $this->validateRevenue($snapshot, $pipeline),
            'profit' => $this->validateProfit($snapshot),
            'payroll' => $this->validatePayroll($cash, $business),
            'treasury' => $this->validateTreasury($business),
            'sku' => $this->validateSku($business, $inventory),
        ];

        $statuses = collect($results)->pluck('status')->values();
        $overallStatus = $statuses->contains('FAIL')
            ? 'FAIL'
            : ($statuses->contains('WARNING') ? 'WARNING' : 'PASS');

        return [
            'overall_status' => $overallStatus,
            'results' => $results,
            'summary' => [
                'PASS' => $statuses->where('PASS')->count(),
                'WARNING' => $statuses->where('WARNING')->count(),
                'FAIL' => $statuses->where('FAIL')->count(),
            ],
        ];
    }

    private function validateRevenue(array $snapshot, array $pipeline): array
    {
        $deliveredRevenue = (float) ($pipeline['total_collected_revenue'] ?? 0);
        $recognizedRevenue = (float) ($snapshot['revenue_total'] ?? 0);
        $orders = (int) ($pipeline['completed_orders'] ?? 0);

        if ($orders > 0 && $recognizedRevenue <= 0) {
            return $this->result(
                'FAIL',
                'Delivered orders exist, but no revenue has been recognized yet.',
                'Revenue should appear after delivery is recorded.',
                ['Delivered orders' => $orders, 'Recognized revenue' => $recognizedRevenue]
            );
        }

        if (abs($deliveredRevenue - $recognizedRevenue) > 1) {
            return $this->result(
                'WARNING',
                'Delivered revenue and recognized revenue are not lining up exactly.',
                'Revenue should remain close to the delivery truth for this month.',
                ['Delivered revenue' => $deliveredRevenue, 'Recognized revenue' => $recognizedRevenue]
            );
        }

        return $this->result(
            'PASS',
            'Delivered revenue and recognized revenue are aligned.',
            'The delivery story is feeding revenue cleanly.',
            ['Delivered revenue' => $deliveredRevenue, 'Recognized revenue' => $recognizedRevenue]
        );
    }

    private function validateProfit(array $snapshot): array
    {
        $revenue = (float) ($snapshot['revenue_total'] ?? 0);
        $cost = (float) ($snapshot['cost_total'] ?? 0);
        $profit = (float) ($snapshot['estimated_profit'] ?? 0);
        $expected = $revenue - $cost - (float) ($snapshot['leakage_total'] ?? 0);

        if ($revenue > 0 && $cost <= 0) {
            return $this->result(
                'FAIL',
                'Revenue exists, but cost allocation is still empty.',
                'Profit cannot be trusted when cost truth is missing.',
                ['Revenue' => $revenue, 'Cost' => $cost]
            );
        }

        if (abs($expected - $profit) > 1) {
            return $this->result(
                'WARNING',
                'The month profit is not matching the expected formula exactly.',
                'Profit should stay close to revenue minus cost minus leakage.',
                ['Expected profit' => $expected, 'Snapshot profit' => $profit]
            );
        }

        return $this->result(
            'PASS',
            'Profit is matching the month formula.',
            'The current month profit calculation is consistent.',
            ['Revenue' => $revenue, 'Cost' => $cost, 'Profit' => $profit]
        );
    }

    private function validatePayroll(array $cash, Business $business): array
    {
        $salaryObligation = (float) ($cash['salary_obligation'] ?? 0);
        $employees = (float) $business->employees()->where('active', true)->sum('monthly_salary');

        if ($employees <= 0 && $salaryObligation > 0) {
            return $this->result(
                'FAIL',
                'Salary obligations exist, but employee salary records are missing.',
                'Payroll truth needs employee salary records to be reliable.',
                ['Salary obligation' => $salaryObligation, 'Employee salary total' => $employees]
            );
        }

        if (abs($salaryObligation - $employees) > 1) {
            return $this->result(
                'WARNING',
                'Salary obligation and employee salary records are not matching exactly.',
                'Payroll should stay close to the employee salary roll.',
                ['Salary obligation' => $salaryObligation, 'Employee salary total' => $employees]
            );
        }

        return $this->result(
            'PASS',
            'Payroll obligation matches employee records.',
            'Salary pressure is readable from the employee roll.',
            ['Salary obligation' => $salaryObligation, 'Employee salary total' => $employees]
        );
    }

    private function validateTreasury(Business $business): array
    {
        $transactions = $business->bankTransactions()
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->get();

        $missingAccount = $transactions->whereNull('money_container')->count();
        $missingTransferDestination = $transactions
            ->where('transaction_type', 'transfer')
            ->whereNull('counter_money_container')
            ->count();
        $missingAllocation = $transactions
            ->whereIn('transaction_type', ['revenue', 'expense', 'loan', 'owner_contribution', 'owner_withdrawal'])
            ->whereNull('allocated_business_id')
            ->count();
        $reviewRows = $transactions->where('status', 'review')->count();

        if ($missingAccount > 0 || $missingTransferDestination > 0 || $missingAllocation > 0) {
            return $this->result(
                'FAIL',
                'Treasury rows still have missing account or business assignment details.',
                'Treasury cannot be trusted while money containers or business assignments are missing.',
                [
                    'Missing account rows' => $missingAccount,
                    'Missing transfer destination rows' => $missingTransferDestination,
                    'Missing business allocation rows' => $missingAllocation,
                ]
            );
        }

        if ($reviewRows > 0) {
            return $this->result(
                'WARNING',
                'Some treasury rows are still waiting on review.',
                'Reviewed rows are easier to trust than pending rows.',
                ['Review rows' => $reviewRows]
            );
        }

        return $this->result(
            'PASS',
            'Treasury rows are assigned and reviewed.',
            'Cash by account is readable for the current month.',
            [
                'Review rows' => $reviewRows,
                'Transactions' => $transactions->count(),
            ]
        );
    }

    private function validateSku(Business $business, array $inventory): array
    {
        $activeSkus = $business->skus()->where('active', true)->get();
        $missingRecipe = $activeSkus->filter(fn ($sku): bool => (float) $sku->materialCostPerUnit() <= 0 || (float) $sku->productionCostPerUnit() <= 0)->count();
        $productionRows = $business->productionEntries()->count();

        if ($missingRecipe > 0 && $productionRows > 0) {
            return $this->result(
                'FAIL',
                'Production exists, but some active SKUs still have incomplete recipe costing.',
                'SKU costing must be complete before production numbers can be trusted.',
                ['Active SKUs missing cost truth' => $missingRecipe, 'Production rows' => $productionRows]
            );
        }

        if ($missingRecipe > 0) {
            return $this->result(
                'WARNING',
                'Some active SKUs still need recipe costing.',
                'SKU costing should be complete before product profitability is read as exact.',
                ['Active SKUs missing cost truth' => $missingRecipe]
            );
        }

        return $this->result(
            'PASS',
            'Active SKU costing is present.',
            'Product costing can be read from the current SKU setup.',
            [
                'Active SKUs' => $activeSkus->count(),
                'Production rows' => $productionRows,
                'Recipe coverage' => (int) ($inventory['recipe_coverage'] ?? 0),
            ]
        );
    }

    private function result(string $status, string $message, string $whyItMatters, array $evidence = []): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'why_it_matters' => $whyItMatters,
            'evidence' => $evidence,
        ];
    }
}

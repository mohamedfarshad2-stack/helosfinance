<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Filament\Pages\MissingSkuMapping;
use App\Filament\Resources\BankTransactionResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\MaterialLedgerResource;
use App\Filament\Resources\SkuRecipeResource;
use App\Filament\Resources\SkuResource;

class TrustValidationService
{
    public function __construct(
        private readonly BusinessHealthSnapshotService $snapshots,
        private readonly BreakEvenIntelligenceService $breakEvenIntelligence,
        private readonly GoalIntelligenceService $goalIntelligence,
        private readonly CashIntelligenceService $cashIntelligence,
        private readonly CapitalIntelligenceService $capitalIntelligence,
        private readonly InventoryIntelligenceService $inventoryIntelligence,
        private readonly RevenuePipelineService $revenuePipeline,
        private readonly BusinessCompletenessService $businessCompleteness,
        private readonly CalculationValidationService $calculationValidation,
        private readonly IntegrationHealthService $integrationHealth,
        private readonly SharedOverheadValidationService $sharedOverheadValidation,
        private readonly HostingReadinessService $hostingReadiness,
    ) {
    }

    public function forCurrentMonth(Business $business): array
    {
        $snapshot = $this->snapshots->currentMonthSummary($business);
        $breakEven = $this->breakEvenIntelligence->forCurrentMonth($business);
        $goal = $this->goalIntelligence->forCurrentMonth($business);
        $cash = $this->cashIntelligence->forCurrentMonth($business);
        $capital = $this->capitalIntelligence->forCurrentMonth($business);
        $inventory = $this->inventoryIntelligence->forCurrentMonth($business);
        $pipeline = $this->revenuePipeline->forCurrentMonth($business);
        $completeness = $this->businessCompleteness->forCurrentMonth($business);
        $calculationValidation = $this->calculationValidation->forCurrentMonth($business);
        $integrationHealth = $this->integrationHealth->forBusiness($business);
        $sharedOverhead = $this->sharedOverheadValidation->forBusiness($business);
        $hostingReadiness = $this->hostingReadiness->audit();

        $warnings = $this->buildWarnings($business, $completeness);
        $metricStatuses = $this->buildMetricStatuses($goal, $breakEven, $warnings);
        $sectionStatuses = $this->buildSectionStatuses($metricStatuses);

        $criticalCount = count($warnings['critical']);
        $importantCount = count($warnings['important']);
        $informationalCount = count($warnings['informational']);
        $estimatedNumbersCount = collect($metricStatuses)->where('status', 'Estimated')->count();
        $validationIssueCount = $criticalCount + $importantCount + $informationalCount;
        $missingInformationCount = $criticalCount + $importantCount;

        $dataQualityPercent = max(
            0,
            ((int) ($completeness['score'] ?? 100))
                - ($estimatedNumbersCount * 2)
                - (($calculationValidation['summary']['FAIL'] ?? 0) * 8)
                - (($integrationHealth['status'] === 'broken') ? 10 : 0)
        );

        $headline = match (true) {
            $criticalCount > 0 => 'Some owner numbers still need validation before they are treated as reliable.',
            $importantCount > 0 => 'The numbers are readable, but a few important inputs still need attention.',
            $estimatedNumbersCount > 0 => 'Most of the picture is readable, but some numbers are still estimated.',
            default => 'The current month is reading cleanly enough for owner review.',
        };

        return [
            'headline' => $headline,
            'status_label' => $criticalCount > 0 || ($calculationValidation['overall_status'] ?? 'PASS') === 'FAIL' || ($integrationHealth['status'] ?? 'healthy') === 'broken'
                ? 'Pending Validation'
                : (($estimatedNumbersCount > 0 || $importantCount > 0 || ($completeness['status_label'] ?? '') === 'Estimated' || ($integrationHealth['status'] ?? 'healthy') === 'delayed' || ($calculationValidation['overall_status'] ?? 'PASS') === 'WARNING') ? 'Estimated' : 'Verified'),
            'data_quality_percent' => (int) round($dataQualityPercent),
            'business_completeness_percent' => (int) ($completeness['score'] ?? 100),
            'validation_issue_count' => $validationIssueCount,
            'missing_information_count' => $missingInformationCount,
            'estimated_numbers_count' => $estimatedNumbersCount,
            'warnings' => $warnings,
            'metric_statuses' => $metricStatuses,
            'section_statuses' => $sectionStatuses,
            'calculation_validation' => $calculationValidation,
            'integration_health' => $integrationHealth,
            'allocation_quality' => $sharedOverhead,
            'hosting_readiness' => $hostingReadiness,
            'trust_center' => $this->buildTrustCenter(
                (int) round($dataQualityPercent),
                $completeness,
                $calculationValidation,
                $integrationHealth,
                $sharedOverhead,
                $hostingReadiness
            ),
        ];
    }

    private function buildWarnings(Business $business, array $completeness): array
    {
        $warnings = $completeness['issues'] ?? [
            'critical' => [],
            'important' => [],
            'informational' => [],
        ];

        foreach ($warnings as $severity => $items) {
            $warnings[$severity] = array_map(
                fn (array $warning): array => $this->decorateWarning($warning),
                $items
            );
        }

        return $warnings;
    }

    private function decorateWarning(array $warning): array
    {
        $repair = match ($warning['title'] ?? '') {
            'Missing SKU recipe' => [
                'action_label' => 'Fix product recipes',
                'action_url' => SkuRecipeResource::getUrl('index'),
                'fix_guidance' => 'Open Product Cost Recipes, select the product, then add the missing material and labour lines.',
            ],
            'Missing material cost' => [
                'action_label' => 'Fix product costs',
                'action_url' => SkuResource::getUrl('index'),
                'fix_guidance' => 'Open Products / SKUs or Product Cost Recipes and enter the missing material cost truth.',
            ],
            'Missing stock mapping', 'Missing lifecycle stages' => [
                'action_label' => 'Fix missing product links',
                'action_url' => MissingSkuMapping::getUrl(),
                'fix_guidance' => 'Open Missing Product Links, choose the correct product for each order row, and save. HELOS will recalculate the affected costs.',
            ],
            'Missing supplier name', 'Missing due date' => [
                'action_label' => 'Fix expenses',
                'action_url' => ExpenseResource::getUrl('index'),
                'fix_guidance' => 'Open Expenses & Payables and complete the supplier/payee or due date on the warning rows.',
            ],
            'Missing salary mapping' => [
                'action_label' => 'Fix staff pay',
                'action_url' => EmployeeResource::getUrl('index'),
                'fix_guidance' => 'Open Staff & Salary Setup and complete the salary or pay setup for active employees.',
            ],
            'Missing business allocation', 'Missing treasury allocation' => [
                'action_label' => 'Fix bank review',
                'action_url' => BankTransactionResource::getUrl('index'),
                'fix_guidance' => 'Open Bank Review, classify the row, and allocate it to the correct business or money container.',
            ],
            'Missing material SKU link' => [
                'action_label' => 'Fix material stock',
                'action_url' => MaterialLedgerResource::getUrl('index'),
                'fix_guidance' => 'Open Raw Material Stock and link material rows to the correct product where needed.',
            ],
            'No monthly goal set' => [
                'action_label' => 'Set monthly goal',
                'action_url' => '#helos-goal',
                'fix_guidance' => 'Set a monthly goal so HELOS can explain progress and what still needs to improve.',
            ],
            default => [
                'action_label' => 'Review warning',
                'action_url' => null,
                'fix_guidance' => 'Open the related setup or work screen and complete the missing information.',
            ],
        };

        return array_merge($warning, $repair);
    }

    private function buildMetricStatuses(array $goal, array $breakEven, array $warnings): array
    {
        $criticalCount = count($warnings['critical']);
        $importantCount = count($warnings['important']);
        $goalConfigured = (bool) ($goal['configured'] ?? false);
        $hasCurrentActivity = (($breakEven['progress']['current_deliveries'] ?? 0) > 0)
            || (($breakEven['progress']['current_revenue'] ?? 0) > 0)
            || (($goal['goal']['target'] ?? 0) > 0);

        $status = function (string $metric) use ($criticalCount, $importantCount, $goalConfigured, $hasCurrentActivity): array {
            $value = match ($metric) {
                'safe_to_use', 'safe_to_withdraw', 'growth_capacity', 'treasury' => $criticalCount > 0 ? 'Pending Validation' : 'Estimated',
                'profit', 'break_even' => $criticalCount > 0 ? 'Pending Validation' : ($importantCount > 0 ? 'Estimated' : 'Verified'),
                'goal_progress' => ! $goalConfigured
                    ? 'Pending Validation'
                    : ($criticalCount > 0 ? 'Pending Validation' : ($importantCount > 0 ? 'Estimated' : 'Verified')),
                'cash_pressure' => $criticalCount > 0 ? 'Pending Validation' : ($importantCount > 0 ? 'Estimated' : 'Verified'),
                'revenue', 'returns', 'stock' => $criticalCount > 0 ? 'Pending Validation' : ($hasCurrentActivity ? 'Verified' : 'Pending Validation'),
                default => $importantCount > 0 ? 'Estimated' : ($criticalCount > 0 ? 'Pending Validation' : 'Verified'),
            };

            $reason = match ($metric) {
                'safe_to_use' => 'This is a conservative treasury view, so it stays estimated.',
                'safe_to_withdraw' => 'Withdrawable cash is protected by settlement and commitment checks.',
                'growth_capacity' => 'Growth capacity is a conservative leftover view, not bank truth.',
                'treasury' => 'Treasury rows need allocation and review before the picture is exact.',
                'profit' => 'Profit depends on complete product, stock, and expense truth.',
                'break_even' => 'Break-even depends on complete fixed and variable cost truth.',
                'goal_progress' => 'Goal progress uses the current month truth against the saved target.',
                'cash_pressure' => 'Cash pressure depends on due dates and obligations being complete.',
                'revenue' => 'Revenue is strongest when the order lifecycle is complete.',
                'returns' => 'Return pressure depends on return handling being fully recorded.',
                'stock' => 'Stock pressure depends on stock links and recipe coverage.',
                default => 'This number is read from the current month truth.',
            };

            return [
                'status' => $value,
                'reason' => $reason,
            ];
        };

        return [
            'profit' => $status('profit'),
            'break_even' => $status('break_even'),
            'goal_progress' => $status('goal_progress'),
            'cash_pressure' => $status('cash_pressure'),
            'safe_to_use' => $status('safe_to_use'),
            'safe_to_withdraw' => $status('safe_to_withdraw'),
            'growth_capacity' => $status('growth_capacity'),
            'treasury' => $status('treasury'),
            'revenue' => $status('revenue'),
            'returns' => $status('returns'),
            'stock' => $status('stock'),
        ];
    }

    private function buildTrustCenter(
        int $dataQualityPercent,
        array $completeness,
        array $calculationValidation,
        array $integrationHealth,
        array $sharedOverhead,
        array $hostingReadiness
    ): array {
        return [
            'headline' => match (true) {
                ($calculationValidation['overall_status'] ?? 'PASS') === 'FAIL' => 'Some calculations are not safe yet.',
                ($integrationHealth['status'] ?? 'never synced') === 'broken' => 'Integration health needs attention.',
                ($completeness['status_label'] ?? 'Verified') === 'Pending Validation' => 'Data completeness still needs work.',
                default => 'The business picture is readable and ready for review.',
            },
            'cards' => [
                [
                    'title' => 'Data Quality',
                    'value' => $dataQualityPercent.'%',
                    'status' => $completeness['status_label'] ?? 'Estimated',
                    'note' => 'Validation issues: '.(int) ($completeness['issue_count'] ?? 0),
                ],
                [
                    'title' => 'Business Completeness',
                    'value' => ((int) ($completeness['score'] ?? 0)).'%',
                    'status' => match ((int) ($completeness['critical_count'] ?? 0)) {
                        0 => 'Good',
                        default => 'Needs attention',
                    },
                    'note' => 'Missing items: '.(int) ($completeness['missing_information_count'] ?? 0),
                ],
                [
                    'title' => 'Calculation Validation',
                    'value' => $calculationValidation['overall_status'] ?? 'PASS',
                    'status' => $calculationValidation['overall_status'] ?? 'PASS',
                    'note' => 'Pass: '.(int) ($calculationValidation['summary']['PASS'] ?? 0).' | Warn: '.(int) ($calculationValidation['summary']['WARNING'] ?? 0).' | Fail: '.(int) ($calculationValidation['summary']['FAIL'] ?? 0),
                ],
                [
                    'title' => 'Integration Health',
                    'value' => ucfirst((string) ($integrationHealth['status'] ?? 'never synced')),
                    'status' => ucfirst((string) ($integrationHealth['status'] ?? 'never synced')),
                    'note' => 'Last sync: '.($integrationHealth['last_successful_sync_at'] ?? 'No sync yet'),
                ],
                [
                    'title' => 'Allocation Quality',
                    'value' => ((int) ($sharedOverhead['allocation_quality_percent'] ?? 0)).'%',
                    'status' => $sharedOverhead['status_label'] ?? 'Estimated',
                    'note' => 'Shared rows: '.(int) ($sharedOverhead['shared_rows'] ?? 0).' | Unallocated: '.(int) ($sharedOverhead['unallocated_rows'] ?? 0),
                ],
                [
                    'title' => 'Hosting Readiness',
                    'value' => ((int) ($hostingReadiness['score'] ?? 0)).'%',
                    'status' => $hostingReadiness['status_label'] ?? 'Warnings',
                    'note' => 'Critical blockers: '.count($hostingReadiness['critical_blockers'] ?? []).' | Warnings: '.count($hostingReadiness['warnings'] ?? []),
                ],
            ],
        ];
    }

    private function buildSectionStatuses(array $metricStatuses): array
    {
        $sectionMap = [
            'health' => ['revenue', 'profit', 'cash_pressure', 'returns', 'stock'],
            'bucket' => ['safe_to_use', 'safe_to_withdraw', 'growth_capacity'],
            'break_even' => ['break_even'],
            'goal' => ['goal_progress'],
            'treasury' => ['treasury', 'cash_pressure'],
            'trust_center' => ['treasury', 'profit', 'break_even'],
        ];

        $sectionStatuses = [];

        foreach ($sectionMap as $section => $metrics) {
            $statuses = collect($metrics)
                ->map(fn (string $metric): string => $metricStatuses[$metric]['status'] ?? 'Estimated')
                ->all();

            $sectionStatuses[$section] = in_array('Pending Validation', $statuses, true)
                ? 'Pending Validation'
                : (in_array('Estimated', $statuses, true) ? 'Estimated' : 'Verified');
        }

        return $sectionStatuses;
    }

    private function warning(string $title, string $missing, string $whyItMatters, string $affected, array $examples = []): array
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

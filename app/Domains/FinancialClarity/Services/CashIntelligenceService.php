<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\ServiceBillingRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CashIntelligenceService
{
    public function forCurrentMonth(Business $business): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();
        $previousStart = now()->subMonthNoOverflow()->startOfMonth();
        $previousEnd = now()->subMonthNoOverflow()->endOfMonth();

        $currentTransactions = BankTransaction::query()
            ->where('business_id', $business->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()]);

        $bankInflow = (float) (clone $currentTransactions)->sum('credit');
        $bankOutflow = (float) (clone $currentTransactions)->sum('debit');
        $netMovement = $bankInflow - $bankOutflow;
        $transactionCount = (clone $currentTransactions)->count();

        $previousTransactions = BankTransaction::query()
            ->where('business_id', $business->id)
            ->whereBetween('transaction_date', [$previousStart->toDateString(), $previousEnd->toDateString()]);

        $previousNetMovement = (float) (clone $previousTransactions)->sum('credit') - (float) (clone $previousTransactions)->sum('debit');

        $timeline = BankTransaction::query()
            ->where('business_id', $business->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('transaction_date')
            ->get()
            ->groupBy(fn (BankTransaction $transaction): string => optional($transaction->transaction_date)->toDateString() ?? 'unknown')
            ->map(function ($transactions, string $date): array {
                $inflow = (float) $transactions->sum('credit');
                $outflow = (float) $transactions->sum('debit');

                return [
                    'date' => $date,
                    'inflow' => $inflow,
                    'outflow' => $outflow,
                    'net' => $inflow - $outflow,
                    'count' => $transactions->count(),
                ];
            })
            ->values()
            ->all();

        $expenseObligations = Expense::query()
            ->where('business_id', $business->id)
            ->whereIn('payment_status', ['partial', 'cheque_pending', 'credit_due'])
            ->get()
            ->map(function (Expense $expense): array {
                $due = max((float) $expense->amount - (float) $expense->paid_amount, 0);

                return [
                    'type' => 'expense',
                    'title' => $expense->category,
                    'amount' => $due,
                    'status' => $expense->payment_status,
                    'due_on' => optional($expense->due_on)->toDateString(),
                    'label' => 'Expense settlement',
                ];
            });

        $pendingProduction = ProductionEntry::query()
            ->where('business_id', $business->id)
            ->where('payment_status', 'pending')
            ->get()
            ->map(fn (ProductionEntry $entry): array => [
                'type' => 'production',
                'title' => trim(($entry->employee_name ?: 'Production worker').' - '.$entry->sku?->code),
                'amount' => (float) $entry->net_payable,
                'status' => $entry->payment_status,
                'due_on' => optional($entry->produced_on)->toDateString(),
                'label' => 'Production payout',
            ]);

        $salaryObligation = (float) Employee::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->sum('monthly_salary');

        $salaryItem = $salaryObligation > 0 ? collect([[
            'type' => 'salary',
            'title' => 'Active fixed salaries',
            'amount' => $salaryObligation,
            'status' => 'active',
            'due_on' => now()->endOfMonth()->toDateString(),
            'label' => 'Monthly payroll',
        ]]) : collect();

        $serviceReceivables = ServiceBillingRecord::query()
            ->where('business_id', $business->id)
            ->whereIn('payment_status', ['unpaid', 'partial', 'overdue'])
            ->get()
            ->filter(fn (ServiceBillingRecord $record): bool => $record->balanceDue() > 0)
            ->map(fn (ServiceBillingRecord $record): array => [
                'type' => 'service_receivable',
                'title' => $record->client_name.' - '.(ServiceBillingRecord::billingTypeOptions()[$record->billing_type] ?? 'Service billing'),
                'amount' => $record->balanceDue(),
                'status' => $record->payment_status,
                'due_on' => optional($record->due_on)->toDateString(),
                'label' => 'Service money to collect',
            ])
            ->values();

        $obligations = collect($expenseObligations->all())
            ->merge($pendingProduction->all())
            ->merge($salaryItem->all())
            ->sortByDesc('amount')
            ->values()
            ->all();
        $incomingDueSoon = $serviceReceivables
            ->filter(function (array $item): bool {
                if (blank($item['due_on'])) {
                    return false;
                }

                $dueOn = Carbon::parse($item['due_on']);

                return $dueOn->between(today(), now()->addDays(30)->endOfDay());
            })
            ->values()
            ->all();
        $incomingOverdue = $serviceReceivables
            ->filter(fn (array $item): bool => filled($item['due_on']) && Carbon::parse($item['due_on'])->lessThan(today()))
            ->values()
            ->all();

        $totalObligations = collect($obligations)->sum('amount');
        $overdueObligations = collect($obligations)
            ->filter(fn (array $item): bool => filled($item['due_on']) && Carbon::parse($item['due_on'])->lessThan(today()))
            ->values()
            ->all();
        $dueSoonObligations = collect($obligations)
            ->filter(function (array $item): bool {
                if (blank($item['due_on'])) {
                    return false;
                }

                $dueOn = Carbon::parse($item['due_on']);

                return $dueOn->between(today(), now()->addDays(30)->endOfDay());
            })
            ->values()
            ->all();

        $headline = match (true) {
            $transactionCount === 0 => 'Import bank statements to see the cash timeline.',
            $netMovement < 0 && $totalObligations > 0 => 'Cash is moving out faster than it is coming back.',
            $netMovement >= 0 && $totalObligations > 0 => 'Cash movement is positive, but obligations still need attention.',
            default => 'Cash movement is visible, and obligations are currently light.',
        };

        return [
            'period_label' => now()->format('F Y'),
            'bank_inflow' => $bankInflow,
            'bank_outflow' => $bankOutflow,
            'net_movement' => $netMovement,
            'previous_net_movement' => $previousNetMovement,
            'transaction_count' => $transactionCount,
            'timeline' => $timeline,
            'obligations' => $obligations,
            'overdue_obligations' => $overdueObligations,
            'due_soon_obligations' => $dueSoonObligations,
            'incoming_receivables' => $serviceReceivables->all(),
            'incoming_due_soon' => $incomingDueSoon,
            'incoming_overdue' => $incomingOverdue,
            'total_incoming_receivables' => $serviceReceivables->sum('amount'),
            'salary_obligation' => $salaryObligation,
            'total_obligations' => $totalObligations,
            'headline' => $headline,
            'confidence' => $transactionCount > 0 ? 'High' : 'Medium',
            'actions' => array_values(array_filter([
                $transactionCount === 0 ? 'Import a bank statement first so cash movement becomes visible.' : null,
                $totalObligations > 0 ? 'Settle overdue items and keep due-soon payments in view.' : null,
                $serviceReceivables->isNotEmpty() ? 'Collect overdue or due-soon service billing money.' : null,
                $netMovement < $previousNetMovement ? 'Review outflows that grew compared with the previous month.' : null,
                $salaryObligation > 0 ? 'Keep salary commitments visible near month-end.' : null,
            ])),
        ];
    }
}

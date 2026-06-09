<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Expense;
use Illuminate\Support\Collection;

class SharedOverheadValidationService
{
    public function forBusiness(Business $business): array
    {
        $transactions = BankTransaction::query()
            ->where('business_id', $business->id)
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $businessNames = Business::query()->pluck('name', 'id');

        $allocatedRows = $transactions
            ->filter(fn (BankTransaction $transaction): bool => filled($transaction->allocated_business_id) && $transaction->transaction_type !== 'transfer');

        $sharedRows = $transactions
            ->filter(fn (BankTransaction $transaction): bool => blank($transaction->allocated_business_id) || (string) $transaction->transaction_type === 'transfer' && blank($transaction->counter_money_container));

        $unallocatedRows = $transactions
            ->filter(fn (BankTransaction $transaction): bool => in_array($transaction->transaction_type, ['revenue', 'expense', 'loan', 'owner_contribution', 'owner_withdrawal'], true) && blank($transaction->allocated_business_id));

        $businessBreakdown = $allocatedRows
            ->groupBy('allocated_business_id')
            ->map(function (Collection $group, int|string $businessId) use ($businessNames): array {
                $inflow = (float) $group->sum('credit');
                $outflow = (float) $group->sum('debit');

                return [
                    'business' => $businessNames->get((int) $businessId, 'Business '.$businessId),
                    'rows' => $group->count(),
                    'inflow' => $inflow,
                    'outflow' => $outflow,
                    'net' => $inflow - $outflow,
                ];
            })
            ->sortByDesc('rows')
            ->values()
            ->all();

        $expenseRows = Expense::query()
            ->where('business_id', $business->id)
            ->whereBetween('spent_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->get();

        $missingSupplier = $expenseRows
            ->filter(fn (Expense $expense): bool => in_array($expense->expense_type, ['fixed', 'variable'], true) && blank($expense->payee))
            ->count();

        $missingDueDates = $expenseRows
            ->filter(fn (Expense $expense): bool => in_array($expense->payment_status, ['partial', 'cheque_pending', 'credit_due'], true) && blank($expense->due_on))
            ->count();

        $highRiskRows = collect()
            ->merge($unallocatedRows->map(fn (BankTransaction $transaction): array => [
                'title' => trim((string) ($transaction->description ?: 'Bank row '.$transaction->id)),
                'reason' => 'This row still needs a business assignment.',
                'affected' => 'Profit, treasury, and break-even.',
            ]))
            ->merge($transactions->where('transaction_type', 'transfer')->whereNull('counter_money_container')->map(fn (BankTransaction $transaction): array => [
                'title' => trim((string) ($transaction->description ?: 'Bank row '.$transaction->id)),
                'reason' => 'This transfer still needs the destination account.',
                'affected' => 'Treasury and cash pressure.',
            ]))
            ->merge($expenseRows->filter(fn (Expense $expense): bool => in_array($expense->payment_status, ['partial', 'cheque_pending', 'credit_due'], true) && blank($expense->due_on))->map(fn (Expense $expense): array => [
                'title' => trim((string) ($expense->category ?: 'Expense')).($expense->payee ? ' / '.$expense->payee : ''),
                'reason' => 'This expense still needs a due date.',
                'affected' => 'Weekly obligation reminders.',
            ]))
            ->values()
            ->all();

        $score = 100
            - min(28, $unallocatedRows->count() * 8)
            - min(14, $sharedRows->where('transaction_type', 'transfer')->count() * 6)
            - min(12, $missingSupplier * 3)
            - min(12, $missingDueDates * 3);

        $score = max(min($score, 100), 0);

        return [
            'allocation_quality_percent' => (int) round($score),
            'status_label' => $score >= 90 ? 'Verified' : ($score >= 70 ? 'Estimated' : 'Pending Validation'),
            'headline' => match (true) {
                $unallocatedRows->isNotEmpty() => 'Some rows still need business allocation before overheads can be trusted.',
                $sharedRows->isNotEmpty() => 'Shared rows are present and need review.',
                default => 'Overhead allocation is readable for the current month.',
            },
            'allocated_rows' => $allocatedRows->count(),
            'shared_rows' => $sharedRows->count(),
            'unallocated_rows' => $unallocatedRows->count(),
            'business_breakdown' => $businessBreakdown,
            'high_risk_rows' => $highRiskRows,
        ];
    }
}

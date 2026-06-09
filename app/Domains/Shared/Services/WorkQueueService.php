<?php

namespace App\Domains\Shared\Services;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\SkuStockMovement;
use App\Filament\Resources\BankTransactionResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\MaterialLedgerResource;
use App\Filament\Resources\OperationalEventResource;
use App\Filament\Resources\ProductionEntryResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WorkQueueService
{
    public function forBusiness(Business $business): array
    {
        $tasks = collect([
            ...$this->bankTasks($business),
            ...$this->expenseTasks($business),
            ...$this->productionTasks($business),
            ...$this->orderTasks($business),
            ...$this->inventoryTasks($business),
            ...$this->exceptionTasks($business),
            ...$this->movementTasks($business),
        ])->values();

        $openTasks = $tasks->where('state', 'open')->values();
        $completedToday = $tasks
            ->where('state', 'completed')
            ->filter(fn (array $task): bool => filled($task['completed_at']) && Carbon::parse($task['completed_at'])->isToday())
            ->values();

        $dueToday = $openTasks
            ->filter(fn (array $task): bool => filled($task['due_on']) && Carbon::parse($task['due_on'])->lessThanOrEqualTo(today()))
            ->sortBy(fn (array $task): string => $this->taskSortKey($task))
            ->values();

        $highPriority = $openTasks
            ->filter(fn (array $task): bool => $task['priority'] === 'high')
            ->sortBy(fn (array $task): string => $this->taskSortKey($task))
            ->values();

        $waitingReview = $openTasks
            ->filter(fn (array $task): bool => $task['queue'] === 'review')
            ->sortBy(fn (array $task): string => $this->taskSortKey($task))
            ->values();

        $blockedWork = $openTasks
            ->filter(fn (array $task): bool => $task['priority'] === 'high' && filled($task['due_on']) && Carbon::parse($task['due_on'])->lessThan(today()))
            ->values();

        $teamWorkload = $openTasks
            ->groupBy(fn (array $task): string => (string) ($task['assigned_team'] ?? 'Unassigned'))
            ->map(fn (Collection $group, string $team): array => [
                'team' => $team,
                'count' => $group->count(),
                'high_priority' => $group->where('priority', 'high')->count(),
            ])
            ->sortByDesc('count')
            ->values();

        $completionRate = $this->completionRate($openTasks->count(), $completedToday->count());

        return [
            'headline' => $openTasks->isEmpty()
                ? 'Today\'s work is clear. The team can focus on finishing what is already open.'
                : 'Today\'s work is ready. Start with the tasks that block money or flow.',
            'summary' => [
                'Tasks due today' => $dueToday->count(),
                'High priority' => $highPriority->count(),
                'Waiting for review' => $waitingReview->count(),
                'Completed today' => $completedToday->count(),
                'Overdue' => $blockedWork->count(),
                'Completion rate' => $completionRate,
            ],
            'sections' => [
                'due_today' => $dueToday->all(),
                'high_priority' => $highPriority->all(),
                'waiting_review' => $waitingReview->all(),
                'completed_today' => $completedToday->all(),
            ],
            'tasks' => $tasks->all(),
            'team_workload' => $teamWorkload->all(),
            'open_count' => $openTasks->count(),
            'blocked_count' => $blockedWork->count(),
            'completed_today_count' => $completedToday->count(),
        ];
    }

    private function bankTasks(Business $business): array
    {
        $rows = BankTransaction::query()
            ->where('business_id', $business->id)
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        return $rows
            ->flatMap(function (BankTransaction $transaction) use ($business): array {
                $label = trim((string) ($transaction->description ?: 'Bank row '.$transaction->id));
                $related = $this->relatedRecord('bank_transaction', $transaction->id, $label, BankTransactionResource::getUrl('edit', ['record' => $transaction]));
                $createdAt = optional($transaction->transaction_date)->toDateString() ?? now()->toDateString();
                $tasks = [];

                if (blank($transaction->money_container)) {
                    $tasks[] = $this->makeTask([
                        'id' => 'bank-account-missing-'.$transaction->id,
                        'queue' => 'review',
                        'state' => 'open',
                        'priority' => $this->datePriority($transaction->transaction_date, true),
                        'title' => 'Bank account needs review',
                        'why_it_matters' => 'HELOS needs to know which money container this row belongs to before it can explain cash correctly.',
                        'recommended_action' => 'Choose the bank account or cash container for this row.',
                        'related_record' => $related,
                        'assigned_team' => 'Accounts',
                        'assigned_user' => $this->assignedUserLabel($business, ['accounts', 'finance', 'bank']),
                        'created_at' => $createdAt,
                        'due_on' => $createdAt,
                        'status_label' => $this->statusLabel($createdAt),
                        'work_type' => 'bank_account_review',
                    ]);
                }

                if ($transaction->status === 'review') {
                    $tasks[] = $this->makeTask([
                        'id' => 'bank-review-'.$transaction->id,
                        'queue' => 'review',
                        'state' => 'open',
                        'priority' => $this->datePriority($transaction->transaction_date),
                        'title' => 'Bank transaction needs review',
                        'why_it_matters' => 'The money picture stays blurry until this row is classified.',
                        'recommended_action' => 'Open the bank row, choose the right meaning, and mark it reviewed.',
                        'related_record' => $related,
                        'assigned_team' => 'Accounts',
                        'assigned_user' => $this->assignedUserLabel($business, ['accounts', 'finance', 'bank']),
                        'created_at' => $createdAt,
                        'due_on' => $createdAt,
                        'status_label' => $this->statusLabel($createdAt),
                        'work_type' => 'bank_review',
                    ]);
                }

                if (blank($transaction->transaction_type)) {
                    $tasks[] = $this->makeTask([
                        'id' => 'bank-missing-type-'.$transaction->id,
                        'queue' => 'review',
                        'state' => 'open',
                        'priority' => $this->datePriority($transaction->transaction_date, true),
                        'title' => 'Transaction type missing',
                        'why_it_matters' => 'The employee still has to tell HELOS what kind of money move this is.',
                        'recommended_action' => 'Open the row and choose Revenue, Expense, Transfer, Owner contribution, Owner withdrawal, Loan, or Other.',
                        'related_record' => $related,
                        'assigned_team' => 'Accounts',
                        'assigned_user' => $this->assignedUserLabel($business, ['accounts', 'finance', 'bank']),
                        'created_at' => $createdAt,
                        'due_on' => $createdAt,
                        'status_label' => $this->statusLabel($createdAt),
                        'work_type' => 'bank_transaction_type',
                    ]);
                }

                if ($this->needsBusinessAssignment($transaction) && blank($transaction->allocated_business_id)) {
                    $tasks[] = $this->makeTask([
                        'id' => 'bank-missing-business-'.$transaction->id,
                        'queue' => 'review',
                        'state' => 'open',
                        'priority' => $this->datePriority($transaction->transaction_date, true),
                        'title' => 'Business assignment missing',
                        'why_it_matters' => 'This row affects a business, so HELOS needs to know which one.',
                        'recommended_action' => 'Choose the business that should own this revenue, expense, loan, or owner transaction.',
                        'related_record' => $related,
                        'assigned_team' => 'Accounts',
                        'assigned_user' => $this->assignedUserLabel($business, ['accounts', 'finance', 'bank']),
                        'created_at' => $createdAt,
                        'due_on' => $createdAt,
                        'status_label' => $this->statusLabel($createdAt),
                        'work_type' => 'bank_business_assignment',
                    ]);
                }

                if ($transaction->transaction_type === 'transfer' && blank($transaction->counter_money_container)) {
                    $tasks[] = $this->makeTask([
                        'id' => 'bank-transfer-destination-'.$transaction->id,
                        'queue' => 'review',
                        'state' => 'open',
                        'priority' => 'high',
                        'title' => 'Transfer destination account missing',
                        'why_it_matters' => 'Internal transfers need the other account named so treasury stays readable.',
                        'recommended_action' => 'Choose the destination account that received the transfer.',
                        'related_record' => $related,
                        'assigned_team' => 'Accounts',
                        'assigned_user' => $this->assignedUserLabel($business, ['accounts', 'finance', 'bank']),
                        'created_at' => $createdAt,
                        'due_on' => $createdAt,
                        'status_label' => $this->statusLabel($createdAt),
                        'work_type' => 'bank_transfer_destination',
                    ]);
                }

                if ($this->looksLikeTransfer($transaction) && $transaction->transaction_type !== 'transfer') {
                    $tasks[] = $this->makeTask([
                        'id' => 'bank-transfer-confirm-'.$transaction->id,
                        'queue' => 'review',
                        'state' => 'open',
                        'priority' => 'high',
                        'title' => 'Possible transfer needs confirmation',
                        'why_it_matters' => 'Internal transfers should not be treated like revenue or expense.',
                        'recommended_action' => 'If this is money moving between accounts, mark it as Transfer and leave business unallocated.',
                        'related_record' => $related,
                        'assigned_team' => 'Accounts',
                        'assigned_user' => $this->assignedUserLabel($business, ['accounts', 'finance', 'bank']),
                        'created_at' => $createdAt,
                        'due_on' => $createdAt,
                        'status_label' => $this->statusLabel($createdAt),
                        'work_type' => 'bank_transfer_confirmation',
                    ]);
                }

                if (in_array($transaction->status, ['classified', 'matched'], true) && ! blank($transaction->transaction_type) && (! $this->needsBusinessAssignment($transaction) || filled($transaction->allocated_business_id))) {
                    $tasks[] = $this->makeTask([
                        'id' => 'bank-reviewed-'.$transaction->id,
                        'queue' => 'review',
                        'state' => 'completed',
                        'priority' => 'low',
                        'title' => 'Bank transaction reviewed',
                        'why_it_matters' => 'The bank row is now clear for the month.',
                        'recommended_action' => 'No action needed. The row has already been reviewed.',
                        'related_record' => $related,
                        'assigned_team' => 'Accounts',
                        'assigned_user' => $this->assignedUserLabel($business, ['accounts', 'finance', 'bank']),
                        'created_at' => optional($transaction->reviewed_at)->toDateString() ?? $createdAt,
                        'completed_at' => optional($transaction->reviewed_at)->toDateString() ?? $createdAt,
                        'status_label' => 'Completed today',
                        'work_type' => 'bank_review',
                    ]);
                }

                return $tasks;
            })
            ->values()
            ->all();
    }

    private function expenseTasks(Business $business): array
    {
        $openRows = Expense::query()
            ->where('business_id', $business->id)
            ->whereIn('payment_status', ['partial', 'cheque_pending', 'credit_due'])
            ->orderByRaw('COALESCE(due_on, spent_on) asc')
            ->orderBy('id')
            ->get();

        $completedRows = Expense::query()
            ->where('business_id', $business->id)
            ->where('payment_status', 'settled')
            ->whereDate('settled_on', today())
            ->orderBy('settled_on')
            ->get();

        return [
            ...$openRows->map(fn (Expense $expense): array => $this->makeTask([
                'id' => 'expense-settlement-'.$expense->id,
                'queue' => 'approval',
                'state' => 'open',
                'priority' => $this->expensePriority($expense),
                'title' => $this->expenseTaskTitle($expense),
                'why_it_matters' => 'This money is still spoken for, so leaving it open keeps pressure on the team.',
                'recommended_action' => 'Open the expense row and settle the balance or confirm the due date.',
                'related_record' => $this->relatedRecord('expense', $expense->id, trim((string) ($expense->category ?: 'Expense')).($expense->payee ? ' / '.$expense->payee : ''), ExpenseResource::getUrl('edit', ['record' => $expense])),
                'assigned_team' => 'Accounts',
                'assigned_user' => $this->assignedUserLabel($business, ['accounts', 'finance', 'cash', 'admin']),
                'created_at' => optional($expense->spent_on ?? $expense->due_on)->toDateString() ?? now()->toDateString(),
                'due_on' => optional($expense->due_on ?? $expense->spent_on)->toDateString() ?? now()->toDateString(),
                'status_label' => $this->expenseStatusLabel($expense),
                'work_type' => 'expense_settlement',
            ]))->all(),
            ...$completedRows->map(fn (Expense $expense): array => $this->makeTask([
                'id' => 'expense-settled-'.$expense->id,
                'queue' => 'approval',
                'state' => 'completed',
                'priority' => 'low',
                'title' => 'Expense settled',
                'why_it_matters' => 'The payment is no longer blocking the month.',
                'recommended_action' => 'No action needed. The expense is settled.',
                'related_record' => $this->relatedRecord('expense', $expense->id, trim((string) ($expense->category ?: 'Expense')).($expense->payee ? ' / '.$expense->payee : ''), ExpenseResource::getUrl('edit', ['record' => $expense])),
                'assigned_team' => 'Accounts',
                'assigned_user' => $this->assignedUserLabel($business, ['accounts', 'finance', 'cash', 'admin']),
                'created_at' => optional($expense->settled_on)->toDateString() ?? now()->toDateString(),
                'completed_at' => optional($expense->settled_on)->toDateString() ?? now()->toDateString(),
                'status_label' => 'Completed today',
                'work_type' => 'expense_settlement',
            ]))->all(),
        ];
    }

    private function productionTasks(Business $business): array
    {
        $openRows = ProductionEntry::query()
            ->where('business_id', $business->id)
            ->where('payment_status', 'pending')
            ->orderBy('produced_on')
            ->orderBy('id')
            ->get();

        $completedRows = ProductionEntry::query()
            ->where('business_id', $business->id)
            ->where('payment_status', 'paid')
            ->whereDate('paid_on', today())
            ->orderBy('paid_on')
            ->get();

        return [
            ...$openRows->map(fn (ProductionEntry $entry): array => $this->makeTask([
                'id' => 'production-payout-'.$entry->id,
                'queue' => 'approval',
                'state' => 'open',
                'priority' => $this->datePriority($entry->produced_on, true),
                'title' => 'Production payout pending',
                'why_it_matters' => 'Production work stays open until the payout is handled.',
                'recommended_action' => 'Open the production row and settle the payout when it is ready.',
                'related_record' => $this->relatedRecord('production_entry', $entry->id, trim((string) ($entry->employee_name ?: 'Production payout')).($entry->sku?->code ? ' / '.$entry->sku->code : ''), ProductionEntryResource::getUrl('edit', ['record' => $entry])),
                'assigned_team' => 'Production',
                'assigned_user' => $this->assignedUserLabel($business, ['production', 'factory', 'line', 'supervisor']),
                'created_at' => optional($entry->produced_on)->toDateString() ?? now()->toDateString(),
                'due_on' => optional($entry->produced_on)->toDateString() ?? now()->toDateString(),
                'status_label' => $this->statusLabel(optional($entry->produced_on)->toDateString()),
                'work_type' => 'production_payout',
            ]))->all(),
            ...$completedRows->map(fn (ProductionEntry $entry): array => $this->makeTask([
                'id' => 'production-paid-'.$entry->id,
                'queue' => 'approval',
                'state' => 'completed',
                'priority' => 'low',
                'title' => 'Production payout settled',
                'why_it_matters' => 'The production payout is closed and no longer waiting.',
                'recommended_action' => 'No action needed. The payout is already paid.',
                'related_record' => $this->relatedRecord('production_entry', $entry->id, trim((string) ($entry->employee_name ?: 'Production payout')).($entry->sku?->code ? ' / '.$entry->sku->code : ''), ProductionEntryResource::getUrl('edit', ['record' => $entry])),
                'assigned_team' => 'Production',
                'assigned_user' => $this->assignedUserLabel($business, ['production', 'factory', 'line', 'supervisor']),
                'created_at' => optional($entry->paid_on)->toDateString() ?? now()->toDateString(),
                'completed_at' => optional($entry->paid_on)->toDateString() ?? now()->toDateString(),
                'status_label' => 'Completed today',
                'work_type' => 'production_payout',
            ]))->all(),
        ];
    }

    private function orderTasks(Business $business): array
    {
        $events = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereIn('event_type', [
                OperationalEvent::ORDER_CREATED,
                OperationalEvent::ORDER_CONFIRMED,
                OperationalEvent::TRACKING_NUMBER_ADDED,
                OperationalEvent::ORDER_DELIVERED,
                OperationalEvent::ORDER_RETURNED,
                OperationalEvent::ORDER_RESENT,
                OperationalEvent::FAKE_ORDER_DETECTED,
            ])
            ->get();

        if ($events->isEmpty()) {
            return [];
        }

        $orders = $events
            ->groupBy(fn (OperationalEvent $event): string => (string) ($event->external_id ?: $event->id))
            ->map(fn (Collection $group): array => [
                'first' => $group->sortBy('occurred_at')->first(),
                'latest' => $group->sortBy('occurred_at')->last(),
            ]);

        $tasks = [];

        foreach ($orders as $order) {
            /** @var OperationalEvent $first */
            $first = $order['first'];
            /** @var OperationalEvent $latest */
            $latest = $order['latest'];
            $label = $this->orderLabel($latest);
            $createdAt = optional($first->occurred_at)->toDateString() ?? now()->toDateString();
            $latestDate = optional($latest->occurred_at)->toDateString() ?? now()->toDateString();
            $related = $this->relatedRecord('order', $latest->external_id ?: $latest->id, $label, OperationalEventResource::getUrl('index'));

            if (in_array($latest->event_type, [OperationalEvent::ORDER_CREATED, OperationalEvent::ORDER_CONFIRMED], true)) {
                $tasks[] = $this->makeTask([
                    'id' => 'order-track-'.($latest->external_id ?: $latest->id),
                    'queue' => 'pending',
                    'state' => 'open',
                    'priority' => $this->datePriority($first->occurred_at),
                    'title' => 'Order needs a tracking number',
                    'why_it_matters' => 'The order cannot move forward until tracking is added.',
                    'recommended_action' => 'Open the order and add the tracking number.',
                    'related_record' => $related,
                    'assigned_team' => 'Operations',
                    'assigned_user' => $this->assignedUserLabel($business, ['operations', 'dispatch', 'sales', 'admin']),
                    'created_at' => $createdAt,
                    'due_on' => $createdAt,
                    'status_label' => $this->statusLabel($createdAt),
                    'work_type' => 'order_tracking',
                ]);
            }

            if ($latest->event_type === OperationalEvent::ORDER_RETURNED) {
                $tasks[] = $this->makeTask([
                    'id' => 'order-return-'.($latest->external_id ?: $latest->id),
                    'queue' => 'exception',
                    'state' => 'open',
                    'priority' => 'high',
                    'title' => 'Return needs action',
                    'why_it_matters' => 'The order came back, so the team needs to decide what happens next.',
                    'recommended_action' => 'Check whether the return should be restocked or treated as damaged.',
                    'related_record' => $related,
                    'assigned_team' => 'Operations',
                    'assigned_user' => $this->assignedUserLabel($business, ['operations', 'dispatch', 'sales', 'admin']),
                    'created_at' => $latestDate,
                    'due_on' => $latestDate,
                    'status_label' => $this->statusLabel($latestDate),
                    'work_type' => 'return_action',
                ]);
            }

            if ($latest->event_type === OperationalEvent::ORDER_RESENT) {
                $tasks[] = $this->makeTask([
                    'id' => 'order-resend-'.($latest->external_id ?: $latest->id),
                    'queue' => 'exception',
                    'state' => 'open',
                    'priority' => $this->datePriority($latest->occurred_at, true),
                    'title' => 'Resend is still open',
                    'why_it_matters' => 'The resend still needs to make it through delivery.',
                    'recommended_action' => 'Check the resend parcel and follow it until it is delivered.',
                    'related_record' => $related,
                    'assigned_team' => 'Operations',
                    'assigned_user' => $this->assignedUserLabel($business, ['operations', 'dispatch', 'sales', 'admin']),
                    'created_at' => $latestDate,
                    'due_on' => $latestDate,
                    'status_label' => $this->statusLabel($latestDate),
                    'work_type' => 'resend_follow_up',
                ]);
            }

            if ($latest->event_type === OperationalEvent::FAKE_ORDER_DETECTED) {
                $tasks[] = $this->makeTask([
                    'id' => 'fake-order-'.($latest->external_id ?: $latest->id),
                    'queue' => 'exception',
                    'state' => 'open',
                    'priority' => 'high',
                    'title' => 'Possible fake order needs checking',
                    'why_it_matters' => 'A fake order can waste stock, courier time, and team effort.',
                    'recommended_action' => 'Check the order details and confirm whether it should be stopped.',
                    'related_record' => $related,
                    'assigned_team' => 'Operations',
                    'assigned_user' => $this->assignedUserLabel($business, ['operations', 'dispatch', 'sales', 'admin']),
                    'created_at' => $latestDate,
                    'due_on' => $latestDate,
                    'status_label' => $this->statusLabel($latestDate),
                    'work_type' => 'fake_order_check',
                ]);
            }

            if ($latest->event_type === OperationalEvent::TRACKING_NUMBER_ADDED && $latest->occurred_at?->isToday()) {
                $tasks[] = $this->makeTask([
                    'id' => 'tracking-added-'.($latest->external_id ?: $latest->id),
                    'queue' => 'pending',
                    'state' => 'completed',
                    'priority' => 'low',
                    'title' => 'Tracking number added',
                    'why_it_matters' => 'The order can now move into dispatch and delivery tracking.',
                    'recommended_action' => 'No action needed. The tracking number has been added.',
                    'related_record' => $related,
                    'assigned_team' => 'Operations',
                    'assigned_user' => $this->assignedUserLabel($business, ['operations', 'dispatch', 'sales', 'admin']),
                    'created_at' => $latestDate,
                    'completed_at' => $latestDate,
                    'status_label' => 'Completed today',
                    'work_type' => 'tracking_added',
                ]);
            }

            if ($latest->event_type === OperationalEvent::ORDER_DELIVERED && $latest->occurred_at?->isToday()) {
                $tasks[] = $this->makeTask([
                    'id' => 'order-delivered-'.($latest->external_id ?: $latest->id),
                    'queue' => 'pending',
                    'state' => 'completed',
                    'priority' => 'low',
                    'title' => 'Order delivered',
                    'why_it_matters' => 'Delivery is complete, so the work has moved past dispatch.',
                    'recommended_action' => 'No action needed. The order has been delivered.',
                    'related_record' => $related,
                    'assigned_team' => 'Operations',
                    'assigned_user' => $this->assignedUserLabel($business, ['operations', 'dispatch', 'sales', 'admin']),
                    'created_at' => $latestDate,
                    'completed_at' => $latestDate,
                    'status_label' => 'Completed today',
                    'work_type' => 'order_delivery',
                ]);
            }
        }

        return $tasks;
    }

    private function inventoryTasks(Business $business): array
    {
        return MaterialLedgerEntry::query()
            ->where('business_id', $business->id)
            ->whereNull('sku_id')
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get()
            ->map(fn (MaterialLedgerEntry $entry): array => $this->makeTask([
                'id' => 'material-link-sku-'.$entry->id,
                'queue' => 'missing_data',
                'state' => 'open',
                'priority' => $this->datePriority($entry->occurred_on, true),
                'title' => 'Material entry needs a SKU',
                'why_it_matters' => 'Without a SKU, the stock record cannot be trusted.',
                'recommended_action' => 'Open the material entry and link it to the right product.',
                'related_record' => $this->relatedRecord('material_ledger_entry', $entry->id, trim((string) $entry->component_name ?: 'Material entry'), MaterialLedgerResource::getUrl('edit', ['record' => $entry])),
                'assigned_team' => 'Inventory',
                'assigned_user' => $this->assignedUserLabel($business, ['inventory', 'store', 'warehouse', 'stock']),
                'created_at' => optional($entry->occurred_on)->toDateString() ?? now()->toDateString(),
                'due_on' => optional($entry->occurred_on)->toDateString() ?? now()->toDateString(),
                'status_label' => $this->statusLabel(optional($entry->occurred_on)->toDateString()),
                'work_type' => 'missing_material_sku',
            ]))
            ->all();
    }

    private function exceptionTasks(Business $business): array
    {
        return OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereIn('event_type', [
                OperationalEvent::PRODUCTION_WASTE,
            ])
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (OperationalEvent $event): array => $this->makeTask([
                'id' => 'production-waste-'.$event->id,
                'queue' => 'exception',
                'state' => 'open',
                'priority' => 'medium',
                'title' => 'Production waste needs checking',
                'why_it_matters' => 'Waste makes the month heavier and hides the real output story.',
                'recommended_action' => 'Open the waste record and review what went wrong.',
                'related_record' => $this->relatedRecord('operational_event', $event->id, 'Production waste', OperationalEventResource::getUrl('index')),
                'assigned_team' => 'Production',
                'assigned_user' => $this->assignedUserLabel($business, ['production', 'factory', 'line', 'supervisor']),
                'created_at' => optional($event->occurred_at)->toDateString() ?? now()->toDateString(),
                'due_on' => optional($event->occurred_at)->toDateString() ?? now()->toDateString(),
                'status_label' => $this->statusLabel(optional($event->occurred_at)->toDateString()),
                'work_type' => 'production_waste',
            ]))
            ->all();
    }

    private function movementTasks(Business $business): array
    {
        return SkuStockMovement::query()
            ->where('business_id', $business->id)
            ->whereDate('occurred_at', today())
            ->whereIn('movement_type', ['dispatch', 'resend_dispatch', 'return_restocked', 'return_damaged'])
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (SkuStockMovement $movement): array => $this->makeTask([
                'id' => 'stock-movement-'.$movement->id,
                'queue' => 'pending',
                'state' => 'completed',
                'priority' => 'low',
                'title' => $this->movementTitle($movement->movement_type),
                'why_it_matters' => 'The stock movement has been recorded and the workflow moved forward.',
                'recommended_action' => 'No action needed. The movement has already been logged.',
                'related_record' => $this->relatedRecord('stock_movement', $movement->id, $this->movementTitle($movement->movement_type), OperationalEventResource::getUrl('index')),
                'assigned_team' => $this->movementTeam($movement->movement_type),
                'assigned_user' => $this->assignedUserLabel($business, $this->movementKeywords($movement->movement_type)),
                'created_at' => optional($movement->occurred_at)->toDateString() ?? now()->toDateString(),
                'completed_at' => optional($movement->occurred_at)->toDateString() ?? now()->toDateString(),
                'status_label' => 'Completed today',
                'work_type' => 'stock_movement',
            ]))
            ->all();
    }

    private function makeTask(array $task): array
    {
        return array_merge([
            'id' => null,
            'queue' => 'pending',
            'state' => 'open',
            'priority' => 'medium',
            'title' => '',
            'why_it_matters' => '',
            'recommended_action' => '',
            'related_record' => null,
            'assigned_team' => 'Unassigned',
            'assigned_user' => 'Unassigned',
            'created_at' => null,
            'due_on' => null,
            'completed_at' => null,
            'status_label' => 'Waiting',
            'work_type' => 'general',
        ], $task);
    }

    private function relatedRecord(string $type, mixed $id, string $label, ?string $url = null): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'label' => $label,
            'url' => $url,
        ];
    }

    private function assignedUserLabel(Business $business, array $keywords): string
    {
        $employee = Employee::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->get()
            ->first(function (Employee $employee) use ($keywords): bool {
                $role = strtolower((string) $employee->role);

                foreach ($keywords as $keyword) {
                    if ($keyword !== '' && str_contains($role, strtolower($keyword))) {
                        return true;
                    }
                }

                return false;
            });

        if ($employee instanceof Employee) {
            return trim($employee->name.' ('.$employee->role.')');
        }

        return ucfirst($keywords[0] ?? 'team');
    }

    private function taskSortKey(array $task): string
    {
        $priorityWeight = match ($task['priority'] ?? 'medium') {
            'high' => '1',
            'medium' => '2',
            default => '3',
        };

        return $priorityWeight.'|'.($task['due_on'] ?? '9999-12-31').'|'.($task['created_at'] ?? '9999-12-31').'|'.($task['id'] ?? '');
    }

    private function datePriority(mixed $date, bool $mediumIfToday = false): string
    {
        if (! $date instanceof Carbon) {
            $date = $date ? Carbon::parse($date) : null;
        }

        if (! $date instanceof Carbon) {
            return 'medium';
        }

        if ($date->lessThan(today())) {
            return 'high';
        }

        if ($mediumIfToday && $date->isToday()) {
            return 'medium';
        }

        return $date->isToday() ? 'medium' : 'low';
    }

    private function expensePriority(Expense $expense): string
    {
        $due = $expense->due_on ? Carbon::parse($expense->due_on) : null;

        if ($due instanceof Carbon && $due->lessThan(today())) {
            return 'high';
        }

        if (in_array($expense->payment_status, ['cheque_pending', 'credit_due'], true)) {
            return 'high';
        }

        return 'medium';
    }

    private function expenseTaskTitle(Expense $expense): string
    {
        if (! empty($expense->payee)) {
            return 'Supplier payment needs settlement';
        }

        if (in_array($expense->payment_status, ['cheque_pending', 'credit_due'], true)) {
            return 'Supplier payment needs settlement';
        }

        return 'Expense needs settlement';
    }

    private function expenseStatusLabel(Expense $expense): string
    {
        if ($expense->due_on && Carbon::parse($expense->due_on)->lessThan(today())) {
            return 'Overdue';
        }

        if ($expense->due_on && Carbon::parse($expense->due_on)->isToday()) {
            return 'Due today';
        }

        return 'Waiting';
    }

    private function statusLabel(?string $dueOn): string
    {
        if (blank($dueOn)) {
            return 'Waiting';
        }

        $date = Carbon::parse($dueOn);

        if ($date->lessThan(today())) {
            return 'Overdue';
        }

        if ($date->isToday()) {
            return 'Due today';
        }

        return 'Waiting';
    }

    private function completionRate(int $openCount, int $completedToday): string
    {
        $total = $openCount + $completedToday;

        if ($total <= 0) {
            return '100%';
        }

        return number_format(($completedToday / $total) * 100, 0).'%' ;
    }

    private function orderLabel(OperationalEvent $event): string
    {
        return trim((string) ($event->payload['order_number'] ?? $event->external_id ?? 'Order '.$event->id));
    }

    private function movementTitle(string $movementType): string
    {
        return match ($movementType) {
            'dispatch' => 'Stock dispatched',
            'resend_dispatch' => 'Resend stock dispatched',
            'return_restocked' => 'Returned stock restocked',
            'return_damaged' => 'Returned stock marked damaged',
            default => 'Stock movement recorded',
        };
    }

    private function movementTeam(string $movementType): string
    {
        return match ($movementType) {
            'dispatch', 'resend_dispatch' => 'Operations',
            'return_restocked', 'return_damaged' => 'Inventory',
            default => 'Operations',
        };
    }

    private function movementKeywords(string $movementType): array
    {
        return match ($movementType) {
            'dispatch', 'resend_dispatch' => ['operations', 'dispatch', 'sales', 'admin'],
            'return_restocked', 'return_damaged' => ['inventory', 'store', 'warehouse', 'stock'],
            default => ['operations'],
        };
    }

    private function needsBusinessAssignment(BankTransaction $transaction): bool
    {
        return in_array($transaction->transaction_type, [
            'revenue',
            'expense',
            'loan',
            'owner_contribution',
            'owner_withdrawal',
        ], true);
    }

    private function looksLikeTransfer(BankTransaction $transaction): bool
    {
        $description = Str::lower(trim((string) $transaction->description));

        return $transaction->transaction_type === 'transfer'
            || $transaction->classification === 'transfer'
            || Str::contains($description, ['transfer', 'trf', 'internal transfer', 'inter account']);
    }
}

<?php

namespace App\Domains\Shared\Services;

use App\Domains\FinancialClarity\Services\OperationalEventRecalculator;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\CodOrder;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\MaterialLedgerEntry;
use App\Domains\Shared\Models\Mission;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\Sku;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class MissionSourceActionService
{
    private const OWNER_ONLY_BANK_TYPES = [
        'owner_contribution',
        'owner_withdrawal',
        'loan',
    ];

    public function __construct(private readonly OperationalEventRecalculator $recalculator)
    {
    }

    public function canComplete(User $user, Mission $mission): bool
    {
        if ($user->isOwner() || $user->isInternalAdmin()) {
            return true;
        }

        return $user->activeStaffResponsibilityAssignments()
            ->where('business_id', $mission->business_id)
            ->where('responsibility_code', $mission->responsibility_code)
            ->where('can_complete', true)
            ->exists();
    }

    public function defaultData(Mission $mission): array
    {
        $source = $this->sourceRecord($mission);

        if ($source instanceof BankTransaction) {
            return [
                'classification' => $source->classification ?: 'unknown',
                'transaction_type' => $source->transaction_type ?: '',
                'allocated_business_id' => $source->allocated_business_id ?: $source->business_id,
                'money_container' => $source->money_container,
                'counter_money_container' => $source->counter_money_container,
                'note' => '',
            ];
        }

        if ($source instanceof Expense) {
            return [
                'payee' => $source->payee,
                'due_on' => optional($source->due_on)->format('Y-m-d'),
                'paid_amount' => (float) ($source->paid_amount ?? 0),
                'payment_status' => $source->payment_status ?: 'unpaid',
                'payment_method' => $source->payment_method,
                'note' => '',
            ];
        }

        if ($source instanceof ServiceBillingRecord) {
            return [
                'paid_amount' => (float) ($source->paid_amount ?? 0),
                'payment_status' => $source->payment_status ?: 'unpaid',
                'payment_method' => $source->payment_method,
                'reference' => $source->reference,
                'note' => $source->note,
            ];
        }

        if ($source instanceof ProductionEntry) {
            return [
                'payment_status' => $source->payment_status ?: 'pending',
                'paid_on' => optional($source->paid_on)->format('Y-m-d'),
                'note' => '',
            ];
        }

        if ($source instanceof MaterialLedgerEntry) {
            return [
                'sku_id' => $source->sku_id,
                'note' => $source->note,
            ];
        }

        if ($source instanceof OperationalEvent) {
            $payload = is_array($source->payload) ? $source->payload : [];

            return [
                'sku_id' => $source->sku_id,
                'tracking_number' => $payload['tracking_number'] ?? $payload['tracking'] ?? null,
                'courier_name' => $payload['courier_name'] ?? $payload['courier'] ?? null,
                'return_outcome' => $payload['return_outcome'] ?? $payload['return_stock'] ?? null,
                'return_reason' => $payload['return_reason'] ?? null,
                'follow_up_note' => $payload['follow_up_note'] ?? null,
                'note' => '',
            ];
        }

        if ($source instanceof CodOrder) {
            return [
                'tracking_number' => $source->tracking_number,
                'courier_name' => $source->courier_name,
                'status' => $source->status,
                'return_reason' => $source->return_reason,
                'resend_reason' => $source->resend_reason,
                'note' => $source->remarks,
            ];
        }

        return ['note' => ''];
    }

    public function apply(Mission $mission, User $user, array $data): Mission
    {
        if (! $this->canComplete($user, $mission)) {
            throw ValidationException::withMessages([
                'mission' => 'You can view this mission, but you are not allowed to complete it.',
            ]);
        }

        $source = $this->sourceRecord($mission);

        if (! $source) {
            $mission->block($user, 'This mission has no editable source record yet.');

            return $mission->refresh();
        }

        $before = $source->getAttributes();

        match (true) {
            $source instanceof BankTransaction => $this->applyBankTransaction($source, $mission, $user, $data),
            $source instanceof Expense => $this->applyExpense($source, $data),
            $source instanceof ServiceBillingRecord => $this->applyServiceBilling($source, $data),
            $source instanceof ProductionEntry => $this->applyProductionEntry($source, $data),
            $source instanceof MaterialLedgerEntry => $this->applyMaterialLedgerEntry($source, $data),
            $source instanceof OperationalEvent => $this->applyOperationalEvent($source, $data),
            $source instanceof CodOrder => $this->applyCodOrder($source, $data),
            default => null,
        };

        $source->refresh();
        $mission->recordEvent('source_action', $user, 'Source record updated from Today\'s Work.', $before, $source->getAttributes());

        if ($mission->status === Mission::STATUS_ESCALATED || $mission->status === Mission::STATUS_WAITING_REVIEW) {
            return $mission->refresh();
        }

        if ($this->isResolved($mission->refresh())) {
            $mission->complete($user, 'Source condition resolved from mission action.');
        } else {
            $mission->start($user);
            $mission->recordEvent('source_action_incomplete', $user, 'Source updated, but more work is still needed.');
        }

        return $mission->refresh();
    }

    public function completeIfResolved(Mission $mission, User $user): bool
    {
        if (! $this->canComplete($user, $mission)) {
            return false;
        }

        if (! $this->isResolved($mission)) {
            return false;
        }

        $mission->complete($user, 'Source condition was already resolved.');

        return true;
    }

    public function isResolved(Mission $mission): bool
    {
        $source = $this->sourceRecord($mission);

        if (! $source) {
            return false;
        }

        return match (true) {
            $source instanceof BankTransaction => $source->status === 'classified'
                && filled($source->classification)
                && filled($source->transaction_type)
                && (! BankTransaction::needsBusinessAssignment($source->transaction_type) || filled($source->allocated_business_id))
                && ($source->transaction_type !== 'transfer' || filled($source->counter_money_container)),
            $source instanceof Expense => in_array($source->payment_status, ['paid', 'settled'], true)
                || (filled($source->payee) && filled($source->due_on)),
            $source instanceof ServiceBillingRecord => $source->balanceDue() <= 0.01,
            $source instanceof ProductionEntry => in_array($source->payment_status, ['paid', 'settled'], true),
            $source instanceof MaterialLedgerEntry => filled($source->sku_id),
            $source instanceof OperationalEvent => $this->operationalEventResolved($source, $mission),
            $source instanceof CodOrder => $this->codOrderResolved($source, $mission),
            default => false,
        };
    }

    public function sourceRecord(Mission $mission): ?Model
    {
        if (blank($mission->source_type) || blank($mission->source_id)) {
            return null;
        }

        return match ((string) $mission->source_type) {
            'bank_transaction' => BankTransaction::query()->whereKey($mission->source_id)->first(),
            'expense' => Expense::query()->whereKey($mission->source_id)->first(),
            'service_billing_record' => ServiceBillingRecord::query()->whereKey($mission->source_id)->first(),
            'production_entry' => ProductionEntry::query()->whereKey($mission->source_id)->first(),
            'material_ledger_entry' => MaterialLedgerEntry::query()->whereKey($mission->source_id)->first(),
            'operational_event' => OperationalEvent::query()->whereKey($mission->source_id)->first(),
            'order' => OperationalEvent::query()
                ->where('business_id', $mission->business_id)
                ->where('external_id', $mission->source_id)
                ->latest('occurred_at')
                ->latest('id')
                ->first(),
            'cod_order' => CodOrder::query()->whereKey($mission->source_id)->first(),
            default => null,
        };
    }

    private function applyBankTransaction(BankTransaction $transaction, Mission $mission, User $user, array $data): void
    {
        $classification = $this->clean($data['classification'] ?? $transaction->classification);
        $transactionType = $this->clean($data['transaction_type'] ?? null) ?: BankTransaction::inferTransactionType($classification) ?: $transaction->transaction_type;

        if (! ($user->isOwner() || $user->isInternalAdmin()) && in_array($transactionType, self::OWNER_ONLY_BANK_TYPES, true)) {
            $transaction->forceFill(['status' => 'review'])->save();
            $mission->transition(Mission::STATUS_WAITING_REVIEW, $user, 'submitted_for_owner_review', 'Owner-only bank decision submitted for review.');

            return;
        }

        $allocatedBusinessId = filled($data['allocated_business_id'] ?? null) ? (int) $data['allocated_business_id'] : null;

        $transaction->forceFill([
            'classification' => $classification ?: 'unknown',
            'transaction_type' => $transactionType ?: 'other',
            'allocated_business_id' => BankTransaction::needsBusinessAssignment($transactionType) ? ($allocatedBusinessId ?: $transaction->business_id) : $allocatedBusinessId,
            'money_container' => $this->clean($data['money_container'] ?? $transaction->money_container),
            'counter_money_container' => $this->clean($data['counter_money_container'] ?? $transaction->counter_money_container),
            'status' => 'classified',
            'reviewed_at' => now(),
            'raw_payload' => $this->mergePayloadNote($transaction->raw_payload, $data['note'] ?? null),
        ])->save();
    }

    private function applyExpense(Expense $expense, array $data): void
    {
        $paidAmount = $this->money($data['paid_amount'] ?? $expense->paid_amount);
        $paymentStatus = $this->clean($data['payment_status'] ?? $expense->payment_status) ?: $expense->payment_status;

        $expense->forceFill([
            'payee' => $this->clean($data['payee'] ?? $expense->payee),
            'due_on' => $this->date($data['due_on'] ?? null) ?: $expense->due_on,
            'paid_amount' => $paidAmount,
            'payment_status' => $paymentStatus,
            'payment_method' => $this->clean($data['payment_method'] ?? $expense->payment_method),
            'settled_on' => in_array($paymentStatus, ['paid', 'settled'], true) ? today() : $expense->settled_on,
            'description' => $this->appendNote($expense->description, $data['note'] ?? null),
        ])->save();
    }

    private function applyServiceBilling(ServiceBillingRecord $billing, array $data): void
    {
        $paidAmount = $this->money($data['paid_amount'] ?? $billing->paid_amount);
        $status = $this->clean($data['payment_status'] ?? null);

        if (! $status) {
            $status = $paidAmount >= (float) $billing->amount_due ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid');
        }

        $billing->forceFill([
            'paid_amount' => $paidAmount,
            'payment_status' => $status,
            'paid_on' => $status === 'paid' ? today() : $billing->paid_on,
            'payment_method' => $this->clean($data['payment_method'] ?? $billing->payment_method),
            'reference' => $this->clean($data['reference'] ?? $billing->reference),
            'note' => $this->appendNote($billing->note, $data['note'] ?? null),
        ])->save();
    }

    private function applyProductionEntry(ProductionEntry $entry, array $data): void
    {
        $status = $this->clean($data['payment_status'] ?? $entry->payment_status) ?: 'pending';

        $entry->forceFill([
            'payment_status' => $status,
            'paid_on' => in_array($status, ['paid', 'settled'], true)
                ? ($this->date($data['paid_on'] ?? null) ?: today())
                : $entry->paid_on,
            'note' => $this->appendNote($entry->note, $data['note'] ?? null),
        ])->save();
    }

    private function applyMaterialLedgerEntry(MaterialLedgerEntry $entry, array $data): void
    {
        $entry->forceFill([
            'sku_id' => filled($data['sku_id'] ?? null) ? (int) $data['sku_id'] : $entry->sku_id,
            'note' => $this->appendNote($entry->note, $data['note'] ?? null),
        ])->save();
    }

    private function applyOperationalEvent(OperationalEvent $event, array $data): void
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        foreach (['tracking_number', 'courier_name', 'return_outcome', 'return_reason', 'follow_up_note'] as $key) {
            if (filled($data[$key] ?? null)) {
                $payload[$key] = $this->clean($data[$key]);
            }
        }

        if (filled($data['sku_id'] ?? null)) {
            $sku = Sku::query()
                ->where('business_id', $event->business_id)
                ->whereKey((int) $data['sku_id'])
                ->first();

            if ($sku) {
                $event->forceFill(['sku_id' => $sku->id])->save();
                $this->recalculator->recalculateSingleEvent($event->refresh(), $sku);
                $payload = array_merge(is_array($event->refresh()->payload) ? $event->payload : [], $payload);
            }
        }

        $event->forceFill(['payload' => $this->mergePayloadNote($payload, $data['note'] ?? null)])->save();
    }

    private function applyCodOrder(CodOrder $order, array $data): void
    {
        $order->forceFill([
            'tracking_number' => $this->clean($data['tracking_number'] ?? $order->tracking_number),
            'courier_name' => $this->clean($data['courier_name'] ?? $order->courier_name),
            'status' => $this->clean($data['status'] ?? $order->status) ?: $order->status,
            'return_reason' => $this->clean($data['return_reason'] ?? $order->return_reason),
            'resend_reason' => $this->clean($data['resend_reason'] ?? $order->resend_reason),
            'remarks' => $this->appendNote($order->remarks, $data['note'] ?? null),
        ])->save();
    }

    private function operationalEventResolved(OperationalEvent $event, Mission $mission): bool
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        return match ($mission->mission_type) {
            'return_action' => filled($payload['return_outcome'] ?? null) || filled($payload['return_reason'] ?? null),
            'resend_follow_up' => in_array($event->event_type, [OperationalEvent::ORDER_DELIVERED, OperationalEvent::ORDER_RETURNED], true),
            'order_tracking' => filled($payload['tracking_number'] ?? null) || filled($payload['tracking'] ?? null),
            'fake_order_check' => filled($payload['follow_up_note'] ?? null),
            default => filled($event->sku_id) && $event->sku?->productionCostPerUnit() > 0,
        };
    }

    private function codOrderResolved(CodOrder $order, Mission $mission): bool
    {
        return match ($mission->mission_type) {
            'order_tracking' => filled($order->tracking_number) && filled($order->courier_name),
            'return_action' => filled($order->return_reason),
            'resend_follow_up' => filled($order->resend_reason) || $order->status === CodOrder::STATUS_DELIVERED,
            default => true,
        };
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return filled($value) ? (string) $value : null;
    }

    private function money(mixed $value): float
    {
        return max((float) ($value ?? 0), 0);
    }

    private function date(mixed $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value) : null;
    }

    private function appendNote(?string $existing, mixed $note): ?string
    {
        $note = $this->clean($note);

        if (! $note) {
            return $existing;
        }

        return trim((string) $existing) === ''
            ? $note
            : trim((string) $existing)."\n".$note;
    }

    private function mergePayloadNote(mixed $payload, mixed $note): array
    {
        $payload = is_array($payload) ? $payload : [];
        $note = $this->clean($note);

        if ($note) {
            $payload['mission_note'] = $note;
        }

        return $payload;
    }
}

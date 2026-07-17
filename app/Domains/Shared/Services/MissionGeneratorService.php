<?php

namespace App\Domains\Shared\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Mission;
use App\Domains\Shared\Models\StaffResponsibilityAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

class MissionGeneratorService
{
    public function __construct(private readonly WorkQueueService $workQueue)
    {
    }

    public function syncForBusiness(Business $business): Collection
    {
        $workQueue = $this->workQueue->forBusiness($business);
        $tasks = collect($workQueue['tasks'] ?? []);
        $activeSourceKeys = [];

        $missions = $tasks
            ->filter(fn (array $task): bool => filled($task['id']))
            ->map(function (array $task) use ($business, &$activeSourceKeys): Mission {
                $sourceKey = $business->id.':'.(string) $task['id'];
                $activeSourceKeys[] = $sourceKey;
                $related = is_array($task['related_record'] ?? null) ? $task['related_record'] : [];
                $responsibility = $this->responsibilityForTask($task);
                $status = $this->missionStatusForTask($task);

                $mission = Mission::query()->updateOrCreate(
                    ['source_key' => $sourceKey],
                    [
                        'mission_type' => (string) ($task['work_type'] ?? 'general'),
                        'responsibility_code' => $responsibility,
                        'business_id' => $business->id,
                        'source_type' => $related['type'] ?? null,
                        'source_id' => filled($related['id'] ?? null) ? (string) $related['id'] : null,
                        'title' => (string) ($task['title'] ?? 'Work item'),
                        'summary' => (string) ($task['why_it_matters'] ?? ''),
                        'priority' => $this->missionPriority((string) ($task['priority'] ?? 'medium')),
                        'impact_type' => $this->impactTypeForResponsibility($responsibility),
                        'estimated_impact' => filled($task['amount'] ?? null) ? (float) $task['amount'] : null,
                        'confidence' => filled($task['amount'] ?? null) ? 'estimated' : 'incomplete',
                        'due_at' => filled($task['due_on'] ?? null) ? now()->parse($task['due_on'])->endOfDay() : null,
                        'assigned_user_id' => $this->assignedUserId($business, $responsibility),
                        'status' => $status,
                        'completed_at' => $status === Mission::STATUS_COMPLETED
                            ? (filled($task['completed_at'] ?? null) ? now()->parse($task['completed_at'])->endOfDay() : now())
                            : null,
                        'metadata' => [
                            'recommended_action' => $task['recommended_action'] ?? null,
                            'status_label' => $task['status_label'] ?? null,
                            'assigned_team' => $task['assigned_team'] ?? null,
                            'assigned_user_label' => $task['assigned_user'] ?? null,
                            'related_record' => $related,
                        ],
                    ]
                );

                if ($mission->wasRecentlyCreated) {
                    $mission->recordEvent('generated', null, 'Generated from HELOS business condition.');
                }

                return $mission;
            });

        Mission::query()
            ->where('business_id', $business->id)
            ->active()
            ->whereNotIn('source_key', $activeSourceKeys)
            ->get()
            ->each(function (Mission $mission): void {
                $mission->forceFill([
                    'status' => Mission::STATUS_CANCELLED,
                    'blocked_reason' => 'The source condition no longer needs action.',
                ])->save();

                $mission->recordEvent('cancelled', null, 'The business condition disappeared.');
            });

        return $missions->values();
    }

    public function visibleForUser(User $user): Collection
    {
        if (! $user->isStaff()) {
            return collect();
        }

        $businessIds = $user->accessibleBusinessIds();

        if ($businessIds === []) {
            return collect();
        }

        $businesses = Business::query()->whereIn('id', $businessIds)->get();

        $businesses->each(fn (Business $business) => $this->syncForBusiness($business));

        $responsibilitiesByBusiness = collect($businessIds)
            ->mapWithKeys(fn (int $businessId): array => [$businessId => $user->staffResponsibilities($businessId)])
            ->filter(fn (array $responsibilities): bool => $responsibilities !== []);

        if ($responsibilitiesByBusiness->isEmpty()) {
            return collect();
        }

        return Mission::query()
            ->whereIn('business_id', $businessIds)
            ->active()
            ->where(function ($query) use ($responsibilitiesByBusiness): void {
                foreach ($responsibilitiesByBusiness as $businessId => $responsibilities) {
                    $query->orWhere(function ($query) use ($businessId, $responsibilities): void {
                        $query->where('business_id', $businessId)
                            ->whereIn('responsibility_code', $responsibilities);
                    });
                }
            })
            ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('due_at')
            ->orderBy('created_at')
            ->get();
    }

    public function canUserAccessMission(User $user, Mission $mission): bool
    {
        if ($user->isOwner() || $user->isInternalAdmin()) {
            return in_array($mission->business_id, $user->accessibleBusinessIds(), true) || $user->isInternalAdmin();
        }

        return $user->hasStaffResponsibility((string) $mission->responsibility_code, (int) $mission->business_id);
    }

    private function responsibilityForTask(array $task): ?string
    {
        return match ((string) ($task['work_type'] ?? 'general')) {
            'order_tracking', 'tracking_added', 'order_delivery' => 'dispatch',
            'return_action', 'resend_follow_up' => 'return_recovery',
            'fake_order_check' => 'order_confirmation',
            'wholesale_collection', 'service_collection' => 'collections',
            'bank_account_review',
            'bank_review',
            'bank_transaction_type',
            'bank_business_assignment',
            'bank_transfer_destination',
            'bank_transfer_confirmation' => 'bank_exceptions',
            'expense_settlement' => 'expense_recording',
            'production_payout', 'production_waste' => 'production',
            'missing_material_sku', 'stock_movement' => 'material_stock',
            'missing_product_links' => 'product_repair',
            default => null,
        };
    }

    private function missionStatusForTask(array $task): string
    {
        if (($task['state'] ?? 'open') === 'completed') {
            return Mission::STATUS_COMPLETED;
        }

        if (($task['queue'] ?? '') === 'review') {
            return Mission::STATUS_WAITING_REVIEW;
        }

        return Mission::STATUS_OPEN;
    }

    private function missionPriority(string $priority): string
    {
        return match ($priority) {
            'high' => 'high',
            'low' => 'low',
            default => 'normal',
        };
    }

    private function impactTypeForResponsibility(?string $responsibility): ?string
    {
        return match ($responsibility) {
            'dispatch' => 'revenue_protected',
            'return_recovery' => 'revenue_recoverable',
            'collections' => 'cash_collectible',
            'bank_exceptions', 'product_repair' => 'financial_truth_blocked',
            'expense_recording', 'production', 'material_stock' => 'cost_avoidable',
            default => null,
        };
    }

    private function assignedUserId(Business $business, ?string $responsibility): ?int
    {
        if (! $responsibility) {
            return null;
        }

        return StaffResponsibilityAssignment::query()
            ->activeNow()
            ->where('business_id', $business->id)
            ->where('responsibility_code', $responsibility)
            ->orderByDesc('can_complete')
            ->orderBy('id')
            ->value('user_id');
    }
}

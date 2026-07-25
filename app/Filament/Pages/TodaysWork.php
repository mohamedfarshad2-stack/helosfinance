<?php

namespace App\Filament\Pages;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Mission;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Services\MissionGeneratorService;
use App\Domains\Shared\Services\MissionSourceActionService;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TodaysWork extends Page
{
    protected static ?string $slug = 'todays-work';

    protected static ?string $navigationGroup = 'My Work';

    protected static ?string $navigationLabel = 'My guided dashboard';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.todays-work';

    public ?Business $business = null;

    public array $workQueue = [];

    public ?int $activeMissionId = null;

    public array $missionActionData = [];

    public ?string $missionActionError = null;

    public function mount(MissionGeneratorService $missions): void
    {
        $businessId = Auth::user()?->business_id;
        $this->business = $businessId ? Business::query()->find($businessId) : null;
        $this->workQueue = $this->employeeWorkQueue($missions->visibleForUser(Auth::user()));
    }

    protected function getViewData(): array
    {
        return [
            'business' => $this->business,
            'workQueue' => $this->workQueue,
            'activeMission' => $this->activeMission(),
            'actionOptions' => $this->actionOptions(),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->isStaff() ?? false;
    }

    public static function canAccess(): bool
    {
        return Auth::check() && (Auth::user()?->isStaff() ?? false);
    }

    public function startMission(int $missionId, MissionGeneratorService $missions): void
    {
        $mission = Mission::query()->findOrFail($missionId);
        $user = Auth::user();

        if (! $user || ! $missions->canUserAccessMission($user, $mission)) {
            abort(403);
        }

        $mission->start($user);
        $this->workQueue = $this->employeeWorkQueue($missions->visibleForUser($user));

        Notification::make()->title('Mission started')->success()->send();
    }

    public function completeMission(int $missionId, MissionGeneratorService $missions, MissionSourceActionService $actions): void
    {
        $mission = Mission::query()->findOrFail($missionId);
        $user = Auth::user();

        if (! $user || ! $missions->canUserAccessMission($user, $mission)) {
            abort(403);
        }

        if (! $actions->completeIfResolved($mission, $user)) {
            Notification::make()
                ->title('Finish the source action first')
                ->body('This mission stays open until the real bank, expense, product, production, order, or collection record is fixed.')
                ->warning()
                ->send();

            return;
        }

        $this->workQueue = $this->employeeWorkQueue($missions->visibleForUser($user));

        Notification::make()->title('Mission completed')->success()->send();
    }

    public function blockMission(int $missionId, MissionGeneratorService $missions): void
    {
        $mission = Mission::query()->findOrFail($missionId);
        $user = Auth::user();

        if (! $user || ! $missions->canUserAccessMission($user, $mission)) {
            abort(403);
        }

        $mission->block($user, 'Blocked by staff from Today\'s Work.');
        $this->workQueue = $this->employeeWorkQueue($missions->visibleForUser($user));

        Notification::make()->title('Mission marked blocked')->warning()->send();
    }

    public function escalateMission(int $missionId, MissionGeneratorService $missions): void
    {
        $mission = Mission::query()->findOrFail($missionId);
        $user = Auth::user();

        if (! $user || ! $missions->canUserAccessMission($user, $mission)) {
            abort(403);
        }

        $mission->escalate($user, 'Escalated from Today\'s Work.');
        $this->workQueue = $this->employeeWorkQueue($missions->visibleForUser($user));

        Notification::make()->title('Mission escalated')->warning()->send();
    }

    public function openMissionAction(int $missionId, MissionGeneratorService $missions, MissionSourceActionService $actions): void
    {
        $mission = Mission::query()->findOrFail($missionId);
        $user = Auth::user();

        if (! $user || ! $missions->canUserAccessMission($user, $mission)) {
            abort(403);
        }

        $this->activeMissionId = $mission->id;
        $this->missionActionData = $actions->defaultData($mission);
        $this->missionActionError = null;
    }

    public function closeMissionAction(): void
    {
        $this->activeMissionId = null;
        $this->missionActionData = [];
        $this->missionActionError = null;
    }

    public function saveMissionAction(MissionGeneratorService $missions, MissionSourceActionService $actions): void
    {
        $mission = Mission::query()->findOrFail($this->activeMissionId);
        $user = Auth::user();

        if (! $user || ! $missions->canUserAccessMission($user, $mission)) {
            abort(403);
        }

        try {
            $mission = $actions->apply($mission, $user, $this->missionActionData);
        } catch (ValidationException $exception) {
            $this->missionActionError = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->workQueue = $this->employeeWorkQueue($missions->visibleForUser($user));
        $this->closeMissionAction();

        Notification::make()
            ->title($mission->status === Mission::STATUS_COMPLETED ? 'Mission completed' : 'Mission updated')
            ->body($mission->status === Mission::STATUS_COMPLETED ? 'The source record is fixed, so HELOS closed the mission.' : 'The source record was updated. HELOS kept the mission open because more work or review is needed.')
            ->success()
            ->send();
    }

    private function employeeWorkQueue(Collection $missions): array
    {
        $tasks = $missions
            ->map(fn (Mission $mission): array => $this->missionTask($mission))
            ->values();

        $sections = [
            'due_today' => $tasks->filter(fn (array $task): bool => filled($task['due_on']) && Carbon::parse($task['due_on'])->lessThanOrEqualTo(today()) && $task['state'] !== 'completed')->values()->all(),
            'high_priority' => $tasks->filter(fn (array $task): bool => in_array($task['priority'], ['critical', 'high'], true) && $task['state'] !== 'completed')->values()->all(),
            'waiting_review' => $tasks->filter(fn (array $task): bool => in_array($task['state'], ['waiting_review', 'escalated', 'blocked'], true))->values()->all(),
            'completed_today' => $tasks->filter(fn (array $task): bool => $task['state'] === 'completed' && filled($task['completed_at']) && Carbon::parse($task['completed_at'])->isToday())->values()->all(),
            'problems' => $tasks->filter(fn (array $task): bool => in_array($task['state'], ['blocked', 'escalated'], true))->values()->all(),
            'missing_information' => $tasks->filter(fn (array $task): bool => in_array($task['responsibility_code'] ?? '', ['bank_exceptions', 'product_repair', 'material_stock'], true) && $task['state'] !== 'completed')->values()->all(),
        ];

        $openTasks = $tasks
            ->whereIn('state', [
                Mission::STATUS_OPEN,
                Mission::STATUS_IN_PROGRESS,
                Mission::STATUS_BLOCKED,
                Mission::STATUS_WAITING_REVIEW,
                Mission::STATUS_ESCALATED,
                Mission::STATUS_REOPENED,
            ])
            ->values();
        $completedToday = $tasks
            ->where('state', 'completed')
            ->filter(fn (array $task): bool => filled($task['completed_at']) && Auth::user() && Carbon::parse($task['completed_at'])->isToday())
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

        $responsibilityGroups = $openTasks
            ->groupBy(fn (array $task): string => $this->taskResponsibility($task) ?? 'general')
            ->map(fn (Collection $group, string $responsibility): array => [
                'key' => $responsibility,
                'label' => $this->responsibilityLabel($responsibility),
                'count' => $group->count(),
                'high_priority' => $group->where('priority', 'high')->count(),
                'tasks' => $group->sortBy(fn (array $task): string => $this->taskSortKey($task))->values()->all(),
            ])
            ->sortByDesc('count')
            ->values();

        return [
            'headline' => $openTasks->isEmpty()
                ? 'Your missions are clear right now.'
                : 'Start with the mission that protects the most money or removes the biggest blocker.',
            'summary' => [
                'Tasks due today' => $dueToday->count(),
                'High priority' => $highPriority->count(),
                'Waiting for review' => $waitingReview->count(),
                'Completed today' => $completedToday->count(),
                'Overdue' => $blockedWork->count(),
                'Completion rate' => $this->employeeCompletionRate($openTasks->count(), $completedToday->count()),
            ],
            'sections' => $sections,
            'todays_priority' => $openTasks->sortBy(fn (array $task): string => $this->taskSortKey($task))->first(),
            'ranked_missions' => $openTasks->sortBy(fn (array $task): string => $this->taskSortKey($task))->values()->all(),
            'tasks' => $tasks->all(),
            'team_workload' => $teamWorkload->all(),
            'responsibility_groups' => $responsibilityGroups->all(),
            'my_responsibilities' => $this->myResponsibilityLabels(),
            'open_count' => $openTasks->count(),
            'blocked_count' => $blockedWork->count(),
            'completed_today_count' => $completedToday->count(),
            'employee_guide' => $this->employeeGuide(),
            'team_summary' => $this->teamSummary(),
        ];
    }

    private function employeeGuide(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $responsibilities = collect($user->staffResponsibilities())
            ->map(fn (string $code): array => [
                'label' => $this->responsibilityLabel($code),
                'direction' => $this->responsibilityDirection($code),
                'profit_outcome' => $this->responsibilityProfitOutcome($code),
            ])
            ->values()
            ->all();

        return [
            'name' => $user->name,
            'reports_to' => $user->supervisor?->name ?? 'Business owner',
            'is_supervisor' => $user->is_staff_supervisor,
            'direct_reports' => $user->directReports()->orderBy('name')->pluck('name')->all(),
            'responsibilities' => $responsibilities,
            'daily_routine' => [
                'Open the highest-priority mission and start it before taking lower-impact work.',
                'Update the real order, product, stock, production, expense, or collection record—not only the mission status.',
                'Mark blockers immediately and escalate them to '.($user->supervisor?->name ?? 'the business owner').' instead of leaving work silent.',
                'Finish by checking overdue work and submitted items waiting for review.',
            ],
        ];
    }

    private function teamSummary(): array
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->is_staff_supervisor) {
            return [];
        }

        $reportIds = $user->directReports()->pluck('id');

        if ($reportIds->isEmpty()) {
            return [];
        }

        $missions = Mission::query()
            ->whereIn('business_id', $user->accessibleBusinessIds())
            ->whereIn('assigned_user_id', $reportIds)
            ->active()
            ->get();

        return [
            'people' => $reportIds->count(),
            'open' => $missions->count(),
            'overdue' => $missions->filter(fn (Mission $mission): bool => $mission->due_at?->isPast() ?? false)->count(),
            'blocked' => $missions->whereIn('status', [Mission::STATUS_BLOCKED, Mission::STATUS_ESCALATED])->count(),
            'waiting_review' => $missions->where('status', Mission::STATUS_WAITING_REVIEW)->count(),
        ];
    }

    private function responsibilityDirection(string $responsibility): string
    {
        return match ($responsibility) {
            'order_confirmation' => 'Verify customer intent, phone, address, product, size, and value quickly; resolve no-answer orders through structured follow-up.',
            'return_recovery' => 'Contact returned and failed-delivery customers, identify the real cause, and recover suitable orders through correction or resend.',
            'dispatch' => 'Move confirmed orders to courier without avoidable delay and ensure tracking and delivery status are complete.',
            'product_repair' => 'Link missing Stock App product descriptions to the correct HELOAS SKU so product cost and profit become trustworthy.',
            'material_stock' => 'Keep material receipts, usage, waste, and stock balances accurate before shortages interrupt production.',
            'supervisor_review' => 'Review overdue, blocked, and submitted work; coach the responsible employee and escalate only unresolved business risks.',
            'production' => 'Record output, piece-work, waste, and delays accurately so production cost and capacity are visible.',
            'expense_recording' => 'Record genuine expenses and supplier dues promptly with the correct business and evidence.',
            'collections' => 'Follow up collectible money and update the actual receipt or billing record when cash is received.',
            'bank_exceptions' => 'Resolve unclear bank rows without guessing classifications or approving owner-only decisions.',
            default => 'Complete assigned missions using the underlying business record and leave a clear audit trail.',
        };
    }

    private function responsibilityProfitOutcome(string $responsibility): string
    {
        return match ($responsibility) {
            'order_confirmation' => 'Increase valid confirmed sales and reduce fake, duplicate, or unreachable orders.',
            'return_recovery' => 'Reduce return leakage and recover revenue that would otherwise be lost.',
            'dispatch' => 'Shorten order-to-courier time and prevent confirmed revenue from getting stuck.',
            'product_repair' => 'Make SKU-level margin reliable and expose loss-making products.',
            'material_stock' => 'Avoid emergency buying, excess stock, shortages, and unrecorded waste.',
            'supervisor_review' => 'Prevent overdue work and repeated employee blockers from becoming revenue or cost leakage.',
            'production' => 'Increase usable output while controlling piece-pay, delays, and waste.',
            'expense_recording' => 'Prevent hidden costs, duplicate payments, and overdue supplier risk.',
            'collections' => 'Convert recorded revenue into usable cash faster.',
            'bank_exceptions' => 'Protect cash accuracy and prevent incorrect financial decisions.',
            default => 'Protect revenue, reduce avoidable cost, and improve business truth.',
        };
    }

    private function missionTask(Mission $mission): array
    {
        $metadata = is_array($mission->metadata) ? $mission->metadata : [];

        return [
            'id' => $mission->id,
            'queue' => $mission->status === Mission::STATUS_WAITING_REVIEW ? 'review' : 'mission',
            'state' => $mission->status,
            'priority' => $mission->priority,
            'title' => $mission->title,
            'why_it_matters' => $mission->summary,
            'recommended_action' => $metadata['recommended_action'] ?? 'Open the related record and finish the next real step.',
            'related_record' => $metadata['related_record'] ?? null,
            'assigned_team' => $metadata['assigned_team'] ?? 'Assigned team',
            'assigned_user' => $metadata['assigned_user_label'] ?? optional($mission->assignedUser)->name ?? 'Assigned staff',
            'created_at' => optional($mission->created_at)->toDateString(),
            'due_on' => optional($mission->due_at)->toDateString(),
            'completed_at' => optional($mission->completed_at)->toDateString(),
            'status_label' => $this->missionStatusLabel($mission),
            'work_type' => $mission->mission_type,
            'responsibility_code' => $mission->responsibility_code,
            'impact_type' => $mission->impact_type,
            'estimated_impact' => $mission->estimated_impact,
            'confidence' => $mission->confidence,
        ];
    }

    private function taskResponsibility(array $task): ?string
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

    private function responsibilityLabel(string $responsibility): string
    {
        return User::staffResponsibilityOptions()[$responsibility] ?? 'General work';
    }

    private function missionStatusLabel(Mission $mission): string
    {
        if ($mission->status === Mission::STATUS_COMPLETED) {
            return 'Completed';
        }

        if ($mission->status === Mission::STATUS_BLOCKED) {
            return 'Blocked';
        }

        if ($mission->status === Mission::STATUS_ESCALATED) {
            return 'Escalated';
        }

        if ($mission->status === Mission::STATUS_WAITING_REVIEW) {
            return 'Waiting review';
        }

        if ($mission->due_at?->isPast()) {
            return 'Overdue';
        }

        if ($mission->due_at?->isToday()) {
            return 'Due today';
        }

        return 'Open';
    }

    private function myResponsibilityLabels(): array
    {
        $user = Auth::user();

        if (! $user?->isStaff()) {
            return [];
        }

        return collect($user->staffResponsibilities())
            ->map(fn (string $responsibility): string => $this->responsibilityLabel($responsibility))
            ->values()
            ->all();
    }

    private function taskSortKey(array $task): string
    {
        $priorityWeight = match ($task['priority'] ?? 'medium') {
            'critical' => '0',
            'high' => '1',
            'medium', 'normal' => '2',
            default => '3',
        };

        $impact = filled($task['estimated_impact'] ?? null) ? str_pad((string) (999999999 - (int) $task['estimated_impact']), 12, '0', STR_PAD_LEFT) : '999999999999';

        return $priorityWeight.'|'.$impact.'|'.($task['due_on'] ?? '9999-12-31').'|'.($task['created_at'] ?? '9999-12-31').'|'.($task['id'] ?? '');
    }

    private function employeeCompletionRate(int $openCount, int $completedToday): string
    {
        $total = $openCount + $completedToday;

        if ($total <= 0) {
            return '100%';
        }

        return number_format(($completedToday / $total) * 100, 0).'%';
    }

    private function activeMission(): ?Mission
    {
        return $this->activeMissionId ? Mission::query()->find($this->activeMissionId) : null;
    }

    private function actionOptions(): array
    {
        $user = Auth::user();

        return [
            'classifications' => BankTransaction::classificationOptions(),
            'transactionTypes' => BankTransaction::transactionTypeOptions(),
            'businesses' => Business::query()
                ->whereIn('id', $user?->accessibleBusinessIds() ?? [])
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'skus' => Sku::query()
                ->when($user instanceof User && ! $user->isInternalAdmin(), fn ($query) => $query->whereIn('business_id', $user->accessibleBusinessIds()))
                ->orderBy('code')
                ->limit(500)
                ->get()
                ->mapWithKeys(fn ($sku): array => [$sku->id => $sku->code.' - '.$sku->name])
                ->all(),
        ];
    }
}

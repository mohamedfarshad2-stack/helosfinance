<?php

namespace App\Filament\Pages;

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
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

    public array $managerProfit = [];

    public ?int $activeMissionId = null;

    public array $missionActionData = [];

    public ?string $missionActionError = null;

    private ?string $stockAppUrlCache = null;

    public function mount(MissionGeneratorService $missions, BusinessHealthSnapshotService $snapshots): void
    {
        $businessId = Auth::user()?->business_id;
        $this->business = $businessId ? Business::query()->find($businessId) : null;
        $this->workQueue = $this->employeeWorkQueue($missions->visibleForUser(Auth::user()));
        $this->managerProfit = $this->managerProfitGuide($snapshots);
    }

    protected function getViewData(): array
    {
        return [
            'business' => $this->business,
            'workQueue' => $this->workQueue,
            'managerProfit' => $this->managerProfit,
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
            'due_today' => $tasks->filter(fn (array $task): bool => filled($task['due_on']) && Carbon::parse($task['due_on'])->lessThanOrEqualTo(today()) && $task['state'] !== 'completed')->take(10)->values()->all(),
            'high_priority' => $tasks->filter(fn (array $task): bool => in_array($task['priority'], ['critical', 'high'], true) && $task['state'] !== 'completed')->take(10)->values()->all(),
            'waiting_review' => $tasks->filter(fn (array $task): bool => in_array($task['state'], ['waiting_review', 'escalated', 'blocked'], true))->take(10)->values()->all(),
            'completed_today' => $tasks->filter(fn (array $task): bool => $task['state'] === 'completed' && filled($task['completed_at']) && Carbon::parse($task['completed_at'])->isToday())->take(10)->values()->all(),
            'problems' => $tasks->filter(fn (array $task): bool => in_array($task['state'], ['blocked', 'escalated'], true))->take(10)->values()->all(),
            'missing_information' => $tasks->filter(fn (array $task): bool => in_array($task['responsibility_code'] ?? '', ['bank_exceptions', 'product_repair', 'material_stock'], true) && $task['state'] !== 'completed')->take(10)->values()->all(),
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
            'ranked_missions' => $openTasks->sortBy(fn (array $task): string => $this->taskSortKey($task))->take(25)->values()->all(),
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

        $responsibilities = collect($user->staffResponsibilities($user->business_id))
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

    private function managerProfitGuide(BusinessHealthSnapshotService $snapshots): array
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->is_staff_supervisor || ! $this->business) {
            return [];
        }

        $savedSnapshot = $snapshots->readCurrentMonth($this->business);
        $summary = $savedSnapshot ? [
            'revenue_total' => (float) $savedSnapshot->revenue_total,
            'cost_total' => (float) $savedSnapshot->cost_total,
            'leakage_total' => (float) $savedSnapshot->leakage_total,
            'estimated_profit' => (float) $savedSnapshot->estimated_profit,
            'metrics' => $savedSnapshot->metrics ?? [],
        ] : $snapshots->currentMonthSummary($this->business);
        $metrics = is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];
        $profit = (float) ($summary['estimated_profit'] ?? 0);
        $leakage = (float) ($summary['leakage_total'] ?? 0);
        $returnImpact = (float) ($metrics['return_impact'] ?? 0);
        $unrecognizedRevenue = (float) ($metrics['unrecognized_order_revenue'] ?? 0);
        $operatingCost = (float) ($metrics['direct_operational_costs'] ?? 0);
        $expensePressure = (float) ($metrics['manual_overhead_costs'] ?? 0);
        $salaryPressure = (float) ($metrics['salary_pressure'] ?? 0);

        $employees = $user->directReports()
            ->orderBy('name')
            ->get()
            ->map(function (User $employee) use ($leakage, $returnImpact, $unrecognizedRevenue, $operatingCost, $expensePressure, $salaryPressure): array {
                $responsibilities = $employee->staffResponsibilities($this->business?->id);
                $signals = [];

                if (in_array('dispatch', $responsibilities, true)) {
                    $signals[] = ['label' => 'Order value still awaiting trusted delivery recognition', 'amount' => $unrecognizedRevenue, 'kind' => 'revenue_at_risk'];
                }

                if (in_array('return_recovery', $responsibilities, true)) {
                    $signals[] = ['label' => 'Shared monthly return pressure', 'amount' => max($returnImpact, $leakage), 'kind' => 'shared_recovery_pool'];
                }

                if (in_array('product_repair', $responsibilities, true)) {
                    $signals[] = ['label' => 'Product profit blocked until SKU links are repaired', 'amount' => null, 'kind' => 'truth_blocker'];
                }

                if (in_array('production', $responsibilities, true) || in_array('material_stock', $responsibilities, true)) {
                    $signals[] = ['label' => 'Production, direct cost, and salary base to control', 'amount' => $operatingCost + $salaryPressure, 'kind' => 'cost_control'];
                }

                if (in_array('expense_recording', $responsibilities, true)) {
                    $signals[] = ['label' => 'Recorded overhead requiring cost control', 'amount' => $expensePressure, 'kind' => 'cost_control'];
                }

                if (in_array('order_confirmation', $responsibilities, true)) {
                    $signals[] = ['label' => 'Reduce fake, incorrect, and unreachable orders before courier cost', 'amount' => null, 'kind' => 'prevention'];
                }

                if (in_array('supervisor_review', $responsibilities, true)) {
                    $signals[] = ['label' => 'Own overdue and blocked work across direct reports', 'amount' => null, 'kind' => 'management'];
                }

                return [
                    'name' => $employee->name,
                    'responsibilities' => collect($responsibilities)->map(fn (string $code): string => $this->responsibilityLabel($code))->values()->all(),
                    'signals' => $signals,
                ];
            })
            ->values()
            ->all();

        return [
            'period' => now()->format('F Y'),
            'as_of' => $savedSnapshot?->period_end?->format('M j, Y') ?? now()->format('M j, Y'),
            'revenue' => (float) ($summary['revenue_total'] ?? 0),
            'cost' => (float) ($summary['cost_total'] ?? 0),
            'profit' => $profit,
            'loss_gap' => max(-$profit, 0),
            'leakage' => $leakage,
            'return_impact' => $returnImpact,
            'headline' => $profit < 0
                ? 'The company is currently below cost by LKR '.number_format(abs($profit), 2).' this month.'
                : 'The company is not showing an overall monthly loss, but LKR '.number_format($leakage, 2).' of recorded leakage still needs recovery or prevention.',
            'employees' => $employees,
            'warning' => 'These are company-level pools influenced by employees, not commission values. Shared amounts must not be added together. Profit remains estimated until missing SKU links, product costs, salaries, expenses, and bank truth are complete.',
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
            'members' => $missions
                ->groupBy('assigned_user_id')
                ->map(function (Collection $memberMissions, int|string $userId): array {
                    $employee = User::query()->find($userId);

                    return [
                        'name' => $employee?->name ?? 'Unassigned employee',
                        'open' => $memberMissions->count(),
                        'overdue' => $memberMissions->filter(fn (Mission $mission): bool => $mission->due_at?->isPast() ?? false)->count(),
                        'blocked' => $memberMissions->whereIn('status', [Mission::STATUS_BLOCKED, Mission::STATUS_ESCALATED])->count(),
                    ];
                })
                ->sortByDesc('overdue')
                ->values()
                ->all(),
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
            'assigned_user' => optional($mission->assignedUser)->name ?? $metadata['assigned_user_label'] ?? 'Assigned staff',
            'created_at' => optional($mission->created_at)->toDateString(),
            'due_on' => optional($mission->due_at)->toDateString(),
            'completed_at' => optional($mission->completed_at)->toDateString(),
            'status_label' => $this->missionStatusLabel($mission),
            'work_type' => $mission->mission_type,
            'responsibility_code' => $mission->responsibility_code,
            'impact_type' => $mission->impact_type,
            'estimated_impact' => $mission->estimated_impact,
            'confidence' => $mission->confidence,
            'workplace' => $this->missionWorkplace($mission),
            'workplace_label' => $this->missionWorkplace($mission) === 'stock_app' ? 'Stock App' : 'HELOAS',
            'workplace_instruction' => $this->missionWorkplace($mission) === 'stock_app'
                ? 'HELOAS is guiding this work. Complete the operational change in Stock App, then return after the next sync.'
                : 'Complete this source record inside HELOAS because it controls finance, cost, stock truth, production, or management review.',
            'workplace_url' => $this->missionWorkplace($mission) === 'stock_app'
                ? $this->stockAppUrl()
                : ($metadata['related_record']['url'] ?? null),
        ];
    }

    private function missionWorkplace(Mission $mission): string
    {
        return in_array($mission->responsibility_code, ['order_confirmation', 'return_recovery', 'dispatch'], true)
            ? 'stock_app'
            : 'helos';
    }

    private function stockAppUrl(): string
    {
        if ($this->stockAppUrlCache !== null) {
            return $this->stockAppUrlCache;
        }

        $baseUrl = IntegrationSource::query()
            ->where('business_id', $this->business?->id)
            ->where('type', 'stock_app')
            ->where('status', 'active')
            ->value('base_url');

        return $this->stockAppUrlCache = rtrim((string) ($baseUrl ?: 'https://codreturnslanka.lk'), '/').'/admin';
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

        return collect($user->staffResponsibilities($user->business_id))
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

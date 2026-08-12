<?php

namespace App\Filament\Pages;

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\Mission;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Services\MissionGeneratorService;
use App\Domains\Shared\Services\MissionSourceActionService;
use App\Domains\Shared\Services\StockAppPendingParityService;
use App\Filament\Resources\MissionResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    public array $employeeContribution = [];

    public array $parcelMovement = [];

    public ?string $parcelStartDate = null;

    public ?string $parcelEndDate = null;

    public ?int $activeMissionId = null;

    public array $missionActionData = [];

    public ?string $missionActionError = null;

    private ?string $stockAppUrlCache = null;

    public function mount(MissionGeneratorService $missions, BusinessHealthSnapshotService $snapshots): void
    {
        $businessId = $this->resolvedBusinessId();

        $this->business = $businessId ? Business::query()->find($businessId) : null;
        $this->parcelStartDate = today()->toDateString();
        $this->parcelEndDate = today()->toDateString();

        $user = Auth::user();

        if ($user instanceof User && $user->isStaff()) {
            $visibleMissions = $missions->visibleForUser($user);

            $this->workQueue = $this->employeeWorkQueue($visibleMissions);
            $this->managerProfit = $this->managerProfitGuide($snapshots);
            $this->employeeContribution = $this->employeeContributionGuide($snapshots);
            $this->parcelMovement = $this->employeeParcelMovement();

            return;
        }

        $this->workQueue = $this->fallbackWorkQueue();
        $this->managerProfit = [];
        $this->employeeContribution = [];
        $this->parcelMovement = [];
    }

    protected function getViewData(): array
    {
        return [
            'business' => $this->business,
            'workQueue' => $this->workQueue ?: $this->fallbackWorkQueue(),
            'managerProfit' => $this->managerProfit,
            'employeeContribution' => $this->employeeContribution,
            'parcelMovement' => $this->parcelMovement,
            'activeMission' => $this->activeMission(),
            'actionOptions' => $this->actionOptions(),
        ];
    }

    public function updatedParcelStartDate(): void
    {
        $this->parcelMovement = $this->employeeParcelMovement();
    }

    public function updatedParcelEndDate(): void
    {
        $this->parcelMovement = $this->employeeParcelMovement();
    }

    public function refreshLivePending(): void
    {
        if (! $this->business) {
            return;
        }

        app(StockAppPendingParityService::class)->compare(
            $this->business,
            $this->stockAppQueueStart(),
            today()->endOfDay(),
            0,
            true,
        );

        $this->parcelMovement = $this->employeeParcelMovement();

        Notification::make()
            ->title('Live Stock App pending count refreshed')
            ->body('HELOAS refreshed the live pending count without changing Stock App.')
            ->success()
            ->send();
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

    private function fallbackWorkQueue(): array
    {
        $user = Auth::user();
        $name = $user instanceof User ? $user->name : 'Team member';
        $isSupervisor = $user instanceof User ? (bool) $user->is_staff_supervisor : false;

        return [
            'headline' => 'Today\'s work is ready.',
            'summary' => [],
            'sections' => [
                'due_today' => [],
                'high_priority' => [],
                'waiting_review' => [],
                'completed_today' => [],
                'problems' => [],
                'missing_information' => [],
            ],
            'todays_priority' => null,
            'ranked_missions' => [],
            'tasks' => [],
            'team_workload' => [],
            'responsibility_groups' => [],
            'my_responsibilities' => [],
            'open_count' => 0,
            'blocked_count' => 0,
            'completed_today_count' => 0,
            'employee_guide' => [
                'name' => $name,
                'reports_to' => 'Business owner',
                'is_supervisor' => $isSupervisor,
                'direct_reports' => [],
                'responsibilities' => [],
                'daily_routine' => [
                    'Open the highest-priority mission and start it before taking lower-impact work.',
                    'Update the real order, product, stock, production, expense, or collection record—not only the mission status.',
                    'Mark blockers immediately and escalate them to the business owner instead of leaving work silent.',
                    'Finish by checking overdue work and submitted items waiting for review.',
                ],
            ],
            'team_summary' => [],
        ];
    }

    private function employeeGuide(): array
    {
        $user = Auth::user();
        $businessId = $this->resolvedBusinessId();

        if (! $user instanceof User) {
            return [];
        }

        $responsibilities = collect($user->staffResponsibilities($businessId))
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

        if (! $savedSnapshot || ! $savedSnapshot->period_end?->isToday()) {
            $savedSnapshot = $snapshots->currentMonth($this->business);
        }
        $summary = $savedSnapshot ? [
            'revenue_total' => (float) $savedSnapshot->revenue_total,
            'cost_total' => (float) $savedSnapshot->cost_total,
            'leakage_total' => (float) $savedSnapshot->leakage_total,
            'estimated_profit' => (float) $savedSnapshot->estimated_profit,
            'metrics' => $savedSnapshot->metrics ?? [],
        ] : $snapshots->currentMonthSummary($this->business);
        $metrics = is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];
        $revenue = (float) ($summary['revenue_total'] ?? 0);
        $profit = (float) ($summary['estimated_profit'] ?? 0);
        $leakage = (float) ($summary['leakage_total'] ?? 0);
        $directCosts = (float) ($metrics['direct_operational_costs'] ?? 0);
        $delivered = (int) ($metrics['order_counts']['delivered'] ?? 0);
        $settings = is_array($this->business->settings ?? null) ? $this->business->settings : [];
        $goal = is_array($settings['goal'] ?? null) ? $settings['goal'] : [];
        $goalType = (string) ($goal['type'] ?? 'profit');
        $target = (float) ($goal['amount'] ?? 0);
        $configured = $target > 0;
        $current = match ($goalType) {
            'revenue' => $revenue,
            'deliveries' => (float) $delivered,
            'profit' => $profit,
            default => null,
        };
        $ownerGap = $configured && $current !== null ? max($target - $current, 0) : null;
        $recommendedGap = max(-$profit, $leakage, 0);
        $gap = $configured ? $ownerGap : $recommendedGap;
        $isRecommended = ! $configured;
        $operatingLoss = max(-$profit, 0);
        $recoveryPressure = max($operatingLoss, $leakage, (float) ($gap ?? 0));
        $contributionPool = max($revenue - $directCosts - $leakage, 0);
        $averageContribution = $delivered > 0 ? $contributionPool / $delivered : 0;
        $averageRevenue = $delivered > 0 ? $revenue / $delivered : 0;
        $requiredDeliveries = match (true) {
            $gap === null => null,
            $goalType === 'deliveries' => (int) ceil($gap),
            $goalType === 'revenue' && $averageRevenue > 0 => (int) ceil($gap / $averageRevenue),
            $goalType === 'profit' && $averageContribution > 0 => (int) ceil($gap / $averageContribution),
            $isRecommended && $averageContribution > 0 => (int) ceil($gap / $averageContribution),
            $isRecommended && $averageRevenue > 0 => (int) ceil($gap / $averageRevenue),
            default => null,
        };
        $daysRemaining = max((int) now()->startOfDay()->diffInDays(now()->endOfMonth()->startOfDay()) + 1, 1);
        $dailyDeliveries = $requiredDeliveries === null ? null : (int) ceil($requiredDeliveries / $daysRemaining);
        $businessId = (int) $this->business->id;
        $directReports = $this->directReportsForBusiness($user, $businessId);
        $directReportIds = $directReports->pluck('id');
        $teamOverdue = Mission::query()
            ->whereIn('assigned_user_id', $directReportIds)
            ->where('business_id', $businessId)
            ->active()
            ->where('due_at', '<', today())
            ->count();

        $employees = collect([$user])
            ->merge($directReports)
            ->map(function (User $employee) use ($leakage, $requiredDeliveries, $dailyDeliveries, $teamOverdue): array {
                $responsibilities = $employee->staffResponsibilities($this->business?->id);
                $signals = [];
                $primaryCommand = 'Clear assigned missions and report blockers before end of day.';
                $isManager = $employee->is(Auth::user());
                $openMissions = Mission::query()
                    ->where('assigned_user_id', $employee->id)
                    ->where('business_id', $this->business?->id)
                    ->active()
                    ->count();
                $overdueMissions = Mission::query()
                    ->where('assigned_user_id', $employee->id)
                    ->where('business_id', $this->business?->id)
                    ->active()
                    ->where('due_at', '<', today())
                    ->count();

                if (in_array('dispatch', $responsibilities, true) || in_array('delivery_follow_up', $responsibilities, true)) {
                    $signals[] = ['label' => 'Delivery contribution required', 'display' => $requiredDeliveries === null ? 'Waiting for enough delivery and cost data' : number_format($requiredDeliveries).' additional deliveries shared across operations'];
                    $primaryCommand = in_array('delivery_follow_up', $responsibilities, true)
                        ? 'Push not-delivered parcels to delivered; call, solve address/courier issues, and update Stock App.'
                        : 'Clear confirmed orders waiting for dispatch; add tracking and move parcels to courier.';
                }

                if (in_array('return_recovery', $responsibilities, true)) {
                    $signals[] = ['label' => 'Return leakage to recover or prevent', 'display' => 'LKR '.number_format($leakage, 2).' shared company pressure'];
                }

                if (in_array('product_repair', $responsibilities, true)) {
                    $signals[] = ['label' => 'Product-cost truth work', 'display' => number_format($openMissions).' open assigned missions; repair SKU links before margin decisions'];
                }

                if (in_array('production', $responsibilities, true) || in_array('material_stock', $responsibilities, true)) {
                    $signals[] = ['label' => 'Production and material control', 'display' => 'Keep output, waste, material use, and piece-pay records current'];
                    $primaryCommand = 'Enter today\'s production before day end; item code, part/work step, worker, quantity, waste, and pay.';
                }

                if (in_array('expense_recording', $responsibilities, true)) {
                    $signals[] = ['label' => 'Expense control', 'display' => 'Record and challenge avoidable expenses without exposing owner financial totals'];
                }

                if (in_array('order_confirmation', $responsibilities, true)) {
                    $signals[] = ['label' => 'Daily confirmation pace', 'display' => $dailyDeliveries === null ? 'Waiting for enough delivery and cost data' : 'Support at least '.number_format($dailyDeliveries).' additional deliveries per remaining day'];
                }

                if (in_array('supervisor_review', $responsibilities, true)) {
                    $signals[] = ['label' => 'Overdue work reduction', 'display' => $isManager ? number_format($teamOverdue).' overdue direct-report missions to triage' : number_format($overdueMissions).' overdue assigned missions to clear'];
                    if ($isManager) {
                        $primaryCommand = 'Reduce overdue team missions first; assign one clear outcome to each direct report.';
                    }
                }

                return [
                    'name' => $employee->name,
                    'is_manager' => $isManager,
                    'responsibilities' => collect($responsibilities)->map(fn (string $code): string => $this->responsibilityLabel($code))->values()->all(),
                    'primary_command' => $primaryCommand,
                    'open_missions' => $openMissions,
                    'overdue_missions' => $overdueMissions,
                    'signals' => $signals,
                ];
            })
            ->values()
            ->all();

        $deliveryCommand = $dailyDeliveries === null
            ? 'First make delivery and cost data trusted, then HELOS can calculate the exact delivery pace.'
            : 'Target at least '.number_format($dailyDeliveries).' extra delivered parcels per day for the remaining '.$daysRemaining.' day(s).';
        $cfoActions = [
            [
                'label' => '1. Stop silent backlog',
                'body' => number_format($teamOverdue).' overdue team missions must be triaged today. Anything impossible should be blocked or escalated, not left open.',
                'tone' => 'rose',
            ],
            [
                'label' => '2. Convert parcel value',
                'body' => $deliveryCommand,
                'tone' => 'emerald',
            ],
            [
                'label' => '3. Make cost truth trusted',
                'body' => 'Production output, waste, material use, piece-pay, expenses, and bank review must be entered daily so owner profit is not guessed.',
                'tone' => 'violet',
            ],
        ];

        return [
            'period' => now()->format('F Y'),
            'as_of' => $savedSnapshot?->period_end?->format('M j, Y') ?? now()->format('M j, Y'),
            'configured' => $configured,
            'is_recommended' => $isRecommended,
            'operating_loss' => $operatingLoss,
            'recovery_pressure' => $recoveryPressure,
            'recovery_status' => $recoveryPressure > 0 || $teamOverdue > 0 ? 'Recovery mode' : 'Controlled',
            'goal_label' => match ($goalType) {
                'revenue' => 'Monthly sales target gap',
                'deliveries' => 'Monthly delivery target gap',
                'collections' => 'Monthly collection target gap',
                default => $isRecommended ? 'Minimum operational recovery gap' : 'Monthly company target gap',
            },
            'gap' => $gap,
            'gap_is_count' => $goalType === 'deliveries',
            'required_deliveries' => $requiredDeliveries,
            'daily_deliveries' => $dailyDeliveries,
            'days_remaining' => $daysRemaining,
            'leakage' => $leakage,
            'team_overdue' => $teamOverdue,
            'headline' => ! $configured
                ? 'Company is in recovery mode. HELOS is showing the manager the minimum operational gap it can act on today.'
                : ($gap !== null && $gap <= 0
                    ? 'The company target is covered. Protect it by reducing leakage and overdue work.'
                    : 'Close the remaining target gap through the operational numbers below.'),
            'employees' => $employees,
            'cfo_actions' => $cfoActions,
            'warning' => $configured
                ? 'Managers see only the remaining operational gap and assigned recovery actions. HELOS uses the owner-approved target to calculate this manager plan. Shared targets must not be added together.'
                : 'Managers see only the remaining operational gap and assigned recovery actions. No owner target is set, so this is a minimum operational recovery plan. If the owner expects about LKR 500,000 recovery, set that as the monthly target so the manager plan becomes stronger.',
        ];
    }

    private function employeeContributionGuide(BusinessHealthSnapshotService $snapshots): array
    {
        $user = Auth::user();
        $businessId = $this->resolvedBusinessId();

        if (! $user instanceof User || ! $user->isStaff() || $user->is_staff_supervisor || ! $businessId) {
            return [];
        }

        $business = $this->business ?: Business::query()->find($businessId);

        if (! $business instanceof Business) {
            return [];
        }

        $snapshot = $snapshots->readCurrentMonth($business);

        if (! $snapshot || ! $snapshot->period_end?->isToday()) {
            $snapshot = $snapshots->currentMonth($business);
        }

        $metrics = is_array($snapshot?->metrics) ? $snapshot->metrics : [];
        $revenue = (float) ($snapshot?->revenue_total ?? 0);
        $directCosts = (float) ($metrics['direct_operational_costs'] ?? 0);
        $leakage = (float) ($snapshot?->leakage_total ?? 0);
        $profit = (float) ($snapshot?->estimated_profit ?? 0);
        $delivered = (int) ($metrics['order_counts']['delivered'] ?? 0);
        $contributionPerDelivery = $delivered > 0
            ? max(($revenue - $directCosts - $leakage) / $delivered, 0)
            : 0;
        $recoveryGap = max(-$profit, $leakage, 0);
        $daysRemaining = max((int) now()->startOfDay()->diffInDays(now()->endOfMonth()->startOfDay()) + 1, 1);
        $companyDailyDeliveries = $contributionPerDelivery > 0
            ? (int) ceil(ceil($recoveryGap / $contributionPerDelivery) / $daysRemaining)
            : null;

        $responsibilities = $user->staffResponsibilities($business->id);
        $deliveryRoles = ['order_confirmation', 'dispatch', 'delivery_follow_up', 'return_recovery'];
        $supportsDeliveries = array_intersect($responsibilities, $deliveryRoles) !== [];
        $deliveryStaffCount = User::query()
            ->where('business_id', $business->id)
            ->where('is_employee', true)
            ->get()
            ->filter(fn (User $employee): bool => array_intersect($employee->staffResponsibilities($business->id), $deliveryRoles) !== [])
            ->count();
        $personalDeliveryTarget = $supportsDeliveries && $companyDailyDeliveries !== null
            ? (int) ceil($companyDailyDeliveries / max($deliveryStaffCount, 1))
            : null;

        $completedToday = Mission::query()
            ->where('business_id', $business->id)
            ->where('assigned_user_id', $user->id)
            ->where('status', Mission::STATUS_COMPLETED)
            ->whereDate('completed_at', today())
            ->count();
        $openToday = Mission::query()
            ->where('business_id', $business->id)
            ->where('assigned_user_id', $user->id)
            ->active()
            ->where(fn ($query) => $query->whereNull('due_at')->orWhereDate('due_at', '<=', today()))
            ->count();
        $taskTarget = max($completedToday + $openToday, 1);
        $progress = min((int) round(($completedToday / $taskTarget) * 100), 100);

        $goals = collect($responsibilities)
            ->map(function (string $responsibility) use ($personalDeliveryTarget, $openToday): array {
                return match ($responsibility) {
                    'order_confirmation' => [
                        'label' => 'Move confirmed orders forward',
                        'target' => $personalDeliveryTarget ? 'Support '.$personalDeliveryTarget.' deliveries today' : 'Clear today\'s confirmation missions',
                        'action' => 'Confirm genuine orders and follow up no-answer customers in Stock App.',
                        'tone' => 'blue',
                    ],
                    'dispatch' => [
                        'label' => 'Move confirmed orders to courier',
                        'target' => 'Clear today\'s dispatch missions',
                        'action' => 'Add tracking and move confirmed parcels to the courier without delay.',
                        'tone' => 'emerald',
                    ],
                    'delivery_follow_up' => [
                        'label' => 'Push not-delivered parcels to delivered',
                        'target' => $personalDeliveryTarget ? $personalDeliveryTarget.' deliveries to support' : 'Clear today\'s delivery follow-up missions',
                        'action' => 'Check dispatched parcels, call customers, solve courier/address issues, and update delivery status in Stock App.',
                        'tone' => 'emerald',
                    ],
                    'return_recovery' => [
                        'label' => 'Recover delayed and returned orders',
                        'target' => max($openToday, 1).' assigned actions to review',
                        'action' => 'Call, correct, resend, or close each return case in Stock App.',
                        'tone' => 'amber',
                    ],
                    'production' => [
                        'label' => 'Keep production output current',
                        'target' => 'Record today\'s completed output',
                        'action' => 'Enter good quantity, waste, material use, and employee payout in HELOAS.',
                        'tone' => 'violet',
                    ],
                    'material_stock' => [
                        'label' => 'Keep materials ready',
                        'target' => 'Clear material and stock blockers',
                        'action' => 'Update receipts, usage, shortages, and missing SKU links in HELOAS.',
                        'tone' => 'cyan',
                    ],
                    'product_repair' => [
                        'label' => 'Make product information usable',
                        'target' => max($openToday, 1).' assigned fixes to review',
                        'action' => 'Repair missing product and SKU links so the next decision uses trusted data.',
                        'tone' => 'rose',
                    ],
                    'expense_recording', 'collections', 'bank_exceptions' => [
                        'label' => $this->responsibilityLabel($responsibility),
                        'target' => max($openToday, 1).' assigned actions to review',
                        'action' => $this->responsibilityDirection($responsibility),
                        'tone' => 'indigo',
                    ],
                    default => [],
                };
            })
            ->filter()
            ->take(3)
            ->values()
            ->all();

        return [
            'completed' => $completedToday,
            'remaining' => max($taskTarget - $completedToday, 0),
            'target' => $taskTarget,
            'progress' => $progress,
            'status' => $progress >= 100 ? 'Target reached' : ($progress >= 50 ? 'Good progress' : 'Start with your first priority'),
            'goals' => $goals,
            'calculation_ready' => $contributionPerDelivery > 0,
        ];
    }

    private function employeeParcelMovement(): array
    {
        $user = Auth::user();
        $businessId = $this->resolvedBusinessId();

        if (! $user instanceof User || ! $user->isStaff() || ! $businessId) {
            return [];
        }

        $business = $this->business ?: Business::query()->find($businessId);

        if (! $business instanceof Business) {
            return [];
        }

        $responsibilities = $user->staffResponsibilities($business->id);
        $canMoveParcels = array_intersect($responsibilities, ['order_confirmation', 'dispatch', 'delivery_follow_up']) !== [];

        if (! $canMoveParcels) {
            return [];
        }

        $start = $this->safeDate($this->parcelStartDate, today())->startOfDay();
        $end = $this->safeDate($this->parcelEndDate, today())->endOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $dispatchEvents = OperationalEvent::query()
            ->where('business_id', $business->id)
            ->whereIn('event_type', [OperationalEvent::TRACKING_NUMBER_ADDED, OperationalEvent::WHOLESALE_PARCEL_SENT])
            ->whereBetween('occurred_at', [$start, $end])
            ->get();
        $latestEvents = $this->latestStockAppOrderEvents();
        $pendingConfirmation = $latestEvents
            ->filter(fn (OperationalEvent $event): bool => $this->parcelMovementLane($event) === 'pending_confirmation')
            ->values();
        $confirmedWaitingDispatch = $latestEvents
            ->filter(fn (OperationalEvent $event): bool => $this->parcelMovementLane($event) === 'confirmed_waiting_dispatch')
            ->values();
        $dispatchedWaitingDelivery = $latestEvents
            ->filter(fn (OperationalEvent $event): bool => $this->parcelMovementLane($event) === 'dispatched_waiting_delivery')
            ->values();
        $deliveredSoFar = $this->deliveredStockAppSummary();

        $pendingConfirmationValue = (float) $pendingConfirmation->sum(fn (OperationalEvent $event): float => $this->stockAppOrderValue($event));
        $confirmedWaitingDispatchValue = (float) $confirmedWaitingDispatch->sum(fn (OperationalEvent $event): float => $this->stockAppOrderValue($event));
        $dispatchedWaitingDeliveryValue = (float) $dispatchedWaitingDelivery->sum(fn (OperationalEvent $event): float => $this->stockAppOrderValue($event));
        $dispatchedValue = (float) $dispatchEvents->sum(fn (OperationalEvent $event): float => $this->stockAppOrderValue($event));
        $liveQueueStart = $this->stockAppQueueStart();
        $pendingSyncCoverage = $this->pendingSyncCoverage($liveQueueStart);
        $pendingParity = app(StockAppPendingParityService::class)->compare($business, $liveQueueStart, today()->endOfDay(), $pendingConfirmation->count(), false);

        return [
            'period_label' => $start->isSameDay($end)
                ? $start->format('M j, Y')
                : $start->format('M j').' - '.$end->format('M j, Y'),
            'business_name' => $business->name,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'pending_confirmation_label' => 'Current Stock App queue',
            'pending_confirmation_sync_note' => $pendingSyncCoverage['note'],
            'pending_confirmation_sync_warning' => $pendingSyncCoverage['warning'],
            'pending_confirmation_live_count' => $pendingParity['live_count'],
            'pending_confirmation_live_note' => $pendingParity['note'],
            'pending_confirmation_live_warning' => $pendingParity['warning'],
            'current_queue_label' => 'Current open work',
            'follow_up_label' => 'Current open work',
            'delivered_label' => 'So far',
            'dispatched_count' => $dispatchEvents
                ->groupBy(fn (OperationalEvent $event): string => $this->stockAppOrderKey($event))
                ->count(),
            'dispatched_value' => $dispatchedValue,
            'pending_confirmation_count' => $pendingConfirmation->count(),
            'pending_confirmation_value' => $pendingConfirmationValue,
            'pending_confirmation_items' => $this->parcelMovementItems($pendingConfirmation, 1000),
            'confirmed_waiting_dispatch_count' => $confirmedWaitingDispatch->count(),
            'confirmed_waiting_dispatch_value' => $confirmedWaitingDispatchValue,
            'confirmed_waiting_dispatch_items' => $this->parcelMovementItems($confirmedWaitingDispatch, 1000),
            'confirmed_waiting_dispatch_breakdown' => $this->parcelMovementBreakdown($confirmedWaitingDispatch),
            'dispatched_waiting_delivery_count' => $dispatchedWaitingDelivery->count(),
            'dispatched_waiting_delivery_value' => $dispatchedWaitingDeliveryValue,
            'dispatched_waiting_delivery_items' => $this->parcelMovementItems($dispatchedWaitingDelivery, 1000),
            'delivered_so_far_count' => $deliveredSoFar['count'],
            'delivered_so_far_value' => $deliveredSoFar['value'],
            'delivered_so_far_items' => $deliveredSoFar['items'],
            'can_dispatch' => in_array('dispatch', $responsibilities, true),
            'can_follow_delivery' => in_array('delivery_follow_up', $responsibilities, true),
        ];
    }

    private function pendingSyncCoverage(Carbon $start): array
    {
        if (! $this->business) {
            return ['warning' => false, 'note' => null];
        }

        $integration = IntegrationSource::query()
            ->where('business_id', $this->business->id)
            ->where('type', 'stock_app')
            ->orderByDesc('last_successful_sync_at')
            ->orderByDesc('last_webhook_received_at')
            ->first();

        if (! $integration instanceof IntegrationSource) {
            return ['warning' => true, 'note' => 'HELOAS has no active Stock App sync record for this business yet.'];
        }

        $coverageStart = collect([
            $integration->last_successful_sync_at,
            $integration->last_webhook_received_at,
            $integration->created_at,
        ])
            ->filter(fn ($value) => $value instanceof Carbon)
            ->min();

        if (! $coverageStart instanceof Carbon) {
            return ['warning' => true, 'note' => 'HELOAS cannot prove when Stock App pending-order coverage started.'];
        }

        if ($start->lt($coverageStart->copy()->startOfDay())) {
            return [
                'warning' => true,
                'note' => 'Pending queue may be incomplete before '.$coverageStart->format('M j, Y g:i A').'. Older pending orders can stay in Stock App without existing in HELOAS until they are synced.',
            ];
        }

        return ['warning' => false, 'note' => 'Pending queue is being read from HELOAS sync records for the current Stock App queue.'];
    }

    private function parcelMovementItems(Collection $events, int $limit = 8): array
    {
        return $events
            ->sortByDesc(fn (OperationalEvent $event): int => $event->occurred_at?->timestamp ?? 0)
            ->take($limit)
            ->map(fn (OperationalEvent $event): array => [
                'reference' => $this->stockAppOrderReference($event),
                'status' => str_replace('_', ' ', $this->stockAppStatus($event)),
                'value' => $this->stockAppOrderValue($event),
                'date' => $this->stockAppPipelineDate($event)?->format('M j, H:i') ?? 'No date',
            ])
            ->values()
            ->all();
    }

    private function parcelMovementBreakdown(Collection $events): array
    {
        $now = now();

        $sourceRows = $events
            ->groupBy(fn (OperationalEvent $event): string => (string) $event->source)
            ->map(fn (Collection $group, string $source): array => [
                'label' => str_replace('_', ' ', $source ?: 'unknown source'),
                'count' => $group->count(),
                'value' => (float) $group->sum(fn (OperationalEvent $event): float => $this->stockAppOrderValue($event)),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $eventTypeRows = $events
            ->groupBy(fn (OperationalEvent $event): string => (string) $event->event_type)
            ->map(fn (Collection $group, string $eventType): array => [
                'label' => str_replace('_', ' ', $eventType ?: 'unknown event'),
                'count' => $group->count(),
                'value' => (float) $group->sum(fn (OperationalEvent $event): float => $this->stockAppOrderValue($event)),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $statusRows = $events
            ->groupBy(fn (OperationalEvent $event): string => $this->stockAppStatus($event))
            ->map(fn (Collection $group, string $status): array => [
                'label' => str_replace('_', ' ', $status ?: 'unknown status'),
                'count' => $group->count(),
                'value' => (float) $group->sum(fn (OperationalEvent $event): float => $this->stockAppOrderValue($event)),
            ])
            ->sortByDesc('count')
            ->take(8)
            ->values()
            ->all();

        $ageRows = collect([
            'today' => ['label' => 'Today', 'count' => 0, 'value' => 0.0],
            '1_2_days' => ['label' => '1-2 days old', 'count' => 0, 'value' => 0.0],
            '3_7_days' => ['label' => '3-7 days old', 'count' => 0, 'value' => 0.0],
            '8_plus_days' => ['label' => '8+ days old', 'count' => 0, 'value' => 0.0],
            'no_date' => ['label' => 'No usable date', 'count' => 0, 'value' => 0.0],
        ]);

        foreach ($events as $event) {
            $date = $this->stockAppPipelineDate($event);
            $value = $this->stockAppOrderValue($event);

            $bucket = match (true) {
                ! $date instanceof Carbon => 'no_date',
                $date->isToday() => 'today',
                $date->diffInDays($now) <= 2 => '1_2_days',
                $date->diffInDays($now) <= 7 => '3_7_days',
                default => '8_plus_days',
            };

            $row = $ageRows->get($bucket);
            $row['count']++;
            $row['value'] += $value;
            $ageRows->put($bucket, $row);
        }

        return [
            'sources' => $sourceRows,
            'event_types' => $eventTypeRows,
            'statuses' => $statusRows,
            'ages' => $ageRows
                ->filter(fn (array $row): bool => (int) $row['count'] > 0)
                ->values()
                ->all(),
        ];
    }

    private function stockAppPipelineDate(OperationalEvent $event): ?Carbon
    {
        $lane = $this->parcelMovementLane($event);
        $keys = match ($lane) {
            'pending_confirmation' => [
                'order_date',
                'created_at',
                'created_on',
                'ordered_at',
                'order_created_at',
                'order.order_date',
                'order.created_at',
                'data.order_date',
                'data.created_at',
                'payload.order_date',
                'payload.created_at',
            ],
            'confirmed_waiting_dispatch' => [
                'confirmed_at',
                'order_confirmed_at',
                'client_confirmed_at',
                'order.confirmed_at',
                'data.confirmed_at',
                'payload.confirmed_at',
                'order_date',
            ],
            'delivered' => [
                'delivered_at',
                'delivery_done_at',
                'completed_at',
                'order.delivered_at',
                'data.delivered_at',
                'payload.delivered_at',
            ],
            default => [
                'dispatched_at',
                'tracking_added_at',
                'tracking_number_added_at',
                'courier_sent_at',
                'shipped_at',
                'order.dispatched_at',
                'data.dispatched_at',
                'payload.dispatched_at',
            ],
        };

        foreach ($keys as $key) {
            $date = $this->parseStockAppPayloadDate(data_get($event->payload, $key));

            if ($date instanceof Carbon) {
                return $date;
            }
        }

        return $event->occurred_at;
    }

    private function parseStockAppPayloadDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function latestStockAppOrderEvents(): Collection
    {
        $queueStart = $this->stockAppQueueStart();
        $closedKeys = OperationalEvent::query()
            ->where('business_id', $this->business?->id)
            ->whereIn('source', ['stock_app', 'stock_app_sync'])
            ->whereIn('event_type', [
                OperationalEvent::ORDER_DELIVERED,
                OperationalEvent::ORDER_RETURNED,
                OperationalEvent::FAKE_ORDER_DETECTED,
            ])
            ->where('occurred_at', '>=', $queueStart)
            ->get(['id', 'business_id', 'source', 'event_type', 'external_id', 'payload', 'occurred_at'])
            ->map(fn (OperationalEvent $event): string => $this->stockAppOrderKey($event))
            ->filter()
            ->unique()
            ->flip();

        return OperationalEvent::query()
            ->where('business_id', $this->business?->id)
            ->whereIn('source', ['stock_app', 'stock_app_sync'])
            ->where('occurred_at', '>=', $queueStart)
            ->whereIn('event_type', array_values(array_unique(array_merge(
                $this->pendingConfirmationEventTypes(),
                [
                    OperationalEvent::ORDER_CONFIRMED,
                    OperationalEvent::TRACKING_NUMBER_ADDED,
                    OperationalEvent::WHOLESALE_PARCEL_SENT,
                ],
            ))))
            ->get(['id', 'business_id', 'source', 'event_type', 'external_id', 'revenue_amount', 'payload', 'occurred_at'])
            ->groupBy(fn (OperationalEvent $event): string => $this->stockAppOrderKey($event))
            ->map(fn (Collection $events): OperationalEvent => $events
                ->sortBy(fn (OperationalEvent $event): string => sprintf(
                    '%012d-%012d',
                    $event->occurred_at?->timestamp ?? 0,
                    $event->id,
                ))
                ->last())
            ->reject(fn (OperationalEvent $event): bool => $closedKeys->has($this->stockAppOrderKey($event)))
            ->values();
    }

    private function deliveredStockAppSummary(): array
    {
        $query = OperationalEvent::query()
            ->where('business_id', $this->business?->id)
            ->whereIn('source', ['stock_app', 'stock_app_sync'])
            ->where('event_type', OperationalEvent::ORDER_DELIVERED)
            ->where('occurred_at', '>=', $this->stockAppQueueStart());

        $recentItems = (clone $query)
            ->latest('occurred_at')
            ->limit(8)
            ->get(['id', 'business_id', 'source', 'event_type', 'external_id', 'revenue_amount', 'payload', 'occurred_at']);

        return [
            'count' => (clone $query)->count(),
            'value' => (float) (clone $query)->sum('revenue_amount'),
            'items' => $this->parcelMovementItems($recentItems),
        ];
    }

    private function stockAppQueueStart(): Carbon
    {
        return today()->subMonths(3)->startOfDay();
    }

    private function stockAppOrderKey(OperationalEvent $event): string
    {
        foreach ([
            'cod_order_id',
            'order_id',
            'order_number',
            'reference',
            'id',
            'order.id',
            'order.cod_order_id',
            'order.order_id',
            'order.order_number',
            'data.cod_order_id',
            'data.id',
            'data.order_id',
            'data.order_number',
            'payload.cod_order_id',
            'payload.id',
            'payload.order_id',
            'payload.order_number',
        ] as $key) {
            $value = trim((string) data_get($event->payload, $key));

            if ($value !== '') {
                return $key.':'.$value;
            }
        }

        $externalId = trim((string) ($event->external_id ?? ''));

        if ($externalId !== '') {
            if (preg_match('/^(.*?)-(?:created|new|pending|confirmed|delivered|returned|resent|tracking_number_added|tracking_added|tracking|dispatch|dispatched|shipped|shipping|sent_to_courier)(?:-|$)/i', $externalId, $matches) === 1) {
                return 'external:'.$matches[1];
            }

            return 'external:'.$externalId;
        }

        return 'event:'.$event->id;
    }

    private function stockAppOrderReference(OperationalEvent $event): string
    {
        foreach ([
            'order_id',
            'order_number',
            'reference',
            'tracking_number',
            'cod_order_id',
            'id',
            'order.id',
            'order.cod_order_id',
            'order.order_id',
            'order.order_number',
            'data.id',
            'data.cod_order_id',
            'data.order_id',
            'data.order_number',
            'payload.id',
            'payload.cod_order_id',
            'payload.order_id',
            'payload.order_number',
        ] as $key) {
            $value = trim((string) data_get($event->payload, $key));

            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) $event->external_id) ?: 'Event '.$event->id;
    }

    /**
     * @return array<int, string>
     */
    private function pendingConfirmationEventTypes(): array
    {
        return [
            OperationalEvent::ORDER_CREATED,
            'pending',
            'Pending',
            'new',
            'New',
            'order_pending',
            'Order Pending',
            'order pending',
            'pending_confirmation',
            'Pending Confirmation',
        ];
    }

    private function parcelMovementLane(OperationalEvent $event): string
    {
        $status = $this->stockAppStatus($event);
        $eventType = strtolower((string) $event->event_type);

        if ($this->isPendingConfirmationStatus($status)) {
            return 'pending_confirmation';
        }

        if ($this->isConfirmedStatus($status)) {
            return 'confirmed_waiting_dispatch';
        }

        if ($this->isDispatchedStatus($status)) {
            return 'dispatched_waiting_delivery';
        }

        if ($this->isDeliveredStatus($status)) {
            return 'delivered';
        }

        if ($event->event_type === OperationalEvent::ORDER_CONFIRMED) {
            return 'confirmed_waiting_dispatch';
        }

        if ($event->event_type === OperationalEvent::ORDER_DELIVERED) {
            return 'delivered';
        }

        if (in_array($event->event_type, [
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
        ], true)) {
            return 'dispatched_waiting_delivery';
        }

        if (in_array($eventType, array_map('strtolower', $this->pendingConfirmationEventTypes()), true)) {
            return 'pending_confirmation';
        }

        return 'closed_or_other';
    }

    private function stockAppStatus(OperationalEvent $event): string
    {
        $status = $this->stockAppPayloadStatus($event)
            ?? $event->event_type;

        return str_replace([' ', '-'], '_', strtolower(trim((string) $status)));
    }

    private function stockAppPayloadStatus(OperationalEvent $event): mixed
    {
        $confirmationKeys = [
            'confirmation_status',
            'confirm_status',
            'customer_confirmation_status',
            'customer_confirm_status',
            'call_status',
            'order.confirmation_status',
            'order.confirm_status',
            'order.customer_confirmation_status',
            'order.customer_confirm_status',
            'order.call_status',
            'data.confirmation_status',
            'data.confirm_status',
            'data.customer_confirmation_status',
            'data.customer_confirm_status',
            'data.call_status',
            'payload.confirmation_status',
            'payload.confirm_status',
            'payload.customer_confirmation_status',
            'payload.customer_confirm_status',
            'payload.call_status',
        ];

        foreach ($confirmationKeys as $key) {
            $value = data_get($event->payload, $key);

            if (filled($value) && $this->isPendingConfirmationStatus(str_replace([' ', '-'], '_', strtolower(trim((string) $value))))) {
                return $value;
            }
        }

        foreach ([
            'status',
            'order_status',
            'current_status',
            'delivery_status',
            'status_name',
            'status_label',
            'order_state',
            'parcel_status',
            'shipment_status',
            'fulfillment_status',
            'order.status',
            'order.order_status',
            'order.current_status',
            'order.delivery_status',
            'data.status',
            'data.order_status',
            'data.current_status',
            'data.delivery_status',
            'payload.status',
            'payload.order_status',
            'payload.current_status',
            'payload.delivery_status',
        ] as $key) {
            $value = data_get($event->payload, $key);

            if (filled($value)) {
                return $value;
            }
        }

        foreach ($confirmationKeys as $key) {
            $value = data_get($event->payload, $key);

            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private function isPendingConfirmationStatus(string $status): bool
    {
        return in_array($status, [
            'pending',
            'new',
            'created',
            'order_pending',
            'pending_confirmation',
            'confirmation_pending',
            'pending_confirm',
            'pending_call',
            'call_pending',
            'to_confirm',
            'not_confirmed',
            'unconfirmed',
            'no_answer',
        ], true)
            || str_contains($status, 'pending')
            || str_contains($status, 'to_confirm')
            || str_contains($status, 'not_confirm')
            || str_contains($status, 'unconfirmed')
            || str_contains($status, 'no_answer');
    }

    private function isConfirmedStatus(string $status): bool
    {
        return in_array($status, [
            'confirmed',
            'confirm',
            'order_confirmed',
        ], true);
    }

    private function isDispatchedStatus(string $status): bool
    {
        return in_array($status, [
            'dispatched',
            'dispatch',
            'shipped',
            'shipping',
            'tracking',
            'tracking_added',
            'tracking_number',
            'tracking_number_added',
            'courier_pending',
            'delivery_pending',
            'out_for_delivery',
            'wholesale_sent',
            'wholesale_dispatched',
            'wholesale_parcel_sent',
            'transport_sent',
            'parcel_sent',
            'sent_to_courier',
        ], true);
    }

    private function isDeliveredStatus(string $status): bool
    {
        return in_array($status, [
            'delivered',
            'delivery_done',
            'completed',
            'complete',
        ], true);
    }

    private function stockAppOrderValue(OperationalEvent $event): float
    {
        return max((float) (
            data_get($event->payload, 'sale_amount')
            ?? data_get($event->payload, 'customer_total_amount')
            ?? data_get($event->payload, 'total_customer_amount')
            ?? data_get($event->payload, 'total_amount')
            ?? data_get($event->payload, 'amount')
            ?? $event->revenue_amount
            ?? 0
        ), 0);
    }

    private function safeDate(?string $date, Carbon $fallback): Carbon
    {
        try {
            return filled($date) ? Carbon::parse($date) : $fallback->copy();
        } catch (\Throwable) {
            return $fallback->copy();
        }
    }

    private function teamSummary(): array
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->is_staff_supervisor || ! $this->business) {
            return [];
        }

        $businessId = (int) $this->business->id;
        $reports = $this->directReportsForBusiness($user, $businessId);
        $reportIds = $reports->pluck('id');

        if ($reportIds->isEmpty()) {
            return [];
        }

        $missions = Mission::query()
            ->where('business_id', $businessId)
            ->whereIn('assigned_user_id', $reportIds)
            ->active()
            ->get();

        return [
            'people' => $reportIds->count(),
            'open' => $missions->count(),
            'overdue' => $missions->filter(fn (Mission $mission): bool => $mission->due_at?->isPast() ?? false)->count(),
            'blocked' => $missions->whereIn('status', [Mission::STATUS_BLOCKED, Mission::STATUS_ESCALATED])->count(),
            'waiting_review' => $missions->where('status', Mission::STATUS_WAITING_REVIEW)->count(),
            'members' => $reports
                ->map(function (User $employee) use ($missions): array {
                    $memberMissions = $missions->where('assigned_user_id', $employee->id);

                    $url = MissionResource::canAccess()
                        ? MissionResource::getUrl('index', [
                            'tableFilters' => [
                                'assigned_user_id' => [
                                    'value' => $employee->id,
                                ],
                            ],
                        ])
                        : null;

                    return [
                        'name' => $employee->name,
                        'open' => $memberMissions->count(),
                        'overdue' => $memberMissions->filter(fn (Mission $mission): bool => $mission->due_at?->isPast() ?? false)->count(),
                        'blocked' => $memberMissions->whereIn('status', [Mission::STATUS_BLOCKED, Mission::STATUS_ESCALATED])->count(),
                        'url' => $url,
                    ];
                })
                ->sortByDesc('overdue')
                ->values()
                ->all(),
        ];
    }

    private function directReportsForBusiness(User $user, int $businessId): Collection
    {
        return $user->directReports()
            ->where('is_employee', true)
            ->where(function ($query) use ($businessId): void {
                $query->where('business_id', $businessId)
                    ->orWhereHas('activeStaffResponsibilityAssignments', fn ($query) => $query->where('business_id', $businessId));
            })
            ->orderBy('name')
            ->get();
    }

    private function responsibilityDirection(string $responsibility): string
    {
        return match ($responsibility) {
            'order_confirmation' => 'Verify customer intent, phone, address, product, size, and value quickly; resolve no-answer orders through structured follow-up.',
            'return_recovery' => 'Contact returned and failed-delivery customers, identify the real cause, and recover suitable orders through correction or resend.',
            'dispatch' => 'Move confirmed orders to courier without avoidable delay and make sure tracking is complete.',
            'delivery_follow_up' => 'Follow dispatched parcels that are not delivered yet, solve customer or courier blockers, and push them to delivered status.',
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
            'dispatch' => 'Shorten order-to-courier time and prevent confirmed orders from getting stuck before dispatch.',
            'delivery_follow_up' => 'Convert dispatched parcel value into delivered revenue while keeping the employee view action-only.',
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
        return in_array($mission->responsibility_code, ['order_confirmation', 'return_recovery', 'dispatch', 'delivery_follow_up'], true)
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

    private function resolvedBusinessId(): ?int
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->business_id
            ?: $user->defaultBusinessId()
            ?: ($user->accessibleBusinessIds()[0] ?? null);
    }

    private function taskResponsibility(array $task): ?string
    {
        return match ((string) ($task['work_type'] ?? 'general')) {
            'pending_confirmation' => 'order_confirmation',
            'order_tracking', 'tracking_added' => 'dispatch',
            'delivery_follow_up', 'order_delivery' => 'delivery_follow_up',
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
            'production_daily_entry', 'production_payout', 'production_waste' => 'production',
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
        $businessId = $this->resolvedBusinessId();

        if (! $user?->isStaff()) {
            return [];
        }

        return collect($user->staffResponsibilities($businessId))
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

<?php

namespace App\Filament\Pages;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Services\WorkQueueService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class TodaysWork extends Page
{
    protected static ?string $slug = 'todays-work';
    protected static ?string $navigationGroup = 'Work';
    protected static ?string $navigationLabel = "Today's Work";
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?int $navigationSort = 0;
    protected static string $view = 'filament.pages.todays-work';

    public ?Business $business = null;

    public array $workQueue = [];

    public function mount(WorkQueueService $workQueue): void
    {
        $businessId = Auth::user()?->business_id;
        $this->business = $businessId ? Business::query()->find($businessId) : null;
        $this->workQueue = $this->business ? $this->employeeWorkQueue($workQueue->forBusiness($this->business)) : [];
    }

    protected function getViewData(): array
    {
        return [
            'business' => $this->business,
            'workQueue' => $this->workQueue,
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

    private function employeeWorkQueue(array $workQueue): array
    {
        $tasks = collect($workQueue['tasks'] ?? [])
            ->filter(fn (array $task): bool => $this->taskVisibleToEmployee($task))
            ->values();

        $sections = [
            'due_today' => collect($workQueue['sections']['due_today'] ?? [])->filter(fn (array $task): bool => $this->taskVisibleToEmployee($task))->values()->all(),
            'high_priority' => collect($workQueue['sections']['high_priority'] ?? [])->filter(fn (array $task): bool => $this->taskVisibleToEmployee($task))->values()->all(),
            'waiting_review' => collect($workQueue['sections']['waiting_review'] ?? [])->filter(fn (array $task): bool => $this->taskVisibleToEmployee($task))->values()->all(),
            'completed_today' => collect($workQueue['sections']['completed_today'] ?? [])->filter(fn (array $task): bool => $this->taskVisibleToEmployee($task))->values()->all(),
        ];

        $openTasks = $tasks->where('state', 'open')->values();
        $completedToday = $tasks
            ->where('state', 'completed')
            ->filter(fn (array $task): bool => filled($task['completed_at']) && Auth::user() && \Illuminate\Support\Carbon::parse($task['completed_at'])->isToday())
            ->values();

        $dueToday = $openTasks
            ->filter(fn (array $task): bool => filled($task['due_on']) && \Illuminate\Support\Carbon::parse($task['due_on'])->lessThanOrEqualTo(today()))
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
            ->filter(fn (array $task): bool => $task['priority'] === 'high' && filled($task['due_on']) && \Illuminate\Support\Carbon::parse($task['due_on'])->lessThan(today()))
            ->values();

        $teamWorkload = $openTasks
            ->groupBy(fn (array $task): string => (string) ($task['assigned_team'] ?? 'Unassigned'))
            ->map(fn (\Illuminate\Support\Collection $group, string $team): array => [
                'team' => $team,
                'count' => $group->count(),
                'high_priority' => $group->where('priority', 'high')->count(),
            ])
            ->sortByDesc('count')
            ->values();

        return [
            ...$workQueue,
            'summary' => [
                'Tasks due today' => $dueToday->count(),
                'High priority' => $highPriority->count(),
                'Waiting for review' => $waitingReview->count(),
                'Completed today' => $completedToday->count(),
                'Overdue' => $blockedWork->count(),
                'Completion rate' => $this->employeeCompletionRate($openTasks->count(), $completedToday->count()),
            ],
            'sections' => $sections,
            'tasks' => $tasks->all(),
            'team_workload' => $teamWorkload->all(),
            'open_count' => $openTasks->count(),
            'blocked_count' => $blockedWork->count(),
            'completed_today_count' => $completedToday->count(),
        ];
    }

    private function taskVisibleToEmployee(array $task): bool
    {
        $user = Auth::user();
        $profile = $user?->employeeAccessProfileValue() ?? 'operations';
        $workType = (string) ($task['work_type'] ?? 'general');

        return match ($profile) {
            'work_only' => in_array($workType, [
                'order_tracking',
                'return_action',
                'resend_follow_up',
                'fake_order_check',
                'tracking_added',
                'order_delivery',
                'missing_material_sku',
                'production_waste',
                'stock_movement',
                'general',
            ], true),
            'operations' => in_array($workType, [
                'order_tracking',
                'return_action',
                'resend_follow_up',
                'fake_order_check',
                'tracking_added',
                'order_delivery',
                'production_payout',
                'missing_material_sku',
                'production_waste',
                'stock_movement',
                'general',
            ], true),
            'finance_ops', 'full_staff' => true,
            default => true,
        };
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

    private function employeeCompletionRate(int $openCount, int $completedToday): string
    {
        $total = $openCount + $completedToday;

        if ($total <= 0) {
            return '100%';
        }

        return number_format(($completedToday / $total) * 100, 0).'%' ;
    }
}

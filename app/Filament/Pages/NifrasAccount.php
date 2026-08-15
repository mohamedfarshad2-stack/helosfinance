<?php

namespace App\Filament\Pages;

use App\Domains\FinancialClarity\Services\RevenuePipelineService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Mission;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class NifrasAccount extends Page
{
    protected static ?string $slug = 'nifras-account';

    protected static ?string $navigationGroup = 'My Work';

    protected static ?string $navigationLabel = 'Nifras Account';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.nifras-account';

    public ?Business $business = null;

    public array $pipeline = [];

    public array $teamRows = [];

    public static function shouldRegisterNavigation(): bool
    {
        return static::isNifrasAccount();
    }

    public static function canAccess(): bool
    {
        return static::isNifrasAccount();
    }

    public function mount(RevenuePipelineService $revenuePipeline): void
    {
        $this->loadAccount($revenuePipeline);
    }

    public function refreshAccount(RevenuePipelineService $revenuePipeline): void
    {
        $this->loadAccount($revenuePipeline);
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    protected function getViewData(): array
    {
        return [
            'business' => $this->business,
            'pipeline' => $this->pipeline,
            'teamRows' => $this->teamRows,
            'hasBusiness' => $this->business instanceof Business,
            'isNifras' => static::isNifrasAccount(),
        ];
    }

    private static function isNifrasAccount(): bool
    {
        $user = Auth::user();

        return Auth::check()
            && $user instanceof User
            && strtolower((string) $user->email) === 'nifras@helos.com';
    }

    private function loadAccount(RevenuePipelineService $revenuePipeline): void
    {
        $user = Auth::user();
        $businessId = $user?->defaultBusinessId() ?: ($user?->accessibleBusinessIds()[0] ?? null);
        $this->business = $businessId ? Business::query()->find($businessId) : null;

        if (! $this->business instanceof Business) {
            $this->pipeline = [];
            $this->teamRows = [];

            return;
        }

        $this->pipeline = $revenuePipeline->forCurrentMonth($this->business);
        $this->teamRows = $this->directReportRows();
    }

    private function directReportRows(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return $user->directReports()
            ->where('is_employee', true)
            ->orderBy('name')
            ->get()
            ->map(function (User $report): array {
                $open = Mission::query()
                    ->where('business_id', $this->business?->id)
                    ->where('assigned_user_id', $report->id)
                    ->active()
                    ->count();

                $overdue = Mission::query()
                    ->where('business_id', $this->business?->id)
                    ->where('assigned_user_id', $report->id)
                    ->active()
                    ->where('due_at', '<', today())
                    ->count();

                return [
                    'name' => $report->name,
                    'responsibilities' => collect($report->staffResponsibilities($this->business?->id))
                        ->map(fn (string $code): string => User::staffResponsibilityOptions()[$code] ?? $code)
                        ->values()
                        ->all(),
                    'open' => $open,
                    'overdue' => $overdue,
                    'status' => match (true) {
                        $overdue > 0 => 'Behind',
                        $open > 3 => 'Attention Needed',
                        $open > 0 => 'On Track',
                        default => 'On Track',
                    },
                ];
            })
            ->values()
            ->all();
    }
}

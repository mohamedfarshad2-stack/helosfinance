<?php

namespace App\Filament\Pages;

use App\Domains\FinancialClarity\Services\BusinessAdvisorService;
use App\Domains\FinancialClarity\Services\BreakEvenIntelligenceService;
use App\Domains\FinancialClarity\Services\CapitalIntelligenceService;
use App\Domains\FinancialClarity\Services\CashIntelligenceService;
use App\Domains\FinancialClarity\Services\GoalIntelligenceService;
use App\Domains\FinancialClarity\Services\InventoryIntelligenceService;
use App\Domains\FinancialClarity\Services\TrustValidationService;
use App\Domains\FinancialClarity\Services\RevenuePipelineService;
use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\Shared\Services\WorkQueueService;
use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\FinancialSnapshot;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\CodOrderWorkbench;
use App\Filament\Resources\BankTransactionResource;
use App\Filament\Resources\BusinessResource;
use App\Filament\Resources\CostAssumptionResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\MaterialComponentResource;
use App\Filament\Resources\OperationalEventResource;
use App\Filament\Resources\ProductionEntryResource;
use App\Filament\Resources\ServiceBillingResource;
use App\Filament\Resources\SkuRecipeResource;
use App\Filament\Resources\SkuResource;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;

class ClientHealthReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'HELOS';
    protected static ?string $navigationLabel = 'Owner Home';
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static ?int $navigationSort = -1;
    protected static string $view = 'filament.pages.client-health-report';

    public ?array $data = [];

    public ?Business $business = null;

    public ?FinancialSnapshot $snapshot = null;

    public array $advisor = [];

    public array $briefing = [];

    public array $cashIntelligence = [];

    public array $capitalIntelligence = [];

    public array $inventoryIntelligence = [];

    public array $revenuePipeline = [];

    public array $breakEvenStory = [];

    public array $goalStory = [];

    public array $healthStory = [];

    public array $bucketStory = [];

    public array $lifecycleStory = [];

    public array $operationalSummary = [];

    public array $treasuryStory = [];

    public array $trustStatus = [];

    public array $ownerBusinessMap = [];

    public array $ownerSetupGuide = [];

    public Collection $trend;

    public Collection $topExpenses;

    public array $impact = [];

    public function mount(BusinessHealthSnapshotService $snapshots, BusinessAdvisorService $advisor, CashIntelligenceService $cashIntelligence, CapitalIntelligenceService $capitalIntelligence, InventoryIntelligenceService $inventoryIntelligence, RevenuePipelineService $revenuePipeline, BreakEvenIntelligenceService $breakEvenIntelligence, GoalIntelligenceService $goalIntelligence, TrustValidationService $trustValidation, WorkQueueService $workQueue): void
    {
        $this->form->fill([
            'business_id' => Auth::user()?->defaultBusinessId(),
            'goal_type' => 'profit',
            'goal_amount' => null,
        ]);

        $this->loadReport($snapshots, $advisor, $cashIntelligence, $capitalIntelligence, $inventoryIntelligence, $revenuePipeline, $breakEvenIntelligence, $goalIntelligence, $trustValidation, $workQueue, false);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('business_id')
                    ->label('Client / business')
                    ->options(fn () => $this->businessOptions())
                    ->searchable()
                    ->required(),
                Select::make('goal_type')
                    ->label('Goal type')
                    ->options(GoalIntelligenceService::goalTypeOptions())
                    ->default('profit')
                    ->required(),
                \Filament\Forms\Components\TextInput::make('goal_amount')
                    ->label('Target amount')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('300000')
                    ->helperText('Use money for profit, revenue, and collections. Use a count for deliveries.'),
            ])
            ->statePath('data')
            ->columns(3);
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    public function refreshReport(BusinessHealthSnapshotService $snapshots, BusinessAdvisorService $advisor, CashIntelligenceService $cashIntelligence, CapitalIntelligenceService $capitalIntelligence, InventoryIntelligenceService $inventoryIntelligence, RevenuePipelineService $revenuePipeline, BreakEvenIntelligenceService $breakEvenIntelligence, GoalIntelligenceService $goalIntelligence, TrustValidationService $trustValidation, WorkQueueService $workQueue): void
    {
        $this->loadReport($snapshots, $advisor, $cashIntelligence, $capitalIntelligence, $inventoryIntelligence, $revenuePipeline, $breakEvenIntelligence, $goalIntelligence, $trustValidation, $workQueue, true);
    }

    private function loadReport(BusinessHealthSnapshotService $snapshots, BusinessAdvisorService $advisor, CashIntelligenceService $cashIntelligence, CapitalIntelligenceService $capitalIntelligence, InventoryIntelligenceService $inventoryIntelligence, RevenuePipelineService $revenuePipeline, BreakEvenIntelligenceService $breakEvenIntelligence, GoalIntelligenceService $goalIntelligence, TrustValidationService $trustValidation, WorkQueueService $workQueue, bool $refreshSnapshot): void
    {
        $state = $this->form->getState();
        $businessId = (int) ($state['business_id'] ?? 0);
        $this->business = Business::query()->find($businessId);

        if (! $this->business) {
            $this->snapshot = null;
            $this->advisor = [];
            $this->trend = collect();
            $this->topExpenses = collect();
            $this->impact = [];
            $this->healthStory = [];
            $this->bucketStory = [];
            $this->briefing = [];
            $this->cashIntelligence = [];
            $this->capitalIntelligence = [];
            $this->inventoryIntelligence = [];
            $this->revenuePipeline = [];
            $this->breakEvenStory = [];
            $this->goalStory = [];
            $this->lifecycleStory = [];
            $this->operationalSummary = [];
            $this->treasuryStory = [];
            $this->trustStatus = [];
            $this->ownerBusinessMap = [];
            $this->ownerSetupGuide = [];

            return;
        }

        if (array_key_exists('goal_amount', $state) && $state['goal_amount'] !== null) {
            $goalIntelligence->saveGoal($this->business, [
                'goal_type' => $state['goal_type'] ?? 'profit',
                'goal_amount' => $state['goal_amount'] ?? null,
            ]);
        }

        $this->snapshot = $refreshSnapshot
            ? $snapshots->refreshCurrentMonth($this->business)
            : $snapshots->readCurrentMonth($this->business);

        if (! $this->snapshot instanceof FinancialSnapshot) {
            $preview = $snapshots->previewCurrentMonth($this->business);
            $this->snapshot = new FinancialSnapshot($preview);
        }

        $this->advisor = $advisor->forCurrentMonth($this->business);
        $this->briefing = $this->advisor['briefing'] ?? [];
        $this->cashIntelligence = $cashIntelligence->forCurrentMonth($this->business);
        $this->capitalIntelligence = $capitalIntelligence->forCurrentMonth($this->business);
        $this->inventoryIntelligence = $inventoryIntelligence->forCurrentMonth($this->business);
        $this->revenuePipeline = $revenuePipeline->forCurrentMonth($this->business);
        $this->breakEvenStory = $breakEvenIntelligence->forCurrentMonth($this->business);
        $this->goalStory = $goalIntelligence->forCurrentMonth($this->business);
        $goalSettings = $goalIntelligence->goalSettings($this->business);
        $this->form->fill([
            'business_id' => $this->business->id,
            'goal_type' => $goalSettings['type'] ?? 'profit',
            'goal_amount' => $goalSettings['amount'] > 0 ? $goalSettings['amount'] : null,
        ]);
        $this->trend = FinancialSnapshot::query()
            ->where('business_id', $this->business->id)
            ->orderByDesc('period_end')
            ->limit(6)
            ->get()
            ->reverse()
            ->values();

        $this->topExpenses = collect($this->snapshot?->metrics['top_expense_categories'] ?? [])
            ->map(fn (array $row): object => (object) $row);

        $this->impact = [
            'returns' => (float) ($this->advisor['return_impact'] ?? 0),
            'courier' => (float) ($this->advisor['courier_impact'] ?? 0),
            'sku' => $this->advisor['top_loss_sku'] ?? null,
        ];

        $this->lifecycleStory = $this->buildLifecycleStory();
        $this->healthStory = $this->buildHealthStory();
        $this->bucketStory = $this->buildBucketStory();
        $this->operationalSummary = $workQueue->forBusiness($this->business)['summary'] ?? [];
        $this->treasuryStory = $this->buildTreasuryStory();
        $this->trustStatus = $trustValidation->forCurrentMonth($this->business);
        $this->healthStory = $this->attachTrustStatus($this->healthStory, [
            'Money coming in' => 'revenue',
            'Money left after running the business' => 'profit',
            'Money still waiting to settle' => 'cash_pressure',
            'Returns hurting profits' => 'returns',
            'Stock holding cash' => 'stock',
            'Money tied up' => 'treasury',
        ], 'health');
        $this->bucketStory = $this->attachTrustStatus($this->bucketStory, [
            'Money Safe To Use' => 'safe_to_use',
            'Money Already Committed' => 'treasury',
            'Money Tied Up' => 'growth_capacity',
            'Money Waiting To Settle' => 'cash_pressure',
            'Money Safe To Withdraw' => 'safe_to_withdraw',
            'Growth Capacity' => 'growth_capacity',
        ], 'bucket');
        $this->breakEvenStory = $this->attachTrustStatus($this->breakEvenStory, [
            'Money Needed To Cover The Month' => 'break_even',
            'Deliveries Needed To Cover The Month' => 'break_even',
            'Revenue Needed To Cover The Month' => 'break_even',
            'Current Progress' => 'break_even',
            'Still Needed' => 'break_even',
            'What Is Making It Harder' => 'break_even',
        ], 'break_even');
        $this->goalStory = $this->attachTrustStatus($this->goalStory, [
            'Your Goal' => 'goal_progress',
            'Current Progress' => 'goal_progress',
            'Still Needed' => 'goal_progress',
            'What Is Slowing You Down' => 'goal_progress',
            'Fastest Path Forward' => 'goal_progress',
        ], 'goal');
        $this->treasuryStory['trust_status'] = $this->trustStatus['section_statuses']['treasury'] ?? 'Estimated';
        $this->ownerBusinessMap = $this->buildOwnerBusinessMap();
        $this->ownerSetupGuide = $this->buildOwnerSetupGuide();
    }

    protected function getViewData(): array
    {
        return [
            'business' => $this->business,
            'snapshot' => $this->snapshot,
            'advisor' => $this->advisor,
            'briefing' => $this->briefing,
            'cashIntelligence' => $this->cashIntelligence,
            'capitalIntelligence' => $this->capitalIntelligence,
            'inventoryIntelligence' => $this->inventoryIntelligence,
            'revenuePipeline' => $this->revenuePipeline,
            'breakEvenStory' => $this->breakEvenStory,
            'goalStory' => $this->goalStory,
            'lifecycleStory' => $this->lifecycleStory,
            'healthStory' => $this->healthStory,
            'bucketStory' => $this->bucketStory,
            'operationalSummary' => $this->operationalSummary,
            'treasuryStory' => $this->treasuryStory,
            'trustStatus' => $this->trustStatus,
            'ownerBusinessMap' => $this->ownerBusinessMap,
            'ownerSetupGuide' => $this->ownerSetupGuide,
            'trend' => $this->trend,
            'topExpenses' => $this->topExpenses,
            'impact' => $this->impact,
        ];
    }

    private function buildOwnerSetupGuide(): array
    {
        if (! $this->business instanceof Business) {
            return ['steps' => [], 'next_step' => null, 'progress' => 0];
        }

        $steps = [];
        $fixedExpenses = Expense::query()
            ->where('business_id', $this->business->id)
            ->where('expense_type', 'fixed')
            ->count();
        $employees = $this->business->employees()->count();
        $bankRows = BankTransaction::query()->where('business_id', $this->business->id)->count();
        $goalConfigured = (bool) ($this->goalStory['configured'] ?? false);
        $integration = IntegrationSource::query()->where('business_id', $this->business->id)->exists();
        $internalCodOrders = $this->business->codOrders()->exists();

        $steps[] = $this->setupStep(
            'business_profile',
            'Confirm business setup',
            'Business type, owner group, maturity, and setup stage must be correct before HELOS guides the owner.',
            filled($this->business->business_type) && filled($this->business->business_maturity),
            BusinessResource::getUrl('edit', ['record' => $this->business]),
            'Open business setup'
        );

        $steps[] = $this->setupStep(
            'fixed_costs',
            'Add monthly fixed costs',
            'Rent, salaries, subscriptions, and fixed commitments set the survival line for break-even.',
            $fixedExpenses > 0,
            ExpenseResource::getUrl('index'),
            'Open expenses'
        );

        $steps[] = $this->setupStep(
            'team',
            'Add staff and salary truth',
            'HELOS needs staff and pay-cycle truth before payroll pressure and weekly work can be trusted.',
            $employees > 0,
            EmployeeResource::getUrl('index'),
            'Open staff'
        );

        if ($this->business->supportsBusinessType(Business::TYPE_SERVICE)) {
            $serviceRecords = ServiceBillingRecord::query()
                ->where('business_id', $this->business->id)
                ->count();

            $steps[] = $this->setupStep(
                'service_billing',
                'Add service clients and monthly fees',
                'Registration fees, monthly subscriptions, paid, part-paid, and overdue service money should be recorded here.',
                $serviceRecords > 0,
                ServiceBillingResource::getUrl('index'),
                'Open service billing'
            );
        }

        if ($this->business->supportsSkuManagement()) {
            $skuCount = Sku::query()->where('business_id', $this->business->id)->count();

            $steps[] = $this->setupStep(
                'products',
                'Add products or SKUs',
                'Trading and manufacturing businesses need products before HELOS can read item-level sales and cost.',
                $skuCount > 0,
                SkuResource::getUrl('index'),
                'Open products'
            );
        }

        if ($this->business->supportsProductionTracking()) {
            $recipeCount = SkuRecipeItem::query()->where('business_id', $this->business->id)->count();

            $steps[] = $this->setupStep(
                'recipe',
                'Add materials, work steps, and recipes',
                'Manufacturing profit needs material components, labour steps, and SKU recipe lines before cost is trusted.',
                $recipeCount > 0,
                SkuRecipeResource::getUrl('index'),
                'Open recipes'
            );

            $steps[] = $this->setupStep(
                'production',
                'Start weekly production records',
                'Daily or weekly part production drives piece-work salary and production cost.',
                ProductionEntry::query()->where('business_id', $this->business->id)->exists(),
                ProductionEntryResource::getUrl('index'),
                'Open production pay'
            );
        }

        if (
            ($this->business->supportsBusinessType(Business::TYPE_TRADING) || $this->business->supportsBusinessType(Business::TYPE_MANUFACTURING))
            && $this->business->codOrderSource() !== Business::COD_SOURCE_NONE
        ) {
            $usesInternalCod = $this->business->usesInternalCodOrders();

            $steps[] = $this->setupStep(
                'sales',
                $usesInternalCod ? 'Start HELOS COD orders' : 'Connect stock-app',
                $usesInternalCod
                    ? 'Use this when the client does not use Stock App. Each HELOS COD order can carry its own courier, delivery charge, return charge, and resend charge.'
                    : 'Use this when the client already runs Stock App. Stock App order events feed money and profitability.',
                $usesInternalCod ? $internalCodOrders : ($integration || OperationalEvent::query()->where('business_id', $this->business->id)->exists()),
                $usesInternalCod ? CodOrderWorkbench::getUrl() : ($integration ? OperationalEventResource::getUrl('index') : '#helos-revenue'),
                $usesInternalCod ? 'Open COD orders' : ($integration ? 'Open sales events' : 'Review revenue flow')
            );
        }

        $steps[] = $this->setupStep(
            'bank',
            'Import or review bank and cash rows',
            'Bank review separates revenue, expenses, transfers, owner money, and shared/unallocated cash.',
            $bankRows > 0,
            $bankRows > 0 ? BankTransactionResource::getUrl('index') : BankStatementImport::getUrl(),
            $bankRows > 0 ? 'Open bank review' : 'Import bank statement'
        );

        $steps[] = $this->setupStep(
            'goal',
            'Set this month goal',
            'A simple profit, revenue, delivery, or collection target lets HELOS explain distance and fastest path.',
            $goalConfigured,
            '#helos-goal',
            'Open goal'
        );

        $completed = collect($steps)->where('done', true)->count();
        $next = collect($steps)->firstWhere('done', false);
        $progress = count($steps) > 0 ? (int) round(($completed / count($steps)) * 100) : 0;

        return [
            'headline' => $next
                ? 'Start here: '.$next['title'].'.'
                : 'This business is ready for daily owner review.',
            'subheadline' => 'Follow these steps in order. HELOS will say what to enter, why it matters, and what number becomes unsafe if it is skipped.',
            'progress' => $progress,
            'completed' => $completed,
            'total' => count($steps),
            'next_step' => $next,
            'steps' => $steps,
        ];
    }

    private function setupStep(string $key, string $title, string $why, bool $done, string $url, string $action): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'why' => $why,
            'done' => $done,
            'status' => $done ? 'Done' : 'Needed',
            'tone' => $done ? 'green' : 'amber',
            'url' => $url,
            'action' => $action,
        ];
    }

    private function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! $user?->seesAllBusinesses(), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function buildHealthStory(): array
    {
        $currentProfit = (float) ($this->snapshot?->estimated_profit ?? 0);
        $currentRevenue = (float) ($this->snapshot?->revenue_total ?? 0);
        $currentCosts = (float) ($this->snapshot?->cost_total ?? 0);
        $cashDue = (float) ($this->cashIntelligence['cash_due'] ?? 0);
        $bankMovement = (float) ($this->cashIntelligence['net_movement'] ?? 0);
        $returnImpact = (float) ($this->impact['returns'] ?? 0);
        $courierImpact = (float) ($this->impact['courier'] ?? 0);
        $flowGap = (float) ($this->inventoryIntelligence['flow_gap'] ?? 0);
        $wasteRatio = (float) ($this->inventoryIntelligence['waste_ratio'] ?? 0);
        $capitalCommitted = (float) ($this->capitalIntelligence['capital_committed'] ?? 0);
        $cashAfterObligations = (float) ($this->capitalIntelligence['cash_after_obligations_proxy'] ?? 0);
        $totalExpected = (float) ($this->revenuePipeline['total_expected_revenue'] ?? 0);
        $totalCollected = (float) ($this->revenuePipeline['total_collected_revenue'] ?? 0);
        $totalReturned = (float) ($this->revenuePipeline['total_returned_revenue'] ?? 0);
        $previousMonth = $this->trend->count() > 1 ? $this->trend->slice(-2, 1)->first() : null;
        $previousRevenue = (float) ($previousMonth?->revenue_total ?? 0);
        $revenueDelta = $currentRevenue - $previousRevenue;

        $headline = match (true) {
            $currentProfit < 0 => 'Money left after running the business is under pressure, so returns and spending need attention first.',
            $cashDue > 0 => 'Money still waiting to settle is tied up, so settlement discipline matters next.',
            $totalReturned > 0 => 'Returns are shaping the month and need careful watch.',
            $flowGap > 0 || $wasteRatio > 0 => 'Stock is holding more money than it is releasing.',
            $capitalCommitted > 0 => 'Money is already committed to operations, materials, or production.',
            default => 'Money coming in, money left after running the business, money still waiting to settle, returns, stock, and tied-up money are moving in one readable story.',
        };

        return [
            'headline' => $headline,
            'summary' => [
                'Money coming in moved '.($revenueDelta >= 0 ? 'up' : 'down').' by LKR '.number_format(abs($revenueDelta), 2).'.',
                $currentProfit >= 0
                    ? 'Money left after running the business is still positive at LKR '.number_format($currentProfit, 2).'.'
                    : 'Money left after running the business is still negative at LKR '.number_format(abs($currentProfit), 2).'.',
                $cashDue > 0
                    ? 'LKR '.number_format($cashDue, 2).' is still waiting to be settled.'
                    : 'Money still waiting to settle is light right now.',
            ],
            'cards' => [
                [
                    'title' => 'Money coming in',
                    'value' => 'LKR '.number_format($currentRevenue, 2),
                    'status' => $totalCollected > 0 ? 'Coming in' : 'Watch',
                    'tone' => $totalCollected > 0 ? 'info' : 'warning',
                    'note' => $totalExpected > 0
                        ? 'Expected: LKR '.number_format($totalExpected, 2).' | Collected: LKR '.number_format($totalCollected, 2)
                        : ($this->revenuePipeline['headline'] ?? 'No order flow recorded yet.'),
                ],
                [
                    'title' => 'Money left after running the business',
                    'value' => 'LKR '.number_format($currentProfit, 2),
                    'status' => $currentProfit >= 0 ? 'Healthy' : 'Under pressure',
                    'tone' => $currentProfit >= 0 ? 'success' : 'danger',
                    'note' => 'Current month running cost: LKR '.number_format($currentCosts, 2),
                ],
                [
                    'title' => 'Money still waiting to settle',
                    'value' => 'LKR '.number_format($cashDue, 2),
                    'status' => $cashDue <= 0 ? 'Settled' : 'Needs attention',
                    'tone' => $cashDue <= 0 ? 'success' : 'warning',
                    'note' => 'Bank movement: LKR '.number_format($bankMovement, 2),
                ],
                [
                    'title' => 'Returns hurting profits',
                    'value' => 'LKR '.number_format($returnImpact, 2),
                    'status' => $returnImpact <= 0 ? 'Calm' : 'Pressure',
                    'tone' => $returnImpact <= 0 ? 'success' : 'danger',
                    'note' => 'Courier impact: LKR '.number_format($courierImpact, 2),
                ],
                [
                    'title' => 'Stock holding cash',
                    'value' => (int) ($this->inventoryIntelligence['recipe_coverage'] ?? 0).'% coverage',
                    'status' => $flowGap <= 0 && $wasteRatio <= 0 ? 'Under control' : 'Watch',
                    'tone' => $flowGap <= 0 && $wasteRatio <= 0 ? 'success' : 'warning',
                    'note' => 'Flow gap: LKR '.number_format($flowGap, 2).' | Waste ratio: '.number_format($wasteRatio, 2).'%'
                ],
                [
                    'title' => 'Money tied up',
                    'value' => 'LKR '.number_format($capitalCommitted, 2),
                    'status' => $cashAfterObligations >= 0 ? 'Available' : 'Committed',
                    'tone' => $cashAfterObligations >= 0 ? 'info' : 'warning',
                    'note' => 'Money after commitments proxy: LKR '.number_format($cashAfterObligations, 2),
                ],
            ],
        ];
    }

    private function buildLifecycleStory(): array
    {
        $setupStatus = $this->business?->onboarding_status ?? 'setup';
        $nextSetupStep = $this->business?->nextSetupStep() ?? 'Continue setup';
        $setupHeadline = match ($setupStatus) {
            'setup' => 'The business is still in setup.',
            'fixed_expenses' => 'Fixed costs are being shaped into the business setup.',
            'sku_costing' => 'The business is moving through product costing.',
            'ready' => 'The business is ready for ongoing control.',
            default => 'The business lifecycle is still moving through setup.',
        };

        $orderRows = collect($this->revenuePipeline['orders'] ?? []);
        $orderCreated = $orderRows->where('status', OperationalEvent::ORDER_CREATED)->count();
        $orderConfirmed = $orderRows->where('status', OperationalEvent::ORDER_CONFIRMED)->count();
        $trackingAdded = $orderRows->where('status', OperationalEvent::TRACKING_NUMBER_ADDED)->count();
        $wholesaleSent = $orderRows->where('status', OperationalEvent::WHOLESALE_PARCEL_SENT)->count();
        $delivered = $orderRows->where('status', OperationalEvent::ORDER_DELIVERED)->count();
        $returned = $orderRows->where('status', OperationalEvent::ORDER_RETURNED)->count();
        $resent = $orderRows->where('status', OperationalEvent::ORDER_RESENT)->count();

        $trackingHeadline = match (true) {
            $returned > 0 && $resent > 0 => 'Orders are moving through delivery, returns, and resends.',
            $delivered > 0 => 'Orders are reaching delivery and moving toward money collection.',
            $wholesaleSent > 0 => 'Wholesale parcels are out by transport and waiting for delivery or settlement.',
            $trackingAdded > 0 => 'Orders are in dispatch and courier handoff.',
            $orderConfirmed > 0 => 'Orders are confirmed and waiting to move forward.',
            $orderCreated > 0 => 'Orders have started, but the lifecycle is still early.',
            default => 'No order lifecycle activity has been recorded yet.',
        };

        $stockDispatches = (int) ($this->inventoryIntelligence['finished_goods_dispatches'] ?? 0);
        $stockRestocked = (int) ($this->inventoryIntelligence['finished_goods_return_restocked'] ?? 0);
        $stockDamaged = (int) ($this->inventoryIntelligence['finished_goods_return_damaged'] ?? 0);
        $stockHeadline = match (true) {
            $stockRestocked > 0 && $stockDamaged > 0 => 'Stock is returning in both restockable and damaged states.',
            $stockRestocked > 0 => 'Returned stock is re-entering sellable inventory.',
            $stockDamaged > 0 => 'Returned stock is leaving sellable inventory as damaged.',
            $stockDispatches > 0 => 'Finished goods are leaving stock through dispatch.',
            default => 'Stock movement is quiet for now.',
        };

        $bankReviewCount = BankTransaction::query()
            ->where('business_id', $this->business->id)
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->count();
        $bankReviewedCount = BankTransaction::query()
            ->where('business_id', $this->business->id)
            ->where('status', 'classified')
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->count();
        $bankMatchedCount = BankTransaction::query()
            ->where('business_id', $this->business->id)
            ->where('status', 'matched')
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->count();
        $expensePayments = Expense::query()
            ->where('business_id', $this->business->id)
            ->whereBetween('spent_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        $expensePartialCount = (clone $expensePayments)->where('payment_status', 'partial')->count();
        $expenseChequePendingCount = (clone $expensePayments)->where('payment_status', 'cheque_pending')->count();
        $expenseCreditDueCount = (clone $expensePayments)->where('payment_status', 'credit_due')->count();
        $expensePaidCount = (clone $expensePayments)->where('payment_status', 'paid')->count();
        $cashDue = (float) ($this->cashIntelligence['cash_due'] ?? 0);
        $bankHeadline = match (true) {
            $cashDue > 0 && $bankMatchedCount > 0 => 'Money is partly settled and partly waiting on review or settlement.',
            $cashDue > 0 => 'Money is still waiting to be settled.',
            $bankMatchedCount > 0 => 'Money is moving from review to matched settlement.',
            $bankReviewedCount > 0 => 'Bank rows are being reviewed and classified.',
            $bankReviewCount > 0 => 'Bank rows are present and need attention.',
            default => 'Money movement is still quiet.',
        };

        $productionEntries = ProductionEntry::query()
            ->where('business_id', $this->business->id)
            ->whereBetween('produced_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        $productionPending = (clone $productionEntries)->where('payment_status', 'pending')->count();
        $productionPaid = (clone $productionEntries)->where('payment_status', 'paid')->count();
        $productionTotal = (clone $productionEntries)->count();
        $productionHeadline = match (true) {
            $productionPaid > 0 && $productionPending > 0 => 'Production work is happening, with some payouts still pending.',
            $productionPaid > 0 => 'Production payouts are moving into paid status.',
            $productionPending > 0 => 'Production has started, but payouts are still pending.',
            $productionTotal > 0 => 'Production activity is present this month.',
            default => 'Production activity is quiet for now.',
        };

        $integration = IntegrationSource::query()
            ->where('business_id', $this->business->id)
            ->latest('last_synced_at')
            ->first();
        $integrationHeadline = match (true) {
            $this->business->usesInternalCodOrders() => 'HELOS internal COD orders are active for this business.',
            $this->business->codOrderSource() === Business::COD_SOURCE_NONE => 'This business is not using a COD order workflow.',
            $integration instanceof IntegrationSource && $integration->status === 'active' => 'The stock-app integration is active.',
            $integration instanceof IntegrationSource && $integration->status === 'testing' => 'The stock-app integration is still being tested.',
            $integration instanceof IntegrationSource && $integration->status === 'paused' => 'The stock-app integration is paused.',
            $integration instanceof IntegrationSource => 'The stock-app integration exists, but the status is still being settled.',
            default => 'No active integration has been linked yet.',
        };

        return [
            'headline' => 'Business setup, orders, stock, money, production, and the sales source are readable together.',
            'summary' => [
                $setupHeadline.' Next step: '.$nextSetupStep.'.',
                $trackingHeadline,
                $stockHeadline,
                $bankHeadline,
                $productionHeadline,
                $integrationHeadline,
            ],
            'cards' => [
                [
                    'title' => 'Business setup',
                    'value' => ucfirst((string) $setupStatus),
                    'status' => $this->business?->fixed_expenses_locked_at ? 'Moving' : 'Open',
                    'tone' => $this->business?->onboarding_status === 'ready' ? 'success' : 'warning',
                    'note' => $nextSetupStep,
                ],
                [
                    'title' => 'Orders',
                    'value' => $orderConfirmed + $trackingAdded + $delivered,
                    'status' => $delivered > 0 ? 'Progressing' : 'Early',
                    'tone' => $delivered > 0 ? 'success' : 'info',
                    'note' => 'Created: '.$orderCreated.' | Confirmed: '.$orderConfirmed.' | Delivered: '.$delivered,
                ],
                [
                    'title' => 'Parcels on the move',
                    'value' => $trackingAdded + $delivered,
                    'status' => ($trackingAdded + $delivered) > 0 ? 'In motion' : 'Quiet',
                    'tone' => ($trackingAdded + $delivered) > 0 ? 'info' : 'warning',
                    'note' => 'Tracking added: '.$trackingAdded.' | Delivered: '.$delivered,
                ],
                [
                    'title' => 'Returns and retries',
                    'value' => $returned + $resent,
                    'status' => $returned > 0 ? 'Watching' : 'Calm',
                    'tone' => $returned > 0 ? 'warning' : 'success',
                    'note' => 'Returned: '.$returned.' | Resent: '.$resent,
                ],
                [
                    'title' => 'Stock movement',
                    'value' => $stockDispatches,
                    'status' => ($stockRestocked + $stockDamaged) > 0 ? 'Active' : 'Quiet',
                    'tone' => ($stockRestocked + $stockDamaged) > 0 ? 'warning' : 'info',
                    'note' => 'Restocked: '.$stockRestocked.' | Damaged: '.$stockDamaged,
                ],
                [
                    'title' => 'Money movement',
                    'value' => 'LKR '.number_format((float) ($this->cashIntelligence['net_movement'] ?? 0), 2),
                    'status' => $cashDue > 0 ? 'Waiting' : 'Settled',
                    'tone' => $cashDue > 0 ? 'warning' : 'success',
                    'note' => 'Bank rows: '.$bankReviewCount.' | Reviewed: '.$bankReviewedCount.' | Matched: '.$bankMatchedCount.' | Expenses paid: '.$expensePaidCount,
                ],
                [
                    'title' => 'Production activity',
                    'value' => $productionTotal,
                    'status' => $productionPending > 0 ? 'Pending payouts' : 'Settled',
                    'tone' => $productionPending > 0 ? 'warning' : 'success',
                    'note' => 'Pending: '.$productionPending.' | Paid: '.$productionPaid,
                ],
                [
                    'title' => 'Current business position',
                    'value' => 'LKR '.number_format((float) ($this->snapshot?->estimated_profit ?? 0), 2),
                    'status' => 'One story',
                    'tone' => 'info',
                    'note' => 'The month is being read across business, orders, stock, money, and production.',
                ],
            ],
            'integration' => [
                'headline' => $integrationHeadline,
                'status' => $integration?->status ?? 'missing',
                'last_synced_at' => optional($integration?->last_synced_at)->toDateTimeString(),
            ],
            'money' => [
                'headline' => $bankHeadline,
                'expense_partial_count' => $expensePartialCount,
                'expense_cheque_pending_count' => $expenseChequePendingCount,
                'expense_credit_due_count' => $expenseCreditDueCount,
                'expense_paid_count' => $expensePaidCount,
                'bank_review_count' => $bankReviewCount,
                'bank_reviewed_count' => $bankReviewedCount,
                'bank_matched_count' => $bankMatchedCount,
            ],
        ];
    }

    private function buildBucketStory(): array
    {
        $cashAfterObligations = (float) ($this->capitalIntelligence['cash_after_obligations_proxy'] ?? 0);
        $capitalCommitted = (float) ($this->capitalIntelligence['capital_committed'] ?? 0);
        $flowGap = (float) ($this->inventoryIntelligence['flow_gap'] ?? 0);
        $productionPendingPay = (float) ($this->capitalIntelligence['production_pending_pay'] ?? 0);
        $totalObligations = (float) ($this->cashIntelligence['total_obligations'] ?? 0);
        $cashDue = max((float) ($this->snapshot?->metrics['to_settle'] ?? 0), 0);
        $dueSoon = collect($this->cashIntelligence['due_soon_obligations'] ?? [])->sum('amount');
        $overdue = collect($this->cashIntelligence['overdue_obligations'] ?? [])->sum('amount');
        $availableAfterSettlement = max($cashAfterObligations - $cashDue, 0);
        $reserve = min(max($dueSoon + $overdue, 0), $availableAfterSettlement);
        $safeToUse = max($availableAfterSettlement - $reserve, 0);
        $tiedUp = max($capitalCommitted - $totalObligations, 0) + max($flowGap, 0);
        $safeToWithdraw = max($safeToUse - $tiedUp, 0);
        $growthCapacity = max($safeToUse - $totalObligations, 0);

        $headline = match (true) {
            $safeToUse > 0 => 'Money safe to use is ready for today.',
            $cashDue > 0 || $reserve > 0 => 'Money is protected first, so spending should stay careful.',
            default => 'Money is calm enough to read clearly for now.',
        };

        return [
            'headline' => $headline,
            'summary' => [
                'Reserve held back: LKR '.number_format($reserve, 2).'.',
                'Money already committed: LKR '.number_format($totalObligations, 2).'.',
                'Money tied up in stock and production: LKR '.number_format($tiedUp, 2).'.',
            ],
            'cards' => [
                [
                    'title' => 'Money Safe To Use',
                    'value' => 'LKR '.number_format($safeToUse, 2),
                    'status' => $safeToUse > 0 ? 'Ready' : 'Hold back',
                    'tone' => $safeToUse > 0 ? 'success' : 'warning',
                    'note' => 'Cash after settlement and reserve: LKR '.number_format($availableAfterSettlement - $reserve, 2),
                ],
                [
                    'title' => 'Money Already Committed',
                    'value' => 'LKR '.number_format($totalObligations, 2),
                    'status' => $totalObligations > 0 ? 'Spoken for' : 'Light',
                    'tone' => $totalObligations > 0 ? 'warning' : 'success',
                    'note' => 'Includes salaries, expenses, and pending production pay.',
                ],
                [
                    'title' => 'Money Tied Up',
                    'value' => 'LKR '.number_format($tiedUp, 2),
                    'status' => $tiedUp > 0 ? 'Locked' : 'Clear',
                    'tone' => $tiedUp > 0 ? 'warning' : 'success',
                    'note' => 'Production pending pay: LKR '.number_format($productionPendingPay, 2).' | Flow gap: LKR '.number_format($flowGap, 2),
                ],
                [
                    'title' => 'Money Waiting To Settle',
                    'value' => 'LKR '.number_format($cashDue, 2),
                    'status' => $cashDue > 0 ? 'Waiting' : 'Settled',
                    'tone' => $cashDue > 0 ? 'warning' : 'success',
                    'note' => 'From the monthly snapshot settle view.',
                ],
                [
                    'title' => 'Money Safe To Withdraw',
                    'value' => 'LKR '.number_format($safeToWithdraw, 2),
                    'status' => $safeToWithdraw > 0 ? 'Can draw' : 'Protect first',
                    'tone' => $safeToWithdraw > 0 ? 'info' : 'warning',
                    'note' => 'Owner money after protection and tied-up pressure.',
                ],
                [
                    'title' => 'Growth Capacity',
                    'value' => 'LKR '.number_format($growthCapacity, 2),
                    'status' => $growthCapacity > 0 ? 'Can grow' : 'Wait',
                    'tone' => $growthCapacity > 0 ? 'success' : 'warning',
                    'note' => 'Money left for growth after current commitments are covered.',
                ],
            ],
        ];
    }

    private function buildTreasuryStory(): array
    {
        $transactions = BankTransaction::query()
            ->where('business_id', $this->business->id)
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $businessNames = Business::query()->pluck('name', 'id');

        $accountRows = $transactions
            ->groupBy(fn (BankTransaction $transaction): string => trim((string) ($transaction->money_container ?: 'Shared / Unallocated')))
            ->map(function (Collection $group, string $container): array {
                $inflow = (float) $group->sum('credit');
                $outflow = (float) $group->sum('debit');

                return [
                    'container' => $container,
                    'count' => $group->count(),
                    'inflow' => $inflow,
                    'outflow' => $outflow,
                    'net' => $inflow - $outflow,
                    'review_count' => $group->where('status', 'review')->count(),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();

        $businessRows = $transactions
            ->filter(fn (BankTransaction $transaction): bool => filled($transaction->allocated_business_id) && $transaction->transaction_type !== 'transfer')
            ->groupBy('allocated_business_id')
            ->map(function (Collection $group, int|string $businessId) use ($businessNames): array {
                $inflow = (float) $group->sum('credit');
                $outflow = (float) $group->sum('debit');

                return [
                    'business' => $businessNames->get((int) $businessId, 'Business '.$businessId),
                    'count' => $group->count(),
                    'inflow' => $inflow,
                    'outflow' => $outflow,
                    'net' => $inflow - $outflow,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();

        $sharedRows = $transactions
            ->filter(function (BankTransaction $transaction): bool {
                if (blank($transaction->money_container)) {
                    return true;
                }

                if ($transaction->transaction_type === 'transfer' && blank($transaction->counter_money_container)) {
                    return true;
                }

                return blank($transaction->allocated_business_id) && in_array($transaction->transaction_type, ['revenue', 'expense', 'loan', 'owner_contribution', 'owner_withdrawal'], true);
            })
            ->sortByDesc('transaction_date')
            ->take(5)
            ->map(fn (BankTransaction $transaction): array => [
                'title' => trim((string) ($transaction->description ?: 'Bank row '.$transaction->id)),
                'container' => $transaction->money_container ?: 'Shared / Unallocated',
                'business' => $businessNames->get((int) ($transaction->allocated_business_id ?? 0), 'Shared / Unallocated'),
                'type' => $transaction->transaction_type ?: 'Needs review',
                'amount' => (float) max($transaction->credit, $transaction->debit),
                'status' => $transaction->status,
            ])
            ->values()
            ->all();

        $reviewCount = $transactions->where('status', 'review')->count();
        $unallocatedCount = $transactions
            ->filter(fn (BankTransaction $transaction): bool => blank($transaction->allocated_business_id) && in_array($transaction->transaction_type, ['revenue', 'expense', 'loan', 'owner_contribution', 'owner_withdrawal'], true))
            ->count();
        $transferReviewCount = $transactions
            ->filter(fn (BankTransaction $transaction): bool => $transaction->transaction_type === 'transfer' && blank($transaction->counter_money_container))
            ->count();

        $headline = match (true) {
            $reviewCount > 0 => 'Treasury rows are visible, but some still need review.',
            count($accountRows) > 1 => 'Money is moving through more than one container.',
            default => 'Treasury is readable in one cash lane for now.',
        };

        return [
            'headline' => $headline,
            'summary' => [
                'Cash accounts seen this month: '.count($accountRows).'.',
                $reviewCount > 0 ? $reviewCount.' rows still need review.' : 'No treasury rows are waiting on review right now.',
                $unallocatedCount > 0 ? $unallocatedCount.' rows still need business allocation.' : 'Business allocation is clear for the current rows.',
            ],
            'accounts' => $accountRows,
            'businesses' => $businessRows,
            'shared_rows' => $sharedRows,
            'review_count' => $reviewCount,
            'unallocated_count' => $unallocatedCount,
            'transfer_review_count' => $transferReviewCount,
        ];
    }

    private function buildOwnerBusinessMap(): array
    {
        $currentProfit = (float) ($this->snapshot?->estimated_profit ?? 0);
        $currentRevenue = (float) ($this->snapshot?->revenue_total ?? 0);
        $currentCosts = (float) ($this->snapshot?->cost_total ?? 0);
        $trustLabel = (string) ($this->trustStatus['status_label'] ?? 'Estimated');
        $safeToUseCard = $this->storyCard($this->bucketStory, 'Money Safe To Use');
        $breakEvenProgress = (float) ($this->breakEvenStory['progress']['coverage_percent'] ?? 0);
        $breakEvenRemainingDeliveries = $this->breakEvenStory['progress']['remaining_deliveries'] ?? null;
        $goalConfigured = (bool) ($this->goalStory['configured'] ?? false);
        $goalProgress = (float) ($this->goalStory['goal']['progress_percent'] ?? 0);
        $returnImpact = (float) ($this->impact['returns'] ?? 0);
        $recipeCoverage = (int) ($this->inventoryIntelligence['recipe_coverage'] ?? 0);
        $wasteRatio = (float) ($this->inventoryIntelligence['waste_ratio'] ?? 0);
        $dueSoon = collect($this->cashIntelligence['due_soon_obligations'] ?? [])->count();
        $overdue = collect($this->cashIntelligence['overdue_obligations'] ?? [])->count();
        $treasuryReviewCount = (int) ($this->treasuryStory['review_count'] ?? 0);
        $treasuryUnallocatedCount = (int) ($this->treasuryStory['unallocated_count'] ?? 0);

        $nodes = [
            $this->ownerMapNode(
                key: 'trust',
                title: 'Can I trust today?',
                value: $trustLabel,
                tone: match ($trustLabel) {
                    'Verified' => 'green',
                    'Pending Validation' => 'red',
                    default => 'amber',
                },
                progress: (int) ($this->trustStatus['data_quality_percent'] ?? 0),
                heading: $this->trustStatus['headline'] ?? 'Trust status is still being checked.',
                why: 'HELOS checks missing information, validation issues, calculation safety, integration health, and allocation quality before the owner trusts the month.',
                makeGreen: 'Clear critical warnings first, then review important warnings until the Trust Center becomes Verified.',
                nextStep: 'Open the Trust Center warnings below and fix the missing information one by one.',
                anchor: '#helos-trust-center'
            ),
            $this->ownerMapNode(
                key: 'health',
                title: 'Business health',
                value: $currentProfit >= 0 ? 'Positive' : 'Under pressure',
                tone: $currentProfit >= 0 ? 'green' : 'red',
                progress: $currentProfit >= 0 ? 80 : 35,
                heading: $this->healthStory['headline'] ?? 'Business picture is not ready yet.',
                why: $currentProfit >= 0
                    ? 'The current month is still showing money left after running the business.'
                    : 'The current month is showing pressure after costs, returns, stock, or cash movement.',
                makeGreen: 'Reduce the biggest cost or return pressure first, then push the strongest revenue stream.',
                nextStep: 'Review the business picture cards and the What to do next panel.',
                anchor: '#helos-business-picture'
            ),
            $this->ownerMapNode(
                key: 'cash',
                title: 'Cash safety',
                value: $safeToUseCard['value'] ?? 'Not ready',
                tone: ($safeToUseCard['tone'] ?? 'warning') === 'success' ? 'green' : 'amber',
                progress: ($safeToUseCard['tone'] ?? 'warning') === 'success' ? 85 : 45,
                heading: $this->bucketStory['headline'] ?? 'Money safety is not ready yet.',
                why: 'This checks cash after settlement, reserves, due commitments, and tied-up money.',
                makeGreen: 'Settle pending money, clear overdue obligations, and reduce tied-up stock or production pressure.',
                nextStep: 'Use Money Safe To Use and Weekly Reminders before spending or withdrawing.',
                anchor: '#helos-money-safety'
            ),
            $this->ownerMapNode(
                key: 'break_even',
                title: 'Break-even',
                value: $breakEvenRemainingDeliveries === null
                    ? 'Not ready'
                    : ($breakEvenRemainingDeliveries <= 0 ? 'Covered' : $breakEvenRemainingDeliveries.' deliveries'),
                tone: $breakEvenRemainingDeliveries === null ? 'gray' : ($breakEvenRemainingDeliveries <= 0 ? 'green' : ($breakEvenProgress >= 60 ? 'amber' : 'red')),
                progress: (int) min(max($breakEvenProgress, 0), 100),
                heading: $this->breakEvenStory['headline'] ?? 'Break-even is not ready yet.',
                why: 'HELOS compares current contribution against monthly fixed costs and shows how many deliveries or how much revenue is still needed.',
                makeGreen: 'Increase delivered profitable orders, reduce return/courier pressure, or lower fixed costs.',
                nextStep: 'Open the break-even pressure area and fix the top obstacle first.',
                anchor: '#helos-break-even'
            ),
            $this->ownerMapNode(
                key: 'goal',
                title: 'Goal progress',
                value: $goalConfigured ? number_format($goalProgress, 0).'%' : 'Not set',
                tone: ! $goalConfigured ? 'gray' : ($goalProgress >= 90 ? 'green' : ($goalProgress >= 50 ? 'amber' : 'red')),
                progress: (int) min(max($goalProgress, 0), 100),
                heading: $this->goalStory['headline'] ?? 'Set a monthly goal to start tracking progress.',
                why: 'The goal view compares the current month against the target and uses break-even contribution to estimate what is still needed.',
                makeGreen: 'Set a realistic monthly target, then improve the fastest path shown by HELOS.',
                nextStep: 'Open Your Goal and follow Fastest Path Forward.',
                anchor: '#helos-goal'
            ),
            $this->ownerMapNode(
                key: 'profit',
                title: 'Profitability',
                value: 'LKR '.number_format($currentProfit, 0),
                tone: $currentProfit >= 0 ? 'green' : 'red',
                progress: $currentProfit >= 0 ? 80 : 25,
                heading: $currentProfit >= 0 ? 'Profit is currently positive.' : 'Profit is currently negative.',
                why: 'Profit depends on revenue truth, SKU costs, production costs, returns, courier cost, salaries, and expenses.',
                makeGreen: 'Protect high-margin products, fix missing SKU recipes, and reduce costs that are not helping sales.',
                nextStep: 'Check top expenses, product pressure, and return impact.',
                anchor: '#helos-profitability'
            ),
            $this->ownerMapNode(
                key: 'stock',
                title: 'Stock pressure',
                value: $recipeCoverage.'% recipes',
                tone: $recipeCoverage < 70 || $wasteRatio > 10 ? 'red' : ($recipeCoverage < 95 || $wasteRatio > 0 ? 'amber' : 'green'),
                progress: $recipeCoverage,
                heading: $this->inventoryIntelligence['headline'] ?? 'Stock signal is not ready yet.',
                why: 'Stock pressure rises when recipes are missing, material consumption is unclear, or waste is high.',
                makeGreen: 'Complete SKU recipes, record material usage, and reduce waste or unexplained flow gaps.',
                nextStep: 'Open stock holding cash and recipe coverage details.',
                anchor: '#helos-stock'
            ),
            $this->ownerMapNode(
                key: 'returns',
                title: 'Returns pressure',
                value: 'LKR '.number_format($returnImpact, 0),
                tone: $returnImpact > 0 ? 'red' : 'green',
                progress: $returnImpact > 0 ? 35 : 100,
                heading: $returnImpact > 0 ? 'Returns are hurting profit.' : 'Returns are calm right now.',
                why: 'Returned parcels can create courier cost, lost revenue, resend cost, and stock damage.',
                makeGreen: 'Find the top return reason, fix confirmation quality, and reduce repeat courier losses.',
                nextStep: 'Open return and courier pressure details.',
                anchor: '#helos-returns'
            ),
            $this->ownerMapNode(
                key: 'treasury',
                title: 'Treasury review',
                value: ($treasuryReviewCount + $treasuryUnallocatedCount).' rows',
                tone: ($treasuryReviewCount + $treasuryUnallocatedCount) > 0 ? 'amber' : 'green',
                progress: ($treasuryReviewCount + $treasuryUnallocatedCount) > 0 ? 55 : 100,
                heading: $this->treasuryStory['headline'] ?? 'Treasury picture is not ready yet.',
                why: 'Bank money must be classified and allocated before cash, profit, and business performance can be trusted.',
                makeGreen: 'Classify review rows and allocate shared/unallocated transactions to the correct business.',
                nextStep: 'Open Treasury picture and clear shared/unallocated rows.',
                anchor: '#helos-treasury'
            ),
            $this->ownerMapNode(
                key: 'week',
                title: 'This week',
                value: $overdue > 0 ? $overdue.' overdue' : $dueSoon.' due soon',
                tone: $overdue > 0 ? 'red' : ($dueSoon > 0 ? 'amber' : 'green'),
                progress: $overdue > 0 ? 25 : ($dueSoon > 0 ? 65 : 100),
                heading: $overdue > 0 ? 'Some commitments are already overdue.' : ($dueSoon > 0 ? 'Some commitments need attention this week.' : 'No urgent weekly commitments are showing.'),
                why: 'Weekly salaries, supplier payments, cheque dates, and COD settlement pressure can hurt cash if missed.',
                makeGreen: 'Pay or schedule overdue items first, then prepare money for due-soon commitments.',
                nextStep: 'Open Weekly reminders and handle overdue/due-soon items.',
                anchor: '#helos-weekly-reminders'
            ),
        ];

        $attention = collect($nodes)
            ->whereIn('tone', ['red', 'amber', 'gray'])
            ->sortBy(fn (array $node): int => match ($node['tone']) {
                'red' => 0,
                'amber' => 1,
                'gray' => 2,
                default => 3,
            })
            ->take(3)
            ->values()
            ->all();

        $redCount = collect($nodes)->where('tone', 'red')->count();
        $amberCount = collect($nodes)->where('tone', 'amber')->count();
        $greenCount = collect($nodes)->where('tone', 'green')->count();
        $notReadyCount = collect($nodes)->where('tone', 'gray')->count();
        $overallScore = (int) round(collect($nodes)->avg('progress') ?? 0);
        $chartMax = max($currentRevenue, $currentCosts, abs($currentProfit), 1);

        return [
            'headline' => $this->ownerMapHeadline($nodes),
            'default_key' => $attention[0]['key'] ?? ($nodes[0]['key'] ?? 'trust'),
            'overall_score' => $overallScore,
            'counts' => [
                'red' => $redCount,
                'amber' => $amberCount,
                'green' => $greenCount,
                'gray' => $notReadyCount,
            ],
            'key_metrics' => [
                [
                    'label' => 'Revenue',
                    'value' => 'LKR '.number_format($currentRevenue, 0),
                    'tone' => $currentRevenue > 0 ? 'green' : 'gray',
                    'percent' => (int) round(($currentRevenue / $chartMax) * 100),
                ],
                [
                    'label' => 'Costs',
                    'value' => 'LKR '.number_format($currentCosts, 0),
                    'tone' => $currentCosts > $currentRevenue ? 'red' : 'amber',
                    'percent' => (int) round(($currentCosts / $chartMax) * 100),
                ],
                [
                    'label' => 'Profit',
                    'value' => 'LKR '.number_format($currentProfit, 0),
                    'tone' => $currentProfit >= 0 ? 'green' : 'red',
                    'percent' => (int) round((abs($currentProfit) / $chartMax) * 100),
                ],
                [
                    'label' => 'Safe to use',
                    'value' => $safeToUseCard['value'] ?? 'Not ready',
                    'tone' => ($safeToUseCard['tone'] ?? 'warning') === 'success' ? 'green' : 'amber',
                    'percent' => ($safeToUseCard['tone'] ?? 'warning') === 'success' ? 80 : 35,
                ],
            ],
            'mini_graphs' => [
                [
                    'label' => 'Break-even',
                    'value' => number_format(min(max($breakEvenProgress, 0), 100), 0).'%',
                    'percent' => (int) min(max($breakEvenProgress, 0), 100),
                    'tone' => $breakEvenRemainingDeliveries === null ? 'gray' : ($breakEvenRemainingDeliveries <= 0 ? 'green' : ($breakEvenProgress >= 60 ? 'amber' : 'red')),
                ],
                [
                    'label' => 'Goal',
                    'value' => $goalConfigured ? number_format($goalProgress, 0).'%' : 'Not set',
                    'percent' => (int) min(max($goalProgress, 0), 100),
                    'tone' => ! $goalConfigured ? 'gray' : ($goalProgress >= 90 ? 'green' : ($goalProgress >= 50 ? 'amber' : 'red')),
                ],
                [
                    'label' => 'Recipe coverage',
                    'value' => $recipeCoverage.'%',
                    'percent' => $recipeCoverage,
                    'tone' => $recipeCoverage < 70 ? 'red' : ($recipeCoverage < 95 ? 'amber' : 'green'),
                ],
                [
                    'label' => 'Data quality',
                    'value' => (int) ($this->trustStatus['data_quality_percent'] ?? 0).'%',
                    'percent' => (int) ($this->trustStatus['data_quality_percent'] ?? 0),
                    'tone' => $trustLabel === 'Verified' ? 'green' : ($trustLabel === 'Pending Validation' ? 'red' : 'amber'),
                ],
            ],
            'nodes' => $nodes,
            'top_actions' => $attention,
        ];
    }

    private function ownerMapNode(string $key, string $title, string $value, string $tone, int $progress, string $heading, string $why, string $makeGreen, string $nextStep, string $anchor): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'value' => $value,
            'tone' => $tone,
            'flag' => match ($tone) {
                'green' => 'Green flag',
                'red' => 'Red flag',
                'gray' => 'Not ready',
                default => 'Amber flag',
            },
            'progress' => max(0, min($progress, 100)),
            'heading' => $heading,
            'why' => $why,
            'make_green' => $makeGreen,
            'next_step' => $nextStep,
            'anchor' => $anchor,
        ];
    }

    private function ownerMapHeadline(array $nodes): string
    {
        $red = collect($nodes)->where('tone', 'red')->count();
        $amber = collect($nodes)->where('tone', 'amber')->count();

        return match (true) {
            $red > 0 => $red.' red flag(s) need owner attention before the business is fully green.',
            $amber > 0 => 'No major red flags, but '.$amber.' area(s) still need attention.',
            default => 'The business map is mostly green right now.',
        };
    }

    private function storyCard(array $story, string $title): ?array
    {
        return collect($story['cards'] ?? [])
            ->first(fn (array $card): bool => ($card['title'] ?? null) === $title);
    }

    private function attachTrustStatus(array $story, array $mapping, string $section): array
    {
        $metricStatuses = $this->trustStatus['metric_statuses'] ?? [];
        $sectionStatus = $this->trustStatus['section_statuses'][$section] ?? null;

        if (isset($story['cards']) && is_array($story['cards'])) {
            $story['cards'] = array_map(function (array $card) use ($mapping, $metricStatuses): array {
                $metric = $mapping[$card['title'] ?? ''] ?? null;

                if (! $metric || ! isset($metricStatuses[$metric])) {
                    return $card;
                }

                $card['trust_status'] = $metricStatuses[$metric]['status'] ?? null;
                $card['trust_reason'] = $metricStatuses[$metric]['reason'] ?? null;

                return $card;
            }, $story['cards']);
        }

        if ($sectionStatus) {
            $story['trust_status'] = $sectionStatus;
        }

        return $story;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()?->isOwner() ?? false);
    }

    public static function canAccess(): bool
    {
        return Auth::check() && (Auth::user()?->isOwner() ?? false);
    }

}

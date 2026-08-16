<?php

namespace App\Filament\Pages;

use App\Domains\FinancialClarity\Services\RevenuePipelineService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\ServiceClient;
use App\Domains\Shared\Models\ServiceLead;
use App\Domains\Shared\Services\StockAppClientSyncService;
use App\Domains\Shared\Services\ServiceLeadSpreadsheetImportService;
use App\Domains\Shared\Services\ServiceLeadTemplateExportService;
use App\Domains\Shared\Services\WorkQueueService;
use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;

class SandhamaliAccount extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected static ?string $slug = 'sandhamali-account';

    protected static ?string $navigationGroup = 'My Work';

    protected static ?string $navigationLabel = 'Sandhamali Account';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.sandhamali-account';

    public ?Business $business = null;

    public array $pipeline = [];

    public array $leadSummary = [];

    public array $clientSummary = [];

    public array $billingSummary = [];

    public array $teamExceptionSummary = [];

    public array $stockAppSyncStatus = [
        'available' => null,
        'note' => null,
        'synced_clients' => 0,
        'synced_billing_records' => 0,
    ];

    public SupportCollection $recentServiceLeads;

    public SupportCollection $activeServiceClients;

    public SupportCollection $billingDueRecords;

    public SupportCollection $clientWorkQueue;

    public ?array $leadData = [];

    public mixed $leadImportFile = null;

    public bool $showLeadDesk = true;

    public static function shouldRegisterNavigation(): bool
    {
        return static::isSandhamaliAccount();
    }

    public static function canAccess(): bool
    {
        return static::isSandhamaliAccount();
    }

    public function mount(RevenuePipelineService $revenuePipeline, WorkQueueService $workQueue): void
    {
        $this->leadData = $this->defaultLeadFormState();
        $this->loadWorkspace($revenuePipeline, $workQueue);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleLeadDesk')
                ->label(fn (): string => $this->showLeadDesk ? 'Close lead desk' : 'Open lead desk')
                ->icon(fn (): string => $this->showLeadDesk ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down')
                ->color('gray')
                ->action(function (): void {
                    $this->toggleLeadDesk();
                }),
            Actions\Action::make('downloadLeadSample')
                ->label('Download sample')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->downloadLeadSample()),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Hidden::make('business_id'),
            TextInput::make('prospect_name')
                ->label('Prospect / client name')
                ->required()
                ->maxLength(255),
            TextInput::make('contact_person')
                ->label('Contact person')
                ->maxLength(255),
            TextInput::make('phone')
                ->label('Phone')
                ->tel()
                ->maxLength(50),
            TextInput::make('whatsapp_number')
                ->label('WhatsApp')
                ->tel()
                ->maxLength(50),
            Select::make('source')
                ->label('Source')
                ->options(ServiceLead::sourceOptions())
                ->default(ServiceLead::SOURCE_COD_CONFIRMATION)
                ->required(),
            Select::make('status')
                ->label('Funnel stage')
                ->options(ServiceLead::statusOptions())
                ->default(ServiceLead::STATUS_LEAD)
                ->required(),
            Select::make('billing_terms')
                ->label('Billing terms')
                ->options(ServiceLead::billingTermsOptions())
                ->default(ServiceLead::BILLING_MONTH_END)
                ->required(),
            TextInput::make('expected_monthly_amount')
                ->label('Expected monthly amount')
                ->numeric()
                ->prefix('LKR')
                ->default(0),
            DatePicker::make('next_follow_up_at')
                ->label('Next follow-up'),
            Textarea::make('notes')
                ->label('Notes')
                ->rows(3)
                ->columnSpanFull(),
        ])->statePath('leadData')->columns(2);
    }

    public function saveLead(): void
    {
        $data = validator($this->leadData ?? [], [
            'prospect_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
            'source' => ['required', 'string'],
            'status' => ['required', 'string'],
            'billing_terms' => ['required', 'string'],
            'expected_monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'next_follow_up_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ])->validate();

        if (! $this->business instanceof Business) {
            Notification::make()
                ->title('No service business found')
                ->body('Sandhamali needs an accessible service business before a lead can be saved.')
                ->danger()
                ->send();

            return;
        }

        ServiceLead::query()->create([
            'business_id' => $this->business->id,
            'prospect_name' => $data['prospect_name'],
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'source' => $data['source'],
            'status' => $data['status'],
            'billing_terms' => $data['billing_terms'],
            'expected_monthly_amount' => $data['expected_monthly_amount'] ?? 0,
            'next_follow_up_at' => filled($data['next_follow_up_at'] ?? null) ? Carbon::parse($data['next_follow_up_at']) : null,
            'notes' => $data['notes'] ?? null,
            'captured_by_user_id' => Auth::id(),
        ]);

        $this->leadData = $this->defaultLeadFormState();
        $this->loadWorkspace(app(RevenuePipelineService::class), app(WorkQueueService::class));

        Notification::make()
            ->title('Service lead saved')
            ->success()
            ->send();
    }

    public function convertLead(int $leadId): void
    {
        $lead = ServiceLead::query()
            ->where('business_id', $this->business?->id)
            ->findOrFail($leadId);

        if (! $this->business instanceof Business) {
            return;
        }

        $client = ServiceClient::query()->updateOrCreate(
            [
                'business_id' => $this->business->id,
                'name' => $lead->prospect_name,
            ],
            [
                'status' => ServiceClient::STATUS_ACTIVE,
                'billing_style' => $lead->billingStyleForClient(),
                'default_monthly_amount' => $lead->expected_monthly_amount ?: 0,
                'default_registration_fee' => 0,
                'default_due_day' => 5,
                'active_from' => now()->toDateString(),
                'notes' => $lead->notes,
            ]
        );

        if ((float) $lead->expected_monthly_amount > 0) {
            ServiceBillingRecord::query()->create([
                'business_id' => $this->business->id,
                'service_client_id' => $client->id,
                'client_name' => $client->name,
                'billing_type' => ServiceBillingRecord::TYPE_SUBSCRIPTION,
                'amount_due' => $lead->expected_monthly_amount,
                'paid_amount' => 0,
                'payment_status' => 'unpaid',
                'due_on' => now()->endOfMonth()->toDateString(),
                'note' => 'Auto-created from lead conversion.',
            ]);
        }

        $lead->forceFill([
            'status' => ServiceLead::STATUS_ACTIVE,
            'converted_client_id' => $client->id,
            'converted_at' => now(),
            'next_follow_up_at' => null,
        ])->save();

        $this->loadWorkspace(app(RevenuePipelineService::class), app(WorkQueueService::class));

        Notification::make()
            ->title('Lead converted to client')
            ->body('HELOS created the service client and seeded the first billing row where needed.')
            ->success()
            ->send();
    }

    public function touchLead(int $leadId, string $status): void
    {
        $lead = ServiceLead::query()
            ->where('business_id', $this->business?->id)
            ->findOrFail($leadId);

        $lead->forceFill([
            'status' => array_key_exists($status, ServiceLead::statusOptions()) ? $status : ServiceLead::STATUS_CONTACTED,
            'last_contacted_at' => now(),
            'next_follow_up_at' => $status === ServiceLead::STATUS_LOST ? null : ($lead->recommendedFollowUpAt() ? Carbon::parse($lead->recommendedFollowUpAt()) : null),
        ])->save();

        $this->loadWorkspace(app(RevenuePipelineService::class), app(WorkQueueService::class));
    }

    public function markBillingPaid(int $billingId): void
    {
        $record = ServiceBillingRecord::query()
            ->where('business_id', $this->business?->id)
            ->findOrFail($billingId);

        $record->update([
            'paid_amount' => $record->amount_due,
            'payment_status' => 'paid',
            'paid_on' => now()->toDateString(),
        ]);

        $this->loadWorkspace(app(RevenuePipelineService::class), app(WorkQueueService::class));
    }

    public function toggleLeadDesk(): void
    {
        $this->showLeadDesk = ! $this->showLeadDesk;
    }

    public function downloadLeadSample()
    {
        if (! $this->business instanceof Business) {
            return null;
        }

        $exporter = app(ServiceLeadTemplateExportService::class);
        $path = storage_path('app/sandhamali-service-leads-template.xlsx');

        $exporter->export($this->business, $path);

        return response()->download($path, 'sandhamali-service-leads-template.xlsx')->deleteFileAfterSend();
    }

    public function importLeads(ServiceLeadSpreadsheetImportService $importer): void
    {
        if (! $this->business instanceof Business) {
            Notification::make()
                ->title('No service business found')
                ->body('Sandhamali needs an accessible service business before leads can be imported.')
                ->danger()
                ->send();

            return;
        }

        $this->validate([
            'leadImportFile' => ['required', 'file', 'mimes:xlsx,xls,csv,txt'],
        ]);

        $relativePath = $this->leadImportFile->store('imports/service-leads', 'local');
        $path = storage_path('app/'.$relativePath);

        try {
            $result = $importer->import($this->business, $path, Auth::user());
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Service lead upload failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } finally {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($relativePath);
        }

        $this->leadImportFile = null;
        $this->loadWorkspace(app(RevenuePipelineService::class), app(WorkQueueService::class));

        Notification::make()
            ->title('Service leads imported')
            ->body('Created '.number_format($result['created']).', updated '.number_format($result['updated']).', skipped '.number_format($result['skipped']).'.')
            ->status($result['skipped'] > 0 ? 'warning' : 'success')
            ->send();
    }

    public function refreshWorkspace(RevenuePipelineService $revenuePipeline, WorkQueueService $workQueue): void
    {
        $this->loadWorkspace($revenuePipeline, $workQueue);
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
            'leadSummary' => $this->leadSummary,
            'clientSummary' => $this->clientSummary,
            'billingSummary' => $this->billingSummary,
            'teamExceptionSummary' => $this->teamExceptionSummary,
            'stockAppSyncStatus' => $this->stockAppSyncStatus,
            'recentServiceLeads' => $this->recentServiceLeads,
            'activeServiceClients' => $this->activeServiceClients,
            'billingDueRecords' => $this->billingDueRecords,
            'clientWorkQueue' => $this->clientWorkQueue,
            'leadData' => $this->leadData,
            'showLeadDesk' => $this->showLeadDesk,
            'isSandhamali' => static::isSandhamaliAccount(),
            'hasBusiness' => $this->business instanceof Business,
        ];
    }

    private function loadWorkspace(RevenuePipelineService $revenuePipeline, WorkQueueService $workQueue): void
    {
        $this->business = $this->serviceBusiness();

        if (! $this->business instanceof Business) {
            $this->pipeline = [];
            $this->leadSummary = [];
            $this->clientSummary = [];
            $this->billingSummary = [];
            $this->teamExceptionSummary = [];
            $this->stockAppSyncStatus = [
                'available' => null,
                'note' => null,
                'synced_clients' => 0,
                'synced_billing_records' => 0,
            ];
            $this->recentServiceLeads = collect();
            $this->activeServiceClients = collect();
            $this->billingDueRecords = collect();
            $this->clientWorkQueue = collect();

            return;
        }

        $syncResult = app(StockAppClientSyncService::class)->sync($this->business, ! app()->environment('testing'));
        $this->stockAppSyncStatus = $syncResult;

        if (($syncResult['available'] ?? false) === true) {
            $this->purgeUnsyncedServiceClients($this->business);
        }

        $service = $revenuePipeline->forCurrentMonth($this->business)['service'] ?? [];

        $this->activeServiceClients = $this->liveServiceClients($this->business)
            ->sortBy(fn (ServiceClient $client): array => [$client->status, Str::lower($client->name)])
            ->values();

        $leadOrder = array_flip([
            ServiceLead::STATUS_LEAD,
            ServiceLead::STATUS_CONTACTED,
            ServiceLead::STATUS_PROPOSAL,
            ServiceLead::STATUS_CLIENT,
            ServiceLead::STATUS_ACTIVE,
            ServiceLead::STATUS_RETAINED,
            ServiceLead::STATUS_LIFETIME,
            ServiceLead::STATUS_LOST,
        ]);

        $this->recentServiceLeads = ServiceLead::query()
            ->where('business_id', $this->business->id)
            ->get()
            ->sortBy(function (ServiceLead $lead) use ($leadOrder): string {
                $statusRank = str_pad((string) ($leadOrder[$lead->status] ?? 999), 3, '0', STR_PAD_LEFT);
                $followUp = optional($lead->next_follow_up_at)->toDateTimeString() ?? '9999-12-31 23:59:59';

                return $statusRank.'|'.$followUp.'|'.Str::of($lead->prospect_name)->lower()->squish()->toString();
            })
            ->values();

        $this->billingDueRecords = ServiceBillingRecord::query()
            ->where('business_id', $this->business->id)
            ->whereIn('payment_status', ['unpaid', 'partial', 'overdue'])
            ->orderBy('due_on')
            ->get()
            ->filter(fn (ServiceBillingRecord $record): bool => $record->balanceDue() > 0)
            ->values();

        $workQueueData = $workQueue->forBusiness($this->business);
        $this->clientWorkQueue = collect($workQueueData['tasks'] ?? [])
            ->filter(fn (array $task): bool => ($task['work_type'] ?? '') === 'service_collection')
            ->values();

        $openLeads = $this->recentServiceLeads->filter(fn (ServiceLead $lead): bool => $lead->isOpen());
        $dueLeads = $openLeads->filter(fn (ServiceLead $lead): bool => $lead->next_follow_up_at?->isPast() ?? false);
        $convertedLeads = $this->recentServiceLeads->filter(fn (ServiceLead $lead): bool => $lead->isConverted());
        $attentionClients = $this->activeServiceClients->filter(fn (ServiceClient $client): bool => in_array($client->status, [ServiceClient::STATUS_PAUSED, ServiceClient::STATUS_STOPPED], true))->count();
        $overdueBilling = $this->billingDueRecords->filter(fn (ServiceBillingRecord $record): bool => filled($record->due_on) && $record->due_on?->isPast())->count();

        $this->pipeline = [
            'service' => $service,
        ];
        $this->leadSummary = [
            'open_leads' => $openLeads->count(),
            'due_today' => $dueLeads->count(),
            'converted_clients' => $convertedLeads->count(),
        ];
        $this->clientSummary = [
            'active_clients' => $this->activeServiceClients->where('status', ServiceClient::STATUS_ACTIVE)->count(),
            'paused_clients' => $this->activeServiceClients->where('status', ServiceClient::STATUS_PAUSED)->count(),
            'retained_clients' => $this->recentServiceLeads->where('status', ServiceLead::STATUS_RETAINED)->count(),
            'lifetime_clients' => $this->recentServiceLeads->where('status', ServiceLead::STATUS_LIFETIME)->count(),
        ];
        $this->billingSummary = [
            'open_bills' => $this->billingDueRecords->count(),
            'open_balance' => (float) $this->billingDueRecords->sum(fn (ServiceBillingRecord $record): float => $record->balanceDue()),
            'due_today' => $this->billingDueRecords->filter(fn (ServiceBillingRecord $record): bool => filled($record->due_on) && $record->due_on?->isToday())->count(),
        ];
        $this->teamExceptionSummary = [
            'attention_clients' => $attentionClients,
            'overdue_billing' => $overdueBilling,
            'client_work_open' => $this->clientWorkQueue->count(),
        ];
    }

    private function liveServiceClients(Business $business): SupportCollection
    {
        return ServiceClient::query()
            ->where('business_id', $business->id)
            ->whereHas('billingRecords')
            ->get();
    }

    private function purgeUnsyncedServiceClients(Business $business): void
    {
        if (! static::isSandhamaliAccount()) {
            return;
        }

        $orphanClientIds = ServiceClient::query()
            ->where('business_id', $business->id)
            ->whereDoesntHave('billingRecords')
            ->pluck('id');

        if ($orphanClientIds->isEmpty()) {
            return;
        }

        ServiceClient::query()
            ->whereIn('id', $orphanClientIds)
            ->delete();
    }

    private function defaultLeadFormState(): array
    {
        return [
            'prospect_name' => null,
            'contact_person' => null,
            'phone' => null,
            'whatsapp_number' => null,
            'source' => ServiceLead::SOURCE_COD_CONFIRMATION,
            'status' => ServiceLead::STATUS_LEAD,
            'billing_terms' => ServiceLead::BILLING_MONTH_END,
            'expected_monthly_amount' => 0,
            'next_follow_up_at' => now()->addDay()->toDateString(),
            'notes' => null,
        ];
    }

    private function serviceBusiness(): ?Business
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $businessIds = collect($user->accessibleBusinessIds())->filter()->values();

        if ($businessIds->isEmpty() && filled($user->defaultBusinessId())) {
            $businessIds = collect([(int) $user->defaultBusinessId()]);
        }

        if ($businessIds->isEmpty()) {
            return null;
        }

        $businesses = Business::query()
            ->whereIn('id', $businessIds->all())
            ->orderBy('name')
            ->get();

        return $businesses->first(fn (Business $business): bool => $business->supportsBusinessType(Business::TYPE_SERVICE));
    }

    private static function isSandhamaliAccount(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSandhamaliAccount() ?? false);
    }
}

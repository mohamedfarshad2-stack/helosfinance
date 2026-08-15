<?php

namespace App\Filament\Pages;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Services\WorkQueueService;
use App\Filament\Resources\BankTransactionResource;
use App\Filament\Resources\BusinessResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\MaterialLedgerResource;
use App\Filament\Resources\ProductionEntryResource;
use App\Filament\Resources\UserResource;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManagerWorkQueue extends Page
{
    protected static ?string $slug = 'work-queue';

    protected static ?string $navigationGroup = 'Team Work';

    protected static ?string $navigationLabel = 'Owner Work Queue';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.manager-work-queue';

    public ?Business $business = null;

    public array $workQueue = [];

    public bool $showAdminShortcuts = false;

    public function mount(WorkQueueService $workQueue): void
    {
        $user = Auth::user();

        if (strtolower((string) $user?->email) === 'sandhamali@helos.com') {
            abort(403);
        }

        if ($user?->isStaff() && strtolower((string) $user?->email) !== 'sandhamali@helos.com') {
            $this->redirect(TodaysWork::getUrl());

            return;
        }

        $this->loadQueue($workQueue);
    }

    protected function getViewData(): array
    {
        return [
            'business' => $this->business,
            'workQueue' => $this->workQueue,
            'showAdminShortcuts' => $this->showAdminShortcuts,
            'adminShortcuts' => $this->adminShortcuts(),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return Auth::check()
            && (bool) ($user?->isOwner() ?? false)
            && strtolower((string) $user?->email) !== 'sandhamali@helos.com';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check()
            && (($user?->isOwner() ?? false) || ($user?->isStaff() ?? false))
            && strtolower((string) $user?->email) !== 'sandhamali@helos.com';
    }

    private function loadQueue(WorkQueueService $workQueue): void
    {
        $user = Auth::user();
        $this->showAdminShortcuts = (bool) ($user?->isInternalAdmin() ?? false);
        $businessId = $user?->defaultBusinessId() ?: $this->defaultBusinessId();
        $this->business = $businessId ? Business::query()->find($businessId) : null;
        $this->workQueue = $this->business ? $workQueue->forBusiness($this->business) : [];
    }

    private function defaultBusinessId(): ?int
    {
        $user = Auth::user();

        if ($user?->defaultBusinessId()) {
            return (int) $user->defaultBusinessId();
        }

        return Business::query()->orderBy('name')->value('id');
    }

    private function adminShortcuts(): array
    {
        if (! $this->showAdminShortcuts) {
            return [];
        }

        return [
            [
                'label' => 'New Client',
                'description' => 'Create a client portal and optional stock-app connection.',
                'url' => NewClientWizard::getUrl(),
                'icon' => 'heroicon-o-sparkles',
            ],
            [
                'label' => 'Businesses',
                'description' => 'Review the client setup and linked businesses.',
                'url' => BusinessResource::getUrl('index'),
                'icon' => 'heroicon-o-building-storefront',
            ],
            [
                'label' => 'Employee Accounts',
                'description' => 'Create or manage staff logins for client businesses.',
                'url' => UserResource::getUrl('index'),
                'icon' => 'heroicon-o-identification',
            ],
            [
                'label' => 'Import Bank Statement',
                'description' => 'Review imported bank statements for the client.',
                'url' => BankStatementImport::getUrl(),
                'icon' => 'heroicon-o-arrow-up-tray',
            ],
            [
                'label' => 'Bank Review',
                'description' => 'Classify bank transactions and shared treasury rows.',
                'url' => BankTransactionResource::getUrl('index'),
                'icon' => 'heroicon-o-banknotes',
            ],
            [
                'label' => 'Staff & Salary Setup',
                'description' => 'Review salaried staff for the client business.',
                'url' => EmployeeResource::getUrl('index'),
                'icon' => 'heroicon-o-user-group',
            ],
            [
                'label' => 'Production Pay',
                'description' => 'Manage production payouts and pay flows.',
                'url' => ProductionEntryResource::getUrl('index'),
                'icon' => 'heroicon-o-banknotes',
            ],
            [
                'label' => 'Expenses & Payables',
                'description' => 'Review day-to-day and settlement expenses.',
                'url' => ExpenseResource::getUrl('index'),
                'icon' => 'heroicon-o-receipt-percent',
            ],
            [
                'label' => 'Raw Material Stock',
                'description' => 'Track stock and material movements for manufacturing clients.',
                'url' => MaterialLedgerResource::getUrl('index'),
                'icon' => 'heroicon-o-rectangle-stack',
            ],
        ];
    }
}

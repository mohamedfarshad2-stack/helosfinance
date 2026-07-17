<?php

namespace App\Filament\Pages;

use App\Domains\FinancialClarity\Services\CodOrderSpreadsheetImportService;
use App\Domains\FinancialClarity\Services\CodOrderTemplateExportService;
use App\Domains\FinancialClarity\Services\CourierRateService;
use App\Domains\FinancialClarity\Services\InternalCodOrderEventService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CodOrder;
use App\Domains\Shared\Models\CodOrderSource;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Sku;
use App\Filament\Resources\CodOrderResource;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CodOrderWorkbench extends Page
{
    protected static ?string $slug = 'cod-orders-workbench';
    protected static ?string $navigationGroup = 'Sales & Work';
    protected static ?string $navigationLabel = 'COD Orders';
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.cod-order-workbench';

    public ?int $businessId = null;

    public string $search = '';

    public string $statusFilter = '';

    public function mount(): void
    {
        $this->businessId = $this->defaultBusinessId();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadCodTemplate')
                ->label('Download Excel template')
                ->icon('heroicon-o-arrow-down-tray')
                ->form([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => $this->businessOptions())
                        ->default(fn () => $this->businessId)
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->dehydrated()
                        ->required(),
                ])
                ->action(fn (array $data, CodOrderTemplateExportService $exporter) => $this->downloadTemplate((int) $data['business_id'], $exporter)),
            Actions\Action::make('uploadCodOrders')
                ->label('Upload orders')
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => $this->businessOptions())
                        ->default(fn () => $this->businessId)
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->dehydrated()
                        ->required(),
                    FileUpload::make('file')
                        ->label('COD order Excel or CSV file')
                        ->disk('local')
                        ->directory('imports/cod-orders')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/csv',
                            'text/plain',
                        ])
                        ->required(),
                ])
                ->action(function (array $data, CodOrderSpreadsheetImportService $importer): void {
                    $business = Business::query()->findOrFail($data['business_id']);
                    $relativePath = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    $path = Storage::disk('local')->path($relativePath);

                    try {
                        $result = $importer->import($business, $path);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('COD order upload failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->businessId = $business->id;

                    Notification::make()
                        ->title('COD order upload completed')
                        ->body($this->uploadSummary($business, $result))
                        ->status($result['skipped'] > 0 ? 'warning' : 'success')
                        ->send();
                }),
            Actions\Action::make('addSingleOrder')
                ->label('Add single order')
                ->icon('heroicon-o-plus')
                ->url(fn (): string => CodOrderResource::getUrl('create')),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'businessOptions' => $this->businessOptions(),
            'orders' => $this->orders(),
            'skuOptions' => $this->skuOptions(),
            'courierOptions' => $this->courierOptions(),
            'sourceOptions' => $this->sourceOptions(),
            'csrOptions' => $this->csrOptions(),
            'statusOptions' => CodOrder::statusOptions(),
        ];
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! Auth::check()) {
            return false;
        }

        if (($user?->isOwner() ?? false) || ($user?->isInternalAdmin() ?? false) || ($user?->canAccessOrderWork() ?? false)) {
            return Business::query()
                ->whereIn('id', $user?->accessibleBusinessIds() ?? [])
                ->get()
                ->contains(fn (Business $business): bool => $business->usesInternalCodOrders());
        }

        return false;
    }

    public function updatedBusinessId(): void
    {
        $this->resetPageState();
    }

    public function updateField(int $orderId, string $field, mixed $value): void
    {
        $order = $this->findOrder($orderId);

        if (! $order) {
            return;
        }

        if (! in_array($field, $this->editableFields(), true)) {
            return;
        }

        $value = $this->normalizeFieldValue($field, $value);

        match ($field) {
            'status' => $this->applyStatus($order, (string) $value),
            'tracking_number' => $this->applyTracking($order, (string) $value),
            'courier_name' => $this->applyCourier($order, (string) $value),
            default => $this->saveSimpleField($order, $field, $value),
        };
    }

    public function downloadTemplate(int $businessId, CodOrderTemplateExportService $exporter)
    {
        $business = Business::query()->findOrFail($businessId);
        $path = storage_path('app/helos-cod-order-template.xlsx');

        $exporter->export($business, $this->businessOptions(), $path);

        return response()->download($path, 'helos-cod-order-template.xlsx')->deleteFileAfterSend();
    }

    private function orders(): Collection
    {
        if (! $this->businessId) {
            return collect();
        }

        return CodOrder::query()
            ->where('business_id', $this->businessId)
            ->with(['sku', 'orderSource', 'csrEmployee'])
            ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $search = '%'.trim($this->search).'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('order_number', 'like', $search)
                        ->orWhere('customer_name', 'like', $search)
                        ->orWhere('customer_phone', 'like', $search)
                        ->orWhere('customer_alt_phone', 'like', $search)
                        ->orWhere('tracking_number', 'like', $search)
                        ->orWhere('address', 'like', $search)
                        ->orWhere('city', 'like', $search)
                        ->orWhere('district', 'like', $search);
                });
            })
            ->latest('order_date')
            ->latest('id')
            ->limit(120)
            ->get();
    }

    private function findOrder(int $orderId): ?CodOrder
    {
        if (! $this->businessId) {
            return null;
        }

        return CodOrder::query()
            ->where('business_id', $this->businessId)
            ->whereKey($orderId)
            ->first();
    }

    private function applyStatus(CodOrder $order, string $status): void
    {
        if (! array_key_exists($status, CodOrder::statusOptions())) {
            return;
        }

        if ($status === CodOrder::STATUS_DISPATCHED && blank($order->tracking_number)) {
            $order->forceFill(['status' => CodOrder::STATUS_CONFIRMED])->save();

            Notification::make()
                ->title('Add tracking number first')
                ->body('Courier cost starts when tracking is added.')
                ->warning()
                ->send();

            return;
        }

        $updates = [
            'status' => $status,
            'delivered_on' => $status === CodOrder::STATUS_DELIVERED ? today()->toDateString() : null,
            'returned_on' => $status === CodOrder::STATUS_RETURNED ? today()->toDateString() : null,
            'confirmed_at' => $status === CodOrder::STATUS_CONFIRMED && ! $order->confirmed_at ? now() : null,
            'collected_amount' => $status === CodOrder::STATUS_DELIVERED && (float) $order->collected_amount <= 0 ? $order->totalPrice() : null,
        ];

        if ($status === CodOrder::STATUS_DISPATCHED && ! $order->dispatched_at) {
            $updates['dispatched_at'] = now();
            $updates['dispatched_on'] = today()->toDateString();
        }

        $order->forceFill(array_filter($updates, fn ($value): bool => $value !== null))->save();
        $this->applyDefaultCourierIfNeeded($order->fresh());

        $this->syncOrder($order->fresh());
    }

    private function applyTracking(CodOrder $order, string $trackingNumber): void
    {
        $updates = ['tracking_number' => $trackingNumber];

        if (filled($trackingNumber) && in_array($order->status, [
            CodOrder::STATUS_CONFIRMED,
            CodOrder::STATUS_RETURNED,
            CodOrder::STATUS_RESENT,
            CodOrder::STATUS_COURIER_PENDING,
        ], true)) {
            $updates['status'] = CodOrder::STATUS_DISPATCHED;
            $updates['dispatched_on'] = today()->toDateString();
            $updates['dispatched_at'] = $order->dispatched_at ?? now();
        }

        $order->forceFill($updates)->save();
        $this->applyDefaultCourierIfNeeded($order->fresh());
        $this->syncOrder($order->fresh());
    }

    private function applyCourier(CodOrder $order, string $courierName): void
    {
        app(CourierRateService::class)->applyToOrder($order, $courierName);
        $this->syncOrder($order->fresh());
    }

    private function saveSimpleField(CodOrder $order, string $field, mixed $value): void
    {
        $order->forceFill([$field => $value])->save();
        $this->syncOrder($order->fresh());
    }

    private function applyDefaultCourierIfNeeded(CodOrder $order): void
    {
        if (! in_array($order->status, [
            CodOrder::STATUS_DISPATCHED,
            CodOrder::STATUS_DELIVERED,
            CodOrder::STATUS_RETURNED,
            CodOrder::STATUS_RESENT,
        ], true)) {
            return;
        }

        app(CourierRateService::class)->applyDefaultToOrder($order);
    }

    private function syncOrder(CodOrder $order): void
    {
        app(InternalCodOrderEventService::class)->sync($order);
    }

    private function normalizeFieldValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'sku_id', 'cod_order_source_id', 'csr_employee_id' => filled($value) ? (int) $value : null,
            'quantity', 'call_attempts' => max((int) $value, $field === 'quantity' ? 1 : 0),
            'sale_amount', 'delivery_charge', 'return_charge', 'resend_charge', 'collected_amount' => (float) $value,
            'resend_from_stock' => in_array((string) $value, ['1', 'true', 'yes', 'on'], true),
            default => trim((string) $value),
        };
    }

    private function editableFields(): array
    {
        return [
            'order_date',
            'order_number',
            'customer_name',
            'customer_phone',
            'customer_alt_phone',
            'address',
            'city',
            'district',
            'sku_id',
            'cod_order_source_id',
            'csr_employee_id',
            'size',
            'quantity',
            'sale_amount',
            'call_attempts',
            'status',
            'tracking_number',
            'courier_name',
            'collected_amount',
            'resend_from_stock',
            'confirmation_remark',
            'confirmation_reason',
            'delivery_instruction',
        ];
    }

    private function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->get()
            ->filter(fn (Business $business): bool => $business->usesInternalCodOrders())
            ->sortBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function defaultBusinessId(): ?int
    {
        $default = Auth::user()?->defaultBusinessId();
        $business = $default ? Business::query()->find($default) : null;

        if ($business?->usesInternalCodOrders()) {
            return $business->id;
        }

        return array_key_first($this->businessOptions());
    }

    private function skuOptions(): array
    {
        if (! $this->businessId) {
            return [];
        }

        return Sku::query()
            ->where('business_id', $this->businessId)
            ->where('active', true)
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }

    private function courierOptions(): array
    {
        return app(CourierRateService::class)->optionsForBusiness($this->businessId);
    }

    private function sourceOptions(): array
    {
        if (! $this->businessId) {
            return [];
        }

        return CodOrderSource::query()
            ->where('business_id', $this->businessId)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function csrOptions(): array
    {
        if (! $this->businessId) {
            return [];
        }

        return Employee::query()
            ->where('business_id', $this->businessId)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  array{created:int, updated:int, skipped:int, skipped_reasons?:list<string>}  $result
     */
    private function uploadSummary(Business $business, array $result): string
    {
        $body = "Business: {$business->name}. Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}.";
        $reasons = collect($result['skipped_reasons'] ?? [])->take(5)->implode(' ');

        return $reasons === '' ? $body : $body.' '.$reasons;
    }

    private function resetPageState(): void
    {
        $this->search = '';
        $this->statusFilter = '';
    }
}

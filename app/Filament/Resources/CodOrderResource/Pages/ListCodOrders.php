<?php

namespace App\Filament\Resources\CodOrderResource\Pages;

use App\Domains\FinancialClarity\Services\CodOrderSpreadsheetImportService;
use App\Domains\FinancialClarity\Services\CodOrderTemplateExportService;
use App\Domains\Shared\Models\Business;
use App\Filament\Resources\CodOrderResource;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListCodOrders extends ListRecords
{
    protected static string $resource = CodOrderResource::class;

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
                        ->default(fn () => Auth::user()?->defaultBusinessId())
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
                        ->default(fn () => Auth::user()?->defaultBusinessId())
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

                    Notification::make()
                        ->title('COD order upload completed')
                        ->body($this->uploadSummary($business, $result))
                        ->status($result['skipped'] > 0 ? 'warning' : 'success')
                        ->send();
                }),
            Actions\CreateAction::make()->label('Add single order'),
        ];
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    public function downloadTemplate(int $businessId, CodOrderTemplateExportService $exporter)
    {
        $business = Business::query()->findOrFail($businessId);
        $path = storage_path('app/helos-cod-order-template.xlsx');

        $exporter->export($business, $this->businessOptions(), $path);

        return response()->download($path, 'helos-cod-order-template.xlsx')->deleteFileAfterSend();
    }

    /**
     * @return array<int, string>
     */
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

    /**
     * @param  array{created:int, updated:int, skipped:int, skipped_reasons?:list<string>}  $result
     */
    private function uploadSummary(Business $business, array $result): string
    {
        $body = "Business: {$business->name}. Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}.";
        $reasons = collect($result['skipped_reasons'] ?? [])->take(5)->implode(' ');

        return $reasons === '' ? $body : $body.' '.$reasons;
    }
}

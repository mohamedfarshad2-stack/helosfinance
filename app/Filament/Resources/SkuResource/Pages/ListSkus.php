<?php

namespace App\Filament\Resources\SkuResource\Pages;

use App\Domains\Manufacturing\Services\SkuSpreadsheetImportService;
use App\Domains\Manufacturing\Services\SkuUploadTemplateExportService;
use App\Domains\Shared\Models\Business;
use App\Filament\Resources\SkuResource;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListSkus extends ListRecords
{
    protected static string $resource = SkuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadSample')
                ->label('Download sample')
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
                ->action(fn (array $data, SkuUploadTemplateExportService $exporter) => $this->downloadSample((int) $data['business_id'], $exporter)),
            Actions\Action::make('uploadSkus')
                ->label('Upload Excel')
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
                        ->label('SKU Excel or CSV file')
                        ->disk('local')
                        ->directory('imports/skus')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/csv',
                            'text/plain',
                        ])
                        ->required(),
                ])
                ->action(function (array $data, SkuSpreadsheetImportService $importer): void {
                    $business = Business::query()->findOrFail($data['business_id']);
                    $relativePath = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    $path = Storage::disk('local')->path($relativePath);

                    try {
                        $result = $importer->import($business, $path);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('SKU upload failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('SKU upload completed')
                        ->body($this->uploadSummary($business, $result))
                        ->status($result['skipped'] > 0 ? 'warning' : 'success')
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }

    public function downloadSample(int $businessId, SkuUploadTemplateExportService $exporter)
    {
        $business = Business::query()->findOrFail($businessId);
        $path = storage_path('app/sku-upload-sample.xlsx');

        $exporter->export($business, $this->businessOptions(), $path);

        return response()->download($path, 'helos-sku-upload-sample.xlsx')->deleteFileAfterSend();
    }

    /**
     * @return array<int, string>
     */
    private function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
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
}

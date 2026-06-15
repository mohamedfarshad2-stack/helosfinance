<?php

namespace App\Filament\Resources\SkuResource\Pages;

use App\Domains\Manufacturing\Services\SkuSpreadsheetImportService;
use App\Domains\Shared\Models\Business;
use App\Filament\Resources\SkuResource;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
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
                ->action(fn () => $this->downloadSample()),
            Actions\Action::make('uploadSkus')
                ->label('Upload Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(Business::query()->pluck('name', 'id'))
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
                        ->body("Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}.")
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }

    public function downloadSample()
    {
        $path = storage_path('app/sku-upload-sample.xlsx');
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['code', 'name', 'expected_sale_price', 'active', 'material_cost', 'packaging_cost', 'labor_rate', 'finishing_cost', 'note']));
        $writer->addRow(Row::fromValues(['SLP-001', 'Black Slipper Size 8', 1200, 'yes', '', '', '', '', 'Costs should normally come from SKU Recipe / BOM.']));
        $writer->addRow(Row::fromValues(['SLP-002', 'Brown Slipper Size 9', 1350, 'yes', '', '', '', '', 'Leave fallback costs blank if recipe will be added.']));
        $writer->close();

        return response()->download($path, 'helos-sku-upload-sample.xlsx')->deleteFileAfterSend();
    }
}

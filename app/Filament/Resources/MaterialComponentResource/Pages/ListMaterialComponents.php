<?php

namespace App\Filament\Resources\MaterialComponentResource\Pages;

use App\Domains\Manufacturing\Services\MaterialComponentSpreadsheetImportService;
use App\Domains\Shared\Models\Business;
use App\Filament\Resources\MaterialComponentResource;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer;
use Throwable;

class ListMaterialComponents extends ListRecords
{
    protected static string $resource = MaterialComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadSample')
                ->label('Download sample')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->downloadSample()),
            Actions\Action::make('uploadComponents')
                ->label('Upload components')
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => $this->businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->required(),
                    FileUpload::make('file')
                        ->label('Component CSV or Excel file')
                        ->disk('local')
                        ->directory('imports/material-components')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/csv',
                            'text/plain',
                        ])
                        ->required(),
                ])
                ->action(function (array $data, MaterialComponentSpreadsheetImportService $importer): void {
                    $business = Business::query()->findOrFail($data['business_id']);
                    $relativePath = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    $path = Storage::disk('local')->path($relativePath);

                    try {
                        $result = $importer->import($business, $path);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Component upload failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Component upload completed')
                        ->body("Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}.")
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }

    public function downloadSample()
    {
        $path = storage_path('app/material-component-upload-sample.csv');
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([
            'name',
            'purchase_unit',
            'purchase_unit_cost',
            'units_per_purchase_unit',
            'waste_percent',
            'consumption_unit',
            'active',
            'note',
        ]));
        $writer->addRow(Row::fromValues(['DSI sheet', 'sheet', 1200, 12, 5, 'piece', 'yes', 'One sheet cuts 12 pieces before waste.']));
        $writer->addRow(Row::fromValues(['Glue', 'bottle', 400, 10, 0, 'use', 'yes', 'One bottle gives about 10 uses.']));
        $writer->addRow(Row::fromValues(['Thread', 'roll', 900, 30, 0, 'use', 'yes', 'One roll gives about 30 uses.']));
        $writer->close();

        return response()->download($path, 'helos-material-components-sample.csv')->deleteFileAfterSend();
    }

    private function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn ($query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}

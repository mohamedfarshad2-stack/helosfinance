<?php

namespace App\Filament\Resources\SkuRecipeResource\Pages;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use App\Domains\Manufacturing\Services\SkuRecipeSpreadsheetImportService;
use App\Filament\Resources\SkuRecipeResource;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer;
use Throwable;

class ListSkuRecipeItems extends ListRecords
{
    protected static string $resource = SkuRecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadSample')
                ->label('Download sample')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->downloadSample()),
            Actions\Action::make('uploadRecipeSheet')
                ->label('Upload recipe sheet')
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => $this->businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->live()
                        ->required(),
                    FileUpload::make('file')
                        ->label('Recipe CSV or Excel file')
                        ->disk('local')
                        ->directory('imports/sku-recipes')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/csv',
                            'text/plain',
                        ])
                        ->required(),
                ])
                ->action(function (array $data, SkuRecipeSpreadsheetImportService $importer): void {
                    $business = Business::query()->findOrFail($data['business_id']);
                    $relativePath = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    $path = Storage::disk('local')->path($relativePath);

                    try {
                        $result = $importer->import($business, $path);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Recipe upload failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Recipe upload completed')
                        ->body("Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}.")
                        ->success()
                        ->send();
                }),
            Actions\Action::make('addRecipeBundle')
                ->label('Add recipe bundle')
                ->icon('heroicon-o-rectangle-stack')
                ->form([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => $this->businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->live()
                        ->required(),
                    Select::make('sku_id')
                        ->label('Finished product / SKU')
                        ->options(fn (Get $get) => $this->skuOptions((int) ($get('business_id') ?? 0)))
                        ->searchable()
                        ->required(),
                    Repeater::make('lines')
                        ->label('Materials and labor lines')
                        ->helperText('Add every raw material and labor line that belongs to this SKU. One row = one component or one labor step.')
                        ->defaultItems(2)
                        ->schema([
                            Select::make('line_type')
                                ->label('Line type')
                                ->options([
                                    SkuRecipeItem::TYPE_RAW_MATERIAL => 'Raw material',
                                    SkuRecipeItem::TYPE_LABOR => 'Manpower / labor',
                                ])
                                ->default(SkuRecipeItem::TYPE_RAW_MATERIAL)
                                ->required(),
                            TextInput::make('component_name')
                                ->label('Component / labor step')
                                ->required(),
                            TextInput::make('quantity_per_unit')
                                ->label('Qty per unit')
                                ->numeric()
                                ->default(1)
                                ->required(),
                            TextInput::make('unit_cost')
                                ->label('Unit cost')
                                ->numeric()
                                ->prefix('LKR')
                                ->required(),
                        ])
                        ->columns(2)
                        ->reorderable(),
                ])
                ->action(function (array $data): void {
                    $business = Business::query()->findOrFail($data['business_id']);
                    $sku = Sku::query()->where('business_id', $business->id)->findOrFail($data['sku_id']);
                    $lines = collect($data['lines'] ?? [])->filter(fn (array $line): bool => filled($line['component_name'] ?? null));

                    DB::transaction(function () use ($business, $sku, $lines): void {
                        foreach ($lines as $line) {
                            SkuRecipeItem::query()->create([
                                'business_id' => $business->id,
                                'sku_id' => $sku->id,
                                'line_type' => $line['line_type'] ?? SkuRecipeItem::TYPE_RAW_MATERIAL,
                                'component_name' => trim((string) ($line['component_name'] ?? '')),
                                'quantity_per_unit' => (float) ($line['quantity_per_unit'] ?? 0),
                                'unit_cost' => (float) ($line['unit_cost'] ?? 0),
                                'active' => true,
                            ]);
                        }
                    });

                    Notification::make()
                        ->title('Recipe bundle saved')
                        ->body('All materials and labor lines were added in one go.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function downloadSample()
    {
        $path = storage_path('app/sku-recipe-upload-sample.csv');
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['sku_code', 'line_type', 'component_name', 'quantity_per_unit', 'unit_cost', 'active', 'note']));
        $writer->addRow(Row::fromValues(['SLP-001', 'raw_material', 'Rubber sheet', 1, 300, 'yes', '']));
        $writer->addRow(Row::fromValues(['SLP-001', 'raw_material', 'Glue', 0.2, 40, 'yes', '']));
        $writer->addRow(Row::fromValues(['SLP-001', 'raw_material', 'Thread', 0.15, 30, 'yes', '']));
        $writer->addRow(Row::fromValues(['SLP-001', 'raw_material', 'Label', 1, 12, 'yes', '']));
        $writer->addRow(Row::fromValues(['SLP-001', 'labor', 'Cutting labor', 1, 60, 'yes', '']));
        $writer->addRow(Row::fromValues(['SLP-001', 'labor', 'Stitching labor', 2, 100, 'yes', '']));
        $writer->addRow(Row::fromValues(['SLP-001', 'labor', 'Finishing labor', 1, 50, 'yes', '']));
        $writer->close();

        return response()->download($path, 'helos-sku-recipe-sample.csv')->deleteFileAfterSend();
    }

    private function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! $user?->seesAllBusinesses(), fn ($query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function skuOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return Sku::query()
            ->where('business_id', $businessId)
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }
}

<?php

namespace App\Filament\Resources\SkuRecipeResource\Pages;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use App\Domains\Shared\Models\ProductionWorkStep;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use App\Domains\Manufacturing\Services\SkuRecipeSpreadsheetImportService;
use App\Domains\Manufacturing\Services\SkuRecipeTemplateExportService;
use App\Filament\Resources\SkuRecipeResource;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListSkuRecipeItems extends ListRecords
{
    protected static string $resource = SkuRecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadSample')
                ->label('Download Excel template')
                ->icon('heroicon-o-arrow-down-tray')
                ->form([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => $this->businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->required(),
                ])
                ->action(fn (array $data, SkuRecipeTemplateExportService $exporter) => $this->downloadSample((int) $data['business_id'], $exporter)),
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

                    $skippedReasons = collect($result['skipped_reasons'] ?? [])->take(5)->implode(' ');
                    $body = "Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}.";

                    if ($skippedReasons !== '') {
                        $body .= ' '.$skippedReasons;
                    }

                    Notification::make()
                        ->title('Recipe upload completed')
                        ->body($body)
                        ->status($result['skipped'] > 0 ? 'warning' : 'success')
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
                                    SkuRecipeItem::TYPE_LABOR => 'Manpower / labour',
                                ])
                                ->default(SkuRecipeItem::TYPE_RAW_MATERIAL)
                                ->live()
                                ->required(),
                            Select::make('material_component_id')
                                ->label('Material component')
                                ->options(fn (Get $get) => $this->materialComponentOptions((int) ($get('../../business_id') ?? 0)))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->visible(fn (Get $get): bool => ($get('line_type') ?? SkuRecipeItem::TYPE_RAW_MATERIAL) === SkuRecipeItem::TYPE_RAW_MATERIAL)
                                ->required(fn (Get $get): bool => ($get('line_type') ?? SkuRecipeItem::TYPE_RAW_MATERIAL) === SkuRecipeItem::TYPE_RAW_MATERIAL)
                                ->createOptionForm($this->materialComponentForm())
                                ->createOptionUsing(fn (array $data, Get $get): int => $this->createMaterialComponent((int) ($get('../../business_id') ?? 0), $data)->id)
                                ->afterStateUpdated(function (mixed $state, Set $set): void {
                                    $component = MaterialComponent::query()->find((int) $state);

                                    if (! $component) {
                                        return;
                                    }

                                    $set('component_name', $component->name);
                                    $set('quantity_per_unit', 1);
                                    $set('unit_cost', $component->costPerConsumptionUnit());
                                }),
                            Select::make('component_name')
                                ->label('Work step')
                                ->options(fn (Get $get) => $this->workStepSelectOptions((int) ($get('../../business_id') ?? 0)))
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => ($get('line_type') ?? SkuRecipeItem::TYPE_RAW_MATERIAL) === SkuRecipeItem::TYPE_LABOR)
                                ->createOptionForm($this->workStepForm())
                                ->createOptionUsing(fn (array $data, Get $get): string => $this->createWorkStep((int) ($get('../../business_id') ?? 0), $data)->name)
                                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                    $step = $this->workStepByName((int) ($get('../../business_id') ?? 0), (string) $state);

                                    if (! $step) {
                                        return;
                                    }

                                    $set('unit_cost', (float) $step->unit_cost);
                                })
                                ->required(fn (Get $get): bool => ($get('line_type') ?? SkuRecipeItem::TYPE_RAW_MATERIAL) === SkuRecipeItem::TYPE_LABOR),
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
                            $workStep = null;

                            if (($line['line_type'] ?? SkuRecipeItem::TYPE_RAW_MATERIAL) === SkuRecipeItem::TYPE_LABOR) {
                                $workStep = $this->workStep($business, trim((string) ($line['component_name'] ?? '')));
                            }

                            SkuRecipeItem::query()->create([
                                'business_id' => $business->id,
                                'sku_id' => $sku->id,
                                'line_type' => $line['line_type'] ?? SkuRecipeItem::TYPE_RAW_MATERIAL,
                                'material_component_id' => $line['material_component_id'] ?? null,
                                'production_work_step_id' => $workStep?->id,
                                'component_name' => $workStep?->name ?? trim((string) ($line['component_name'] ?? '')),
                                'quantity_per_unit' => (float) ($line['quantity_per_unit'] ?? 0),
                                'unit_cost' => (float) ($line['unit_cost'] ?? $workStep?->unit_cost ?? 0),
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

    public function downloadSample(int $businessId, SkuRecipeTemplateExportService $exporter)
    {
        $business = Business::query()->findOrFail($businessId);
        $path = storage_path('app/helos-sku-recipe-template.xlsx');

        $exporter->export($business, $path);

        return response()->download($path, 'helos-sku-recipe-template.xlsx')->deleteFileAfterSend();
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

    private function materialComponentOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return MaterialComponent::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (MaterialComponent $component): array => [
                $component->id => $component->name.' - LKR '.number_format($component->costPerConsumptionUnit(), 2).' / '.$component->consumption_unit,
            ])
            ->all();
    }

    private function workStepSelectOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return ProductionWorkStep::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    private function materialComponentForm(): array
    {
        return [
            TextInput::make('name')->label('Component name')->placeholder('DSI sheet')->required()->maxLength(255),
            TextInput::make('purchase_unit')->label('Buying unit')->default('sheet')->required()->maxLength(50),
            TextInput::make('consumption_unit')->label('Used as')->default('piece')->required()->maxLength(50),
            TextInput::make('units_per_purchase_unit')->label('Usable pieces per buying unit')->numeric()->default(1)->required(),
            TextInput::make('waste_percent')->label('Expected waste %')->numeric()->default(0)->required(),
            TextInput::make('latest_purchase_unit_cost')->label('Latest buying unit cost')->numeric()->default(0)->prefix('LKR')->required(),
        ];
    }

    private function createMaterialComponent(int $businessId, array $data): MaterialComponent
    {
        $businessId = $businessId > 0 ? $businessId : (int) Auth::user()?->defaultBusinessId();

        return MaterialComponent::query()->updateOrCreate(
            [
                'business_id' => $businessId,
                'name' => trim((string) $data['name']),
            ],
            [
                'purchase_unit' => trim((string) ($data['purchase_unit'] ?? 'unit')) ?: 'unit',
                'consumption_unit' => trim((string) ($data['consumption_unit'] ?? 'piece')) ?: 'piece',
                'units_per_purchase_unit' => (float) ($data['units_per_purchase_unit'] ?? 1),
                'waste_percent' => (float) ($data['waste_percent'] ?? 0),
                'latest_purchase_unit_cost' => (float) ($data['latest_purchase_unit_cost'] ?? 0),
                'active' => true,
            ],
        );
    }

    private function workStepForm(): array
    {
        return [
            TextInput::make('name')->label('Work name')->placeholder('Bottom labour')->required()->maxLength(255),
            TextInput::make('unit_cost')->label('Rate per unit')->numeric()->default(0)->prefix('LKR')->required(),
        ];
    }

    private function createWorkStep(int $businessId, array $data): ProductionWorkStep
    {
        $businessId = $businessId > 0 ? $businessId : (int) Auth::user()?->defaultBusinessId();

        return ProductionWorkStep::query()->updateOrCreate(
            [
                'business_id' => $businessId,
                'name' => trim((string) $data['name']),
            ],
            [
                'unit_cost' => (float) ($data['unit_cost'] ?? 0),
                'active' => true,
            ],
        );
    }

    private function workStepByName(int $businessId, string $name): ?ProductionWorkStep
    {
        if ($businessId <= 0 || blank($name)) {
            return null;
        }

        return ProductionWorkStep::query()
            ->where('business_id', $businessId)
            ->where('name', $name)
            ->first();
    }

    private function workStep(Business $business, string $name): ?ProductionWorkStep
    {
        if (blank($name)) {
            return null;
        }

        return ProductionWorkStep::query()
            ->where('business_id', $business->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();
    }
}

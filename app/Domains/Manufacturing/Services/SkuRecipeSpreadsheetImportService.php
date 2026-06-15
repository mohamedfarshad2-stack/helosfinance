<?php

namespace App\Domains\Manufacturing\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use App\Domains\Shared\Models\ProductionWorkStep;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\Common\Creator\ReaderFactory;

class SkuRecipeSpreadsheetImportService
{
    /**
     * @return array{created:int, updated:int, skipped:int}
     */
    public function import(Business $business, string $path): array
    {
        $reader = ReaderFactory::createFromFile($path);
        $reader->open($path);

        $headers = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                $values = $row->toArray();

                if ($rowNumber === 1) {
                    $headers = $this->normalizeHeaders($values);
                    $this->validateHeaders($headers);
                    continue;
                }

                $data = $this->combineRow($headers, $values);

                if (! $this->hasMinimumData($data)) {
                    $skipped++;
                    continue;
                }

                $skuCode = trim((string) ($data['sku_code'] ?? ''));
                $sku = Sku::query()
                    ->where('business_id', $business->id)
                    ->where('code', $skuCode)
                    ->first();

                if (! $sku instanceof Sku) {
                    $skipped++;
                    continue;
                }

                $lineType = $this->lineType($data['line_type'] ?? null);
                $componentName = trim((string) ($data['component_name'] ?? ''));
                $component = $lineType === SkuRecipeItem::TYPE_RAW_MATERIAL
                    ? $this->materialComponent($business, $componentName, $data)
                    : null;
                $workStep = $lineType === SkuRecipeItem::TYPE_LABOR
                    ? $this->workStep($business, $componentName)
                    : null;

                if ($lineType === SkuRecipeItem::TYPE_LABOR && ! $workStep instanceof ProductionWorkStep) {
                    $skipped++;
                    continue;
                }

                $attributes = [
                    'business_id' => $business->id,
                    'sku_id' => $sku->id,
                    'line_type' => $lineType,
                    'component_name' => $component?->name ?? $workStep?->name ?? $componentName,
                ];

                $unitCost = (float) ($data['unit_cost'] ?? 0);
                if ($unitCost <= 0 && $component instanceof MaterialComponent) {
                    $unitCost = $component->costPerConsumptionUnit();
                }
                if ($unitCost <= 0 && $workStep instanceof ProductionWorkStep) {
                    $unitCost = (float) $workStep->unit_cost;
                }

                $values = [
                    'material_component_id' => $component?->id,
                    'production_work_step_id' => $workStep?->id,
                    'quantity_per_unit' => (float) ($data['quantity_per_unit'] ?? 0),
                    'unit_cost' => $unitCost,
                    'active' => $this->bool($data['active'] ?? true),
                    'note' => trim((string) ($data['note'] ?? '')) ?: null,
                ];

                $recipe = SkuRecipeItem::query()->updateOrCreate($attributes, $values);

                $recipe->wasRecentlyCreated ? $created++ : $updated++;
            }

            break;
        }

        $reader->close();

        return compact('created', 'updated', 'skipped');
    }

    private function normalizeHeaders(array $values): array
    {
        return array_map(fn ($value) => Str::of((string) $value)->lower()->replace([' ', '-'], '_')->trim()->toString(), $values);
    }

    private function validateHeaders(array $headers): void
    {
        $required = ['sku_code', 'component_name', 'quantity_per_unit', 'unit_cost'];
        $missing = array_diff($required, $headers);

        if ($missing !== []) {
            throw new InvalidArgumentException('Missing columns: '.implode(', ', $missing));
        }
    }

    private function combineRow(array $headers, array $values): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $row[$header] = $values[$index] ?? null;
        }

        return $row;
    }

    private function hasMinimumData(array $data): bool
    {
        return filled($data['sku_code'] ?? null) && filled($data['component_name'] ?? null);
    }

    private function lineType(mixed $value): string
    {
        $normalized = Str::of((string) $value)->lower()->replace([' ', '-', '/'], '_')->trim()->toString();

        if (in_array($normalized, ['labor', 'labour', 'manpower', 'worker', 'worker_step', 'labor_step', 'labour_step'], true)) {
            return SkuRecipeItem::TYPE_LABOR;
        }

        return SkuRecipeItem::TYPE_RAW_MATERIAL;
    }

    private function bool(mixed $value): bool
    {
        return in_array(Str::lower((string) $value), ['1', 'true', 'yes', 'active'], true);
    }

    private function materialComponent(Business $business, string $name, array $data): MaterialComponent
    {
        $purchaseUnitCost = (float) ($data['purchase_unit_cost'] ?? $data['latest_purchase_unit_cost'] ?? $data['unit_cost'] ?? 0);

        return MaterialComponent::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'name' => $name,
            ],
            [
                'purchase_unit' => trim((string) ($data['purchase_unit'] ?? 'unit')) ?: 'unit',
                'consumption_unit' => trim((string) ($data['consumption_unit'] ?? 'piece')) ?: 'piece',
                'units_per_purchase_unit' => (float) ($data['units_per_purchase_unit'] ?? 1),
                'waste_percent' => (float) ($data['waste_percent'] ?? 0),
                'latest_purchase_unit_cost' => $purchaseUnitCost,
                'active' => true,
            ],
        );
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

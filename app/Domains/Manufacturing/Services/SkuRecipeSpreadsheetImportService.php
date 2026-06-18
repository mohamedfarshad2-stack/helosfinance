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
     * @return array{created:int, updated:int, skipped:int, skipped_reasons:list<string>}
     */
    public function import(Business $business, string $path): array
    {
        $reader = ReaderFactory::createFromFile($path);
        $reader->open($path);

        $headers = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $skippedReasons = [];

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
                    $this->addSkippedReason($skippedReasons, $rowNumber, 'SKU code or component/work step name is missing.');
                    continue;
                }

                $skuCode = trim((string) ($data['sku_code'] ?? $data['code'] ?? ''));
                $sku = Sku::query()
                    ->where('business_id', $business->id)
                    ->where('code', $skuCode)
                    ->first();

                if (! $sku instanceof Sku) {
                    $sku = $this->createSkuFromRow($business, $skuCode, $data);

                    if (! $sku instanceof Sku) {
                        $skipped++;
                        $this->addSkippedReason($skippedReasons, $rowNumber, "SKU '{$skuCode}' was not found for this business and product name is missing.");
                        continue;
                    }
                }

                $lineType = $this->lineType($data['line_type'] ?? null);
                $componentName = trim((string) ($data['component_name'] ?? ''));
                $partName = trim((string) ($data['part_name'] ?? $data['part'] ?? 'General')) ?: 'General';
                $unitCost = (float) ($data['unit_cost'] ?? 0);
                $component = $lineType === SkuRecipeItem::TYPE_RAW_MATERIAL
                    ? $this->materialComponent($business, $componentName, $data)
                    : null;
                $workStep = $lineType === SkuRecipeItem::TYPE_LABOR
                    ? $this->workStep($business, $componentName, $unitCost)
                    : null;

                if ($lineType === SkuRecipeItem::TYPE_LABOR && ! $workStep instanceof ProductionWorkStep) {
                    $skipped++;
                    $this->addSkippedReason($skippedReasons, $rowNumber, "Work step '{$componentName}' could not be created.");
                    continue;
                }

                $attributes = [
                    'business_id' => $business->id,
                    'sku_id' => $sku->id,
                    'line_type' => $lineType,
                    'part_name' => $partName,
                    'component_name' => $component?->name ?? $workStep?->name ?? $componentName,
                ];

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

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'skipped_reasons' => $skippedReasons,
        ];
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
        return filled($data['sku_code'] ?? $data['code'] ?? null) && filled($data['component_name'] ?? null);
    }

    private function createSkuFromRow(Business $business, string $skuCode, array $data): ?Sku
    {
        $productName = trim((string) ($data['product_name'] ?? $data['name'] ?? ''));

        if ($skuCode === '' || $productName === '') {
            return null;
        }

        return Sku::query()->create([
            'business_id' => $business->id,
            'code' => $skuCode,
            'name' => $productName,
            'expected_sale_price' => (float) ($data['expected_sale_price'] ?? $data['selling_price'] ?? 0),
            'material_cost' => 0,
            'packaging_cost' => 0,
            'labor_rate' => 0,
            'finishing_cost' => 0,
            'active' => true,
        ]);
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

    /**
     * @param  list<string>  $reasons
     */
    private function addSkippedReason(array &$reasons, int $rowNumber, string $reason): void
    {
        if (count($reasons) >= 8) {
            return;
        }

        $reasons[] = "Row {$rowNumber}: {$reason}";
    }

    private function workStep(Business $business, string $name, float $unitCost): ?ProductionWorkStep
    {
        if (blank($name)) {
            return null;
        }

        $existing = ProductionWorkStep::query()
            ->where('business_id', $business->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing instanceof ProductionWorkStep) {
            return $existing;
        }

        return ProductionWorkStep::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'unit_cost' => max($unitCost, 0),
            'active' => true,
        ]);
    }
}

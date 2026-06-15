<?php

namespace App\Domains\Manufacturing\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\Common\Creator\ReaderFactory;

class MaterialComponentSpreadsheetImportService
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

                if (blank($data['name'] ?? null)) {
                    $skipped++;
                    continue;
                }

                $component = MaterialComponent::query()->updateOrCreate(
                    [
                        'business_id' => $business->id,
                        'name' => trim((string) $data['name']),
                    ],
                    [
                        'purchase_unit' => trim((string) ($data['purchase_unit'] ?? 'unit')) ?: 'unit',
                        'consumption_unit' => trim((string) ($data['consumption_unit'] ?? 'piece')) ?: 'piece',
                        'units_per_purchase_unit' => (float) ($data['units_per_purchase_unit'] ?? 1),
                        'waste_percent' => (float) ($data['waste_percent'] ?? 0),
                        'latest_purchase_unit_cost' => (float) ($data['latest_purchase_unit_cost'] ?? $data['purchase_unit_cost'] ?? 0),
                        'active' => $this->bool($data['active'] ?? true),
                        'note' => trim((string) ($data['note'] ?? '')) ?: null,
                    ],
                );

                $component->wasRecentlyCreated ? $created++ : $updated++;
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
        if (! in_array('name', $headers, true)) {
            throw new InvalidArgumentException('Missing columns: name');
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

    private function bool(mixed $value): bool
    {
        return in_array(Str::lower((string) $value), ['1', 'true', 'yes', 'active'], true);
    }
}

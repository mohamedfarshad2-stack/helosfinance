<?php

namespace App\Domains\Manufacturing\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Sku;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\Common\Creator\ReaderFactory;

class SkuSpreadsheetImportService
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

                $sku = Sku::query()->updateOrCreate(
                    [
                        'business_id' => $business->id,
                        'code' => trim((string) $data['code']),
                    ],
                    [
                        'name' => trim((string) $data['name']),
                        'material_cost' => $this->money($data['material_cost'] ?? 0),
                        'packaging_cost' => $this->money($data['packaging_cost'] ?? 0),
                        'labor_rate' => $this->money($data['labor_rate'] ?? 0),
                        'finishing_cost' => $this->money($data['finishing_cost'] ?? 0),
                        'expected_sale_price' => $this->money($data['expected_sale_price'] ?? 0),
                        'active' => $this->bool($data['active'] ?? true),
                    ]
                );

                $sku->wasRecentlyCreated ? $created++ : $updated++;
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
        $missing = array_diff(['code', 'name', 'material_cost', 'packaging_cost', 'labor_rate', 'finishing_cost', 'expected_sale_price'], $headers);

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
        return filled($data['code'] ?? null) && filled($data['name'] ?? null);
    }

    private function money(mixed $value): float
    {
        return (float) str_replace([',', 'LKR', 'Rs', 'rs'], '', (string) $value);
    }

    private function bool(mixed $value): bool
    {
        return in_array(Str::lower((string) $value), ['1', 'true', 'yes', 'active'], true);
    }
}

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
                    $this->addSkippedReason($skippedReasons, $rowNumber, 'SKU code or product name is missing.');
                    continue;
                }

                if (! $this->matchesSelectedBusiness($business, $data)) {
                    $skipped++;
                    $rowBusiness = trim((string) ($data['business_name'] ?? $data['business'] ?? $data['business_id'] ?? ''));
                    $this->addSkippedReason($skippedReasons, $rowNumber, "Business '{$rowBusiness}' does not match selected business '{$business->name}'.");
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
        $missing = array_diff(['code', 'name'], $headers);

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

    private function matchesSelectedBusiness(Business $business, array $data): bool
    {
        $businessId = trim((string) ($data['business_id'] ?? ''));

        if ($businessId !== '' && (int) $businessId !== $business->id) {
            return false;
        }

        $businessName = trim((string) ($data['business_name'] ?? $data['business'] ?? ''));

        if ($businessName === '') {
            return true;
        }

        return Str::of($businessName)->lower()->squish()->toString() === Str::of($business->name)->lower()->squish()->toString();
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

    private function money(mixed $value): float
    {
        return (float) str_replace([',', 'LKR', 'Rs', 'rs'], '', (string) $value);
    }

    private function bool(mixed $value): bool
    {
        return in_array(Str::lower((string) $value), ['1', 'true', 'yes', 'active'], true);
    }
}

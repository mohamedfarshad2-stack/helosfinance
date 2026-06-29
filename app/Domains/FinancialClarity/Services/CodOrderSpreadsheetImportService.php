<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CodOrder;
use App\Domains\Shared\Models\CodOrderSource;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Sku;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\Common\Creator\ReaderFactory;

class CodOrderSpreadsheetImportService
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
                    $this->addSkippedReason($skippedReasons, $rowNumber, 'Customer name or phone is missing.');
                    continue;
                }

                if (! $this->matchesSelectedBusiness($business, $data)) {
                    $skipped++;
                    $rowBusiness = trim((string) ($data['business_name'] ?? $data['business'] ?? $data['business_id'] ?? ''));
                    $this->addSkippedReason($skippedReasons, $rowNumber, "Business '{$rowBusiness}' does not match selected business '{$business->name}'.");
                    continue;
                }

                $sku = $this->sku($business, $data['sku_code'] ?? $data['sku'] ?? null);
                $source = $this->source($business, $data['order_source'] ?? $data['source'] ?? null);
                $csr = $this->employee($business, $data['csr_employee'] ?? $data['csr'] ?? $data['responsible_employee'] ?? null);

                if (filled($data['sku_code'] ?? $data['sku'] ?? null) && ! $sku) {
                    $skipped++;
                    $this->addSkippedReason($skippedReasons, $rowNumber, "SKU '".trim((string) ($data['sku_code'] ?? $data['sku']))."' was not found.");
                    continue;
                }

                $attributes = [
                    'business_id' => $business->id,
                    'cod_order_source_id' => $source?->id,
                    'csr_employee_id' => $csr?->id,
                    'customer_name' => trim((string) $data['customer_name']),
                    'customer_phone' => trim((string) ($data['customer_phone'] ?? $data['phone'] ?? '')),
                    'customer_alt_phone' => trim((string) ($data['customer_alt_phone'] ?? $data['alternate_phone'] ?? $data['alt_phone'] ?? '')),
                    'address' => trim((string) ($data['address'] ?? '')),
                    'city' => trim((string) ($data['city'] ?? '')),
                    'district' => trim((string) ($data['district'] ?? '')),
                    'sku_id' => $sku?->id,
                    'size' => trim((string) ($data['size'] ?? '')),
                    'quantity' => max((int) ($data['quantity'] ?? 1), 1),
                    'sale_amount' => $this->money($data['sale_amount'] ?? $data['marked_price'] ?? 0),
                    'status' => CodOrder::STATUS_NEW,
                    'call_attempts' => max((int) ($data['call_attempts'] ?? 0), 0),
                    'confirmation_remark' => trim((string) ($data['remark'] ?? $data['confirmation_remark'] ?? '')),
                    'confirmation_reason' => trim((string) ($data['confirmation_reason'] ?? $data['reason'] ?? '')),
                    'delivery_instruction' => trim((string) ($data['delivery_instruction'] ?? $data['instruction'] ?? '')),
                    'tracking_number' => trim((string) ($data['tracking_number'] ?? $data['tracking'] ?? '')),
                    'order_date' => $this->date($data['order_date'] ?? null),
                    'preferred_delivery_at' => $this->nullableDateTime($data['preferred_delivery_at'] ?? $data['preferred_delivery_date'] ?? null),
                    'uploaded_at' => now(),
                ];

                $orderNumber = trim((string) ($data['order_number'] ?? $data['order_reference'] ?? ''));

                if ($orderNumber !== '') {
                    $order = CodOrder::query()->updateOrCreate(
                        ['business_id' => $business->id, 'order_number' => $orderNumber],
                        $attributes
                    );
                } else {
                    $order = CodOrder::query()->create($attributes);
                }

                $order->wasRecentlyCreated ? $created++ : $updated++;
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
        $missing = array_diff(['customer_name'], $headers);

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
        return filled($data['customer_name'] ?? null)
            && filled($data['customer_phone'] ?? $data['phone'] ?? null);
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

    private function sku(Business $business, mixed $skuCode): ?Sku
    {
        $code = trim((string) $skuCode);

        if ($code === '') {
            return null;
        }

        return Sku::query()
            ->where('business_id', $business->id)
            ->where('code', $code)
            ->first();
    }

    private function source(Business $business, mixed $sourceName): ?CodOrderSource
    {
        $name = trim((string) $sourceName);

        if ($name === '') {
            return null;
        }

        return CodOrderSource::query()
            ->where('business_id', $business->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();
    }

    private function employee(Business $business, mixed $employeeName): ?Employee
    {
        $name = trim((string) $employeeName);

        if ($name === '') {
            return null;
        }

        return Employee::query()
            ->where('business_id', $business->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();
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

    private function date(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $date = trim((string) $value);

        if ($date === '') {
            return today()->toDateString();
        }

        $timestamp = strtotime($date);

        return $timestamp ? date('Y-m-d', $timestamp) : today()->toDateString();
    }

    private function nullableDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $date = trim((string) $value);

        if ($date === '') {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }
}

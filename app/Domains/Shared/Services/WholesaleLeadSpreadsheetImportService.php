<?php

namespace App\Domains\Shared\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\WholesaleLead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\Common\Creator\ReaderFactory;

class WholesaleLeadSpreadsheetImportService
{
    /**
     * @return array{created:int, updated:int, skipped:int, skipped_reasons:list<string>}
     */
    public function import(Business $business, string $path, ?User $capturedBy = null): array
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
                    $rowBusiness = trim((string) ($data['business_name'] ?? $data['business'] ?? ''));
                    $this->addSkippedReason($skippedReasons, $rowNumber, "Business '{$rowBusiness}' does not match selected business '{$business->name}'.");
                    continue;
                }

                $status = $this->status((string) ($data['status'] ?? WholesaleLead::STATUS_LEAD));
                $phone = trim((string) ($data['phone'] ?? $data['customer_phone'] ?? $data['mobile'] ?? ''));
                $whatsappPhone = trim((string) ($data['whatsapp_phone'] ?? $data['whatsapp'] ?? ''));
                $nextFollowUpAt = $this->dateTime($data['next_follow_up_at'] ?? $data['follow_up_at'] ?? null);
                $convertedAt = $this->dateTime($data['converted_at'] ?? null);
                $capturedById = $capturedBy?->id;

                $lead = WholesaleLead::query()->updateOrCreate(
                    [
                        'business_id' => $business->id,
                        'customer_name' => trim((string) $data['customer_name']),
                        'phone' => $phone ?: null,
                        'whatsapp_phone' => $whatsappPhone ?: null,
                    ],
                    [
                        'captured_by_user_id' => $capturedById,
                        'source' => $this->source((string) ($data['source'] ?? 'other')),
                        'status' => $status,
                        'contact_name' => trim((string) ($data['contact_name'] ?? '')),
                        'location' => trim((string) ($data['location'] ?? '')),
                        'products_of_interest' => trim((string) ($data['products_of_interest'] ?? $data['products'] ?? '')),
                        'last_contacted_at' => $this->dateTime($data['last_contacted_at'] ?? $data['contacted_at'] ?? now()),
                        'next_follow_up_at' => $nextFollowUpAt,
                        'converted_at' => $convertedAt ?? ($status === WholesaleLead::STATUS_CUSTOMER ? now() : null),
                        'notes' => trim((string) ($data['notes'] ?? '')),
                    ]
                );

                $lead->forceFill([
                    'next_follow_up_at' => $lead->next_follow_up_at ?? $lead->recommendedFollowUpAt(),
                ])->save();

                $lead->wasRecentlyCreated ? $created++ : $updated++;
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
            && filled($data['phone'] ?? $data['customer_phone'] ?? $data['whatsapp_phone'] ?? $data['mobile'] ?? null);
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

    private function status(string $status): string
    {
        return array_key_exists($status, WholesaleLead::statusOptions()) ? $status : WholesaleLead::STATUS_LEAD;
    }

    private function source(string $source): string
    {
        return array_key_exists($source, WholesaleLead::sourceOptions()) ? $source : 'other';
    }

    private function dateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $date = trim((string) $value);

        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
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
}

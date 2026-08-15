<?php

namespace App\Domains\Shared\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ServiceLead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\Common\Creator\ReaderFactory;

class ServiceLeadSpreadsheetImportService
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
                    $this->addSkippedReason($skippedReasons, $rowNumber, 'Prospect name or phone is missing.');
                    continue;
                }

                if (! $this->matchesSelectedBusiness($business, $data)) {
                    $skipped++;
                    $rowBusiness = trim((string) ($data['business_name'] ?? $data['business'] ?? ''));
                    $this->addSkippedReason($skippedReasons, $rowNumber, "Business '{$rowBusiness}' does not match selected business '{$business->name}'.");
                    continue;
                }

                $status = $this->status((string) ($data['status'] ?? ServiceLead::STATUS_LEAD));
                $lead = ServiceLead::query()->updateOrCreate(
                    [
                        'business_id' => $business->id,
                        'prospect_name' => trim((string) ($data['prospect_name'] ?? $data['lead_name'] ?? $data['client_name'] ?? '')),
                        'phone' => trim((string) ($data['phone'] ?? $data['customer_phone'] ?? $data['mobile'] ?? '')) ?: null,
                        'whatsapp_number' => trim((string) ($data['whatsapp_number'] ?? $data['whatsapp_phone'] ?? $data['whatsapp'] ?? '')) ?: null,
                    ],
                    [
                        'captured_by_user_id' => $capturedBy?->id,
                        'contact_person' => trim((string) ($data['contact_person'] ?? $data['contact_name'] ?? '')),
                        'source' => $this->source((string) ($data['source'] ?? ServiceLead::SOURCE_OTHER)),
                        'status' => $status,
                        'billing_terms' => $this->billingTerms((string) ($data['billing_terms'] ?? $data['billing_style'] ?? ServiceLead::BILLING_MONTH_END)),
                        'expected_monthly_amount' => (float) ($data['expected_monthly_amount'] ?? $data['monthly_amount'] ?? 0),
                        'next_follow_up_at' => $this->dateTime($data['next_follow_up_at'] ?? $data['follow_up_at'] ?? null),
                        'last_contacted_at' => $this->dateTime($data['last_contacted_at'] ?? null),
                        'converted_at' => $this->dateTime($data['converted_at'] ?? null),
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
        $missing = array_diff(['prospect_name'], $headers);

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
        return filled($data['prospect_name'] ?? $data['lead_name'] ?? $data['client_name'] ?? null)
            && filled($data['phone'] ?? $data['whatsapp_number'] ?? $data['whatsapp_phone'] ?? $data['mobile'] ?? null);
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
        return array_key_exists($status, ServiceLead::statusOptions()) ? $status : ServiceLead::STATUS_LEAD;
    }

    private function source(string $source): string
    {
        return array_key_exists($source, ServiceLead::sourceOptions()) ? $source : ServiceLead::SOURCE_OTHER;
    }

    private function billingTerms(string $billingTerms): string
    {
        return array_key_exists($billingTerms, ServiceLead::billingTermsOptions()) ? $billingTerms : ServiceLead::BILLING_MONTH_END;
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

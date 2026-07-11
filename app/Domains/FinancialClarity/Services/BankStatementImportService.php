<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\BankTransaction;
use App\Domains\Shared\Models\BankTransactionRule;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use Carbon\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\Common\Creator\ReaderFactory;

class BankStatementImportService
{
    /**
     * @return array{created:int, reviewed:int, skipped:int}
     */
    public function import(Business $business, string $path, ?IntegrationSource $source = null, ?string $moneyContainer = null): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException('Bank statement file could not be read.');
        }

        try {
            $reader = ReaderFactory::createFromFile($path);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Bank statement file type is not supported.');
        }

        $created = 0;
        $reviewed = 0;
        $skipped = 0;
        $duplicates = 0;

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = [];
                $headerFound = false;

                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    $values = $row->toArray();

                    if (! $headerFound) {
                        $candidateHeaders = $this->normalizeHeaders($values);

                        if ($this->looksLikeHeaderRow($candidateHeaders)) {
                            $headers = $candidateHeaders;
                            $headerFound = true;
                        }

                        continue;
                    }

                    $data = $this->extractRowData($headers, $values);

                    if ($this->rowIsBlank($data)) {
                        $skipped++;
                        continue;
                    }

                    $rowHash = $this->rowHash($data);

                    if (BankTransaction::query()->where('business_id', $business->id)->where('row_hash', $rowHash)->exists()) {
                        $duplicates++;
                        continue;
                    }

                    $classification = $this->classify($business, $data);

                    BankTransaction::query()->create([
                        'business_id' => $business->id,
                        'integration_source_id' => $source?->id,
                        'statement_name' => $source?->name ?? basename($path),
                        'row_hash' => $rowHash,
                        'transaction_date' => $this->dateValue($data),
                        'description' => trim((string) ($data['description'] ?? '')),
                        'money_container' => $moneyContainer,
                        'debit' => $this->money($data['debit'] ?? 0),
                        'credit' => $this->money($data['credit'] ?? 0),
                        'balance' => filled($data['balance'] ?? null) ? $this->money($data['balance']) : null,
                        'classification' => $classification['classification'],
                        'transaction_type' => $classification['transaction_type'],
                        'allocated_business_id' => $classification['allocated_business_id'],
                        'confidence' => $classification['confidence'],
                        'rule_key' => $classification['rule_key'],
                        'status' => $classification['status'],
                        'raw_payload' => $data,
                    ]);

                    $classification['status'] === 'review' ? $reviewed++ : $created++;
                }

            }
        } finally {
            $reader->close();
        }

        return compact('created', 'reviewed', 'skipped', 'duplicates');
    }

    private function normalizeHeaders(array $values): array
    {
        return array_map(function ($value): string {
            $value = (string) $value;
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

            return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
        }, $values);
    }

    private function looksLikeHeaderRow(array $headers): bool
    {
        $hasDate = false;
        $hasNarrative = false;

        foreach ($headers as $header) {
            if ($header === 'date' || Str::contains($header, 'date')) {
                $hasDate = true;
            }

            if (in_array($header, ['description', 'narrative', 'narration', 'particulars', 'details', 'memo', 'remarks'], true)) {
                $hasNarrative = true;
            }
        }

        return $hasDate && $hasNarrative;
    }

    private function extractRowData(array $headers, array $values): array
    {
        $index = array_flip($headers);

        $data = [
            'date' => $this->valueByAliases($index, $values, ['date', 'transaction_date', 'value_date', 'posted_date', 'entry_date']),
            'description' => $this->valueByAliases($index, $values, ['description', 'narration', 'narrative', 'particulars', 'details', 'memo', 'remarks']),
            'debit' => $this->valueByAliases($index, $values, ['debit', 'withdrawal', 'withdraw', 'dr', 'outflow', 'payment_out', 'paid_out']),
            'credit' => $this->valueByAliases($index, $values, ['credit', 'deposit', 'cr', 'inflow', 'payment_in', 'received']),
            'balance' => $this->valueByAliases($index, $values, ['balance', 'running_balance', 'available_balance']),
            'amount' => $this->valueByAliases($index, $values, ['amount', 'transaction_amount', 'txn_amount', 'value']),
            'transaction_type' => $this->valueByAliases($index, $values, ['type', 'dr_cr', 'drcr', 'transaction_type', 'entry_type', 'direction']),
        ];

        $this->normalizeDirectionalAmount($data);

        return $data;
    }

    private function rowIsBlank(array $data): bool
    {
        foreach (['date', 'description', 'debit', 'credit', 'balance', 'amount', 'transaction_type'] as $field) {
            if (filled($data[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function rowHash(array $data): string
    {
        $payload = [
            'date' => $this->dateValue($data),
            'description' => Str::lower(trim((string) ($data['description'] ?? ''))),
            'debit' => number_format($this->money($data['debit'] ?? 0), 2, '.', ''),
            'credit' => number_format($this->money($data['credit'] ?? 0), 2, '.', ''),
            'balance' => number_format($this->money($data['balance'] ?? 0), 2, '.', ''),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($payload));
    }

    private function valueByAliases(array $headerIndex, array $values, array $aliases): mixed
    {
        foreach ($aliases as $alias) {
            foreach ($headerIndex as $header => $position) {
                if (! $this->headerMatchesAlias($header, $alias)) {
                    continue;
                }

                $value = $values[$position] ?? null;

                if (filled($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function headerMatchesAlias(string $header, string $alias): bool
    {
        if ($header === $alias) {
            return true;
        }

        if (strlen($alias) <= 3) {
            return false;
        }

        return Str::contains($header, $alias);
    }

    private function normalizeDirectionalAmount(array &$data): void
    {
        $debit = $this->money($data['debit'] ?? 0);
        $credit = $this->money($data['credit'] ?? 0);
        $amount = $this->money($data['amount'] ?? 0);
        $type = Str::lower(trim((string) ($data['transaction_type'] ?? '')));

        if ($debit > 0 || $credit > 0) {
            $data['debit'] = $debit;
            $data['credit'] = $credit;

            return;
        }

        if ($amount === 0.0) {
            $data['debit'] = 0;
            $data['credit'] = 0;

            return;
        }

        $debitWords = ['debit', 'dr', 'withdrawal', 'withdraw', 'payment', 'out', 'outgoing', 'charge'];
        $creditWords = ['credit', 'cr', 'deposit', 'in', 'incoming', 'received', 'refund'];

        if ($amount < 0 || Str::contains($type, $debitWords)) {
            $data['debit'] = abs($amount);
            $data['credit'] = 0;

            return;
        }

        if (Str::contains($type, $creditWords)) {
            $data['debit'] = 0;
            $data['credit'] = abs($amount);

            return;
        }

        $data['debit'] = 0;
        $data['credit'] = abs($amount);
    }

    private function dateValue(array $data): string
    {
        foreach (['date', 'transaction_date', 'value_date', 'posted_date', 'entry_date'] as $key) {
            if (filled($data[$key] ?? null)) {
                return $this->parseDate((string) $data[$key]);
            }
        }

        return now()->toDateString();
    }

    private function parseDate(string $value): string
    {
        $value = trim($value);

        $formats = [
            '!d/m/Y',
            '!d/m/y',
            '!d-m-Y',
            '!d-m-y',
            '!Y-m-d',
            '!Y/m/d',
            '!m/d/Y',
            '!m/d/y',
            '!d M Y',
            '!d M y',
            '!d-M-Y',
            '!d-M-y',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if ($date !== false) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }

    private function classify(Business $business, array $data): array
    {
        $description = Str::lower(trim((string) ($data['description'] ?? '')));
        $matchedRule = BankTransactionRule::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->orderByDesc('confidence')
            ->get()
            ->first(fn (BankTransactionRule $rule): bool => filled($rule->match_text) && Str::contains($description, Str::lower($rule->match_text)));

        if ($matchedRule) {
            $matchedRule->forceFill(['last_matched_at' => now()])->save();

            $transactionType = BankTransaction::inferTransactionType($matchedRule->classification);
            $allocatedBusinessId = BankTransaction::needsBusinessAssignment($transactionType) ? $business->id : null;

            return [
                'classification' => $matchedRule->classification,
                'transaction_type' => $transactionType,
                'allocated_business_id' => $allocatedBusinessId,
                'confidence' => (float) $matchedRule->confidence,
                'rule_key' => 'rule:'.$matchedRule->id,
                'status' => $this->reviewStatusFor($transactionType, $allocatedBusinessId, null, 'matched'),
            ];
        }

        if (filled($data['credit'] ?? null) && (float) $data['credit'] > 0) {
            return $this->keywordFallback($business, $description, 'revenue');
        }

        if (filled($data['debit'] ?? null) && (float) $data['debit'] > 0) {
            return $this->keywordFallback($business, $description, 'expense');
        }

        return [
            'classification' => 'unknown',
            'transaction_type' => null,
            'allocated_business_id' => null,
            'confidence' => 0.2,
            'rule_key' => null,
            'status' => 'review',
        ];
    }

    private function keywordFallback(Business $business, string $description, string $type): array
    {
        $map = [
            'salary' => 'salary',
            'payroll' => 'salary',
            'wage' => 'salary',
            'courier' => 'courier',
            'delivery' => 'courier',
            'fuel' => 'fuel',
            'bank charge' => 'bank_charge',
            'bank fee' => 'bank_charge',
            'processing fee' => 'bank_charge',
            'transaction fee' => 'bank_charge',
            'rent' => 'rent',
            'utility' => 'utility',
            'electricity' => 'utility',
            'phone' => 'utility',
            'internet' => 'utility',
            'marketing' => 'marketing',
            'owner' => 'owner_withdrawal',
            'withdraw' => 'owner_withdrawal',
            'transfer' => 'transfer',
            'supplier' => 'supplier_payment',
            'payment' => 'supplier_payment',
            'petty cash' => 'petty_cash',
            'maintenance' => 'maintenance',
            'repair' => 'maintenance',
        ];

        foreach ($map as $needle => $classification) {
            if (Str::contains($description, $needle)) {
                $transactionType = BankTransaction::inferTransactionType($classification);
                $allocatedBusinessId = BankTransaction::needsBusinessAssignment($transactionType) ? $business->id : null;

                return [
                    'classification' => $classification,
                    'transaction_type' => $transactionType,
                    'allocated_business_id' => $allocatedBusinessId,
                    'confidence' => 0.65,
                    'rule_key' => 'keyword:'.$needle,
                    'status' => $this->reviewStatusFor($transactionType, $allocatedBusinessId),
                ];
            }
        }

        $transactionType = BankTransaction::inferTransactionType($type);

        return [
            'classification' => $type,
            'transaction_type' => $transactionType,
            'allocated_business_id' => BankTransaction::needsBusinessAssignment($transactionType) ? $business->id : null,
            'confidence' => 0.4,
            'rule_key' => null,
            'status' => $this->reviewStatusFor($transactionType, BankTransaction::needsBusinessAssignment($transactionType) ? $business->id : null),
        ];
    }

    private function reviewStatusFor(?string $transactionType, ?int $allocatedBusinessId, ?string $counterMoneyContainer = null, string $preferred = 'classified'): string
    {
        if (blank($transactionType)) {
            return 'review';
        }

        if ($transactionType === 'transfer') {
            return blank($counterMoneyContainer) ? 'review' : $preferred;
        }

        if (BankTransaction::needsBusinessAssignment($transactionType) && blank($allocatedBusinessId)) {
            return 'review';
        }

        return $preferred;
    }

    private function money(mixed $value): float
    {
        return (float) str_replace([',', 'LKR', 'Rs', 'rs', ' '], '', (string) $value);
    }
}

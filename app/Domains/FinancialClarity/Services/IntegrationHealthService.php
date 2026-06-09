<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use Illuminate\Support\Collection;

class IntegrationHealthService
{
    public function forBusiness(Business $business): array
    {
        $sources = IntegrationSource::query()
            ->where('business_id', $business->id)
            ->where('type', 'stock_app')
            ->orderByDesc('last_successful_sync_at')
            ->orderByDesc('last_synced_at')
            ->get();

        if ($sources->isEmpty()) {
            return [
                'status' => 'never synced',
                'headline' => 'No stock-app integration has synced yet.',
                'last_successful_sync_at' => null,
                'last_webhook_received_at' => null,
                'failed_sync_attempts' => 0,
                'duplicate_event_count' => 0,
                'rejected_event_count' => 0,
                'sources' => [],
            ];
        }

        $summary = $sources->map(fn (IntegrationSource $source): array => $this->summarizeSource($source));
        $overallStatus = $this->overallStatus($summary);
        $latest = $summary->firstWhere('last_successful_sync_at', $summary->pluck('last_successful_sync_at')->filter()->max()) ?? $summary->first();

        return [
            'status' => $overallStatus,
            'headline' => match ($overallStatus) {
                'healthy' => 'Stock-app syncing looks healthy.',
                'delayed' => 'Stock-app syncing is moving, but it needs attention.',
                'broken' => 'Stock-app syncing is failing or being rejected.',
                default => 'Stock-app syncing has not started yet.',
            },
            'last_successful_sync_at' => $latest['last_successful_sync_at'] ?? null,
            'last_webhook_received_at' => $latest['last_webhook_received_at'] ?? null,
            'failed_sync_attempts' => $summary->sum('failed_sync_attempts'),
            'duplicate_event_count' => $summary->sum('duplicate_event_count'),
            'rejected_event_count' => $summary->sum('rejected_event_count'),
            'sources' => $summary->all(),
        ];
    }

    private function summarizeSource(IntegrationSource $source): array
    {
        $lastSuccessfulSync = $source->last_successful_sync_at ?? $source->last_synced_at;
        $ageHours = $lastSuccessfulSync ? now()->diffInHours($lastSuccessfulSync) : null;
        $status = match (true) {
            blank($lastSuccessfulSync) && blank($source->last_webhook_received_at) && (int) ($source->duplicate_event_count ?? 0) <= 0 && (int) ($source->rejected_event_count ?? 0) <= 0 => 'never synced',
            (int) ($source->failed_sync_attempts ?? 0) >= 3 || (int) ($source->rejected_event_count ?? 0) >= 3 => 'broken',
            $ageHours !== null && $ageHours > 168 => 'broken',
            $ageHours !== null && $ageHours > 24 => 'delayed',
            (int) ($source->failed_sync_attempts ?? 0) > 0 => 'delayed',
            default => 'healthy',
        };

        return [
            'id' => $source->id,
            'name' => $source->name,
            'status' => $status,
            'last_successful_sync_at' => optional($lastSuccessfulSync)->toDateTimeString(),
            'last_webhook_received_at' => optional($source->last_webhook_received_at)->toDateTimeString(),
            'failed_sync_attempts' => (int) ($source->failed_sync_attempts ?? 0),
            'duplicate_event_count' => (int) ($source->duplicate_event_count ?? 0),
            'rejected_event_count' => (int) ($source->rejected_event_count ?? 0),
            'last_health_status' => $source->last_health_status ?? $status,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $summary
     */
    private function overallStatus(Collection $summary): string
    {
        $statuses = $summary->pluck('status');

        if ($statuses->every(fn (string $status): bool => $status === 'healthy')) {
            return 'healthy';
        }

        if ($statuses->contains('broken')) {
            return 'broken';
        }

        if ($statuses->contains('delayed')) {
            return 'delayed';
        }

        return 'never synced';
    }
}

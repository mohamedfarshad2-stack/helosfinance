<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\FinancialClarity\Services\OperationalImpactCalculator;
use App\Domains\FinancialClarity\Services\SkuStockMovementService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Services\StockAppEmployeeDiscoveryService;
use App\Domains\Shared\Services\StockAppIntegrationSecurityService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class StockAppWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        OperationalImpactCalculator $calculator,
        SkuStockMovementService $stockMovements,
        StockAppIntegrationSecurityService $security,
        StockAppEmployeeDiscoveryService $employees,
    ): JsonResponse {
        $data = $request->validate([
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'business_key' => ['nullable', 'string'],
            'event_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'external_id' => ['nullable', 'string'],
            'sku_code' => ['nullable', 'string'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'amount' => ['nullable', 'numeric'],
            'sale_amount' => ['nullable', 'numeric'],
            'total_amount' => ['nullable', 'numeric'],
            'customer_total_amount' => ['nullable', 'numeric'],
            'total_customer_amount' => ['nullable', 'numeric'],
            'product_sale_amount' => ['nullable', 'numeric'],
            'product_selling_amount' => ['nullable', 'numeric'],
            'marked_price' => ['nullable', 'numeric'],
            'customer_delivery_charge' => ['nullable', 'numeric'],
            'customer_delivery_amount' => ['nullable', 'numeric'],
            'transport_cost_amount' => ['nullable', 'numeric'],
            'delivery_amount' => ['nullable', 'numeric'],
            'courier_amount' => ['nullable', 'numeric'],
            'channel' => ['nullable', 'string'],
            'department' => ['nullable', 'string'],
            'tracking_number' => ['nullable', 'string'],
            'customer_name' => ['nullable', 'string'],
            'customer_phone' => ['nullable', 'string'],
            'customer_alt_phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'district' => ['nullable', 'string'],
            'delivery_instruction' => ['nullable', 'string'],
            'confirmation_reason' => ['nullable', 'string'],
            'return_reason' => ['nullable', 'string'],
            'preferred_delivery_at' => ['nullable', 'date'],
            'occurred_at' => ['nullable', 'date'],
            'order_id' => ['nullable', 'string'],
            'reference' => ['nullable', 'string'],
            'stage_occurred_at_source' => ['nullable', 'string'],
            'restockable' => ['nullable'],
            'return_stock' => ['nullable'],
            'restock' => ['nullable'],
            'damage_cost' => ['nullable', 'numeric'],
            'recovery_amount' => ['nullable', 'numeric'],
        ], [
            'business_id.required' => 'Business context is required for this webhook.',
            'event_type.required' => 'Event type or status is required for this webhook.',
        ]);

        [$business, $integrationSource] = $this->resolveBusinessContext($data);
        $eventType = $this->normalizeEventType($data['event_type'] ?? $data['status'] ?? null);

        if (! $business instanceof Business) {
            return response()->json([
                'message' => 'Business context is required for this webhook.',
            ], 422);
        }

        $authorization = $security->authorize($request, $business, $integrationSource, 'webhook');
        if (! ($authorization['allowed'] ?? false)) {
            $security->recordRejected($integrationSource, (string) ($authorization['reason'] ?? 'Rejected webhook request.'));

            return response()->json([
                'message' => (string) ($authorization['reason'] ?? 'Rejected webhook request.'),
            ], (int) ($authorization['status'] ?? 401));
        }

        if ($eventType === null) {
            $security->recordRejected($integrationSource, 'Event type or status is required for this webhook.');

            return response()->json([
                'message' => 'Event type or status is required for this webhook.',
            ], 422);
        }

        try {
            $payload = array_merge($request->all(), ['event_type' => $eventType]);
            $employees->discover($business, $payload);
            $impact = $calculator->calculate($business, $payload);
            $externalId = $data['external_id'] ?? $this->stableExternalId($business->id, $payload);
            $eventImpact = $impact;
            unset($eventImpact['economics']);
            $eventPayload = array_merge($payload, [
                'economics' => $impact['economics'] ?? [],
            ]);

            $event = OperationalEvent::query()->firstOrCreate(
                [
                    'business_id' => $business->id,
                    'source' => 'stock_app',
                    'event_type' => $eventType,
                    'external_id' => $externalId,
                ],
                [
                    ...$eventImpact,
                    'channel' => $data['channel'] ?? null,
                    'department' => $data['department'] ?? 'Operations',
                    'quantity' => $data['quantity'] ?? 1,
                    'payload' => $eventPayload,
                    'occurred_at' => $data['occurred_at'] ?? now(),
                ]
            );

            if (! $event->wasRecentlyCreated) {
                $event->forceFill([
                    ...$eventImpact,
                    'channel' => $data['channel'] ?? $event->channel,
                    'department' => $data['department'] ?? $event->department,
                    'quantity' => $data['quantity'] ?? $event->quantity,
                    'payload' => array_merge($event->payload ?? [], $eventPayload),
                    'occurred_at' => $this->resolvedOccurredAt(
                        $event->occurred_at,
                        $data['occurred_at'] ?? null,
                        $event->payload ?? [],
                        $eventPayload,
                        $eventType,
                    ),
                ])->save();
            }

            $stockMovements->record($business, $event, $payload);
            $security->recordSuccess($integrationSource, ! $event->wasRecentlyCreated);

            return response()->json([
                'message' => 'Operational event received.',
                'event_id' => $event->id,
                'impact' => $impact,
            ], 201);
        } catch (Throwable $throwable) {
            $security->recordFailure($integrationSource, $throwable->getMessage());
            report($throwable);

            return response()->json([
                'message' => 'Unable to process the webhook right now.',
            ], 500);
        }
    }

    private function stableExternalId(int $businessId, array $payload): string
    {
        $parts = [
            $businessId,
            $payload['event_type'] ?? 'unknown',
            $payload['order_id'] ?? $payload['external_id'] ?? $payload['reference'] ?? $payload['sku_code'] ?? 'payload',
            $payload['tracking_number'] ?? 'no-tracking',
            $payload['occurred_at'] ?? 'now',
            $payload['quantity'] ?? 1,
        ];

        return 'stock-webhook-'.sha1(implode('|', $parts));
    }

    /**
     * @return array{0:?Business,1:?IntegrationSource}
     */
    private function resolveBusinessContext(array $data): array
    {
        $businessId = $data['business_id'] ?? null;

        if ($businessId) {
            $business = Business::query()->find($businessId);

            if ($business) {
                return [
                    $business,
                    IntegrationSource::query()
                        ->where('business_id', $business->id)
                        ->where('type', 'stock_app')
                        ->orderByDesc('last_successful_sync_at')
                        ->first(),
                ];
            }
        }

        $businessKey = trim((string) ($data['business_key'] ?? ''));

        if ($businessKey === '') {
            return [null, null];
        }

        $integrationSource = IntegrationSource::query()
            ->where('type', 'stock_app')
            ->where('settings->stock_app_business_key', $businessKey)
            ->first();

        return [$integrationSource?->business, $integrationSource];
    }

    private function normalizeEventType(mixed $value): ?string
    {
        $eventType = trim((string) $value);

        if ($eventType === '') {
            return null;
        }

        $normalized = str_replace([' ', '-'], '_', strtolower($eventType));

        return match ($normalized) {
            'dispatched', 'dispatch', 'tracking_added', 'tracking', 'tracking_number', 'tracking_number_added' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'wholesale_sent', 'wholesale_dispatched', 'transport_sent', 'parcel_sent', 'wholesale_parcel_sent' => OperationalEvent::WHOLESALE_PARCEL_SENT,
            'delivered', 'delivery_done' => OperationalEvent::ORDER_DELIVERED,
            'returned', 'return' => OperationalEvent::ORDER_RETURNED,
            'resent', 'resend' => OperationalEvent::ORDER_RESENT,
            'created', 'new', 'pending', 'order_pending', 'pending_confirmation' => OperationalEvent::ORDER_CREATED,
            'confirmed', 'confirm' => OperationalEvent::ORDER_CONFIRMED,
            default => $eventType,
        };
    }

    private function resolvedOccurredAt(
        mixed $existingOccurredAt,
        mixed $incomingOccurredAt,
        array $existingPayload = [],
        array $incomingPayload = [],
        ?string $eventType = null,
    ): mixed {
        if (blank($incomingOccurredAt)) {
            return $existingOccurredAt;
        }

        $incoming = Carbon::parse($incomingOccurredAt);

        if (blank($existingOccurredAt)) {
            return $incoming;
        }

        $existing = $existingOccurredAt instanceof Carbon
            ? $existingOccurredAt
            : Carbon::parse($existingOccurredAt);

        $existingRank = $this->occurredAtSourceRank(
            (string) ($existingPayload['stage_occurred_at_source'] ?? ''),
            $eventType,
        );
        $incomingRank = $this->occurredAtSourceRank(
            (string) ($incomingPayload['stage_occurred_at_source'] ?? ''),
            $eventType,
        );

        if ($incomingRank > $existingRank) {
            return $incoming;
        }

        if ($incomingRank < $existingRank) {
            return $existing;
        }

        return $incoming->lt($existing) ? $incoming : $existing;
    }

    private function occurredAtSourceRank(string $source, ?string $eventType): int
    {
        $normalized = trim(strtolower($source));

        if ($normalized === '') {
            return 0;
        }

        $dispatchLike = [
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
            OperationalEvent::ORDER_RESENT,
        ];

        $deliveryLike = [
            OperationalEvent::ORDER_DELIVERED,
            OperationalEvent::ORDER_RETURNED,
        ];

        return match (true) {
            in_array($eventType, $dispatchLike, true) => match ($normalized) {
                'client_dispatched_at' => 4,
                'stock_app', 'shipped_at' => 3,
                'confirmed_at' => 2,
                'order_date' => 1,
                default => 0,
            },
            in_array($eventType, $deliveryLike, true) => match ($normalized) {
                'delivered_at', 'returned_at' => 5,
                'client_dispatched_at' => 4,
                'stock_app', 'shipped_at' => 3,
                'confirmed_at' => 2,
                'order_date' => 1,
                default => 0,
            },
            default => match ($normalized) {
                'confirmed_at' => 2,
                'order_date' => 1,
                default => 0,
            },
        };
    }
}

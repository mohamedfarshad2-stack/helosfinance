<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\FinancialClarity\Services\OperationalImpactCalculator;
use App\Domains\FinancialClarity\Services\SkuStockMovementService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Services\StockAppEmployeeDiscoveryService;
use App\Domains\Shared\Services\StockAppIntegrationSecurityService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class StockAppSyncController extends Controller
{
    public function orders(
        Request $request,
        OperationalImpactCalculator $calculator,
        SkuStockMovementService $stockMovements,
        StockAppIntegrationSecurityService $security,
        StockAppEmployeeDiscoveryService $employees,
    ): JsonResponse {
        $data = $request->validate([
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'business_key' => ['nullable', 'string'],
            'orders' => ['required', 'array'],
            'orders.*.event_type' => ['nullable', 'string'],
            'orders.*.status' => ['nullable', 'string'],
            'orders.*.order_status' => ['nullable', 'string'],
            'orders.*.current_status' => ['nullable', 'string'],
            'orders.*.delivery_status' => ['nullable', 'string'],
            'orders.*.status_name' => ['nullable', 'string'],
            'orders.*.status_label' => ['nullable', 'string'],
            'orders.*.order_state' => ['nullable', 'string'],
            'orders.*.parcel_status' => ['nullable', 'string'],
            'orders.*.shipment_status' => ['nullable', 'string'],
            'orders.*.fulfillment_status' => ['nullable', 'string'],
            'orders.*.external_id' => ['nullable', 'string'],
            'orders.*.order_id' => ['nullable', 'string'],
            'orders.*.reference' => ['nullable', 'string'],
            'orders.*.sku_code' => ['nullable', 'string'],
            'orders.*.sku_name' => ['nullable', 'string'],
            'orders.*.product_name' => ['nullable', 'string'],
            'orders.*.quantity' => ['nullable', 'integer', 'min:1'],
            'orders.*.amount' => ['nullable', 'numeric'],
            'orders.*.sale_amount' => ['nullable', 'numeric'],
            'orders.*.total_amount' => ['nullable', 'numeric'],
            'orders.*.customer_total_amount' => ['nullable', 'numeric'],
            'orders.*.total_customer_amount' => ['nullable', 'numeric'],
            'orders.*.product_sale_amount' => ['nullable', 'numeric'],
            'orders.*.product_selling_amount' => ['nullable', 'numeric'],
            'orders.*.marked_price' => ['nullable', 'numeric'],
            'orders.*.customer_delivery_charge' => ['nullable', 'numeric'],
            'orders.*.customer_delivery_amount' => ['nullable', 'numeric'],
            'orders.*.transport_cost_amount' => ['nullable', 'numeric'],
            'orders.*.delivery_amount' => ['nullable', 'numeric'],
            'orders.*.courier_amount' => ['nullable', 'numeric'],
            'orders.*.channel' => ['nullable', 'string'],
            'orders.*.department' => ['nullable', 'string'],
            'orders.*.tracking_number' => ['nullable', 'string'],
            'orders.*.customer_name' => ['nullable', 'string'],
            'orders.*.customer_phone' => ['nullable', 'string'],
            'orders.*.customer_alt_phone' => ['nullable', 'string'],
            'orders.*.address' => ['nullable', 'string'],
            'orders.*.city' => ['nullable', 'string'],
            'orders.*.district' => ['nullable', 'string'],
            'orders.*.delivery_instruction' => ['nullable', 'string'],
            'orders.*.confirmation_reason' => ['nullable', 'string'],
            'orders.*.return_reason' => ['nullable', 'string'],
            'orders.*.preferred_delivery_at' => ['nullable', 'date'],
            'orders.*.occurred_at' => ['nullable', 'date'],
            'orders.*.stage_occurred_at_source' => ['nullable', 'string'],
            'orders.*.restockable' => ['nullable'],
            'orders.*.return_stock' => ['nullable'],
            'orders.*.restock' => ['nullable'],
            'orders.*.damage_cost' => ['nullable', 'numeric'],
            'orders.*.recovery_amount' => ['nullable', 'numeric'],
            'orders.*.csr_employee' => ['nullable', 'string', 'max:255'],
        ], [
            'business_id.required' => 'Business context is required for this sync.',
        ]);

        [$business, $integrationSource] = $this->resolveBusinessContext($data);

        if (! $business) {
            return response()->json([
                'message' => 'Business context is required for this sync.',
            ], 422);
        }

        $authorization = $security->authorize($request, $business, $integrationSource, 'sync');
        if (! ($authorization['allowed'] ?? false)) {
            $security->recordRejected($integrationSource, (string) ($authorization['reason'] ?? 'Rejected sync request.'));

            return response()->json([
                'message' => (string) ($authorization['reason'] ?? 'Rejected sync request.'),
            ], (int) ($authorization['status'] ?? 401));
        }

        $created = 0;
        $duplicates = 0;

        try {
            foreach ($data['orders'] as $order) {
                $eventType = $this->normalizeEventType($order);

                if ($eventType === null) {
                    $security->recordRejected($integrationSource, 'An order row was missing an event type.');

                    continue;
                }

                $this->ensureSkuExists($business, $order);
                $employees->discover($business, $order);

                $payload = array_merge($order, ['event_type' => $eventType]);
                $impact = $calculator->calculate($business, $payload);
                $eventImpact = $impact;
                unset($eventImpact['economics']);
                $externalId = $order['external_id'] ?? $this->stableExternalId($business->id, $payload);
                $eventPayload = array_merge($payload, [
                    'economics' => $impact['economics'] ?? [],
                ]);

                $event = OperationalEvent::query()->firstOrCreate(
                    [
                        'business_id' => $business->id,
                        'source' => 'stock_app_sync',
                        'event_type' => $eventType,
                        'external_id' => $externalId,
                    ],
                    [
                        ...$eventImpact,
                        'channel' => $order['channel'] ?? null,
                        'department' => $order['department'] ?? 'Operations',
                        'quantity' => $order['quantity'] ?? 1,
                        'payload' => $eventPayload,
                        'occurred_at' => $order['occurred_at'] ?? now(),
                    ]
                );

                if (! $event->wasRecentlyCreated) {
                    $event->forceFill([
                        ...$eventImpact,
                        'channel' => $order['channel'] ?? $event->channel,
                        'department' => $order['department'] ?? $event->department,
                        'quantity' => $order['quantity'] ?? $event->quantity,
                        'payload' => array_merge($event->payload ?? [], $eventPayload),
                        'occurred_at' => $this->resolvedOccurredAt(
                            $event->occurred_at,
                            $order['occurred_at'] ?? null,
                            $event->payload ?? [],
                            $eventPayload,
                            $eventType,
                        ),
                    ])->save();
                }

                $stockMovements->record($business, $event, $payload);

                $event->wasRecentlyCreated ? $created++ : $duplicates++;
            }

            $security->recordSuccess($integrationSource, $duplicates > 0);

            return response()->json([
                'message' => 'Orders synced.',
                'created' => $created,
                'duplicates' => $duplicates,
            ]);
        } catch (Throwable $throwable) {
            $security->recordFailure($integrationSource, $throwable->getMessage());
            report($throwable);

            return response()->json([
                'message' => 'Unable to sync orders right now.',
            ], 500);
        }
    }

    public function healthSummary(BusinessHealthSnapshotService $snapshots): JsonResponse
    {
        $request = request();
        [$business, $integrationSource] = $this->resolveBusinessContext([
            'business_id' => $request->input('business_id'),
            'business_key' => $request->input('business_key'),
        ]);

        if (! $business) {
            return response()->json([
                'message' => 'Business context is required for this summary.',
            ], 422);
        }

        $authorization = app(StockAppIntegrationSecurityService::class)->authorize($request, $business, $integrationSource, 'health-summary');
        if (! ($authorization['allowed'] ?? false)) {
            return response()->json([
                'message' => (string) ($authorization['reason'] ?? 'Rejected summary request.'),
            ], (int) ($authorization['status'] ?? 401));
        }

        return response()->json($snapshots->currentMonthSummary($business));
    }

    private function stableExternalId(int $businessId, array $order): string
    {
        $parts = [
            $businessId,
            $order['event_type'] ?? 'unknown',
            $order['order_id'] ?? $order['external_id'] ?? $order['reference'] ?? $order['sku_code'] ?? 'payload',
            $order['tracking_number'] ?? 'no-tracking',
            $order['occurred_at'] ?? 'now',
            $order['quantity'] ?? 1,
        ];

        return 'stock-sync-'.sha1(implode('|', $parts));
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
        if (is_array($value)) {
            foreach (['status', 'order_status', 'current_status', 'delivery_status', 'status_name', 'status_label', 'order_state', 'parcel_status', 'shipment_status', 'fulfillment_status'] as $key) {
                $normalized = $this->normalizeKnownEventType($value[$key] ?? null);

                if ($normalized !== null) {
                    return $normalized;
                }
            }

            return $this->normalizeSingleEventType($value['event_type'] ?? null, true);
        }

        return $this->normalizeSingleEventType($value, true);
    }

    private function normalizeKnownEventType(mixed $value): ?string
    {
        $normalized = $this->normalizeSingleEventType($value);

        return in_array($normalized, [
            OperationalEvent::ORDER_CREATED,
            OperationalEvent::ORDER_CONFIRMED,
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
            OperationalEvent::ORDER_DELIVERED,
            OperationalEvent::ORDER_RETURNED,
            OperationalEvent::ORDER_RESENT,
            OperationalEvent::FAKE_ORDER_DETECTED,
        ], true) ? $normalized : null;
    }

    private function normalizeSingleEventType(mixed $value, bool $preserveUnknown = false): ?string
    {
        $eventType = trim((string) $value);

        if ($eventType === '') {
            return null;
        }

        $normalized = str_replace([' ', '-'], '_', strtolower($eventType));

        return match ($normalized) {
            'dispatched', 'dispatch', 'shipped', 'shipping', 'sent_to_courier', 'tracking_added', 'tracking', 'tracking_number', 'tracking_number_added' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'wholesale_sent', 'wholesale_dispatched', 'transport_sent', 'parcel_sent', 'wholesale_parcel_sent' => OperationalEvent::WHOLESALE_PARCEL_SENT,
            'delivered', 'delivery_done' => OperationalEvent::ORDER_DELIVERED,
            'returned', 'return' => OperationalEvent::ORDER_RETURNED,
            'resent', 'resend' => OperationalEvent::ORDER_RESENT,
            'created', 'new', 'pending', 'order_pending', 'pending_confirmation', 'confirmation_pending', 'pending_confirm', 'pending_call', 'call_pending', 'to_confirm', 'not_confirmed', 'unconfirmed', 'no_answer' => OperationalEvent::ORDER_CREATED,
            'confirmed', 'confirm' => OperationalEvent::ORDER_CONFIRMED,
            'fake', 'fake_order', 'fake_order_detected' => OperationalEvent::FAKE_ORDER_DETECTED,
            OperationalEvent::ORDER_CREATED,
            OperationalEvent::ORDER_CONFIRMED,
            OperationalEvent::TRACKING_NUMBER_ADDED,
            OperationalEvent::WHOLESALE_PARCEL_SENT,
            OperationalEvent::ORDER_DELIVERED,
            OperationalEvent::ORDER_RETURNED,
            OperationalEvent::ORDER_RESENT,
            OperationalEvent::FAKE_ORDER_DETECTED => $normalized,
            default => $preserveUnknown ? $eventType : null,
        };
    }

    private function ensureSkuExists(Business $business, array $order): ?Sku
    {
        $code = trim((string) ($order['sku_code'] ?? ''));

        if ($code === '') {
            return null;
        }

        return Sku::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'code' => $code,
            ],
            [
                'name' => trim((string) ($order['sku_name'] ?? $order['product_name'] ?? $code)) ?: $code,
                'material_cost' => 0,
                'packaging_cost' => 0,
                'labor_rate' => 0,
                'finishing_cost' => 0,
                'expected_sale_price' => (float) ($order['product_sale_amount'] ?? $order['product_selling_amount'] ?? $order['marked_price'] ?? $order['sale_amount'] ?? $order['amount'] ?? 0),
                'active' => true,
            ]
        );
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

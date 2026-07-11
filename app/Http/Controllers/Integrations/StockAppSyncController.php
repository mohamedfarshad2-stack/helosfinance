<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\FinancialClarity\Services\OperationalImpactCalculator;
use App\Domains\FinancialClarity\Services\SkuStockMovementService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
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
        StockAppIntegrationSecurityService $security
    ): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'business_key' => ['nullable', 'string'],
            'orders' => ['required', 'array'],
            'orders.*.event_type' => ['nullable', 'string'],
            'orders.*.status' => ['nullable', 'string'],
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
            'orders.*.restockable' => ['nullable'],
            'orders.*.return_stock' => ['nullable'],
            'orders.*.restock' => ['nullable'],
            'orders.*.damage_cost' => ['nullable', 'numeric'],
            'orders.*.recovery_amount' => ['nullable', 'numeric'],
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
                $eventType = $this->normalizeEventType($order['event_type'] ?? $order['status'] ?? null);

                if ($eventType === null) {
                    $security->recordRejected($integrationSource, 'An order row was missing an event type.');
                    continue;
                }

                $this->ensureSkuExists($business, $order);

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
                        'occurred_at' => $this->resolvedOccurredAt($event->occurred_at, $order['occurred_at'] ?? null),
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
        $eventType = trim((string) $value);

        if ($eventType === '') {
            return null;
        }

        return match ($eventType) {
            'dispatched', 'dispatch', 'tracking_added', 'tracking', 'tracking_number', 'tracking_number_added' => OperationalEvent::TRACKING_NUMBER_ADDED,
            'wholesale_sent', 'wholesale_dispatched', 'transport_sent', 'parcel_sent', 'wholesale_parcel_sent' => OperationalEvent::WHOLESALE_PARCEL_SENT,
            'delivered', 'delivery_done' => OperationalEvent::ORDER_DELIVERED,
            'returned', 'return' => OperationalEvent::ORDER_RETURNED,
            'resent', 'resend' => OperationalEvent::ORDER_RESENT,
            'created' => OperationalEvent::ORDER_CREATED,
            'confirmed' => OperationalEvent::ORDER_CONFIRMED,
            default => $eventType,
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

    private function resolvedOccurredAt(mixed $existingOccurredAt, mixed $incomingOccurredAt): mixed
    {
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

        return $incoming->lt($existing) ? $incoming : $existing;
    }
}

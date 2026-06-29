<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Services\StockAppIntegrationSecurityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAppSkuController extends Controller
{
    public function index(Request $request, StockAppIntegrationSecurityService $security): JsonResponse
    {
        [$business, $integrationSource] = $this->resolveBusinessContext($request);

        if (! $business) {
            return response()->json([
                'message' => 'Business context is required for SKU lookup.',
            ], 422);
        }

        $authorization = $security->authorize($request, $business, $integrationSource, 'sku-list');
        if (! ($authorization['allowed'] ?? false)) {
            return response()->json([
                'message' => (string) ($authorization['reason'] ?? 'Rejected SKU lookup request.'),
            ], (int) ($authorization['status'] ?? 401));
        }

        $search = trim((string) $request->query('search', ''));
        $limit = min(max((int) $request->query('limit', 100), 1), 500);

        $skus = Sku::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderBy('code')
            ->limit($limit)
            ->get(['code', 'name']);

        return response()->json([
            'business_id' => $business->id,
            'items' => $skus->map(fn (Sku $sku): array => [
                'code' => $sku->code,
                'name' => $sku->name,
            ])->values(),
        ]);
    }

    /**
     * @return array{0:?Business,1:?IntegrationSource}
     */
    private function resolveBusinessContext(Request $request): array
    {
        $businessId = $request->integer('business_id');

        if ($businessId > 0) {
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

        $businessKey = trim((string) $request->query('business_key', $request->input('business_key', '')));

        if ($businessKey === '') {
            return [null, null];
        }

        $integrationSource = IntegrationSource::query()
            ->where('type', 'stock_app')
            ->where('settings->stock_app_business_key', $businessKey)
            ->first();

        return [$integrationSource?->business, $integrationSource];
    }
}

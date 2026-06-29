<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Services\StockAppIntegrationSecurityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAppEmployeeController extends Controller
{
    public function index(Request $request, StockAppIntegrationSecurityService $security): JsonResponse
    {
        [$business, $integrationSource] = $this->resolveBusinessContext($request);

        if (! $business) {
            return response()->json([
                'message' => 'Business context is required for employee lookup.',
            ], 422);
        }

        $authorization = $security->authorize($request, $business, $integrationSource, 'employee-list');
        if (! ($authorization['allowed'] ?? false)) {
            return response()->json([
                'message' => (string) ($authorization['reason'] ?? 'Rejected employee lookup request.'),
            ], (int) ($authorization['status'] ?? 401));
        }

        $search = trim((string) $request->query('search', ''));
        $limit = min(max((int) $request->query('limit', 100), 1), 500);

        $employees = Employee::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('role', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'role']);

        return response()->json([
            'business_id' => $business->id,
            'items' => $employees->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'role' => $employee->role,
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

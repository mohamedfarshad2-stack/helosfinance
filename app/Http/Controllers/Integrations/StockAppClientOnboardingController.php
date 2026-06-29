<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ClientGroup;
use App\Domains\Shared\Models\IntegrationSource;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockAppClientOnboardingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $token = $this->providedToken($request);

        if (! $this->tokenAllowed($token)) {
            return response()->json([
                'message' => 'Integration token did not match.',
            ], 401);
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_key' => ['required', 'string', 'max:100'],
            'client_group_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:8'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'owner_email' => ['nullable', 'email', 'max:255'],
            'owner_password' => ['nullable', 'string', 'min:8'],
            'stock_app_base_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $businessKey = Str::slug((string) $data['business_key']);
        $sharedToken = $token ?: (string) config('services.stock_app.onboarding_token');

        $result = DB::transaction(function () use ($data, $businessKey, $sharedToken): array {
            $integrationSource = IntegrationSource::query()
                ->where('type', 'stock_app')
                ->where('settings->stock_app_business_key', $businessKey)
                ->first();

            $clientGroup = null;
            $business = $integrationSource?->business;

            if (! $business instanceof Business) {
                $clientGroup = ClientGroup::query()->firstOrCreate(
                    ['name' => $data['client_group_name'] ?? $data['business_name']],
                    ['note' => 'Created from COD Returns Lanka onboarding.']
                );

                $business = Business::query()->firstOrCreate(
                    [
                        'name' => $data['business_name'],
                        'client_group_id' => $clientGroup->id,
                    ],
                    [
                        'currency' => $data['currency'] ?? 'LKR',
                        'industry' => $data['industry'] ?? 'COD',
                        'business_type' => Business::TYPE_TRADING,
                        'business_maturity' => Business::MATURITY_LEVEL_1,
                        'onboarding_status' => 'setup',
                        'clarity_started_on' => now()->toDateString(),
                        'settings' => [
                            'integration_security' => array_filter([
                                'shared_token' => $sharedToken,
                            ]),
                        ],
                    ]
                );
            }

            if (! $clientGroup instanceof ClientGroup && filled($business->client_group_id)) {
                $clientGroup = ClientGroup::query()->find($business->client_group_id);
            }

            $integrationSource = IntegrationSource::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'type' => 'stock_app',
                    'name' => $data['business_name'].' Stock App',
                ],
                [
                    'base_url' => $data['stock_app_base_url'] ?? 'https://codreturnslanka.lk',
                    'status' => 'active',
                    'shared_token' => $sharedToken,
                    'settings' => [
                        'stock_app_business_key' => $businessKey,
                        'notes' => $data['notes'] ?? 'Created from COD Returns Lanka.',
                    ],
                ]
            );

            $user = null;

            if (filled($data['owner_email'] ?? null)) {
                $userPayload = [
                    'name' => $data['owner_name'] ?? $data['business_name'],
                    'business_id' => $business->id,
                    'client_group_id' => $clientGroup?->id,
                    'is_platform_admin' => false,
                    'is_employee' => false,
                ];

                if (filled($data['owner_password'] ?? null)) {
                    $userPayload['password'] = $data['owner_password'];
                    $user = User::query()->updateOrCreate(['email' => $data['owner_email']], $userPayload);
                } else {
                    $user = User::query()->where('email', $data['owner_email'])->first();

                    if ($user) {
                        $user->forceFill($userPayload)->save();
                    }
                }
            }

            return [
                'business' => $business,
                'integration_source' => $integrationSource,
                'user' => $user,
            ];
        });

        return response()->json([
            'message' => 'Client connected to HELOS.',
            'business_id' => $result['business']->id,
            'business_key' => $businessKey,
            'integration_source_id' => $result['integration_source']->id,
            'user_id' => $result['user']?->id,
        ]);
    }

    private function tokenAllowed(?string $token): bool
    {
        if (! filled($token)) {
            return false;
        }

        $configured = (string) config('services.stock_app.onboarding_token');

        if (filled($configured) && hash_equals($configured, (string) $token)) {
            return true;
        }

        return IntegrationSource::query()
            ->whereNotNull('shared_token')
            ->get(['shared_token'])
            ->contains(fn (IntegrationSource $source): bool => hash_equals((string) $source->shared_token, (string) $token));
    }

    private function providedToken(Request $request): ?string
    {
        $token = trim((string) $request->header('X-HELOS-TOKEN', ''));

        if (filled($token)) {
            return $token;
        }

        $authorization = trim((string) $request->header('Authorization', ''));

        if (str_starts_with($authorization, 'Bearer ')) {
            return trim(substr($authorization, 7));
        }

        return null;
    }
}

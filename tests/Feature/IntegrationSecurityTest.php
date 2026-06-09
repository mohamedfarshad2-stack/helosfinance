<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\OperationalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_invalid_shared_token_and_marks_source_rejected(): void
    {
        $business = Business::query()->create([
            'name' => 'Secure Business',
            'settings' => [
                'integration_security' => [
                    'shared_token' => 'shared-secret-123',
                    'allow_local_bypass' => false,
                ],
            ],
        ]);

        $source = IntegrationSource::query()->create([
            'business_id' => $business->id,
            'name' => 'Secure stock-app',
            'type' => 'stock_app',
            'base_url' => 'http://127.0.0.1:8001',
            'allow_local_bypass' => false,
            'status' => 'testing',
            'settings' => [
                'stock_app_business_key' => 'SECURE-001',
            ],
        ]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_CREATED,
            'external_id' => 'SECURE-ORDER-1',
            'quantity' => 1,
        ], [
            'X-HELOS-TOKEN' => 'wrong-secret',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Integration token or signature did not match.');

        $source->refresh();

        $this->assertSame(1, $source->rejected_event_count);
        $this->assertSame(1, $source->failed_sync_attempts);
        $this->assertSame('broken', $source->last_health_status);
        $this->assertNotNull($source->last_webhook_received_at);
        $this->assertSame(0, OperationalEvent::query()->count());
    }

    public function test_webhook_accepts_valid_shared_token(): void
    {
        $business = Business::query()->create([
            'name' => 'Secure Business',
            'settings' => [
                'integration_security' => [
                    'shared_token' => 'shared-secret-123',
                    'allow_local_bypass' => false,
                ],
            ],
        ]);

        $source = IntegrationSource::query()->create([
            'business_id' => $business->id,
            'name' => 'Secure stock-app',
            'type' => 'stock_app',
            'base_url' => 'http://127.0.0.1:8001',
            'allow_local_bypass' => false,
            'status' => 'testing',
            'settings' => [
                'stock_app_business_key' => 'SECURE-001',
            ],
        ]);

        $this->postJson('/api/v1/stock-app/webhook', [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_CREATED,
            'external_id' => 'SECURE-ORDER-2',
            'quantity' => 1,
        ], [
            'X-HELOS-TOKEN' => 'shared-secret-123',
        ])->assertCreated();

        $source->refresh();

        $this->assertNotNull($source->last_successful_sync_at);
        $this->assertSame('healthy', $source->last_health_status);
        $this->assertSame(1, OperationalEvent::query()->count());
    }

    public function test_webhook_accepts_valid_signature(): void
    {
        $business = Business::query()->create([
            'name' => 'Secure Business',
            'settings' => [
                'integration_security' => [
                    'signature_secret' => 'signature-secret-123',
                    'allow_local_bypass' => false,
                ],
            ],
        ]);

        IntegrationSource::query()->create([
            'business_id' => $business->id,
            'name' => 'Secure stock-app',
            'type' => 'stock_app',
            'base_url' => 'http://127.0.0.1:8001',
            'allow_local_bypass' => false,
            'status' => 'testing',
            'settings' => [
                'stock_app_business_key' => 'SECURE-001',
            ],
        ]);

        $payload = [
            'business_id' => $business->id,
            'event_type' => OperationalEvent::ORDER_CREATED,
            'external_id' => 'SECURE-ORDER-3',
            'quantity' => 1,
        ];

        $canonical = 'POST|api/v1/stock-app/webhook||'.json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $canonical, 'signature-secret-123');

        $this->postJson('/api/v1/stock-app/webhook', $payload, [
            'X-HELOS-SIGNATURE' => $signature,
        ])->assertCreated();

        $this->assertSame(1, OperationalEvent::query()->count());
    }
}

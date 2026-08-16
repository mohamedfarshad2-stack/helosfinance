<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\ServiceClient;
use App\Domains\Shared\Services\StockAppClientSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockAppClientSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_syncs_live_stock_app_clients_and_pricing_rows_into_helo_service_data(): void
    {
        Carbon::setTestNow('2026-08-16 12:00:00');

        $business = Business::query()->create(['name' => 'Horns England']);

        $loginHtml = <<<'HTML'
<html><head>
<meta name="csrf-token" content="csrf-token-123">
<form wire:id="login-1" wire:initial-data="{&quot;fingerprint&quot;:{&quot;id&quot;:&quot;login-1&quot;,&quot;name&quot;:&quot;filament.core.auth.login&quot;,&quot;locale&quot;:&quot;en&quot;,&quot;path&quot;:&quot;admin/login&quot;,&quot;method&quot;:&quot;GET&quot;,&quot;v&quot;:&quot;acj&quot;},&quot;effects&quot;:{&quot;listeners&quot;:[]},&quot;serverMemo&quot;:{&quot;children&quot;:[],&quot;errors&quot;:[],&quot;htmlHash&quot;:&quot;abc&quot;,&quot;data&quot;:{&quot;email&quot;:null,&quot;password&quot;:null,&quot;remember&quot;:false},&quot;dataMeta&quot;:[],&quot;checksum&quot;:&quot;abc&quot;}}"></form>
</head><body>login</body></html>
HTML;

        $clientsHtml = <<<'HTML'
<table><tbody>
<tr>
    <td class="filament-tables-checkbox-cell"></td>
    <td class="filament-tables-cell filament-table-cell-name"><span>Hot Dropz</span></td>
    <td class="filament-tables-cell filament-table-cell-code"><span>HD-001</span></td>
    <td class="filament-tables-cell filament-table-cell-contact-name"><span>Mr. Hot</span></td>
    <td class="filament-tables-cell filament-table-cell-package-type"><span>starter</span></td>
    <td class="filament-tables-cell filament-table-cell-status"><span>active</span></td>
    <td class="filament-tables-cell filament-table-cell-users-count"><span>2</span></td>
    <td class="filament-tables-cell filament-table-cell-whatsapp-connects"><span>1</span></td>
    <td class="filament-tables-cell filament-table-cell-has-whatsapp-bulk-center"><span>Yes</span></td>
    <td class="filament-tables-cell filament-table-cell-helos-finance-enabled"><span>Yes</span></td>
    <td class="filament-tables-cell filament-table-cell-has-courier-sync"><span>Yes</span></td>
    <td class="filament-tables-cell filament-table-cell-orders-count"><span>15</span></td>
    <td class="filament-tables-cell filament-table-cell-created-at"><span>May 4, 2026 00:16:15</span></td>
</tr>
<tr>
    <td class="filament-tables-checkbox-cell"></td>
    <td class="filament-tables-cell filament-table-cell-name"><span>Ynox Store</span></td>
    <td class="filament-tables-cell filament-table-cell-code"><span>YN-002</span></td>
    <td class="filament-tables-cell filament-table-cell-contact-name"><span>Ms. Yin</span></td>
    <td class="filament-tables-cell filament-table-cell-package-type"><span>starter</span></td>
    <td class="filament-tables-cell filament-table-cell-status"><span>active</span></td>
    <td class="filament-tables-cell filament-table-cell-users-count"><span>1</span></td>
    <td class="filament-tables-cell filament-table-cell-whatsapp-connects"><span>0</span></td>
    <td class="filament-tables-cell filament-table-cell-has-whatsapp-bulk-center"><span>No</span></td>
    <td class="filament-tables-cell filament-table-cell-helos-finance-enabled"><span>No</span></td>
    <td class="filament-tables-cell filament-table-cell-has-courier-sync"><span>No</span></td>
    <td class="filament-tables-cell filament-table-cell-orders-count"><span>8</span></td>
    <td class="filament-tables-cell filament-table-cell-created-at"><span>May 8, 2026 16:55:11</span></td>
</tr>
</tbody></table>
HTML;

        $pricingHtml = <<<'HTML'
<table><tbody>
<tr>
    <td class="filament-tables-checkbox-cell"></td>
    <td class="filament-tables-cell filament-table-cell-client.name"><span>Hot Dropz</span></td>
    <td class="filament-tables-cell filament-table-cell-monthly-fee"><span>0.00 ₨</span></td>
    <td class="filament-tables-cell filament-table-cell-confirmation-call-rate"><span>10.00 ₨</span></td>
    <td class="filament-tables-cell filament-table-cell-return-handling-rate"><span>0.00 ₨</span></td>
    <td class="filament-tables-cell filament-table-cell-tamil-call-rate"><span>15.00 ₨</span></td>
    <td class="filament-tables-cell filament-table-cell-has-confirmation-service"><span>Yes</span></td>
    <td class="filament-tables-cell filament-table-cell-has-return-handling-service"><span>No</span></td>
    <td class="filament-tables-cell filament-table-cell-has-tamil-support"><span>Yes</span></td>
    <td class="filament-tables-cell filament-table-cell-is-active"><span>Yes</span></td>
</tr>
<tr>
    <td class="filament-tables-checkbox-cell"></td>
    <td class="filament-tables-cell filament-table-cell-client.name"><span>Ynox Store</span></td>
    <td class="filament-tables-cell filament-table-cell-monthly-fee"><span>6,000.00 ₨</span></td>
    <td class="filament-tables-cell filament-table-cell-confirmation-call-rate"><span>0.00 ₨</span></td>
    <td class="filament-tables-cell filament-table-cell-return-handling-rate"><span>0.00 ₨</span></td>
    <td class="filament-tables-cell filament-table-cell-tamil-call-rate"><span>0.00 ₨</span></td>
    <td class="filament-tables-cell filament-table-cell-has-confirmation-service"><span>No</span></td>
    <td class="filament-tables-cell filament-table-cell-has-return-handling-service"><span>No</span></td>
    <td class="filament-tables-cell filament-table-cell-has-tamil-support"><span>No</span></td>
    <td class="filament-tables-cell filament-table-cell-is-active"><span>Yes</span></td>
</tr>
</tbody></table>
HTML;

        Http::fake([
            'https://codreturnslanka.lk/admin/login' => Http::response($loginHtml, 200),
            'https://codreturnslanka.lk/livewire/message/filament.core.auth.login' => Http::response(json_encode([
                'effects' => ['html' => null, 'redirect' => 'https://codreturnslanka.lk/admin'],
                'serverMemo' => ['data' => ['email' => 'admin1@gmail.com', 'password' => 'Horns@123']],
            ]), 200),
            'https://codreturnslanka.lk/admin/clients' => Http::response($clientsHtml, 200),
            'https://codreturnslanka.lk/admin/client-pricings' => Http::response($pricingHtml, 200),
        ]);

        $result = app(StockAppClientSyncService::class)->sync($business);

        $this->assertTrue($result['available']);
        $this->assertSame(2, $result['synced_clients']);
        $this->assertSame(2, $result['synced_billing_records']);

        $hotDropz = ServiceClient::query()
            ->where('business_id', $business->id)
            ->where('name', 'Hot Dropz')
            ->firstOrFail();

        $this->assertSame(ServiceClient::STATUS_ACTIVE, $hotDropz->status);
        $this->assertSame('0.00', (string) $hotDropz->default_monthly_amount);

        $ynox = ServiceClient::query()
            ->where('business_id', $business->id)
            ->where('name', 'Ynox Store')
            ->firstOrFail();

        $this->assertSame('6000.00', (string) $ynox->default_monthly_amount);

        $integration = IntegrationSource::query()
            ->where('business_id', $business->id)
            ->where('type', 'stock_app')
            ->firstOrFail();

        $this->assertSame('horns-england', $integration->settings['stock_app_business_key']);

        $billing = ServiceBillingRecord::query()
            ->where('business_id', $business->id)
            ->where('service_client_id', $ynox->id)
            ->firstOrFail();

        $this->assertSame('6000.00', (string) $billing->amount_due);
        $this->assertSame('unpaid', $billing->payment_status);
    }
}

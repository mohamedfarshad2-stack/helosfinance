<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\WholesaleLead;
use App\Domains\Shared\Services\WholesaleLeadSpreadsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WholesaleLeadSpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_wholesale_leads_from_a_sheet(): void
    {
        $business = Business::query()->create([
            'name' => 'ShoeHub Wholesale',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $path = storage_path('app/lead-import-test.csv');
        File::put($path, implode(PHP_EOL, [
            'business_name,customer_name,contact_name,phone,whatsapp_phone,location,source,status,products_of_interest,next_follow_up_at,notes',
            'ShoeHub Wholesale,Lanka Traders,Kasun,0771234567,0771234567,Colombo,whatsapp,lead,Men trainers,2026-08-16,Owner referral lead',
            'ShoeHub Wholesale,Island Footwear,Nimal,0712345678,0712345678,Galle,phone_call,customer,Ladies sandals,2026-08-17,Converted customer',
        ]));

        $result = app(WholesaleLeadSpreadsheetImportService::class)->import($business, $path);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseCount('wholesale_leads', 2);

        $lead = WholesaleLead::query()->where('customer_name', 'Island Footwear')->firstOrFail();
        $this->assertSame('customer', $lead->status);
        $this->assertSame('2026-08-17 00:00:00', optional($lead->next_follow_up_at)->toDateTimeString());

        File::delete($path);
    }
}

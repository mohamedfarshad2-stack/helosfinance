<?php

namespace Tests\Feature;

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\FinancialSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_month_snapshot_updates_the_same_row_instead_of_creating_duplicates(): void
    {
        $business = Business::query()->create([
            'name' => 'Snapshot Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_1,
            'onboarding_status' => 'setup',
        ]);

        $service = app(BusinessHealthSnapshotService::class);

        $first = $service->currentMonth($business);
        $second = $service->currentMonth($business);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FinancialSnapshot::query()->where('business_id', $business->id)->count());
    }
}

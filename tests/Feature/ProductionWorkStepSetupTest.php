<?php

namespace Tests\Feature;

use App\Domains\Manufacturing\Services\ProductionWorkStepSetupService;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ProductionWorkStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionWorkStepSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_common_work_steps_without_creating_duplicates(): void
    {
        $business = Business::query()->create([
            'name' => 'Factory Client',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_MANUFACTURING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $service = app(ProductionWorkStepSetupService::class);

        $first = $service->addCommonSteps($business);
        $second = $service->addCommonSteps($business);

        $this->assertSame(6, $first['created']);
        $this->assertSame(0, $first['updated']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(6, $second['updated']);
        $this->assertDatabaseCount('production_work_steps', 6);
        $this->assertTrue(ProductionWorkStep::query()->where('name', 'Stitching labour')->exists());
        $this->assertTrue(ProductionWorkStep::query()->where('name', 'Top making labour')->exists());
    }
}

<?php

namespace App\Domains\Manufacturing\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ProductionWorkStep;

class ProductionWorkStepSetupService
{
    /**
     * @return array{created:int, updated:int}
     */
    public function addCommonSteps(Business $business): array
    {
        $created = 0;
        $updated = 0;

        foreach ($this->commonStepNames() as $name) {
            $step = ProductionWorkStep::query()->firstOrNew([
                'business_id' => $business->id,
                'name' => $name,
            ]);

            if ($step->exists) {
                $updated++;
            } else {
                $created++;
            }

            $step->fill([
                'unit_cost' => $step->unit_cost ?? 0,
                'active' => true,
            ]);
            $step->save();
        }

        return [
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * @return list<string>
     */
    public function commonStepNames(): array
    {
        return [
            'Cutting labour',
            'Bottom labour',
            'Stitching labour',
            'Top making labour',
            'Finishing labour',
            'Packing labour',
        ];
    }
}

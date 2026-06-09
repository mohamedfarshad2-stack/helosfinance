<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;

class BusinessAdvisorService
{
    public function __construct(private readonly BusinessExplainabilityService $explainability)
    {
    }

    public function forCurrentMonth(Business $business): array
    {
        return $this->explainability->forCurrentMonth($business);
    }
}

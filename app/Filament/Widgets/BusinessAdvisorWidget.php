<?php

namespace App\Filament\Widgets;

use App\Domains\FinancialClarity\Services\BusinessAdvisorService;
use App\Domains\Shared\Models\Business;
use Filament\Widgets\Widget;

class BusinessAdvisorWidget extends Widget
{
    protected static string $view = 'filament.widgets.business-advisor-widget';

    protected int | string | array $columnSpan = 'full';

    public bool $hasBusiness = false;

    public array $insights = [];

    public function mount(BusinessAdvisorService $service): void
    {
        $business = $this->resolveBusiness();

        if (! $business instanceof Business) {
            return;
        }

        $this->hasBusiness = true;
        $this->insights = $service->forCurrentMonth($business);
    }

    private function resolveBusiness(): ?Business
    {
        $user = auth()->user();

        if ($user?->business instanceof Business) {
            return $user->business;
        }

        if ($user?->business_id) {
            return Business::query()->find($user->business_id);
        }

        return null;
    }
}

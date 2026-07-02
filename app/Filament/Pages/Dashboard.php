<?php

namespace App\Filament\Pages;

use App\Filament\Pages\TodaysWork;
use App\Filament\Pages\WebsiteInsights;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $user = Auth::user();

        if ($user?->isStaff()) {
            $this->redirect(TodaysWork::getUrl());

            return;
        }

        if ($user?->isInternalAdmin()) {
            $this->redirect(WebsiteInsights::getUrl());

            return;
        }

        if ($user?->isOwner()) {
            $this->redirect(WebsiteInsights::getUrl());

            return;
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (Auth::user()?->isInternalAdmin());
    }
}

<?php

namespace App\Filament\Pages;

use App\Filament\Pages\ClientHealthReport;
use App\Filament\Pages\ManagerWorkQueue;
use App\Filament\Pages\TodaysWork;
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
            $this->redirect(ManagerWorkQueue::getUrl());

            return;
        }

        if ($user?->isOwner()) {
            $this->redirect(ClientHealthReport::getUrl());

            return;
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->isInternalAdmin() ?? false;
    }
}

<?php

use App\Domains\FinancialClarity\Services\OperationalEventRecalculator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(OperationalEventRecalculator::class)->recalculateStaleProductCostEvents();
    }

    public function down(): void
    {
        //
    }
};

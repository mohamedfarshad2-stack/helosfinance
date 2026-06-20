<?php

namespace Tests\Feature;

use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\QuickExpenseEntry;
use App\Filament\Resources\BankTransactionResource;
use App\Filament\Resources\BusinessResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\MaterialComponentResource;
use App\Filament\Resources\MaterialLedgerResource;
use App\Filament\Resources\OperationalEventResource;
use App\Filament\Resources\ProductionEntryResource;
use App\Filament\Resources\ProductionWorkStepResource;
use App\Filament\Resources\SkuRecipeResource;
use App\Filament\Resources\SkuResource;
use Tests\TestCase;

class NavigationLanguageTest extends TestCase
{
    public function test_navigation_uses_owner_friendly_business_language(): void
    {
        $labels = [
            BusinessResource::getNavigationLabel(),
            EmployeeResource::getNavigationLabel(),
            ExpenseResource::getNavigationLabel(),
            BankTransactionResource::getNavigationLabel(),
            QuickExpenseEntry::getNavigationLabel(),
            BankStatementImport::getNavigationLabel(),
            OperationalEventResource::getNavigationLabel(),
            SkuResource::getNavigationLabel(),
            SkuRecipeResource::getNavigationLabel(),
            MaterialComponentResource::getNavigationLabel(),
            MaterialLedgerResource::getNavigationLabel(),
            ProductionEntryResource::getNavigationLabel(),
            ProductionWorkStepResource::getNavigationLabel(),
        ];

        $this->assertContains('Businesses', $labels);
        $this->assertContains('Staff & Pay', $labels);
        $this->assertContains('Expenses & Payables', $labels);
        $this->assertContains('Bank & Cash Review', $labels);
        $this->assertContains('Add Expense', $labels);
        $this->assertContains('Import Bank Statement', $labels);
        $this->assertContains('Sales & Order Activity', $labels);
        $this->assertContains('Products', $labels);
        $this->assertContains('Product Recipes', $labels);
        $this->assertContains('Raw Materials', $labels);
        $this->assertContains('Material Stock', $labels);
        $this->assertContains('Production Pay', $labels);
        $this->assertContains('Production Work Types', $labels);

        $this->assertNotContains('Operational Events', $labels);
        $this->assertNotContains('Expense Review', $labels);
        $this->assertNotContains('Client Businesses', $labels);
        $this->assertNotContains('Weekly Production Pay', $labels);
    }
}

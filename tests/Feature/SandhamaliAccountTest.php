<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Domains\Shared\Models\ServiceClient;
use App\Domains\Shared\Models\ServiceLead;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ManagerWorkQueue;
use App\Filament\Pages\SandhamaliAccount;
use App\Filament\Pages\TodaysWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SandhamaliAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandhamali_sees_only_the_service_workspace(): void
    {
        $business = Business::query()->create([
            'name' => 'COD Returns Lanka',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_SERVICE,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        ServiceClient::query()->create([
            'business_id' => $business->id,
            'name' => 'Peak Logistics',
            'status' => ServiceClient::STATUS_ACTIVE,
            'billing_style' => ServiceClient::BILLING_FIXED_MONTHLY,
            'default_monthly_amount' => 25000,
            'default_due_day' => 5,
        ]);

        ServiceClient::query()->create([
            'business_id' => $business->id,
            'name' => 'Temporary Manual Client',
            'status' => ServiceClient::STATUS_ACTIVE,
            'billing_style' => ServiceClient::BILLING_FIXED_MONTHLY,
            'default_monthly_amount' => 5000,
            'default_due_day' => 10,
        ]);

        ServiceBillingRecord::query()->create([
            'business_id' => $business->id,
            'client_name' => 'Peak Logistics',
            'billing_type' => ServiceBillingRecord::TYPE_SUBSCRIPTION,
            'amount_due' => 25000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'due_on' => now()->toDateString(),
        ]);

        ServiceLead::query()->create([
            'business_id' => $business->id,
            'prospect_name' => 'Return Care Lanka',
            'contact_person' => 'Nimal',
            'phone' => '0712345678',
            'whatsapp_number' => '0712345678',
            'source' => ServiceLead::SOURCE_RETURN_FOLLOW_UP,
            'status' => ServiceLead::STATUS_LEAD,
            'billing_terms' => ServiceLead::BILLING_MONTH_END,
            'expected_monthly_amount' => 12000,
            'next_follow_up_at' => now()->toDateString(),
        ]);

        $sandhamali = User::query()->create([
            'name' => 'Sandhamali',
            'email' => 'sandhamali@helos.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
            'employee_access_profile' => 'finance_ops',
            'staff_responsibilities' => ['collections', 'expense_recording'],
            'responsibilities_configured' => true,
        ]);

        $this->actingAs($sandhamali);

        $this->get(Dashboard::getUrl())->assertRedirect(SandhamaliAccount::getUrl());
        $this->get(TodaysWork::getUrl())->assertRedirect(SandhamaliAccount::getUrl());
        $this->get(ManagerWorkQueue::getUrl())->assertRedirect(SandhamaliAccount::getUrl());

        $this->get(SandhamaliAccount::getUrl())
            ->assertOk()
            ->assertSee('Sandhamali account')
            ->assertSee('Peak Logistics')
            ->assertSee('Return Care Lanka')
            ->assertDontSee('Temporary Manual Client')
            ->assertDontSee('Arafath parcel command board');

        $this->assertDatabaseHas('service_clients', [
            'business_id' => $business->id,
            'name' => 'Temporary Manual Client',
        ]);
    }
}

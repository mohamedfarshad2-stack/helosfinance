<?php

namespace Tests\Feature;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\WholesaleLead;
use App\Filament\Pages\NifrasAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class NifrasWholesaleLeadDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_nifras_can_save_a_wholesale_lead_and_see_contact_actions(): void
    {
        $business = Business::query()->create([
            'name' => 'ShoeHub Wholesale',
            'currency' => 'LKR',
            'business_type' => Business::TYPE_TRADING,
            'business_maturity' => Business::MATURITY_LEVEL_5,
            'onboarding_status' => 'ready',
        ]);

        $nifras = User::query()->create([
            'name' => 'Nifras',
            'email' => 'nifras@helos.com',
            'password' => Hash::make('password'),
            'business_id' => $business->id,
            'is_employee' => true,
            'is_platform_admin' => false,
        ]);

        Livewire::actingAs($nifras)
            ->test(NifrasAccount::class)
            ->set('leadData', [
                'customer_name' => 'Lanka Traders',
                'contact_name' => 'Kasun',
                'phone' => '0771234567',
                'whatsapp_phone' => '0771234567',
                'location' => 'Colombo',
                'products_of_interest' => 'Men s trainers, size 8 and 9',
                'source' => 'whatsapp',
                'status' => WholesaleLead::STATUS_INTERESTED,
                'next_follow_up_at' => '2026-08-16',
                'notes' => 'Owner passed the lead to Nifras.',
            ])
            ->call('saveWholesaleLead')
            ->assertHasNoFormErrors();

        $lead = WholesaleLead::query()->firstOrFail();

        $this->assertSame($business->id, $lead->business_id);
        $this->assertSame('Lanka Traders', $lead->customer_name);
        $this->assertSame('whatsapp', $lead->source);
        $this->assertSame('interested', $lead->status);
        $this->assertSame('2026-08-16 00:00:00', optional($lead->next_follow_up_at)->toDateTimeString());

        $this->actingAs($nifras)
            ->get(NifrasAccount::getUrl())
            ->assertOk()
            ->assertSee('Lead action queue')
            ->assertSee('Lanka Traders')
            ->assertSee('WhatsApp')
            ->assertSee('Call');
    }
}

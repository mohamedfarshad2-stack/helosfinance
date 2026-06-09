<?php

namespace Database\Seeders;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CostAssumption;
use App\Domains\Shared\Models\Expense;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\FixedExpenseTemplate;
use App\Domains\Shared\Models\IntegrationSource;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\Sku;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $business = Business::query()->firstOrCreate(
            ['name' => 'Demo Operations Business'],
            [
                'currency' => 'LKR',
                'industry' => 'COD retail',
                'business_type' => 'hybrid',
                'primary_business_type' => 'manufacturing',
                'secondary_business_types' => ['trading', 'retail'],
                'business_maturity' => 'level_5',
                'onboarding_status' => 'setup',
                'clarity_started_on' => now()->startOfMonth(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'admin@helos.local'],
            [
                'name' => 'HELOS Admin',
                'password' => Hash::make('password'),
                'business_id' => $business->id,
                'is_platform_admin' => true,
                'is_employee' => false,
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'manager@helos.local'],
            [
                'name' => 'Demo Manager',
                'password' => Hash::make('password'),
                'business_id' => $business->id,
                'is_platform_admin' => false,
                'is_employee' => false,
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'employee@helos.local'],
            [
                'name' => 'Demo Employee',
                'password' => Hash::make('password'),
                'business_id' => $business->id,
                'is_platform_admin' => false,
                'is_employee' => true,
            ]
        );

        foreach ([
            ['rent', 'Rent or workspace cost', 'fixed', 'Operations', 'per month', 'The monthly cost of the shop, warehouse, office, or production space.', 10],
            ['base_salaries', 'Base salaries', 'fixed', 'People', 'per month', 'Any fixed monthly staff payments that happen even when order volume changes.', 20],
            ['electricity', 'Electricity', 'fixed', 'Operations', 'per month', 'Use the regular monthly amount if the bill changes only a little month to month.', 30],
            ['internet_phone', 'Internet and phone', 'fixed', 'Operations', 'per month', 'Internet, office phones, business mobile packages, and call packages.', 40],
            ['software_tools', 'Software and tools', 'fixed', 'Operations', 'per month', 'Monthly software, hosting, subscriptions, or system fees.', 50],
            ['vehicle_warehouse', 'Vehicle or warehouse fixed cost', 'fixed', 'Dispatch', 'per month', 'Fixed vehicle lease, warehouse fee, parking, or maintenance baseline.', 60],
            ['loan_repayments', 'Loan repayments', 'fixed', 'Owner', 'per month', 'Regular business loan or equipment repayment that creates monthly pressure.', 70],
            ['owner_drawings', 'Owner drawings', 'fixed', 'Owner', 'per month', 'Money the owner regularly takes from the business and wants visible in clarity.', 80],
            ['courier_delivery', 'Courier delivery cost', 'variable', 'Dispatch', 'per delivered order', 'Cost that changes with delivered orders or courier usage.', 110],
            ['courier_return', 'Courier return cost', 'variable', 'Dispatch', 'per returned order', 'Cost created when an order comes back.', 120],
            ['packaging_usage', 'Packaging usage', 'variable', 'Packing', 'per order', 'Bags, boxes, labels, tape, and other packing materials used per order.', 130],
            ['fuel_dispatch', 'Fuel for dispatch', 'variable', 'Dispatch', 'per trip or day', 'Fuel cost that changes with delivery or pickup activity.', 140],
            ['sales_commission', 'Sales commission', 'variable', 'Sales', 'per sale or percentage', 'Commission paid only when sales happen.', 150],
            ['production_piece_labor', 'Production piece labor', 'variable', 'Manufacturing', 'per SKU produced', 'Labor paid by item or SKU performance instead of fixed salary.', 160],
            ['payment_gateway', 'Payment or COD handling fee', 'variable', 'Payments', 'per order or percentage', 'Gateway, COD collection, bank, or payment handling fee.', 170],
        ] as [$key, $label, $type, $department, $basis, $hint, $order]) {
            FixedExpenseTemplate::query()->updateOrCreate(
                ['key' => $key],
                [
                    'label' => $label,
                    'expense_type' => $type,
                    'department' => $department,
                    'basis' => $basis,
                    'plain_hint' => $hint,
                    'common_for_most_businesses' => true,
                    'sort_order' => $order,
                ]
            );
        }

        $classic = Sku::query()->updateOrCreate(
            ['business_id' => $business->id, 'code' => 'BAG-CLASSIC'],
            [
                'name' => 'Classic Bag',
                'material_cost' => 950,
                'packaging_cost' => 120,
                'labor_rate' => 280,
                'finishing_cost' => 150,
                'expected_sale_price' => 3200,
            ]
        );

        $premium = Sku::query()->updateOrCreate(
            ['business_id' => $business->id, 'code' => 'BAG-PREMIUM'],
            [
                'name' => 'Premium Bag',
                'material_cost' => 1450,
                'packaging_cost' => 160,
                'labor_rate' => 420,
                'finishing_cost' => 260,
                'expected_sale_price' => 5200,
            ]
        );

        foreach ([
            ['delivery_fee', 'Delivery cost', 360],
            ['return_courier_fee', 'Return courier cost', 260],
            ['return_packaging_fee', 'Return packaging cost', 160],
            ['return_fee', 'Return cost', 420],
            ['resend_courier_fee', 'Resend courier cost', 180],
            ['resend_packaging_fee', 'Resend packaging cost', 120],
            ['resend_fee', 'Resend retry cost', 300],
            ['verification_cost', 'Verification cost', 65],
        ] as [$key, $label, $amount]) {
            CostAssumption::query()->updateOrCreate(
                ['business_id' => $business->id, 'key' => $key],
                ['label' => $label, 'amount' => $amount, 'behavior' => 'per_event']
            );
        }

        foreach ([
            [
                'business_id' => $business->id,
                'sku_id' => $classic->id,
                'source' => 'demo',
                'event_type' => OperationalEvent::ORDER_CONFIRMED,
                'external_id' => 'STOCK-1001',
                'channel' => 'COD',
                'department' => 'Customer Operations',
                'quantity' => 1,
                'revenue_amount' => 0,
                'direct_cost_amount' => 0,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'occurred_at' => now()->subDays(3),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_id' => $business->id,
                'sku_id' => $classic->id,
                'source' => 'demo',
                'event_type' => OperationalEvent::TRACKING_NUMBER_ADDED,
                'external_id' => 'STOCK-1001',
                'channel' => 'COD',
                'department' => 'Dispatch',
                'quantity' => 1,
                'revenue_amount' => 0,
                'direct_cost_amount' => 1860,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'occurred_at' => now()->subDays(2),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_id' => $business->id,
                'sku_id' => $classic->id,
                'source' => 'demo',
                'event_type' => OperationalEvent::ORDER_DELIVERED,
                'external_id' => 'STOCK-1001',
                'channel' => 'COD',
                'department' => 'Dispatch',
                'quantity' => 1,
                'revenue_amount' => 3200,
                'direct_cost_amount' => 0,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'occurred_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_id' => $business->id,
                'sku_id' => $premium->id,
                'source' => 'demo',
                'event_type' => OperationalEvent::ORDER_RETURNED,
                'external_id' => 'STOCK-1002',
                'channel' => 'COD',
                'department' => 'Courier',
                'quantity' => 1,
                'revenue_amount' => 0,
                'direct_cost_amount' => 0,
                'leakage_amount' => 420,
                'recovery_amount' => 0,
                'occurred_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_id' => $business->id,
                'sku_id' => $classic->id,
                'source' => 'demo',
                'event_type' => OperationalEvent::ORDER_RESENT,
                'external_id' => 'STOCK-1003',
                'channel' => 'COD',
                'department' => 'Customer Operations',
                'quantity' => 1,
                'revenue_amount' => 0,
                'direct_cost_amount' => 300,
                'leakage_amount' => 0,
                'recovery_amount' => 0,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ] as $event) {
            OperationalEvent::query()->updateOrCreate(
                ['business_id' => $business->id, 'external_id' => $event['external_id']],
                $event
            );
        }

        Expense::query()->updateOrCreate(
            ['business_id' => $business->id, 'category' => 'Rent', 'spent_on' => now()->startOfMonth()->toDateString()],
            [
                'department' => 'Operations',
                'expense_type' => 'fixed',
                'suggested_key' => 'rent',
                'description' => 'Monthly operating space',
                'amount' => 45000,
                'recurring' => true,
            ]
        );

        IntegrationSource::query()->updateOrCreate(
            ['business_id' => $business->id, 'name' => 'Local stock-app'],
            ['type' => 'stock_app', 'base_url' => 'http://127.0.0.1:8000', 'status' => 'testing']
        );

        foreach ([
            ['name' => 'Kamal Perera', 'role' => 'Supervisor', 'monthly_salary' => 55000],
            ['name' => 'Nimal Silva', 'role' => 'Production helper', 'monthly_salary' => 42000],
            ['name' => 'Saman Jayasuriya', 'role' => 'Packing and dispatch', 'monthly_salary' => 48000],
        ] as $employee) {
            Employee::query()->updateOrCreate(
                ['business_id' => $business->id, 'name' => $employee['name']],
                [
                    'role' => $employee['role'],
                    'monthly_salary' => $employee['monthly_salary'],
                    'pay_cycle' => 'month_end',
                    'active' => true,
                ]
            );
        }
    }
}

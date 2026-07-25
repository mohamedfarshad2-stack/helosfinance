<?php

namespace App\Domains\Shared\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use Illuminate\Support\Str;

class StockAppEmployeeDiscoveryService
{
    public function discover(Business $business, array $payload): ?Employee
    {
        $name = Str::squish((string) ($payload['csr_employee'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255 || preg_match('/^\d+$/', $name) === 1) {
            return null;
        }

        $employee = Employee::query()
            ->where('business_id', $business->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($employee) {
            if (! $employee->active) {
                $employee->forceFill(['active' => true])->save();
            }

            return $employee;
        }

        return Employee::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'role' => 'CSR',
            'monthly_salary' => 0,
            'pay_cycle' => 'month_end',
            'active' => true,
            'note' => 'Automatically discovered from a Stock App order. Salary and login access still require owner confirmation.',
        ]);
    }
}

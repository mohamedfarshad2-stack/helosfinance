<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\CodOrder;
use App\Domains\Shared\Models\CourierRate;

class CourierRateService
{
    public function optionsForBusiness(?int $businessId): array
    {
        if (! $businessId) {
            return [];
        }

        return CourierRate::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('courier_name')
            ->pluck('courier_name', 'courier_name')
            ->all();
    }

    public function rateForBusiness(?int $businessId, ?string $courierName): ?CourierRate
    {
        if (! $businessId || blank($courierName)) {
            return null;
        }

        return CourierRate::query()
            ->where('business_id', $businessId)
            ->where('courier_name', $courierName)
            ->where('active', true)
            ->first();
    }

    public function defaultRateForBusiness(?int $businessId): ?CourierRate
    {
        if (! $businessId) {
            return null;
        }

        return CourierRate::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('courier_name')
            ->first();
    }

    public function applyDefaultToOrder(CodOrder $order): CodOrder
    {
        if (filled($order->courier_name) || (float) $order->delivery_charge > 0) {
            return $order;
        }

        $rate = $this->defaultRateForBusiness($order->business_id);

        if (! $rate instanceof CourierRate) {
            return $order;
        }

        return $this->applyToOrder($order, $rate->courier_name);
    }

    public function applyToOrder(CodOrder $order, ?string $courierName): CodOrder
    {
        $rate = $this->rateForBusiness($order->business_id, $courierName);

        if (! $rate instanceof CourierRate) {
            $order->forceFill(['courier_name' => $courierName])->save();

            return $order;
        }

        $order->forceFill([
            'courier_name' => $rate->courier_name,
            'delivery_charge' => $rate->delivery_charge,
            'return_charge' => $rate->return_charge,
            'resend_charge' => $rate->resend_charge,
        ])->save();

        return $order;
    }
}

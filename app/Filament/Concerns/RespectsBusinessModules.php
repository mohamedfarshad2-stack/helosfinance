<?php

namespace App\Filament\Concerns;

use App\Domains\Shared\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait RespectsBusinessModules
{
    private static function hasAccessibleBusinessMatching(callable $callback): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return Business::query()
            ->whereIn('id', $user->accessibleBusinessIds())
            ->get()
            ->contains(fn (Business $business): bool => (bool) $callback($business));
    }

    private static function businessOptionsMatching(callable $callback): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! ($user?->seesAllBusinesses() ?? false), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->orderBy('name')
            ->get()
            ->filter(fn (Business $business): bool => (bool) $callback($business))
            ->pluck('name', 'id')
            ->all();
    }

    private static function scopeToAccessibleBusinessesMatching(Builder $query, callable $callback): Builder
    {
        $ids = Business::query()
            ->whereIn('id', Auth::user()?->accessibleBusinessIds() ?? [])
            ->get()
            ->filter(fn (Business $business): bool => (bool) $callback($business))
            ->pluck('id')
            ->all();

        return $query->whereIn('business_id', $ids);
    }
}

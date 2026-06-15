<?php

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ClientGroup;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Business::query()
            ->whereNull('client_group_id')
            ->orderBy('id')
            ->each(function (Business $business): void {
                $clientGroup = ClientGroup::query()->firstOrCreate(
                    ['name' => $business->name],
                    ['note' => 'Created automatically for existing client businesses.'],
                );

                $business->forceFill(['client_group_id' => $clientGroup->id])->save();
            });

        User::query()
            ->whereNull('client_group_id')
            ->whereNotNull('business_id')
            ->where('is_platform_admin', false)
            ->orderBy('id')
            ->each(function (User $user): void {
                $clientGroupId = Business::query()
                    ->whereKey($user->business_id)
                    ->value('client_group_id');

                if ($clientGroupId) {
                    $user->forceFill(['client_group_id' => $clientGroupId])->save();
                }
            });
    }

    public function down(): void
    {
        User::query()->whereNotNull('client_group_id')->update(['client_group_id' => null]);
        Business::query()->whereNotNull('client_group_id')->update(['client_group_id' => null]);

        ClientGroup::query()
            ->where('note', 'Created automatically for existing client businesses.')
            ->delete();
    }
};

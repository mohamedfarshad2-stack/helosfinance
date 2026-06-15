<?php

namespace App\Domains\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientGroup extends Model
{
    protected $fillable = [
        'name',
        'note',
    ];

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

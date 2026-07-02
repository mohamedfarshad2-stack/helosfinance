<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteAnalyticsEvent extends Model
{
    protected $fillable = [
        'site_key',
        'visitor_key',
        'session_key',
        'event_type',
        'label',
        'page_url',
        'page_path',
        'referrer_url',
        'selector',
        'device_type',
        'country_code',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}

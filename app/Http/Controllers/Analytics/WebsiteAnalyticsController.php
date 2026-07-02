<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Shared\Models\WebsiteAnalyticsEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebsiteAnalyticsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_key' => ['nullable', 'string', 'max:80'],
            'visitor_key' => ['required', 'string', 'max:80'],
            'session_key' => ['required', 'string', 'max:80'],
            'event_type' => ['required', 'string', 'max:50'],
            'label' => ['nullable', 'string', 'max:255'],
            'page_url' => ['nullable', 'string'],
            'page_path' => ['nullable', 'string', 'max:255'],
            'referrer_url' => ['nullable', 'string'],
            'selector' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'max:8'],
            'metadata' => ['nullable', 'array'],
        ]);

        WebsiteAnalyticsEvent::query()->create([
            'site_key' => $data['site_key'] ?? config('app.name', 'site'),
            'visitor_key' => $data['visitor_key'],
            'session_key' => $data['session_key'],
            'event_type' => $data['event_type'],
            'label' => $data['label'] ?? null,
            'page_url' => $data['page_url'] ?? null,
            'page_path' => $data['page_path'] ?? null,
            'referrer_url' => $data['referrer_url'] ?? null,
            'selector' => $data['selector'] ?? null,
            'device_type' => $data['device_type'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'occurred_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function script(Request $request)
    {
        $payload = [
            'endpoint' => url('/api/v1/analytics/events'),
            'siteKey' => config('analytics.site_key', config('app.name', 'site')),
        ];

        $javascript = <<<'JS'
(function () {
    const config = __CONFIG__;
    const storage = {
        visitor: 'he_visitor_key',
        session: 'he_session_key',
    };

    function uuid() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID().replace(/-/g, '');
        }

        return Math.random().toString(36).slice(2) + Date.now().toString(36);
    }

    function getOrCreateKey(name) {
        try {
            const existing = window.localStorage.getItem(name);

            if (existing) {
                return existing;
            }

            const fresh = uuid();
            window.localStorage.setItem(name, fresh);

            return fresh;
        } catch (error) {
            return uuid();
        }
    }

    function deviceType() {
        const width = window.innerWidth || document.documentElement.clientWidth || 0;

        if (width < 768) return 'mobile';
        if (width < 1024) return 'tablet';

        return 'desktop';
    }

    function selectorFor(el) {
        if (! el || ! el.tagName) return null;

        if (el.id) {
            return '#' + el.id;
        }

        const parts = [el.tagName.toLowerCase()];

        if (el.classList && el.classList.length) {
            parts.push('.' + Array.from(el.classList).slice(0, 3).join('.'));
        }

        return parts.join('');
    }

    function cleanLabel(value) {
        return String(value || '').replace(/\s+/g, ' ').trim().slice(0, 255);
    }

    function buildLabel(el) {
        if (! el) return 'unknown';

        return cleanLabel(
            el.getAttribute('data-track-label')
            || el.getAttribute('aria-label')
            || el.getAttribute('title')
            || el.textContent
            || el.tagName
        ) || 'unknown';
    }

    function send(eventType, details) {
        const body = JSON.stringify({
            site_key: config.siteKey,
            visitor_key: getOrCreateKey(storage.visitor),
            session_key: getOrCreateKey(storage.session),
            event_type: eventType,
            page_url: window.location.href,
            page_path: window.location.pathname,
            referrer_url: document.referrer || null,
            device_type: deviceType(),
            ...details,
        });

        if (navigator.sendBeacon) {
            const blob = new Blob([body], { type: 'application/json' });
            navigator.sendBeacon(config.endpoint, blob);
            return;
        }

        fetch(config.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body,
            keepalive: true,
            credentials: 'omit',
        }).catch(() => {});
    }

    function trackVisit() {
        send('visit', {
            label: document.title || 'page view',
        });
    }

    function trackClick(target) {
        const interactive = target.closest('[data-track-click], a, button, [role="button"], input[type="submit"], input[type="button"]');

        if (! interactive) return;

        send('click', {
            label: buildLabel(interactive),
            selector: selectorFor(interactive),
            metadata: {
                href: interactive.getAttribute('href'),
                track_area: interactive.getAttribute('data-track-area'),
                tag: interactive.tagName.toLowerCase(),
            },
        });
    }

    function trackCheckout(eventName, extra) {
        send(eventName, extra || {});
    }

    window.hornsAnalytics = {
        track: send,
        trackVisit,
        trackClick,
        trackCheckout,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackVisit, { once: true });
    } else {
        trackVisit();
    }

    document.addEventListener('click', function (event) {
        trackClick(event.target);
    }, true);
})();
JS;

        $javascript = str_replace('__CONFIG__', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $javascript);

        return response($javascript, 200)->header('Content-Type', 'application/javascript; charset=UTF-8');
    }
}

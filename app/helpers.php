<?php

use App\Services\SettingsService;

if (! function_exists('setting')) {
    /**
     * A business setting, e.g. setting('business_phone'). Cached until saved again.
     */
    function setting(string $key, ?string $default = null): ?string
    {
        return app(SettingsService::class)->get($key, $default);
    }
}

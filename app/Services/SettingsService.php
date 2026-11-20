<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Business settings, cached until they are saved again.
 */
class SettingsService
{
    private const CACHE_KEY = 'settings';

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());
    }

    /**
     * Save several settings at once and forget the cached copy.
     *
     * @param  array<string, string|null>  $values
     */
    public function save(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => filled($value) ? $value : null]);
            }
        });

        Cache::forget(self::CACHE_KEY);
    }
}

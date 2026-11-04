<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Typed access to the `settings` table. Every known key and its default value
 * is declared in config('mouhim.settings'); unknown keys are rejected so a
 * typo can never silently create a new setting.
 */
class SettingsService
{
    private const CACHE_KEY = 'mouhim.settings';

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());

        return array_merge($this->defaults(), array_intersect_key($stored, $this->defaults()));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKnown($key);

        return $this->all()[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, ?User $by = null): void
    {
        foreach (array_keys($values) as $key) {
            $this->assertKnown($key);
        }

        $before = $this->all();

        DB::transaction(function () use ($values, $by) {
            foreach ($values as $key => $value) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
            }
        });

        Cache::forget(self::CACHE_KEY);

        $changed = array_filter($values, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);

        if ($changed !== []) {
            activity('settings')
                ->causedBy($by)
                ->event('settings_updated')
                ->withProperties([
                    'old' => array_intersect_key($before, $changed),
                    'attributes' => $changed,
                ])
                ->log('settings_updated');
        }
    }

    public function set(string $key, mixed $value, ?User $by = null): void
    {
        $this->setMany([$key => $value], $by);
    }

    /** VAT rate in basis points (2000 = 20 %). */
    public function vatRateBasisPoints(): int
    {
        return (int) $this->get('tax.vat_rate_bp');
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return config('mouhim.settings');
    }

    private function assertKnown(string $key): void
    {
        if (! array_key_exists($key, $this->defaults())) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }
    }
}

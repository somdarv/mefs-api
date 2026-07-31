<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime settings (brief §5.2). Service charge, delivery fee, cutoff defaults.
 *
 * These are settings and not config because she changes them, and a deploy is not an
 * acceptable way to turn off a service charge.
 *
 * **Values are typed.** A bare string store is a bug waiting to happen: `false` round-trips
 * as `"false"`, which is truthy, so a disabled service charge quietly applies. The `cast`
 * column is what stops that.
 */
class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'cast', 'group', 'description', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'bool'];
    }

    private const CACHE_KEY = 'system_settings.all';

    /** Typed value for one key, or `$default` when it is not set. */
    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_cached()[$key] ?? $default;
    }

    /**
     * Every setting the public may see, for the `checkout-config` endpoint.
     *
     * Reads `is_public`, which defaults to **false** — a new setting is private until
     * someone deliberately opens it, so adding one can never accidentally leak.
     *
     * @return array<string, mixed>
     */
    public static function publicValues(): array
    {
        return static::query()
            ->where('is_public', true)
            ->get()
            ->mapWithKeys(fn (self $s) => [$s->key => $s->typedValue()])
            ->all();
    }

    public static function put(string $key, mixed $value): void
    {
        $setting = static::query()->where('key', $key)->firstOrFail();

        $setting->value = $setting->cast === 'json'
            ? json_encode($value)
            : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

        $setting->save();

        static::flush();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private static function all_cached(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->get()->mapWithKeys(
                fn (self $s) => [$s->key => $s->typedValue()],
            )->all(),
        );
    }

    public function typedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->cast) {
            'int' => (int) $this->value,
            // Only "1" and "true" are true. Everything else — including the string
            // "false", which a naive cast would read as truthy — is false.
            'bool' => in_array(strtolower($this->value), ['1', 'true'], true),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }
}

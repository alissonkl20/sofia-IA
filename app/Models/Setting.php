<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("setting.{$key}", 3600, function () use ($key, $default) {
            $setting = static::query()->find($key);

            if (! $setting) {
                return $default;
            }

            $decoded = json_decode($setting->value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $setting->value;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        $stored = is_string($value) ? $value : json_encode($value);

        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored],
        );

        Cache::forget("setting.{$key}");
    }
}

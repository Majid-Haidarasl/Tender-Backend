<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Str;

class SystemSettingsService
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $setting = SystemSetting::where('key', $key)->first();
        $value = $setting ? $setting->value : $default;
        self::$cache[$key] = $value;

        return $value;
    }

    public static function set(string $key, mixed $value, string $group = 'general', ?string $updatedBy = null, ?string $description = null): SystemSetting
    {
        $setting = SystemSetting::firstOrNew(['key' => $key]);
        if (!$setting->exists) {
            $setting->id = (string) Str::uuid();
        }
        $setting->fill([
            'value' => $value,
            'group' => $group,
            'description' => $description,
            'updated_by' => $updatedBy,
        ]);
        $setting->save();

        self::$cache[$key] = $value;

        return $setting;
    }

    public static function allGrouped(): array
    {
        return SystemSetting::orderBy('group')->orderBy('key')
            ->get()
            ->groupBy('group')
            ->map(fn ($items) => $items->mapWithKeys(fn ($s) => [$s->key => $s->value]))
            ->toArray();
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}

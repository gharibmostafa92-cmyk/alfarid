<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

class SettingService
{
    private const CACHE_KEY = 'app_settings';

    /**
     * Return all settings grouped by their group name, with values cast to their declared types.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllGrouped(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return Setting::query()
                ->orderBy('group')
                ->orderBy('key')
                ->get()
                ->groupBy('group')
                ->map(fn ($settings): array => $settings
                    ->mapWithKeys(fn (Setting $setting): array => [
                        $setting->key => $this->castValue($setting->value, $setting->type),
                    ])
                    ->all())
                ->all();
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        foreach ($this->getAllGrouped() as $settings) {
            if (array_key_exists($key, $settings)) {
                return $settings[$key];
            }
        }

        return $default;
    }

    /**
     * Update a flat key/value map atomically and invalidate the cached settings afterward.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws JsonException
     */
    public function updateBatch(array $settings): void
    {
        DB::transaction(function () use ($settings): void {
            foreach ($settings as $key => $value) {
                $setting = Setting::query()
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();

                if (! $setting instanceof Setting) {
                    throw new InvalidArgumentException("Unknown setting [{$key}].");
                }

                $setting->value = $this->serializeValue($value, $setting->type);
                $setting->save();
            }
        }, attempts: 3);

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Atomically return and increment the next document number for the requested type.
     *
     * @throws JsonException
     */
    public function generateNextNumber(string $type): string
    {
        $definitions = [
            'invoice' => ['prefix' => 'invoice_prefix', 'next' => 'invoice_next_number', 'padding' => 'invoice_padding'],
            'order' => ['prefix' => 'order_prefix', 'next' => 'order_next_number', 'padding' => 'order_padding'],
            'purchase_order' => ['prefix' => 'purchase_order_prefix', 'next' => 'purchase_order_next_number', 'padding' => null],
            'quotation' => ['prefix' => 'quotation_prefix', 'next' => 'quotation_next_number', 'padding' => null],
        ];

        if (! isset($definitions[$type])) {
            throw new InvalidArgumentException("Unsupported numbering type [{$type}].");
        }

        $definition = $definitions[$type];
        $keys = array_values(array_filter([
            $definition['prefix'],
            $definition['next'],
            $definition['padding'],
        ]));

        $number = DB::transaction(function () use ($definition, $keys): string {
            $settings = Setting::query()
                ->whereIn('key', $keys)
                ->orderBy('key')
                ->lockForUpdate()
                ->get()
                ->keyBy('key');

            foreach ($keys as $key) {
                if (! $settings->has($key)) {
                    throw new InvalidArgumentException("Required numbering setting [{$key}] is missing.");
                }
            }

            $prefix = (string) $settings->get($definition['prefix'])->value;
            $nextSetting = $settings->get($definition['next']);
            $nextValue = (int) $nextSetting->value;
            $padding = $definition['padding'] === null
                ? 0
                : max(0, (int) $settings->get($definition['padding'])->value);

            $formatted = $prefix.str_pad((string) $nextValue, $padding, '0', STR_PAD_LEFT);
            $nextSetting->value = (string) ($nextValue + 1);
            $nextSetting->save();

            return $formatted;
        }, attempts: 3);

        Cache::forget(self::CACHE_KEY);

        return $number;
    }

    private function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    /** @throws JsonException */
    private function serializeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($type === 'json') {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if ($type === 'boolean') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return (string) $value;
    }
}

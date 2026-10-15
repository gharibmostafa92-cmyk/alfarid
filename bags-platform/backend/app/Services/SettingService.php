<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

class SettingService
{
    /** @var array<string, array{0: string, 1: int}> بادئة وحشو الترقيم لكل نوع — يُقرآن مرة واحدة لكل طلب (M11). */
    private array $numberingConfig = [];

    private const CACHE_KEY = 'app_settings_static';

    private const CACHE_TTL_SECONDS = 3600;

    /** @var list<string> */
    private const NUMBERING_COUNTER_KEYS = [
        'invoice_next_number',
        'order_next_number',
        'purchase_order_next_number',
        'quotation_next_number',
        'movement_next_number',
        'transfer_next_number',
        'stocktake_next_number',
    ];

    /**
     * Return all settings grouped by their group name, with values cast to their declared types.
     * Numbering counters are read fresh because they change for every generated document number.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllGrouped(): array
    {
        $grouped = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return Setting::query()
                ->whereNotIn('key', self::NUMBERING_COUNTER_KEYS)
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

        $counters = Setting::query()
            ->whereIn('key', self::NUMBERING_COUNTER_KEYS)
            ->orderBy('key')
            ->get()
            ->mapWithKeys(fn (Setting $setting): array => [
                $setting->key => $this->castValue($setting->value, $setting->type),
            ])
            ->all();

        if (isset($counters['movement_next_number'])) {
            $counters['movement_next_number'] = $this->nextMovementNumber();
        }

        if ($counters !== []) {
            $grouped['numbering'] = array_merge($grouped['numbering'] ?? [], $counters);
            ksort($grouped['numbering']);
        }

        return $grouped;
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
     * Update a flat key/value map atomically and invalidate the cached settings after commit.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws JsonException
     */
    public function updateBatch(array $settings): void
    {
        DB::transaction(function () use ($settings): void {
            $lockedSettings = Setting::query()
                ->whereIn('key', array_keys($settings))
                ->orderBy('key')
                ->lockForUpdate()
                ->get()
                ->keyBy('key');

            $missingKeys = array_diff(array_keys($settings), $lockedSettings->keys()->all());

            if ($missingKeys !== []) {
                throw new InvalidArgumentException('Settings are not initialized: '.implode(', ', $missingKeys));
            }

            foreach ($settings as $key => $value) {
                /** @var Setting $setting */
                $setting = $lockedSettings->get($key);

                if (in_array($key, self::NUMBERING_COUNTER_KEYS, true)) {
                    $value = max($this->counterValue($setting->value), $this->counterValue($value));
                    if ($key === 'movement_next_number') {
                        // Configuration changes are rare; lock the sequence only here, never per movement.
                        DB::statement('ALTER SEQUENCE stock_movement_number_seq INCREMENT BY 1');
                        $value = max($value, $this->nextMovementNumber());
                        DB::statement('ALTER SEQUENCE stock_movement_number_seq RESTART WITH '.$value);
                    }
                }

                $setting->value = $value === null && str_ends_with($key, '_prefix')
                    ? ''
                    : $this->serializeValue($value, $setting->type);
                $setting->save();
            }

            DB::afterCommit(fn () => $this->clearCache());
        }, attempts: 3);
    }

    /**
     * Atomically return and increment the next document number for the requested type.
     *
     * @throws JsonException
     */
    public function generateNextNumber(string $type): string
    {
        if ($type === 'movement') {
            [$prefix, $padding] = $this->numberingConfig('movement', 'movement_prefix', 'movement_padding');
            $number = DB::selectOne("SELECT nextval('stock_movement_number_seq') AS number")->number;

            return $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
        }
        $definitions = [
            'invoice' => ['prefix' => 'invoice_prefix', 'next' => 'invoice_next_number', 'padding' => 'invoice_padding'],
            'order' => ['prefix' => 'order_prefix', 'next' => 'order_next_number', 'padding' => 'order_padding'],
            'purchase_order' => ['prefix' => 'purchase_order_prefix', 'next' => 'purchase_order_next_number', 'padding' => 'purchase_order_padding'],
            'quotation' => ['prefix' => 'quotation_prefix', 'next' => 'quotation_next_number', 'padding' => 'quotation_padding'],
            'transfer' => ['prefix' => 'transfer_prefix', 'next' => 'transfer_next_number', 'padding' => 'transfer_padding'],
            'stocktake' => ['prefix' => 'stocktake_prefix', 'next' => 'stocktake_next_number', 'padding' => 'stocktake_padding'],
        ];

        if (! isset($definitions[$type])) {
            throw new InvalidArgumentException("Unsupported numbering type [{$type}].");
        }

        $definition = $definitions[$type];
        [$prefix, $padding] = $this->numberingConfig($type, $definition['prefix'], $definition['padding']);

        return DB::transaction(function () use ($definition, $prefix, $padding): string {
            // العداد وحده يُقفل ويُقرأ طازجًا كل مرة؛ البادئة والحشو من ذاكرة الطلب.
            $nextSetting = Setting::query()
                ->where('key', $definition['next'])
                ->lockForUpdate()
                ->first();

            if ($nextSetting === null) {
                throw new InvalidArgumentException("Required numbering setting [{$definition['next']}] is missing.");
            }

            $nextValue = $this->counterValue($nextSetting->value);

            $formatted = $prefix.str_pad((string) $nextValue, $padding, '0', STR_PAD_LEFT);
            $nextSetting->value = (string) ($nextValue + 1);
            $nextSetting->save();

            return $formatted;
        }, attempts: 3);
    }

    /**
     * بادئة وحشو نوع ترقيم — قراءة واحدة لكل طلب بدل استعلام مع كل حركة/مستند.
     *
     * @return array{0: string, 1: int}
     */
    private function numberingConfig(string $type, string $prefixKey, string $paddingKey): array
    {
        return $this->numberingConfig[$type] ??= (function () use ($prefixKey, $paddingKey): array {
            $settings = Setting::query()->whereIn('key', [$prefixKey, $paddingKey])->pluck('value', 'key');

            foreach ([$prefixKey, $paddingKey] as $key) {
                if (! $settings->has($key)) {
                    throw new InvalidArgumentException("Required numbering setting [{$key}] is missing.");
                }
            }

            return [(string) $settings[$prefixKey], max(0, (int) $settings[$paddingKey])];
        })();
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->numberingConfig = [];
    }

    private function nextMovementNumber(): int
    {
        $sequence = DB::selectOne('SELECT last_value, is_called FROM stock_movement_number_seq');

        return (int) $sequence->last_value + ($sequence->is_called ? 1 : 0);
    }

    private function counterValue(mixed $value): int
    {
        $number = (is_int($value) || is_string($value))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - 1]])
            : false;
        if ($number === false) {
            throw new BusinessException('قيمة عداد الترقيم يجب أن تكون عددًا صحيحًا موجبًا ضمن النطاق المسموح');
        }

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

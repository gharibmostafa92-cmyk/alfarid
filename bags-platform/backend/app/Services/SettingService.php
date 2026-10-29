<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

/**
 * Central settings and document numbering configuration service.
 *
 * Caches static system settings and manages atomic generation of sequential document identifiers
 * (orders, transfers, movements, invoices) via PostgreSQL sequences and optimistic concurrency locking.
 */
class SettingService
{
    /** @var array<string, array{0: string, 1: int}> Cached numbering prefix and padding config per request to avoid repeated lookups. */
    private array $numberingConfig = [];

    private ?array $transactionSnapshot = null;

    private bool $uncommittedSettings = false;

    public function resetReadSnapshot(bool $transactionBoundary = false): void
    {
        $this->transactionSnapshot = null;
        if ($transactionBoundary) {
            $this->uncommittedSettings = false;
        }
    }

    /** @var list<string> */
    public array $warnings = [];

    private const CACHE_KEY = 'app_settings_static';

    private const CACHE_VERSION_KEY = 'app_settings_static_version';

    private const CACHE_TTL_SECONDS = 3600;

    /** @var list<string> */
    private const NUMBERING_COUNTER_KEYS = [
        'shift_next_number',
        'expense_next_number',
        'treasury_transfer_next_number',
        'payment_notice_next_number',
        'pos_invoice_next_number',
        'invoice_next_number',
        'order_next_number',
        'purchase_order_next_number',
        'quotation_next_number',
        'movement_next_number',
        'transfer_next_number',
        'stocktake_next_number',
        'return_next_number',
        'purchase_request_next_number',
        'delivery_next_number',
        'supplier_invoice_next_number',
        'supplier_payment_next_number',
    ];

    /** Internal documents numbered via dedicated PostgreSQL sequences to prevent long transaction row locks. */
    private const SEQUENCE_TYPES = [
        'order' => 'order_next_number',
        'purchase_order' => 'purchase_order_next_number',
        'quotation' => 'quotation_next_number',
        'transfer' => 'transfer_next_number',
        'stocktake' => 'stocktake_next_number',
        'return' => 'return_next_number',
        'purchase_request' => 'purchase_request_next_number',
        'delivery' => 'delivery_next_number',
        'supplier_invoice' => 'supplier_invoice_next_number',
        'supplier_payment' => 'supplier_payment_next_number',
    ];

    /**
     * Return all settings grouped by their group name, with values cast to their declared types.
     * Numbering counters are read fresh because they change for every generated document number.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllGrouped(): array
    {
        $grouped = $this->staticSettings();

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
        foreach (self::SEQUENCE_TYPES as $type => $key) {
            if (isset($counters[$key])) {
                $counters[$key] = $this->nextDocumentNumber($type);
            }
        }

        if ($counters !== []) {
            $grouped['numbering'] = array_merge($grouped['numbering'] ?? [], $counters);
            ksort($grouped['numbering']);
        }

        return $grouped;
    }

    private function staticSettings(): array
    {
        $read = function (): array {
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
        };

        $inTransaction = DB::transactionLevel() > 0;
        if ($inTransaction && $this->transactionSnapshot !== null) {
            return $this->transactionSnapshot;
        }
        if ($inTransaction && $this->uncommittedSettings) {
            return $this->transactionSnapshot = $read();
        }
        $version = Cache::get(self::CACHE_VERSION_KEY);
        if (! is_string($version)) {
            Cache::add(self::CACHE_VERSION_KEY, (string) Str::uuid());
            $version = Cache::get(self::CACHE_VERSION_KEY);
        }

        $key = self::CACHE_KEY.':'.$version;
        if ($inTransaction) {
            // Reuse committed cache values, but never publish an uncommitted cache miss.
            return $this->transactionSnapshot = Cache::get($key) ?? $read();
        }

        return Cache::remember($key, self::CACHE_TTL_SECONDS, $read);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (in_array($key, self::NUMBERING_COUNTER_KEYS, true)) {
            if ($key === 'movement_next_number') {
                return $this->nextMovementNumber();
            }
            $type = array_search($key, self::SEQUENCE_TYPES, true);
            if (is_string($type)) {
                return $this->nextDocumentNumber($type);
            }
            $setting = Setting::query()->where('key', $key)->first();

            return $setting ? $this->castValue($setting->value, $setting->type) : $default;
        }
        foreach ($this->staticSettings() as $settings) {
            if (array_key_exists($key, $settings)) {
                return $settings[$key];
            }
        }

        return $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array{name: string, logo: ?string, email: ?string, phone: ?string, address: ?string} */
    public function publicBranding(): array
    {
        $company = Setting::query()->where('group', 'company')
            ->whereIn('key', ['company_name', 'company_logo', 'company_email', 'company_phone', 'company_address'])
            ->pluck('value', 'key')->all();

        return [
            'name' => trim((string) ($company['company_name'] ?? '')) ?: 'Alfarid',
            'logo' => $company['company_logo'] ?? null,
            'email' => $company['company_email'] ?? null,
            'phone' => $company['company_phone'] ?? null,
            'address' => $company['company_address'] ?? null,
        ];
    }

    /**
     * Update a flat key/value map atomically and invalidate the cached settings after commit.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws JsonException
     */
    public function updateBatch(array $settings, ?array $expected = null): void
    {
        $this->warnings = [];
        DB::transaction(function () use ($settings, $expected): void {
            $this->uncommittedSettings = true;
            $this->resetReadSnapshot();
            $movementSequenceTarget = null;
            /** @var array<string, int> $documentSequenceTargets */
            $documentSequenceTargets = [];
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

            $before = [];
            $after = [];
            foreach ($settings as $key => $value) {
                /** @var Setting $setting */
                $setting = $lockedSettings->get($key);
                $current = $this->castValue($setting->value, $setting->type);
                if (in_array($key, self::NUMBERING_COUNTER_KEYS, true)) {
                    $current = $this->get($key);
                }
                if ($expected !== null && (! array_key_exists($key, $expected)
                    || $this->comparisonValue($current, $setting->type) !== $this->comparisonValue($expected[$key], $setting->type))) {
                    abort(409, 'تم تغيير إعداد أو عداد منذ فتح الصفحة؛ أعد تحميل الإعدادات قبل الحفظ.');
                }
                $before[$key] = $current;

                if (in_array($key, self::NUMBERING_COUNTER_KEYS, true)) {
                    $requested = $this->counterValue($value);
                    if ($requested > 999999999) {
                        throw ValidationException::withMessages([$key => 'الحد الأقصى للعداد هو 999999999.']);
                    }
                    $value = max($this->counterValue($setting->value), $requested);
                    if ($key === 'movement_next_number') {
                        $value = max($value, $this->nextMovementNumber());
                        $movementSequenceTarget = $value;
                    }
                    $sequenceType = array_search($key, self::SEQUENCE_TYPES, true);
                    if (is_string($sequenceType)) {
                        $value = max($value, $this->nextDocumentNumber($sequenceType));
                        $documentSequenceTargets[$sequenceType] = $value;
                    }
                    if ($requested < $value) {
                        throw ValidationException::withMessages([$key => 'لا يمكن خفض عداد الترقيم عن قيمته الحالية.']);
                    }
                }

                $setting->value = $value === null && str_ends_with($key, '_prefix')
                    ? ''
                    : $this->serializeValue($value, $setting->type);
                $setting->save();
                $after[$key] = $this->castValue($setting->value, $setting->type);
            }

            StaffAdministration::audit(request()->user('staff'), 'settings.updated', 'settings', null, array_keys($settings),
                ['settings' => $this->auditableSettings($before)], ['settings' => $this->auditableSettings($after)]);
            DB::afterCommit(fn () => $this->clearCache());

            if ($movementSequenceTarget !== null) {
                // Synchronize sequence after commit to avoid holding an exclusive lock on the sequence during transactions
                DB::afterCommit(function () use ($movementSequenceTarget): void {
                    $this->synchronizeSequence(fn () => DB::selectOne('SELECT inventory_movement_number(?) AS number', [$movementSequenceTarget]));
                });
            }
            if ($documentSequenceTargets !== []) {
                DB::afterCommit(function () use ($documentSequenceTargets): void {
                    foreach ($documentSequenceTargets as $type => $minimum) {
                        $this->synchronizeSequence(fn () => DB::selectOne('SELECT document_next_number(?, ?) AS number', [$type, $minimum]));
                    }
                });
            }
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
            $number = DB::selectOne('SELECT inventory_movement_number() AS number')->number;

            return $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
        }
        if (isset(self::SEQUENCE_TYPES[$type])) {
            $definitions = $this->numberingDefinitions();
            $definition = $definitions[$type];
            [$prefix, $padding] = $this->numberingConfig($type, $definition['prefix'], $definition['padding']);
            $number = DB::selectOne('SELECT document_next_number(?) AS number', [$type])->number;

            return $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
        }
        $definitions = $this->numberingDefinitions();

        if (! isset($definitions[$type])) {
            throw new InvalidArgumentException("Unsupported numbering type [{$type}].");
        }

        $definition = $definitions[$type];
        [$prefix, $padding] = $this->numberingConfig($type, $definition['prefix'], $definition['padding']);

        return DB::transaction(function () use ($definition, $prefix, $padding): string {
            // Counter row is locked and read fresh; prefix and padding are cached in request memory
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
     * Cache numbering prefix and padding per request to reduce query overhead.
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

    private function synchronizeSequence(callable $sync): void
    {
        try {
            $sync();
        } catch (\Throwable $exception) {
            report($exception);
            $this->warnings[] = 'تم حفظ الإعدادات، لكن تعذرت مزامنة أحد عدادات الترقيم؛ أعد حفظ العداد للمحاولة مرة أخرى.';
        }
    }

    public function clearCache(): void
    {
        Cache::forever(self::CACHE_VERSION_KEY, (string) Str::uuid());
        Cache::forget(self::CACHE_KEY); // Retire the cache key used before versioning.
        $this->numberingConfig = [];
        $this->resetReadSnapshot();
    }

    private function comparisonValue(mixed $value, string $type): ?string
    {
        if ($type === 'float' && $value !== null && is_numeric($value)) {
            return (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros();
        }

        return $type === 'string' ? (string) ($value ?? '') : $this->serializeValue($value, $type);
    }

    private function auditableSettings(array $settings): array
    {
        // Only the public administrative settings schema is safe to audit as values.
        $allowed = array_keys((new UpdateSettingsRequest)->rules());

        return array_intersect_key($settings, array_flip($allowed));
    }

    /**
     * @return array<string, array{prefix: string, next: string, padding: string}>
     */
    private function numberingDefinitions(): array
    {
        return [
            'invoice' => ['prefix' => 'invoice_prefix', 'next' => 'invoice_next_number', 'padding' => 'invoice_padding'],
            'order' => ['prefix' => 'order_prefix', 'next' => 'order_next_number', 'padding' => 'order_padding'],
            'purchase_order' => ['prefix' => 'purchase_order_prefix', 'next' => 'purchase_order_next_number', 'padding' => 'purchase_order_padding'],
            'quotation' => ['prefix' => 'quotation_prefix', 'next' => 'quotation_next_number', 'padding' => 'quotation_padding'],
            'transfer' => ['prefix' => 'transfer_prefix', 'next' => 'transfer_next_number', 'padding' => 'transfer_padding'],
            'stocktake' => ['prefix' => 'stocktake_prefix', 'next' => 'stocktake_next_number', 'padding' => 'stocktake_padding'],
            'return' => ['prefix' => 'return_prefix', 'next' => 'return_next_number', 'padding' => 'return_padding'],
            'purchase_request' => ['prefix' => 'purchase_request_prefix', 'next' => 'purchase_request_next_number', 'padding' => 'purchase_request_padding'],
            'delivery' => ['prefix' => 'delivery_prefix', 'next' => 'delivery_next_number', 'padding' => 'delivery_padding'],
            'supplier_invoice' => ['prefix' => 'supplier_invoice_prefix', 'next' => 'supplier_invoice_next_number', 'padding' => 'supplier_invoice_padding'],
            'supplier_payment' => ['prefix' => 'supplier_payment_prefix',    'next' => 'supplier_payment_next_number',    'padding' => 'supplier_payment_padding'],
            'shift' => ['prefix' => 'shift_prefix',               'next' => 'shift_next_number',               'padding' => 'shift_padding'],
            'expense' => ['prefix' => 'expense_prefix',             'next' => 'expense_next_number',             'padding' => 'expense_padding'],
            'treasury_transfer' => ['prefix' => 'treasury_transfer_prefix',   'next' => 'treasury_transfer_next_number',   'padding' => 'treasury_transfer_padding'],
            'payment_notice' => ['prefix' => 'payment_notice_prefix',      'next' => 'payment_notice_next_number',      'padding' => 'payment_notice_padding'],
        ];
    }

    private function nextMovementNumber(): int
    {
        $sequence = DB::selectOne('SELECT last_value, is_called FROM stock_movement_number_seq');

        return (int) $sequence->last_value + ($sequence->is_called ? 1 : 0);
    }

    private function nextDocumentNumber(string $type): int
    {
        if (! isset(self::SEQUENCE_TYPES[$type])) {
            throw new InvalidArgumentException("Unsupported numbering type [{$type}].");
        }
        $sequence = DB::selectOne("SELECT last_value, is_called FROM document_{$type}_number_seq");

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

<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Exceptions\BusinessException;
use App\Http\Requests\Admin\Auth\UpdateSettingsRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\StaffAdministration;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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
    public const BRANDING_CACHE_KEY = 'store:branding:v1';

    private const CACHE_KEY = 'app_settings_static';

    private const CACHE_VERSION_KEY = 'app_settings_static_version';

    private const CACHE_TTL_SECONDS = 3600;

    private const MAX_COUNTER_VALUE = 999_999_999;

    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'ai_catalog_tools_enabled' => false,
        'ai_catalog_vision_enabled' => false,
        'ai_catalog_bulk_price_max_items' => 200,
        'ai_catalog_copywriting_enabled' => false,
        'ai_inventory_tools_enabled' => false,
        'ai_inventory_vision_enabled' => false,
        'ai_inventory_share_cost_with_model' => false,
        'ai_inventory_high_risk_value' => 50000,
        'ai_inventory_proactive_enabled' => false,
        'ai_pricing_tools_enabled' => false,
        'ai_promotions_tools_enabled' => false,
        'ai_pricing_max_items_per_action' => 200,
        'ai_coupon_high_risk_percent' => 30,
        'ai_price_below_cost_policy' => 'block',
        'ai_pricing_vision_enabled' => false,
        'ai_shipping_tools_enabled' => false,
        'ai_storefront_tools_enabled' => false,
        'ai_storefront_place_order_enabled' => false,
        'ai_max_steps' => 6,
        'ai_whatsapp_enabled' => false,
        'ai_whatsapp_allow_writes' => false,
        'ai_whatsapp_max_risk' => 'medium',
        'ai_whatsapp_disabled_tools' => ['create_staff_user', 'create_role', 'update_role_permissions', 'delete_role', 'assign_roles', 'revoke_user_sessions', 'update_settings', 'update_document_numbering'],
        'ai_whatsapp_idle_rotate_hours' => 12,
        'ai_whatsapp_link_token_ttl_minutes' => 10,
        'ai_whatsapp_pin_required' => false,
        'ai_wholesale_tools_enabled' => false,
        'ai_wholesale_receipt_vision_enabled' => false,
        'ai_quote_high_risk_discount_percent' => 15,
        'ai_quote_high_risk_total' => 100000,
        'ai_wholesale_shadow_mode' => true,
        'ai_wholesale_quote_sla_hours' => 24,
        'ai_wholesale_notice_sla_hours' => 24,
        'ai_wholesale_reminder_weekly_cap' => 3,
        'ai_whatsapp_allow_notice_approval' => false,
        'ai_whatsapp_quote_max_discount_percent' => 5,
        'wholesale_fifo_mode' => 'strict',
        'wholesale_default_payment_terms_days' => 30,
    ];

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
        // High-volume POS entities use database sequences where gaps are acceptable to avoid single-row lock contention.
        'shift' => 'shift_next_number',
        'pos_invoice' => 'pos_invoice_next_number',
        'expense' => 'expense_next_number',
        'treasury_transfer' => 'treasury_transfer_next_number',
        'payment_notice' => 'payment_notice_next_number',
    ];
    // Tax invoices remain row-locked because continuous sequential numbering without gaps is a legal requirement.

    /**
     * Single source of truth: document type to [table, column] mapping for
     * both numbering-collision asserts. Types without dedicated tables are
     * excluded: `invoice` (no invoices table exists in schema — only
     * supplier_invoices) and `pos_invoice` (sequence-only numbering, mapped
     * to table null in 2026_10_31_000001_move_high_volume_numbering_to_sequences).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DOCUMENT_NUMBER_TARGETS = [
        'order' => ['orders', 'order_number'],
        'purchase_order' => ['purchase_orders', 'po_number'],
        'quotation' => ['quotations', 'quotation_number'],
        'transfer' => ['stock_transfers', 'transfer_number'],
        'stocktake' => ['stocktakes', 'stocktake_number'],
        'return' => ['return_requests', 'return_number'],
        'purchase_request' => ['purchase_requests', 'request_number'],
        'delivery' => ['supplier_deliveries', 'delivery_number'],
        'supplier_invoice' => ['supplier_invoices', 'invoice_number'],
        'supplier_payment' => ['supplier_payments', 'payment_number'],
        'shift' => ['pos_shifts', 'shift_number'],
        'expense' => ['expenses', 'expense_number'],
        'treasury_transfer' => ['treasury_transfers', 'transfer_number'],
        'payment_notice' => ['wholesale_payment_notices', 'notice_number'],
        'movement' => ['stock_movements', 'reference_number'],
    ];

    /** @var list<string> */
    private const NUMBERING_TYPES = [
        'invoice',
        'order',
        'purchase_order',
        'quotation',
        'transfer',
        'stocktake',
        'return',
        'purchase_request',
        'delivery',
        'supplier_invoice',
        'supplier_payment',
        'movement',
        'shift',
        'pos_invoice',
        'expense',
        'treasury_transfer',
        'payment_notice',
    ];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, array{0: string, 1: int}> Cached numbering prefix and padding config per request to avoid repeated lookups. */
    private array $numberingConfig = [];

    private ?array $transactionSnapshot = null;

    private bool $uncommittedSettings = false;

    /** @var array<string, mixed>|null Per-request in-memory cache to avoid repeated serialization and cache lookups. */
    private ?array $requestCache = null;

    private ?string $requestCacheVersion = null;

    private ?string $numberingConfigVersion = null;

    public function resetReadSnapshot(bool $transactionBoundary = false): void
    {
        $this->transactionSnapshot = null;
        if ($transactionBoundary) {
            $this->uncommittedSettings = false;
            $this->requestCache = null;
            $this->requestCacheVersion = null;
            $this->numberingConfigVersion = null;
        }
    }

    /**
     * Resets in-memory cached state (snapshots, counters, proxy counters).
     */
    public function forgetMemoryState(): void
    {
        $this->numberingConfig = [];
        $this->resetReadSnapshot(true);
    }

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

    public function get(string $key, mixed $default = null): mixed
    {
        if (in_array($key, self::NUMBERING_COUNTER_KEYS, true)) {
            return $this->getNumberingCounter($key, $default);
        }

        foreach ($this->staticSettings() as $settings) {
            if (array_key_exists($key, $settings)) {
                return $settings[$key];
            }
        }

        if ($default === null && array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key];
        }

        return $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array{name: string, logo: ?string, email: ?string, phone: ?string, address: ?string, commercialRegister: ?string, taxNumber: ?string} */
    public function publicBranding(): array
    {
        // Read branding from in-memory settings to avoid repetitive database queries on public storefront hits.
        $company = $this->staticSettings()['company'] ?? [];
        $tax = $this->staticSettings()['tax'] ?? [];

        return [
            'name' => trim((string) ($company['company_name'] ?? '')) ?: 'VARYA',
            'legalName' => trim((string) ($company['legal_name'] ?? '')) ?: null,
            'logo' => $company['company_logo'] ?? null,
            'email' => $company['company_email'] ?? null,
            'phone' => $company['company_phone'] ?? null,
            'address' => $company['company_address'] ?? null,
            'commercialRegister' => $company['commercial_register'] ?? null,
            'taxNumber' => $tax['tax_number'] ?? null,
        ];
    }

    /**
     * Update a flat key/value map atomically and invalidate the cached settings after commit.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws JsonException
     */
    public function updateBatch(array $settings, ?array $expected = null, array $via = [], ?User $actor = null): void
    {
        $this->warnings = [];
        DB::transaction(function () use ($settings, $expected, $via, $actor): void {
            $this->uncommittedSettings = true;
            $this->resetReadSnapshot();

            $lockedSettings = $this->lockRequestedSettings($settings);
            [$before, $after, $movementSequenceTarget, $documentSequenceTargets] = $this->applySettingsBatch(
                $settings,
                $lockedSettings,
                $expected,
            );

            $this->assertNumberingDoesNotCollide($settings, $after);
            // Changing prefix or padding can collide with previously issued numbers; assert next formatted number is unused in target document table.
            $this->assertNoNumberingCollision(array_keys($settings));

            // F19: CLI/queue/WhatsApp callers have no staff HTTP request; an explicit
            // actor keeps the audit log accurate, otherwise fall back safely.
            $resolvedActor = $actor ?? (app()->runningInConsole() ? null : request()->user('staff'));
            StaffAdministration::audit(
                $resolvedActor,
                'settings.updated',
                'settings',
                null,
                array_keys($settings),
                ['settings' => $this->auditableSettings($before)],
                ['settings' => $this->auditableSettings($after)],
                $via,
            );
            DB::afterCommit(fn () => $this->clearCache());

            // Synchronize sequences within the transaction: a non-transactional
            // sequence advance may leave gaps on rollback, but prevents
            // duplicate document numbers.
            $this->synchronizeSequences($movementSequenceTarget, $documentSequenceTargets);
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

            return $this->formatDocumentNumber($prefix, (int) $number, $padding);
        }

        if (isset(self::SEQUENCE_TYPES[$type])) {
            $definition = $this->numberingDefinition($type);
            [$prefix, $padding] = $this->numberingConfig($type, $definition['prefix'], $definition['padding']);
            $number = DB::selectOne('SELECT document_next_number(?) AS number', [$type])->number;

            return $this->formatDocumentNumber($prefix, (int) $number, $padding);
        }

        if (! in_array($type, self::NUMBERING_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported numbering type [{$type}].");
        }

        $definition = $this->numberingDefinition($type);
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
            $formatted = $this->formatDocumentNumber($prefix, $nextValue, $padding);
            $nextSetting->value = (string) ($nextValue + 1);
            $nextSetting->save();

            return $formatted;
        }, attempts: 3);
    }

    public function clearCache(): void
    {
        Cache::forever(self::CACHE_VERSION_KEY, (string) Str::uuid());
        Cache::forget(self::CACHE_KEY); // Retire the cache key used before versioning.
        Cache::forget(self::BRANDING_CACHE_KEY);
        $this->numberingConfig = [];
        $this->numberingConfigVersion = null;
        $this->requestCache = null;
        $this->requestCacheVersion = null;
        $this->resetReadSnapshot();
    }

    private function staticSettings(): array
    {
        $read = fn (): array => Setting::query()
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

        // M5: long-lived workers (queue/Octane/scheduler) must notice a version
        // bump from another process instead of serving a frozen snapshot forever.
        if ($this->requestCache !== null && $this->requestCacheVersion === $version) {
            return $this->requestCache;
        }

        $key = self::CACHE_KEY.':'.$version;
        if ($inTransaction) {
            // Reuse committed cache values, but never publish an uncommitted cache miss.
            return $this->transactionSnapshot = Cache::get($key) ?? $read();
        }

        $result = Cache::remember($key, self::CACHE_TTL_SECONDS, $read);
        // Guard against concurrent version bump during read to prevent pinning stale snapshots in request cache.
        if (Cache::get(self::CACHE_VERSION_KEY) === $version) {
            $this->requestCache = $result;
            $this->requestCacheVersion = $version;
        }

        return $result;
    }

    private function getNumberingCounter(string $key, mixed $default): mixed
    {
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

    /**
     * @param  array<string, mixed>  $settings
     * @return Collection<string, Setting>
     */
    private function lockRequestedSettings(array $settings)
    {
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

        return $lockedSettings;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  Collection<string, Setting>  $lockedSettings
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int|null, 3: array<string, int>}
     */
    private function applySettingsBatch(array $settings, $lockedSettings, ?array $expected): array
    {
        $before = [];
        $after = [];
        $movementSequenceTarget = null;
        /** @var array<string, int> $documentSequenceTargets */
        $documentSequenceTargets = [];

        foreach ($settings as $key => $value) {
            /** @var Setting $setting */
            $setting = $lockedSettings->get($key);
            $isCounter = in_array($key, self::NUMBERING_COUNTER_KEYS, true);
            $current = $isCounter ? $this->get($key) : $this->castValue($setting->value, $setting->type);

            // Dynamic sequence counters increment continuously under concurrent traffic; enforce monotonic increase rather than exact equality on optimistic concurrency.
            if (! $isCounter && $expected !== null && (! array_key_exists($key, $expected)
                || $this->comparisonValue($current, $setting->type) !== $this->comparisonValue($expected[$key], $setting->type))) {
                abort(409, 'تم تغيير إعداد أو عداد منذ فتح الصفحة؛ أعد تحميل الإعدادات قبل الحفظ.');
            }
            $before[$key] = $current;

            if ($isCounter) {
                $value = $this->resolveMonotonicCounterUpdate($key, $value, $setting, $movementSequenceTarget, $documentSequenceTargets);
            }

            $setting->value = $value === null && str_ends_with($key, '_prefix')
                ? ''
                : $this->serializeValue($value, $setting->type);
            $setting->save();
            $after[$key] = $this->castValue($setting->value, $setting->type);
        }

        return [$before, $after, $movementSequenceTarget, $documentSequenceTargets];
    }

    /**
     * @param  array<string, int>  $documentSequenceTargets
     */
    private function resolveMonotonicCounterUpdate(
        string $key,
        mixed $value,
        Setting $setting,
        ?int &$movementSequenceTarget,
        array &$documentSequenceTargets,
    ): int {
        $requested = $this->counterValue($value);
        if ($requested > self::MAX_COUNTER_VALUE) {
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

        return $value;
    }

    private function assertNumberingDoesNotCollide(array $settings, array $after): void
    {
        // Single source of truth: iterate the one DOCUMENT_NUMBER_TARGETS map.
        foreach (self::DOCUMENT_NUMBER_TARGETS as $type => $target) {
            $definition = $this->numberingDefinition($type);
            $keys = [$definition['prefix'], $definition['next'], $definition['padding']];
            if (array_intersect($keys, array_keys($settings)) === []) {
                continue;
            }

            $prefix = array_key_exists($definition['prefix'], $after)
                ? (string) $after[$definition['prefix']]
                : (string) $this->get($definition['prefix'], '');
            $padding = array_key_exists($definition['padding'], $after)
                ? max(0, (int) $after[$definition['padding']])
                : max(0, (int) $this->get($definition['padding'], 0));
            $next = array_key_exists($definition['next'], $after)
                ? (int) $after[$definition['next']]
                : ($type === 'movement' ? $this->nextMovementNumber() : $this->nextDocumentNumber($type));

            // Collision scan covers the next number AND any future number:
            // any existing document sharing this prefix whose numeric suffix
            // is >= the proposed counter would collide now or later.
            // H3: aggregate MAX in SQL instead of loading every matching document
            // (a common prefix can pull millions of rows into PHP while the
            // settings rows are locked).
            $max = self::maxNumericSuffix($target[0], $target[1], $prefix);

            if ($max !== null && $max >= $next) {
                $formatted = $this->formatDocumentNumber($prefix, $next, $padding);
                throw ValidationException::withMessages([
                    $definition['next'] => 'الرقم '.$formatted.' مستخدم بالفعل في '.$target[0].'. غيّر البداية أو البادئة أو الطول.',
                ]);
            }
        }
    }

    /**
     * Largest purely-numeric suffix after $prefix in a document table, computed
     * in PostgreSQL. The {1,18} guard keeps CAST from overflowing bigint on
     * malformed or oversized suffixes.
     */
    private static function maxNumericSuffix(string $table, string $column, string $prefix): ?int
    {
        $table = self::quoteIdentifier($table);
        $column = self::quoteIdentifier($column);
        $start = mb_strlen($prefix) + 1;
        $sql = "SELECT MAX(CAST(SUBSTRING({$column} FROM {$start}) AS BIGINT)) AS max_suffix"
            ." FROM {$table}"
            ." WHERE {$column} LIKE ?"
            ." AND SUBSTRING({$column} FROM {$start}) ~ '^[0-9]{1,18}$'";
        $row = DB::selectOne($sql, [SearchTerm::prefix($prefix)]);

        return $row?->max_suffix !== null ? (int) $row->max_suffix : null;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid SQL identifier ['.$identifier.'].');
        }

        return '"'.$identifier.'"';
    }

    /**
     * Advance the movement/document sequences to at least the synchronized
     * targets. A concurrent allocation can hold the numbering lock while it
     * accesses the sequence. Retry the sync with backoff on 55P03.
     *
     * @param  array<string, int>  $documentSequenceTargets
     */
    private function synchronizeSequences(?int $movementSequenceTarget, array $documentSequenceTargets): void
    {
        // PostgreSQL aborts the current transaction on 55P03. Roll back to a
        // savepoint before retrying so subsequent queries can actually run.
        retry(
            3,
            fn () => DB::transaction(function () use ($movementSequenceTarget, $documentSequenceTargets): void {
                if ($movementSequenceTarget !== null) {
                    DB::selectOne('SELECT inventory_movement_number(?) AS number', [$movementSequenceTarget]);
                }
                foreach ($documentSequenceTargets as $type => $minimum) {
                    DB::selectOne('SELECT document_next_number(?, ?) AS number', [$type, $minimum]);
                }
            }),
            fn (int $attempt): int => 500 * $attempt,
            fn (\Throwable $e): bool => $e instanceof QueryException && ($e->errorInfo[0] ?? null) === '55P03',
        );
    }

    /**
     * Cache numbering prefix and padding per request to reduce query overhead.
     *
     * @return array{0: string, 1: int}
     */
    private function numberingConfig(string $type, string $prefixKey, string $paddingKey): array
    {
        // M5: numbering prefix/padding must also follow version bumps so a
        // long-lived worker cannot keep issuing numbers with a stale prefix.
        $version = Cache::get(self::CACHE_VERSION_KEY);
        if ($this->numberingConfigVersion !== $version) {
            $this->numberingConfig = [];
            $this->numberingConfigVersion = is_string($version) ? $version : null;
        }

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
     * Assert that formatted next number does not collide with existing document rows when prefix/padding/number changes.
     *
     * @param  list<string>  $changedKeys
     */
    private function assertNoNumberingCollision(array $changedKeys): void
    {
        foreach (self::DOCUMENT_NUMBER_TARGETS as $type => [$table, $column]) {
            // Numbering settings follow a uniform {type}_prefix/_next_number/_padding naming convention.
            $keys = [$type.'_prefix', $type.'_next_number', $type.'_padding'];
            if (array_intersect($keys, $changedKeys) === []) {
                continue;
            }
            // Read current values within transaction to combine freshly saved overrides with untouched defaults.
            $rows = Setting::query()->whereIn('key', $keys)->get()->keyBy('key');
            if ($rows->count() !== count($keys)) {
                continue;
            }
            $prefix = (string) $this->castValue($rows[$keys[0]]->value, $rows[$keys[0]]->type);
            $next = (int) $this->castValue($rows[$keys[1]]->value, $rows[$keys[1]]->type);
            $padding = (int) $this->castValue($rows[$keys[2]]->value, $rows[$keys[2]]->type);
            $candidate = $this->formatDocumentNumber($prefix, $next, $padding);
            if (DB::table($table)->where($column, $candidate)->exists()) {
                throw ValidationException::withMessages([$keys[0] => "الرقم التالي المُنسَّق ({$candidate}) صدر من قبل لنوع {$type}؛ غيّر البادئة أو الطول أو ارفع العداد."]);
            }
        }
    }

    /**
     * @return array{prefix: string, next: string, padding: string}
     */
    private function numberingDefinition(string $type): array
    {
        return [
            'prefix' => $type.'_prefix',
            'next' => $type.'_next_number',
            'padding' => $type.'_padding',
        ];
    }

    private function formatDocumentNumber(string $prefix, int $number, int $padding): string
    {
        return $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
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

        if ($type === 'json') {
            try {
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
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

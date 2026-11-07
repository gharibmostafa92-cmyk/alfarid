<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping;

use App\Models\City;
use App\Services\Ai\ToolOutcome;
use Illuminate\Validation\ValidationException;

final class ShippingArgs
{
    /** @param array<string, mixed> $args */
    public static function rejectSecrets(array $args): void
    {
        $walk = function (mixed $value) use (&$walk): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if (is_string($key) && self::secretKey($key)) {
                    throw ValidationException::withMessages([
                        $key => 'مفاتيح شركات الشحن لا تُمرَّر للمساعد. أدخلها من شاشة الإدارة.',
                    ]);
                }
                $walk($child);
            }
        };
        $walk($args);
    }

    public static function secretKey(string $key): bool
    {
        $name = strtolower($key);

        return str_contains($name, 'credential')
            || str_contains($name, 'secret')
            || str_contains($name, 'api_key')
            || $name === 'token'
            || $name === 'password';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public static function city(array $args): City|ToolOutcome
    {
        if (! empty($args['city_id'])) {
            $city = City::query()->find((int) $args['city_id']);
            if (! $city) {
                return ToolOutcome::denied('المدينة غير موجودة.', auditable: false);
            }

            return $city;
        }
        $name = trim((string) ($args['city_query'] ?? $args['city'] ?? ''));
        if ($name === '') {
            return ToolOutcome::denied('حدد المدينة بـ city_id أو city_query.', auditable: false);
        }
        $matches = City::query()
            ->where('is_active', true)
            ->where(function ($q) use ($name): void {
                $q->where('name_ar', $name)->orWhere('name_en', $name);
            })
            ->orderBy('id')
            ->limit(5)
            ->get();
        if ($matches->isEmpty()) {
            return ToolOutcome::denied('مفيش مدينة نشطة بالاسم ده. ابعت city_id.', auditable: false);
        }
        if ($matches->count() > 1) {
            return ToolOutcome::denied('الاسم مطابق لأكتر من مدينة. ابعت city_id.', auditable: false);
        }

        return $matches->first();
    }

    public static function money(mixed $value, string $field, bool $nullable = false): ?float
    {
        if ($value === null || $value === '') {
            if ($nullable) {
                return null;
            }
            throw ValidationException::withMessages([$field => 'القيمة مطلوبة.']);
        }
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'القيمة لازم تكون رقم.']);
        }
        $number = round((float) $value, 2);
        if (! is_finite($number) || $number < 0 || $number > 99999999.99) {
            throw ValidationException::withMessages([$field => 'القيمة لازم تكون بين 0 و 99999999.99.']);
        }

        return $number;
    }
}

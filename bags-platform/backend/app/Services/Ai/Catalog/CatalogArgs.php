<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Services\Ai\EntityResolver;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CatalogArgs
{
    /**
     * @return array{color_name_ar: string, color_name_en: string, color_hex: string}
     *
     * @throws ValidationException
     */
    public static function resolveColor(string $input): array
    {
        $clean = trim($input);
        $norm = EntityResolver::normalizeForSearch($clean);
        $lower = mb_strtolower($clean);
        $colors = (array) config('ai_catalog_colors', []);

        foreach ($colors as $key => $entry) {
            if ($lower === mb_strtolower((string) $key) || $lower === mb_strtolower((string) ($entry['en'] ?? ''))) {
                return [
                    'color_name_ar' => (string) ($entry['ar'][0] ?? $clean),
                    'color_name_en' => (string) $entry['en'],
                    'color_hex' => (string) $entry['hex'],
                ];
            }
            foreach ((array) ($entry['ar'] ?? []) as $arVariant) {
                if ($norm === EntityResolver::normalizeForSearch((string) $arVariant) || $lower === mb_strtolower((string) $arVariant)) {
                    return [
                        'color_name_ar' => (string) $arVariant,
                        'color_name_en' => (string) $entry['en'],
                        'color_hex' => (string) $entry['hex'],
                    ];
                }
            }
        }

        // Suggestions based on levenshtein / similar_text
        $suggestions = [];
        foreach ($colors as $entry) {
            $arMain = (string) ($entry['ar'][0] ?? '');
            $enMain = (string) ($entry['en'] ?? '');
            similar_text($norm, EntityResolver::normalizeForSearch($arMain), $pctAr);
            similar_text($lower, mb_strtolower($enMain), $pctEn);
            $score = max($pctAr, $pctEn);
            $suggestions[] = ['label' => "{$arMain} ({$enMain})", 'score' => $score];
        }
        usort($suggestions, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice(array_column($suggestions, 'label'), 0, 5);

        throw ValidationException::withMessages([
            'color' => ["اللون «{$clean}» مش موجود في قاموس الألوان المعتمد. اقتراحات متاحة: ".implode('، ', $top)],
        ]);
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public static function normalizeDigitsInArray(array $args): array
    {
        foreach ($args as $key => $value) {
            if (is_string($value)) {
                $converted = EntityResolver::normalizeDigits($value);
                if (in_array($key, ['retail_price', 'wholesale_price', 'cost_price', 'compare_at_price', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'value'], true) && is_numeric($converted)) {
                    $args[$key] = (float) $converted;
                } elseif (in_array($key, ['reorder_level', 'sort_order', 'warranty_months', 'category_id', 'parent_id', 'product_id', 'variant_id'], true) && is_numeric($converted)) {
                    $args[$key] = (int) $converted;
                } elseif (in_array($key, ['code', 'sku', 'barcode'], true)) {
                    $args[$key] = strtoupper(trim($converted));
                } elseif ($key === 'slug') {
                    $args[$key] = strtolower(trim($converted));
                } else {
                    $args[$key] = is_int($key) ? $converted : $value;
                }
            } elseif (is_array($value)) {
                $args[$key] = self::normalizeDigitsInArray($value);
            }
        }

        return $args;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public static function normalizeVariant(array $args): array
    {
        $args = self::normalizeDigitsInArray($args);
        unset($args['variant_id'], $args['product_id']);

        if (isset($args['color']) && is_string($args['color']) && trim($args['color']) !== '') {
            $resolved = self::resolveColor($args['color']);
            $args['color_name_ar'] = $resolved['color_name_ar'];
            $args['color_name_en'] = $resolved['color_name_en'];
            $args['color_hex'] = $resolved['color_hex'];
            unset($args['color']);
        } elseif (isset($args['color_name_ar']) && is_string($args['color_name_ar']) && empty($args['color_name_en'])) {
            $resolved = self::resolveColor($args['color_name_ar']);
            $args['color_name_ar'] = $resolved['color_name_ar'];
            $args['color_name_en'] = $resolved['color_name_en'];
            $args['color_hex'] = $args['color_hex'] ?? $resolved['color_hex'];
        }

        if (isset($args['sku']) && is_string($args['sku'])) {
            $args['sku'] = strtoupper(trim($args['sku']));
        }
        if (isset($args['barcode']) && is_string($args['barcode'])) {
            $args['barcode'] = strtoupper(trim($args['barcode']));
        }

        return $args;
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $rules
     *
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->validate();
    }
}

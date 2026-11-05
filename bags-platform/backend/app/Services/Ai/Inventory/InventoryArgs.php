<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory;

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;

final class InventoryArgs
{
    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public static function normalize(array $args): array
    {
        foreach ($args as $key => $value) {
            if (is_string($value)) {
                $converted = EntityResolver::normalizeDigits($value);
                if (in_array($key, ['variant_id', 'product_variant_id', 'product_id', 'branch_id', 'from_branch_id', 'to_branch_id', 'location_id', 'from_location_id', 'to_location_id', 'item_id', 'reference_id', 'user_id', 'transfer_id', 'stocktake_id', 'limit', 'cursor', 'dead_days', 'days'], true) && is_numeric($converted)) {
                    $args[$key] = (int) $converted;
                } elseif (in_array($key, ['quantity', 'counted_qty', 'unit_cost'], true)) {
                    $args[$key] = str_replace('٫', '.', $converted);
                } elseif (in_array($key, ['sku', 'barcode'], true)) {
                    $args[$key] = strtoupper(trim($converted));
                } else {
                    $args[$key] = $value;
                }
            } elseif (is_array($value)) {
                $args[$key] = self::normalize($value);
            }
        }

        return $args;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{branch: Branch|null}|ToolOutcome
     */
    public static function scopeBranch(User $actor, BranchAccess $branches, array $args): array|ToolOutcome
    {
        try {
            $restricted = $branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        $requested = isset($args['branch_id']) ? (int) $args['branch_id'] : null;
        if ($restricted !== null) {
            if ($requested !== null && $requested !== $restricted) {
                return ToolOutcome::denied('الفرع خارج نطاقك.', auditable: false);
            }

            return ['branch' => Branch::find($restricted)];
        }
        if ($requested !== null) {
            $branch = Branch::find($requested);
            if (! $branch) {
                return ToolOutcome::denied('الفرع مش موجود.', auditable: false);
            }

            return ['branch' => $branch];
        }
        if (isset($args['branch_query']) && trim((string) $args['branch_query']) !== '') {
            $found = app(EntityResolver::class)->exactBranch($actor, (string) $args['branch_query']);
            if (! $found) {
                return ToolOutcome::denied('اسم الفرع مش واضح. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
            }

            return ['branch' => $found];
        }

        return ['branch' => null];
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{variant: ProductVariant}|ToolOutcome
     */
    public static function resolveVariant(User $actor, EntityResolver $resolver, array $args): array|ToolOutcome
    {
        if (! empty($args['variant_id'])) {
            $variant = ProductVariant::with('product:id,name_ar,code')->find((int) $args['variant_id']);
            if (! $variant) {
                return ToolOutcome::denied('المتغير مش موجود.', auditable: false);
            }

            return ['variant' => $variant];
        }
        $code = trim((string) ($args['sku'] ?? $args['barcode'] ?? ''));
        if ($code !== '') {
            $variant = $resolver->exactVariantByCode($code);
            if (! $variant) {
                return ToolOutcome::denied('مش لاقي متغير بالكود ده. استخدم find_entities.', auditable: false);
            }
            $variant->loadMissing('product:id,name_ar,code');

            return ['variant' => $variant];
        }
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return ToolOutcome::denied('حدد المتغير بـ variant_id أو sku أو query.', auditable: false);
        }
        $options = $resolver->variants($actor, $query, isset($args['product_id']) ? (int) $args['product_id'] : null);
        if (count($options) === 0) {
            return ToolOutcome::denied('مش لاقي متغير بالوصف ده.', auditable: false);
        }
        if (count($options) > 1) {
            return ToolOutcome::denied('لقيت أكتر من متغير. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
        }
        $variant = ProductVariant::with('product:id,name_ar,code')->find($options[0]['id']);
        if (! $variant) {
            return ToolOutcome::denied('المتغير مش موجود.', auditable: false);
        }

        return ['variant' => $variant];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;

final class PricingArgs
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
                if (in_array($key, ['variant_id', 'customer_id', 'customer_group_id', 'price_list_id', 'price_list_item_id', 'branch_id', 'limit', 'page', 'per_page', 'expiring_within_days'], true) && is_numeric($converted)) {
                    $args[$key] = (int) $converted;
                } elseif (in_array($key, ['qty', 'min_qty'], true) && is_numeric($converted)) {
                    $args[$key] = (float) $converted;
                } elseif (in_array($key, ['sku', 'barcode', 'code'], true)) {
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

    /**
     * Branch scoping with the same 404-style behavior as PriceListService::assertListAccess:
     * a branch-restricted user can only see their own branch lists.
     *
     * @param  array<string,mixed>  $args
     * @return array{branchId: int|null}|ToolOutcome
     */
    public static function scopeBranchId(User $actor, BranchAccess $branches, array $args): array|ToolOutcome
    {
        try {
            $restricted = $branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        $requested = isset($args['branch_id']) ? (int) $args['branch_id'] : null;
        if ($restricted !== null) {
            if ($requested !== null && $requested !== $restricted) {
                return ToolOutcome::denied('قائمة الأسعار غير موجودة.', auditable: false);
            }

            return ['branchId' => $restricted];
        }
        if ($requested !== null) {
            $branch = Branch::find($requested);
            if (! $branch) {
                return ToolOutcome::denied('الفرع مش موجود.', auditable: false);
            }

            return ['branchId' => $branch->id];
        }
        if (isset($args['branch_query']) && trim((string) $args['branch_query']) !== '') {
            $found = app(EntityResolver::class)->exactBranch($actor, (string) $args['branch_query']);
            if (! $found) {
                return ToolOutcome::denied('اسم الفرع مش واضح. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
            }

            return ['branchId' => $found->id];
        }

        return ['branchId' => null];
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{customer: Customer|null}|ToolOutcome
     */
    public static function resolveCustomer(User $actor, EntityResolver $resolver, array $args): array|ToolOutcome
    {
        if (! empty($args['customer_id'])) {
            $customer = Customer::query()->find((int) $args['customer_id']);
            if (! $customer) {
                return ToolOutcome::denied('العميل مش موجود.', auditable: false);
            }

            return ['customer' => $customer];
        }
        $query = trim((string) ($args['customer_query'] ?? ''));
        if ($query === '') {
            return ['customer' => null];
        }
        $options = $resolver->customers($actor, $query);
        if (count($options) === 0) {
            return ToolOutcome::denied('مش لاقي عميل بالوصف ده.', auditable: false);
        }
        if (count($options) > 1) {
            return ToolOutcome::denied('لقيت أكتر من عميل. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
        }
        $customer = Customer::query()->find($options[0]['id']);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.', auditable: false);
        }

        return ['customer' => $customer];
    }

}

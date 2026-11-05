<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance;

use App\Models\Expense;
use App\Services\Ai\EntityResolver;

final class ExpenseCategoryNormalizer
{
    /** @return array{category: string, is_new: bool} */
    public function normalize(string $category, int $branchId): array
    {
        $aliases = [];
        foreach (config('ai_finance_expense_categories', []) as $alias => $canonical) {
            $aliases[EntityResolver::normalizeForSearch($alias)] = $canonical;
        }
        $key = EntityResolver::normalizeForSearch($category);
        $canonical = $aliases[$key] ?? $category;
        $canonicalKey = EntityResolver::normalizeForSearch($canonical);
        $existing = Expense::query()->where('branch_id', $branchId)->whereNull('voided_at')
            ->where('expense_date', '>=', now(config('app.business_timezone', 'Africa/Cairo'))->subMonthsNoOverflow(6)->toDateString())
            ->distinct()->orderBy('category')->pluck('category');

        foreach ($existing as $label) {
            if (EntityResolver::normalizeForSearch($label) === $canonicalKey) {
                return ['category' => $label, 'is_new' => false];
            }
        }
        foreach ($existing as $label) {
            $labelKey = EntityResolver::normalizeForSearch($label);
            if (isset($aliases[$labelKey]) && EntityResolver::normalizeForSearch($aliases[$labelKey]) === $canonicalKey) {
                return ['category' => $label, 'is_new' => false];
            }
        }

        return ['category' => $canonical, 'is_new' => true];
    }
}

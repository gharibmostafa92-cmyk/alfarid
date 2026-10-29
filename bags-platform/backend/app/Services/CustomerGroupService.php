<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CustomerGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Manages customer groups and their wholesale discount configurations.
 */
class CustomerGroupService
{
    /**
     * Paginate customer groups with search filtering and active counts.
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return CustomerGroup::query()
            ->withCount('customers')
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($search) => $search
                ->whereLike('name', SearchTerm::pattern($v), caseSensitive: false)
                ->orWhereLike('code', SearchTerm::pattern($v), caseSensitive: false)))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new customer group record.
     */
    public function create(array $data): CustomerGroup
    {
        $data = array_filter($data, fn ($value, $key) => $value !== null || ! in_array($key, ['discount_percentage', 'is_active'], true), ARRAY_FILTER_USE_BOTH);

        return CustomerGroup::query()->create($data);
    }

    /**
     * Update customer group attributes.
     */
    public function update(CustomerGroup $group, array $data): CustomerGroup
    {
        $group->update($data);

        return $group;
    }

    /**
     * Delete customer group safely under lock, preventing orphaned customer associations.
     *
     * @throws BusinessException If assigned to customers or price lists
     */
    public function delete(CustomerGroup $group): void
    {
        try {
            DB::transaction(function () use ($group): void {
                // Lock row before existence check to prevent concurrent customer assignment that would silently lose group via nullOnDelete
                $group = CustomerGroup::query()->lockForUpdate()->findOrFail($group->id);

                if ($group->customers()->exists()) {
                    throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بعملاء؛ انقل العملاء أولًا أو عطّل المجموعة.');
                }

                if ($group->priceLists()->exists()) {
                    throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بقوائم أسعار.');
                }

                $group->delete();
            });
        } catch (QueryException $e) {
            // Convert foreign key violation (PostgreSQL 23503) into friendly 422 business error
            if (($e->errorInfo[0] ?? $e->getCode()) === '23503') {
                throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بعملاء أو قوائم أسعار.');
            }

            throw $e;
        }
    }
}

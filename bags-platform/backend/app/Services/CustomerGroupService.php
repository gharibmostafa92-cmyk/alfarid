<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    public function create(array $data, ?User $actor = null, array $via = []): CustomerGroup
    {
        $data = array_filter($data, fn ($value, $key) => $value !== null || ! in_array($key, ['discount_percentage', 'is_active'], true), ARRAY_FILTER_USE_BOTH);
        try {
            return DB::transaction(function () use ($data, $actor, $via): CustomerGroup {
                $group = CustomerGroup::query()->create($data)->refresh();
                // L-7: الخصم بيأثر على التسعير.
                if ($actor !== null) {
                    StaffAdministration::audit($actor, 'customer_group.created', 'customer_group', $group->id, array_keys($data), [], $group->only(array_keys($data)), $via);
                }

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'كود المجموعة مستخدم بالفعل.']); // L-4
        }
    }

    /**
     * Update customer group attributes.
     */
    public function update(CustomerGroup $group, array $data, ?User $actor = null, array $via = []): CustomerGroup
    {
        try {
            return DB::transaction(function () use ($group, $data, $actor, $via): CustomerGroup {
                $group = CustomerGroup::query()->lockForUpdate()->findOrFail($group->id);
                $before = $group->only(array_keys($data));
                $group->update($data);
                // M13: مسار REST كان مش بيسجل؛ مسار المساعد بيسجل بنفسه فمش بيبعت actor.
                if ($actor !== null) {
                    StaffAdministration::audit($actor, 'customer_group.updated', 'customer_group', $group->id, array_keys($data), $before, $group->only(array_keys($data)), $via);
                }

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'كود المجموعة مستخدم بالفعل.']); // L-4
        }
    }

    /**
     * Delete customer group safely under lock, preventing orphaned customer associations.
     *
     * @throws BusinessException If assigned to customers or price lists
     */
    public function delete(CustomerGroup $group, ?User $actor = null): void
    {
        try {
            DB::transaction(function () use ($group, $actor): void {
                // Lock row before existence check to prevent concurrent customer assignment that would silently lose group via nullOnDelete
                $group = CustomerGroup::query()->lockForUpdate()->findOrFail($group->id);

                // L-1: العملاء المحذوفين لما يترجعوا لازم يلاقوا مجموعتهم.
                if ($group->customers()->withTrashed()->exists()) {
                    throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بعملاء؛ انقل العملاء أولًا أو عطّل المجموعة.');
                }

                if ($group->priceLists()->exists()) {
                    throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بقوائم أسعار.');
                }

                $before = $group->only(['name', 'code', 'description', 'discount_percentage', 'is_active']);
                $group->delete();
                if ($actor !== null) {
                    StaffAdministration::audit($actor, 'customer_group.deleted', 'customer_group', $group->id, array_keys($before), $before, [], []);
                }
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

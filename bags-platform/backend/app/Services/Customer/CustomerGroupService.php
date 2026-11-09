<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Services\Auth\StaffAdministration;
use App\Services\Shared\SearchTerm;

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
     *
     * @param  array<string, mixed>  $filters  Search criteria and pagination parameters.
     * @return LengthAwarePaginator Paginated group models.
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
     *
     * @param  array<string, mixed>  $data  Group attributes.
     * @param  User|null  $actor  Staff user initiating the action.
     * @param  array<string, mixed>  $via  Audit metadata context.
     * @return CustomerGroup Created group instance.
     *
     * @throws ValidationException If group code is already registered.
     */
    public function create(array $data, ?User $actor = null, array $via = []): CustomerGroup
    {
        $data = array_filter($data, fn ($value, $key) => $value !== null || ! in_array($key, ['discount_percentage', 'is_active'], true), ARRAY_FILTER_USE_BOTH);
        try {
            return DB::transaction(function () use ($data, $actor, $via): CustomerGroup {
                $group = CustomerGroup::query()->create($data)->refresh();

                // Audit group creation because discount percentage impacts wholesale order pricing.
                if ($actor !== null) {
                    StaffAdministration::audit($actor, 'customer_group.created', 'customer_group', $group->id, array_keys($data), [], $group->only(array_keys($data)), $via);
                }

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'كود المجموعة مستخدم بالفعل.']);
        }
    }

    /**
     * Update customer group attributes.
     *
     * @param  CustomerGroup  $group  Target group model.
     * @param  array<string, mixed>  $data  Updated attributes.
     * @param  User|null  $actor  Staff user initiating the update.
     * @param  array<string, mixed>  $via  Audit metadata context.
     * @return CustomerGroup Refreshed group instance.
     *
     * @throws ValidationException If updated code collides with an existing group.
     */
    public function update(CustomerGroup $group, array $data, ?User $actor = null, array $via = []): CustomerGroup
    {
        try {
            return DB::transaction(function () use ($group, $data, $actor, $via): CustomerGroup {
                $group = CustomerGroup::query()->lockForUpdate()->findOrFail($group->id);
                $before = $group->only(array_keys($data));
                $group->update($data);

                // Audit when actor is supplied; assistant tools handle audit logging independently.
                if ($actor !== null) {
                    StaffAdministration::audit($actor, 'customer_group.updated', 'customer_group', $group->id, array_keys($data), $before, $group->only(array_keys($data)), $via);
                }

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'كود المجموعة مستخدم بالفعل.']);
        }
    }

    /**
     * Delete customer group safely under lock, preventing orphaned customer associations.
     *
     * @param  CustomerGroup  $group  Target group model.
     * @param  User|null  $actor  Staff user initiating the deletion.
     *
     * @throws BusinessException If assigned to customers or price lists.
     */
    public function delete(CustomerGroup $group, ?User $actor = null): void
    {
        try {
            DB::transaction(function () use ($group, $actor): void {
                // Lock row before existence check to prevent concurrent customer assignment that would silently lose group via nullOnDelete.
                $group = CustomerGroup::query()->lockForUpdate()->findOrFail($group->id);

                // Prevent deletion if soft-deleted customers exist to preserve association upon restoration.
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
            // Convert foreign key violation (PostgreSQL 23503) into friendly 422 business error.
            if (($e->errorInfo[0] ?? $e->getCode()) === '23503') {
                throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بعملاء أو قوائم أسعار.');
            }

            throw $e;
        }
    }
}

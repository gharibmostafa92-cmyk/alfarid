<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CustomerGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerGroupService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return CustomerGroup::query()
            ->withCount('customers')
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($search) => $search
                ->whereLike('name', '%'.SearchTerm::escape($v).'%', caseSensitive: false)
                ->orWhereLike('code', '%'.SearchTerm::escape($v).'%', caseSensitive: false)))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): CustomerGroup
    {
        $data = array_filter($data, fn ($value, $key) => $value !== null || ! in_array($key, ['discount_percentage', 'is_active'], true), ARRAY_FILTER_USE_BOTH);

        return CustomerGroup::query()->create($data);
    }

    public function update(CustomerGroup $group, array $data): CustomerGroup
    {
        $group->update($data);

        return $group;
    }

    public function delete(CustomerGroup $group): void
    {
        if ($group->customers()->exists()) {
            throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بعملاء؛ انقل العملاء أولًا أو عطّل المجموعة.');
        }

        if ($group->priceLists()->exists()) {
            throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بقوائم أسعار.');
        }

        $group->delete();
    }
}

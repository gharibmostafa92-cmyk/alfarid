<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * إدارة العملاء من لوحة الإدارة: CRUD + دورة اعتماد الجملة (اعتماد/رفض/إيقاف).
 * الإيقاف يقتل كل جلسات العميل فورًا؛ الرفض يبقي توكنه حيًا حتى يطّلع على
 * سبب الرفض ومستنداته المرفوضة عبر application-status.
 */
class CustomerManagementService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Customer::query()
            ->with('customerGroup')
            ->when(($filters['trashed'] ?? null) === 'only', fn ($q) => $q->onlyTrashed())
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['customer_group_id'] ?? null, fn ($q, $v) => $q->where('customer_group_id', $v))
            ->when($filters['search'] ?? null, function ($q, $v): void {
                $term = '%'.SearchTerm::escape($v).'%';
                $q->whereLike('search_text', $term, caseSensitive: false);
            })
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): Customer
    {
        return Customer::query()
            ->with(['customerGroup', 'documents', 'addresses.city', 'approver'])
            ->findOrFail($id);
    }

    public function create(array $data): Customer
    {
        $data['type'] ??= CustomerType::Retail->value;
        $data['credit_limit'] ??= 0;
        // الحالة الافتراضية حسب النوع ما لم تُرسل صراحة: قطاعي معتمد، جملة/موزع قيد المراجعة
        $data['status'] ??= ($data['type'] ?? CustomerType::Retail->value) === CustomerType::Retail->value
            ? CustomerStatus::Approved
            : CustomerStatus::Pending;

        if ($data['status'] === CustomerStatus::Approved || $data['status'] === CustomerStatus::Approved->value) {
            if ($data['type'] !== CustomerType::Retail->value) {
                throw new BusinessException('أنشئ حساب الجملة قيد المراجعة ثم استخدم مسار الاعتماد.');
            }
        }

        return Customer::query()->create($data)->load('customerGroup');
    }

    public function update(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if (isset($data['type']) && $data['type'] !== $customer->type->value) {
                throw new BusinessException('تغيير نوع الحساب المعتمد يتطلب استخدام مسار الاعتماد.');
            }
            if ($customer->status === CustomerStatus::Approved && $customer->type !== CustomerType::Retail) {
                foreach (['company_name', 'tax_number', 'commercial_register'] as $field) {
                    if (array_key_exists($field, $data) && blank($data[$field])) {
                        throw new BusinessException('لا يمكن مسح بيانات الجملة لحساب معتمد.', [$field => ['هذا الحقل مطلوب.']]);
                    }
                }
            }
            $customer->update($data);

            return $customer->load('customerGroup');
        });
    }

    /** حذف ناعم + إنهاء الجلسات: حساب محذوف لا يبقى مسجلًا دخوله. */
    public function delete(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $customer->tokens()->delete();
            $customer->delete();
        });
    }

    /**
     * اعتماد حساب (غالبًا طلب جملة): يطبق اختياريًا المجموعة والحد الائتماني
     * والترقية لموزع، ويسجل المعتمِد ووقته، ويمسح أي سبب رفض/إيقاف سابق.
     */
    public function approve(Customer $customer, array $data, int $adminId): Customer
    {
        return DB::transaction(function () use ($customer, $data, $adminId): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status === CustomerStatus::Approved && ($data['type'] ?? $customer->type->value) === $customer->type->value) {
                throw new BusinessException('هذا الحساب معتمد بالفعل.');
            }
            $legal = [];
            if (($data['type'] ?? $customer->type->value) !== CustomerType::Retail->value) {
                foreach (['company_name', 'tax_number', 'commercial_register'] as $field) {
                    $legal[$field] = $data[$field] ?? $customer->{$field};
                    if (blank($legal[$field])) {
                        throw new BusinessException('بيانات الجملة مطلوبة قبل الاعتماد.', [$field => ['هذا الحقل مطلوب لاعتماد حساب الجملة أو الموزع.']]);
                    }
                }
            }

            $customer->update([
                ...$legal,
                'status' => CustomerStatus::Approved,
                'approved_by' => $adminId,
                'approved_at' => now(),
                'rejection_reason' => null,
                'suspension_reason' => null,
                'customer_group_id' => array_key_exists('customer_group_id', $data) ? $data['customer_group_id'] : $customer->customer_group_id,
                'credit_limit' => $data['credit_limit'] ?? $customer->credit_limit,
                'type' => $data['type'] ?? $customer->type,
            ]);

            return $customer->load(['customerGroup', 'documents']);
        });
    }

    public function reject(Customer $customer, string $reason): Customer
    {
        return DB::transaction(function () use ($customer, $reason): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status !== CustomerStatus::Pending) {
                throw new BusinessException('يمكن رفض الطلبات قيد المراجعة فقط؛ استخدم الإيقاف للحسابات المعتمدة.');
            }

            $customer->update([
                'status' => CustomerStatus::Rejected,
                'rejection_reason' => $reason,
            ]);

            return $customer->load(['customerGroup', 'documents']);
        });
    }

    /** إيقاف فوري: الحالة + السبب + حذف كل توكنات العميل في نفس اللحظة. */
    public function suspend(Customer $customer, string $reason): Customer
    {
        return DB::transaction(function () use ($customer, $reason): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status === CustomerStatus::Suspended) {
                throw new BusinessException('هذا الحساب موقوف بالفعل.');
            }
            $customer->update([
                'status' => CustomerStatus::Suspended,
                'suspension_reason' => $reason,
            ]);
            $customer->tokens()->delete();

            return $customer->load('customerGroup');
        });
    }
}

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
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['customer_group_id'] ?? null, fn ($q, $v) => $q->where('customer_group_id', $v))
            ->when($filters['search'] ?? null, function ($q, $v): void {
                $term = '%'.SearchTerm::escape($v).'%';
                $q->where(fn ($inner) => $inner
                    ->whereLike('name', $term, caseSensitive: false)
                    ->orWhereLike('phone', $term, caseSensitive: false)
                    ->orWhereLike('commercial_register', $term, caseSensitive: false)
                    ->orWhereLike('email', $term, caseSensitive: false));
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
        // الحالة الافتراضية حسب النوع ما لم تُرسل صراحة: قطاعي معتمد، جملة/موزع قيد المراجعة
        $data['status'] ??= ($data['type'] ?? CustomerType::Retail->value) === CustomerType::Retail->value
            ? CustomerStatus::Approved
            : CustomerStatus::Pending;

        return Customer::query()->create($data)->load('customerGroup');
    }

    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer->load('customerGroup');
    }

    /** حذف ناعم + إنهاء الجلسات: حساب محذوف لا يبقى مسجلًا دخوله. */
    public function delete(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
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
        if ($customer->status === CustomerStatus::Approved) {
            throw new BusinessException('هذا الحساب معتمد بالفعل.');
        }

        $customer->update([
            'status' => CustomerStatus::Approved,
            'approved_by' => $adminId,
            'approved_at' => now(),
            'rejection_reason' => null,
            'suspension_reason' => null,
            'customer_group_id' => $data['customer_group_id'] ?? $customer->customer_group_id,
            'credit_limit' => $data['credit_limit'] ?? $customer->credit_limit,
            'type' => $data['type'] ?? $customer->type,
        ]);

        return $customer->load(['customerGroup', 'documents']);
    }

    public function reject(Customer $customer, string $reason): Customer
    {
        if ($customer->status === CustomerStatus::Rejected) {
            throw new BusinessException('هذا الحساب مرفوض بالفعل.');
        }

        $customer->update([
            'status' => CustomerStatus::Rejected,
            'rejection_reason' => $reason,
        ]);

        return $customer->load(['customerGroup', 'documents']);
    }

    /** إيقاف فوري: الحالة + السبب + حذف كل توكنات العميل في نفس اللحظة. */
    public function suspend(Customer $customer, string $reason): Customer
    {
        if ($customer->status === CustomerStatus::Suspended) {
            throw new BusinessException('هذا الحساب موقوف بالفعل.');
        }

        return DB::transaction(function () use ($customer, $reason): Customer {
            $customer->update([
                'status' => CustomerStatus::Suspended,
                'suspension_reason' => $reason,
            ]);
            $customer->tokens()->delete();

            return $customer->load('customerGroup');
        });
    }
}

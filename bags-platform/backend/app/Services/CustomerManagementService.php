<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\CustomerDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            ->paginate($filters['per_page'] ?? 15)->withQueryString();
    }

    public function find(int $id): Customer
    {
        return Customer::query()
            ->with(['customerGroup', 'documents', 'addresses.city', 'approver'])
            ->findOrFail($id);
    }

    public function create(array $data, ?int $actorId = null): Customer
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

        return DB::transaction(function () use ($data, $actorId): Customer {
            if (in_array($data['status'], [CustomerStatus::Approved, CustomerStatus::Approved->value], true)) {
                $data['approved_at'] = now();
                $data['approved_by'] = $actorId;
            }
            $customer = Customer::query()->create($data);
            CustomerAuditLog::record($customer, 'created', $actorId);

            return $customer->load('customerGroup');
        });
    }

    public function update(Customer $customer, array $data, ?int $actorId = null): Customer
    {
        return DB::transaction(function () use ($customer, $data, $actorId): Customer {
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
            $businessIdentityChanged = collect(['company_name', 'tax_number', 'commercial_register'])
                ->contains(fn ($field) => array_key_exists($field, $data) && $data[$field] !== $customer->{$field});
            $credentialsChanged = array_key_exists('password', $data)
                || (isset($data['email']) && $data['email'] !== $customer->email)
                || (isset($data['phone']) && $data['phone'] !== $customer->phone)
                || (array_key_exists('email', $data) && $data['email'] === null && $customer->email !== null);
            if (isset($data['phone']) && $data['phone'] !== $customer->phone) {
                $customer->forceFill(['phone_verified_at' => null]);
            }
            $customer->fill($data);
            if ($credentialsChanged) {
                $customer->forceFill(['remember_token' => Str::random(60)]);
                $customer->tokens()->delete();
                DB::table('password_reset_tokens')->where('email', $customer->getEmailForPasswordReset())->delete();
            }
            $customer->save();
            CustomerAuditLog::record($customer, $credentialsChanged ? 'credentials_changed' : 'updated', $actorId, ['fields' => array_keys($data)]);
            if ($businessIdentityChanged) {
                CustomerAuditLog::record($customer, 'business_identity_changed', $actorId, ['fields' => array_keys(array_intersect_key($data, array_flip(['company_name', 'tax_number', 'commercial_register'])))]);
            }

            return $customer->load('customerGroup');
        });
    }

    /** حذف ناعم + إنهاء الجلسات: حساب محذوف لا يبقى مسجلًا دخوله. */
    public function delete(Customer $customer, ?int $actorId = null): void
    {
        DB::transaction(function () use ($customer, $actorId): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $customer->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $customer->getEmailForPasswordReset())->delete();
            CustomerAuditLog::record($customer, 'deleted', $actorId);
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
            if ($customer->documents()->where('status', '!=', 'verified')->exists()) {
                throw new BusinessException('راجع واعتمد جميع المستندات المرفقة قبل اعتماد الحساب.');
            }
            if (($data['type'] ?? $customer->type->value) !== CustomerType::Retail->value) {
                if (! $customer->documents()->where('status', 'verified')->exists()) {
                    throw new BusinessException('يلزم مستند موثّق واحد على الأقل لاعتماد حساب الجملة أو الموزع.');
                }
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
            CustomerAuditLog::record($customer, 'approved', $adminId, ['type' => $customer->type->value, 'credit_limit' => $customer->credit_limit, 'customer_group_id' => $customer->customer_group_id]);

            return $customer->load(['customerGroup', 'documents']);
        });
    }

    public function reject(Customer $customer, string $reason, ?int $actorId = null): Customer
    {
        return DB::transaction(function () use ($customer, $reason, $actorId): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status !== CustomerStatus::Pending) {
                throw new BusinessException('يمكن رفض الطلبات قيد المراجعة فقط؛ استخدم الإيقاف للحسابات المعتمدة.');
            }

            $customer->update([
                'status' => CustomerStatus::Rejected,
                'rejection_reason' => $reason,
            ]);
            CustomerAuditLog::record($customer, 'rejected', $actorId, ['reason' => $reason]);

            return $customer->load(['customerGroup', 'documents']);
        });
    }

    /** إيقاف فوري: الحالة + السبب + حذف كل توكنات العميل في نفس اللحظة. */
    public function suspend(Customer $customer, string $reason, ?int $actorId = null): Customer
    {
        return DB::transaction(function () use ($customer, $reason, $actorId): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status === CustomerStatus::Suspended) {
                throw new BusinessException('هذا الحساب موقوف بالفعل.');
            }
            $customer->update([
                'status' => CustomerStatus::Suspended,
                'suspension_reason' => $reason,
            ]);
            $customer->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $customer->getEmailForPasswordReset())->delete();
            CustomerAuditLog::record($customer, 'suspended', $actorId, ['reason' => $reason]);

            return $customer->load('customerGroup');
        });
    }

    public function restore(Customer $customer, ?int $actorId): Customer
    {
        return DB::transaction(function () use ($customer, $actorId): Customer {
            $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($customer->id);
            if (! $customer->trashed()) {
                throw new BusinessException('الحساب غير محذوف.');
            }
            $customer->restore();
            CustomerAuditLog::record($customer, 'restored', $actorId);

            return $customer->load('customerGroup');
        });
    }

    public function reviewDocument(Customer $customer, CustomerDocument $document, string $status, int $actorId): CustomerDocument
    {
        return DB::transaction(function () use ($customer, $document, $status, $actorId) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $document = $customer->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $document->forceFill(['status' => $status, 'reviewed_by' => $actorId, 'reviewed_at' => now()])->save();
            CustomerAuditLog::record($customer, 'document_reviewed', $actorId, ['document_id' => $document->id, 'status' => $status]);

            return $document;
        });
    }
}

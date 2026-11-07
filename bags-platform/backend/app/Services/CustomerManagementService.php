<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Events\WholesaleApplicationReviewed;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\CustomerDocument;
use App\Models\CustomerGroup;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Rules\UniqueCustomerEmail;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer administration and lifecycle management (CRUD, wholesale verification, approval, rejection, and suspension).
 * Immediate suspension invalidates all active sessions; rejection keeps tokens active to review rejection reasons.
 */
class CustomerManagementService
{
    private const MAX_ACTIVE_DOCUMENTS = 5;

    private const MAX_TOTAL_DOCUMENTS = 10;

    /**
     * Paginate customers with search, type, and status filtering.
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Customer::query()
            ->with('customerGroup')
            ->when(($filters['trashed'] ?? null) === 'only', fn ($q) => $q->onlyTrashed())
            ->when($filters['business_only'] ?? false, fn ($q) => $q->whereIn('type', ['wholesale', 'distributor']))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['customer_group_id'] ?? null, fn ($q, $v) => $q->where('customer_group_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->whereLike('search_text', SearchTerm::pattern($v), caseSensitive: false))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)->withQueryString();
    }

    /**
     * Retrieve customer with complete profile relations.
     */
    public function find(int $id): Customer
    {
        return Customer::query()
            ->with(['customerGroup', 'documents', 'addresses.city', 'approver'])
            ->findOrFail($id);
    }

    /**
     * Merge the execution channel marker (AI vs REST) into audit metadata.
     * REST calls pass an empty $via, so their recorded metadata stays
     * byte-identical to before; AI calls carry channel/conversation/action.
     */
    private function viaMeta(array $via, array $meta = []): array
    {
        return $via === [] ? $meta : [...$meta, 'via' => $via];
    }

    /**
     * Acquire a transactional advisory lock and re-validate customer email uniqueness under concurrency.
     *
     * @param  string|null  $email  Email address to claim.
     * @param  int|null  $ignoreId  Customer ID to exclude from unique checks during updates.
     */
    private function claimEmail(?string $email, ?int $ignoreId = null): void
    {
        if ($email === null || trim($email) === '') {
            return;
        }
        $email = mb_strtolower(trim($email));
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$email]);
        Validator::make(['email' => $email], [
            'email' => [new UniqueCustomerEmail($ignoreId), new EmailNotUsedByOtherAccountType('customers')],
        ])->validate();
    }

    /**
     * Assert that the specified customer group exists and is currently active.
     */
    private function assertActiveGroup(?int $groupId): void
    {
        if ($groupId !== null && ! CustomerGroup::query()->whereKey($groupId)->where('is_active', true)->exists()) {
            throw new BusinessException('مجموعة العملاء غير موجودة أو معطلة.', ['customer_group_id' => ['اختر مجموعة مفعّلة.']]);
        }
    }

    /**
     * Create customer record from admin console.
     * Enforces Approved status for retail and Pending for wholesale accounts.
     *
     * @throws BusinessException If attempting to directly create an approved wholesale account
     */
    public function create(array $data, ?int $actorId = null, array $via = []): Customer
    {
        $data['type'] ??= CustomerType::Retail->value;
        $data['credit_limit'] ??= 0;
        // Default status by type unless explicitly provided: retail is approved, wholesale requires review.
        $data['status'] ??= ($data['type'] ?? CustomerType::Retail->value) === CustomerType::Retail->value
            ? CustomerStatus::Approved
            : CustomerStatus::Pending;

        if ($data['status'] === CustomerStatus::Approved || $data['status'] === CustomerStatus::Approved->value) {
            if ($data['type'] !== CustomerType::Retail->value) {
                throw new BusinessException('أنشئ حساب الجملة قيد المراجعة ثم استخدم مسار الاعتماد.');
            }
        }

        try {
            return DB::transaction(function () use ($data, $actorId, $via): Customer {
                $this->claimEmail($data['email'] ?? null);
                $this->assertActiveGroup(isset($data['customer_group_id']) ? (int) $data['customer_group_id'] : null);
                if (in_array($data['status'], [CustomerStatus::Approved, CustomerStatus::Approved->value], true)) {
                    $data['approved_at'] = now();
                    $data['approved_by'] = $actorId;
                }
                $customer = (new Customer)->forceFill($data);
                $customer->save();
                CustomerAuditLog::record($customer, 'created', $actorId, $this->viaMeta($via));

                return $customer->load('customerGroup');
            });
        } catch (UniqueConstraintViolationException $e) {
            throw Customer::translateUniqueViolation($e);
        }
    }

    /**
     * Update customer profile, credentials, and business identity.
     * Invalidates active sessions and reset tokens if credentials change.
     */
    public function update(Customer $customer, array $data, ?int $actorId = null, array $via = []): Customer
    {
        try {
            return DB::transaction(function () use ($customer, $data, $actorId, $via): Customer {
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                if (isset($data['email']) && $data['email'] !== $customer->email) {
                    $this->claimEmail($data['email'], $customer->id);
                }
                if (array_key_exists('customer_group_id', $data) && $data['customer_group_id'] !== null
                    && (int) $data['customer_group_id'] !== (int) $customer->customer_group_id) {
                    $this->assertActiveGroup((int) $data['customer_group_id']);
                }
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

                // Track previous and new values for financial attributes in the audit payload.
                $financial = [];
                if (array_key_exists('credit_limit', $data) && (float) $data['credit_limit'] !== (float) $customer->credit_limit) {
                    $financial['credit_limit'] = ['before' => (string) $customer->credit_limit, 'after' => (string) $data['credit_limit']];
                }
                if (array_key_exists('customer_group_id', $data)) {
                    $old = $customer->customer_group_id === null ? null : (int) $customer->customer_group_id;
                    $new = $data['customer_group_id'] === null ? null : (int) $data['customer_group_id'];
                    if ($old !== $new) {
                        $financial['customer_group_id'] = ['before' => $old, 'after' => $new];
                    }
                }
                if (array_key_exists('whatsapp_opt_in', $data)) {
                    $data['whatsapp_opt_in_at'] = $data['whatsapp_opt_in']
                        ? ($customer->whatsapp_opt_in_at ?? now())
                        : null;
                    unset($data['whatsapp_opt_in']);
                }
                $customer->fill(Arr::except($data, ['credit_limit']));
                if (array_key_exists('credit_limit', $data)) {
                    $customer->forceFill(['credit_limit' => $data['credit_limit']]);
                }
                if ($credentialsChanged) {
                    $customer->forceFill(['remember_token' => Str::random(60)]);
                    $customer->tokens()->delete();
                    DB::table('password_reset_tokens')->where('email', $customer->getEmailForPasswordReset())->delete();
                }
                $customer->save();
                CustomerAuditLog::record($customer, $credentialsChanged ? 'credentials_changed' : 'updated', $actorId, $this->viaMeta($via, ['fields' => array_keys($data)] + ($financial !== [] ? ['changes' => $financial] : [])));
                if ($businessIdentityChanged) {
                    CustomerAuditLog::record($customer, 'business_identity_changed', $actorId, $this->viaMeta($via, ['fields' => array_keys(array_intersect_key($data, array_flip(['company_name', 'tax_number', 'commercial_register'])))]));
                }

                return $customer->load('customerGroup');
            });
        } catch (UniqueConstraintViolationException $e) {
            throw Customer::translateUniqueViolation($e);
        }
    }

    /**
     * Soft-delete customer and revoke all active tokens/sessions.
     */
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
     * Approve customer application (wholesale/distributor).
     * Validates verified supporting documents and assigns group, credit limit, and approver timestamp.
     *
     * @throws BusinessException If documents unverified or required legal info missing
     */
    public function approve(Customer $customer, array $data, int $adminId, array $via = []): Customer
    {
        return DB::transaction(function () use ($customer, $data, $adminId, $via): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status === CustomerStatus::Suspended) {
                throw new BusinessException('الحساب موقوف؛ استخدم إعادة التفعيل بدل الاعتماد.');
            }
            $this->assertActiveGroup(array_key_exists('customer_group_id', $data) && $data['customer_group_id'] !== null ? (int) $data['customer_group_id'] : null);
            if ($customer->status === CustomerStatus::Approved && ($data['type'] ?? $customer->type->value) === $customer->type->value) {
                throw new BusinessException('هذا الحساب معتمد بالفعل.');
            }
            $legal = [];

            // Exclude rejected documents from blocking approval; only pending documents require resolution.
            if ($customer->documents()->where('status', CustomerDocumentStatus::Pending->value)->exists()) {
                throw new BusinessException('راجع المستندات المعلقة (اعتمد السليم وارفض غير السليم) قبل اعتماد الحساب.');
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

            $customer->forceFill([
                ...$legal,
                'status' => CustomerStatus::Approved,
                'approved_by' => $adminId,
                'approved_at' => now(),
                'rejection_reason' => null,
                'suspension_reason' => null,
                'pre_suspension_status' => null,
                'customer_group_id' => array_key_exists('customer_group_id', $data) ? $data['customer_group_id'] : $customer->customer_group_id,
                'credit_limit' => $data['credit_limit'] ?? $customer->credit_limit,
                'type' => $data['type'] ?? $customer->type,
            ])->save();
            CustomerAuditLog::record($customer, 'approved', $adminId, $this->viaMeta($via, ['type' => $customer->type->value, 'credit_limit' => $customer->credit_limit, 'customer_group_id' => $customer->customer_group_id]));

            if ($customer->type !== CustomerType::Retail) {
                WholesaleApplicationReviewed::dispatch($customer, 'approved');
            }

            return $customer->load(['customerGroup', 'documents']);
        });
    }

    /**
     * Reject a pending customer application with a recorded reason.
     *
     * @throws BusinessException If customer is not in Pending status
     */
    public function reject(Customer $customer, string $reason, ?int $actorId = null, array $via = []): Customer
    {
        return DB::transaction(function () use ($customer, $reason, $actorId, $via): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status !== CustomerStatus::Pending) {
                throw new BusinessException('يمكن رفض الطلبات قيد المراجعة فقط؛ استخدم الإيقاف للحسابات المعتمدة.');
            }

            $customer->forceFill([
                'status' => CustomerStatus::Rejected,
                'rejection_reason' => $reason,
            ])->save();
            CustomerAuditLog::record($customer, 'rejected', $actorId, $this->viaMeta($via, ['reason' => $reason]));

            if ($customer->type !== CustomerType::Retail) {
                WholesaleApplicationReviewed::dispatch($customer, 'rejected', $reason);
            }

            return $customer->load(['customerGroup', 'documents']);
        });
    }

    /**
     * Suspend customer account and revoke all active sessions immediately.
     */
    public function suspend(Customer $customer, string $reason, ?int $actorId = null, array $via = []): Customer
    {
        return DB::transaction(function () use ($customer, $reason, $actorId, $via): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status === CustomerStatus::Suspended) {
                throw new BusinessException('هذا الحساب موقوف بالفعل.');
            }
            $previous = $customer->status->value;
            $customer->forceFill([
                'status' => CustomerStatus::Suspended,
                'suspension_reason' => $reason,
                'pre_suspension_status' => $previous,
            ])->save();
            $customer->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $customer->getEmailForPasswordReset())->delete();
            CustomerAuditLog::record($customer, 'suspended', $actorId, $this->viaMeta($via, ['reason' => $reason, 'previous_status' => $previous]));

            return $customer->load('customerGroup');
        });
    }

    /**
     * Restore a soft-deleted customer account.
     */
    public function restore(Customer $customer, ?int $actorId, array $via = []): Customer
    {
        return DB::transaction(function () use ($customer, $actorId, $via): Customer {
            $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($customer->id);
            if (! $customer->trashed()) {
                throw new BusinessException('الحساب غير محذوف.');
            }

            // Re-claim email under lock in case another account registered the same address while deleted.
            try {
                $this->claimEmail($customer->email, $customer->id);
            } catch (ValidationException) {
                throw new BusinessException('لا يمكن الاستعادة: البريد '.$customer->email.' مستخدم الآن بحساب نشط آخر. غيّر بريد الحساب الآخر أولاً.');
            }
            $customer->restore();
            CustomerAuditLog::record($customer, 'restored', $actorId, $this->viaMeta($via));

            return $customer->load('customerGroup');
        });
    }

    /**
     * Review supporting customer verification document status (verified/rejected).
     */
    public function reviewDocument(Customer $customer, CustomerDocument $document, string $status, int $actorId, ?string $note = null, array $via = []): CustomerDocument
    {
        return DB::transaction(function () use ($customer, $document, $status, $actorId, $note, $via) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $document = $customer->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $document->forceFill(['status' => $status, 'reviewed_by' => $actorId, 'reviewed_at' => now(), 'review_note' => $note])->save();
            CustomerAuditLog::record($customer, 'document_reviewed', $actorId, $this->viaMeta($via, ['document_id' => $document->id, 'status' => $status]));

            return $document;
        });
    }

    /**
     * Reactivate a suspended customer account, restoring their pre-suspension verification status.
     *
     * @param  Customer  $customer  Suspended customer model.
     * @param  int|null  $actorId  Reviewing staff member ID.
     * @param  array<string, mixed>  $via  Audit metadata context.
     * @param  string|null  $restoreTo  Optional explicit target status override.
     * @return Customer Refreshed customer instance.
     *
     * @throws BusinessException If customer is not suspended or legal attributes are incomplete.
     */
    public function reactivate(Customer $customer, ?int $actorId = null, array $via = [], ?string $restoreTo = null): Customer
    {
        return DB::transaction(function () use ($customer, $actorId, $via, $restoreTo) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($locked->status !== CustomerStatus::Suspended) {
                throw new BusinessException('الحساب مش موقوف.');
            }

            // Revert to pre-suspension status to prevent rejected applications from silently re-entering pending queue.
            $target = CustomerStatus::tryFrom((string) ($restoreTo ?? $locked->pre_suspension_status));
            if ($target === null || $target === CustomerStatus::Suspended) {
                $target = $locked->approved_at ? CustomerStatus::Approved : CustomerStatus::Pending;
            }

            // Explicit attribute check prevents PostgreSQL check constraint violations when reactivating approved wholesale accounts.
            if ($target === CustomerStatus::Approved && $locked->type !== CustomerType::Retail) {
                $missing = array_values(array_filter(['company_name', 'tax_number', 'commercial_register'], fn ($f) => blank($locked->{$f})));
                if ($missing !== []) {
                    throw new BusinessException('لا يمكن إعادة تفعيل حساب جملة وبياناته القانونية ناقصة؛ كمّلها الأول.', array_fill_keys($missing, ['هذا الحقل مطلوب.']));
                }
            }
            $locked->forceFill(['status' => $target, 'suspension_reason' => null, 'pre_suspension_status' => null])->save();
            CustomerAuditLog::record($locked, 'reactivated', $actorId, $this->viaMeta($via, ['status' => $target->value]));

            return $locked->fresh(['customerGroup']);
        });
    }

    /**
     * Store and associate a private customer document up to a limit of 5 documents.
     */
    public function uploadDocument(Customer $customer, UploadedFile $file, ?int $actorId, ?CustomerDocumentType $type = null, bool $selfService = false): CustomerDocument
    {
        return PrivateAttachments::transaction(function (PrivateAttachments $files) use ($customer, $file, $actorId, $type, $selfService): CustomerDocument {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($customer->type === CustomerType::Retail) {
                throw new BusinessException('رفع المستندات متاح للتجار والموزعين فقط.');
            }
            if ($selfService && $customer->status !== CustomerStatus::Pending) {
                throw new BusinessException('رفع المستندات متاح للطلبات قيد المراجعة فقط.', [], 403);
            }

            // Enforce separate active and lifetime document caps to prevent storage quota exhaustion.
            $active = $customer->documents()->where('status', '!=', CustomerDocumentStatus::Rejected->value)->count();
            $total = $customer->documents()->count();
            if ($active >= self::MAX_ACTIVE_DOCUMENTS || $total >= self::MAX_TOTAL_DOCUMENTS) {
                throw new BusinessException('وصلت للحد الأقصى للمستندات ('.self::MAX_ACTIVE_DOCUMENTS.' فعّالة / '.self::MAX_TOTAL_DOCUMENTS.' إجمالي).');
            }

            $path = $files->store($file, 'customer_documents/'.$customer->id);
            $document = $customer->documents()->create([
                'document_type' => $type ?? CustomerDocumentType::Other,
                'file_path' => $path,
                'original_name' => CustomerDocument::safeName($file->getClientOriginalName()),
                'mime_type' => $file->getMimeType(),
                'status' => CustomerDocumentStatus::Pending,
            ]);
            CustomerAuditLog::record($customer, 'document_uploaded', $actorId, ['document_id' => $document->id] + ($selfService ? ['channel' => 'wholesale_self'] : []));

            return $document;
        }, [[$file, 'customer_documents/'.$customer->id]]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Enums\Customer\CustomerDocumentStatus;
use App\Enums\Customer\CustomerDocumentType;
use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Events\Wholesale\WholesaleApplicationReviewed;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\CustomerDocument;
use App\Models\CustomerGroup;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Rules\UniqueCustomerEmail;
use App\Services\Shared\PrivateAttachments;
use App\Services\Shared\SearchTerm;
use App\Support\AccountEmailLock;
use Carbon\Carbon;
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

    private const COMMERCIAL_LEGAL_FIELDS = ['company_name', 'tax_number', 'commercial_register'];

    /**
     * Paginate customers with search, type, and status filtering.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Customer::query()
            ->with('customerGroup')
            ->when(($filters['trashed'] ?? null) === 'only', fn ($q) => $q->onlyTrashed())
            ->when($filters['business_only'] ?? false, fn ($q) => $q->commercial())
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
     * Create customer record from admin console.
     * Enforces Approved status for retail and Pending for wholesale accounts.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $via
     *
     * @throws BusinessException If attempting to directly create an approved wholesale account
     */
    public function create(array $data, ?int $actorId = null, array $via = []): Customer
    {
        $data = $this->normalizeCreationAttributes($data);
        $this->assertValidCreationPolicy($data['type'], $data['status']);

        $isApproved = $data['status'] === CustomerStatus::Approved->value;

        try {
            return DB::transaction(function () use ($data, $actorId, $via, $isApproved): Customer {
                $this->claimEmail($data['email'] ?? null);
                $this->assertActiveGroup(isset($data['customer_group_id']) ? (int) $data['customer_group_id'] : null);
                if ($isApproved) {
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
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $via
     */
    public function update(Customer $customer, array $data, ?int $actorId = null, array $via = []): Customer
    {
        try {
            return DB::transaction(function () use ($customer, $data, $actorId, $via): Customer {
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

                $newGroupId = array_key_exists('customer_group_id', $data) && $data['customer_group_id'] !== null
                    ? (int) $data['customer_group_id']
                    : null;
                $oldGroupId = $customer->customer_group_id === null ? null : (int) $customer->customer_group_id;

                $this->assertOptimisticLockIfSensitiveChange($customer, $data, $newGroupId, $oldGroupId);
                unset($data['expected_version'], $data['expected_updated_at']);

                if (isset($data['email']) && $data['email'] !== $customer->email) {
                    $this->claimEmail($data['email'], $customer->id);
                }
                if ($newGroupId !== null && $newGroupId !== $oldGroupId) {
                    $this->assertActiveGroup($newGroupId);
                }
                if (isset($data['type']) && $data['type'] !== $customer->type->value) {
                    throw new BusinessException('تغيير نوع الحساب المعتمد يتطلب استخدام مسار الاعتماد.');
                }

                $this->assertApprovedWholesaleDataNotCleared($customer, $data);

                $businessIdentityChanged = $this->hasBusinessIdentityChanges($customer, $data);
                $credentialsChanged = $this->hasCredentialChanges($customer, $data);
                $phoneChanged = $this->hasPhoneChanges($customer, $data);

                if (isset($data['phone']) && $data['phone'] !== $customer->phone) {
                    $customer->forceFill(['phone_verified_at' => null]);
                }
                if ($phoneChanged) {
                    DB::table('customer_channels')->where('customer_id', $customer->id)->delete();
                }

                $financialDiff = $this->buildFinancialAuditDiff($customer, $data, $oldGroupId, $newGroupId);
                $this->syncWhatsAppOptIn($customer, $data);

                $customer->fill(Arr::except($data, ['credit_limit']));
                if (array_key_exists('credit_limit', $data)) {
                    $customer->forceFill(['credit_limit' => $data['credit_limit']]);
                }
                if ($credentialsChanged) {
                    $customer->forceFill(['remember_token' => Str::random(60)]);
                    $this->revokeSessionsAndResetTokens($customer);
                }
                $customer->save();

                $auditMeta = $this->viaMeta($via, ['fields' => array_keys($data)] + ($financialDiff !== [] ? ['changes' => $financialDiff] : []));
                CustomerAuditLog::record($customer, $credentialsChanged ? 'credentials_changed' : 'updated', $actorId, $auditMeta);

                if ($businessIdentityChanged) {
                    $legalFields = array_keys(array_intersect_key($data, array_flip(self::COMMERCIAL_LEGAL_FIELDS)));
                    CustomerAuditLog::record($customer, 'business_identity_changed', $actorId, $this->viaMeta($via, ['fields' => $legalFields]));
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
            $this->revokeSessionsAndResetTokens($customer);
            CustomerAuditLog::record($customer, 'deleted', $actorId);
            $customer->delete();
        });
    }

    /**
     * Approve customer application (wholesale/distributor).
     * Validates verified supporting documents and assigns group, credit limit, and approver timestamp.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $via
     *
     * @throws BusinessException If documents unverified or required legal info missing
     */
    public function approve(Customer $customer, array $data, int $adminId, array $via = []): Customer
    {
        return DB::transaction(function () use ($customer, $data, $adminId, $via): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $requestedType = isset($data['type']) ? $this->canonicalTypeValue($data['type']) : null;
            $targetType = $requestedType ?? $customer->type->value;

            $this->assertApprovable($customer, $requestedType);
            $this->assertActiveGroup(array_key_exists('customer_group_id', $data) && $data['customer_group_id'] !== null ? (int) $data['customer_group_id'] : null);
            $this->assertDocumentsAllowApproval($customer, $targetType);

            $legal = $this->resolveCommercialLegalFieldsForApproval($customer, $data, $targetType);
            $previousType = $this->canonicalTypeValue($customer->type);

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

            $nextType = $this->canonicalTypeValue($customer->type);
            if ($nextType !== $previousType) {
                // A channel change invalidates previously issued tokens: force
                // re-authentication so the new token matches the new channel.
                $customer->tokens()->delete();
            }

            CustomerAuditLog::record(
                $customer,
                'approved',
                $adminId,
                $this->viaMeta($via, [
                    'type' => $customer->type->value,
                    'credit_limit' => $customer->credit_limit,
                    'customer_group_id' => $customer->customer_group_id,
                ])
            );

            if ($customer->isCommercialTier()) {
                WholesaleApplicationReviewed::dispatch($customer, 'approved');
            }

            return $customer->load(['customerGroup', 'documents']);
        });
    }

    /**
     * Reject a pending customer application with a recorded reason.
     *
     * @param  array<string, mixed>  $via
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

            if ($customer->isCommercialTier()) {
                WholesaleApplicationReviewed::dispatch($customer, 'rejected', $reason);
            }

            return $customer->load(['customerGroup', 'documents']);
        });
    }

    /**
     * Suspend customer account and revoke all active sessions immediately.
     *
     * @param  array<string, mixed>  $via
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
            $this->revokeSessionsAndResetTokens($customer);
            CustomerAuditLog::record($customer, 'suspended', $actorId, $this->viaMeta($via, ['reason' => $reason, 'previous_status' => $previous]));

            return $customer->load('customerGroup');
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

            $target = $this->resolveReactivationTargetStatus($locked, $restoreTo);

            if ($target === CustomerStatus::Approved && $locked->isCommercialTier()) {
                $this->assertCommercialLegalAttributesComplete($locked);
            }

            $locked->forceFill([
                'status' => $target,
                'suspension_reason' => null,
                'pre_suspension_status' => null,
            ])->save();

            CustomerAuditLog::record($locked, 'reactivated', $actorId, $this->viaMeta($via, ['status' => $target->value]));

            return $locked->fresh(['customerGroup']);
        });
    }

    /**
     * Restore a soft-deleted customer account.
     *
     * @param  array<string, mixed>  $via
     */
    public function restore(Customer $customer, ?int $actorId, array $via = []): Customer
    {
        return DB::transaction(function () use ($customer, $actorId, $via): Customer {
            $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($customer->id);
            if (! $customer->trashed()) {
                throw new BusinessException('الحساب غير محذوف.');
            }

            $this->assertRestoreEligibility($customer);

            try {
                $customer->restore();
            } catch (UniqueConstraintViolationException) {
                throw new BusinessException('لا يمكن الاستعادة: بيانات الحساب تتعارض مع حساب نشط آخر.');
            }
            CustomerAuditLog::record($customer, 'restored', $actorId, $this->viaMeta($via));

            return $customer->load('customerGroup');
        });
    }

    /**
     * Review supporting customer verification document status (verified/rejected).
     *
     * @param  array<string, mixed>  $via
     */
    public function reviewDocument(Customer $customer, CustomerDocument $document, CustomerDocumentStatus|string $status, int $actorId, ?string $note = null, array $via = []): CustomerDocument
    {
        $status = $status instanceof CustomerDocumentStatus ? $status : CustomerDocumentStatus::tryFrom($status);
        if (! in_array($status, [CustomerDocumentStatus::Verified, CustomerDocumentStatus::Rejected], true)) {
            throw new BusinessException('حالة مراجعة المستند يجب أن تكون معتمدًا أو مرفوضًا.');
        }

        return DB::transaction(function () use ($customer, $document, $status, $actorId, $note, $via) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $document = $customer->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $document->forceFill([
                'status' => $status->value,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
            CustomerAuditLog::record($customer, 'document_reviewed', $actorId, $this->viaMeta($via, ['document_id' => $document->id, 'status' => $status->value]));

            return $document;
        });
    }

    /**
     * Store and associate a private customer document up to a limit of 5 documents.
     */
    public function uploadDocument(Customer $customer, UploadedFile $file, ?int $actorId, ?CustomerDocumentType $type = null, bool $selfService = false): CustomerDocument
    {
        return PrivateAttachments::transaction(function (PrivateAttachments $files) use ($customer, $file, $actorId, $type, $selfService): CustomerDocument {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if (! $customer->isCommercialTier()) {
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

    // ---------- Stepdown Private Helpers ----------

    /**
     * Normalizes input attributes for customer creation, guaranteeing scalar types and defaults.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeCreationAttributes(array $data): array
    {
        $data['type'] ??= CustomerType::Retail->value;
        $data['type'] = $this->canonicalTypeValue($data['type']);
        $data['status'] = isset($data['status']) ? $this->canonicalStatusValue($data['status']) : null;
        $data['credit_limit'] ??= 0;

        $data['status'] ??= $data['type'] === CustomerType::Retail->value
            ? CustomerStatus::Approved->value
            : CustomerStatus::Pending->value;

        return $data;
    }

    /**
     * Enforces the policy that retail accounts must be created approved,
     * while wholesale accounts must be created pending review.
     */
    private function assertValidCreationPolicy(string $type, string $status): void
    {
        $isRetail = $type === CustomerType::Retail->value;
        $isApproved = $status === CustomerStatus::Approved->value;

        if ($isRetail && ! $isApproved) {
            throw new BusinessException('حسابات التجزئة يجب أن تكون معتمدة عند إنشائها.');
        }

        if ($isApproved && ! $isRetail) {
            throw new BusinessException('أنشئ حساب الجملة قيد المراجعة ثم استخدم مسار الاعتماد.');
        }
    }

    /**
     * Validates concurrency token (lock_version or expected_updated_at) when modifying sensitive financial fields.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertOptimisticLockIfSensitiveChange(Customer $customer, array $data, ?int $newGroupId, ?int $oldGroupId): void
    {
        $creditLimitChanged = array_key_exists('credit_limit', $data) && (float) $data['credit_limit'] !== (float) $customer->credit_limit;
        $groupChanged = array_key_exists('customer_group_id', $data) && $newGroupId !== $oldGroupId;

        if (! $creditLimitChanged && ! $groupChanged) {
            return;
        }

        if (array_key_exists('expected_version', $data) && $data['expected_version'] !== null) {
            if ((int) $data['expected_version'] !== (int) $customer->lock_version) {
                throw new BusinessException('تعدّل ملف العميل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
            }

            return;
        }

        if (! empty($data['expected_updated_at'])) {
            $expected = Carbon::parse($data['expected_updated_at'])->utc()->timestamp;
            // Fail closed on a stale copy: two consoles editing the same
            // credit limit must not silently overwrite each other.
            if ($expected !== $customer->updated_at->copy()->utc()->timestamp) {
                throw new BusinessException('تعدّل ملف العميل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
            }

            return;
        }

        throw new BusinessException('يجب تمرير رمز التزامن لتعديل الحدود المالية للعميل.', [], 409);
    }

    /**
     * Prevents clearing required commercial legal data on an already approved wholesale account.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertApprovedWholesaleDataNotCleared(Customer $customer, array $data): void
    {
        if (! $customer->isWholesaleApproved()) {
            return;
        }

        foreach (self::COMMERCIAL_LEGAL_FIELDS as $field) {
            if (array_key_exists($field, $data) && blank($data[$field])) {
                throw new BusinessException('لا يمكن مسح بيانات الجملة لحساب معتمد.', [$field => ['هذا الحقل مطلوب.']]);
            }
        }
    }

    /**
     * Checks if business identity fields differ from the customer's persisted attributes.
     *
     * @param  array<string, mixed>  $data
     */
    private function hasBusinessIdentityChanges(Customer $customer, array $data): bool
    {
        return collect(self::COMMERCIAL_LEGAL_FIELDS)
            ->contains(fn ($field) => array_key_exists($field, $data) && $data[$field] !== $customer->{$field});
    }

    /**
     * Checks if password, email, or phone attributes are changing.
     *
     * @param  array<string, mixed>  $data
     */
    private function hasCredentialChanges(Customer $customer, array $data): bool
    {
        return array_key_exists('password', $data)
            || (isset($data['email']) && $data['email'] !== $customer->email)
            || (isset($data['phone']) && $data['phone'] !== $customer->phone)
            || (array_key_exists('email', $data) && $data['email'] === null && $customer->email !== null);
    }

    /**
     * Checks if regular phone or WhatsApp phone numbers are changing.
     *
     * @param  array<string, mixed>  $data
     */
    private function hasPhoneChanges(Customer $customer, array $data): bool
    {
        return (isset($data['phone']) && $data['phone'] !== $customer->phone)
            || (array_key_exists('whatsapp_phone', $data) && $data['whatsapp_phone'] !== $customer->whatsapp_phone);
    }

    /**
     * Computes the before/after change map for financial attributes (credit limit, customer group).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function buildFinancialAuditDiff(Customer $customer, array $data, ?int $oldGroupId, ?int $newGroupId): array
    {
        $financial = [];
        if (array_key_exists('credit_limit', $data) && (float) $data['credit_limit'] !== (float) $customer->credit_limit) {
            $financial['credit_limit'] = [
                'before' => (string) $customer->credit_limit,
                'after' => (string) $data['credit_limit'],
            ];
        }
        if (array_key_exists('customer_group_id', $data) && $oldGroupId !== $newGroupId) {
            $financial['customer_group_id'] = [
                'before' => $oldGroupId,
                'after' => $newGroupId,
            ];
        }

        return $financial;
    }

    /**
     * Synchronizes whatsapp_opt_in_at timestamp based on the incoming boolean flag.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncWhatsAppOptIn(Customer $customer, array &$data): void
    {
        if (! array_key_exists('whatsapp_opt_in', $data)) {
            return;
        }

        $data['whatsapp_opt_in_at'] = $data['whatsapp_opt_in']
            ? ($customer->whatsapp_opt_in_at ?? now())
            : null;
        unset($data['whatsapp_opt_in']);
    }

    /**
     * Validates that the customer is eligible to transition to Approved status.
     */
    private function assertApprovable(Customer $customer, ?string $requestedType): void
    {
        if ($customer->status === CustomerStatus::Suspended) {
            throw new BusinessException('الحساب موقوف؛ استخدم إعادة التفعيل بدل الاعتماد.');
        }

        $targetType = $requestedType ?? $customer->type->value;
        if ($customer->status === CustomerStatus::Approved && $targetType === $customer->type->value) {
            throw new BusinessException('هذا الحساب معتمد بالفعل.');
        }
    }

    /**
     * Ensures pending documents are resolved and commercial tiers have at least one verified document.
     */
    private function assertDocumentsAllowApproval(Customer $customer, string $targetType): void
    {
        // Exclude rejected documents from blocking approval; only pending documents require resolution.
        if ($customer->documents()->where('status', CustomerDocumentStatus::Pending->value)->exists()) {
            throw new BusinessException('راجع المستندات المعلقة (اعتمد السليم وارفض غير السليم) قبل اعتماد الحساب.');
        }

        if ($targetType !== CustomerType::Retail->value) {
            if (! $customer->documents()->where('status', CustomerDocumentStatus::Verified->value)->exists()) {
                throw new BusinessException('يلزم مستند موثّق واحد على الأقل لاعتماد حساب الجملة أو الموزع.');
            }
        }
    }

    /**
     * Validates and extracts required commercial legal fields for an approved account.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveCommercialLegalFieldsForApproval(Customer $customer, array $data, string $targetType): array
    {
        if ($targetType === CustomerType::Retail->value) {
            return [];
        }

        $legal = [];
        foreach (self::COMMERCIAL_LEGAL_FIELDS as $field) {
            $legal[$field] = $data[$field] ?? $customer->{$field};
            if (blank($legal[$field])) {
                throw new BusinessException('بيانات الجملة مطلوبة قبل الاعتماد.', [$field => ['هذا الحقل مطلوب لاعتماد حساب الجملة أو الموزع.']]);
            }
        }

        return $legal;
    }

    /**
     * Determines the appropriate status to revert a suspended customer to.
     */
    private function resolveReactivationTargetStatus(Customer $customer, ?string $restoreTo): CustomerStatus
    {
        // Revert to pre-suspension status to prevent rejected applications from silently re-entering pending queue.
        $target = CustomerStatus::tryFrom((string) ($restoreTo ?? $customer->pre_suspension_status));
        if ($target === null || $target === CustomerStatus::Suspended) {
            $target = $customer->approved_at ? CustomerStatus::Approved : CustomerStatus::Pending;
        }

        return $target;
    }

    /**
     * Checks that all required commercial legal attributes are populated before reactivating an approved account.
     */
    private function assertCommercialLegalAttributesComplete(Customer $customer): void
    {
        // Explicit attribute check prevents PostgreSQL check constraint violations when reactivating approved wholesale accounts.
        $missing = array_values(array_filter(
            self::COMMERCIAL_LEGAL_FIELDS,
            fn ($f) => blank($customer->{$f})
        ));

        if ($missing !== []) {
            throw new BusinessException('لا يمكن إعادة تفعيل حساب جملة وبياناته القانونية ناقصة؛ كمّلها الأول.', array_fill_keys($missing, ['هذا الحقل مطلوب.']));
        }
    }

    /**
     * Validates that a soft-deleted customer does not conflict with active emails or phone numbers upon restoration.
     */
    private function assertRestoreEligibility(Customer $customer): void
    {
        // Re-claim email under lock in case another account registered the same address while deleted.
        try {
            $this->claimEmail($customer->email, $customer->id);
        } catch (ValidationException) {
            throw new BusinessException('لا يمكن الاستعادة: البريد '.$customer->email.' مستخدم الآن بحساب نشط آخر. غيّر بريد الحساب الآخر أولاً.');
        }

        if ($customer->phone !== null && Customer::query()->whereNull('deleted_at')->where('phone', $customer->phone)->where('id', '!=', $customer->id)->exists()) {
            throw new BusinessException('لا يمكن الاستعادة: رقم الهاتف '.$customer->phone.' مستخدم الآن بحساب نشط آخر.');
        }
    }

    /**
     * Revokes all active Sanctum tokens, clears password reset tokens, and wipes channel associations.
     */
    private function revokeSessionsAndResetTokens(Customer $customer): void
    {
        $customer->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $customer->getEmailForPasswordReset())->delete();
        DB::table('customer_channels')->where('customer_id', $customer->id)->delete();
    }

    /**
     * Merge the execution channel marker (AI vs REST) into audit metadata.
     * REST calls pass an empty $via, so their recorded metadata stays
     * byte-identical to before; AI calls carry channel/conversation/action.
     *
     * @param  array<string, mixed>  $via
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
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
        AccountEmailLock::acquire($email);
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
     * Returns the canonical string representation of a customer type.
     */
    private function canonicalTypeValue(CustomerType|string $type): string
    {
        return $type instanceof CustomerType ? $type->value : (string) $type;
    }

    /**
     * Returns the canonical string representation of a customer status.
     */
    private function canonicalStatusValue(CustomerStatus|string $status): string
    {
        return $status instanceof CustomerStatus ? $status->value : (string) $status;
    }
}

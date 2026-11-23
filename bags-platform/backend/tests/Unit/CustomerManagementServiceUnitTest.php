<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Customer\CustomerDocumentStatus;
use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Services\Customer\CustomerManagementService;
use Tests\TestCase;

class CustomerManagementServiceUnitTest extends TestCase
{
    private CustomerManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CustomerManagementService;
    }

    public function test_retail_creation_requires_approved_status(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('حسابات التجزئة يجب أن تكون معتمدة عند إنشائها.');

        $this->service->create([
            'name' => 'Retail User',
            'email' => 'retail@example.test',
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Pending,
        ]);
    }

    public function test_retail_creation_with_string_type_and_status_is_guarded(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('حسابات التجزئة يجب أن تكون معتمدة عند إنشائها.');

        $this->service->create([
            'name' => 'Retail User String',
            'email' => 'retail2@example.test',
            'type' => 'retail',
            'status' => 'pending',
        ]);
    }

    public function test_wholesale_direct_creation_as_approved_is_forbidden(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('أنشئ حساب الجملة قيد المراجعة ثم استخدم مسار الاعتماد.');

        $this->service->create([
            'name' => 'Wholesale User',
            'email' => 'wholesale@example.test',
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
        ]);
    }

    public function test_wholesale_direct_creation_with_string_enum_values_is_forbidden(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('أنشئ حساب الجملة قيد المراجعة ثم استخدم مسار الاعتماد.');

        $this->service->create([
            'name' => 'Wholesale User 2',
            'email' => 'wholesale2@example.test',
            'type' => 'wholesale',
            'status' => 'approved',
        ]);
    }

    public function test_review_document_requires_verified_or_rejected_status(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('حالة مراجعة المستند يجب أن تكون معتمدًا أو مرفوضًا.');

        $customer = new Customer(['id' => 1]);
        $document = new CustomerDocument(['id' => 10, 'customer_id' => 1]);

        $this->service->reviewDocument($customer, $document, CustomerDocumentStatus::Pending, 1);
    }

    public function test_review_document_rejects_invalid_string_status(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('حالة مراجعة المستند يجب أن تكون معتمدًا أو مرفوضًا.');

        $customer = new Customer(['id' => 1]);
        $document = new CustomerDocument(['id' => 10, 'customer_id' => 1]);

        $this->service->reviewDocument($customer, $document, 'invalid_status', 1);
    }
}

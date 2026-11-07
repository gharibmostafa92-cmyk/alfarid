<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

class CustomerPhoneNormalizationTest extends TestCase
{
    public function test_equivalent_egyptian_mobile_formats_normalize_idempotently(): void
    {
        $digits = array_combine(range(0, 9), mb_str_split('٠١٢٣٤٥٦٧٨٩'));
        $random = new Randomizer(new Mt19937(20261107));
        $this->assertSame('legacy-55', Customer::normalizePhone('legacy-55'));
        for ($index = 0; $index < 2000; $index++) {
            $prefix = ['010', '011', '012', '015'][$index % 4];
            $subscriber = sprintf('%08d', $random->getInt(0, 99_999_999));
            $local = $prefix.$subscriber;
            $national = substr($local, 1);
            foreach ([
                '+20'.$national,
                '0020'.$national,
                '20'.$national,
                strtr($local, $digits),
                " ({$prefix}) ".substr($subscriber, 0, 3).'-'.substr($subscriber, 3).' ',
            ] as $formatted) {
                $normalized = Customer::normalizePhone($formatted);
                $this->assertSame($local, $normalized, $formatted);
                $this->assertSame($normalized, Customer::normalizePhone($normalized));
            }
        }
    }

    public function test_normalize_identifier_handles_emails_and_phones(): void
    {
        $this->assertNull(Customer::normalizeIdentifier(null));
        $this->assertSame('', Customer::normalizeIdentifier('   '));
        $this->assertSame('user@example.com', Customer::normalizeIdentifier('  User@Example.COM  '));
        $this->assertSame('01012345678', Customer::normalizeIdentifier('+201012345678'));
        $this->assertSame('01012345678', Customer::normalizeIdentifier('  01012345678  '));
        $this->assertSame('legacy-55', Customer::normalizeIdentifier('legacy-55'));
    }

    public function test_translate_unique_violation_handles_phone_and_email_collisions(): void
    {
        $phoneEx = new UniqueConstraintViolationException('default', 'duplicate key value violates unique constraint "customers_phone_unique"', [], new \Exception);
        $translatedPhone = Customer::translateUniqueViolation($phoneEx);
        $this->assertInstanceOf(ValidationException::class, $translatedPhone);
        $this->assertArrayHasKey('phone', $translatedPhone->errors());
        $this->assertSame('رقم الموبايل مسجل بالفعل.', $translatedPhone->errors()['phone'][0]);

        $emailEx = new UniqueConstraintViolationException('default', 'duplicate key value violates unique constraint "customers_email_unique"', [], new \Exception);
        $translatedEmail = Customer::translateUniqueViolation($emailEx);
        $this->assertInstanceOf(ValidationException::class, $translatedEmail);
        $this->assertArrayHasKey('email', $translatedEmail->errors());
        $this->assertSame('البريد الإلكتروني مستخدم بالفعل.', $translatedEmail->errors()['email'][0]);

        $otherEx = new \RuntimeException('Generic runtime error');
        $this->assertSame($otherEx, Customer::translateUniqueViolation($otherEx));
    }

    public function test_customer_commercial_tier_and_wholesale_approval_predicates(): void
    {
        $retail = new Customer(['type' => \App\Enums\CustomerType::Retail, 'status' => \App\Enums\CustomerStatus::Approved]);
        $this->assertFalse($retail->isCommercialTier());
        $this->assertFalse($retail->isWholesaleApproved());

        $pendingWholesale = new Customer(['type' => \App\Enums\CustomerType::Wholesale, 'status' => \App\Enums\CustomerStatus::Pending]);
        $this->assertTrue($pendingWholesale->isCommercialTier());
        $this->assertFalse($pendingWholesale->isWholesaleApproved());

        $approvedWholesale = new Customer(['type' => \App\Enums\CustomerType::Wholesale, 'status' => \App\Enums\CustomerStatus::Approved]);
        $this->assertTrue($approvedWholesale->isCommercialTier());
        $this->assertTrue($approvedWholesale->isWholesaleApproved());

        $approvedDistributor = new Customer(['type' => \App\Enums\CustomerType::Distributor, 'status' => \App\Enums\CustomerStatus::Approved]);
        $this->assertTrue($approvedDistributor->isCommercialTier());
        $this->assertTrue($approvedDistributor->isWholesaleApproved());

        $rejectedDistributor = new Customer(['type' => \App\Enums\CustomerType::Distributor, 'status' => \App\Enums\CustomerStatus::Rejected]);
        $this->assertTrue($rejectedDistributor->isCommercialTier());
        $this->assertFalse($rejectedDistributor->isWholesaleApproved());
    }
}

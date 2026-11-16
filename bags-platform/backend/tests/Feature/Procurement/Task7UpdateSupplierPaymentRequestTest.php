<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Http\Requests\Admin\Procurement\UpdateSupplierPaymentRequest;
use Tests\TestCase;

/**
 * Task7 F02: payment_method is no longer blanket-prohibited on payment update.
 * The frontend stops sending it; the API accepts an unchanged value and the
 * service layer rejects only an actual change.
 */
class Task7UpdateSupplierPaymentRequestTest extends TestCase
{
    public function test_payment_method_is_not_prohibited(): void
    {
        $rules = (new UpdateSupplierPaymentRequest)->rules();

        $this->assertArrayHasKey('payment_method', $rules);
        $this->assertNotContains('prohibited', $rules['payment_method']);
    }

    public function test_payment_method_accepts_known_enum_values(): void
    {
        $rules = (new UpdateSupplierPaymentRequest)->rules();

        $validator = validator(
            ['payment_method' => 'bank_transfer', 'reference_no' => 'X-1'],
            $rules
        );

        $this->assertFalse($validator->fails());
    }

    public function test_payment_method_rejects_unknown_values(): void
    {
        $rules = (new UpdateSupplierPaymentRequest)->rules();

        $validator = validator(['payment_method' => 'barter'], $rules);

        $this->assertTrue($validator->fails());
    }

    public function test_update_payload_without_payment_method_is_valid(): void
    {
        $rules = (new UpdateSupplierPaymentRequest)->rules();

        $validator = validator(['reference_no' => 'X-2', 'notes' => 'typo fix'], $rules);

        $this->assertFalse($validator->fails());
    }
}

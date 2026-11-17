<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Customer\CustomerDocumentStatus;
use App\Enums\Customer\CustomerDocumentType;
use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Models\Customer;
use App\Services\Customer\CustomerManagementService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

// task3 HIGH-04: channel-scoped tokens (store-token must not open wholesale,
// type changes must revoke stale tokens).
class Task3ChannelTokenScopeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function approvedWholesaler(): Customer
    {
        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Approved]);
        $customer->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'customer_documents/'.$customer->id.'/cr.pdf',
            'original_name' => 'cr.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);

        return $customer;
    }

    public function test_wholesale_portal_rejects_a_retail_scoped_token(): void
    {
        $customer = $this->approvedWholesaler();
        $token = $customer->createToken('store-token', ['store-token'])->plainTextToken;

        $this->getJson('/api/v1/wholesale/dashboard', ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }

    public function test_wholesale_portal_accepts_a_wholesale_scoped_token(): void
    {
        $customer = $this->approvedWholesaler();
        $token = $customer->createToken('wholesale-token', ['wholesale-token'])->plainTextToken;

        $this->getJson('/api/v1/wholesale/dashboard', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_approve_with_type_change_revokes_existing_tokens(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Retail, 'status' => CustomerStatus::Approved,
        ]);
        $customer->createToken('store-token', ['store-token']);
        $customer->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'customer_documents/'.$customer->id.'/cr.pdf',
            'original_name' => 'cr.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);
        $this->assertSame(1, $customer->tokens()->count());

        app(CustomerManagementService::class)->approve($customer, [
            'type' => CustomerType::Wholesale->value,
            'company_name' => 'Test Co',
            'tax_number' => '123456789',
            'commercial_register' => '987654',
        ], adminId: 1);

        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_approve_without_type_change_keeps_tokens(): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $customer->createToken('wholesale-token', ['wholesale-token']);
        $customer->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'customer_documents/'.$customer->id.'/cr.pdf',
            'original_name' => 'cr.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);

        app(CustomerManagementService::class)->approve($customer, [
            'company_name' => 'Test Co',
            'tax_number' => '123456789',
            'commercial_register' => '987654',
        ], adminId: 1);

        $this->assertSame(1, $customer->tokens()->count());
    }

    public function test_wholesale_login_does_not_consume_the_guest_cart(): void
    {
        $customer = $this->approvedWholesaler();
        $guest = \App\Models\Cart::factory()->create();
        \App\Models\CartItem::factory()->create(['cart_id' => $guest->id, 'qty' => 1]);

        $response = $this->postJson('/api/v1/auth/login',
            ['login' => $customer->phone, 'password' => 'password'],
            ['X-Cart-Session' => $guest->cart_token]);

        $response->assertOk()->assertJsonPath('cart_merged', false);
        // The guest cart survives untouched for the wholesale portal.
        $this->assertDatabaseHas('carts', ['id' => $guest->id, 'customer_id' => null]);
        $this->assertDatabaseMissing('carts', ['customer_id' => $customer->id]);
    }
}

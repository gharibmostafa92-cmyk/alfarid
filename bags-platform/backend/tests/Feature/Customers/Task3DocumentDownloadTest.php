<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\Customer\CustomerDocumentStatus;
use App\Enums\Customer\CustomerDocumentType;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\CustomerDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// task3 HIGH-05: single-trip document download (no exists() HEAD pre-check).
class Task3DocumentDownloadTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function centralStaffToken(): string
    {
        $staff = User::factory()->create(['branch_id' => null]);
        $staff->givePermissionTo([
            Permission::findOrCreate('customers.view', 'staff'),
            Permission::findOrCreate('customers.documents.view', 'staff'),
            'inventory.all_branches',
        ]);
        $role = Role::findOrCreate('task3-doc-role', 'staff');
        $role->givePermissionTo(Permission::findOrCreate('customers.documents.view', 'staff'));
        $staff->assignRole($role);

        return $staff->createToken('docs-token', ['*'])->plainTextToken;
    }

    private function document(Customer $customer, string $path): CustomerDocument
    {
        return CustomerDocument::create([
            'customer_id' => $customer->id,
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => $path,
            'original_name' => 'register.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);
    }

    public function test_missing_object_returns_404_without_an_audit_record(): void
    {
        Storage::fake('r2');
        $token = $this->centralStaffToken();
        $customer = Customer::factory()->create();
        $doc = $this->document($customer, "customer_documents/{$customer->id}/gone.pdf");

        Auth::forgetGuards();
        $this->withToken($token)
            ->get("/api/v1/admin/customers/{$customer->id}/documents/{$doc->id}/download")
            ->assertNotFound();

        $this->assertDatabaseMissing('customer_audit_logs', [
            'customer_id' => $customer->id, 'action' => 'document_downloaded',
        ]);
    }

    public function test_approver_without_document_permission_can_download_for_review(): void
    {
        // Least-privilege credit approvers (customers.approve only) must be
        // able to inspect the documents approve() itself requires.
        Storage::fake('r2');
        $staff = User::factory()->create(['branch_id' => null]);
        $staff->givePermissionTo([
            Permission::findOrCreate('customers.approve', 'staff'),
            'inventory.all_branches',
        ]);
        $token = $staff->createToken('approver-token', ['*'])->plainTextToken;
        $customer = Customer::factory()->create();
        Storage::disk('r2')->put("customer_documents/{$customer->id}/file.pdf", "%PDF-1.4\nclean");
        $doc = $this->document($customer, "customer_documents/{$customer->id}/file.pdf");

        Auth::forgetGuards();
        $this->withToken($token)
            ->get("/api/v1/admin/customers/{$customer->id}/documents/{$doc->id}/download")
            ->assertOk();
    }

    public function test_existing_object_streams_with_attachment_headers(): void
    {
        Storage::fake('r2');
        $token = $this->centralStaffToken();
        $customer = Customer::factory()->create();
        Storage::disk('r2')->put("customer_documents/{$customer->id}/file.pdf", "%PDF-1.4\nclean");
        $doc = $this->document($customer, "customer_documents/{$customer->id}/file.pdf");

        Auth::forgetGuards();
        $response = $this->withToken($token)
            ->get("/api/v1/admin/customers/{$customer->id}/documents/{$doc->id}/download")
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('customer_audit_logs', [
            'customer_id' => $customer->id, 'action' => 'document_downloaded',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Customer\CustomerDocumentStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\User;
use App\Services\Customer\CustomerManagementService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Task 8 (security audit) slice 2: customer approval segregation of duties,
 * KYC document download hardening, and document review status constraints.
 */
class Task8Slice2CustomerKycTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param list<string> $permissions */
    private function staffToken(array $permissions, ?int $branchId = null): string
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'staff');
        }
        $staff = User::factory()->create(['is_active' => true, 'branch_id' => $branchId]);
        $staff->givePermissionTo($permissions);

        return $staff->createToken('test', ['*'])->plainTextToken;
    }

    private function documentFor(Customer $customer): CustomerDocument
    {
        $path = Storage::disk('r2')->putFileAs(
            "customer_documents/{$customer->id}",
            \Illuminate\Http\UploadedFile::fake()->create('id.pdf', 20, 'application/pdf'),
            'id.pdf',
        );

        return $customer->documents()->create([
            'document_type' => 'national_id',
            'file_path' => $path,
            'original_name' => 'id.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Pending->value,
        ]);
    }

    public function test_manage_permission_cannot_approve_customers_without_approve_permission(): void
    {
        $token = $this->staffToken(['customers.manage', 'customers.view']);
        $customer = Customer::factory()->wholesale()->create();
        $customer->documents()->create([
            'document_type' => 'commercial_register',
            'file_path' => 'verified.pdf',
            'original_name' => 'verified.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified->value,
        ]);

        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/admin/customers/{$customer->id}/approve", [
            'credit_limit' => 9999999999.99,
            'type' => 'distributor',
        ])->assertForbidden();

        $this->assertSame('pending', $customer->refresh()->status->value);
    }

    public function test_sensitive_document_download_requires_central_scope_and_is_throttled(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $branchToken = $this->staffToken(['customers.documents.view'], $branch->id);
        $centralToken = $this->staffToken(['customers.documents.view', 'inventory.all_branches']);

        $customer = Customer::factory()->wholesale()->create();
        $document = $this->documentFor($customer);

        $uri = "/api/v1/admin/customers/{$customer->id}/documents/{$document->id}/download";

        Auth::forgetGuards();
        $this->withToken($branchToken)->get($uri)->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($centralToken)->get($uri)->assertOk();

        $route = collect(Route::getRoutes())->first(
            static fn ($route): bool => $route->getName() === 'admin.customers.documents.download',
        );
        $this->assertNotNull($route);
        $this->assertContains('throttle:admin-heavy', $route->gatherMiddleware());
    }

    public function test_document_download_rejects_document_of_another_customer(): void
    {
        $token = $this->staffToken(['customers.documents.view', 'inventory.all_branches']);
        $customer = Customer::factory()->wholesale()->create();
        $other = Customer::factory()->wholesale()->create();
        $document = $this->documentFor($customer);

        Auth::forgetGuards();
        $this->withToken($token)
            ->get("/api/v1/admin/customers/{$other->id}/documents/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_review_document_rejects_pending_status_and_accepts_enum_callers(): void
    {
        $token = $this->staffToken(['customers.approve', 'customers.documents.view', 'inventory.all_branches']);
        $actor = User::query()->latest('id')->firstOrFail();
        $customer = Customer::factory()->wholesale()->create();
        $document = $this->documentFor($customer);

        Auth::forgetGuards();
        $this->withToken($token)
            ->patchJson("/api/v1/admin/customers/{$customer->id}/documents/{$document->id}", [
                'status' => CustomerDocumentStatus::Pending->value,
            ])
            ->assertUnprocessable();

        $reviewed = app(CustomerManagementService::class)->reviewDocument(
            $customer,
            $document,
            CustomerDocumentStatus::Verified,
            (int) $actor->id,
        );

        $this->assertSame(CustomerDocumentStatus::Verified, $reviewed->refresh()->status);
    }
}

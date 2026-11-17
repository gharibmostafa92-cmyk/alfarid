<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Customer\CustomerDocumentStatus;
use App\Enums\Customer\CustomerDocumentType;
use App\Enums\Customer\CustomerStatus;
use App\Models\AiConversation;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task3Slice4AuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function apiAs(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function staffToken(array $permissions = [], ?int $branchId = null): string
    {
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'staff');
        }
        $user = User::factory()->create(['branch_id' => $branchId]);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user->createToken('staff-token', ['*'])->plainTextToken;
    }

    public function test_denied_ai_conversation_access_is_audited(): void
    {
        $owner = User::factory()->create();
        $conversation = AiConversation::query()->create([
            'user_id' => $owner->id,
            'model' => 'test-model',
        ]);

        $intruderToken = $this->staffToken();

        // A snooping write (message into another agent's thread) is a
        // mutating request, so it passes through the denial audit.
        $this->apiAs($intruderToken, 'POST', '/api/v1/admin/ai/conversations/'.$conversation->id.'/messages', [
            'content' => 'snoop',
        ])->assertForbidden();

        $this->assertDatabaseHas('staff_audit_logs', [
            'action' => 'administration.denied',
            'target_type' => 'ai_administration',
        ]);
    }

    public function test_denied_nested_resource_audit_includes_full_path(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        // Branch-restricted staff with document view rights but no central
        // approval rights hits the permission boundary on a nested route.
        $token = $this->staffToken(['customers.view', 'customers.documents.view'], $branch->id);
        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $document = $customer->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'customer_documents/'.$customer->id.'/cr.pdf',
            'original_name' => 'cr.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);

        $this->apiAs($token, 'PATCH', '/api/v1/admin/customers/'.$customer->id.'/documents/'.$document->id, [
            'status' => CustomerDocumentStatus::Rejected->value,
            'note' => 'invalid',
        ])->assertForbidden();

        $row = DB::table('staff_audit_logs')
            ->where('action', 'administration.denied')
            ->where('target_type', 'customer')
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $metadata = json_decode((string) $row->metadata, true);
        $this->assertSame(
            'api/v1/admin/customers/'.$customer->id.'/documents/'.$document->id,
            $metadata['via']['path'] ?? null
        );
    }
}

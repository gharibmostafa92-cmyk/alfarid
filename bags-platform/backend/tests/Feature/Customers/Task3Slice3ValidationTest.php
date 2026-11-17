<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customer\CustomerManagementService;
use Database\Seeders\CustomerPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task3Slice3ValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CustomerPermissionSeeder::class);
    }

    private function apiAs(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function centralToken(array $permissions): string
    {
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'staff');
        }
        $user = User::factory()->create(['branch_id' => null]);
        $user->givePermissionTo(array_merge($permissions, ['inventory.all_branches']));

        return $user->createToken('staff-token', ['*'])->plainTextToken;
    }

    public function test_all_phone_validation_rules_use_strict_anchors(): void
    {
        $files = [
            'app/Http/Requests/Admin/Customer/StoreCustomerRequest.php',
            'app/Http/Requests/Admin/Customer/UpdateCustomerRequest.php',
            'app/Http/Requests/Store/Customer/ForgotPasswordRequest.php',
            'app/Http/Requests/Store/Customer/PhoneVerificationRequest.php',
            'app/Http/Requests/Store/Customer/RegisterRequest.php',
            'app/Http/Requests/Store/Customer/ResetPasswordRequest.php',
            'app/Http/Requests/Wholesale/WholesaleRegisterRequest.php',
            'app/Http/Controllers/Api/V1/Pos/PosCustomerController.php',
            'app/Services/Ai/ToolRegistry.php',
        ];

        foreach ($files as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path, $relative);
            $contents = (string) file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression(
                '#\^01\[0125\]\[0-9\]\{8\}\$#',
                $contents,
                $relative.' still uses loose ^...$ phone anchors'
            );
            $this->assertMatchesRegularExpression(
                '#\\\\A01\[0125\]\[0-9\]\{8\}\\\\z#',
                $contents,
                $relative.' must use strict \\A...\\z phone anchors'
            );
        }
    }

    public function test_whatsapp_phone_accepts_international_format(): void
    {
        $token = $this->centralToken(['customers.view', 'customers.update', 'customers.approve']);
        $customer = Customer::factory()->create(['phone' => '01010020030']);

        $this->apiAs($token, 'PUT', '/api/v1/admin/customers/'.$customer->id, [
            'expected_version' => $customer->fresh()->lock_version,
            'whatsapp_phone' => '+201010020030',
        ])->assertOk();

        $this->assertSame('01010020030', $customer->fresh()->whatsapp_phone);
    }

    public function test_create_accepts_enum_type_and_status_objects(): void
    {
        $service = app(CustomerManagementService::class);

        $retail = $service->create([
            'name' => 'Retail Enum',
            'phone' => '01010020031',
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ]);

        $this->assertSame(CustomerType::Retail, $retail->type);
        $this->assertSame(CustomerStatus::Approved, $retail->status);

        $wholesale = $service->create([
            'name' => 'Wholesale Enum',
            'phone' => '01010020032',
            'type' => CustomerType::Wholesale,
        ], null, ['channel' => 'test']);

        $this->assertSame(CustomerType::Wholesale, $wholesale->type);
        $this->assertSame(CustomerStatus::Pending, $wholesale->status);
    }
}

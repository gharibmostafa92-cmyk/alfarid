<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Models\Customer;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TaskTxtQuotationNumericRoutesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $traderToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 100000,
        ]);
        $this->traderToken = $trader->createToken('portal')->plainTextToken;
    }

    private function asTrader(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->traderToken);
    }

    public function test_non_numeric_quotation_show_returns_404(): void
    {
        $this->asTrader()->getJson('/api/v1/wholesale/quotations/abc')->assertNotFound();
    }

    public function test_non_numeric_quotation_update_returns_404(): void
    {
        $this->asTrader()->putJson('/api/v1/wholesale/quotations/abc', ['customer_notes' => 'x'])->assertNotFound();
    }

    public function test_non_numeric_quotation_delete_returns_404(): void
    {
        $this->asTrader()->deleteJson('/api/v1/wholesale/quotations/abc')->assertNotFound();
    }
}

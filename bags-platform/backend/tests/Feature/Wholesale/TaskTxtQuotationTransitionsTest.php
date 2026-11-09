<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\QuotationStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Wholesale\QuotationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TaskTxtQuotationTransitionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $traderToken;

    private string $adminToken;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $this->traderToken = $this->trader->createToken('portal')->plainTextToken;

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->adminToken = $admin->createToken('admin')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'wholesale_price' => null, 'is_active' => true]);
    }

    private function asTrader(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->traderToken);
    }

    private function asAdmin(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->adminToken);
    }

    private function draft(int $itemCount = 1): Quotation
    {
        $items = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $items[] = ['product_variant_id' => $this->variant->id, 'qty' => 1];
        }
        $id = $this->asTrader()->postJson('/api/v1/wholesale/quotations', ['items' => $items])
            ->assertCreated()->json('data.id');

        return Quotation::query()->findOrFail($id);
    }

    private function price(int $id, array $overrides = [])
    {
        return $this->asAdmin()->postJson("/api/v1/admin/quotations/{$id}/price", array_merge([
            'valid_until' => now()->addDays(30)->toDateString(),
        ], $overrides));
    }

    public function test_allowed_transitions_succeed(): void
    {
        $draft = $this->draft();
        $this->asTrader()->putJson("/api/v1/wholesale/quotations/{$draft->id}", ['customer_notes' => 'ok'])
            ->assertOk();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$draft->id}/submit")
            ->assertOk()->assertJsonPath('data.status', 'requested');

        $requested = $this->draft();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$requested->id}/submit")->assertOk();
        $this->price($requested->id)->assertOk()->assertJsonPath('data.status', 'priced_by_admin');

        $rejectable = $this->draft();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$rejectable->id}/submit")->assertOk();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$rejectable->id}/reject")
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $expirable = $this->draft();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$expirable->id}/submit")->assertOk();
        $this->price($expirable->id)->assertOk();
        Quotation::query()->whereKey($expirable->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->asAdmin()->postJson("/api/v1/admin/quotations/{$expirable->id}/expire")
            ->assertOk()->assertJsonPath('data.status', 'expired');
    }

    public function test_forbidden_transitions_fail_without_state_change(): void
    {
        $requested = $this->draft();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$requested->id}/submit")->assertOk();

        // Update / submit only on drafts.
        $this->asTrader()->putJson("/api/v1/wholesale/quotations/{$requested->id}", ['customer_notes' => 'x'])
            ->assertStatus(422);
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$requested->id}/submit")->assertStatus(422);
        $this->assertSame(QuotationStatus::Requested, $requested->fresh()->status);

        // Price only on requested.
        $draft = $this->draft();
        $this->price($draft->id)->assertStatus(422);
        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);

        $priced = $this->draft();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$priced->id}/submit")->assertOk();
        $this->price($priced->id)->assertOk();
        $this->price($priced->id)->assertStatus(422);
        $this->assertSame(QuotationStatus::PricedByAdmin, $priced->fresh()->status);

        // Reject only requested/priced.
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$draft->id}/reject")->assertStatus(422);

        // Expire only priced.
        $this->asAdmin()->postJson("/api/v1/admin/quotations/{$requested->id}/expire")->assertStatus(422);
        $this->asAdmin()->postJson("/api/v1/admin/quotations/{$draft->id}/expire")->assertStatus(422);

        // Accept before pricing is rejected.
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$requested->id}/accept")->assertStatus(422);
    }

    public function test_submit_without_items_is_rejected(): void
    {
        $quotation = $this->draft();
        $quotation->items()->delete();

        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/submit")->assertStatus(422);
        $this->assertSame(QuotationStatus::Draft, $quotation->fresh()->status);
    }

    public function test_extend_validity_only_on_priced(): void
    {
        $service = app(QuotationService::class);

        $draft = $this->draft();
        try {
            $service->extendValidity($draft, now()->addDays(10)->toDateString());
            $this->fail('Extending a draft must be rejected.');
        } catch (BusinessException) {
        }

        $requested = $this->draft();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$requested->id}/submit")->assertOk();
        $this->price($requested->id)->assertOk();

        $extended = $service->extendValidity($requested->fresh(), now()->addDays(10)->toDateString());
        $this->assertSame(QuotationStatus::PricedByAdmin, $extended->status);
        $this->assertSame(now()->addDays(10)->toDateString(), $extended->valid_until->toDateString());
    }
}

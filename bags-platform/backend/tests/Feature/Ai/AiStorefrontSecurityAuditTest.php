<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\BannerLinkType;
use App\Enums\BannerPosition;
use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StaffAuditLog;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Storefront\Tools\DiagnoseCartTool;
use App\Services\Ai\Storefront\Tools\PlaceAssistedOrderTool;
use App\Services\Ai\Storefront\Tools\PreviewCheckoutTool;
use App\Services\Ai\Storefront\Tools\ReorderBannersTool;
use App\Services\Ai\Storefront\Tools\SetBannerStatusTool;
use App\Services\Ai\Storefront\Tools\UpdateBannerTool;
use App\Services\Shared\SettingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiStorefrontSecurityAuditTest extends TestCase
{
    use DatabaseTransactions;

    private function enableStorefrontAi(bool $placeOrder = true): void
    {
        Setting::updateOrCreate(['key' => 'ai_storefront_tools_enabled'], ['value' => '1', 'group' => 'ai', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => 'ai_storefront_place_order_enabled'], ['value' => $placeOrder ? '1' : '0', 'group' => 'ai', 'type' => 'boolean']);
        app(SettingService::class)->clearCache();
    }

    private function makeStaff(array $permissions = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'staff');
            $user->givePermissionTo($perm);
        }

        return $user;
    }

    private function makeShoppableCart(Customer $customer, float $price = 500.0): array
    {
        $branch = Branch::create(['name' => 'Online', 'code' => 'ONL-'.uniqid(), 'type' => 'warehouse', 'is_active' => true, 'is_online_stock' => true]);
        $city = City::create(['name_ar' => 'القاهرة', 'name_en' => 'Cairo', 'is_active' => true]);
        $cat = Category::create(['name_ar' => 'شنط', 'name_en' => 'Bags', 'slug' => 'bags-'.uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $cat->id,
            'code' => 'PRD-'.uniqid(),
            'name_ar' => 'شنطة',
            'name_en' => 'Bag',
            'slug' => 'bag-'.uniqid(),
            'status' => ProductStatus::Published,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'retail_price' => $price,
            'wholesale_price' => $price * 0.8,
            'is_active' => true,
        ]);
        StockLevel::forceCreate([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'on_hand' => 20,
            'reserved' => 0,
            'damaged' => 0,
        ]);

        $cart = Cart::create(['customer_id' => $customer->id]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ]);

        return [$cart->fresh('items'), $city, $variant];
    }

    public function test_place_assisted_order_normalizes_phone_and_enforces_kill_switch_and_toctou(): void
    {
        $this->enableStorefrontAi(true);
        $staff = $this->makeStaff(['orders.create', 'orders.view', 'customers.view']);
        $conv = AiConversation::create(['user_id' => $staff->id, 'title' => 'SF', 'model' => 'gemini-2.5-flash']);
        app(ProposalBuilder::class)->setConversation($conv);

        $customer = Customer::create([
            'name' => 'Test Customer',
            'phone' => '+20 100 123-4567 ',
            'email' => 'cust-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'),
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ]);
        [$cart, $city, $variant] = $this->makeShoppableCart($customer, 500.0);

        $tool = app(PlaceAssistedOrderTool::class);
        $outcome = $tool->prepare($staff, [
            'customer_id' => $customer->id,
            'cart_id' => $cart->id,
            'city_id' => $city->id,
            'payment_method' => 'cod',
            'shipping_address_line' => '10 Nile St, Cairo',
        ]);

        $this->assertSame('proposal', $outcome->type, var_export($outcome, true));
        /** @var AiPendingAction $action */
        $action = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();
        $this->assertSame('4567', $action->preview['typed_confirmation'] ?? null);

        // 1. Kill switch disabled after prepare()
        $this->enableStorefrontAi(false);
        $killResult = $tool->execute($staff, $action);
        $this->assertSame('failed', $killResult->type);

        // Re-enable kill switch, then mutate cart (TOCTOU)
        $this->enableStorefrontAi(true);
        $cart->items()->first()->update(['qty' => 3]);

        $this->expectException(BusinessException::class);
        $tool->execute($staff, $action);
    }

    public function test_preview_checkout_and_diagnose_cart_block_mismatched_customer_and_guest_cart_idor(): void
    {
        $staff = $this->makeStaff(['orders.view']);
        $customerA = Customer::create([
            'name' => 'Cust A',
            'phone' => '01000000011',
            'email' => 'a-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'),
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ]);
        $customerB = Customer::create([
            'name' => 'Cust B',
            'phone' => '01000000012',
            'email' => 'b-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'),
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ]);
        [$cartA, $city] = $this->makeShoppableCart($customerA);
        $guestCart = Cart::create(['customer_id' => null, 'guest_token' => 'guest-'.uniqid()]);

        $preview = app(PreviewCheckoutTool::class);
        $diagnose = app(DiagnoseCartTool::class);

        // Mismatched customer_id + cart_id must be denied in PreviewCheckoutTool
        $mismatch = $preview->handle($staff, [
            'cart_id' => $cartA->id,
            'customer_id' => $customerB->id,
            'city_id' => $city->id,
        ]);
        $this->assertSame('denied', $mismatch->type);

        // Guest cart by numeric cart_id must be denied in both tools
        $guestPreview = $preview->handle($staff, ['cart_id' => $guestCart->id]);
        $this->assertSame('denied', $guestPreview->type);

        $guestDiagnose = $diagnose->handle($staff, ['cart_id' => $guestCart->id]);
        $this->assertSame('denied', $guestDiagnose->type);
    }

    public function test_banner_tools_enforce_optimistic_lock_and_record_audit_logs(): void
    {
        $staff = $this->makeStaff(['store.manage']);
        $conv = AiConversation::create(['user_id' => $staff->id, 'title' => 'Banners', 'model' => 'gemini-2.5-flash']);
        app(ProposalBuilder::class)->setConversation($conv);

        $banner1 = Banner::create([
            'title' => 'Banner 1',
            'position' => BannerPosition::MainSlider,
            'link_type' => BannerLinkType::None,
            'image_path' => 'banners/b1.jpg',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $banner2 = Banner::create([
            'title' => 'Banner 2',
            'position' => BannerPosition::MainSlider,
            'link_type' => BannerLinkType::None,
            'image_path' => 'banners/b2.jpg',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $updateTool = app(UpdateBannerTool::class);
        $statusTool = app(SetBannerStatusTool::class);
        $reorderTool = app(ReorderBannersTool::class);

        $this->assertInstanceOf(LocksTargets::class, $updateTool);
        $this->assertInstanceOf(LocksTargets::class, $statusTool);

        // Prepare update, then concurrently touch banner1
        $proposal = $updateTool->prepare($staff, ['banner_id' => $banner1->id, 'title' => 'Updated Banner 1']);
        $this->assertSame('proposal', $proposal->type);

        $updateAction = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();

        $this->travel(2)->seconds();
        $banner1->update(['title' => 'Concurrent Edit']);
        $banner1->touch();

        $caught = false;
        try {
            $updateTool->execute($staff, $updateAction);
        } catch (BusinessException $e) {
            $caught = $e->status === 409;
        }
        $this->assertTrue($caught, 'Expected 409 BusinessException on stale banner update');

        // Reorder should record StaffAdministration audit log
        $reorderProposal = $reorderTool->prepare($staff, [
            'position' => BannerPosition::MainSlider->value,
            'ids' => [$banner2->id, $banner1->id],
        ]);
        $reorderAction = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();
        $reorderRes = $reorderTool->execute($staff, $reorderAction);
        $this->assertSame('result', $reorderRes->type);
        $this->assertTrue(
            \Illuminate\Support\Facades\DB::table('staff_audit_logs')->where('actor_id', $staff->id)->where('action', 'banner.reordered')->exists()
        );
    }
}

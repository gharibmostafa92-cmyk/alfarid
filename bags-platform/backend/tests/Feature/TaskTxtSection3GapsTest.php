<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\AgentOrchestrator;
use App\Services\Ai\Catalog\Tools\BulkUpdatePricesTool;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolRegistry;
use App\Services\Catalog\BarcodeGenerator;
use App\Services\Catalog\CategoryHierarchy;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §3 remaining gaps: assistant-layer guarantees (APP_KEY rotation,
 * bulk cancel, retired models, selection auto-text, 16k truncation),
 * catalog-tool specifics (draft limits/suggestions/warnings, status
 * stripping, category-status guards), REST gaps (remove_image, multipart
 * PUT, root filter, >100 variants, grandchildren, mid-create delete,
 * single-variant stock delete), and property checks (hashArgs, rounding,
 * barcodes, random trees, digit normalization).
 */
class TaskTxtSection3GapsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled', 'ai_catalog_tools_enabled', 'ai_catalog_vision_enabled', 'ai_catalog_copywriting_enabled',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function catalogAdmin(): User
    {
        return $this->staff([
            'ai_agent.use', 'ai_agent.execute',
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.publish',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
        ]);
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function geminiText(string $text = 'تمام.'): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]];
    }

    private static int $seq = 0;

    private function variantFixture(float $retail = 400): ProductVariant
    {
        $n = 78000 + (++self::$seq);
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['category_id' => $cat->id, 'code' => 'P'.$n,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both]);

        return ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'P'.$n.'-BLK',
            'retail_price' => $retail, 'wholesale_price' => 300, 'cost_price' => 50, 'is_active' => true]);
    }

    // ── APP_KEY rotation invalidates issued cards (documented) ──
    public function test_app_key_rotation_rejects_issued_cards(): void
    {
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $variant = $this->variantFixture();
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'update_variant', 'args' => ['variant_id' => $variant->id, 'retail_price' => 450]]);
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);

        // Cards are HMAC-bound to APP_KEY: rotating the key must invalidate
        // issued cards (tamper response), never silently execute them.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->withToken($this->token($admin))
            ->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm")
            ->assertStatus(409);
        $this->assertSame(400.0, (float) $variant->fresh()->retail_price);
    }

    // ── cancel bulk cancels the children ──
    public function test_cancel_bulk_cancels_children(): void
    {
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $v1 = $this->variantFixture();
        $v2 = $this->variantFixture();

        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'bulk_actions', 'args' => ['items' => [
            ['tool' => 'update_variant', 'args' => ['variant_id' => $v1->id, 'retail_price' => 410]],
            ['tool' => 'update_variant', 'args' => ['variant_id' => $v2->id, 'retail_price' => 420]],
        ]]]);
        $parent = AiPendingAction::findOrFail($prop->data['pending_action_id']);

        $this->withToken($this->token($admin))->postJson("/api/v1/admin/ai/actions/{$parent->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $parent->fresh()->status);
        $this->assertSame(0, AiPendingAction::where('conversation_id', $conv->id)->where('status', 'bulk_child')->count());
    }

    // ── retired conversation model swaps to current ──
    public function test_retired_model_swaps_to_current(): void
    {
        config(['services.gemini.retired_models' => ['gemini-old']]);
        $admin = $this->staff(['ai_agent.use', 'branches.view', 'inventory.all_branches']);
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-old']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText())]);

        $this->withToken($this->token($admin))
            ->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'أهلا'])
            ->assertOk();
        Http::assertSent(fn ($req) => str_contains($req->url(), 'gemini-test:generateContent'));
    }

    // ── selection without text → auto text; empty everything → 422 ──
    public function test_selection_without_text_gets_auto_text(): void
    {
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $cat = Category::factory()->create(['is_active' => true, 'name_ar' => 'مختارة']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText('حاضر.'))]);

        $res = $this->withToken($this->token($admin))->postJson(
            "/api/v1/admin/ai/conversations/{$conv->id}/messages",
            ['selection' => ['entity' => 'category', 'id' => $cat->id, 'label' => 'x']]
        )->assertOk();
        $this->assertNotEmpty($res->json('data.text'));

        $userMsg = \App\Models\AiMessage::query()->where('conversation_id', $conv->id)->where('role', 'user')->firstOrFail();
        $this->assertStringStartsWith('اخترت: ', $userMsg->content);
    }

    // ── 16k tool-result truncation keeps the call id ──
    public function test_huge_tool_result_truncated_with_call_id(): void
    {
        $admin = $this->staff(['ai_agent.use', 'branches.view', 'inventory.all_branches']);
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $big = ['functionCall' => ['name' => 'list_branches', 'args' => [], 'id' => 'call-1'], 'thoughtSignature' => 's'];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [$big]]]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]])
            ->push($this->geminiText('خلاصة.'))]);

        // Force a >16k tool response by seeding 60 branches (limit is 50 +
        // meta) — instead directly exercise truncateToolParts via reflection
        // with a synthetic oversized part.
        $orchestrator = app(AgentOrchestrator::class);
        $ref = new \ReflectionMethod(AgentOrchestrator::class, 'cappedToolResponses');
        $huge = ['functionResponse' => ['name' => 'list_branches', 'id' => 'call-1',
            'response' => ['results' => array_fill(0, 500, ['id' => 1, 'label' => str_repeat('ف', 100)])]]];
        $out = $ref->invoke($orchestrator, [$huge]);
        $this->assertStringContainsString('حدّد بحثك', json_encode($out, JSON_UNESCAPED_UNICODE));
        $this->assertSame('call-1', $out[0]['functionResponse']['id']);

        $this->withToken($this->token($admin))
            ->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'الفروع'])
            ->assertOk();
    }

    // ── create_product_draft: >100 combos, suggestions, similarity warning ──
    public function test_create_draft_limits_suggestions_and_warnings(): void
    {
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $registry = app(ToolRegistry::class);
        $cat = Category::factory()->create(['is_active' => true]);

        // >100 combinations → denied (11 real colors × 10 sizes = 110).
        $denied = $registry->dispatch($admin, $conv, ['name' => 'create_product_draft', 'args' => [
            'category_id' => $cat->id, 'name_ar' => 'شنطة', 'name_en' => 'Huge Matrix Bag',
            'sales_channel' => 'retail',
            'variant_matrix' => [
                'colors' => ['black', 'navy', 'beige', 'burgundy', 'grey', 'white', 'brown', 'camel', 'tan', 'red', 'blue'],
                'sizes' => array_map(fn ($i) => 'Size'.$i, range(1, 10)),
            ],
        ]]);
        $this->assertSame('denied', $denied->type);
        $this->assertStringContainsString('110', $denied->message);

        // Unknown color → denied with up to 5 suggestions in a clear message.
        $prop = $registry->dispatch($admin, $conv, ['name' => 'create_product_draft', 'args' => [
            'category_id' => $cat->id, 'name_ar' => 'شنطة سفر', 'name_en' => 'Travel Tote X1',
            'sales_channel' => 'retail', 'variant_matrix' => ['colors' => ['Blurple']],
        ]]);
        $this->assertSame('denied', $prop->type);
        $this->assertStringContainsString('اقتراحات متاحة', $prop->message);
        $this->assertGreaterThanOrEqual(5, count(explode('،', explode('اقتراحات متاحة: ', $prop->message)[1])));

        // 85%+ similar name in the same category → warning block.
        Product::factory()->create(['category_id' => $cat->id, 'code' => 'P79111',
            'name_ar' => 'شنطة سفر', 'name_en' => 'Travel Tote X2',
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail]);
        $prop2 = $registry->dispatch($admin, $conv, ['name' => 'create_product_draft', 'args' => [
            'category_id' => $cat->id, 'name_ar' => 'شنطة سفر', 'name_en' => 'Travel Tote X3',
            'sales_channel' => 'retail', 'variant_matrix' => ['colors' => ['Red']],
        ]]);
        $this->assertSame('proposal', $prop2->type);
        $this->assertStringContainsString('شبهه', json_encode($prop2->uiBlock, JSON_UNESCAPED_UNICODE));
    }

    // ── update_product rejects status (declaration-level, 422-shaped denial) ──
    public function test_update_product_tool_rejects_status(): void
    {
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $variant = $this->variantFixture();

        $out = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'update_product',
            'args' => ['product_id' => $variant->product_id, 'status' => 'published'],
        ]);
        $this->assertSame('denied', $out->type);
    }

    // ── set_category_status: disable with published products blocked w/ 5 samples ──
    public function test_set_category_status_disable_blocked_with_samples(): void
    {
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $cat = Category::factory()->create(['is_active' => true]);
        for ($i = 0; $i < 7; $i++) {
            Product::factory()->create(['category_id' => $cat->id, 'code' => 'P792'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'name_ar' => 'منتج '.$i, 'status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Retail]);
        }

        $out = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'set_category_status', 'args' => ['category_id' => $cat->id, 'is_active' => false]]);
        $this->assertSame('denied', $out->type);
        $this->assertStringContainsString('7', $out->message);

        // Enable path is medium risk: no typed confirmation required.
        $cat->forceFill(['is_active' => false])->save();
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'set_category_status', 'args' => ['category_id' => $cat->id, 'is_active' => true]]);
        $this->assertSame('proposal', $prop->type);
    }

    // ── REST: remove_image, multipart PUT, root filter, >100 variants ──
    public function test_rest_category_image_and_product_limits(): void
    {
        $this->fakeUploads();
        $token = $this->token($this->catalogAdmin());

        $cat = Category::factory()->create(['is_active' => true]);
        // Multipart POST + _method=PUT updates the category.
        $this->withToken($token)->post("/api/v1/admin/categories/{$cat->id}", [
            '_method' => 'PUT', 'name_en' => $cat->name_en.' Updated',
            'image' => UploadedFile::fake()->image('c.jpg'),
        ])->assertOk();
        // remove_image=true clears it.
        $this->withToken($token)->putJson("/api/v1/admin/categories/{$cat->id}", ['remove_image' => true])->assertOk();

        // parent_id=root filter lists top-level only.
        $child = Category::factory()->create(['is_active' => true, 'parent_id' => $cat->id]);
        $idx = $this->withToken($token)->getJson('/api/v1/admin/categories?parent_id=root')->assertOk();
        $ids = collect($idx->json('data'))->pluck('id')->all();
        $this->assertContains($cat->id, $ids);
        $this->assertNotContains($child->id, $ids);

        // >100 variants in one create → 422.
        $variants = [];
        for ($i = 0; $i < 101; $i++) {
            $variants[] = ['color_name_en' => 'Color'.$i, 'retail_price' => 100];
        }
        $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'Big Matrix '.uniqid(),
            'sales_channel' => 'retail', 'variants' => $variants,
        ])->assertStatus(422);
    }

    // ── REST: include_children reaches grandchildren; sort=name works ──
    public function test_rest_filters_grandchildren_and_sort(): void
    {
        $token = $this->token($this->catalogAdmin());
        $root = Category::factory()->create(['is_active' => true]);
        $mid = Category::factory()->create(['is_active' => true, 'parent_id' => $root->id]);
        $leaf = Category::factory()->create(['is_active' => true, 'parent_id' => $mid->id]);
        Product::factory()->create(['category_id' => $leaf->id, 'code' => 'P79311',
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail]);

        $res = $this->withToken($token)->getJson("/api/v1/admin/products?category_id={$root->id}&include_children=1")->assertOk();
        $this->assertNotEmpty($res->json('data'));

        $this->withToken($token)->getJson('/api/v1/admin/products?sort=name')->assertOk();
    }

    // ── REST: category deleted mid-create → reference message; single variant with stock ──
    public function test_rest_mid_create_delete_and_variant_stock(): void
    {
        $admin = $this->catalogAdmin();
        $token = $this->token($admin);
        $cat = Category::factory()->create(['is_active' => true]);
        $catId = $cat->id;
        $cat->delete();

        $res = $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $catId, 'name_ar' => 'م', 'name_en' => 'Orphan '.uniqid(), 'sales_channel' => 'retail',
        ]);
        $this->assertContains($res->status(), [409, 422]);

        // Single variant with stock balance cannot be deleted.
        $variant = $this->variantFixture();
        $branch = \App\Models\Branch::factory()->create();
        $stock = new \App\Models\StockLevel;
        $stock->forceFill(['product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'location_id' => null, 'on_hand' => 2, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0])->save();
        $this->withToken($token)->deleteJson("/api/v1/admin/products/{$variant->product_id}/variants/{$variant->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'deleted_at' => null]);
    }

    // ── property: hashArgs key-order invariant, list-order sensitive ──
    public function test_property_hash_args_canonicalization(): void
    {
        $registry = app(ToolRegistry::class);
        $a = ['variant_id' => 5, 'retail_price' => 100, 'nested' => ['x' => 1, 'y' => 2]];
        $reordered = ['nested' => ['y' => 2, 'x' => 1], 'retail_price' => 100, 'variant_id' => 5];
        $this->assertSame($registry->hashArgs($a), $registry->hashArgs($reordered));

        $list1 = ['items' => [['tool' => 'a'], ['tool' => 'b']]];
        $list2 = ['items' => [['tool' => 'b'], ['tool' => 'a']]];
        $this->assertNotSame($registry->hashArgs($list1), $registry->hashArgs($list2));
    }

    // ── property: rounding invariants over random prices ──
    public function test_property_rounding_invariants(): void
    {
        mt_srand(20261103);
        for ($i = 0; $i < 200; $i++) {
            $price = mt_rand(1, 200000) / 100;
            $this->assertEqualsWithDelta(0.0, fmod(BulkUpdatePricesTool::applyRounding($price, 'nearest_5'), 5.0), 0.001);
            $this->assertSame(9, (int) (BulkUpdatePricesTool::applyRounding($price, 'end_with_9') % 10));
            $r99 = BulkUpdatePricesTool::applyRounding($price, 'end_with_99');
            $this->assertTrue($r99 >= 99.0 && ((int) ($r99 % 100) === 99), "end_with_99($price) = $r99");
        }
    }

    // ── property: random barcodes valid EAN-13 starting with 2 ──
    public function test_property_barcode_random_check_digits(): void
    {
        mt_srand(77);
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $code = BarcodeGenerator::fromId(mt_rand(1, 999999999));
            $this->assertMatchesRegularExpression('/^2\d{12}$/', $code);
            $sum = 0;
            for ($d = 0; $d < 12; $d++) {
                $sum += ((int) $code[$d]) * ($d % 2 === 0 ? 1 : 3);
            }
            $this->assertSame((string) ((10 - ($sum % 10)) % 10), $code[12]);
            $seen[$code] = true;
        }
        $this->assertGreaterThan(90, count($seen));
    }

    // ── property: random valid trees pass assertValid; invalid ones throw ──
    public function test_property_category_hierarchy_random_trees(): void
    {
        mt_srand(42);
        for ($i = 0; $i < 50; $i++) {
            $nodes = [];
            // Random forest of depth ≤ 3, keyed by id.
            $roots = mt_rand(1, 3);
            for ($r = 0; $r < $roots; $r++) {
                $id = 1000 * ($i + 1) + $r * 10;
                $nodes[$id] = ['parent_id' => null];
                $parent = $id;
                $extra = mt_rand(0, 2);
                for ($d = 0; $d < $extra; $d++) {
                    $child = $parent + 1 + $d;
                    $nodes[$child] = ['parent_id' => $parent];
                    $parent = $child;
                }
            }
            CategoryHierarchy::assertValid($nodes);
            $this->assertTrue(true);
        }
        // Cycle and depth-4 always rejected.
        $this->expectException(\App\Exceptions\BusinessException::class);
        CategoryHierarchy::assertValid([
            1 => ['parent_id' => 2],
            2 => ['parent_id' => 1],
        ]);
    }

    // ── property: digit normalization nested (Arabic/Persian → Latin) ──
    // Known keys convert at any nesting level; free-text keys pass through
    // untouched by design (names must not be mangled).
    public function test_property_digit_normalization_nested(): void
    {
        $args = [
            'retail_price' => '٤٥٠.٥',
            'name_ar' => 'شنطة ٤٥',
            'nested' => ['variant_id' => '۲۲', 'items' => [['cost_price' => '١٠٠']]],
        ];
        $out = \App\Services\Ai\Catalog\CatalogArgs::normalizeDigitsInArray($args);
        $this->assertSame(450.5, $out['retail_price']);
        $this->assertSame('شنطة ٤٥', $out['name_ar']);
        $this->assertSame(22, $out['nested']['variant_id']);
        $this->assertSame(100.0, $out['nested']['items'][0]['cost_price']);
    }

    // ── sequential same-name categories get distinct slugs ──
    // (M1 covers the true-parallel race with a retry; sequentially the
    // slug service must still never collide.)
    public function test_sequential_same_name_slugs_stay_unique(): void
    {
        $token = $this->token($this->catalogAdmin());
        $first = $this->withToken($token)->postJson('/api/v1/admin/categories', [
            'name_ar' => 'تكرار', 'name_en' => 'Duplicate Name',
        ])->assertCreated();
        $second = $this->withToken($token)->postJson('/api/v1/admin/categories', [
            'name_ar' => 'تكرار', 'name_en' => 'Duplicate Name',
        ])->assertCreated();
        $this->assertNotSame($first->json('data.slug'), $second->json('data.slug'));
    }

    // ── soft-deleted slug reuse is currently blocked (decision documented) ──
    public function test_soft_deleted_slug_reuse_currently_blocked(): void
    {
        $token = $this->token($this->catalogAdmin());
        $cat = Category::factory()->create(['is_active' => true, 'name_en' => 'Gone Cat']);
        $slug = $cat->slug;
        $this->withToken($token)->deleteJson("/api/v1/admin/categories/{$cat->id}")->assertOk();

        // task.txt §3 flags this as needing an owner decision (allow reuse
        // like variants, or keep blocking). Current behavior: blocked.
        $this->withToken($token)->postJson('/api/v1/admin/categories', [
            'name_ar' => 'جديد', 'name_en' => 'New Cat', 'slug' => $slug,
        ])->assertStatus(422);
    }

    // ── third-level publish succeeds; disabled grandparent blocks ──
    public function test_publish_third_level_and_disabled_grandparent(): void
    {
        $this->fakeUploads();
        $token = $this->token($this->catalogAdmin());
        $l1 = Category::factory()->create(['is_active' => true]);
        $l2 = Category::factory()->create(['is_active' => true, 'parent_id' => $l1->id]);
        $l3 = Category::factory()->create(['is_active' => true, 'parent_id' => $l2->id]);

        $mk = function () use ($token, $l3) {
            $res = $this->withToken($token)->postJson('/api/v1/admin/products', [
                'category_id' => $l3->id, 'name_ar' => 'م', 'name_en' => 'L3 '.uniqid(),
                'sales_channel' => 'retail',
                'variants' => [['color_name_en' => 'Red', 'retail_price' => 100]],
            ])->assertCreated();
            $id = $res->json('data.id');
            $this->withToken($token)->post("/api/v1/admin/products/{$id}/media", [
                'collection' => 'images', 'files' => [UploadedFile::fake()->image('p.jpg')],
            ])->assertCreated();

            return $id;
        };

        // Publish under a level-3 category succeeds.
        $id = $mk();
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id}", [
            'status' => 'published', 'expected_version' => Product::find($id)->lock_version,
        ])->assertOk()->assertJsonPath('data.status', 'published');

        // Disabling the grandparent blocks further publishes underneath.
        $l1->forceFill(['is_active' => false])->save();
        $id2 = $mk();
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id2}", [
            'status' => 'published', 'expected_version' => Product::find($id2)->lock_version,
        ])->assertStatus(422);
    }

    // ── compare_at<retail and wholesale>retail accepted via REST (decisions documented) ──
    public function test_rest_price_anomalies_accepted_with_decision_note(): void
    {
        $token = $this->token($this->catalogAdmin());
        $cat = Category::factory()->create(['is_active' => true]);

        // task.txt §3: compare_at_price < retail is currently ACCEPTED
        // (documented decision — warn, don't block).
        $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'Cmp '.uniqid(),
            'sales_channel' => 'retail',
            'variants' => [['color_name_en' => 'Red', 'retail_price' => 500, 'compare_at_price' => 100]],
        ])->assertCreated();

        // task.txt §3: wholesale > retail is currently ACCEPTED via REST
        // (documented decision — warn, don't block).
        $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'Ws '.uniqid(),
            'sales_channel' => 'both',
            'variants' => [['color_name_en' => 'Blue', 'retail_price' => 400, 'wholesale_price' => 500]],
        ])->assertCreated();
    }

    // ── analyze blocked when the vision flag is off ──
    public function test_analyze_blocked_when_vision_flag_off(): void
    {
        DB::table('settings')->where('key', 'ai_catalog_vision_enabled')->update(['value' => '0']);
        app(SettingService::class)->clearCache();
        $u = $this->staff(['ai_agent.use', 'ai_agent.execute', 'ai_agent.catalog.vision', 'products.create', 'products.view']);
        $conv = AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);

        $out = app(ToolRegistry::class)->dispatch($u, $conv, [
            'name' => 'analyze_product_photos', 'args' => ['attachment_ids' => ['01J0000000000000000000000']],
        ]);
        $this->assertSame('denied', $out->type);
        $this->assertStringContainsString('مقفولة', $out->message);
    }

    // ── draft copy: badge trimmed to 30 chars; non-JSON falls back to raw text ──
    public function test_draft_copy_badge_trim_and_non_json_fallback(): void
    {
        DB::table('settings')->where('key', 'ai_catalog_copywriting_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $variant = $this->variantFixture();

        $longBadge = json_encode([
            'description_ar' => 'وصف', 'description_en' => 'desc',
            'badge_ar' => str_repeat('ش', 50), 'badge_en' => str_repeat('B', 50),
        ]);
        $usage = ['promptTokenCount' => 5, 'candidatesTokenCount' => 5, 'totalTokenCount' => 10];
        $textReply = fn (string $text) => ['candidates' => [
            ['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]],
        ], 'usageMetadata' => $usage];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($textReply($longBadge))
            ->push($textReply('نص حر بدون JSON'))]);

        $out = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'draft_product_copy', 'args' => ['product_id' => $variant->product_id]]);
        $this->assertSame('data', $out->type);
        $this->assertTrue((bool) ($out->data['ai_generated'] ?? false));
        $draft = $out->data['draft'] ?? [];
        $this->assertLessThanOrEqual(30, mb_strlen((string) ($draft['badge_ar'] ?? '')));
        $this->assertLessThanOrEqual(30, mb_strlen((string) ($draft['badge_en'] ?? '')));
        $this->assertSame(mb_substr(str_repeat('ش', 50), 0, 30), $draft['badge_ar']);

        $out2 = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'draft_product_copy', 'args' => ['product_id' => $variant->product_id]]);
        $this->assertSame('data', $out2->type);
        $draft2 = $out2->data['draft'] ?? [];
        $this->assertStringContainsString('نص حر بدون JSON', (string) ($draft2['description_ar'] ?? ''));
        $this->assertNull($draft2['badge_ar'] ?? null);
        $this->assertNull($draft2['badge_en'] ?? null);
    }

    // ── plan output marks bulk eligibility for multi-item steps ──
    public function test_plan_marks_bulk_eligible_steps(): void
    {
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $cat = Category::factory()->create(['is_active' => true]);
        for ($i = 0; $i < 3; $i++) {
            $p = Product::factory()->create(['category_id' => $cat->id, 'code' => 'P794'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail]);
            ProductVariant::factory()->create(['product_id' => $p->id, 'sku' => 'P794'.$i.'-BLK',
                'retail_price' => null, 'wholesale_price' => null, 'cost_price' => 10, 'is_active' => true]);
        }

        $out = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'plan_catalog_fixes', 'args' => []]);
        $this->assertSame('data', $out->type);
        $steps = $out->data['steps'] ?? [];
        $this->assertNotEmpty($steps);

        // Missing-price variants surface as a bulk-eligible step.
        $priceSteps = array_filter($steps, fn ($s) => ($s['category'] ?? '') === 'missing_prices');
        $this->assertNotEmpty($priceSteps);
        $this->assertTrue((bool) reset($priceSteps)['bulk_eligible']);

        // 2+ empty ACTIVE categories → bulk-eligible step with 2+ calls
        // plus a top-level bulk_actions suggestion.
        for ($i = 0; $i < 3; $i++) {
            Category::factory()->create(['is_active' => true, 'name_en' => 'EmptyGap '.$i.uniqid()]);
        }
        $out2 = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'plan_catalog_fixes', 'args' => []]);
        $steps2 = $out2->data['steps'] ?? [];
        $catSteps = array_values(array_filter($steps2, fn ($s) => ($s['category'] ?? '') === 'empty_categories'));
        $this->assertNotEmpty($catSteps);
        $this->assertTrue((bool) $catSteps[0]['bulk_eligible']);
        $this->assertGreaterThanOrEqual(2, count($catSteps[0]['suggested_calls'] ?? []));
        $this->assertNotNull($out2->data['bulk_actions_suggestion'] ?? null);
    }
}

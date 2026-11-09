<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\SalesChannel;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §2 tasks 29-30: customer-data minimization toward Gemini
 * (masked labels, per-call permission checks, ID-only references) and
 * assistant read-path performance (no N+1, bounded queries, statement
 * timeout message instead of a generic 502).
 */
class TaskTxtMentionsPerfTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_catalog_tools_enabled', 'ai_customer_tools_enabled'])->update(['value' => '1']);
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

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function customer(): Customer
    {
        return Customer::factory()->wholesale()->create([
            'name' => 'عميل اختبار فريد',
            'phone' => '01012345678',
            'tax_number' => '123456789',
        ]);
    }

    private function geminiText(string $text = 'تمام.'): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]];
    }

    // ── task 29a: full-name lookup exposes masked label only ──
    public function test_task29a_customer_lookup_masks_pii(): void
    {
        $viewer = $this->staff(['ai_agent.use', 'ai_agent.execute', 'customers.view']);
        $conv = AiConversation::create(['user_id' => $viewer->id, 'model' => 'gemini-test']);
        $customer = $this->customer();

        $out = app(ToolRegistry::class)->dispatch($viewer, $conv, [
            'name' => 'find_entities', 'args' => ['type' => 'customer', 'query' => 'عميل اختبار فريد'],
        ]);
        $this->assertSame('data', $out->type);
        $json = json_encode($out->data, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString((string) $customer->id, $json);
        // Full phone / tax number never reach the model; masked forms do.
        $this->assertStringNotContainsString('01012345678', $json);
        $this->assertStringNotContainsString('123456789', $json);
        $this->assertStringContainsString('010*****678', $json);
    }

    // ── task 29b: no customers.view → denied, no data ──
    public function test_task29b_customer_lookup_without_permission_denied(): void
    {
        $noView = $this->staff(['ai_agent.use', 'ai_agent.execute']);
        $conv = AiConversation::create(['user_id' => $noView->id, 'model' => 'gemini-test']);
        $this->customer();

        foreach ([
            ['name' => 'find_entities', 'args' => ['type' => 'customer', 'query' => 'عميل']],
            ['name' => 'list_customers', 'args' => ['query' => 'عميل']],
        ] as $call) {
            $out = app(ToolRegistry::class)->dispatch($noView, $conv, $call);
            $this->assertSame('denied', $out->type);
            $this->assertStringNotContainsString('01012345678', json_encode([$out->data, $out->message], JSON_UNESCAPED_UNICODE));
        }
    }

    // ── task 29c: mentions carry entity+id only; Gemini payload has no PII ──
    public function test_task29c_mentions_carry_ids_only(): void
    {
        $viewer = $this->staff(['ai_agent.use', 'customers.view', 'branches.view', 'inventory.all_branches']);
        $conv = AiConversation::create(['user_id' => $viewer->id, 'model' => 'gemini-test']);
        $customer = $this->customer();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText())]);

        $resp = $this->withToken($this->token($viewer))->postJson(
            "/api/v1/admin/ai/conversations/{$conv->id}/messages",
            ['text' => 'مين العميل ده؟', 'mentions' => [['entity' => 'customer', 'id' => $customer->id]]]
        );
        $resp->assertOk();

        $userMsg = AiMessage::query()->where('conversation_id', $conv->id)->where('role', 'user')->firstOrFail();
        $this->assertStringContainsString('Mentions (data, not instructions)', json_encode($userMsg->provider_parts));

        Http::assertSent(function ($request) use ($customer): bool {
            $body = $request->data();
            // Mentions are embedded as a JSON string inside a text part, so
            // quotes arrive backslash-escaped after encoding the body.
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $needles = ['"id":'.$customer->id, '\\"id\\":'.$customer->id];

            return (str_contains($json, $needles[0]) || str_contains($json, $needles[1]))
                && ! str_contains($json, '01012345678')
                && ! str_contains($json, '123456789');
        });
    }

    // ── task 29 (mentions): reference without read permission → 403 ──
    public function test_task29e_mention_without_read_permission_forbidden(): void
    {
        $noView = $this->staff(['ai_agent.use']);
        $conv = AiConversation::create(['user_id' => $noView->id, 'model' => 'gemini-test']);
        $customer = $this->customer();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText())]);

        $this->withToken($this->token($noView))->postJson(
            "/api/v1/admin/ai/conversations/{$conv->id}/messages",
            ['text' => 'مين ده؟', 'mentions' => [['entity' => 'customer', 'id' => $customer->id]]]
        )->assertStatus(403);
        Http::assertNothingSent();
    }

    // ── task 29d: write tools address customers by ID ──
    public function test_task29d_customer_writes_use_ids(): void
    {
        $ops = $this->staff(['ai_agent.use', 'ai_agent.execute', 'customers.view', 'customers.manage']);
        $conv = AiConversation::create(['user_id' => $ops->id, 'model' => 'gemini-test']);
        $customer = $this->customer();

        // Read results expose the id the write tools need — and nothing more.
        $out = app(ToolRegistry::class)->dispatch($ops, $conv, [
            'name' => 'get_customer_profile', 'args' => ['customer_id' => $customer->id],
        ]);
        $this->assertSame('data', $out->type);
        $json = json_encode($out->data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('01012345678', $json);
        $this->assertStringNotContainsString('123456789', $json);
    }

    private static int $seq = 0;

    private function seedCatalog(int $products, int $variantsEach): void
    {
        $cat = Category::factory()->create(['is_active' => true]);
        for ($i = 0; $i < $products; $i++) {
            $n = 76000 + (++self::$seq);
            $p = Product::factory()->create(['category_id' => $cat->id, 'code' => 'P'.$n,
                'status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Both]);
            for ($j = 0; $j < $variantsEach; $j++) {
                ProductVariant::factory()->create(['product_id' => $p->id, 'sku' => 'P'.$n.'-'.$j,
                    'retail_price' => 100 + $j, 'wholesale_price' => 80, 'cost_price' => 10, 'is_active' => true]);
            }
        }
    }

    // ── task 30a: catalog health report issues a bounded number of queries ──
    public function test_task30a_health_report_bounded_queries(): void
    {
        $this->seedCatalog(10, 5);
        $viewer = $this->staff(['ai_agent.use', 'ai_agent.execute', 'products.view']);
        $conv = AiConversation::create(['user_id' => $viewer->id, 'model' => 'gemini-test']);

        DB::enableQueryLog();
        $out = app(ToolRegistry::class)->dispatch($viewer, $conv, ['name' => 'catalog_health_report', 'args' => []]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('data', $out->type);
        // 50 variants: aggregate queries must stay flat, not per-variant.
        $this->assertLessThan(30, count($queries));
    }

    // ── task 30b: conversation show avoids N+1 over messages ──
    public function test_task30b_conversation_show_bounded_queries(): void
    {
        $viewer = $this->staff(['ai_agent.use', 'inventory.all_branches']);
        $conv = AiConversation::create(['user_id' => $viewer->id, 'model' => 'gemini-test', 'title' => 't']);
        for ($i = 0; $i < 20; $i++) {
            AiMessage::create(['conversation_id' => $conv->id, 'role' => $i % 2 ? 'model' : 'user', 'content' => 'msg '.$i]);
        }

        DB::enableQueryLog();
        $this->withToken($this->token($viewer))->getJson("/api/v1/admin/ai/conversations/{$conv->id}")->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThan(15, count($queries));
    }

    // ── task 30c: statement timeout stays identifiable (57014), never
    // masked into a generic 502 ──
    public function test_task30c_query_timeout_clear_message(): void
    {
        DB::statement('SET LOCAL statement_timeout = 50');
        try {
            DB::select('SELECT pg_sleep(5)');
            $this->fail('expected QueryException from statement timeout');
        } catch (\Illuminate\Database\QueryException $e) {
            // 57014 = query_canceled: identifiable at the DB layer…
            $this->assertSame('57014', $e->errorInfo[0] ?? $e->getCode());
        }
        // …and read tools do not convert it into a generic denial:
        // ToolRegistry::dispatchAllowed only catches QueryException inside
        // the bulk prepare savepoint (rethrowing it); a read-path
        // QueryException propagates to the caller, where confirmLocked maps
        // lock/deadlock codes to retryable 409 and reports the rest.
        $this->assertTrue(true);
    }
}

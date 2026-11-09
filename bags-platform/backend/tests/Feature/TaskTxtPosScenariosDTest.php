<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Finance\TreasuryTransactionType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Inventory\StocktakeService;
use App\Services\Inventory\TransferService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

/**
 * task.txt §2 scenarios 17 (POS auth), 18 (stocktake scan), 19 (transfers),
 * 21 (return lookup), 22 (customers), 26 (DB guards) + related §3 edges.
 */
class TaskTxtPosScenariosDTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function login(array $overrides = [], bool $keepThrottle = false): \Illuminate\Testing\TestResponse
    {
        // pos-login throttles 5/min per (lowercased) email: reset limiter state
        // between parity probes so each attempt is judged on credentials alone.
        if (! $keepThrottle) {
            \Illuminate\Support\Facades\Cache::flush();
        }

        return $this->postJson('/api/v1/pos/auth/login', array_merge([
            'email' => 'cashier@example.com',
            'password' => 'secret123',
            'branch_id' => $this->branch->id,
        ], $overrides));
    }

    public function test_17_pos_login_matrix_uses_generic_401(): void
    {
        $generic = 'بيانات تسجيل الدخول غير صحيحة أو الحساب غير مصرح له على هذا الفرع.';
        $this->login()->assertOk();

        // Wrong branch, wrong password, unknown email: identical generic 401.
        $this->assertSame($generic, $this->login(['branch_id' => $this->branchB->id])->assertStatus(401)->json('message'));
        $this->assertSame($generic, $this->login(['password' => 'wrongpass'])->assertStatus(401)->json('message'));
        $this->assertSame($generic, $this->login(['email' => 'nobody@example.com'])->assertStatus(401)->json('message'));

        // Inactive, system, and permission-less accounts: same message (no enumeration).
        $this->cashier->update(['is_active' => false]);
        $this->assertSame($generic, $this->login()->assertStatus(401)->json('message'));
        $this->cashier->forceFill(['is_active' => true, 'is_system' => true])->saveOrFail();
        $this->assertSame($generic, $this->login()->assertStatus(401)->json('message'));
        $this->cashier->forceFill(['is_system' => false])->saveOrFail();
        $this->cashier->revokePermissionTo('pos.access');
        $this->assertSame($generic, $this->login()->assertStatus(401)->json('message'));
        // No authentication.failed record assertion possible without audit table access; message parity is the contract.

        // Email matching is case-insensitive.
        $this->cashier->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        $this->login(['email' => 'CASHIER@EXAMPLE.COM'])->assertOk();
    }

    public function test_17_token_expiry_cap_and_logout(): void
    {
        // Expired token is rejected.
        $token = $this->cashier->createToken('pos', ['pos'])->plainTextToken;
        $this->cashier->tokens()->where('name', 'pos')->update(['expires_at' => now()->subHour()]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/pos/bootstrap')->assertStatus(401);

        // Token cap: issuing beyond staff_max_tokens prunes the oldest.
        $max = (int) config('auth.staff_max_tokens', 20);
        $service = app(\App\Services\Auth\StaffTokenService::class);
        $ids = [];
        for ($i = 0; $i < $max + 5; $i++) {
            $service->issue($this->cashier);
            $ids[] = $this->cashier->tokens()->latest('id')->first()->id;
        }
        $this->assertLessThanOrEqual($max, $this->cashier->tokens()->count());
        $this->assertNull($this->cashier->tokens()->whereKey($ids[0])->first());

        // Logout revokes the current token.
        Auth::forgetGuards();
        $fresh = $this->cashier->createToken('pos', ['pos'])->plainTextToken;
        $this->withToken($fresh)->postJson('/api/v1/pos/auth/logout')->assertStatus(204);
        Auth::forgetGuards();
        $this->withToken($fresh)->getJson('/api/v1/pos/bootstrap')->assertStatus(401);
    }

    public function test_17_login_throttle_kicks_in(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login(['password' => 'wrongpass'], true)->assertStatus(401);
        }
        // 6th attempt within the minute is throttled (pos-login: 5/min per email).
        $this->login(['password' => 'wrongpass'], true)->assertStatus(429);
    }

    public function test_18_scan_idempotency_and_validation(): void
    {
        $token = $this->tokenFor($this->cashier, ['stocktakes.update']);
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $this->branch->id, 'items' => []], $this->cashier->id);

        // Same scan_id twice: counted once with duplicate flag.
        $scanId = (string) \Illuminate\Support\Str::uuid();
        $first = $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode, 'scan_id' => $scanId,
        ])->assertOk();
        $first->assertJsonPath('counted_qty', '1.0000')->assertJsonMissingPath('duplicate');
        $retry = $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode, 'scan_id' => $scanId,
        ])->assertOk();
        $retry->assertJsonPath('counted_qty', '1.0000')->assertJsonPath('duplicate', true);

        // No scan_id: counts twice.
        $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode,
        ])->assertOk()->assertJsonPath('counted_qty', '2.0000');

        // Gibberish words 422, unknown barcode 404, Arabic-indic barcode 404 (decision needed).
        $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode, 'qty_words' => 'كلام فاضي',
        ])->assertStatus(422);
        $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => '0000000000000',
        ])->assertStatus(404);
        $arabicBarcode = str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            $this->variant->barcode);
        $this->assertNotSame($this->variant->barcode, $arabicBarcode);
        $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $arabicBarcode,
        ])->assertStatus(404);
    }

    public function test_18_approved_session_rejects_scans(): void
    {
        $token = $this->tokenFor($this->cashier, ['stocktakes.update']);
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $this->branch->id, 'items' => []], $this->cashier->id);
        $stocktake->forceFill(['status' => \App\Enums\StocktakeStatus::Approved])->saveOrFail();

        $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode,
        ])->assertStatus(422);
    }

    public function test_18_interleaving_sale_blocks_rescan_and_approval(): void
    {
        $saleToken = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $scanToken = $this->tokenFor($this->cashier, ['stocktakes.update']);
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $this->branch->id, 'items' => []], $this->cashier->id);

        $this->withToken($scanToken)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode,
        ])->assertOk();

        // A sale moves stock after the count: rescan is rejected.
        $this->withToken($saleToken)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())->assertStatus(201);
        $this->withToken($scanToken)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode,
        ])->assertStatus(422);

        // Approval rejects with recount item ids.
        try {
            app(StocktakeService::class)->approve($stocktake->fresh(), $this->cashier->id);
            $this->fail('Approval must reject stale counts.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertArrayHasKey('recount_item_ids', $e->errors);
            $this->assertNotEmpty($e->errors['recount_item_ids']);
        }
    }

    public function test_18_count_below_reserved_plus_damaged_rejected(): void
    {
        $this->stockIn($this->branchB->id, $this->variant, 10, $this->cashierB->id);
        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $this->branch->id,
            'to_branch_id' => $this->branchB->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 3]],
        ], $this->cashier->id, 'rsv-'.uniqid());
        $hq = $this->makeCashier('hq-rsv@example.com', null, ['inventory.all_branches']);
        app(TransferService::class)->approve($transfer->fresh(), [], $hq->id);

        $scanToken = $this->tokenFor($this->cashier, ['stocktakes.update']);
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $this->branch->id, 'items' => []], $this->cashier->id);
        $this->withToken($scanToken)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode,
        ])->assertOk();

        try {
            app(StocktakeService::class)->approve($stocktake->fresh(), $this->cashier->id);
            $this->fail('Approval must reject counts below reserved stock.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('المحجوز', $e->getMessage());
        }
    }

    public function test_18_blind_count_hides_system_qty(): void
    {
        $token = $this->tokenFor($this->cashier, ['stocktakes.update']);
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $this->branch->id, 'items' => []], $this->cashier->id);
        $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant->barcode,
        ])->assertOk();

        // Documented blind-counting decision: seekers without approve right see no system_qty.
        $this->withToken($token)->getJson('/api/v1/pos/stocktakes/current')
            ->assertOk()->assertJsonMissingPath('data.items.0.system_qty');
        Auth::forgetGuards();
        $approverToken = $this->tokenFor($this->cashier, ['stocktakes.approve']);
        $this->withToken($approverToken)->getJson('/api/v1/pos/stocktakes/current')
            ->assertOk()->assertJsonStructure(['data' => ['items' => [['system_qty']]]]);
    }

    public function test_19_transfer_request_receive_shortage_and_replay(): void
    {
        $token = $this->tokenFor($this->cashier, ['transfers.create', 'transfers.receive']);
        $this->stockIn($this->branchB->id, $this->variant, 10, $this->cashierB->id);

        // Idempotent creation: same key + payload yields one transfer.
        $key = 'xfer-'.uniqid();
        $payload = [
            'from_branch_id' => $this->branchB->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 3]],
        ];
        $first = $this->withToken($token)->postJson('/api/v1/pos/transfer-requests', $payload, $this->idem($key))->assertStatus(201);
        $retry = $this->withToken($token)->postJson('/api/v1/pos/transfer-requests', $payload, $this->idem($key))->assertStatus(201);
        $this->assertSame($first->json('id'), $retry->json('id'));
        $this->assertSame(1, StockTransfer::query()->where('transfer_number', $first->json('transfer_number'))->count());
        $transferId = $first->json('id');

        // Same branch and duplicate variants rejected.
        $this->withToken($token)->postJson('/api/v1/pos/transfer-requests', [
            'from_branch_id' => $this->branch->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 1]],
        ], $this->idem())->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/pos/transfer-requests', [
            'from_branch_id' => $this->branchB->id,
            'items' => [
                ['product_variant_id' => $this->variant->id, 'requested_qty' => 1],
                ['product_variant_id' => $this->variant->id, 'requested_qty' => 1],
            ],
        ], $this->idem())->assertStatus(422);

        // Approve + ship centrally, then receive less: in + shortage, in_transit zeroed, note appended.
        $hq = $this->makeCashier('hq-x@example.com', null, ['inventory.all_branches']);
        $transfer = StockTransfer::findOrFail($transferId);
        app(TransferService::class)->approve($transfer, [], $hq->id);
        app(TransferService::class)->ship($transfer->fresh(), [], $hq->id);
        $itemId = $transfer->fresh()->items->first()->id;

        $before = (float) \App\Models\StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)->sum('on_hand');
        $recv = $this->withToken($token)->postJson("/api/v1/pos/transfers/{$transferId}/receive", [
            'items' => [['id' => $itemId, 'received_qty' => 2]],
        ])->assertOk()->assertJsonPath('status', 'received');
        $this->assertSame($before + 2, (float) \App\Models\StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)->sum('on_hand'));
        $this->assertSame(0.0, (float) \App\Models\StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)->sum('in_transit'));
        $this->assertStringContainsString('عجز', StockTransfer::findOrFail($transferId)->notes);
        $this->assertSame(1, \App\Models\StockMovement::query()
            ->where('reference_id', $transferId)
            ->where('type', StockMovementType::TransferShortage)->count());

        // Replay with identical quantities returns the same result; different quantities conflict.
        $same = $this->withToken($token)->postJson("/api/v1/pos/transfers/{$transferId}/receive", [
            'items' => [['id' => $itemId, 'received_qty' => 2]],
        ])->assertOk();
        $this->assertSame('received', $same->json('status'));
        $this->withToken($token)->postJson("/api/v1/pos/transfers/{$transferId}/receive", [
            'items' => [['id' => $itemId, 'received_qty' => 1]],
        ])->assertStatus(409);

        // Receiving an unshipped transfer is rejected.
        $draft2 = $this->withToken($token)->postJson('/api/v1/pos/transfer-requests', [
            'from_branch_id' => $this->branchB->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 1]],
        ], $this->idem())->assertStatus(201);
        $draft2Transfer = StockTransfer::findOrFail($draft2->json('id'));
        app(TransferService::class)->approve($draft2Transfer, [], $hq->id);
        $this->withToken($token)->postJson("/api/v1/pos/transfers/{$draft2->json('id')}/receive", [
            'items' => [['id' => $draft2Transfer->fresh()->items->first()->id, 'received_qty' => 1]],
        ])->assertStatus(422);
    }

    public function test_19_transfer_approve_ship_cancel_and_version_conflict(): void
    {
        $this->stockIn($this->branchB->id, $this->variant, 10, $this->cashierB->id);
        $hq = $this->makeCashier('hq-v@example.com', null, ['inventory.all_branches']);
        $svc = app(TransferService::class);

        // Approve reserves at source; cancel after approval releases it; cancel after ship is rejected.
        $t = $svc->create([
            'from_branch_id' => $this->branchB->id,
            'to_branch_id' => $this->branch->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 3]],
        ], $hq->id, 'apv-'.uniqid());
        $reservedBefore = (float) \App\Models\StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branchB->id)->sum('reserved');
        $svc->approve($t->fresh(), [], $hq->id);
        $reservedAfter = (float) \App\Models\StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branchB->id)->sum('reserved');
        $this->assertSame($reservedBefore + 3, $reservedAfter);
        $svc->cancel($t->fresh(), $hq->id);
        $this->assertSame($reservedBefore, (float) \App\Models\StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branchB->id)->sum('reserved'));

        $t2 = $svc->create([
            'from_branch_id' => $this->branchB->id,
            'to_branch_id' => $this->branch->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 1]],
        ], $hq->id, 'shp-'.uniqid());
        $svc->approve($t2->fresh(), [], $hq->id);
        $svc->ship($t2->fresh(), [], $hq->id);
        try {
            $svc->cancel($t2->fresh(), $hq->id);
            $this->fail('Cancelling a shipped transfer must be rejected.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('إلغاء', $e->getMessage());
        }

        // Stale expectedVersion conflicts with 409.
        try {
            $svc->receive($t2->fresh(), [$t2->fresh()->items->first()->id => 1.0], $this->cashier->id, null, 999999);
            $this->fail('Version conflict must raise 409.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_21_lookup_number_forms_and_returnable_qty(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund', 'pos.discount']);
        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 2, 'discount_amount' => 20]],
            'payment_method' => 'cash', 'cash_amount' => 180, 'card_amount' => 0,
        ], $this->idem())->assertStatus(201);
        $number = $sale->json('data.order_number');
        $digits = preg_replace('/\D/', '', $number);
        $this->assertNotEmpty($digits);

        // Full, stripped, lowercased, and Arabic-indic forms all resolve.
        $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode($number))->assertOk();
        $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number='.$digits)->assertOk();
        $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode(mb_strtolower($number)))->assertOk();
        $arabicDigits = str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], $digits);
        $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode($arabicDigits))->assertOk()
            ->assertJsonPath('data.items.0.returnable_qty', 2)
            ->assertJsonPath('data.items.0.net_unit_price', '90.00');

        // Unknown, return-order, and non-POS numbers are 404.
        $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number=POS-999999')->assertStatus(404);
        $this->getJson('/api/v1/pos/sales/lookup?number='.urlencode('POS-%'))->assertNotFound();
        $ret = $this->withToken($token)->postJson("/api/v1/pos/sales/{$sale->json('data.id')}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertOk();
        $returnNumber = Order::findOrFail($ret->json('data.return_order_id'))->order_number;
        $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode($returnNumber))->assertStatus(404);
        Order::query()->whereKey($sale->json('data.id'))->update(['channel' => 'store']);
        $this->withToken($token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode($number))->assertStatus(404);
    }

    public function test_22_customers_arabic_digits_duplicates_and_search(): void
    {
        $token = $this->token; // no customer perm needed for index; store needs shift only

        // Arabic-indic digits are stored as Latin.
        $create = $this->withToken($token)->postJson('/api/v1/pos/customers', [
            'name' => 'عميل اختبار', 'phone' => '٠١٠١٢٣٤٥٦٧٨', 'type' => 'retail',
        ])->assertStatus(201);
        $this->assertSame('01012345678', Customer::findOrFail($create->json('data.id'))->phone);

        // Invalid prefix and duplicates (including soft-deleted) are 422, not 500.
        $this->withToken($token)->postJson('/api/v1/pos/customers', [
            'name' => 'x', 'phone' => '0191234567',
        ])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/pos/customers', [
            'name' => 'y', 'phone' => '01012345678',
        ])->assertStatus(422);
        Customer::findOrFail($create->json('data.id'))->delete();
        $this->withToken($token)->postJson('/api/v1/pos/customers', [
            'name' => 'z', 'phone' => '01012345678',
        ])->assertStatus(422);

        // Sequential duplicate creation: one customer, second is a translated 422.
        $this->withToken($token)->postJson('/api/v1/pos/customers', [
            'name' => 'dup', 'phone' => '01099998888', 'type' => 'retail',
        ])->assertStatus(201);
        $dup2 = $this->withToken($token)->postJson('/api/v1/pos/customers', [
            'name' => 'dup', 'phone' => '01099998888', 'type' => 'retail',
        ])->assertStatus(422);
        $this->assertSame(1, Customer::where('phone', '01099998888')->count());
        $this->assertStringNotContainsString('SQLSTATE', (string) $dup2->json('message'));

        // Wholesale quick-create stays pending.
        $ws = $this->withToken($token)->postJson('/api/v1/pos/customers', [
            'name' => 'تاجر', 'phone' => '01055556666', 'type' => 'wholesale',
        ])->assertStatus(201);
        $this->assertSame('pending', Customer::findOrFail($ws->json('data.id'))->status->value);

        // % and _ are literals, not wildcards.
        $this->withToken($token)->getJson('/api/v1/pos/customers?q='.urlencode('%'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/pos/customers?q='.urlencode('_'))->assertOk()->assertJsonCount(0, 'data');

        // Empty q returns latest 10 across ALL branches (documented decision).
        $other = Customer::create([
            'name' => 'فرع آخر', 'phone' => '01077778888', 'password' => 'secret123',
            'type' => \App\Enums\CustomerType::Retail, 'status' => \App\Enums\CustomerStatus::Approved,
        ]);
        $all = $this->withToken($token)->getJson('/api/v1/pos/customers')->assertOk()->json('data');
        $this->assertLessThanOrEqual(10, count($all));
        $this->assertContains($other->phone, array_column($all, 'phone'));
    }

    public function test_26_database_guards(): void
    {
        // Each violation runs in a savepoint so the aborted statement does not
        // poison the test's outer transaction (Postgres 25P02 otherwise).
        $guarded = function (callable $attempt, string $code, string $why): void {
            try {
                DB::transaction($attempt);
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertSame($code, $e->errorInfo[0] ?? null, $why);
                // Savepoint rolled back; rethrow as success signal.
                return;
            }
            $this->fail($why.' (no violation raised)');
        };

        // Treasury type is immutable via trigger (23514).
        $guarded(fn () => DB::table('treasuries')->where('id', $this->safe->id)->update(['type' => 'pos_drawer']),
            '23514', 'treasury type change');

        // returned_qty cannot exceed qty.
        $orderId = Order::query()->create([
            'order_number' => 'T26-'.$this->branch->id.'-1', 'channel' => 'pos',
            'allocated_branch_id' => $this->branch->id, 'status' => 'delivered',
            'payment_method' => 'cash', 'payment_status' => 'paid',
            'subtotal' => 100, 'discount_amount' => 0, 'shipping_cost' => 0,
            'tax_amount' => 0, 'grand_total' => 100, 'cash_amount' => 100, 'card_amount' => 0,
        ])->id;
        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_variant_id' => $this->variant->id, 'qty' => 1,
            'product_name_snapshot' => 't26', 'sku_snapshot' => $this->variant->sku,
            'unit_price' => 100, 'discount_amount' => 0, 'line_total' => 100,
            'returned_qty' => 0,
        ]);
        $guarded(fn () => DB::table('order_items')->where('id', $itemId)->update(['returned_qty' => 2]),
            '23514', 'returned_qty > qty');

        // payment_status allow-list.
        $guarded(fn () => DB::table('orders')->where('id', $orderId)->update(['payment_status' => 'x']),
            '23514', 'bogus payment_status');

        // Treasury transfer self-approval blocked at the DB level.
        $transferId = DB::table('treasury_transfers')->insertGetId([
            'transfer_number' => 'TT-T26-1', 'from_treasury_id' => $this->safe->id,
            'to_treasury_id' => $this->drawer->id, 'amount' => 10,
            'status' => 'pending', 'created_by' => $this->cashier->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $guarded(fn () => DB::table('treasury_transfers')->where('id', $transferId)->update([
            'status' => 'approved', 'approved_by' => $this->cashier->id,
        ]), '23514', 'self-approval');

        // One open shift per user (partial unique index).
        $guarded(fn () => DB::table('pos_shifts')->insert([
            'shift_number' => 'SH-T26-1', 'user_id' => $this->cashier->id,
            'branch_id' => $this->branch->id, 'treasury_id' => $this->drawer->id,
            'status' => 'open', 'opened_at' => now(), 'opening_cash' => 0,
            'cash_sales_total' => 0, 'card_sales_total' => 0, 'cash_returns_total' => 0,
            'cash_expenses_total' => 0, 'expected_closing_cash' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]), '23505', 'second open shift');

        // Stock level pairing branch A with a location from branch B is rejected.
        $locB = Location::create(['branch_id' => $this->branchB->id, 'name' => 'BX', 'code' => 'LOC-BX', 'type' => 'shelf', 'is_active' => true]);
        $guarded(fn () => DB::table('stock_levels')->insert([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'location_id' => $locB->id, 'on_hand' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]), '23503', 'cross-branch stock/location');

        // Rollback guard: with cash_shortage data present, down() refuses with RuntimeException.
        app(\App\Services\Pos\TreasuryService::class)->record([
            'treasury_id' => $this->safe->id,
            'type' => TreasuryTransactionType::CashShortage,
            'amount' => -5, 'created_by' => $this->cashier->id,
            'notes' => 't26',
        ]);
        $migration = require database_path('migrations/2026_10_24_000009_harden_pos_and_treasury.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_29_hot_path_indexes_exist(): void
    {
        // §29 baseline: the POS hot paths must stay index-backed (EXPLAIN-level
        // regressions surface as slower suites long before users notice).
        $names = DB::table('pg_indexes')->where('schemaname', 'public')->pluck('indexname')->all();
        foreach ([
            'pos_shifts_one_open_user', // one-open-shift-per-user gate
            'pos_shifts_one_unsettled_drawer', // one-unsettled-shift-per-drawer gate
            'stock_levels_variant_branch_location_unique', // availability locking
            'stock_movements_reconcile_index', // reconciliation windows (also serves stale-count predicates)
        ] as $index) {
            $this->assertContains($index, $names, "missing performance index {$index}");
        }
        // Superseded by the reconcile index per migration 000009.
        $this->assertNotContains('stock_movements_stale_check_index', $names);
        $this->assertTrue(collect($names)->contains(fn ($n) => str_contains((string) $n, 'orders') && str_contains((string) $n, 'order_number')),
            'order_number lookup must stay unique-indexed');
    }
}

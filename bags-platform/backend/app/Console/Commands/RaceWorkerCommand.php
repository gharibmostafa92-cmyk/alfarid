<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Api\V1\Admin\Pricing\CouponController;
use App\Http\Requests\Admin\Promotion\CouponRequest;
use App\Models\AiPendingAction;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Pricing\CouponService;
use App\Services\Customer\CustomerGroupService;
use App\Services\Pricing\PriceListService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Test-only worker for genuine multi-process concurrency tests
 * (P07ParallelRaceTest). Registered only when APP_ENV=testing.
 * Reads a JSON payload file, executes one job, prints one JSON line.
 */
class RaceWorkerCommand extends Command
{
    protected $signature = 'race:worker {--file= : path to JSON payload file}';

    protected $description = 'Test-only concurrency worker (testing env only).';

    public function runJob(string $file): int
    {
        $payload = json_decode((string) file_get_contents($file), true);
        if (! is_array($payload)) {
            fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'bad_payload'])."\n");

            return 1;
        }

        if (! empty($payload['wait_go'])) {
            $this->waitForFile((string) $payload['wait_go']);
        }

        try {
            $result = match ($payload['job']) {
                'migrate' => $this->jobMigrate(),
                'setup_bulk' => $this->jobSetupBulk($payload),
                'confirm' => $this->jobConfirm($payload),
                'bulk_confirm' => $this->jobBulkConfirm($payload),
                'coupon_setup' => $this->jobCouponSetup($payload),
                'coupon_record' => $this->jobCouponRecord($payload),
                'coupon_destroy' => $this->jobCouponDestroy($payload),
                'coupon_store' => $this->jobCouponStore($payload),
                'group_setup' => $this->jobGroupSetup($payload),
                'group_delete' => $this->jobGroupDelete($payload),
                'customer_attach' => $this->jobCustomerAttach($payload),
                'tier_create' => $this->jobTierCreate($payload),
                'tier_setup' => $this->jobTierSetup(),
                'inspect' => $this->jobInspect($payload),
                'count' => $this->jobCount($payload),
                'advisory_hold' => $this->jobAdvisoryHold($payload),
                default => ['ok' => false, 'error' => 'unknown_job'],
            };
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => get_class($e), 'http' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null, 'code' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null, 'message' => $e->getMessage()];
        }

        fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE)."\n");

        return 0;
    }

    private function waitForFile(string $path): void
    {
        $start = microtime(true);
        while (! file_exists($path) && microtime(true) - $start < 30) {
            usleep(50000);
        }
    }

    private function jobMigrate(): array
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'SettingSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'AiAgentPermissionSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'PricingPermissionSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'PromotionPermissionSeeder', '--force' => true]);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_catalog_tools_enabled'])->update(['value' => '1']);

        return ['ok' => true];
    }

    private function actor(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function jobSetupBulk(): array
    {
        $admin = $this->actor(['ai_agent.use', 'ai_agent.execute', 'products.view', 'products.create', 'products.update', 'products.publish']);
        $conv = \App\Models\AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $cat = \App\Models\Category::factory()->create(['is_active' => true]);
        $variantIds = [];
        foreach ([400.0, 400.0] as $i => $price) {
            $n = 90000 + random_int(1000, 99999) + $i;
            $product = \App\Models\Product::factory()->create(['category_id' => $cat->id, 'code' => 'R'.$n,
                'status' => \App\Enums\ProductStatus::Draft, 'sales_channel' => \App\Enums\SalesChannel::Both]);
            $v = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'R'.$n.'-W',
                'retail_price' => $price, 'wholesale_price' => 300, 'cost_price' => 100, 'is_active' => true]);
            $variantIds[] = $v->id;
        }
        $prop = app(\App\Services\Ai\ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'bulk_update_prices',
            'args' => ['field' => 'retail_price', 'operation' => 'percent', 'value' => 10, 'scope' => ['category_id' => $cat->id]]]);
        $action = AiPendingAction::query()->where('id', $prop->data['pending_action_id'])->firstOrFail();

        // A bulk_actions parent over two single-variant children for the lock test.
        $bulk = app(\App\Services\Ai\ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'bulk_actions', 'args' => ['items' => [
            ['tool' => 'update_variant', 'args' => ['variant_id' => $variantIds[0], 'retail_price' => 410]],
            ['tool' => 'update_variant', 'args' => ['variant_id' => $variantIds[1], 'retail_price' => 420]],
        ]]]);
        $parent = AiPendingAction::query()->where('id', $bulk->data['pending_action_id'])->firstOrFail();

        return ['ok' => true, 'user_id' => $admin->id, 'action_id' => $action->id,
            'parent_id' => $parent->id, 'child_ids' => array_values($parent->args['action_ids']), 'variant_ids' => $variantIds];
    }

    private function jobConfirm(array $p): array
    {
        $actor = User::findOrFail($p['user_id']);
        $action = AiPendingAction::findOrFail($p['action_id']);
        try {
            $outcome = app(PendingActionService::class)->confirm($actor, $action, $p['typed'] ?? null, $p['selected'] ?? null);
        } catch (HttpExceptionInterface $e) {
            return ['ok' => false, 'http' => $e->getStatusCode(), 'message' => $e->getMessage(), 'status' => AiPendingAction::find($p['action_id'])?->status];
        }

        return ['ok' => $outcome->type === 'result', 'type' => $outcome->type, 'message' => $outcome->message, 'status' => AiPendingAction::find($p['action_id'])?->status];
    }

    private function jobBulkConfirm(array $p): array
    {
        return $this->jobConfirm($p);
    }

    private function jobCouponSetup(array $p): array
    {
        $coupon = Coupon::factory()->create([
            'code' => $p['code'], 'type' => 'fixed', 'value' => 10,
            'usage_limit' => $p['usage_limit'] ?? null,
            'usage_limit_per_customer' => $p['per_customer'] ?? 5,
        ]);
        $orderIds = [];
        foreach ((array) ($p['orders'] ?? []) as $guest) {
            $orderIds[] = Order::factory()->create(['guest_phone' => $guest, 'customer_id' => null])->id;
        }

        return ['ok' => true, 'coupon_id' => $coupon->id, 'order_ids' => $orderIds];
    }

    private function jobCouponRecord(array $p): array
    {
        $coupon = Coupon::findOrFail($p['coupon_id']);
        $order = Order::findOrFail($p['order_id']);
        $customer = isset($p['customer_id']) ? Customer::findOrFail($p['customer_id']) : null;
        if (! empty($p['sleep_ms'])) {
            usleep((int) $p['sleep_ms'] * 1000);
        }
        $runner = function () use ($coupon, $order, $customer, $p): array {
            app(CouponService::class)->recordUsage($coupon, $order, $customer, (float) ($p['discount'] ?? 10));

            return ['ok' => true, 'used_count' => (int) $coupon->fresh()->used_count];
        };

        if (array_key_exists('in_transaction', $p) && $p['in_transaction'] === false) {
            try {
                return $runner();
            } catch (BusinessException $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        try {
            return DB::transaction($runner);
        } catch (BusinessException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function jobCouponDestroy(array $p): array
    {
        if (! empty($p['sleep_ms'])) {
            usleep((int) $p['sleep_ms'] * 1000);
        }
        try {
            $response = app(CouponController::class)->destroy(Coupon::findOrFail($p['coupon_id']));

            return ['ok' => true, 'http' => $response->getStatusCode()];
        } catch (BusinessException $e) {
            return ['ok' => false, 'http' => $e->status, 'message' => $e->getMessage()];
        } catch (QueryException $e) {
            return ['ok' => false, 'http' => 500, 'message' => 'query:'.$e->getCode()];
        }
    }

    private function jobCouponStore(array $p): array
    {
        try {
            $request = CouponRequest::create('/race', 'POST', $p['payload']);
            $request->setContainer(app())->setRedirector(app('redirect'));
            $request->validateResolved();
            $response = app(CouponController::class)->store($request);

            return ['ok' => true, 'http' => $response->getStatusCode()];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'http' => 422, 'message' => collect($e->errors())->flatten()->implode(' ')];
        } catch (QueryException $e) {
            return ['ok' => false, 'http' => 500, 'message' => 'query:'.$e->getCode()];
        }
    }

    private function jobGroupSetup(): array
    {
        $actor = $this->actor(['customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete', 'inventory.all_branches']);
        $group = app(CustomerGroupService::class)->create(['name' => 'RG', 'code' => 'RG'.random_int(10000, 99999)], $actor, ['channel' => 'race']);
        $customer = Customer::factory()->create();

        return ['ok' => true, 'actor_id' => $actor->id, 'group_id' => $group->id, 'customer_id' => $customer->id];
    }

    private function jobGroupDelete(array $p): array
    {
        if (! empty($p['sleep_ms'])) {
            usleep((int) $p['sleep_ms'] * 1000);
        }
        try {
            app(CustomerGroupService::class)->delete(CustomerGroup::findOrFail($p['group_id']), User::findOrFail($p['actor_id']));

            return ['ok' => true];
        } catch (BusinessException $e) {
            return ['ok' => false, 'http' => $e->status, 'message' => $e->getMessage()];
        } catch (QueryException $e) {
            return ['ok' => false, 'http' => 500, 'message' => 'query:'.$e->getCode()];
        }
    }

    private function jobCustomerAttach(array $p): array
    {
        if (! empty($p['sleep_ms'])) {
            usleep((int) $p['sleep_ms'] * 1000);
        }
        try {
            Customer::query()->whereKey($p['customer_id'])->update(['customer_group_id' => $p['group_id']]);

            return ['ok' => true];
        } catch (QueryException $e) {
            return ['ok' => false, 'http' => 500, 'message' => 'query:'.($e->errorInfo[0] ?? $e->getCode())];
        }
    }

    private function jobTierCreate(array $p): array
    {
        $staff = User::findOrFail($p['staff_id']);
        try {
            $item = app(PriceListService::class)->createItem(PriceList::findOrFail($p['list_id']), [
                'product_variant_id' => $p['variant_id'], 'min_qty' => $p['min_qty'], 'price' => $p['price'],
            ], $staff);

            return ['ok' => true, 'item_id' => $item->id];
        } catch (BusinessException $e) {
            return ['ok' => false, 'http' => $e->status, 'message' => $e->getMessage()];
        } catch (QueryException $e) {
            return ['ok' => false, 'http' => 500, 'message' => 'query:'.($e->errorInfo[0] ?? $e->getCode())];
        }
    }

    private function jobTierSetup(): array
    {
        $staff = $this->actor(['price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete', 'inventory.all_branches']);
        $list = PriceList::query()->create(['name' => 'RL'.random_int(10000, 99999), 'type' => \App\Enums\PriceListType::Retail]);
        $variant = ProductVariant::factory()->create(['retail_price' => 100, 'wholesale_price' => 80]);

        return ['ok' => true, 'staff_id' => $staff->id, 'list_id' => $list->id, 'variant_id' => $variant->id];
    }

    private function jobInspect(array $p): array
    {
        $row = DB::table($p['table'])->where('id', $p['id'])->first();

        return ['ok' => $row !== null, 'row' => $row === null ? null : (array) $row];
    }

    private function jobCount(array $p): array
    {
        $query = DB::table($p['table']);
        foreach ((array) ($p['where'] ?? []) as [$column, $value]) {
            $query->where($column, $value);
        }

        return ['ok' => true, 'count' => $query->count()];
    }

    private function jobAdvisoryHold(array $p): array
    {
        DB::transaction(function () use ($p): void {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [79301, 1]);
            file_put_contents((string) $p['ready_file'], 'ready');
            sleep((int) ($p['seconds'] ?? 8));
        });

        return ['ok' => true];
    }
}

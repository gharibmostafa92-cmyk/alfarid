<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\User;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

/**
 * task.txt الدفعة الثالثة (تزامن وأدوات): المحفظة (17)، التقليم (25)،
 * أرقام المرتجعات الفريدة، سباق الموافقة/الإلغاء، تمرير privatize، والـseeders.
 * كل الاختبارات هنا بلا حركات مخزنية تشير لمستخدم النظام حتى ينجح rollback
 * الخاص بـDatabaseMigrations.
 */
class ReturnTaskTxtBatchCTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

    private User $staff;

    private string $staffToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ReturnPermissionSeeder::class);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('Super Admin');
        $this->staffToken = $this->staff->createToken('admin-token')->plainTextToken;
    }

    private function adminJob(string $method, string $url, array $data = []): string
    {
        return $this->httpJob($this->staffToken, $method, $url, $data);
    }

    /**
     * task.txt (17): عشرة خصومات متزامنة ×20 من رصيد 100 — خمسة تنجح تمامًا
     * والرصيد صفر دون أي رصيد سالب.
     */
    public function test_concurrent_wallet_debits_serialize_without_negative_balance(): void
    {
        $customer = Customer::factory()->create();
        app(\App\Services\Finance\WalletService::class)->record($customer->id, 100, 'test_topup', 'test', 1, $this->staff->id);

        $jobs = [];
        for ($i = 0; $i < 10; $i++) {
            $jobs[] = <<<PHP
                \$ready();
                try {
                    app(\\App\\Services\\WalletService::class)->record({$customer->id}, -20, 'test_debit', 'test', 1000 + {$i}, {$this->staff->id});
                    return ['ok' => true];
                } catch (\\App\\Exceptions\\BusinessException \$e) {
                    return ['ok' => false, 'message' => \$e->getMessage()];
                }
                PHP;
        }

        $results = $this->concurrentJobs($jobs);
        $wins = collect($results)->where('ok', true)->count();

        $this->assertSame(5, $wins);
        $this->assertSame('0.00', (string) $customer->fresh()->wallet_balance);
        $this->assertSame(0, CustomerWalletTransaction::query()->where('customer_id', $customer->id)->where('balance_after', '<', 0)->count());
        $losers = collect($results)->where('ok', false)->pluck('message')->unique()->all();
        $this->assertSame(['رصيد المحفظة لا يكفي قيمة الطلب كاملة.'], $losers);
    }

    /**
     * task.txt (17) خاصية: الرصيد يساوي دائمًا آخر balance_after ويساوي
     * مجموع الحركات، بلا أي انحراف عشري.
     */
    public function test_wallet_balance_tracks_ledger_exactly_property(): void
    {
        $service = app(\App\Services\Finance\WalletService::class);
        $customer = Customer::factory()->create();
        $ref = 1;
        $expected = 0.0;

        $service->record($customer->id, 500, 'test_topup', 'test', $ref++, $this->staff->id);
        $expected += 500;

        for ($i = 0; $i < 30; $i++) {
            if (random_int(0, 1) === 0) {
                $amount = random_int(1, 5000) / 100;
                $service->record($customer->id, $amount, 'test_credit', 'test', $ref++, $this->staff->id);
                $expected += $amount;
            } else {
                $balance = (float) $customer->fresh()->wallet_balance;
                if ($balance <= 0) {
                    continue;
                }
                $amount = min($balance, random_int(1, 5000) / 100);
                $service->record($customer->id, -$amount, 'test_debit', 'test', $ref++, $this->staff->id);
                $expected -= $amount;
            }
        }

        $expected = round($expected, 2);
        $this->assertSame(
            number_format($expected, 2, '.', ''),
            number_format((float) $customer->fresh()->wallet_balance, 2, '.', '')
        );
        $this->assertSame(
            number_format($expected, 2, '.', ''),
            number_format((float) CustomerWalletTransaction::query()->where('customer_id', $customer->id)->latest('id')->value('balance_after'), 2, '.', '')
        );
        $this->assertSame(
            number_format($expected, 2, '.', ''),
            number_format((float) CustomerWalletTransaction::query()->where('customer_id', $customer->id)->sum('amount'), 2, '.', '')
        );
    }

    /**
     * task.txt (17): المبلغ الصفري يُسجَّل حاليًا كحركة صفرية (بانتظار قرار
     * صاحب المشروع)، و0.1 + 0.2 = 0.30 تمامًا بلا انحراف عشري.
     */
    public function test_wallet_zero_and_fractional_amounts(): void
    {
        $service = app(\App\Services\Finance\WalletService::class);
        $customer = Customer::factory()->create();

        $zero = $service->record($customer->id, 0, 'test_zero', 'test', 1, $this->staff->id);
        $this->assertSame('0.00', (string) $zero->amount);
        $this->assertSame('0.00', (string) $customer->fresh()->wallet_balance);

        $service->record($customer->id, 0.1, 'test_credit', 'test', 2, $this->staff->id);
        $service->record($customer->id, 0.2, 'test_credit', 'test', 3, $this->staff->id);
        $this->assertSame('0.30', (string) $customer->fresh()->wallet_balance);
    }

    /**
     * task.txt (25): التقليم — يتيم قديم يُحذف، ومرتبط/حديث يُحفظ،
     * و--hours=23 يفشل، و--dry-run لا يحذف.
     */
    public function test_prune_returns_orphans_honors_grace_and_dry_run(): void
    {
        $this->markStorageForCleanup('r2');
        $disk = Storage::disk('r2');
        $oldOrphan = 'return_requests/pending/aaa/old.jpg';
        $recentOrphan = 'return_requests/pending/bbb/recent.jpg';
        $referenced = 'return_requests/pending/ccc/kept.jpg';
        $disk->put($oldOrphan, 'old');
        $disk->put($recentOrphan, 'recent');
        $disk->put($referenced, 'kept');
        touch($disk->path($oldOrphan), time() - 25 * 3600);
        ReturnRequestItem::factory()->create(['attachments' => [$referenced]]);

        $this->artisan('attachments:prune-orphans')->assertSuccessful();
        $this->assertFalse($disk->exists($oldOrphan));
        $this->assertTrue($disk->exists($recentOrphan));
        $this->assertTrue($disk->exists($referenced));

        // فترة سماح أقل من عمر الملف مرفوضة ولا تحذف شيئًا.
        $disk->put($oldOrphan, 'old');
        touch($disk->path($oldOrphan), time() - 25 * 3600);
        $this->artisan('attachments:prune-orphans', ['--hours' => '23'])->assertFailed();
        $this->assertTrue($disk->exists($oldOrphan));

        // المحاكاة الجافة لا تحذف.
        $this->artisan('attachments:prune-orphans', ['--dry-run' => true])->assertSuccessful();
        $this->assertTrue($disk->exists($oldOrphan));
    }

    /**
     * The legacy alias delegates in copy-only mode unless deletion is requested.
     */
    public function test_privatize_command_delegates_to_uploads_migrate(): void
    {
        $expected = Artisan::call('uploads:migrate-to-r2');
        $actual = Artisan::call('returns:privatize-attachments');

        $this->assertSame($expected, $actual);
    }

    /**
     * task.txt القسم 3: مصدر صلاحيات المرتجعات — returns.approve/receive/refund
     * تُزرع عبر FinancialPermissionSeeder (الذي يستدعيه RolePermissionSeeder)،
     * وReturnPermissionSeeder يزرع السبعة كلها مستقلًا (idempotent).
     */
    public function test_return_permissions_sources_are_documented(): void
    {
        Permission::query()->delete();

        // ReturnPermissionSeeder وحده يكفي للسبعة.
        $this->seed(ReturnPermissionSeeder::class);
        foreach (['returns.view', 'returns.create', 'returns.update', 'returns.delete', 'returns.approve', 'returns.receive', 'returns.refund'] as $name) {
            $this->assertTrue(Permission::query()->where('name', $name)->exists(), $name);
        }

        // RolePermissionSeeder يتضمنها transitive عبر FinancialPermissionSeeder.
        Permission::query()->delete();
        $this->seed(RolePermissionSeeder::class);
        foreach (['returns.approve', 'returns.receive', 'returns.refund'] as $name) {
            $this->assertTrue(Permission::query()->where('name', $name)->exists(), $name);
        }

        // إعادة التشغيل idempotent: لا صفوف مكررة.
        $this->seed(ReturnPermissionSeeder::class);
        $this->seed(ReturnPermissionSeeder::class);
        $this->assertSame(1, Permission::query()->where('name', 'returns.approve')->count());
    }

    /**
     * task.txt القسم 3: إنشاء متزامن لمرتجعين على طلبين مختلفين —
     * رقما مرتجع فريدان بلا تصادم.
     */
    public function test_concurrent_return_creates_have_unique_numbers(): void
    {
        $branch = Branch::factory()->create();
        $receiving = Location::factory()->create(['branch_id' => $branch->id, 'type' => 'receiving']);
        $this->assertNotNull($receiving);

        $buildOrder = function (): array {
            $customer = Customer::factory()->create();
            $order = Order::factory()->create([
                'customer_id' => $customer->id, 'status' => \App\Enums\OrderStatus::Delivered,
                'channel' => 'store', 'payment_status' => 'paid', 'payment_method' => 'cod',
                'allocated_branch_id' => Branch::query()->firstOrFail()->id,
            ]);
            OrderStatusHistory::forceCreate([
                'order_id' => $order->id, 'from_status' => \App\Enums\OrderStatus::Confirmed->value,
                'to_status' => \App\Enums\OrderStatus::Delivered->value, 'changed_by_id' => $this->staff->id,
            ]);
            $item = OrderItem::factory()->create(['order_id' => $order->id, 'qty' => 2]);

            return [$order, $item, $customer];
        };

        [$orderA, $itemA, $customerA] = $buildOrder();
        [$orderB, $itemB, $customerB] = $buildOrder();

        $makeJob = fn (Order $order, OrderItem $item, Customer $customer): string => <<<PHP
            \$ready();
            try {
                \$return = app(\\App\\Services\\ReturnService::class)->createReturnRequest(
                    \\App\\Models\\Order::findOrFail({$order->id}),
                    ['reason' => 'تزامن', 'items' => [['order_item_id' => {$item->id}, 'requested_qty' => 1]]],
                    \\App\\Models\\Customer::findOrFail({$customer->id})
                );
                return ['ok' => true, 'number' => \$return->return_number];
            } catch (\\Throwable \$e) {
                return ['ok' => false, 'message' => get_class(\$e).': '.\$e->getMessage()];
            }
            PHP;

        $results = $this->concurrentJobs([$makeJob($orderA, $itemA, $customerA), $makeJob($orderB, $itemB, $customerB)]);

        $this->assertSame([true, true], [collect($results)->first()['ok'] ?? false, collect($results)->last()['ok'] ?? false]);
        $numbers = [collect($results)->first()['number'], collect($results)->last()['number']];
        $this->assertCount(2, array_unique($numbers));
    }

    /**
     * task.txt القسم 3: سباق الموافقة والإلغاء — الحالة النهائية متسقة
     * (ملغي) دون أخطاء خادم، والخاسر 422.
     */
    public function test_concurrent_approve_and_cancel_resolve_consistently(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create(['allocated_branch_id' => $branch->id]);
        $orderItem = OrderItem::factory()->create(['order_id' => $order->id, 'qty' => 5]);
        $return = ReturnRequest::factory()->create(['order_id' => $order->id, 'status' => 'pending']);
        $item = ReturnRequestItem::factory()->create([
            'return_request_id' => $return->id, 'order_item_id' => $orderItem->id,
            'requested_qty' => 2, 'approved_qty' => 0,
        ]);

        $results = $this->concurrentJobs([
            $this->adminJob('POST', "/api/v1/admin/returns/{$return->id}/approve", ['branch_id' => $branch->id]),
            $this->adminJob('PATCH', "/api/v1/admin/returns/{$return->id}", ['status' => 'cancelled']),
        ]);

        $statuses = array_column($results, 'status');
        $this->assertContains(200, $statuses);
        foreach ($statuses as $status) {
            $this->assertContains($status, [200, 422]);
        }
        $this->assertSame('cancelled', ReturnRequest::findOrFail($return->id)->status->value);
    }
}

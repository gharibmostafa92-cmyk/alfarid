<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Events\StockLowDetected;
use App\Events\TransferPendingApproval;
use App\Listeners\SendLowStockAlert;
use App\Listeners\SendTransferPendingApproval;
use App\Models\Branch;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Notifications\InventoryAnomalyDigestNotification;
use App\Notifications\LowStockAlertNotification;
use App\Notifications\TransferPendingApprovalNotification;
use App\Services\InventoryService;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * task.txt §21 (low-stock events)، §30 (anomaly detection)،
 * §33 (notification recipients + after-commit).
 */
class TaskTxtInventoryEventsTest extends TaskTxtInventoryCase
{
    private function enableProactiveEngine(): void
    {
        app(SettingService::class)->updateBatch(['ai_inventory_proactive_enabled' => true]);
    }

    private function decrease(string $token, Branch $branch, ProductVariant $variant, float $qty, ?int $locationId = null): void
    {
        $item = ['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => $qty];
        $payload = ['branch_id' => $branch->id, 'items' => [$item]];
        if ($locationId !== null) {
            $payload['location_id'] = $locationId;
        }
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', $payload)->assertCreated();
    }

    // ---------- §21a/b: crossing fires once; a second drop fires nothing ----------

    public function test_low_stock_crossing_dispatches_once(): void
    {
        Event::fake([StockLowDetected::class]);
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['reorder_level' => 5]);

        $this->seedStock($token, $branch, $variant, 6);
        Event::assertNotDispatched(StockLowDetected::class);

        $this->decrease($token, $branch, $variant, 2);
        Event::assertDispatched(StockLowDetected::class, function (StockLowDetected $event) use ($variant, $branch): bool {
            return $event->productVariantId === $variant->id
                && $event->branchId === $branch->id
                && abs($event->available - 4.0) < 0.0001
                && abs($event->reorderLevel - 5.0) < 0.0001;
        });

        // A further drop below the threshold is not a new crossing.
        $this->decrease($token, $branch, $variant, 1);
        Event::assertDispatchedTimes(StockLowDetected::class, 1);
    }

    // ---------- §21c: variant-level aggregate across locations ----------

    public function test_variant_level_threshold_uses_branch_total(): void
    {
        Event::fake([StockLowDetected::class]);
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['reorder_level' => 10]);
        $locA = Location::factory()->create(['branch_id' => $branch->id, 'code' => 'EV-A']);
        $locB = Location::factory()->create(['branch_id' => $branch->id, 'code' => 'EV-B']);

        $service = app(InventoryService::class);
        foreach ([$locA, $locB] as $loc) {
            $service->recordMovement([
                'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => $loc->id,
                'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 6, 'created_by' => $admin->id,
            ]);
        }
        Event::assertNotDispatched(StockLowDetected::class);

        // Branch total 12 → 8 crosses the variant threshold of 10.
        $this->decrease($token, $branch, $variant, 4, $locA->id);
        Event::assertDispatched(StockLowDetected::class, function (StockLowDetected $event) use ($branch): bool {
            return $event->branchId === $branch->id && abs($event->reorderLevel - 10.0) < 0.0001;
        });
    }

    // ---------- §21d: rolled-back postings dispatch nothing ----------

    public function test_rolled_back_movement_dispatches_no_event(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['reorder_level' => 5]);
        $this->seedStock($token, $branch, $variant, 6);

        Notification::fake();
        try {
            DB::transaction(function () use ($branch, $variant, $admin): void {
                app(InventoryService::class)->recordMovement([
                    'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                    'type' => 'adjustment_out', 'affected_bucket' => 'on_hand',
                    'quantity' => -2, 'created_by' => $admin->id,
                ]);
                throw new \RuntimeException('rollback the posting');
            });
            $this->fail('transaction must roll back');
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback the posting', $e->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertSame('6.0000', $this->level($variant->id, $branch->id)->on_hand);
    }

    // ---------- §21e + §33: throttle and recipients ----------

    public function test_low_stock_alert_throttled_and_scoped_to_branch(): void
    {
        Notification::fake();
        $branch = Branch::factory()->create(['is_active' => true]);
        $other = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['reorder_level' => 5]);

        $buyer = $this->staffWithPermissions(['purchases.view'], $branch->id);
        $manager = $this->staffWithPermissions(['inventory.view'], $branch->id);
        $outsider = $this->staffWithPermissions(['purchases.view'], $other->id);

        $listener = app(SendLowStockAlert::class);
        $event = new StockLowDetected($variant->id, $branch->id, 4.0, 5.0);

        $listener->handle($event);
        Notification::assertSentTo($buyer, LowStockAlertNotification::class);
        Notification::assertSentTo($manager, LowStockAlertNotification::class);
        Notification::assertNotSentTo($outsider, LowStockAlertNotification::class);

        // Oscillation within 6h stays silent.
        $listener->handle(new StockLowDetected($variant->id, $branch->id, 3.0, 5.0));
        Notification::assertSentToTimes($buyer, LowStockAlertNotification::class, 1);

        // After the window a new crossing alerts again.
        $this->travel(7)->hours();
        $listener->handle(new StockLowDetected($variant->id, $branch->id, 2.0, 5.0));
        Notification::assertSentToTimes($buyer, LowStockAlertNotification::class, 2);
    }

    // ---------- §33: transfer recipients + after-commit contracts ----------

    public function test_transfer_approval_notifies_both_branches_only(): void
    {
        Notification::fake();
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $third = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $service = app(InventoryService::class);
        $service->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $from->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 5, 'created_by' => $admin->id,
        ]);
        $transfer = app(\App\Services\TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ], $admin->id);

        $fromApprover = $this->staffWithPermissions(['transfers.approve'], $from->id);
        $toApprover = $this->staffWithPermissions(['transfers.approve'], $to->id);
        $stranger = $this->staffWithPermissions(['transfers.approve'], $third->id);

        app(SendTransferPendingApproval::class)->handle(new TransferPendingApproval($transfer));

        Notification::assertSentTo($fromApprover, TransferPendingApprovalNotification::class);
        Notification::assertSentTo($toApprover, TransferPendingApprovalNotification::class);
        Notification::assertNotSentTo($stranger, TransferPendingApprovalNotification::class);
    }

    public function test_domain_events_fire_after_commit_only(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(SendLowStockAlert::class));
        $this->assertContains(ShouldQueue::class, class_implements(SendTransferPendingApproval::class));

        foreach ([StockLowDetected::class, TransferPendingApproval::class] as $event) {
            $this->assertContains(
                \Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class,
                class_implements($event),
                "{$event} must defer to after commit"
            );
        }
    }

    // ---------- §30: anomaly detection ----------

    public function test_anomaly_detection_disabled_by_default(): void
    {
        Log::spy();
        Notification::fake();

        $this->artisan('inventory:detect-anomalies')
            ->assertSuccessful()
            ->expectsOutputToContain('Proactive inventory engine is disabled.');
        Notification::assertNothingSent();
        Log::shouldHaveReceived('info', ['inventory.anomalies.run', \Mockery::on(
            fn ($context) => is_array($context) && array_key_exists('entry_point', $context)
        )]);
    }

    public function test_anomaly_detection_flags_outlier_adjuster(): void
    {
        $this->enableProactiveEngine();
        Notification::fake();

        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $service = app(InventoryService::class);

        $makers = [];
        foreach ([5, 5, 20] as $count) {
            $user = $this->staffWithPermissions([], $branch->id);
            $makers[] = [$user, $count];
        }
        foreach ($makers as [$user, $count]) {
            $service->recordMovement([
                'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 100, 'created_by' => $user->id,
            ]);
            for ($i = 0; $i < $count; $i++) {
                $service->recordMovement([
                    'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                    'type' => 'adjustment_out', 'affected_bucket' => 'on_hand', 'quantity' => -1, 'created_by' => $user->id,
                ]);
            }
        }

        $viewer = $this->staffWithPermissions(['ai_agent.logs.view'], $branch->id);

        $this->artisan('inventory:detect-anomalies')
            ->assertSuccessful()
            ->expectsOutputToContain('Flagged');

        Notification::assertSentTo($viewer, InventoryAnomalyDigestNotification::class, function ($notification) use ($viewer): bool {
            $titles = array_column($notification->toArray($viewer)['notes'] ?? [], 'title');

            return in_array('معدل تسويات خصم مرتفع', $titles, true);
        });
    }

    public function test_anomaly_notes_are_scoped_to_each_branch(): void
    {
        $this->enableProactiveEngine();
        Notification::fake();

        $flagged = Branch::factory()->create(['is_active' => true]);
        $quiet = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $service = app(InventoryService::class);

        $heavy = $this->staffWithPermissions([], $flagged->id);
        $light1 = $this->staffWithPermissions([], $flagged->id);
        $light2 = $this->staffWithPermissions([], $flagged->id);
        $plan = [[$heavy, 20], [$light1, 5], [$light2, 5]];
        foreach ($plan as [$user, $count]) {
            $service->recordMovement([
                'product_variant_id' => $variant->id, 'branch_id' => $flagged->id,
                'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 100, 'created_by' => $user->id,
            ]);
            for ($i = 0; $i < $count; $i++) {
                $service->recordMovement([
                    'product_variant_id' => $variant->id, 'branch_id' => $flagged->id,
                    'type' => 'adjustment_out', 'affected_bucket' => 'on_hand', 'quantity' => -1, 'created_by' => $user->id,
                ]);
            }
        }

        $flaggedViewer = $this->staffWithPermissions(['ai_agent.logs.view'], $flagged->id);
        $quietViewer = $this->staffWithPermissions(['ai_agent.logs.view'], $quiet->id);
        $central = $this->staffWithPermissions(['ai_agent.logs.view', 'inventory.all_branches'], $quiet->id);

        $this->artisan('inventory:detect-anomalies')->assertSuccessful();

        Notification::assertSentTo($flaggedViewer, InventoryAnomalyDigestNotification::class);
        Notification::assertNotSentTo($quietViewer, InventoryAnomalyDigestNotification::class);
        Notification::assertSentTo($central, InventoryAnomalyDigestNotification::class);
    }

    public function test_stopped_branch_holder_never_gets_the_global_digest(): void
    {
        $this->enableProactiveEngine();
        Notification::fake();

        $flagged = Branch::factory()->create(['is_active' => true]);
        $stopped = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $service = app(InventoryService::class);

        $heavy = $this->staffWithPermissions([], $flagged->id);
        $light1 = $this->staffWithPermissions([], $flagged->id);
        $light2 = $this->staffWithPermissions([], $flagged->id);
        foreach ([[$heavy, 20], [$light1, 5], [$light2, 5]] as [$user, $count]) {
            $service->recordMovement([
                'product_variant_id' => $variant->id, 'branch_id' => $flagged->id,
                'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 100, 'created_by' => $user->id,
            ]);
            for ($i = 0; $i < $count; $i++) {
                $service->recordMovement([
                    'product_variant_id' => $variant->id, 'branch_id' => $flagged->id,
                    'type' => 'adjustment_out', 'affected_bucket' => 'on_hand', 'quantity' => -1, 'created_by' => $user->id,
                ]);
            }
        }

        $stoppedViewer = $this->staffWithPermissions(['ai_agent.logs.view'], $stopped->id);
        $stopped->update(['is_active' => false]);

        $this->artisan('inventory:detect-anomalies')->assertSuccessful();

        // Their branch has no notes, so a scoped routing sends them nothing —
        // and the global digest about other branches must never reach them.
        Notification::assertNotSentTo($stoppedViewer, InventoryAnomalyDigestNotification::class);
    }
}

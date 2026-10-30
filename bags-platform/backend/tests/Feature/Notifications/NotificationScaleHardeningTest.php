<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\OrderCreated;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use App\Listeners\SendStaffOrderNotification;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Notifications\StaffRecipients;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NotificationScaleHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_retention_removes_old_unread_notifications_across_batches(): void
    {
        $user = User::factory()->create();
        $old = [];
        for ($i = 0; $i < 1001; $i++) {
            $old[] = $this->row($user, now()->subDays(400));
        }
        foreach (array_chunk($old, 250) as $batch) {
            DB::table('notifications')->insert($batch);
        }
        DB::table('notifications')->insert([
            $this->row($user, now()->subDays(100), now()),
            $keptUnread = $this->row($user, now()->subDays(100)),
            $keptRead = $this->row($user, now()->subDays(10), now()),
        ]);

        $this->artisan('notifications:prune-read')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', ['id' => $keptUnread['id']]);
        $this->assertDatabaseHas('notifications', ['id' => $keptRead['id']]);
    }

    public function test_mark_all_reads_multiple_batches_and_only_the_current_recipient(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = $this->row($user, now()->subDay());
        }
        foreach (array_chunk($rows, 250) as $batch) {
            DB::table('notifications')->insert($batch);
        }
        DB::table('notifications')->insert($this->row($other, now()->subDay()));
        $request = Request::create('/notifications/read-all', 'POST');
        $request->setUserResolver(fn () => $user);

        $response = app(NotificationController::class)->readAll($request);

        $this->assertSame(0, $response->getData(true)['meta']['unread_count']);
        $this->assertSame(1001, $user->readNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_branch_notifications_exclude_other_branches_and_unassigned_staff(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $branch = Branch::factory()->create();
        $own = User::factory()->create(['branch_id' => $branch->id]);
        $other = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $unassigned = User::factory()->create(['branch_id' => null]);
        $central = User::factory()->create(['branch_id' => null]);
        foreach ([$own, $other, $unassigned, $central] as $user) {
            $user->givePermissionTo('orders.view');
        }
        $central->givePermissionTo('inventory.all_branches');

        $ids = StaffRecipients::withAnyPermission(['orders.view'], [$branch->id])->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$own->id, $central->id], $ids);
        $this->assertSame([$central->id], StaffRecipients::withAnyPermission(['orders.view'], [])->pluck('id')->all());
    }

    public function test_fanout_runs_in_one_job_and_rolls_back_if_a_recipient_insert_fails(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $branch = Branch::factory()->create();
        $users = User::factory()->count(2)->create(['branch_id' => $branch->id]);
        foreach ($users as $user) {
            $user->givePermissionTo('orders.view');
        }
        $order = Order::factory()->create(['allocated_branch_id' => $branch->id]);
        Queue::fake();
        $created = 0;
        DatabaseNotification::creating(function () use (&$created): void {
            if (++$created === 2) {
                throw new RuntimeException('second recipient failed');
            }
        });

        try {
            app(SendStaffOrderNotification::class)->handle(new OrderCreated($order));
            $this->fail('Expected the recipient failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('second recipient failed', $exception->getMessage());
        }

        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    private function row(User $user, mixed $createdAt, mixed $readAt = null): array
    {
        return ['id' => (string) Str::uuid(), 'type' => 'regression',
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => '{}', 'read_at' => $readAt, 'created_at' => $createdAt, 'updated_at' => $createdAt];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\OrderPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * M6: عدّادات الطلبات باستعلام واحد + تعليم كل الإشعارات كمقروءة بتحديث واحد.
 */
class OrderStatsNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, OrderPermissionSeeder::class]);
        $this->staff = User::factory()->create();
        $role = \Spatie\Permission\Models\Role::findOrCreate(\App\Enums\StaffRole::SuperAdmin->value, 'staff');
        $this->staff->assignRole($role);
        $this->token = $this->staff->createToken('audit')->plainTextToken;
    }

    public function test_orders_stats_returns_single_query_counters(): void
    {
        Order::factory()->create(['status' => 'pending']);
        Order::factory()->create(['status' => 'processing', 'needs_attention' => true]);
        Order::factory()->create(['status' => 'processing', 'requires_refund' => true]);

        $res = $this->withToken($this->token)->getJson('/api/v1/admin/orders/stats');
        $res->assertOk();
        $res->assertJsonPath('data.pending', 1);
        $res->assertJsonPath('data.processing', 2);
        $res->assertJsonPath('data.needs_attention', 1);
        $res->assertJsonPath('data.requires_refund', 1);
    }

    public function test_notifications_read_all_marks_everything_in_one_call(): void
    {
        $this->staff->notify(new \App\Notifications\LowStockAlertNotification(1, 'SKU-1', 1, 0, 5));
        $this->staff->notify(new \App\Notifications\LowStockAlertNotification(2, 'SKU-2', 1, 0, 5));
        $this->assertSame(2, $this->staff->unreadNotifications()->count());

        $res = $this->withToken($this->token)->postJson('/api/v1/admin/notifications/read-all');
        $res->assertOk()->assertJsonPath('meta.unread_count', 0);
        $this->assertSame(0, $this->staff->fresh()->unreadNotifications()->count());
    }
}

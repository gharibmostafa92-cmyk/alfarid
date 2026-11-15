<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Events\Inventory\StockLowDetected;
use App\Listeners\Inventory\SuggestTransferRedistribution;
use App\Models\Branch;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\Inventory\TransferSuggestionNotification;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task4HardeningSlice2Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_medium_02_suggest_transfer_ignores_inactive_branches_and_locations_and_deduplicates(): void
    {
        $this->seed(SettingSeeder::class);
        DB::table('settings')->where('key', 'ai_inventory_proactive_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();

        $low = Branch::factory()->create(['is_active' => true]);
        $inactiveDonor = Branch::factory()->create(['is_active' => false]);
        $activeDonor = Branch::factory()->create(['is_active' => true]);
        $inactiveLoc = Location::create([
            'branch_id' => $activeDonor->id,
            'name' => 'Quarantine',
            'code' => 'QUAR',
            'is_active' => false,
        ]);

        $manager = User::create([
            'name' => 'Transfer Manager',
            'email' => 'xfer.mgr@example.com',
            'phone' => '+201099887711',
            'password' => Hash::make('secret123'),
            'branch_id' => $low->id,
            'is_active' => true,
        ]);
        $manager->givePermissionTo(Permission::findOrCreate('transfers.create', 'staff'));

        $variant = ProductVariant::factory()->create(['is_active' => true, 'sku' => 'SKU-M02']);

        // Only inactive branch and inactive location have stock -> should NOT suggest any transfer.
        DB::table('stock_levels')->insert([
            ['product_variant_id' => $variant->id, 'branch_id' => $low->id, 'location_id' => null, 'on_hand' => 2, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['product_variant_id' => $variant->id, 'branch_id' => $inactiveDonor->id, 'location_id' => null, 'on_hand' => 500, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['product_variant_id' => $variant->id, 'branch_id' => $activeDonor->id, 'location_id' => $inactiveLoc->id, 'on_hand' => 500, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        for ($d = 1; $d <= 10; $d++) {
            DB::table('stock_movements')->insert([
                'reference_number' => 'SM-M02-'.$d,
                'product_variant_id' => $variant->id,
                'branch_id' => $low->id,
                'type' => StockMovementType::Sale->value,
                'affected_bucket' => StockBucket::OnHand->value,
                'quantity' => -3,
                'balance_before' => 100,
                'balance_after' => 97,
                'created_by' => $manager->id,
                'created_at' => now()->subDays($d),
            ]);
        }

        app(SuggestTransferRedistribution::class)->handle(
            new StockLowDetected($variant->id, $low->id, 2.0, 10.0)
        );
        $this->assertSame(0, DatabaseNotification::where('type', TransferSuggestionNotification::class)->count());

        // Now add stock in an active location of activeDonor, and trigger twice -> only 1 notification due to cooldown.
        $activeLoc = Location::create([
            'branch_id' => $activeDonor->id,
            'name' => 'Main Shelf',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id,
            'branch_id' => $activeDonor->id,
            'location_id' => $activeLoc->id,
            'on_hand' => 200,
            'reserved' => 0,
            'damaged' => 0,
            'in_transit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SuggestTransferRedistribution::class)->handle(
            new StockLowDetected($variant->id, $low->id, 2.0, 10.0)
        );
        app(SuggestTransferRedistribution::class)->handle(
            new StockLowDetected($variant->id, $low->id, 1.0, 10.0)
        );

        $this->assertSame(1, DatabaseNotification::where('type', TransferSuggestionNotification::class)->count());
    }

    public function test_medium_03_and_info_01_postgres_index_and_jobs_autovacuum(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only index and reloptions verification.');
        }

        $dupIndex = DB::scalar(
            "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'finance_reconcile_runs_created_at_idx'"
        );
        $this->assertNull($dupIndex, 'Duplicate index finance_reconcile_runs_created_at_idx must be dropped.');

        $expiryDef = DB::scalar(
            "SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'orders_pending_expiry_idx'"
        );
        $this->assertNotNull($expiryDef);
        $this->assertStringContainsString('(created_at, id)', (string) $expiryDef);

        $relOptions = DB::scalar(
            "SELECT array_to_string(c.reloptions, ',') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = 'jobs'"
        );
        $this->assertStringContainsString('autovacuum_vacuum_scale_factor=0.01', (string) $relOptions);
        $this->assertStringContainsString('autovacuum_vacuum_cost_limit=1000', (string) $relOptions);
    }
}

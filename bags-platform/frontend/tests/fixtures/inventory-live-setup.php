<?php

// Run only against the disposable audit database, never the developer database.
$database = getenv('DB_DATABASE');
if (!is_string($database) || !preg_match('/^alfarid_inventory_audit_[0-9]+$/', $database)) {
    throw new RuntimeException('An isolated inventory audit database is required.');
}
$root = realpath(__DIR__.'/../../../backend');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.pgsql.database' => $database, 'database.connections.pgsql.url' => null]);
Illuminate\Support\Facades\DB::purge('pgsql');
Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Database\Seeders\DatabaseSeeder::class, '--force' => true]);
$source = App\Models\Branch::factory()->create(['name' => 'مخزن اختبار التكامل', 'is_active' => true]);
$destination = App\Models\Branch::factory()->create(['name' => 'فرع اختبار التكامل', 'is_active' => true]);
$location = App\Models\Location::factory()->create(['branch_id' => $source->id, 'is_active' => true]);
$variant = App\Models\ProductVariant::factory()->create(['sku' => 'AUDIT-LIVE-001', 'is_active' => true]);
$admin = App\Models\User::factory()->create(['name' => 'مدير اختبار المخزون', 'branch_id' => null]);
$admin->assignRole(Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'staff'));
$keeper = App\Models\User::factory()->create(['name' => 'أمين مخزن الاختبار', 'branch_id' => $source->id]);
$keeper->assignRole(Spatie\Permission\Models\Role::findOrCreate('storekeeper', 'staff'));
$keeper->givePermissionTo(['inventory.view', 'inventory.adjust', 'branches.view', 'locations.view', 'products.view_cost', 'stocktakes.view', 'transfers.view']);
$output = ['source' => $source->id, 'destination' => $destination->id, 'location' => $location->id, 'variant' => $variant->id,
    'adminToken' => $admin->createToken('inventory-audit')->plainTextToken,
    'keeperToken' => $keeper->createToken('inventory-audit')->plainTextToken];
file_put_contents($root.'/storage/logs/inventory-live-session.json', json_encode($output, JSON_THROW_ON_ERROR));
echo "Seeded isolated inventory audit accounts and fixtures.\n";

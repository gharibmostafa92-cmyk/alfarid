<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(SettingSeeder::class);
        $this->call(BranchSeeder::class);
        $this->call(LocationSeeder::class);
        $this->call(RolePermissionSeeder::class);
        $this->call(CatalogPermissionSeeder::class);
        $this->call(InventoryPermissionSeeder::class);
        $this->call(PricingPermissionSeeder::class);
        $this->call(CustomerPermissionSeeder::class);
        $this->call(OrderPermissionSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $testUser = User::factory()->make([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

            User::query()->firstOrCreate(
                ['email' => 'test@example.com'],
                $testUser->getAttributes(),
            );

            // بيانات الكتالوج التجريبية للبيئات المحلية والاختبار بس
            $this->call(CatalogSeeder::class);
        }
    }
}

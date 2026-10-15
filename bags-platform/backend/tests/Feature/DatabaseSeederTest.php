<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_database_seeder_is_idempotent_in_testing_environment(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    public function test_database_seeder_does_not_create_test_account_in_production_environment(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_setting_seeder_fills_missing_settings_without_overwriting_custom_values(): void
    {
        $this->seed(SettingSeeder::class);
        Setting::query()->where('key', 'company_name')->update(['value' => 'Custom Company']);
        Setting::query()->where('key', 'tax_enabled')->delete();

        $this->seed(SettingSeeder::class);

        $this->assertDatabaseHas('settings', ['key' => 'company_name', 'value' => 'Custom Company']);
        $this->assertDatabaseHas('settings', ['key' => 'tax_enabled', 'value' => '0']);
    }

    public function test_location_seeder_fills_missing_shelves_for_every_branch_without_overwriting_existing_locations(): void
    {
        $firstBranch = Branch::factory()->create();
        $secondBranch = Branch::factory()->create();
        $customLocation = $firstBranch->locations()->create([
            'name' => 'Custom shelf name',
            'code' => 'A-01-01',
            'type' => 'shelf',
        ]);

        $this->seed(LocationSeeder::class);

        $this->assertDatabaseCount('locations', 6);
        $this->assertDatabaseHas('locations', [
            'id' => $customLocation->id,
            'name' => 'Custom shelf name',
        ]);

        foreach ([$firstBranch, $secondBranch] as $branch) {
            $this->assertSame(3, $branch->locations()->count());
        }
    }
}

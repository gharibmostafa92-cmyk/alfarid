<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || Branch::query()->exists()) {
            return;
        }

        Branch::factory()->count(3)->create();
    }
}

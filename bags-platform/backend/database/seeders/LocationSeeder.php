<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $now = now();
        $rows = [];

        Branch::query()->orderBy('id')->get(['id'])->each(function (Branch $branch) use (&$rows, $now): void {
            foreach ([1, 2, 3] as $number) {
                $rows[] = [
                    'branch_id' => $branch->id,
                    'name' => "Shelf {$number}",
                    'code' => sprintf('A-01-%02d', $number),
                    'type' => LocationType::Shelf->value,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        });

        if ($rows !== []) {
            Location::query()->insertOrIgnore($rows);
        }
    }
}

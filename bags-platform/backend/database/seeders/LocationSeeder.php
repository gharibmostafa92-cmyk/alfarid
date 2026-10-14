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
        if (! app()->environment(['local', 'testing']) || Location::query()->exists()) {
            return;
        }

        Branch::query()->orderBy('id')->each(function (Branch $branch): void {
            foreach ([1, 2, 3] as $number) {
                $branch->locations()->create([
                    'name' => "Shelf {$number}",
                    'code' => sprintf('A-01-%02d', $number),
                    'type' => LocationType::Shelf,
                    'is_active' => true,
                ]);
            }
        });
    }
}

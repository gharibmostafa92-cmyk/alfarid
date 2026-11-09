<?php

declare(strict_types=1);

// Backwards-compatibility aliases for actions organized into domain subfolders.
$aliases = [
    // Inventory
    \App\Actions\Inventory\CreateBranch::class => 'App\Actions\Branches\CreateBranch',
    \App\Actions\Inventory\DeleteBranch::class => 'App\Actions\Branches\DeleteBranch',
    \App\Actions\Inventory\UpdateBranch::class => 'App\Actions\Branches\UpdateBranch',
    \App\Actions\Inventory\SetLocationStatus::class => 'App\Actions\Locations\SetLocationStatus',
];

foreach ($aliases as $original => $alias) {
    if (! class_exists($alias, false)) {
        class_alias($original, $alias);
    }
}

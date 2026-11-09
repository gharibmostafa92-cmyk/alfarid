<?php

declare(strict_types=1);

// Backwards-compatibility aliases for HTTP resources organized into domain subfolders.
$aliases = [
    // Auth / Staff resources
    \App\Http\Resources\Admin\Auth\UserResource::class => 'App\Http\Resources\Api\V1\Admin\UserResource',
    \App\Http\Resources\Admin\Auth\RoleResource::class => 'App\Http\Resources\Api\V1\Admin\RoleResource',

    // Inventory resources
    \App\Http\Resources\Admin\Inventory\BranchResource::class => 'App\Http\Resources\Api\V1\Admin\BranchResource',
    \App\Http\Resources\Admin\Inventory\LocationResource::class => 'App\Http\Resources\Api\V1\Admin\LocationResource',

    // Supplier portal resources
    \App\Http\Resources\Supplier\SupplierUserResource::class => 'App\Http\Resources\Api\V1\Supplier\SupplierUserResource',
];

foreach ($aliases as $original => $alias) {
    if (! class_exists($alias, false)) {
        class_alias($original, $alias);
    }
}

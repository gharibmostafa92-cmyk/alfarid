<?php

declare(strict_types=1);

namespace App\Actions\Branches;

use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Facades\DB;

final class CreateBranch
{
    public function __construct(private BranchAccess $access) {}

    /** @param array<string,mixed> $data @param array<string,mixed> $via */
    public function __invoke(User $actor, array $data, array $via = []): Branch
    {
        $this->access->assertCentralAccess($actor);

        return DB::transaction(function () use ($actor, $data, $via): Branch {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['branches.create', 'branches.manage']);
            $this->access->assertCentralAccess($actor);
            $branch = Branch::query()->create($data);
            StaffAdministration::audit($actor, 'branch.created', 'branch', $branch->id, array_keys($data), [], StaffAdministration::branchState($branch), $via);

            return $branch;
        });
    }
}

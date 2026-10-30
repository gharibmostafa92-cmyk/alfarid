<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Models\Location;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Facades\DB;

final class SetLocationStatus
{
    public function __construct(private BranchAccess $access) {}

    /** @param array<string,mixed> $via */
    public function __invoke(User $actor, Location $location, bool $active, array $via = []): Location
    {
        return DB::transaction(function () use ($actor, $location, $active, $via): Location {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['locations.update', 'locations.manage']);
            $locked = Location::query()->lockForUpdate()->findOrFail($location->id);
            $this->access->assertBranch($actor->id, $locked->branch_id);
            $before = ['is_active' => (bool) $locked->is_active];
            if (! $active && ! (bool) $locked->is_active) {
                return $locked;
            }
            if (! $active) {
                $hasBalances = StockLevel::query()->where('location_id', $locked->id)->hasNonZeroBalance()->exists();
                abort_unless(! $hasBalances, 422, 'لا يمكن تعطيل الموقع وهو عليه أرصدة غير صفرية.');
            }
            $locked->update(['is_active' => $active]);
            StaffAdministration::audit($actor, $active ? 'location.activated' : 'location.deactivated', 'location', $locked->id,
                ['is_active'], $before, ['is_active' => $active], $via);

            return $locked;
        });
    }
}

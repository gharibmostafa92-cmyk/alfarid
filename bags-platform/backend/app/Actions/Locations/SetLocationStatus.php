<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Enums\Inventory\StocktakeStatus;
use App\Enums\Inventory\TransferStatus;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockTransferItem;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Auth\StaffAdministration;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates an inventory location, enforcing that occupied locations cannot be deactivated.
 */
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
            if ($active && ! (bool) \App\Models\Branch::query()->find($locked->branch_id)?->is_active) {
                abort(422, 'لا يمكن تفعيل موقع داخل فرع متوقف.');
            }
            if (! $active) {
                self::assertDeactivatable($locked);
            }
            $locked->update(['is_active' => $active]);
            StaffAdministration::audit($actor, $active ? 'location.activated' : 'location.deactivated', 'location', $locked->id,
                ['is_active'], $before, ['is_active' => $active], $via);

            return $locked;
        });
    }

    /**
     * A location may only be disabled when it has no balances and no open transfer or stocktake references it,
     * otherwise those documents could never be shipped, received or approved.
     */
    public static function assertDeactivatable(Location $locked): void
    {
        $hasBalances = StockLevel::query()->where('location_id', $locked->id)->hasNonZeroBalance()->exists();
        abort_unless(! $hasBalances, 422, 'لا يمكن تعطيل الموقع وهو عليه أرصدة غير صفرية.');

        $openTransfer = StockTransferItem::query()
            ->where(fn ($q) => $q->where('from_location_id', $locked->id)->orWhere('to_location_id', $locked->id))
            ->whereHas('transfer', fn ($q) => $q->whereIn('status', [
                TransferStatus::Draft->value, TransferStatus::Approved->value, TransferStatus::Shipped->value,
            ]))
            ->exists();
        abort_if($openTransfer, 422, 'لا يمكن تعطيل الموقع وهو مرتبط بتحويلات مفتوحة؛ أكملها أو ألغها أولًا.');

        $openStocktake = Stocktake::query()->where('location_id', $locked->id)
            ->whereIn('status', [StocktakeStatus::Draft->value, StocktakeStatus::InProgress->value])
            ->exists();
        abort_if($openStocktake, 422, 'لا يمكن تعطيل الموقع وهو مرتبط بجلسة جرد مفتوحة؛ اعتمدها أو ألغها أولًا.');
    }
}

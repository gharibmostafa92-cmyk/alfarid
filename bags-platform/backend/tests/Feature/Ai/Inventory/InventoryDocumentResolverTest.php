<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Inventory;

use App\Models\Branch;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Ai\EntityResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryDocumentResolverTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_egyptian_document_descriptions_resolve_direction_date_and_branch_scope(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        $source = Branch::factory()->create(['name' => 'المخزن الرئيسي']);
        $destination = Branch::factory()->create(['name' => 'مدينة نصر']);
        $other = Branch::factory()->create();
        $ids = [];
        foreach ([[$source, $destination, now()->subDay()], [$destination, $source, now()->subDay()], [$source, $destination, now()]] as $i => [$from, $to, $date]) {
            $transfer = StockTransfer::create(['transfer_number' => 'TRF-RESOLVE-'.$i, 'from_branch_id' => $from->id,
                'to_branch_id' => $to->id, 'status' => 'draft', 'created_by' => $actor->id]);
            $transfer->forceFill(['created_at' => $date])->save();
            $ids[] = $transfer->id;
        }
        $resolver = app(EntityResolver::class);
        $query = 'التحويل اللي من المخزن لفرع مدينة نصر امبارح';
        $this->assertSame([$ids[0]], array_column($resolver->transfers($actor, $query), 'id'));

        $countIds = [];
        foreach ([[$destination, now()->subDay()], [$source, now()->subDay()], [$destination, now()]] as $i => [$branch, $date]) {
            $count = Stocktake::create(['stocktake_number' => 'STK-RESOLVE-'.$i, 'branch_id' => $branch->id, 'status' => 'draft', 'created_by' => $actor->id]);
            $count->forceFill(['created_at' => $date])->save();
            $countIds[] = $count->id;
        }
        $this->assertSame([$countIds[0]], array_column($resolver->stocktakes($actor, 'جرد فرع مدينة نصر امبارح'), 'id'));
        $outsider = User::factory()->create(['branch_id' => $other->id]);
        $this->assertSame([], $resolver->transfers($outsider, $query));
    }
}

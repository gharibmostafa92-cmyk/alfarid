<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The database itself rejects negative transfer quantities and
 * duplicate (transfer, variant, locations) lines.
 */
class TransferItemsDbGuardsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    private function line(int $transferId, int $variantId): array
    {
        return [
            'stock_transfer_id' => $transferId, 'product_variant_id' => $variantId,
            'from_location_id' => null, 'to_location_id' => null,
            'requested_qty' => 1, 'approved_qty' => 0, 'shipped_qty' => 0, 'received_qty' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    public function test_database_rejects_negative_quantities_and_duplicate_lines(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $transfer = StockTransfer::create([
            'transfer_number' => 'TR-M8', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'status' => 'draft', 'created_by' => User::factory()->create()->id,
        ]);

        \DB::table('stock_transfer_items')->insert($this->line($transfer->id, $variant->id));

        // Each violating insert runs in a savepoint: PostgreSQL aborts the
        // whole transaction on constraint violation.
        $attempt = function (array $row): bool {
            try {
                \DB::transaction(fn () => \DB::table('stock_transfer_items')->insert($row), 1);

                return true;
            } catch (QueryException) {
                return false;
            }
        };

        $this->assertFalse($attempt($this->line($transfer->id, $variant->id)), 'duplicate line accepted');

        foreach (['requested_qty', 'approved_qty', 'shipped_qty', 'received_qty'] as $column) {
            $row = $this->line($transfer->id, ProductVariant::factory()->create()->id);
            $row[$column] = -1;
            $this->assertFalse($attempt($row), "negative {$column} accepted");
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Models\User;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Task6 Lane A1: single DOCUMENT_NUMBER_TARGETS map (F1 remainder + F19).
 *
 * - Exactly one numbering-collision map named DOCUMENT_NUMBER_TARGETS;
 *   the duplicate NUMBERING_TARGETS const must be gone.
 * - `invoice` (no invoices table in schema: only supplier_invoices exists)
 *   and `pos_invoice` (sequence-only, see migration
 *   2026_10_31_000001_move_high_volume_numbering_to_sequences which maps
 *   pos_invoice to table null) stay excluded.
 * - updateBatch() accepts an explicit ?User $actor (4th param, default null)
 *   for CLI/queue/WhatsApp contexts (F19).
 */
class Task6LaneASettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_single_document_number_map_without_duplicate_const(): void
    {
        $ref = new ReflectionClass(SettingService::class);

        $this->assertFalse($ref->hasConstant('NUMBERING_TARGETS'), 'Duplicate NUMBERING_TARGETS const must be merged away.');
        $this->assertTrue($ref->hasConstant('DOCUMENT_NUMBER_TARGETS'));
    }

    public function test_tax_and_pos_invoices_stay_excluded_with_reason(): void
    {
        $map = (new ReflectionClass(SettingService::class))->getConstant('DOCUMENT_NUMBER_TARGETS');

        $this->assertIsArray($map);
        $this->assertArrayNotHasKey('invoice', $map, 'No invoices table exists in schema (only supplier_invoices).');
        $this->assertArrayNotHasKey('pos_invoice', $map, 'pos_invoice is sequence-only (table null in numbering migration).');
        // The merged map must still cover the real tables.
        foreach (['order', 'stocktake', 'return', 'treasury_transfer', 'movement'] as $type) {
            $this->assertArrayHasKey($type, $map);
        }
    }

    public function test_update_batch_accepts_explicit_actor(): void
    {
        $params = (new ReflectionMethod(SettingService::class, 'updateBatch'))->getParameters();

        $this->assertGreaterThanOrEqual(4, count($params), 'updateBatch must accept a 4th $actor param.');
        $actor = $params[3];
        $this->assertSame('actor', $actor->getName());
        $this->assertTrue($actor->isDefaultValueAvailable());
        $this->assertNull($actor->getDefaultValue());
        $type = $actor->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(User::class, $type->getName());
        $this->assertTrue($type->allowsNull());
    }

    public function test_update_batch_with_explicit_actor_persists(): void
    {
        $this->seed(SettingSeeder::class);
        $actor = User::factory()->create();

        app(SettingService::class)->updateBatch(['company_name' => 'Actor company'], null, [], $actor);

        $this->assertDatabaseHas('settings', ['key' => 'company_name', 'value' => 'Actor company']);
    }
}

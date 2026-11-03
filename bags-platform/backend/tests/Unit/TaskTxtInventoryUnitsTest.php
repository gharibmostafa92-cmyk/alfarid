<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\ProductionReceipt;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\DiscreteQuantity;
use App\Services\IdempotencyFingerprint;
use App\Services\SearchTerm;
use App\Services\SpokenQuantity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * task.txt §22 (immutability)، §24 (Fingerprint)، §25 (SpokenQuantity)
 * + BranchAccess / CostAccess / SearchTerm / DiscreteQuantity.
 */
class TaskTxtInventoryUnitsTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ---------- §22: ProductionReceipt immutability ----------

    public function test_production_receipt_is_immutable(): void
    {
        $receipt = ProductionReceipt::create([
            'batch_number' => 'IMM-1',
            'branch_id' => Branch::factory()->create()->id,
            'created_by' => User::factory()->create()->id,
            'idempotency_key' => 'imm-key-1',
            'request_hash' => 'imm-hash-1',
            'items' => [],
        ]);

        try {
            $receipt->update(['notes' => 'x']);
            $this->fail('receipt update must be blocked');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $receipt->delete();
    }

    // ---------- §24: Fingerprint ----------

    public function test_fingerprint_ignores_key_and_item_order(): void
    {
        $a = ['branch_id' => 3, 'items' => [
            ['product_variant_id' => 7, 'quantity' => 5],
            ['product_variant_id' => 9, 'quantity' => 2],
        ]];
        $b = ['items' => [
            ['quantity' => 2, 'product_variant_id' => 9],
            ['quantity' => 5, 'product_variant_id' => 7],
        ], 'branch_id' => 3];

        $this->assertSame(IdempotencyFingerprint::hash($a), IdempotencyFingerprint::hash($b));
    }

    public function test_fingerprint_treats_string_and_float_quantities_as_same(): void
    {
        $base = ['branch_id' => 1, 'items' => [['product_variant_id' => 4, 'quantity' => 5]]];
        $asString = ['branch_id' => '1', 'items' => [['product_variant_id' => '4', 'quantity' => '5']]];
        $asFloat = ['branch_id' => 1.0, 'items' => [['product_variant_id' => 4, 'quantity' => 5.0]]];

        // NB: branch_id "1" is coerced (ends with _id); 1.0 float is integral → int.
        $this->assertSame(IdempotencyFingerprint::hash($base), IdempotencyFingerprint::hash($asString));
        $this->assertSame(IdempotencyFingerprint::hash($base), IdempotencyFingerprint::hash($asFloat));
    }

    public function test_fingerprint_distinguishes_unit_cost_digits(): void
    {
        $a = ['items' => [['product_variant_id' => 1, 'quantity' => 1, 'unit_cost' => '10.1234']]];
        $b = ['items' => [['product_variant_id' => 1, 'quantity' => 1, 'unit_cost' => '10.1299']]];

        $this->assertNotSame(IdempotencyFingerprint::hash($a), IdempotencyFingerprint::hash($b));
    }

    public function test_fingerprint_normalizes_decimal_representations(): void
    {
        $variants = [
            ['items' => [['product_variant_id' => 1, 'quantity' => 1, 'unit_cost' => '0.50']]],
            ['items' => [['product_variant_id' => 1, 'quantity' => 1, 'unit_cost' => '.5']]],
            ['items' => [['product_variant_id' => 1, 'quantity' => 1, 'unit_cost' => '5e-1']]],
        ];

        $hashes = array_map([IdempotencyFingerprint::class, 'hash'], $variants);
        $this->assertSame([$hashes[0], $hashes[0]], [$hashes[1], $hashes[2]]);
    }

    public function test_fingerprint_rejects_non_finite_values(): void
    {
        $this->expectException(BusinessException::class);
        IdempotencyFingerprint::hash(['quantity' => INF]);
    }

    public function test_fingerprint_rejects_nan_values(): void
    {
        $this->expectException(BusinessException::class);
        IdempotencyFingerprint::hash(['quantity' => NAN]);
    }

    public function test_fingerprint_handles_huge_exponents_without_memory_blowup(): void
    {
        $start = memory_get_usage(true);
        $hash = IdempotencyFingerprint::hash(['items' => [['product_variant_id' => 1, 'quantity' => 1, 'unit_cost' => '1e999999']]]);
        $this->assertSame(64, strlen($hash));
        // Symbolic exponent must stay compact: well under 1MB growth.
        $this->assertLessThan(1024 * 1024, memory_get_usage(true) - $start);
    }

    // ---------- §25: SpokenQuantity ----------

    #[\PHPUnit\Framework\Attributes\DataProvider('spokenArabicProvider')]
    public function test_spoken_arabic_words_parse_to_numbers(string $words, float $expected): void
    {
        $this->assertSame($expected, SpokenQuantity::parse($words));
    }

    public static function spokenArabicProvider(): array
    {
        return [
            'task case 22' => ['اتنين وعشرين', 22.0],
            'task case 150' => ['ميه وخمسين', 150.0],
            'task case 250' => ['ميتين وخمسين', 250.0],
            'task case 3000' => ['تلاته الف', 3000.0],
            'arabic-indic digits' => ['٢٢', 22.0],
            'plain integer' => ['7', 7.0],
            'dozen' => ['دسته', 12.0],
            'with filler' => ['خمسه قطع', 5.0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('spokenNullProvider')]
    public function test_spoken_unparsable_returns_null(?string $words): void
    {
        $this->assertNull(SpokenQuantity::parse($words));
    }

    public static function spokenNullProvider(): array
    {
        return [
            'decimal' => ['2.5'],
            'gibberish' => ['كلام'],
            'empty' => [''],
            'null' => [null],
        ];
    }

    // ---------- SearchTerm ----------

    public function test_search_term_escapes_like_wildcards(): void
    {
        $this->assertSame('a\\%b\\_c\\\\', SearchTerm::escape('a%b_c\\'));
        $this->assertSame('%a\\%b\\_c\\\\%', SearchTerm::pattern('a%b_c\\'));
        $this->assertSame('a\\%b\\_c\\\\%', SearchTerm::prefix('a%b_c\\'));
    }

    // ---------- DiscreteQuantity ----------

    public function test_discrete_quantity_boundaries(): void
    {
        DiscreteQuantity::assert(1);
        DiscreteQuantity::assert(99999999.9999);
        DiscreteQuantity::assert(0, allowZero: true);

        foreach ([0, -1, 0.00001, 100000000, 'abc', INF] as $bad) {
            try {
                DiscreteQuantity::assert($bad);
                $this->fail('quantity '.var_export($bad, true).' must be rejected');
            } catch (BusinessException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            DiscreteQuantity::assert(0);
            $this->fail('zero must be rejected without allowZero');
        } catch (BusinessException) {
            $this->addToAssertionCount(1);
        }
    }

    // ---------- BranchAccess ----------

    public function test_branch_access_matrix(): void
    {
        $access = app(BranchAccess::class);
        $active = Branch::factory()->create(['is_active' => true]);
        $stopped = Branch::factory()->create(['is_active' => false]);

        // null user → 403
        try {
            $access->restrictedBranch(null);
            $this->fail('null user must be denied');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        // Super Admin → global
        $super = User::factory()->create(['branch_id' => null]);
        $super->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $this->assertNull($access->restrictedBranch($super));

        // all-branches permission → global
        Permission::findOrCreate('inventory.all_branches', 'staff');
        $global = User::factory()->create(['branch_id' => null]);
        $global->givePermissionTo('inventory.all_branches');
        $this->assertNull($access->restrictedBranch($global));

        // confined user → own branch
        $confined = User::factory()->create(['branch_id' => $active->id]);
        $this->assertSame($active->id, $access->restrictedBranch($confined));

        // confined to stopped branch → 403
        $orphaned = User::factory()->create(['branch_id' => $stopped->id]);
        try {
            $access->restrictedBranch($orphaned);
            $this->fail('stopped-branch user must be denied');
        } catch (AuthorizationException $e) {
            $this->assertSame('الفرع المرتبط بحسابك متوقف حالياً.', $e->getMessage());
        }

        // no branch, no privilege → 403
        $nobody = User::factory()->create(['branch_id' => null]);
        $this->expectException(AuthorizationException::class);
        $access->restrictedBranch($nobody);
    }

    public function test_assert_branch_accepts_any_matching_branch(): void
    {
        $access = app(BranchAccess::class);
        $a = Branch::factory()->create(['is_active' => true]);
        $b = Branch::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['branch_id' => $a->id]);

        $access->assertBranch($user->id, $b->id, $a->id); // one match is enough
        $this->addToAssertionCount(1);

        $this->expectException(AuthorizationException::class);
        $access->assertBranch($user->id, $b->id);
    }

    // ---------- CostAccess ----------

    public function test_cost_access_requires_a_cost_permission(): void
    {
        Permission::findOrCreate('products.view_cost', 'staff');
        Permission::findOrCreate('inventory.view_cost', 'staff');

        $this->assertFalse(CostAccess::canView(null));
        $this->assertFalse(CostAccess::canView(User::factory()->create()));

        $legacy = User::factory()->create();
        $legacy->givePermissionTo('products.view_cost');
        $this->assertTrue(CostAccess::canView($legacy));

        $current = User::factory()->create();
        $current->givePermissionTo('inventory.view_cost');
        $this->assertTrue(CostAccess::canView($current));
    }
}

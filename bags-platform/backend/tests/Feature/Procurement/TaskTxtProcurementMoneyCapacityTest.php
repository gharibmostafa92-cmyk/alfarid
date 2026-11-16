<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Http\Middleware\ManufacturingBusiness;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-02: supplier ledger money columns must hold the same capacity as the
 * purchase-order columns, and request validation must permit that capacity.
 */
class TaskTxtProcurementMoneyCapacityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, RolePermissionSeeder::class]);
        $this->withoutMiddleware(ManufacturingBusiness::class);
    }

    public function test_supplier_money_columns_have_purchase_order_capacity(): void
    {
        foreach ([
            ['suppliers', 'opening_balance'],
            ['suppliers', 'current_balance'],
            ['supplier_invoices', 'subtotal'],
            ['supplier_invoices', 'tax_amount'],
            ['supplier_invoices', 'total_amount'],
            ['supplier_invoices', 'paid_amount'],
            ['supplier_payments', 'amount'],
        ] as [$table, $column]) {
            $meta = DB::table('information_schema.columns')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->first(['numeric_precision', 'numeric_scale']);

            $this->assertSame(20, (int) $meta->numeric_precision, "{$table}.{$column} precision");
            $this->assertSame(2, (int) $meta->numeric_scale, "{$table}.{$column} scale");
        }
    }

    public function test_invoice_validation_accepts_the_widened_capacity_and_rejects_overflow(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $supplier = Supplier::factory()->create();
        $token = $admin->createToken('money-capacity')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $supplier->id,
            'supplier_invoice_no' => 'CAP-OK',
            'issue_date' => now()->toDateString(),
            'subtotal' => '999999999999.99',
            'tax_amount' => 0,
        ])->assertCreated();

        $this->assertDatabaseHas('supplier_invoices', [
            'supplier_invoice_no' => 'CAP-OK',
            'total_amount' => '999999999999.99',
        ]);

        $this->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $supplier->id,
            'supplier_invoice_no' => 'CAP-OVER',
            'issue_date' => now()->toDateString(),
            'subtotal' => '1000000000000000000',
            'tax_amount' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('subtotal');
    }

    public function test_fully_received_four_decimal_order_invoices_at_exact_grand_total(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $variant = ProductVariant::factory()->create(['cost_price' => 1, 'retail_price' => 2]);
        $token = $admin->createToken('money-capacity-4d')->plainTextToken;

        $po = $this->withToken($token)->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'discount_amount' => '0.01',
            'items' => [[
                'product_variant_id' => $variant->id,
                'ordered_qty' => 3,
                'unit_cost' => '0.3333',
                'tax_rate' => 15,
            ]],
        ])->assertCreated()->json('data');

        $approver = User::factory()->create();
        $approver->assignRole('Super Admin');
        $approverToken = $approver->createToken('money-capacity-approver')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($approverToken)
            ->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")
            ->assertOk();
        Auth::forgetGuards();
        $this->withToken($approverToken)
            ->postJson("/api/v1/admin/purchase-orders/{$po['id']}/send")
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 3]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        $invoice = $this->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'CAP-4D-OK',
            'issue_date' => now()->toDateString(),
            'subtotal' => $po['grand_total'],
            'tax_amount' => 0,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/supplier-invoices/{$invoice}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');
    }
}

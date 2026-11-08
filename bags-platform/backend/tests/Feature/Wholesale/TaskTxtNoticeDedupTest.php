<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Models\Customer;
use App\Services\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskTxtNoticeDedupTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $this->token = $this->trader->createToken('portal')->plainTextToken;
    }

    private function asTrader(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    private function submit(array $overrides = [], ?string $token = null)
    {
        $payload = array_merge([
            'amount' => 400,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك مصر',
            'reference_number' => 'DEDUP-'.Str::uuid(),
            'transfer_date' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ], $overrides);

        return $this->asTrader($token ?? $this->token)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/wholesale/payment-notices', $payload);
    }

    public function test_same_reference_amount_date_from_another_trader_conflicts(): void
    {
        $other = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);
        $otherToken = $other->createToken('other')->plainTextToken;

        $this->submit(['reference_number' => 'SHARED-1', 'amount' => 250])->assertCreated();
        $this->submit(
            ['reference_number' => 'SHARED-1', 'amount' => 250, 'receipt' => UploadedFile::fake()->image('other.jpg', 200, 200)],
            $otherToken
        )->assertConflict();

        $this->assertSame(1, \App\Models\WholesalePaymentNotice::query()->count());
    }

    public function test_repeated_reference_submission_keeps_single_row(): void
    {
        $this->submit(['reference_number' => 'RACE-1'])->assertCreated();
        $this->submit(
            ['reference_number' => 'RACE-1', 'receipt' => UploadedFile::fake()->image('second.jpg', 200, 200)]
        )->assertConflict();

        $this->assertSame(1, \App\Models\WholesalePaymentNotice::query()->count());
        $this->assertDatabaseCount('treasury_transactions', 0);
    }

    public function test_reference_block_persists_after_reject_while_receipt_check_differs(): void
    {
        // Documents the pending-unification split: the per-trader reference
        // check includes rejected notices, the service receipt check excludes
        // them (the unique index excludes rejected AND reversed).
        $service = app(WholesalePaymentNoticeService::class);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Super Admin');

        $firstId = $this->submit(['reference_number' => 'RETRY-1'])->assertCreated()->json('data.id');
        $first = \App\Models\WholesalePaymentNotice::query()->findOrFail($firstId);
        $service->reject($first, $admin->id, 'غير واضح');

        // Same reference after rejection stays blocked for the same trader.
        $this->submit(
            ['reference_number' => 'RETRY-1', 'receipt' => UploadedFile::fake()->image('retry.jpg', 300, 300)]
        )->assertConflict();
    }
}

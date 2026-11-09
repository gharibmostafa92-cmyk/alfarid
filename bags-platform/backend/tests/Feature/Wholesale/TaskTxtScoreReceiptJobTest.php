<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Jobs\ScoreWholesaleReceipt;
use App\Models\Customer;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\GeminiClient;
use App\Services\Shared\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskTxtScoreReceiptJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        config(['services.gemini.api_key' => 'test-key']);
    }

    private function enableVision(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'ai_wholesale_receipt_vision_enabled'],
            ['value' => '1', 'group' => 'ai', 'type' => 'boolean']
        );
        app(SettingService::class)->clearCache();
    }

    private function noticeWithReceipt(): WholesalePaymentNotice
    {
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 1500,
            'reference_number' => 'TXN-1',
            'transfer_date' => '2026-11-07',
            'bank_name' => 'CIB',
            'status' => 'pending_review',
        ]);
        Storage::disk('r2')->put($notice->receipt_file_path, 'fake-bytes');

        return $notice->fresh();
    }

    /** @return list<string> */
    private function tempReceipts(): array
    {
        return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'receipt*') ?: [];
    }

    private function runJob(int $noticeId): void
    {
        app(ScoreWholesaleReceipt::class, ['noticeId' => $noticeId]);
        (new ScoreWholesaleReceipt($noticeId))->handle(
            app(SettingService::class),
            app(GeminiClient::class),
            app(\App\Services\Wholesale\ReceiptMatchScorer::class)
        );
    }

    public function test_disabled_flag_leaves_score_untouched(): void
    {
        $notice = $this->noticeWithReceipt();

        $this->runJob($notice->id);

        $this->assertNull($notice->fresh()->match_score);
    }

    public function test_missing_api_key_leaves_score_untouched(): void
    {
        $this->enableVision();
        config(['services.gemini.api_key' => '']);
        $notice = $this->noticeWithReceipt();

        $this->runJob($notice->id);

        $this->assertNull($notice->fresh()->match_score);
    }

    public function test_missing_r2_file_leaves_score_untouched(): void
    {
        $this->enableVision();
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'pending_review',
        ]);

        $this->runJob($notice->id);

        $this->assertNull($notice->fresh()->match_score);
    }

    public function test_gemini_failure_leaves_score_untouched_without_temp_leftovers(): void
    {
        $this->enableVision();
        $notice = $this->noticeWithReceipt();
        $before = $this->tempReceipts();

        $gemini = \Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('generateStructured')->once()->andThrow(new \RuntimeException('timeout'));
        app()->instance(GeminiClient::class, $gemini);

        $this->runJob($notice->id);

        $this->assertNull($notice->fresh()->match_score);
        $this->assertSame($before, $this->tempReceipts());
    }

    public function test_successful_read_scores_and_cleans_up(): void
    {
        $this->enableVision();
        $notice = $this->noticeWithReceipt();
        $before = $this->tempReceipts();

        $gemini = \Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('generateStructured')->once()->andReturn([
            'amount' => '1500.00',
            'reference' => 'TXN-1',
            'date' => '2026-11-07',
            'bank' => 'CIB',
            'sender' => 'x',
        ]);
        app()->instance(GeminiClient::class, $gemini);

        $this->runJob($notice->id);

        $this->assertSame(100.0, (float) $notice->fresh()->match_score);
        $this->assertSame($before, $this->tempReceipts());
    }
}

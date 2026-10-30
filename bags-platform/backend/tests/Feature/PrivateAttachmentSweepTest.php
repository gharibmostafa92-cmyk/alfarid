<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ReturnRequestItem;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\CustomerManagementService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateAttachmentSweepTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_document_is_staged_before_its_write_transaction_and_can_be_reviewed(): void
    {
        $customer = Customer::factory()->wholesale()->create();
        $actor = User::factory()->create();
        $level = DB::transactionLevel();
        $file = \Mockery::mock(UploadedFile::class);
        $file->shouldReceive('store')->once()->andReturnUsing(function () use ($level): string {
            $this->assertSame($level, DB::transactionLevel());
            Storage::disk('r2')->put('customer_documents/staged.pdf', 'document');

            return 'customer_documents/staged.pdf';
        });
        $file->shouldReceive('getClientOriginalName')->andReturn('document.pdf');
        $file->shouldReceive('getMimeType')->andReturn('application/pdf');
        $service = app(CustomerManagementService::class);
        $document = $service->uploadDocument($customer, $file, $actor->id);
        $reviewed = $service->reviewDocument($customer, $document, 'verified', $actor->id);
        $this->assertSame('verified', $reviewed->status->value);
        Storage::disk('r2')->assertExists($document->file_path);
    }

    public function test_sweep_keeps_referenced_and_recent_files_and_deletes_old_orphans(): void
    {
        $disk = Storage::disk('r2');
        $returnPath = 'return_requests/old/kept.jpg';
        $noticePath = 'wholesale_payment_notices/old/kept.pdf';
        ReturnRequestItem::factory()->create(['attachments' => [$returnPath]]);
        WholesalePaymentNotice::factory()->create(['receipt_file_path' => $noticePath]);
        $old = [$returnPath, $noticePath, 'return_requests/pending/orphan.jpg', 'supplier_invoices/old/orphan.pdf', 'supplier_deliveries/old/orphan.pdf', 'wholesale_payment_notices/old/orphan.pdf'];
        foreach ($old as $path) {
            $disk->put($path, 'private data');
            touch($disk->path($path), now()->subDays(2)->timestamp);
        }
        $recent = 'return_requests/pending/recent.jpg';
        $disk->put($recent, 'new upload');
        $this->artisan('attachments:prune-orphans --dry-run')->assertSuccessful();
        foreach ($old as $path) {
            $disk->assertExists($path);
        }
        $this->artisan('attachments:prune-orphans')->assertSuccessful();
        foreach ([$returnPath, $noticePath, $recent] as $path) {
            $disk->assertExists($path);
        }
        foreach (array_diff($old, [$returnPath, $noticePath]) as $path) {
            $disk->assertMissing($path);
        }
    }

    public function test_a_reference_committed_after_the_batch_lookup_protects_the_file(): void
    {
        $path = 'supplier_invoices/late-reference.pdf';
        $invoice = SupplierInvoice::factory()->create(['attachment_path' => null]);
        $disk = Storage::disk('r2');
        $disk->put($path, 'invoice');
        touch($disk->path($path), now()->subDays(2)->timestamp);
        $linked = false;
        DB::listen(function ($query) use (&$linked, $invoice, $path): void {
            if (! $linked && str_starts_with($query->sql, 'select "attachment_path" from "supplier_invoices"')) {
                $linked = true;
                $invoice->update(['attachment_path' => $path]);
            }
        });
        $this->artisan('attachments:prune-orphans')->assertSuccessful();
        $this->assertTrue($linked);
        $disk->assertExists($path);
    }

    public function test_a_failed_delete_does_not_stop_cleanup_of_later_prefixes(): void
    {
        $disk = Storage::disk('r2');
        $failed = 'return_requests/old/failure.jpg';
        $other = 'supplier_invoices/old/deleted.pdf';
        foreach ([$failed, $other] as $path) {
            $disk->put($path, 'private data');
            touch($disk->path($path), now()->subDays(2)->timestamp);
        }
        $proxy = \Mockery::mock(FilesystemAdapter::class);
        $proxy->shouldReceive('getDriver')->andReturn($disk->getDriver());
        $proxy->shouldReceive('delete')->with($failed)->once()->andReturnFalse();
        $proxy->shouldReceive('delete')->with($other)->once()->andReturnUsing(fn ($path) => $disk->delete($path));
        Storage::shouldReceive('disk')->with('r2')->andReturn($proxy);
        $this->artisan('attachments:prune-orphans')->assertFailed();
        $disk->assertExists($failed);
        $disk->assertMissing($other);
    }
}

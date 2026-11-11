<?php

declare(strict_types=1);

namespace Tests\Feature\Attachments;

use App\Models\Customer;
use App\Models\ReturnRequestItem;
use App\Models\SupplierDelivery;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Customer\CustomerManagementService;
use App\Support\StorageCleanupOwner;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivateAttachmentSweepTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markStorageForCleanup('r2');
    }

    public function test_cleanup_refuses_a_disk_marked_for_another_database(): void
    {
        $disk = Storage::disk('r2');
        $disk->put('.alfarid-cleanup-owner', 'another-environment');
        $path = 'supplier_invoices/old/retained.pdf';
        $disk->put($path, 'invoice');
        touch($disk->path($path), now()->subDays(2)->timestamp);

        $this->artisan('attachments:prune-orphans')->assertFailed();
        $this->artisan('media:prune-orphans --disk=r2 --staging-only')->assertFailed();
        $disk->assertExists($path);
    }

    public function test_a_reference_from_a_different_attachment_column_protects_the_file(): void
    {
        $path = 'supplier_invoices/old/cross-reference.pdf';
        SupplierDelivery::factory()->create(['invoice_file_path' => $path]);
        $disk = Storage::disk('r2');
        $disk->put($path, 'invoice');
        touch($disk->path($path), now()->subDays(2)->timestamp);

        $this->artisan('attachments:prune-orphans')->assertSuccessful();
        $disk->assertExists($path);
    }

    public function test_a_copied_database_owner_cannot_use_a_marker_without_a_database_fingerprint(): void
    {
        $disk = Storage::disk('r2');
        $disk->put(StorageCleanupOwner::MARKER, 'alfarid-test');
        $path = 'supplier_invoices/old/copied-database.pdf';
        $disk->put($path, 'invoice');
        touch($disk->path($path), now()->subDays(2)->timestamp);

        $this->assertSame(1, $this->realArtisan('attachments:prune-orphans'));
        $disk->assertExists($path);
    }

    public function test_max_deletions_circuit_breaker_stops_a_runaway_sweep(): void
    {
        $disk = Storage::disk('r2');
        $paths = ['return_requests/old/a.pdf', 'supplier_invoices/old/b.pdf', 'supplier_deliveries/old/c.pdf'];
        foreach ($paths as $path) {
            $disk->put($path, 'invoice');
            touch($disk->path($path), now()->subDays(2)->timestamp);
        }

        $this->assertSame(1, $this->realArtisan('attachments:prune-orphans', ['--max-deletions' => 2]));
        $this->assertSame(1, $this->realArtisan('attachments:prune-orphans', ['--max-deletions' => 2]));
        foreach ($paths as $path) {
            $disk->assertExists($path);
        }
        $this->assertSame(1, $this->realArtisan('attachments:prune-orphans', ['--max-deletions' => 0]));
        $this->assertSame(0, $this->realArtisan('attachments:prune-orphans', ['--max-deletions' => 3]));
        foreach ($paths as $path) {
            $disk->assertMissing($path);
        }
    }

    public function test_old_customer_uploads_without_a_document_row_are_reclaimed(): void
    {
        $customer = Customer::factory()->wholesale()->create();
        $prefix = 'customer_documents/'.$customer->id.'/'.Str::uuid();
        $orphan = $prefix.'/orphan.pdf';
        $referenced = $prefix.'/referenced.pdf';
        $customer->documents()->create([
            'file_path' => $referenced, 'document_type' => 'other',
            'original_name' => 'referenced.pdf', 'mime_type' => 'application/pdf',
        ]);
        $disk = Storage::disk('r2');
        foreach ([$orphan, $referenced] as $path) {
            $disk->put($path, 'document');
            touch($disk->path($path), now()->subDays(2)->timestamp);
        }

        $this->artisan('attachments:prune-orphans')->assertSuccessful();
        $disk->assertMissing($orphan);
        $disk->assertExists($referenced);
    }

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
        $legacyPath = 'return_requests/old/legacy-object.jpg';
        ReturnRequestItem::factory()->create(['attachments' => [$returnPath, ['path' => $legacyPath, 'name' => 'proof.jpg']]]);
        WholesalePaymentNotice::factory()->create(['receipt_file_path' => $noticePath]);
        $old = [$returnPath, $legacyPath, $noticePath, 'return_requests/pending/orphan.jpg', 'supplier_invoices/old/orphan.pdf', 'supplier_deliveries/old/orphan.pdf', 'wholesale_payment_notices/old/orphan.pdf'];
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
        foreach ([$returnPath, $legacyPath, $noticePath, $recent] as $path) {
            $disk->assertExists($path);
        }
        foreach (array_diff($old, [$returnPath, $legacyPath, $noticePath]) as $path) {
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
        $proxy->shouldReceive('exists')->with('.alfarid-cleanup-owner')->andReturnTrue();
        $proxy->shouldReceive('get')->with('.alfarid-cleanup-owner')->andReturn($disk->get(StorageCleanupOwner::MARKER));
        $proxy->shouldReceive('delete')->with($failed)->once()->andReturnFalse();
        $proxy->shouldReceive('delete')->with($other)->once()->andReturnUsing(fn ($path) => $disk->delete($path));
        Storage::shouldReceive('disk')->with('r2')->andReturn($proxy);
        $this->artisan('attachments:prune-orphans')->assertFailed();
        $disk->assertExists($failed);
        $disk->assertMissing($other);
    }
}

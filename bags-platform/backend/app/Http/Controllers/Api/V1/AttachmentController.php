<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ReturnRequestItem;
use App\Models\SupplierDelivery;
use App\Models\SupplierInvoice;
use App\Models\SupplierUser;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Securely streams private file attachments with strict role-based access control and nosniff headers.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly BranchAccess $branchAccess) {}

    /**
     * Streams inspection photos attached to a return request item.
     *
     * @param Request $request Authorized request
     * @param int $returnItem Return item identifier
     * @param int $attachment Attachment array index
     * @return StreamedResponse File stream
     */
    public function returnFile(Request $request, int $returnItem, int $attachment): StreamedResponse
    {
        $actor = $request->user();
        $query = ReturnRequestItem::query();
        if ($actor instanceof Customer) {
            $query->whereHas('returnRequest', fn ($q) => $q->where('customer_id', $actor->id));
        } else {
            abort_unless($actor instanceof User && $actor->can('returns.view'), 403);
        }
        $item = $query->findOrFail($returnItem);
        if ($actor instanceof User) {
            $return = $item->returnRequest;
            $branchId = $return->branch_id ?? $return->order?->allocated_branch_id;
            if ($branchId === null) {
                $this->branchAccess->assertCentralAccess($actor);
            } else {
                $this->branchAccess->assertBranch($actor->id, (int) $branchId);
            }
        }

        return $this->download(($item->attachments ?? [])[$attachment] ?? null);
    }

    /**
     * Streams supplier invoice PDF attachment.
     *
     * @param Request $request Authorized request
     * @param int $invoice Supplier invoice identifier
     * @return StreamedResponse File stream
     */
    public function invoice(Request $request, int $invoice): StreamedResponse
    {
        $actor = $request->user();
        $query = SupplierInvoice::query();
        if ($actor instanceof SupplierUser) {
            $query->where('supplier_id', $actor->supplier_id)->where('status', '!=', 'draft');
        } else {
            abort_unless($actor instanceof User && $actor->can('purchases.view'), 403);
        }

        return $this->download($query->findOrFail($invoice)->attachment_path);
    }

    /**
     * Streams delivery receipt document attached to a procurement delivery.
     *
     * @param Request $request Authorized request
     * @param int $delivery Delivery identifier
     * @return StreamedResponse File stream
     */
    public function delivery(Request $request, int $delivery): StreamedResponse
    {
        $actor = $request->user();
        $query = SupplierDelivery::query();
        if ($actor instanceof SupplierUser) {
            $query->where('supplier_id', $actor->supplier_id);
        } else {
            abort_unless($actor instanceof User && $actor->can('purchases.view'), 403);
        }

        return $this->download($query->findOrFail($delivery)->invoice_file_path);
    }

    /**
     * Streams bank transfer receipt attached to a wholesale payment notice.
     *
     * @param Request $request Authorized request
     * @param int $notice Payment notice identifier
     * @return StreamedResponse File stream
     */
    public function paymentNotice(Request $request, int $notice): StreamedResponse
    {
        $actor = $request->user();
        $query = WholesalePaymentNotice::query();
        if ($actor instanceof Customer) {
            $query->where('customer_id', $actor->id);
        } else {
            abort_unless($actor instanceof User && $actor->can('payment_notices.view'), 403);
        }

        return $this->download($query->findOrFail($notice)->receipt_file_path);
    }

    private function download(?string $path): StreamedResponse
    {
        abort_if($path === null || ! Storage::disk('r2')->exists($path), 404);

        return Storage::disk('r2')->download($path, basename($path), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}

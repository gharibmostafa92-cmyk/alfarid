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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Securely streams private file attachments with strict role-based access control and nosniff headers.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly BranchAccess $branchAccess) {}

    /**
     * Streams inspection photos attached to a return request item.
     *
     * @param  Request  $request  Authorized request
     * @param  int  $returnItem  Return item identifier
     * @param  int  $attachment  Attachment array index
     * @return RedirectResponse Short-lived signed R2 URL (H3)
     */
    public function returnFile(Request $request, int $returnItem, int $attachment): RedirectResponse|JsonResponse
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

        return $this->download($request, ($item->attachments ?? [])[$attachment] ?? null);
    }

    /**
     * Streams supplier invoice PDF attachment.
     *
     * @param  Request  $request  Authorized request
     * @param  int  $invoice  Supplier invoice identifier
     * @return RedirectResponse Short-lived signed R2 URL (H3)
     */
    public function invoice(Request $request, int $invoice): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        $query = SupplierInvoice::query()->with('purchaseOrder');
        if ($actor instanceof SupplierUser) {
            $query->where('supplier_id', $actor->supplier_id)->where('status', '!=', 'draft');
        } else {
            abort_unless($actor instanceof User && $actor->can('purchases.view'), 403);
        }

        $model = $query->findOrFail($invoice);
        if ($actor instanceof User) {
            // M5: same branch rule as returnFile — a branch-confined staffer
            // must not fetch another branch's procurement documents by id.
            $branchId = $model->purchaseOrder?->branch_id;
            if ($branchId === null) {
                $this->branchAccess->assertCentralAccess($actor);
            } else {
                $this->branchAccess->assertBranch($actor->id, (int) $branchId);
            }
        }

        return $this->download($request, $model->attachment_path);
    }

    /**
     * Streams delivery receipt document attached to a procurement delivery.
     *
     * @param  Request  $request  Authorized request
     * @param  int  $delivery  Delivery identifier
     * @return RedirectResponse Short-lived signed R2 URL (H3)
     */
    public function delivery(Request $request, int $delivery): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        $query = SupplierDelivery::query()->with('purchaseOrder');
        if ($actor instanceof SupplierUser) {
            $query->where('supplier_id', $actor->supplier_id);
        } else {
            abort_unless($actor instanceof User && $actor->can('purchases.view'), 403);
        }

        $model = $query->findOrFail($delivery);
        if ($actor instanceof User) {
            // M5: same branch rule as returnFile.
            $branchId = $model->purchaseOrder?->branch_id;
            if ($branchId === null) {
                $this->branchAccess->assertCentralAccess($actor);
            } else {
                $this->branchAccess->assertBranch($actor->id, (int) $branchId);
            }
        }

        return $this->download($request, $model->invoice_file_path);
    }

    /**
     * Streams bank transfer receipt attached to a wholesale payment notice.
     *
     * @param  Request  $request  Authorized request
     * @param  int  $notice  Payment notice identifier
     * @return RedirectResponse Short-lived signed R2 URL (H3)
     */
    public function paymentNotice(Request $request, int $notice): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        $query = WholesalePaymentNotice::query();
        if ($actor instanceof Customer) {
            $query->where('customer_id', $actor->id);
        } else {
            abort_unless($actor instanceof User && $actor->can('payment_notices.view'), 403);
        }

        $model = $query->with('order')->findOrFail($notice);
        if ($actor instanceof User) {
            $branchId = $model->order?->allocated_branch_id;
            if ($branchId === null) {
                $this->branchAccess->assertCentralAccess($actor);
            } else {
                $this->branchAccess->assertBranch($actor->id, (int) $branchId);
            }
        }

        return $this->download($request, $model->receipt_file_path);
    }

    private function download(Request $request, ?string $path): RedirectResponse|JsonResponse
    {
        // H3: never proxy R2 bytes through PHP-FPM. The old exists()+download()
        // held one FPM worker for the whole transfer (a 20MB file on a 500KB/s
        // link exceeds the 30s Guzzle http.timeout and arrives truncated), and
        // let one client exhaust workers with slow downloads. Issue a 2-minute
        // signed URL instead; authorization stays here, bytes come from R2.
        abort_if($path === null, 404);

        try {
            if (! $request->boolean('signed') && ! Storage::disk('r2')->exists($path)) {
                abort(404);
            }

            $url = Storage::disk('r2')->temporaryUrl(
                $path,
                now()->addMinutes(2),
                [
                    'ResponseContentDisposition' => HeaderUtils::makeDisposition('attachment', basename($path), 'attachment'),
                    // L11: private documents must never sit in shared caches.
                    'ResponseCacheControl' => 'private, max-age=120',
                ]
            );
        } catch (Throwable $exception) {
            if ((int) ($exception->getCode() ?: 0) === 404 || $exception instanceof HttpException) {
                throw $exception;
            }

            // R2 outage (throw => true) is a dependency failure, not a bug.
            abort(503, 'Document storage is temporarily unavailable.');
        }

        if ($request->boolean('signed')) {
            return response()->json(['url' => $url])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->to($url)->header('Cache-Control', 'private, no-store');
    }
}

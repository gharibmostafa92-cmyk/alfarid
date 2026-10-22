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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
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

        return $this->download(($item->attachments ?? [])[$attachment] ?? null);
    }

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

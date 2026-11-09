<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Http\Controllers\Controller;
use App\Imports\WholesaleQuickOrderImport;
use App\Models\Customer;
use App\Services\Wholesale\WholesaleQuickOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class QuickOrderController extends Controller
{
    public function __construct(private readonly WholesaleQuickOrderService $service) {}

    /**
     * Bulk lookup of variants by SKU/barcode with requested quantities, tiered prices, and availability.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.sku' => ['nullable', 'string', 'max:255', 'required_without:items.*.barcode'],
            'items.*.barcode' => ['nullable', 'string', 'max:255'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();
        $lines = $this->service->lookup($customer, $validated['items']);

        return response()->json([
            'data' => $lines,
            'meta' => [
                'found' => collect($lines)->where('found', true)->count(),
                'total' => collect($lines)->where('found', true)->sum('line_total'),
            ],
        ]);
    }

    /**
     * Batch-add quick order items to the merchant cart (up to 500 lines) in a single request.
     */
    public function addToCart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.sku' => ['required', 'string', 'max:255'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();

        return response()->json(['data' => $this->service->importToCart($customer, $validated['items'])]);
    }

    /**
     * Import spreadsheet rows (SKU, qty) directly into the cart and return added vs skipped item counts.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();

        $import = new WholesaleQuickOrderImport;
        Excel::import($import, $request->file('file'));

        $rows = $this->normalizeRows($import->rows);
        $report = $this->service->importToCart($customer, $rows);

        return response()->json(['data' => $report]);
    }

    /**
     * Normalize sheet rows to [{sku, qty}], handling optional header rows or indexed columns.
     *
     * @return list<array{sku: string, qty: float}>
     */
    private function normalizeRows(array $sheet): array
    {
        $rows = [];
        foreach ($sheet as $index => $row) {
            $values = array_values($row);

            if ($index === 0) {
                $header = collect($values)->map(fn ($v) => strtolower(trim((string) $v)))->all();
                if (in_array('sku', $header, true)) {
                    continue; // Skip detected header row.
                }
            }

            $sku = isset($values[0]) ? trim((string) $values[0]) : '';
            $qty = isset($values[1]) && is_numeric($values[1]) ? (float) $values[1] : 0.0;
            if ($sku === '' || $qty <= 0) {
                continue;
            }

            $rows[] = ['sku' => $sku, 'qty' => $qty];
        }

        return $rows;
    }
}

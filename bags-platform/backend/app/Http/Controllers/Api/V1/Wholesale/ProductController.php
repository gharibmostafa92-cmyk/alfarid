<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Exceptions\BusinessException;
use App\Exports\WholesalePriceListExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Services\PricingService;
use App\Services\SettingService;
use App\Services\WholesalePriceListService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ProductController extends Controller
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly WholesalePriceListService $priceList,
        private readonly SettingService $settings,
    ) {}

    /** كتالوج الجملة: لكل متغير سعر هذا التاجر وشرائح كمياته والمتاح. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();

        $products = Product::query()
            ->where('status', 'published')
            ->with(['variants' => fn ($q) => $q->where('is_active', true)])
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(function ($q) use ($v): void {
                $q->where('name_ar', 'like', "%{$v}%")
                    ->orWhere('name_en', 'like', "%{$v}%")
                    ->orWhereHas('variants', fn ($q) => $q->where('sku', 'like', "%{$v}%"));
            }))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        $variantIds = $products->getCollection()
            ->flatMap(fn (Product $product) => $product->variants->pluck('id'))
            ->all();

        $availability = StockLevel::query()
            ->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(available) AS total_available')
            ->pluck('total_available', 'product_variant_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        $data = $products->getCollection()->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name_ar,
            'name_en' => $product->name_en,
            'variants' => $product->variants->map(function (ProductVariant $variant) use ($customer, $availability): array {
                try {
                    $resolved = $this->pricing->resolve($variant, $customer, 1);
                    $price = $resolved['unit_price'];
                    $base = $resolved['base_price'];
                    $tiers = $this->pricing->tiersFor($variant, $customer);
                } catch (BusinessException) {
                    $price = null;
                    $base = $variant->retail_price !== null ? (float) $variant->retail_price : null;
                    $tiers = [];
                }

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'base_price' => $base,
                    'your_price' => $price,
                    'tier_prices' => $tiers,
                    'available_qty' => $availability[$variant->id] ?? 0.0,
                ];
            })->values()->all(),
        ])->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /** تصدير قائمة أسعار التاجر: xlsx منسق أو PDF بترويسة الشركة وصلاحية. */
    public function exportPriceList(Request $request): BinaryFileResponse|Response
    {
        $validated = $request->validate([
            'format' => ['required', Rule::in(['pdf', 'xlsx'])],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();
        $rows = $this->priceList->rows($customer);

        if ($validated['format'] === 'xlsx') {
            return Excel::download(new WholesalePriceListExport($rows), 'price-list.xlsx');
        }

        $validUntil = now()->addDays(30);

        return Pdf::loadView('pdfs.wholesale_price_list', [
            'company_name' => (string) $this->settings->get('company_name', 'Alfarid'),
            'customer' => $customer,
            'rows' => $rows,
            'valid_until' => $validUntil,
            'generated_at' => now(),
        ])->download('price-list.pdf');
    }
}

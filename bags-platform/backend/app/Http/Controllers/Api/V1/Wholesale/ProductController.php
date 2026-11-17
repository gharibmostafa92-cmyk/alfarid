<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Enums\Orders\SalesChannel;
use App\Exceptions\BusinessException;
use App\Exports\WholesalePriceListExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Services\Pricing\PricingService;
use App\Services\Shared\SearchTerm;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\WholesalePriceListService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Wholesale catalog: resolves merchant tier pricing, base price, and active branch availability.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();

        $products = Product::query()
            // Restrict wholesale catalog to published products on active category trees in wholesale channels.
            ->where('status', 'published')
            ->whereIn('sales_channel', [SalesChannel::Wholesale, SalesChannel::Both])
            ->whereIn('category_id', function ($sub): void {
                $sub->select('id')->fromRaw('(
                    WITH RECURSIVE active_categories AS (
                        SELECT id FROM categories WHERE is_active = true AND parent_id IS NULL AND deleted_at IS NULL
                        UNION ALL
                        SELECT c.id FROM categories c
                        INNER JOIN active_categories ac ON c.parent_id = ac.id
                        WHERE c.is_active = true AND c.deleted_at IS NULL
                    )
                    SELECT id FROM active_categories
                ) AS active_categories');
            })
            ->with(['media', 'variants' => fn ($q) => $q->where('is_active', true)->with('media')])
            ->when($filters['search'] ?? null, function ($q, $v): void {
                $pattern = SearchTerm::pattern($v);
                $q->where(fn ($sub) => $sub
                    ->whereLike('name_ar', $pattern, caseSensitive: false)
                    ->orWhereLike('name_en', $pattern, caseSensitive: false)
                    ->orWhereHas('variants', fn ($qv) => $qv->whereLike('sku', $pattern, caseSensitive: false)));
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        $variantIds = $products->getCollection()
            ->flatMap(fn (Product $product) => $product->variants->pluck('id'))
            ->all();

        // Calculate available stock across active branches only.
        $availability = StockLevel::query()
            ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
            ->where('branches.is_active', true)
            ->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(available) AS total_available')
            ->pluck('total_available', 'product_variant_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        // Preload customer price-list context once for all variants in the page to prevent repeated queries.
        $pricingCtx = $this->pricing->contextFor($customer, $variantIds);

        $data = $products->getCollection()->map(function (Product $product) use ($customer, $availability, $pricingCtx): array {
            $images = $product->catalogImages();
            $first = $images->first();
            $image = $first !== null
                ? ($first->hasGeneratedConversion('thumb') ? $first->getUrl('thumb') : $first->getUrl())
                : null;

            return [
                'id' => $product->id,
                'name' => $product->name_ar,
                'name_en' => $product->name_en,
                'image' => $image,
                'variants' => $product->variants->map(function (ProductVariant $variant) use ($customer, $availability, $pricingCtx, $image): array {
                    try {
                        $resolved = $this->pricing->resolve($variant, $customer, 1, null, $pricingCtx);
                        $price = $resolved['unit_price'];
                        $base = $resolved['base_price'];
                        $tiers = $this->pricing->tiersFor($variant, $customer, $pricingCtx);
                    } catch (BusinessException) {
                        $price = null;
                        $base = $variant->retail_price !== null ? (float) $variant->retail_price : null;
                        $tiers = [];
                    }

                    return [
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'barcode' => $variant->barcode,
                        'label' => $variant->displayLabel(),
                        'image' => $image,
                        'base_price' => $base,
                        'your_price' => $price,
                        'tier_prices' => $tiers,
                        'available_qty' => $availability[$variant->id] ?? 0.0,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

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

    /**
     * Export merchant price list as formatted Excel or branded PDF with 30-day validity.
     */
    public function exportPriceList(Request $request): BinaryFileResponse|Response
    {
        $validated = $request->validate([
            'format' => ['required', Rule::in(['pdf', 'xlsx'])],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();
        $limit = $validated['format'] === 'xlsx' ? null : 501;
        $cacheKey = 'wholesale:price-list-rows:c'.$customer->id.':v'.$this->pricing->catalogVersion().':'.$validated['format'];
        $rows = Cache::remember(
            $cacheKey,
            300,
            fn (): array => $this->priceList->rows($customer, $limit),
        );
        if ($validated['format'] === 'xlsx') {
            return Excel::download(new WholesalePriceListExport($rows), 'price-list.xlsx');
        }
        if (count($rows) > 500) {
            throw new BusinessException('قائمة الأسعار أكبر من حد PDF (500 صنف). استخدم تصدير Excel.');
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

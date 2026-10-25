<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BannerLinkType;
use App\Enums\BannerPosition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBannerRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBannerRequest;
use App\Http\Resources\Admin\AdminBannerResource;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\BannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class BannerController extends Controller
{
    public function __construct(private readonly BannerService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'position' => ['nullable', Rule::enum(BannerPosition::class)],
        ]);
        $banners = Banner::query()
            ->when($request->filled('position'), fn ($q) => $q->where('position', $request->input('position')))
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        $this->attachLinkLabels($banners);

        return AdminBannerResource::collection($banners);
    }

    public function show(Banner $banner): AdminBannerResource
    {
        $this->attachLinkLabels(collect([$banner]));

        return new AdminBannerResource($banner);
    }

    public function store(StoreBannerRequest $request): JsonResponse
    {
        $banner = $this->service->create($request->validated(), $request->file('image'));
        $this->attachLinkLabels(collect([$banner]));

        return (new AdminBannerResource($banner))->response()->setStatusCode(201);
    }

    public function update(UpdateBannerRequest $request, Banner $banner): AdminBannerResource
    {
        $banner = $this->service->update($banner, $request->validated(), $request->file('image'));
        $this->attachLinkLabels(collect([$banner]));

        return new AdminBannerResource($banner);
    }

    public function toggle(Request $request, Banner $banner): AdminBannerResource
    {
        $data = $request->validate(['is_active' => ['sometimes', 'required', 'boolean']]);
        $banner = $this->service->setActive($banner, array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null);
        $this->attachLinkLabels(collect([$banner]));

        return new AdminBannerResource($banner);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'position' => ['required', Rule::enum(BannerPosition::class)],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $this->service->reorder($data['position'], $data['ids']);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function destroy(Banner $banner): JsonResponse
    {
        $this->service->delete($banner);

        return response()->json(null, 204);
    }

    /** @param Collection<int, Banner> $banners */
    private function attachLinkLabels(Collection $banners): void
    {
        $idsFor = fn (BannerLinkType $t) => $banners->filter(fn ($b) => $b->link_type === $t)
            ->pluck('link_value')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $label = fn ($m) => $m ? (trim((string) ($m->name_en ?? '')) ?: $m->name_ar) : null;
        $products = ($ids = $idsFor(BannerLinkType::Product)) === [] ? collect() : Product::whereIn('id', $ids)->get()->keyBy('id');
        $categories = ($ids = $idsFor(BannerLinkType::Category)) === [] ? collect() : Category::whereIn('id', $ids)->get()->keyBy('id');
        $brands = ($ids = $idsFor(BannerLinkType::Brand)) === [] ? collect() : Brand::whereIn('id', $ids)->get()->keyBy('id');
        $banners->each(function ($b) use ($products, $categories, $brands, $label): void {
            $b->setAttribute('link_label', match ($b->link_type) {
                BannerLinkType::Product => $label($products->get((int) $b->link_value)),
                BannerLinkType::Category => $label($categories->get((int) $b->link_value)),
                BannerLinkType::Brand => $label($brands->get((int) $b->link_value)),
                BannerLinkType::External => $b->link_value,
                default => null,
            });
        });
    }
}

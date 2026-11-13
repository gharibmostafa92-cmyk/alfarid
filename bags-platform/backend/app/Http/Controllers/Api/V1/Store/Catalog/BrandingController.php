<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Catalog;

use App\Http\Controllers\Controller;
use App\Services\Shared\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class BrandingController extends Controller
{
    public function show(SettingService $settings): JsonResponse
    {
        $data = (string) config('cache.default') === 'array'
            ? $settings->publicBranding()
            : Cache::flexible(
                SettingService::BRANDING_CACHE_KEY,
                [60, 300],
                fn (): array => $settings->publicBranding(),
                ['seconds' => 30]
            );

        return response()->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}

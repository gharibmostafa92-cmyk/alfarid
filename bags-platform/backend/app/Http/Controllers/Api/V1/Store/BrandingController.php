<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;

class BrandingController extends Controller
{
    public function show(SettingService $settings): JsonResponse
    {
        return response()->json(['data' => $settings->publicBranding()])
            ->header('Cache-Control', 'no-store');
    }
}

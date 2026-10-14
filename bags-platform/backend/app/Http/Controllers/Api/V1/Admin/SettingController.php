<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getAllGrouped(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $this->settings->updateBatch($request->validated());

        return response()->json([
            'data' => $this->settings->getAllGrouped(),
        ]);
    }
}

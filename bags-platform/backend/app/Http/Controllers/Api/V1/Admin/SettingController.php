<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class SettingController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getAllGrouped(),
            'warnings' => $this->settings->warnings,
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $expected = $validated['expected'];
            unset($validated['expected']);
            // L6: ميّز قناة التعديل في التدقيق (rest مقابل ai).
            $this->settings->updateBatch($validated, $expected, ['channel' => 'rest']);
        } catch (InvalidArgumentException) {
            return response()->json([
                'message' => 'إعداد أو أكثر غير مهيأ في قاعدة البيانات. شغّل Seeder الإعدادات ثم أعد المحاولة.',
            ], 409);
        }

        return response()->json([
            'data' => $this->settings->getAllGrouped(),
            'warnings' => $this->settings->warnings,
        ]);
    }
}

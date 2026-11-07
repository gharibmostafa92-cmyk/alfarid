<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Backoffice administration of system-wide configuration keys and tool activation registries.
 */
class SettingController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * Retrieve all system settings grouped by domain with validation warnings.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getAllGrouped(),
            'warnings' => $this->settings->warnings,
        ]);
    }

    /**
     * Update a batch of system settings under optimistic locking.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $expected = $validated['expected'];
            unset($validated['expected']);
            // Distinguish REST API mutations from AI assistant executions in audit logs.
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

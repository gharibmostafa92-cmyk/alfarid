<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\UpdateSettingsRequest;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function index(Request $request, BranchAccess $branchAccess): JsonResponse
    {
        $user = $request->user('staff');
        abort_unless($user instanceof User, 403);
        $branchAccess->assertCentralAccess($user);

        return response()->json([
            'data' => $this->visibleSettings($user),
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
            'data' => $this->visibleSettings($request->user('staff')),
            'warnings' => $this->settings->warnings,
        ]);
    }

    /**
     * Strip AI configuration values from users without the dedicated AI settings permission.
     *
     * @return array<string, array<string, mixed>>
     */
    private function visibleSettings(?User $user): array
    {
        $data = $this->settings->getAllGrouped();
        if ($user?->can('ai_agent.settings')) {
            return $data;
        }

        unset($data['ai']);

        return array_map(
            static fn (array $values): array => array_filter(
                $values,
                static fn (string $key): bool => ! str_starts_with($key, 'ai_'),
                ARRAY_FILTER_USE_KEY,
            ),
            $data,
        );
    }
}

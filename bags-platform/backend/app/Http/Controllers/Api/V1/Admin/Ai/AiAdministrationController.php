<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Models\AiMessage;
use App\Services\Ai\ToolRegistry;
use App\Services\BranchAccess;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiAdministrationController extends Controller
{
    public function settings(Request $request, SettingService $settings, ToolRegistry $registry, BranchAccess $branches): JsonResponse
    {
        $branches->assertCentralAccess($request->user('staff'));

        return response()->json(['data' => $settings->getAllGrouped()['ai'] ?? [], 'tools' => array_keys($registry->definitions())]);
    }

    public function updateSettings(Request $request, SettingService $settings, BranchAccess $branches): JsonResponse
    {
        $branches->assertCentralAccess($request->user('staff'));
        $rules = (new UpdateSettingsRequest)->rules();
        $allowed = array_filter($rules, fn ($key) => str_starts_with($key, 'ai_') || str_starts_with($key, 'expected.ai_') || $key === 'expected', ARRAY_FILTER_USE_KEY);
        abort_if(array_diff(array_keys($request->all()), ['expected', ...array_filter(array_keys($allowed), fn ($key) => str_starts_with($key, 'ai_') && ! str_contains($key, '.'))]) !== [], 422);
        $data = $request->validate($allowed);
        $expected = $data['expected'];
        unset($data['expected']);
        abort_unless(array_diff(array_keys($data), array_keys($expected)) === [] && array_diff(array_keys($expected), array_keys($data)) === [], 422, 'القيم الأصلية مطلوبة لكل إعداد معدل.');
        DB::transaction(function () use ($request, $settings, $data, $expected): void {
            StaffAdministration::lock();
            StaffAdministration::authorize($request->user('staff'), ['ai_agent.settings']);
            $settings->updateBatch($data, $expected);
        });

        return response()->json(['data' => $settings->getAllGrouped()['ai'] ?? []]);
    }

    public function usage(Request $request): JsonResponse
    {
        $actor = $request->user('staff');
        $all = $request->boolean('all') && $actor->can('ai_agent.logs.view');
        $rows = AiMessage::query()->whereHas('conversation', function ($q) use ($actor, $all): void {
            $q->withTrashed();
            if (! $all) {
                $q->where('user_id', $actor->id);
            }
        })->where('created_at', '>=', now()->subDays(30))->selectRaw('DATE(created_at) as day, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')->groupByRaw('DATE(created_at)')->orderByDesc('day')->get();

        return response()->json(['data' => $rows]);
    }
}

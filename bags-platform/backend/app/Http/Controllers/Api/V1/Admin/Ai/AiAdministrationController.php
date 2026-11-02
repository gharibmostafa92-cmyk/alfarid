<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Services\Ai\ToolRegistry;
use App\Services\BranchAccess;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
        try {
            DB::transaction(function () use ($request, $settings, $data, $expected): void {
                StaffAdministration::lock();
                StaffAdministration::authorize($request->user('staff'), ['ai_agent.settings']);
                // L-5: قناة التدقيق (ai) لا تُترك فارغة كما في SettingController.
                $settings->updateBatch($data, $expected, ['channel' => 'ai']);
            });
        } catch (InvalidArgumentException) {
            return response()->json([
                'message' => 'إعداد أو أكثر غير مهيأ في قاعدة البيانات. شغّل Seeder الإعدادات ثم أعد المحاولة.',
            ], 409);
        }

        return response()->json(['data' => $settings->getAllGrouped()['ai'] ?? []]);
    }

    public function usage(Request $request, BranchAccess $branches): JsonResponse
    {
        $actor = $request->user('staff');
        $all = $request->boolean('all') && $actor->can('ai_agent.logs.view');
        // L-4: all=1 يكشف استهلاك المؤسسة كاملة — يتطلب وصولًا مركزيًا.
        if ($all) {
            $branches->assertCentralAccess($actor);
        }
        $since = now(config('app.business_timezone', 'Africa/Cairo'))->startOfDay()->subDays(29)->setTimezone(config('app.timezone'));
        $rows = AiMessage::query()->whereHas('conversation', function ($q) use ($actor, $all): void {
            $q->withTrashed();
            if (! $all) {
                $q->where('user_id', $actor->id);
            }
        })->where('created_at', '>=', $since)
            // L-6: نفس يوم القاهرة اللي guard() بيحسب بيه الميزانية.
            ->selectRaw('DATE((created_at AT TIME ZONE ?) AT TIME ZONE ?) as day, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens', [
                (string) config('app.timezone', 'UTC'), (string) config('app.business_timezone', 'Africa/Cairo'),
            ])
            ->groupBy('day')->orderByDesc('day')->get();

        // 6.3: إحصائيات الأدوات ونسبة التأكيد/الإلغاء ونسبة الـ 409
        $actionsQuery = AiPendingAction::query()
            ->where('created_at', '>=', $since)
            ->when(! $all, fn ($q) => $q->where('user_id', $actor->id));

        $actionRows = (clone $actionsQuery)
            ->selectRaw('tool, status, COUNT(*) as count')
            ->groupBy('tool', 'status')
            ->get();

        $byTool = [];
        $totalProposals = 0;
        $totalExecuted = 0;
        $totalCancelled = 0;
        $totalFailed = 0;

        foreach ($actionRows as $r) {
            $t = (string) $r->tool;
            $st = (string) $r->status;
            $c = (int) $r->count;
            $byTool[$t] ??= ['tool' => $t, 'total' => 0, 'executed' => 0, 'cancelled' => 0, 'failed' => 0, 'pending' => 0];
            $byTool[$t]['total'] += $c;
            $totalProposals += $c;
            if (in_array($st, ['executed', 'undone'], true)) {
                $byTool[$t]['executed'] += $c;
                $totalExecuted += $c;
            } elseif ($st === 'cancelled') {
                $byTool[$t]['cancelled'] += $c;
                $totalCancelled += $c;
            } elseif ($st === 'failed') {
                $byTool[$t]['failed'] += $c;
                $totalFailed += $c;
            } else {
                $byTool[$t]['pending'] += $c;
            }
        }

        $toolMetrics = array_values(array_map(function (array $item): array {
            $decided = $item['executed'] + $item['cancelled'];
            $cancelRate = $decided > 0 ? round(($item['cancelled'] / $decided) * 100, 1) : 0.0;
            $confirmRate = $decided > 0 ? round(($item['executed'] / $decided) * 100, 1) : 0.0;

            return $item + [
                'confirm_rate' => $confirmRate,
                'cancel_rate' => $cancelRate,
                'needs_attention' => $decided >= 3 && $cancelRate > 40.0,
            ];
        }, $byTool));

        usort($toolMetrics, fn ($a, $b) => $b['total'] <=> $a['total']);

        $decidedTotal = $totalExecuted + $totalCancelled;

        return response()->json([
            'data' => $rows,
            'tool_stats' => [
                'total_proposals' => $totalProposals,
                'executed_count' => $totalExecuted,
                'cancelled_count' => $totalCancelled,
                'failed_409_count' => $totalFailed,
                'confirm_rate' => $decidedTotal > 0 ? round(($totalExecuted / $decidedTotal) * 100, 1) : 0.0,
                'cancel_rate' => $decidedTotal > 0 ? round(($totalCancelled / $decidedTotal) * 100, 1) : 0.0,
                'conflict_409_rate' => $totalProposals > 0 ? round(($totalFailed / $totalProposals) * 100, 1) : 0.0,
                'tools' => $toolMetrics,
            ],
        ]);
    }
}

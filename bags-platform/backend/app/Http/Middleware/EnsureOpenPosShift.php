<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Finance\PosShiftStatus;
use App\Models\PosShift;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated cashier has an active, open shift at their assigned branch.
 * Injects the open shift model into request attributes under 'pos_shift'.
 */
class EnsureOpenPosShift
{
    /**
     * Handle incoming POS request and verify active shift presence.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('staff');

        if (! $user instanceof User || $user->branch_id === null) {
            return response()->json([
                'message' => 'لا يمكن استخدام نقطة البيع بدون ارتباط بفرع.',
            ], 403);
        }

        $shifts = PosShift::query()
            ->where('user_id', $user->id)
            ->where('branch_id', $user->branch_id)
            ->where('status', PosShiftStatus::Open)
            ->orderBy('id')
            ->get();

        if ($shifts->isEmpty()) {
            return response()->json([
                'message' => 'لا توجد وردية مفتوحة. افتح وردية أولًا لإتمام العملية.',
                'code'    => 'no_open_shift',
            ], 403);
        }

        if ($shifts->count() > 1) {
            // M5/H3: never silently pick one of several open shifts (the
            // partial unique index normally forbids this; when it is missing
            // — e.g. an unapplied production migration — fail loudly instead
            // of posting sales to an arbitrary drawer).
            return response()->json([
                'message' => 'يوجد أكثر من وردية مفتوحة. أغلق الورديات الزائدة قبل إتمام العملية.',
                'code'    => 'multiple_open_shifts',
            ], 409);
        }

        $request->attributes->set('pos_shift', $shifts->first());

        return $next($request);
    }
}

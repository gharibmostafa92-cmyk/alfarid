<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PosShiftStatus;
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

        $shift = PosShift::query()
            ->where('user_id', $user->id)
            ->where('branch_id', $user->branch_id)
            ->where('status', PosShiftStatus::Open)
            ->latest('id')
            ->first();

        if ($shift === null) {
            return response()->json([
                'message' => 'لا توجد وردية مفتوحة. افتح وردية أولًا لإتمام العملية.',
                'code'    => 'no_open_shift',
            ], 403);
        }

        $request->attributes->set('pos_shift', $shift);

        return $next($request);
    }
}

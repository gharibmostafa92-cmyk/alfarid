<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 5.2: كارت الترحيب الصباحي — بيتبني في PHP بالكامل، من غير أي نداء لـ Gemini (صفر توكنز). */
class AiWelcomeController extends Controller
{
    public function show(Request $request, ToolRegistry $registry): JsonResponse
    {
        return response()->json(['data' => $registry->welcomeBlock($request->user('staff'))]);
    }
}
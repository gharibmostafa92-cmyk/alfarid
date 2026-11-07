<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Morning welcome summary card rendered natively in PHP without LLM token cost.
 */
class AiWelcomeController extends Controller
{
    /**
     * Return staff member personalized daily welcome overview.
     */
    public function show(Request $request, ToolRegistry $registry): JsonResponse
    {
        return response()->json(['data' => $registry->welcomeBlock($request->user('staff'))]);
    }
}
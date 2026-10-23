<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keep historical resale records intact while retiring their operating endpoints. */
class ManufacturingBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/supplier', 'api/v1/supplier/*',
            'api/v1/admin/brands', 'api/v1/admin/brands/*',
            'api/v1/store/brands',
            'api/v1/admin/suppliers', 'api/v1/admin/suppliers/*',
            'api/v1/admin/supplier-*', 'api/v1/admin/purchase-*',
            'api/v1/admin/reports/suppliers')) {
            return response()->json(['message' => 'هذا الجزء متوقف؛ منتجات الفريد تصنيعنا، والتجار يُدارون من العملاء والجملة.'], 410);
        }

        return $next($request);
    }
}

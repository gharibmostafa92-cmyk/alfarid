<?php

use App\Http\Controllers\HealthReadyController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureCustomerAuthenticated;
use App\Http\Middleware\EnsureOpenPosShift;
use App\Http\Middleware\EnsureStaffAuthenticated;
use App\Http\Middleware\EnsureSupplierAuthenticated;
use App\Http\Middleware\EnsureWholesaleApproved;
use App\Http\Middleware\LimitPaymentWebhookBody;
use App\Http\Middleware\ManufacturingBusiness;
use App\Http\Middleware\WarnUntrustedProxyHeaders;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            foreach ([
                'adjustment', 'attachment', 'banner', 'branch', 'brand', 'category', 'conversation',
                'coupon', 'customer', 'customer_group', 'delivery', 'document', 'expense',
                'held_sale', 'identity', 'invoice', 'item', 'location', 'media', 'notice', 'order',
                'payment_notice', 'price_list', 'product', 'promotion', 'purchase_order',
                'purchase_request', 'quotation', 'return_item', 'return_request', 'role', 'sale',
                'shift', 'shipping_company', 'shipping_rate', 'shipping_zone', 'stocktake',
                'supplier', 'supplier_invoice', 'supplier_payment', 'transfer', 'treasury',
                'treasuryTransfer', 'treasury_transfer', 'user', 'variant',
            ] as $parameter) {
                Route::pattern($parameter, '[0-9]{1,18}');
            }
            Route::pattern('action', '[0-9A-Za-z]{26}');

            Route::get('health/ready', HealthReadyController::class)->middleware('throttle:health-ready');
            Route::middleware('api')
                ->prefix('api/v1/admin')
                ->group(base_path('routes/api/admin.php'));
            Route::middleware('api')
                ->prefix('api/v1/store')
                ->name('store.')
                ->group(base_path('routes/api/store.php'));
            Route::middleware('api')
                ->prefix('api/v1/wholesale')
                ->name('wholesale.')
                ->group(base_path('routes/api/wholesale.php'));
            // Supplier portal: authenticate supplier_users; queries scoped to user supplier
            Route::middleware('api')
                ->prefix('api/v1/supplier')
                ->name('supplier.')
                ->group(base_path('routes/api/supplier.php'));
            // External webhooks: unauthenticated; protected by HMAC signature verification in controllers
            Route::middleware('api')
                ->prefix('api/v1/webhooks')
                ->name('webhooks.')
                ->group(base_path('routes/api/webhooks.php'));
            // POS portal: branch cashier; staff token + active shift required for sales operations
            Route::middleware('api')
                ->prefix('api/v1/pos')
                ->name('pos.')
                ->group(base_path('routes/api/pos.php'));
            // Unified authentication: users -> supplier_users -> customers with staff session handoff
            Route::middleware('api')
                ->prefix('api/v1/auth')
                ->name('unified-auth.')
                ->group(base_path('routes/api/auth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(LimitPaymentWebhookBody::class);
        // Resolve trusted forwarding headers before recording client_ip.
        $middleware->append([AssignRequestId::class, WarnUntrustedProxyHeaders::class]);
        $middleware->api(prepend: [ManufacturingBusiness::class]);
        $middleware->alias([
            'auth.staff' => EnsureStaffAuthenticated::class,
            'auth.customer' => EnsureCustomerAuthenticated::class,
            'auth.supplier' => EnsureSupplierAuthenticated::class,
            'wholesale.approved' => EnsureWholesaleApproved::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'pos.shift' => EnsureOpenPosShift::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $e) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            if ($state === '57014' || str_starts_with($state, '08')) {
                // M-7: keep it out of the error tracker but make runaway statements diagnosable (no bindings).
                logger()->warning('Database operation unavailable.', [
                    'sqlstate' => $state,
                    'connection' => $e->getConnectionName(),
                    'sql' => mb_substr($e->getSql(), 0, 2000),
                ]);

                return false;
            }

            return null;
        });
        $exceptions->render(function (QueryException $e, Request $request) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            // M1: carriers treat 4xx as final, so every database failure on a
            // webhook path must be transient (503) and retried — except a
            // caller-supplied duplicate (23505), which would never succeed.
            if ($request->is('api/v1/webhooks/*')
                && ! $e instanceof UniqueConstraintViolationException) {
                return response()->json(['message' => 'تعذر معالجة الإشعار مؤقتًا؛ ستتم إعادة المحاولة تلقائيًا.'], 503)
                    ->header('Retry-After', '5');
            }
            if (($request->is('api/*') || $request->expectsJson())
                && ($state === '57014' || str_starts_with($state, '08'))) {
                $response = response()->json(['message' => 'الخدمة غير متاحة مؤقتًا؛ أعد المحاولة بعد لحظات.'], 503);
                if ($request->isMethodSafe() || filled($request->header('Idempotency-Key'))) {
                    $response->header('Retry-After', $state === '57014' ? (string) random_int(5, 15) : '1');
                }

                return $response;
            }
            // Map concurrency errors (lock timeout 55P03, deadlock 40P01, serialization 40001):
            // return 503 with Retry-After for safe methods and webhooks, 409 for non-idempotent writes.
            if (($request->is('api/*') || $request->expectsJson())
                && in_array($e->errorInfo[0] ?? $e->getCode(), ['55P03', '40P01', '40001'], true)) {
                // Carrier webhooks are idempotent and must be retried. A 409 would be treated as a final client error.
                $status = $request->isMethodSafe() || $request->is('api/v1/webhooks/*') ? 503 : 409;

                return response()->json(['message' => 'العملية مشغولة بطلب آخر؛ أعد المحاولة بعد لحظات.'], $status)->header('Retry-After', '1');
            }

            // Return 409 instead of 500 when foreign key delete restrictions (23503) are violated by related records.
            if (($request->is('api/*') || $request->expectsJson())
                && in_array($state, ['23503', '23001'], true)) {
                return response()->json(['message' => 'تعذر تنفيذ العملية لوجود سجلات مرتبطة أو لتغيّر السجل المرجعي؛ حدّث البيانات وأعد المحاولة.'], 409);
            }

            // M-7: CHECK violations (e.g. coupon usage race) are a conflict, not an internal error.
            if (($request->is('api/*') || $request->expectsJson())
                && ($e->errorInfo[0] ?? $e->getCode()) === '23514') {
                return response()->json(['message' => 'تعذر تنفيذ العملية لأنها تخالف قيدًا على البيانات؛ حدّث البيانات وأعد المحاولة.'], 409);
            }

            // M5: SQLSTATE class 22 is a data exception (bad numeric/text input,
            // invalid encoding) and is the caller's fault, not a server fault.
            if (($request->is('api/*') || $request->expectsJson())
                && ! $e instanceof UniqueConstraintViolationException
                && str_starts_with($state, '22')) {
                return response()->json(['message' => 'قيمة غير صالحة في بيانات الطلب؛ تحقق من الحقول وأعد المحاولة.'], 422);
            }

            if (($request->is('api/*') || $request->expectsJson())
                && ! $e instanceof UniqueConstraintViolationException) {
                return response()->json(['message' => 'تعذر حفظ البيانات بسبب خطأ داخلي؛ أعد المحاولة، وإذا استمرت المشكلة تواصل مع الدعم.'], 500);
            }

            return null;
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($e->getPrevious() instanceof ModelNotFoundException && ($request->is('api/*') || $request->expectsJson())) {
                return response()->json(['message' => 'العنصر المطلوب غير موجود'], 404);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // M6: a 23505 collision is a caller-supplied duplicate (double submit or
        // a concurrent create), not a server fault: report it as a validation error.
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'توجد بيانات متعارضة؛ تحقق من القيم المكررة وأعد المحاولة.',
                    'errors' => ['duplicate' => ['توجد بيانات متعارضة؛ تحقق من القيم المكررة.']],
                ], 422);
            }

            return null;
        });
    })->create();

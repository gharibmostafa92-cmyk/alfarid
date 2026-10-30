<?php

use App\Http\Controllers\HealthReadyController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureCustomerAuthenticated;
use App\Http\Middleware\EnsureOpenPosShift;
use App\Http\Middleware\EnsureStaffAuthenticated;
use App\Http\Middleware\EnsureSupplierAuthenticated;
use App\Http\Middleware\EnsureWholesaleApproved;
use App\Http\Middleware\ManufacturingBusiness;
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
            Route::get('health/ready', HealthReadyController::class);
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
            // بوابة المورد: مصادقة supplier_users وكل الاستعلامات مقيدة بمورد المستخدم
            Route::middleware('api')
                ->prefix('api/v1/supplier')
                ->name('supplier.')
                ->group(base_path('routes/api/supplier.php'));
            // ويب هوكس خارجية: بلا مصادقة — الحماية بالتوقيع الرقمي داخل المتحكمات
            Route::middleware('api')
                ->prefix('api/v1/webhooks')
                ->name('webhooks.')
                ->group(base_path('routes/api/webhooks.php'));
            // بوابة الـ POS: كاشير الفرع — توكن موظف + وردية مفتوحة لعمليات البيع
            Route::middleware('api')
                ->prefix('api/v1/pos')
                ->name('pos.')
                ->group(base_path('routes/api/pos.php'));
            // الدخول الموحد: users ← supplier_users ← customers + handoff للموظفين
            Route::middleware('api')
                ->prefix('api/v1/auth')
                ->name('unified-auth.')
                ->group(base_path('routes/api/auth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Resolve trusted forwarding headers before recording client_ip.
        $middleware->append([AssignRequestId::class]);
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
                logger()->warning('Database operation unavailable.', ['sqlstate' => $state]);

                return false;
            }

            return null;
        });
        $exceptions->render(function (QueryException $e, Request $request) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            if (($request->is('api/*') || $request->expectsJson())
                && ($state === '57014' || str_starts_with($state, '08'))) {
                return response()->json(['message' => 'الخدمة غير متاحة مؤقتًا؛ أعد المحاولة بعد لحظات.'], 503)
                    ->header('Retry-After', '1');
            }
            // F7: أخطاء القفل والتزامن (مهلة قفل 55P03، جمود 40P01، فشل
            // تسلسل 40001) رد مضبوط: 503 للاستعلام الآمن مع Retry-After،
            // و409 للكتابة حتى لا تُعاد طفرة بلا idempotency تلقائيًا.
            if (($request->is('api/*') || $request->expectsJson())
                && in_array($e->errorInfo[0] ?? $e->getCode(), ['55P03', '40P01', '40001'], true)) {
                return response()->json(['message' => 'العملية مشغولة بطلب آخر؛ أعد المحاولة بعد لحظات.'], $request->isMethodSafe() ? 503 : 409)->header('Retry-After', '1');
            }

            // M4: حذف مورد له مستخدمون (restrictOnDelete) يرمي 23503 — رد 409
            // مفهوم بدل 500 في لوحة الإدارة.
            if (($request->is('api/*') || $request->expectsJson())
                && in_array($e->errorInfo[0] ?? $e->getCode(), ['23503'], true)) {
                return response()->json(['message' => 'تعذر تنفيذ العملية لوجود سجلات مرتبطة أو لتغيّر السجل المرجعي؛ حدّث البيانات وأعد المحاولة.'], 409);
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

        // سباق على قيد unique (فحص exists وبعده إدخال متزامن): القيد سليم، فالرد يبقى 409 متحكم فيه بدل 500
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'توجد بيانات متعارضة. أعد المحاولة'], 409);
            }

            return null;
        });
    })->create();

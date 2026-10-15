<?php

namespace App\Providers;

use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Services\BranchAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // scoped لا singleton: ذاكرة المستخدمين لكل طلب تعمل داخل الطلب، ولا
        // تتسرب صلاحيات مستخدم لطلب آخر تحت Octane (L-6).
        $this->app->scoped(BranchAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // أسماء قصيرة ثابتة لمراجع الدفتر (stock_movements.reference_type):
        // إعادة تسمية/نقل الكلاسات لاحقًا لا تكسر أثر التدقيق التاريخي.
        // الصفوف القديمة المخزنة باسم الكلاس الكامل تظل قابلة للقراءة.
        Relation::morphMap([
            'stock_transfer' => StockTransfer::class,
            'stocktake' => Stocktake::class,
        ]);

        RateLimiter::for('admin-login', function (Request $request): array {
            $email = $request->input('email');
            $normalizedEmail = is_string($email)
                ? Str::transliterate(Str::lower(trim($email)))
                : '';
            $emailKey = hash('sha256', $normalizedEmail);

            return [
                Limit::perMinute(5)->by('admin-login-email:'.$emailKey),
                Limit::perHour(30)->by('admin-login-email-hour:'.$emailKey),
                Limit::perMinute(120)->by('admin-login-ip:'.($request->ip() ?? 'unknown')),
            ];
        });
    }
}

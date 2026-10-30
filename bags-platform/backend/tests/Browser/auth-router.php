<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Notifications\CustomerPasswordReset;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

// This router is outside public/ and can only run in the disposable test server.
if (PHP_SAPI !== 'cli-server' || getenv('AUTH_BROWSER_TEST') !== '1'
    || getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'alfarid_testing') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'alfarid_testing') {
    throw new RuntimeException('Browser tests require the testing database.');
}
config([
    'filesystems.disks.r2' => ['driver' => 'local', 'root' => getenv('AUTH_BROWSER_FILES'), 'throw' => true],
    'filesystems.disks.r2_public' => ['driver' => 'local', 'root' => getenv('AUTH_BROWSER_FILES').'/public', 'throw' => true],
]);
if (getenv('AUTH_BROWSER_MODE') !== 'performance') {
    Notification::fake();
}
$request = Request::capture();
$response = $kernel->handle($request);
$phone = $request->input('phone');
if ($response->isSuccessful() && $request->isMethod('POST')
    && $request->is('api/v1/store/auth/forgot-password') && is_string($phone)) {
    $customer = Customer::where('phone', Customer::normalizePhone($phone))->first();
    if ($customer !== null) {
        $notification = Notification::sent($customer, CustomerPasswordReset::class)->first();
        if ($notification !== null) {
            file_put_contents(getenv('AUTH_BROWSER_SMS'), $notification->toSms($customer), LOCK_EX);
        }
    }
}
$response->send();
$kernel->terminate($request, $response);

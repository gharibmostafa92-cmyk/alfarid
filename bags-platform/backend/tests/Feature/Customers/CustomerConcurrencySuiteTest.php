<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Customer\CustomerMessageBudget;
use App\Services\Customer\CustomerPhoneVerificationService;
use Database\Seeders\CustomerPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CustomerConcurrencySuiteTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CustomerPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        foreach (['customers.approve', 'customers.documents.view', 'customers.update', 'customer_groups.create', 'inventory.all_branches'] as $perm) {
            Permission::findOrCreate($perm, 'staff');
        }
        $this->admin = User::factory()->create(['branch_id' => null]);
        $this->admin->givePermissionTo(['customers.approve', 'customers.documents.view', 'customers.update', 'customer_groups.create', 'inventory.all_branches']);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    /**
     * 6 — اعتماد ورفض في نفس اللحظة لنفس التاجر
     */
    public function test_06_concurrent_approve_and_reject_on_same_pending_trader(): void
    {
        $trader = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Pending,
            'company_name' => 'شركة السباق',
            'tax_number' => '111222333',
            'commercial_register' => '444555',
        ]);
        $trader->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'customer_documents/'.$trader->id.'/cr.pdf',
            'original_name' => 'cr.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);

        $approveJob = 'app(App\\Services\\CustomerManagementService::class)->approve(App\\Models\\Customer::findOrFail('.$trader->id.'), [], '.$this->admin->id.');';
        $rejectJob = 'app(App\\Services\\CustomerManagementService::class)->reject(App\\Models\\Customer::findOrFail('.$trader->id.'), "رفض متزامن", '.$this->admin->id.');';

        $outcomes = $this->race([$approveJob, $rejectJob]);

        // If approve wins first -> ['OK', 'REJECTED_422'], final status is approved.
        // If reject wins first -> ['OK', 'OK'], because approve is allowed from rejected status, so final status is still approved!
        $this->assertContains('OK', $outcomes);
        foreach ($outcomes as $o) {
            $this->assertContains($o, ['OK', 'REJECTED_422']);
        }
        $this->assertSame(CustomerStatus::Approved, $trader->fresh()->status);
    }

    /**
     * 10 — تسجيلين متزامنين بنفس الموبايل أو نفس الإيميل
     */
    public function test_10_concurrent_registrations_with_same_phone_or_same_email(): void
    {
        $r2Root = storage_path('framework/testing/disks/r2_race_10');
        File::deleteDirectory($r2Root);
        File::ensureDirectoryExists($r2Root);

        try {
            // 1. Concurrent registrations with same phone -> 1 succeeds, 1 fails with 422 on 'phone', failed files deleted
            $phoneJob = function (string $email) use ($r2Root): string {
                return 'config(["filesystems.disks.r2" => ["driver" => "local", "root" => '.var_export($r2Root, true).', "throw" => true]]);'
                    .'$file = Illuminate\\Http\\UploadedFile::fake()->create("cr.pdf", 20, "application/pdf");'
                    .'app(App\\Services\\WholesaleRegistrationService::class)->register(['
                    .'"name" => "تاجر", "phone" => "01012345678", "email" => '.var_export($email, true).', "password" => "strong-pass-12",'
                    .'"company_name" => "شركة", "tax_number" => "123", "commercial_register" => "456"'
                    .'], [$file], ["commercial_register"]);';
            };

            $outcomesPhone = $this->race([$phoneJob('first@example.com'), $phoneJob('second@example.com')]);
            $this->assertEqualsCanonicalizing(['OK', 'VALIDATION:phone'], $outcomesPhone);
            $this->assertSame(1, Customer::where('phone', '01012345678')->count());
            $this->assertCount(1, File::allFiles($r2Root));

            // 2. Concurrent registrations with same email and different phones -> 1 succeeds, 1 fails with 422 on 'email', failed files deleted
            File::cleanDirectory($r2Root);
            $emailJob = function (string $phone) use ($r2Root): string {
                return 'config(["filesystems.disks.r2" => ["driver" => "local", "root" => '.var_export($r2Root, true).', "throw" => true]]);'
                    .'$file = Illuminate\\Http\\UploadedFile::fake()->create("cr.pdf", 20, "application/pdf");'
                    .'app(App\\Services\\WholesaleRegistrationService::class)->register(['
                    .'"name" => "تاجر", "phone" => '.var_export($phone, true).', "email" => "shared.race@example.com", "password" => "strong-pass-12",'
                    .'"company_name" => "شركة", "tax_number" => "123", "commercial_register" => "456"'
                    .'], [$file], ["commercial_register"]);';
            };

            $outcomesEmail = $this->race([$emailJob('01022223331'), $emailJob('01022223332')]);
            $this->assertEqualsCanonicalizing(['OK', 'VALIDATION:email'], $outcomesEmail);
            $this->assertSame(1, Customer::whereRaw('LOWER(email) = ?', ['shared.race@example.com'])->count());
            $this->assertCount(1, File::allFiles($r2Root));
        } finally {
            File::deleteDirectory($r2Root);
        }
    }

    /**
     * 11 (أ) — حدود رفع المستندات (4 فعّالة ورفع اثنين بالتوازي -> واحد ينجح وواحد 422 وملفه يتمسح)
     */
    public function test_11a_concurrent_document_uploads_at_active_cap_enforce_limit_and_clean_r2(): void
    {
        $r2Root = storage_path('framework/testing/disks/r2_race_11');
        File::deleteDirectory($r2Root);
        File::ensureDirectoryExists($r2Root);

        try {
            $trader = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
            for ($i = 1; $i <= 4; $i++) {
                $trader->documents()->create([
                    'document_type' => CustomerDocumentType::Other,
                    'file_path' => "customer_documents/{$trader->id}/existing_{$i}.pdf",
                    'original_name' => "existing_{$i}.pdf",
                    'mime_type' => 'application/pdf',
                    'status' => CustomerDocumentStatus::Pending,
                ]);
            }

            $uploadJob = 'config(["filesystems.disks.r2" => ["driver" => "local", "root" => '.var_export($r2Root, true).', "throw" => true]]);'
                .'$file = Illuminate\\Http\\UploadedFile::fake()->create("concurrent.pdf", 25, "application/pdf");'
                .'app(App\\Services\\CustomerManagementService::class)->uploadDocument(App\\Models\\Customer::findOrFail('.$trader->id.'), $file, '.$this->admin->id.');';

            $outcomes = $this->race([$uploadJob, $uploadJob]);
            $this->assertEqualsCanonicalizing(['OK', 'REJECTED_422'], $outcomes);
            $this->assertSame(5, $trader->documents()->count());
            // Only the 1 winning uploaded file remains on disk; the loser's file was cleaned up by PrivateAttachments
            $this->assertCount(1, File::allFiles($r2Root));
        } finally {
            File::deleteDirectory($r2Root);
        }
    }

    /**
     * 20 — تسجيلين متزامنين بنفس الـ proof
     */
    public function test_20_concurrent_registrations_with_same_phone_proof_allow_only_one(): void
    {
        Notification::fake();
        config(['auth.require_phone_verification' => true]);
        $phone = '01088887777';
        $service = app(CustomerPhoneVerificationService::class);
        $service->send($phone, CustomerMessageBudget::subnetKey('198.51.100.77'));
        $code = '';
        Notification::assertSentOnDemand(\App\Notifications\CustomerPhoneVerificationCode::class, function ($n, $ch, $notifiable) use (&$code) {
            preg_match('/([0-9]{6})/', $n->toSms($notifiable), $m);
            $code = $m[1];

            return true;
        });
        $proof = $service->verify($phone, $code);

        $job = function (string $regPhone) use ($proof): string {
            return 'config(["auth.require_phone_verification" => true]);'
                .'app(App\\Services\\CustomerAuthService::class)->registerRetail(['
                .'"name" => "عميل", "phone" => '.var_export($regPhone, true).', "password" => "strong-pass-12",'
                .'"phone_verification_token" => '.var_export($proof, true)
                .']);';
        };

        $outcomes = $this->race([$job($phone), $job($phone)]);
        $this->assertSame(1, count(array_filter($outcomes, fn ($o) => $o === 'OK')));
        $this->assertSame(1, Customer::where('phone', $phone)->count());
        $this->assertDatabaseCount('customer_phone_verifications', 0);
    }

    /**
     * 21 — ميزانية الرسائل بالتوازي: مجموع النجاحات مايعدّيش الحد
     */
    public function test_21_concurrent_message_budget_claims_never_exceed_limit(): void
    {
        $netKey = CustomerMessageBudget::subnetKey('203.0.113.99');
        $claimJob = function (int $idx) use ($netKey): string {
            return 'config(['
                .'"auth.customer_sms_daily_limit" => 10,'
                .'"auth.customer_sms_hourly_limit" => 4,'
                .'"auth.customer_sms_verification_fraction" => 0.6,'
                .'"auth.customer_sms_verification_network_hourly_limit" => 3,'
                .'"auth.customer_daily_messages" => 5,'
                .']);'
                .'if (!app(App\\Services\\CustomerMessageBudget::class)->claim("verify:concurrent_'.$idx.'", true, '.var_export($netKey, true).')) {'
                .'    throw new App\\Exceptions\\BusinessException("Budget exceeded");'
                .'}';
        };

        // Verification hourly limit is floor(4 * 0.6) = 2. Race 3 claims concurrently -> exactly 2 OK and 1 REJECTED_422
        $outcomes = $this->race([$claimJob(1), $claimJob(2), $claimJob(3)]);
        $this->assertEqualsCanonicalizing(['OK', 'OK', 'REJECTED_422'], $outcomes);

        $globalDayKey = hash('sha256', 'sms:global:day');
        $this->assertSame(2, (int) DB::table('customer_message_budgets')->where('key', $globalDayKey)->value('sends'));
    }

    /**
     * 23 (هـ) — سباق إنشاء مجموعة عملاء بنفس الكود -> واحد ينجح والتاني 422 على code
     */
    public function test_23e_concurrent_customer_group_creation_with_same_code_returns_validation_error(): void
    {
        $job = 'app(App\\Services\\CustomerGroupService::class)->create(["name" => "مجموعة سباق", "code" => "RACE-GRP"], App\\Models\\User::findOrFail('.$this->admin->id.'));';

        $outcomes = $this->race([$job, $job]);
        $this->assertEqualsCanonicalizing(['OK', 'VALIDATION:code'], $outcomes);
        $this->assertSame(1, CustomerGroup::where('code', 'RACE-GRP')->count());
    }

    /**
     * @param  list<string>  $jobs
     * @return list<string>
     */
    private function race(array $jobs): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'customer-race-');
        unlink($barrier);
        $db = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $db['database'],
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'],
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'RACE_START_FILE' => $barrier,
        ];
        $processes = [];
        try {
            foreach ($jobs as $operation) {
                $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("SET lock_timeout = '7s'");
echo "READY\n";
$deadline = microtime(true) + 12;
while (!is_file(getenv('RACE_START_FILE'))) {
    if (microtime(true) > $deadline) { exit(4); }
    usleep(10000);
}
try {
PHP;
                $script .= $operation;
                $script .= <<<'PHP'
echo "OK\n";
} catch (Illuminate\Validation\ValidationException $exception) {
    $keys = implode(',', array_keys($exception->errors()));
    echo "VALIDATION:{$keys}\n";
} catch (App\Exceptions\BusinessException $exception) {
    echo "REJECTED_".$exception->status."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
                $process = new Process([PHP_BINARY, '-r', $script], base_path(), $environment, timeout: 15);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $process->getErrorOutput());
            }
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = trim(str_replace('READY', '', $process->getOutput()));
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }
}

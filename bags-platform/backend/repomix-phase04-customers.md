This file is a merged representation of a subset of the codebase, containing specifically included files and files not matching ignore patterns, combined into a single document by Repomix.
The content has been processed where line numbers have been added, content has been formatted for parsing in markdown style.

# File Summary

## Purpose
This file contains a packed representation of a subset of the repository's contents that is considered the most important context.
It is designed to be easily consumable by AI systems for analysis, code review,
or other automated processes.

## File Format
The content is organized as follows:
1. This summary section
2. Repository information
3. Directory structure
4. Repository files (if enabled)
5. Multiple file entries, each consisting of:
  a. A header with the file path (## File: path/to/file)
  b. The full contents of the file in a code block

## Usage Guidelines
- This file should be treated as read-only. Any changes should be made to the
  original repository files, not this packed version.
- When processing this file, use the file path to distinguish
  between different files in the repository.
- Be aware that this file may contain sensitive information. Handle it with
  the same level of security as you would the original repository.
- Pay special attention to the Repository Description. These contain important context and guidelines specific to this project.

## Notes
- Some files may have been excluded based on .gitignore rules and Repomix's configuration
- Binary files are not included in this packed representation. Please refer to the Repository Structure section for a complete list of file paths, including binary files
- Only files matching these patterns are included: routes/api/admin.php, routes/api/wholesale.php, bootstrap/app.php, config/auth.php, phpunit.xml, app/Http/Controllers/Controller.php, app/Http/Controllers/Api/V1/Admin/CustomerController.php, app/Http/Controllers/Api/V1/Admin/CustomerGroupController.php, app/Http/Controllers/Api/V1/Wholesale/RegisterController.php, app/Services/CustomerManagementService.php, app/Services/CustomerGroupService.php, app/Services/WholesaleRegistrationService.php, app/Services/CustomerAuthService.php, app/Services/CustomerPhoneVerificationService.php, app/Services/CustomerMessageBudget.php, app/Services/PrivateAttachments.php, app/Services/SearchTerm.php, app/Services/BranchAccess.php, app/Services/StaffAdministration.php, app/Http/Requests/Concerns/NormalizesCustomerInput.php, app/Http/Requests/Admin/Customer/StoreCustomerRequest.php, app/Http/Requests/Admin/Customer/UpdateCustomerRequest.php, app/Http/Requests/Admin/Customer/ApproveCustomerRequest.php, app/Http/Requests/Admin/Customer/RejectCustomerRequest.php, app/Http/Requests/Admin/Customer/SuspendCustomerRequest.php, app/Http/Requests/Admin/Pricing/StoreCustomerGroupRequest.php, app/Http/Requests/Admin/Pricing/UpdateCustomerGroupRequest.php, app/Http/Requests/Wholesale/WholesaleRegisterRequest.php, app/Http/Resources/Admin/CustomerResource.php, app/Http/Resources/Admin/CustomerGroupResource.php, app/Http/Resources/Store/CustomerResource.php, app/Enums/CustomerType.php, app/Enums/CustomerStatus.php, app/Enums/CustomerDocumentType.php, app/Enums/CustomerDocumentStatus.php, app/Enums/StaffRole.php, app/Models/Customer.php, app/Models/CustomerGroup.php, app/Models/CustomerDocument.php, app/Models/CustomerAuditLog.php, app/Models/CustomerAddress.php, app/Models/City.php, app/Models/PriceList.php, app/Models/User.php, app/Models/Concerns/SerializesAccountEmail.php, app/Events/WholesaleApplicationSubmitted.php, app/Events/WholesaleApplicationReviewed.php, app/Listeners/SendWholesaleApplicationSubmitted.php, app/Listeners/SendWholesaleApplicationReviewed.php, app/Notifications/WholesaleApplicationSubmittedNotification.php, app/Notifications/WholesaleApplicationReviewedNotification.php, app/Notifications/CustomerPhoneVerificationCode.php, app/Notifications/Channels/CustomerSmsChannel.php, app/Rules/UniqueCustomerEmail.php, app/Rules/EmailNotUsedByOtherAccountType.php, app/Rules/PasswordByteLimit.php, app/Http/Middleware/EnsureCustomerAuthenticated.php, app/Http/Middleware/EnsureWholesaleApproved.php, app/Http/Middleware/EnsureStaffAuthenticated.php, app/Exceptions/BusinessException.php, app/Modules/Customers/CustomersServiceProvider.php, app/Console/Commands/PruneCustomerArtifacts.php, app/Console/Commands/NormalizeCustomerPhones.php, app/Console/Commands/ValidateCustomerDomains.php, database/migrations/0001_01_01_000000_create_users_table.php, database/migrations/2026_10_14_224514_create_permission_tables.php, database/migrations/2026_10_14_224514_create_personal_access_tokens_table.php, database/migrations/2026_10_15_014601_create_customers_and_supplier_users_tables.php, database/migrations/2026_10_15_032900_add_remember_tokens_to_customer_auth_tables.php, database/migrations/2026_10_15_034200_add_lookup_indexes_to_customer_and_inventory_tables.php, database/migrations/2026_10_15_170001_create_customer_groups_table.php, database/migrations/2026_10_15_170002_add_customer_group_id_to_customers_table.php, database/migrations/2026_10_16_000001_complete_customers_structure.php, database/migrations/2026_10_16_115322_normalize_customer_identity.php, database/migrations/2026_10_16_123837_harden_customer_authentication_and_audit.php, database/migrations/2026_10_16_123839_drop_redundant_customer_email_index.php, database/migrations/2026_10_16_141204_harden_customer_email_and_catalog_limits.php, database/migrations/2026_10_25_000002_validate_customer_constraints.php, database/migrations/2026_11_04_000001_add_review_note_to_customer_documents.php, database/migrations/2026_11_05_000002_enforce_customer_identity_indexes.php, database/factories/CustomerFactory.php, database/factories/UserFactory.php, database/seeders/CustomerPermissionSeeder.php, database/seeders/RolePermissionSeeder.php
- Files matching these patterns are excluded: tests/**, **/*Test.php
- Files matching default ignore patterns are excluded
- Line numbers have been added to the beginning of each line
- Content has been formatted for parsing in markdown style
- Files are sorted by Git change count (files with more changes are at the bottom)

# User Provided Header
Phase 04: Customers & Dealer Approval [P04] - Backend Context for Unit Testing (bags-platform backend)

# Directory Structure
```
app/
  Console/
    Commands/
      NormalizeCustomerPhones.php
      PruneCustomerArtifacts.php
      ValidateCustomerDomains.php
  Enums/
    CustomerDocumentStatus.php
    CustomerDocumentType.php
    CustomerStatus.php
    CustomerType.php
    StaffRole.php
  Events/
    WholesaleApplicationReviewed.php
    WholesaleApplicationSubmitted.php
  Exceptions/
    BusinessException.php
  Http/
    Controllers/
      Api/
        V1/
          Admin/
            CustomerController.php
            CustomerGroupController.php
          Wholesale/
            RegisterController.php
      Controller.php
    Middleware/
      EnsureCustomerAuthenticated.php
      EnsureStaffAuthenticated.php
      EnsureWholesaleApproved.php
    Requests/
      Admin/
        Customer/
          ApproveCustomerRequest.php
          RejectCustomerRequest.php
          StoreCustomerRequest.php
          SuspendCustomerRequest.php
          UpdateCustomerRequest.php
        Pricing/
          StoreCustomerGroupRequest.php
          UpdateCustomerGroupRequest.php
      Concerns/
        NormalizesCustomerInput.php
      Wholesale/
        WholesaleRegisterRequest.php
    Resources/
      Admin/
        CustomerGroupResource.php
        CustomerResource.php
      Store/
        CustomerResource.php
  Listeners/
    SendWholesaleApplicationReviewed.php
    SendWholesaleApplicationSubmitted.php
  Models/
    Concerns/
      SerializesAccountEmail.php
    City.php
    Customer.php
    CustomerAddress.php
    CustomerAuditLog.php
    CustomerDocument.php
    CustomerGroup.php
    PriceList.php
    User.php
  Modules/
    Customers/
      CustomersServiceProvider.php
  Notifications/
    Channels/
      CustomerSmsChannel.php
    CustomerPhoneVerificationCode.php
    WholesaleApplicationReviewedNotification.php
    WholesaleApplicationSubmittedNotification.php
  Rules/
    EmailNotUsedByOtherAccountType.php
    PasswordByteLimit.php
    UniqueCustomerEmail.php
  Services/
    BranchAccess.php
    CustomerAuthService.php
    CustomerGroupService.php
    CustomerManagementService.php
    CustomerMessageBudget.php
    CustomerPhoneVerificationService.php
    PrivateAttachments.php
    SearchTerm.php
    StaffAdministration.php
    WholesaleRegistrationService.php
bootstrap/
  app.php
config/
  auth.php
database/
  factories/
    CustomerFactory.php
    UserFactory.php
  migrations/
    0001_01_01_000000_create_users_table.php
    2026_10_14_224514_create_permission_tables.php
    2026_10_14_224514_create_personal_access_tokens_table.php
    2026_10_15_014601_create_customers_and_supplier_users_tables.php
    2026_10_15_032900_add_remember_tokens_to_customer_auth_tables.php
    2026_10_15_034200_add_lookup_indexes_to_customer_and_inventory_tables.php
    2026_10_15_170001_create_customer_groups_table.php
    2026_10_15_170002_add_customer_group_id_to_customers_table.php
    2026_10_16_000001_complete_customers_structure.php
    2026_10_16_115322_normalize_customer_identity.php
    2026_10_16_123837_harden_customer_authentication_and_audit.php
    2026_10_16_123839_drop_redundant_customer_email_index.php
    2026_10_16_141204_harden_customer_email_and_catalog_limits.php
    2026_10_25_000002_validate_customer_constraints.php
    2026_11_04_000001_add_review_note_to_customer_documents.php
    2026_11_05_000002_enforce_customer_identity_indexes.php
  seeders/
    CustomerPermissionSeeder.php
    RolePermissionSeeder.php
routes/
  api/
    admin.php
    wholesale.php
phpunit.xml
```

# Files

## File: app/Console/Commands/NormalizeCustomerPhones.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Console\Commands;
 6: 
 7: use App\Models\Customer;
 8: use App\Models\CustomerAuditLog;
 9: use Illuminate\Console\Command;
10: use Illuminate\Support\Facades\DB;
11: 
12: class NormalizeCustomerPhones extends Command
13: {
14:     protected $signature = 'customers:normalize-phones {--apply : Apply a collision-free normalization plan in batches}';
15: 
16:     protected $description = 'Check legacy Egyptian mobile numbers for normalization collisions before updating them';
17: 
18:     public function handle(): int
19:     {
20:         // M8: الخطة في transaction قصيرة (الجدول المؤقت ON COMMIT DROP على نفس الاتصال)
21:         // وتتحمّل في الذاكرة؛ بعدين كل batch في transaction مستقلة فالأقفال تتساب دفعة بدفعة.
22:         $plan = DB::transaction(fn (): ?array => $this->buildPlan());
23:         if ($plan === null) {
24:             return self::FAILURE;
25:         }
26:         $this->info(count($plan).' phone numbers need normalization.');
27:         if (! $this->option('apply')) {
28:             return self::SUCCESS;
29:         }
30:         $done = 0;
31:         foreach (array_chunk($plan, 500) as $batch) {
32:             DB::transaction(function () use ($batch): void {
33:                 foreach ($batch as $change) {
34:                     $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($change->id);
35:                     if ($customer->phone !== $change->before) {
36:                         throw new \RuntimeException('A customer changed during normalization; earlier batches are committed — rerun the command to continue.');
37:                     }
38:                     $customer->phone = $change->after;
39:                     $customer->save();
40:                     CustomerAuditLog::record($customer, 'phone_normalized', null);
41:                 }
42:             });
43:             $done += count($batch);
44:             $this->line("Normalized {$done}/".count($plan));
45:         }
46: 
47:         return self::SUCCESS;
48:     }
49: 
50:     /** @return list<object>|null */
51:     private function buildPlan(): ?array
52:     {
53:         DB::statement("CREATE TEMP TABLE customer_phone_normalization ON COMMIT DROP AS
54:             WITH cleaned AS (
55:                 SELECT id, phone AS before, regexp_replace(translate(TRIM(phone),
56:                     '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', '01234567890123456789'), '[[:space:]().-]+', '', 'g') AS value
57:                 FROM customers
58:             )
59:             SELECT id, before, CASE
60:                 WHEN before ~ '^legacy-[0-9]+$' THEN before
61:                 WHEN value ~ '^201[0125][0-9]{8}$' THEN '0' || SUBSTRING(value FROM 3)
62:                 WHEN value LIKE '+20%' THEN '0' || SUBSTRING(value FROM 4)
63:                 WHEN value LIKE '0020%' THEN '0' || SUBSTRING(value FROM 5)
64:                 ELSE value END AS after FROM cleaned");
65:         try {
66:             DB::statement('ALTER TABLE customer_phone_normalization ADD PRIMARY KEY (id)');
67:             $plan = DB::table('customer_phone_normalization');
68:             $invalid = (clone $plan)->whereRaw("after !~ '^(01[0125][0-9]{8}|legacy-[0-9]+)$'")->limit(20)->pluck('id')->all();
69:             $collisions = (clone $plan)->selectRaw('after')->groupBy('after')->havingRaw('COUNT(*) > 1')->limit(20)->pluck('after')->all();
70:             if ($invalid !== [] || $collisions !== []) {
71:                 $this->error('Manual repair required. Invalid customer IDs (first 20): '.json_encode($invalid).'; normalized collisions: '.count($collisions));
72: 
73:                 return null;
74:             }
75: 
76:             return (clone $plan)->whereColumn('before', '!=', 'after')->orderBy('id')->get(['id', 'before', 'after'])->all();
77:         } finally {
78:             DB::statement('DROP TABLE IF EXISTS customer_phone_normalization');
79:         }
80:     }
81: }
```

## File: app/Console/Commands/PruneCustomerArtifacts.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Console\Commands;
  6: 
  7: use App\Models\Customer;
  8: use Illuminate\Console\Command;
  9: use Illuminate\Support\Facades\DB;
 10: use Illuminate\Support\Facades\Storage;
 11: use Throwable;
 12: 
 13: class PruneCustomerArtifacts extends Command
 14: {
 15:     protected $signature = 'customers:prune-artifacts';
 16: 
 17:     protected $description = 'Remove abandoned private staging files, expired phone challenges and old message counters';
 18: 
 19:     public function handle(): int
 20:     {
 21:         $failures = 0;
 22:         $disk = Storage::disk('r2');
 23: 
 24:         // M11: isolate each stage — an R2 exception in the storage sweep must
 25:         // not cancel the DB cleanups below. Customer existence is resolved in
 26:         // batches of 500 instead of one exists() per directory.
 27:         try {
 28:             foreach ($disk->directories('customer_documents/pending') as $directory) {
 29:                 if (! preg_match('~^customer_documents/pending/[a-f0-9-]{36}$~D', $directory)) {
 30:                     continue;
 31:                 }
 32:                 try {
 33:                     $newest = 0;
 34:                     foreach ($disk->allFiles($directory) as $file) {
 35:                         $newest = max($newest, $disk->lastModified($file));
 36:                     }
 37:                     if ($newest < now()->subHour()->timestamp) {
 38:                         $disk->deleteDirectory($directory);
 39:                     }
 40:                 } catch (Throwable $exception) {
 41:                     $failures++;
 42:                     report($exception);
 43:                     $this->error('Could not process '.$directory.'; rerun after checking storage access.');
 44:                 }
 45:             }
 46:         } catch (Throwable $exception) {
 47:             $failures++;
 48:             report($exception);
 49:             $this->error('Pending customer documents listing failed: '.$exception->getMessage());
 50:         }
 51: 
 52:         try {
 53:             $candidateIds = [];
 54:             $dirById = [];
 55:             foreach ($disk->directories('customer_documents') as $directory) {
 56:                 if (preg_match('~^customer_documents/([0-9]+)$~D', $directory, $matches)) {
 57:                     $candidateIds[] = (int) $matches[1];
 58:                     $dirById[(int) $matches[1]] = $directory;
 59:                 }
 60:             }
 61:             $existing = [];
 62:             foreach (array_chunk(array_values(array_unique($candidateIds)), 500) as $chunk) {
 63:                 foreach (Customer::withTrashed()->whereIn('id', $chunk)->pluck('id') as $id) {
 64:                     $existing[(int) $id] = true;
 65:                 }
 66:             }
 67:             foreach ($dirById as $customerId => $directory) {
 68:                 if (isset($existing[$customerId])) {
 69:                     continue;
 70:                 }
 71:                 try {
 72:                     $newest = 0;
 73:                     foreach ($disk->allFiles($directory) as $file) {
 74:                         $newest = max($newest, $disk->lastModified($file));
 75:                     }
 76:                     if ($newest < now()->subHour()->timestamp) {
 77:                         $disk->deleteDirectory($directory);
 78:                     }
 79:                 } catch (Throwable $exception) {
 80:                     $failures++;
 81:                     report($exception);
 82:                     $this->error('Could not process '.$directory.'; rerun after checking storage access.');
 83:                 }
 84:             }
 85:         } catch (Throwable $exception) {
 86:             $failures++;
 87:             report($exception);
 88:             $this->error('Customer documents listing failed: '.$exception->getMessage());
 89:         }
 90: 
 91:         try {
 92:             DB::table('customer_phone_verifications')->where('expires_at', '<', now()->subHour())->delete();
 93:             DB::table('customer_message_budgets')->where('date', '<', now()->subDays(30)->toDateString())->delete();
 94:             DB::table('customer_audit_logs')->whereIn('action', ['login_failed', 'login_succeeded', 'token_issued'])
 95:                 ->where('created_at', '<', now()->subDays(90))
 96:                 ->select('id')->chunkById(1000, function ($rows): void {
 97:                     DB::table('customer_audit_logs')->whereIn('id', $rows->pluck('id'))->delete();
 98:                 });
 99:         } catch (Throwable $exception) {
100:             $failures++;
101:             report($exception);
102:             $this->error('Customer database artifact cleanup failed: '.$exception->getMessage());
103:         }
104: 
105:         $this->info('Expired customer artifacts pruned.');
106: 
107:         return $failures === 0 ? self::SUCCESS : self::FAILURE;
108:     }
109: }
```

## File: app/Console/Commands/ValidateCustomerDomains.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Console\Commands;
 6: 
 7: use Illuminate\Console\Command;
 8: use Illuminate\Database\QueryException;
 9: use Illuminate\Support\Facades\DB;
10: 
11: class ValidateCustomerDomains extends Command
12: {
13:     protected $signature = 'customers:validate-domains';
14: 
15:     protected $description = 'Validate existing customer records against the new domain constraints without blocking normal writes';
16: 
17:     public function handle(): int
18:     {
19:         foreach (['customers_nonnegative_credit', 'customers_approved_business', 'customers_valid_phone'] as $constraint) {
20:             try {
21:                 DB::statement('ALTER TABLE customers VALIDATE CONSTRAINT '.$constraint);
22:             } catch (QueryException $exception) {
23:                 if (($exception->errorInfo[0] ?? null) !== '23514') {
24:                     throw $exception;
25:                 }
26:                 $this->error($constraint.': existing records need manual repair before validation.');
27: 
28:                 return self::FAILURE;
29:             }
30:         }
31:         $this->info('Customer domain constraints validated.');
32: 
33:         return self::SUCCESS;
34:     }
35: }
```

## File: app/Enums/CustomerDocumentStatus.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum CustomerDocumentStatus: string
 8: {
 9:     case Pending = 'pending';
10:     case Verified = 'verified';
11:     case Rejected = 'rejected';
12: }
```

## File: app/Enums/CustomerDocumentType.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum CustomerDocumentType: string
 8: {
 9:     case CommercialRegister = 'commercial_register';
10:     case TaxCard = 'tax_card';
11:     case NationalId = 'national_id';
12:     case Other = 'other';
13: }
```

## File: app/Enums/CustomerStatus.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum CustomerStatus: string
 8: {
 9:     case Pending = 'pending';
10:     case Approved = 'approved';
11:     case Rejected = 'rejected';
12:     case Suspended = 'suspended';
13: }
```

## File: app/Enums/CustomerType.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum CustomerType: string
 8: {
 9:     case Retail = 'retail';
10:     case Wholesale = 'wholesale';
11:     case Distributor = 'distributor';
12: }
```

## File: app/Enums/StaffRole.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum StaffRole: string
 8: {
 9:     case SuperAdmin = 'Super Admin';
10:     case Cashier = 'cashier';
11: 
12:     public static function normalizeName(string $name): string
13:     {
14:         return trim(\Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name);
15:     }
16: 
17:     public static function isReserved(string $name): bool
18:     {
19:         $name = mb_strtolower(self::normalizeName($name));
20:         foreach (self::cases() as $role) {
21:             if ($name === mb_strtolower($role->value)) {
22:                 return true;
23:             }
24:         }
25: 
26:         return false;
27:     }
28: }
```

## File: app/Events/WholesaleApplicationReviewed.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Events;
 6: 
 7: use App\Models\Customer;
 8: use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
 9: use Illuminate\Foundation\Events\Dispatchable;
10: use Illuminate\Queue\SerializesModels;
11: 
12: /** راجعت الإدارة طلب تاجر: decision = approved|rejected. */
13: class WholesaleApplicationReviewed implements ShouldDispatchAfterCommit // M4
14: {
15:     use Dispatchable, SerializesModels;
16: 
17:     public function __construct(
18:         public Customer $customer,
19:         public string $decision,
20:         public ?string $reason = null,
21:     ) {}
22: }
```

## File: app/Events/WholesaleApplicationSubmitted.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Events;
 6: 
 7: use App\Models\Customer;
 8: use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
 9: use Illuminate\Foundation\Events\Dispatchable;
10: use Illuminate\Queue\SerializesModels;
11: 
12: /** سجّل تاجر جملة/موزع جديد وبانتظار مراجعة الإدارة. */
13: class WholesaleApplicationSubmitted implements ShouldDispatchAfterCommit
14: {
15:     use Dispatchable, SerializesModels;
16: 
17:     public function __construct(public Customer $customer) {}
18: }
```

## File: app/Exceptions/BusinessException.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Exceptions;
 6: 
 7: use Exception;
 8: use Illuminate\Http\JsonResponse;
 9: 
10: // الاستثناء اللي بنرميه لما قاعدة من قواعد البيزنس تتكسر، وبيرجّع 422.
11: class BusinessException extends Exception
12: {
13:     public function __construct(
14:         string $message,
15:         public array $errors = [],
16:         public int $status = 422,
17:         public ?string $errorCode = null,
18:     ) {
19:         parent::__construct($message);
20:     }
21: 
22:     public function render(): JsonResponse
23:     {
24:         $payload = ['message' => $this->getMessage(), 'errors' => $this->errors];
25:         if ($this->errorCode !== null) {
26:             // رمز خطأ مميز تتصرف الواجهة على أساسه (مثل coupon_invalid)
27:             $payload['code'] = $this->errorCode;
28:         }
29: 
30:         $response = response()->json($payload, $this->status);
31:         if ($this->status === 503) {
32:             $response->headers->set('Retry-After', '1');
33:         }
34: 
35:         return $response;
36:     }
37: }
```

## File: app/Http/Controllers/Api/V1/Admin/CustomerController.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Http\Controllers\Api\V1\Admin;
  6: 
  7: use App\Enums\CustomerDocumentStatus;
  8: use App\Enums\CustomerStatus;
  9: use App\Enums\CustomerType;
 10: use App\Http\Controllers\Controller;
 11: use App\Http\Requests\Admin\Customer\ApproveCustomerRequest;
 12: use App\Http\Requests\Admin\Customer\RejectCustomerRequest;
 13: use App\Http\Requests\Admin\Customer\StoreCustomerRequest;
 14: use App\Http\Requests\Admin\Customer\SuspendCustomerRequest;
 15: use App\Http\Requests\Admin\Customer\UpdateCustomerRequest;
 16: use App\Http\Resources\Admin\CustomerResource;
 17: use App\Models\Customer;
 18: use App\Models\CustomerAuditLog;
 19: use App\Models\CustomerDocument;
 20: use App\Services\CustomerManagementService;
 21: use Illuminate\Http\JsonResponse;
 22: use Illuminate\Http\Request;
 23: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
 24: use Illuminate\Routing\Controllers\HasMiddleware;
 25: use Illuminate\Routing\Controllers\Middleware;
 26: use Illuminate\Support\Facades\Storage;
 27: use Illuminate\Validation\Rule;
 28: use Symfony\Component\HttpFoundation\StreamedResponse;
 29: 
 30: /** إدارة العملاء واعتماد حسابات الجملة في لوحة الإدارة. */
 31: class CustomerController extends Controller implements HasMiddleware
 32: {
 33:     public function __construct(private readonly CustomerManagementService $customers) {}
 34: 
 35:     public static function middleware(): array
 36:     {
 37:         return [
 38:             new Middleware('can:customers.view', only: ['index', 'show', 'history']),
 39:             new Middleware('can:customers.documents.view', only: ['downloadDocument', 'reviewDocument', 'storeDocument']),
 40:             new Middleware('can:customers.update', only: ['storeDocument']),
 41:             new Middleware('can:customers.create', only: ['store']),
 42:             new Middleware('can:customers.update', only: ['update']),
 43:             new Middleware('can:customers.delete', only: ['destroy', 'restore']),
 44:             new Middleware('can:customers.approve', only: ['approve', 'reject', 'suspend', 'reactivate', 'reviewDocument']),
 45:         ];
 46:     }
 47: 
 48:     public function index(Request $request): AnonymousResourceCollection
 49:     {
 50:         $filters = $request->validate([
 51:             'type' => ['nullable', Rule::enum(CustomerType::class)],
 52:             'business_only' => ['nullable', 'boolean'],
 53:             'status' => ['nullable', Rule::enum(CustomerStatus::class)],
 54:             'customer_group_id' => ['nullable', 'integer', 'min:1'],
 55:             // L-2: أقل من 3 حروف trigram ما يستفيدش من الفهرس → full scan + COUNT(*).
 56:             'search' => ['nullable', 'string', 'min:3', 'max:255'],
 57:             'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
 58:             'trashed' => ['nullable', Rule::in(['only'])],
 59:         ]);
 60: 
 61:         return CustomerResource::collection($this->customers->paginate($filters));
 62:     }
 63: 
 64:     public function store(StoreCustomerRequest $request): JsonResponse
 65:     {
 66:         $customer = $this->customers->create($request->validated(), $request->user()->id);
 67: 
 68:         return CustomerResource::make($customer)
 69:             ->additional(['message' => 'تم إنشاء العميل بنجاح'])
 70:             ->response()
 71:             ->setStatusCode(201);
 72:     }
 73: 
 74:     public function show(Customer $customer): CustomerResource
 75:     {
 76:         return CustomerResource::make($this->customers->find($customer->id));
 77:     }
 78: 
 79:     public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
 80:     {
 81:         return CustomerResource::make($this->customers->update($customer, $request->validated(), $request->user()->id));
 82:     }
 83: 
 84:     public function destroy(Request $request, Customer $customer): JsonResponse
 85:     {
 86:         $this->customers->delete($customer, $request->user()->id);
 87: 
 88:         return response()->json(['message' => 'تم حذف العميل بنجاح']);
 89:     }
 90: 
 91:     public function approve(ApproveCustomerRequest $request, Customer $customer): CustomerResource
 92:     {
 93:         $customer = $this->customers->approve($customer, $request->validated(), $request->user()->id);
 94: 
 95:         return CustomerResource::make($customer)->additional(['message' => 'تم اعتماد الحساب بنجاح']);
 96:     }
 97: 
 98:     public function reject(RejectCustomerRequest $request, Customer $customer): CustomerResource
 99:     {
100:         $customer = $this->customers->reject($customer, $request->validated()['reason'], $request->user()->id);
101: 
102:         return CustomerResource::make($customer)->additional(['message' => 'تم رفض الحساب']);
103:     }
104: 
105:     public function suspend(SuspendCustomerRequest $request, Customer $customer): CustomerResource
106:     {
107:         $customer = $this->customers->suspend($customer, $request->validated()['reason'], $request->user()->id);
108: 
109:         return CustomerResource::make($customer)->additional(['message' => 'تم إيقاف الحساب وإنهاء جلساته']);
110:     }
111: 
112:     public function reactivate(Request $request, Customer $customer): CustomerResource
113:     {
114:         $customer = $this->customers->reactivate($customer, $request->user()->id);
115: 
116:         return CustomerResource::make($customer)->additional(['message' => 'تم إعادة تفعيل الحساب']);
117:     }
118: 
119:     /** سجل تغييرات العميل — الأحدث أولًا، 25 في الصفحة (P04-M01-F05). */
120:     public function history(Customer $customer): JsonResponse
121:     {
122:         $logs = CustomerAuditLog::query()->where('customer_id', $customer->id)->with('actor:id,name')
123:             ->orderByDesc('created_at')->orderByDesc('id')->paginate(25);
124:         $logs->through(fn (CustomerAuditLog $log) => [
125:             'action' => $log->action,
126:             'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
127:             'metadata' => $log->metadata,
128:             'created_at' => $log->created_at,
129:         ]);
130: 
131:         return response()->json($logs);
132:     }
133: 
134:     /** تنزيل مستند تحقق من التخزين الخاص (scoped: المستند تابع لهذا العميل حصرًا). */
135:     public function downloadDocument(Customer $customer, CustomerDocument $document): StreamedResponse
136:     {
137:         abort_unless(Storage::disk('r2')->exists($document->file_path), 404, 'المستند غير موجود.');
138:         CustomerAuditLog::record($customer, 'document_downloaded', request()->user()->id, ['document_id' => $document->id]);
139: 
140:         return Storage::disk('r2')->download($document->file_path, CustomerDocument::safeName($document->original_name));
141:     }
142: 
143:     public function restore(Request $request, Customer $customer): CustomerResource
144:     {
145:         return CustomerResource::make($this->customers->restore($customer, $request->user()->id));
146:     }
147: 
148:     public function reviewDocument(Request $request, Customer $customer, CustomerDocument $document): JsonResponse
149:     {
150:         $data = $request->validate([
151:             'status' => ['required', Rule::enum(CustomerDocumentStatus::class)],
152:             'note' => ['nullable', 'string', 'max:500', 'required_if:status,rejected'],
153:         ]);
154:         $document = $this->customers->reviewDocument($customer, $document, $data['status'], $request->user()->id, $data['note'] ?? null);
155: 
156:         return response()->json(['data' => ['id' => $document->id, 'status' => $document->status, 'review_note' => $document->review_note]]);
157:     }
158: 
159:     public function storeDocument(Request $request, Customer $customer): JsonResponse
160:     {
161:         $request->validate(['document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);
162:         $document = $this->customers->uploadDocument($customer, $request->file('document'), (int) $request->user()->id);
163: 
164:         return response()->json(['data' => ['id' => $document->id, 'status' => $document->status]], 201);
165:     }
166: }
```

## File: app/Http/Controllers/Api/V1/Admin/CustomerGroupController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Http\Requests\Admin\Pricing\StoreCustomerGroupRequest;
 9: use App\Http\Requests\Admin\Pricing\UpdateCustomerGroupRequest;
10: use App\Http\Resources\Admin\CustomerGroupResource;
11: use App\Models\CustomerGroup;
12: use App\Services\BranchAccess;
13: use App\Services\CustomerGroupService;
14: use Illuminate\Http\JsonResponse;
15: use Illuminate\Http\Request;
16: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
17: use Illuminate\Routing\Controllers\HasMiddleware;
18: use Illuminate\Routing\Controllers\Middleware;
19: 
20: class CustomerGroupController extends Controller implements HasMiddleware
21: {
22:     public function __construct(
23:         private readonly CustomerGroupService $groups,
24:         private readonly BranchAccess $branchAccess,
25:     ) {}
26: 
27:     public static function middleware(): array
28:     {
29:         return [
30:             new Middleware('can:customer_groups.view', only: ['index', 'show']),
31:             new Middleware('can:customer_groups.create', only: ['store']),
32:             new Middleware('can:customer_groups.update', only: ['update']),
33:             new Middleware('can:customer_groups.delete', only: ['destroy']),
34:         ];
35:     }
36: 
37:     public function index(Request $request): AnonymousResourceCollection
38:     {
39:         $filters = $request->validate([
40:             'search' => ['nullable', 'string', 'max:100'],
41:             'is_active' => ['nullable', 'boolean'],
42:             'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
43:         ]);
44: 
45:         return CustomerGroupResource::collection($this->groups->paginate($filters));
46:     }
47: 
48:     public function store(StoreCustomerGroupRequest $request): JsonResponse
49:     {
50:         $this->branchAccess->assertCentralAccess($request->user());
51:         $group = $this->groups->create($request->validated(), $request->user());
52: 
53:         return CustomerGroupResource::make($group)
54:             ->additional(['message' => 'تم إنشاء مجموعة العملاء بنجاح'])
55:             ->response()
56:             ->setStatusCode(201);
57:     }
58: 
59:     public function show(CustomerGroup $customerGroup): CustomerGroupResource
60:     {
61:         return CustomerGroupResource::make($customerGroup->loadCount('customers'));
62:     }
63: 
64:     public function update(UpdateCustomerGroupRequest $request, CustomerGroup $customerGroup): CustomerGroupResource
65:     {
66:         $this->branchAccess->assertCentralAccess($request->user());
67: 
68:         return CustomerGroupResource::make($this->groups->update($customerGroup, $request->validated(), $request->user()));
69:     }
70: 
71:     public function destroy(Request $request, CustomerGroup $customerGroup): JsonResponse
72:     {
73:         $this->branchAccess->assertCentralAccess($request->user());
74:         $this->groups->delete($customerGroup, $request->user());
75: 
76:         return response()->json(['message' => 'تم حذف مجموعة العملاء بنجاح']);
77:     }
78: }
```

## File: app/Http/Controllers/Api/V1/Wholesale/RegisterController.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Http\Controllers\Api\V1\Wholesale;
  6: 
  7: use App\Enums\CustomerDocumentStatus;
  8: use App\Enums\CustomerDocumentType;
  9: use App\Enums\CustomerStatus;
 10: use App\Exceptions\BusinessException;
 11: use App\Http\Controllers\Controller;
 12: use App\Http\Requests\Wholesale\WholesaleRegisterRequest;
 13: use App\Http\Resources\Store\CustomerResource;
 14: use App\Models\Customer;
 15: use App\Services\CustomerManagementService;
 16: use App\Services\WholesaleRegistrationService;
 17: use Illuminate\Http\JsonResponse;
 18: use Illuminate\Http\Request;
 19: use Illuminate\Validation\Rule;
 20: 
 21: /** تسجيل تجار الجملة ومتابعة حالة طلب الاعتماد. */
 22: class RegisterController extends Controller
 23: {
 24:     public function __construct(
 25:         private readonly WholesaleRegistrationService $registrations,
 26:     ) {}
 27: 
 28:     public function register(WholesaleRegisterRequest $request): JsonResponse
 29:     {
 30:         $data = $request->validated();
 31:         $registration = $this->registrations->register(
 32:             $data,
 33:             $request->file('documents', []),
 34:             $data['document_types'] ?? [],
 35:         );
 36: 
 37:         return CustomerResource::make($registration['customer'])
 38:             ->additional(['message' => 'تم استلام طلب التسجيل؛ حسابك قيد المراجعة', 'token' => $registration['token']])
 39:             ->response()
 40:             ->setStatusCode(201);
 41:     }
 42: 
 43:     /**
 44:      * حالة الطلب لصاحبه — بما فيه المرفوض (يحتاج سبب الرفض ومستنداته).
 45:      * محمي بمصادقة العملاء فقط؛ لا يحتاج اعتماد الجملة.
 46:      */
 47:     public function applicationStatus(Request $request): JsonResponse
 48:     {
 49:         $customer = $request->user();
 50:         if (! $customer instanceof Customer) {
 51:             throw new BusinessException('هذه النهاية مخصصة لحسابات العملاء.', [], 403);
 52:         }
 53: 
 54:         $customer->load('documents');
 55: 
 56:         return response()->json([
 57:             'data' => [
 58:                 ...CustomerResource::make($customer)->resolve($request),
 59:                 'rejection_reason' => $customer->status === CustomerStatus::Rejected ? $customer->rejection_reason : null,
 60:                 'suspension_reason' => $customer->status === CustomerStatus::Suspended ? $customer->suspension_reason : null,
 61:                 'approved_at' => $customer->approved_at,
 62:                 'documents' => $customer->documents->map(fn ($document) => [
 63:                     'id' => $document->id,
 64:                     'document_type' => $document->document_type,
 65:                     'status' => $document->status,
 66:                     'original_name' => $document->original_name,
 67:                 ])->all(),
 68:                 'rejected_documents' => $customer->documents
 69:                     ->where('status', CustomerDocumentStatus::Rejected)
 70:                     ->map(fn ($document) => [
 71:                         'id' => $document->id,
 72:                         'document_type' => $document->document_type,
 73:                         'original_name' => $document->original_name,
 74:                         'review_note' => $document->review_note,
 75:                     ])->values()->all(),
 76:             ],
 77:         ]);
 78:     }
 79: 
 80:     public function uploadDocument(Request $request, CustomerManagementService $customers): JsonResponse
 81:     {
 82:         $customer = $request->user();
 83:         if (! $customer instanceof Customer) {
 84:             throw new BusinessException('هذا المسار متاح للعملاء فقط.', [], 403);
 85:         }
 86:         $data = $request->validate([
 87:             'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
 88:             'document_type' => ['nullable', Rule::enum(CustomerDocumentType::class)],
 89:         ]);
 90:         $document = $customers->uploadDocument(
 91:             $customer,
 92:             $request->file('document'),
 93:             null,
 94:             isset($data['document_type']) ? CustomerDocumentType::from($data['document_type']) : null,
 95:             selfService: true,
 96:         );
 97: 
 98:         return response()->json(['data' => ['id' => $document->id, 'status' => $document->status]], 201);
 99:     }
100: }
```

## File: app/Http/Controllers/Controller.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers;
 6: 
 7: use App\Exceptions\BusinessException;
 8: use Illuminate\Http\Request;
 9: 
10: abstract class Controller
11: {
12:     /** مفتاح عدم التكرار من الترويسة بعد التحقق من شكله (1–128 حرفًا). */
13:     protected function idempotencyKey(Request $request): ?string
14:     {
15:         $key = $request->header('Idempotency-Key');
16:         if ($key === null) {
17:             return null;
18:         }
19: 
20:         $key = trim($key);
21:         if ($key === '' || strlen($key) > 128) {
22:             throw new BusinessException('قيمة Idempotency-Key غير صالحة (1 إلى 128 حرفًا).');
23:         }
24: 
25:         return $key;
26:     }
27: 
28:     /**
29:      * M2: مفتاح عدم التكرار إلزامي في العمليات المالية-المخزنية (تسوية،
30:      * تحويل، جرد) — إعادة المحاولة بعد timeout بلا مفتاح كانت تكرر الحركة
31:      * وتفسد الأرصدة بصمت.
32:      */
33:     protected function requiredIdempotencyKey(Request $request): string
34:     {
35:         $key = $this->idempotencyKey($request);
36: 
37:         if ($key === null) {
38:             throw new BusinessException('ترويسة Idempotency-Key مطلوبة لهذه العملية؛ أعد الإرسال بنفس المفتاح عند إعادة المحاولة.');
39:         }
40: 
41:         return $key;
42:     }
43: }
```

## File: app/Http/Middleware/EnsureCustomerAuthenticated.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Middleware;
 6: 
 7: use App\Enums\CustomerStatus;
 8: use App\Models\Customer;
 9: use Closure;
10: use Illuminate\Http\Request;
11: use Illuminate\Support\Facades\Auth;
12: use Symfony\Component\HttpFoundation\Response;
13: 
14: class EnsureCustomerAuthenticated
15: {
16:     public function handle(Request $request, Closure $next): Response
17:     {
18:         Auth::shouldUse('customer');
19:         $user = $request->user('customer');
20: 
21:         if ($request->user('sanctum') === null) {
22:             return response()->json([
23:                 'message' => 'يرجى تسجيل الدخول أولًا.',
24:             ], 401);
25:         }
26: 
27:         $canInspectRejection = $request->routeIs('wholesale.auth.application-status', 'store.auth.logout');
28: 
29:         if (! $user instanceof Customer || $user->status === CustomerStatus::Suspended
30:             || ($user->status === CustomerStatus::Rejected && ! $canInspectRejection)) {
31:             return response()->json([
32:                 'message' => 'غير مصرح لك بالدخول كعميل.',
33:             ], 403);
34:         }
35: 
36:         return $next($request);
37:     }
38: }
```

## File: app/Http/Middleware/EnsureStaffAuthenticated.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Middleware;
 6: 
 7: use App\Enums\StaffRole;
 8: use App\Models\Branch;
 9: use Illuminate\Support\Facades\Cache;
10: use App\Models\User;
11: use Closure;
12: use Illuminate\Http\Request;
13: use Illuminate\Support\Facades\Auth;
14: use Symfony\Component\HttpFoundation\Response;
15: 
16: class EnsureStaffAuthenticated
17: {
18:     public function handle(Request $request, Closure $next): Response
19:     {
20:         Auth::shouldUse('staff');
21:         $user = $request->user('staff');
22: 
23:         if ($request->user('sanctum') === null) {
24:             return response()->json([
25:                 'message' => 'يرجى تسجيل الدخول أولًا.',
26:             ], 401);
27:         }
28: 
29:         if (! $user instanceof User || ! $user->is_active || $user->is_system) {
30:             return response()->json([
31:                 'message' => 'غير مصرح لك بالدخول كموظف أو الحساب غير نشط.',
32:             ], 403);
33:         }
34: 
35:         // L8: كلمة المرور المؤقتة يجب تدويرها في أول دخول — تُسمح فقط
36:         // مشاهدة الحساب وتغيير كلمة السر الذاتي وتسجيل الخروج حتى التدوير.
37:         if ((bool) $user->must_change_password && ! $this->isPasswordRotationRequest($request, $user)) {
38:             return response()->json([
39:                 'message' => 'يجب تغيير كلمة المرور المؤقتة قبل المتابعة.',
40:                 'code' => 'password_change_required',
41:             ], 403);
42:         }
43: 
44:         $token = $user->currentAccessToken();
45:         if ($token !== null && ! $token->can('*')
46:             && ! ($request->is('api/v1/pos/*') && $token->can('pos'))) {
47:             return response()->json(['message' => 'التوكن غير مصرح له بهذه الواجهة.'], 403);
48:         }
49: 
50:         // Staff assigned to a deactivated branch are fail-closed everywhere
51:         // except password rotation and logout, mirroring BranchAccess.
52:         if (! $this->isPasswordRotationRequest($request, $user)
53:             && ! $request->is('api/v1/admin/auth/logout') && ! $request->is('api/v1/auth/logout')
54:             && ! $user->hasRole(StaffRole::SuperAdmin->value, 'staff')
55:             && ! $user->can('inventory.all_branches')
56:             && $user->branch_id !== null
57:             && ! $this->branchIsActive((int) $user->branch_id)) {
58:             return response()->json(['message' => 'الفرع المرتبط بحسابك متوقف حالياً.'], 403);
59:         }
60: 
61:         return $next($request);
62:     }
63: 
64:     /** L6: كاش 30 ثانية؛ تعطيل الفرع بياخد لحد 30 ثانية عشان يسري. */
65:     private function branchIsActive(int $branchId): bool
66:     {
67:         return (bool) Cache::remember('branch-active:'.$branchId, 30, fn () => (bool) Branch::query()->whereKey($branchId)->value('is_active'));
68:     }
69: 
70:     private function isPasswordRotationRequest(Request $request, User $user): bool
71:     {
72:         // Read-only self views, own password rotation, and logout stay usable.
73:         if ($request->isMethod('GET') && $request->is('api/v1/admin/me')) {
74:             return true;
75:         }
76:         if (in_array($request->method(), ['PUT', 'PATCH'], true) && $request->is('api/v1/admin/me/password')) {
77:             return true;
78:         }
79:         if ($request->is('api/v1/admin/auth/logout') || $request->is('api/v1/auth/logout')) {
80:             return true;
81:         }
82:         $routeUser = $request->route('user');
83:         $targetId = $routeUser instanceof User ? $routeUser->getKey() : $routeUser;
84: 
85:         // L-1: عدّ الأجزاء كان 4 دائمًا، لكن مسار api/v1/admin/users/5
86:         // فيه 5 أجزاء فالشرط عمره ما تحقق وأي تدوير عبر PUT users/{id}
87:         // كان يأخذ 403 password_change_required. المطابقة باسم المسار
88:         // أدق (users/{user} فقط — لا مسارات فرعية مثل users/{user}/roles).
89:         // L-8: مطابقة المسار بدقة (users/{user} فقط) — لا أي مسار مستقبلي
90:         // مثل users/{user}/roles يُستثنى تلقائيًا.
91:         return in_array($request->method(), ['PUT', 'PATCH'], true)
92:             && $request->routeIs('admin.users.update')
93:             && (int) $targetId === (int) $user->getKey();
94:     }
95: }
```

## File: app/Http/Middleware/EnsureWholesaleApproved.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Middleware;
 6: 
 7: use App\Enums\CustomerStatus;
 8: use App\Enums\CustomerType;
 9: use App\Models\Customer;
10: use Closure;
11: use Illuminate\Http\Request;
12: use Illuminate\Support\Facades\Auth;
13: use Symfony\Component\HttpFoundation\Response;
14: 
15: class EnsureWholesaleApproved
16: {
17:     public function handle(Request $request, Closure $next): Response
18:     {
19:         Auth::shouldUse('customer');
20:         $user = $request->user('customer');
21: 
22:         if ($request->user('sanctum') === null) {
23:             return response()->json([
24:                 'message' => 'يرجى تسجيل الدخول أولًا.',
25:             ], 401);
26:         }
27: 
28:         if (
29:             ! $user instanceof Customer
30:             || ! in_array($user->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
31:             || $user->status !== CustomerStatus::Approved
32:         ) {
33:             return response()->json([
34:                 'message' => 'هذا القسم مخصص لتجار الجملة المعتمدين فقط.',
35:             ], 403);
36:         }
37: 
38:         return $next($request);
39:     }
40: }
```

## File: app/Http/Requests/Admin/Customer/ApproveCustomerRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Customer;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: use Illuminate\Validation\Rule;
 9: 
10: class ApproveCustomerRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return true;
15:     }
16: 
17:     /**
18:      * @return array<string, mixed>
19:      */
20:     public function rules(): array
21:     {
22:         return [
23:             'customer_group_id' => ['nullable', 'integer', Rule::exists('customer_groups', 'id')->where('is_active', true)],
24:             'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
25:             // الاعتماد يبقي النوع أو يرقّيه لموزع — لا يحوّل لقطاعي
26:             'type' => ['nullable', Rule::in(['wholesale', 'distributor'])],
27:             'company_name' => ['sometimes', 'string', 'max:255'],
28:             'tax_number' => ['sometimes', 'string', 'max:50'],
29:             'commercial_register' => ['sometimes', 'string', 'max:50'],
30:         ];
31:     }
32: }
```

## File: app/Http/Requests/Admin/Customer/RejectCustomerRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Customer;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: 
 9: class RejectCustomerRequest extends FormRequest
10: {
11:     public function authorize(): bool
12:     {
13:         return true;
14:     }
15: 
16:     /**
17:      * @return array<string, mixed>
18:      */
19:     public function rules(): array
20:     {
21:         return [
22:             'reason' => ['required', 'string', 'max:2000'],
23:         ];
24:     }
25: }
```

## File: app/Http/Requests/Admin/Customer/StoreCustomerRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Customer;
 6: 
 7: use App\Enums\CustomerStatus;
 8: use App\Enums\CustomerType;
 9: use App\Http\Requests\Concerns\NormalizesCustomerInput;
10: use App\Rules\EmailNotUsedByOtherAccountType;
11: use App\Rules\PasswordByteLimit;
12: use App\Rules\UniqueCustomerEmail;
13: use Illuminate\Foundation\Http\FormRequest;
14: use Illuminate\Validation\Rule;
15: 
16: class StoreCustomerRequest extends FormRequest
17: {
18:     use NormalizesCustomerInput;
19: 
20:     public function authorize(): bool
21:     {
22:         return (! $this->hasAny(['credit_limit', 'customer_group_id']) && $this->input('type') !== 'distributor')
23:             || $this->user()->can('customers.approve');
24:     }
25: 
26:     /**
27:      * @return array<string, mixed>
28:      */
29:     public function rules(): array
30:     {
31:         return [
32:             'name' => ['required', 'string', 'max:255'],
33:             'email' => ['nullable', 'email', 'max:255', new EmailNotUsedByOtherAccountType('customers'), new UniqueCustomerEmail],
34:             'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/', Rule::unique('customers', 'phone')],
35:             'password' => ['required', 'string', new PasswordByteLimit, 'min:12', 'max:72'],
36:             'type' => ['nullable', Rule::enum(CustomerType::class)],
37:             'status' => ['nullable', Rule::in([CustomerStatus::Pending->value, CustomerStatus::Approved->value])],
38:             'company_name' => ['nullable', 'string', 'max:255'],
39:             'tax_number' => ['nullable', 'string', 'max:50'],
40:             'commercial_register' => ['nullable', 'string', 'max:50'],
41:             'customer_group_id' => ['nullable', 'integer', Rule::exists('customer_groups', 'id')->where('is_active', true)],
42:             'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
43:         ];
44:     }
45: }
```

## File: app/Http/Requests/Admin/Customer/SuspendCustomerRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Customer;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: 
 9: class SuspendCustomerRequest extends FormRequest
10: {
11:     public function authorize(): bool
12:     {
13:         return true;
14:     }
15: 
16:     /**
17:      * @return array<string, mixed>
18:      */
19:     public function rules(): array
20:     {
21:         return [
22:             'reason' => ['required', 'string', 'max:2000'],
23:         ];
24:     }
25: }
```

## File: app/Http/Requests/Admin/Customer/UpdateCustomerRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Customer;
 6: 
 7: use App\Http\Requests\Concerns\NormalizesCustomerInput;
 8: use App\Rules\EmailNotUsedByOtherAccountType;
 9: use App\Rules\PasswordByteLimit;
10: use App\Rules\UniqueCustomerEmail;
11: use Illuminate\Foundation\Http\FormRequest;
12: use Illuminate\Validation\Rule;
13: 
14: class UpdateCustomerRequest extends FormRequest
15: {
16:     use NormalizesCustomerInput;
17: 
18:     public function authorize(): bool
19:     {
20:         return ! $this->hasAny(['credit_limit', 'customer_group_id', 'password', 'email', 'phone', 'company_name', 'tax_number', 'commercial_register']) || $this->user()->can('customers.approve');
21:     }
22: 
23:     /**
24:      * @return array<string, mixed>
25:      */
26:     public function rules(): array
27:     {
28:         $customerId = $this->route('customer')?->id;
29: 
30:         return [
31:             'name' => ['sometimes', 'string', 'max:255'],
32:             'email' => ['sometimes', 'nullable', 'email', 'max:255', new EmailNotUsedByOtherAccountType('customers'), new UniqueCustomerEmail($customerId)],
33:             'phone' => ['sometimes', 'string', 'regex:/^01[0125][0-9]{8}$/', Rule::unique('customers', 'phone')->ignore($customerId)],
34:             'password' => ['sometimes', 'string', new PasswordByteLimit, 'min:12', 'max:72'],
35:             'type' => ['missing'],
36:             'status' => ['missing'],
37:             'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
38:             'tax_number' => ['sometimes', 'nullable', 'string', 'max:50'],
39:             'commercial_register' => ['sometimes', 'nullable', 'string', 'max:50'],
40:             'customer_group_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customer_groups', 'id')->where('is_active', true)],
41:             'credit_limit' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
42:         ];
43:     }
44: }
```

## File: app/Http/Requests/Admin/Pricing/StoreCustomerGroupRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Pricing;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: use Illuminate\Validation\Rule;
 9: 
10: class StoreCustomerGroupRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return true;
15:     }
16: 
17:     /**
18:      * @return array<string, mixed>
19:      */
20:     public function rules(): array
21:     {
22:         return [
23:             'name' => ['required', 'string', 'max:255'],
24:             'code' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('customer_groups', 'code')],
25:             'description' => ['nullable', 'string', 'max:2000'],
26:             'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
27:             'is_active' => ['nullable', 'boolean'],
28:         ];
29:     }
30: }
```

## File: app/Http/Requests/Admin/Pricing/UpdateCustomerGroupRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Pricing;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: use Illuminate\Validation\Rule;
 9: 
10: class UpdateCustomerGroupRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return true;
15:     }
16: 
17:     /**
18:      * @return array<string, mixed>
19:      */
20:     public function rules(): array
21:     {
22:         return [
23:             'name' => ['sometimes', 'string', 'max:255'],
24:             'code' => [
25:                 'sometimes', 'string', 'max:64', 'alpha_dash',
26:                 Rule::unique('customer_groups', 'code')->ignore($this->route('customer_group')?->id),
27:             ],
28:             'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
29:             'discount_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
30:             'is_active' => ['sometimes', 'boolean'],
31:         ];
32:     }
33: }
```

## File: app/Http/Requests/Concerns/NormalizesCustomerInput.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Concerns;
 6: 
 7: use App\Models\Customer;
 8: 
 9: trait NormalizesCustomerInput
10: {
11:     protected function prepareForValidation(): void
12:     {
13:         $normalized = [];
14:         foreach (['email', 'phone', 'login'] as $field) {
15:             $value = $this->input($field);
16:             if (is_string($value)) {
17:                 $normalized[$field] = $field === 'phone' || ($field === 'login' && ! str_contains($value, '@'))
18:                     ? Customer::normalizePhone($value) : mb_strtolower(trim($value));
19:             }
20:         }
21:         $this->merge($normalized);
22:     }
23: }
```

## File: app/Http/Requests/Wholesale/WholesaleRegisterRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Wholesale;
 6: 
 7: use App\Enums\CustomerDocumentType;
 8: use App\Http\Requests\Concerns\NormalizesCustomerInput;
 9: use App\Rules\EmailNotUsedByOtherAccountType;
10: use App\Rules\PasswordByteLimit;
11: use App\Rules\UniqueCustomerEmail;
12: use App\Services\CustomerPhoneVerificationService;
13: use Illuminate\Foundation\Http\FormRequest;
14: use Illuminate\Support\Arr;
15: use Illuminate\Validation\Rule;
16: 
17: class WholesaleRegisterRequest extends FormRequest
18: {
19:     use NormalizesCustomerInput;
20: 
21:     public function authorize(): bool
22:     {
23:         app(CustomerPhoneVerificationService::class)->requireProof($this);
24: 
25:         return true;
26:     }
27: 
28:     /**
29:      * @return array<string, mixed>
30:      */
31:     public function rules(): array
32:     {
33:         return [
34:             'name' => ['required', 'string', 'max:255'],
35:             'email' => ['nullable', 'email', 'max:255', new EmailNotUsedByOtherAccountType('customers'), new UniqueCustomerEmail],
36:             'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/', Rule::unique('customers', 'phone')],
37:             'password' => ['required', 'string', new PasswordByteLimit, 'min:12', 'max:72'],
38:             'phone_verification_token' => ['nullable', 'string', 'size:64'],
39:             'company_name' => ['required', 'string', 'max:255'],
40:             'tax_number' => ['required', 'string', 'max:50'],
41:             'commercial_register' => ['required', 'string', 'max:50'],
42:             'documents' => ['required', 'array', 'list', 'min:1', 'max:5'],
43:             'documents.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
44:             'document_types' => ['nullable', 'array', 'list', 'size:'.count(Arr::wrap($this->file('documents', [])))],
45:             'document_types.*' => ['nullable', Rule::enum(CustomerDocumentType::class)],
46:         ];
47:     }
48: }
```

## File: app/Http/Resources/Admin/CustomerGroupResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Admin;
 6: 
 7: use Illuminate\Http\Request;
 8: use Illuminate\Http\Resources\Json\JsonResource;
 9: 
10: class CustomerGroupResource extends JsonResource
11: {
12:     /**
13:      * Transform the resource into an array.
14:      *
15:      * @return array<string, mixed>
16:      */
17:     public function toArray(Request $request): array
18:     {
19:         return [
20:             'id' => $this->id,
21:             'name' => $this->name,
22:             'code' => $this->code,
23:             'description' => $this->description,
24:             'discount_percentage' => $this->discount_percentage,
25:             'is_active' => $this->is_active,
26:             'customers_count' => $this->whenCounted('customers'),
27:             'created_at' => $this->created_at,
28:         ];
29:     }
30: }
```

## File: app/Http/Resources/Admin/CustomerResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Admin;
 6: 
 7: use Illuminate\Http\Request;
 8: use Illuminate\Http\Resources\Json\JsonResource;
 9: 
10: /** تمثيل العميل في لوحة الإدارة — كامل البيانات التشغيلية بما فيها الاعتماد والمستندات. */
11: class CustomerResource extends JsonResource
12: {
13:     /**
14:      * @return array<string, mixed>
15:      */
16:     public function toArray(Request $request): array
17:     {
18:         return [
19:             'id' => $this->id,
20:             'name' => $this->name,
21:             'email' => $this->email,
22:             'email_verified_at' => $this->email_verified_at,
23:             'phone' => $this->phone,
24:             'type' => $this->type,
25:             'status' => $this->status,
26:             'company_name' => $this->company_name,
27:             'tax_number' => $this->tax_number,
28:             'commercial_register' => $this->commercial_register,
29:             'credit_limit' => $this->credit_limit,
30:             'customer_group_id' => $this->customer_group_id,
31:             'customer_group' => $this->whenLoaded('customerGroup', fn () => $this->customerGroup ? [
32:                 'id' => $this->customerGroup->id,
33:                 'name' => $this->customerGroup->name,
34:                 'discount_percentage' => $this->customerGroup->discount_percentage,
35:             ] : null),
36:             'rejection_reason' => $this->rejection_reason,
37:             'suspension_reason' => $this->suspension_reason,
38:             'approved_at' => $this->approved_at,
39:             'approved_by' => $this->approved_by,
40:             'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document) => [
41:                 'id' => $document->id,
42:                 'document_type' => $document->document_type,
43:                 'status' => $document->status,
44:                 'original_name' => $document->original_name,
45:                 'mime_type' => $document->mime_type,
46:                 'reviewed_by' => $document->reviewed_by,
47:                 'reviewed_at' => $document->reviewed_at,
48:                 'review_note' => $document->review_note,
49:                 // تنزيل محمي بصلاحيات الأدمن من التخزين الخاص — لا رابط عام
50:                 'download_url' => route('admin.customers.documents.download', ['customer' => $this->id, 'document' => $document->id]),
51:                 'created_at' => $document->created_at,
52:             ])->all()),
53:             'addresses' => $this->whenLoaded('addresses', fn () => $this->addresses->map(fn ($address) => [
54:                 'id' => $address->id,
55:                 'city' => $address->relationLoaded('city') && $address->city ? [
56:                     'id' => $address->city->id, 'name_ar' => $address->city->name_ar, 'name_en' => $address->city->name_en,
57:                 ] : null,
58:                 'address_line' => $address->address_line,
59:                 'building_no' => $address->building_no,
60:                 'floor_no' => $address->floor_no,
61:                 'apartment_no' => $address->apartment_no,
62:                 'is_default' => $address->is_default,
63:             ])->all()),
64:             'created_at' => $this->created_at,
65:             'updated_at' => $this->updated_at,
66:             'deleted_at' => $this->deleted_at, // M11: تمييز المحذوف في trashed=only
67:         ];
68:     }
69: }
```

## File: app/Http/Resources/Store/CustomerResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Store;
 6: 
 7: use Illuminate\Http\Request;
 8: use Illuminate\Http\Resources\Json\JsonResource;
 9: 
10: /** تمثيل العميل لقنوات العميل نفسه (متجر/جملة) — بلا أي بيانات داخلية. */
11: class CustomerResource extends JsonResource
12: {
13:     /**
14:      * @return array<string, mixed>
15:      */
16:     public function toArray(Request $request): array
17:     {
18:         return [
19:             'id' => $this->id,
20:             'name' => $this->name,
21:             'email' => $this->email,
22:             'email_verified_at' => $this->email_verified_at,
23:             'phone' => $this->phone,
24:             'phone_verified_at' => $this->phone_verified_at,
25:             'type' => $this->type,
26:             'status' => $this->status,
27:             'company_name' => $this->company_name,
28:             'tax_number' => $this->tax_number,
29:             'commercial_register' => $this->commercial_register,
30:             'customer_group_id' => $this->customer_group_id,
31:             'credit_limit' => $this->credit_limit,
32:             'wallet_balance' => $this->wallet_balance,
33:             'approved_at' => $this->approved_at?->toIso8601String(),
34:             'created_at' => $this->created_at,
35:         ];
36:     }
37: }
```

## File: app/Listeners/SendWholesaleApplicationReviewed.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Listeners;
 6: 
 7: use App\Events\WholesaleApplicationReviewed;
 8: use App\Notifications\WholesaleApplicationReviewedNotification;
 9: use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
10: 
11: /**
12:  * إشعار التاجر بنتيجة مراجعة حسابه (قبول/رفض).
13:  * 0.5: التنفيذ بعد الـ commit فقط — وإلا فشل الـ transaction بعد
14:  * الـ dispatch كان يبعت "اتقبلت" على حاجة متنفذتش (خصوصًا في مسار
15:  * الـ AI حيث التنفيذ جوه transaction متداخلة في PendingActionService).
16:  * ملحوظة: ShouldQueueAfterCommit مش ShouldHandleEventsAfterCommit —
17:  * الأخير بيأجّل المستمعين غير المُصفوفة فقط، أما المستمع المصفوف
18:  * (ShouldQueue) فلا يؤجَّل إلا بـ ShouldQueueAfterCommit (موثّق
19:  * بكود الفريمورك واختبار الـ rollback).
20:  */
21: class SendWholesaleApplicationReviewed implements ShouldQueueAfterCommit
22: {
23:     public function handle(WholesaleApplicationReviewed $event): void
24:     {
25:         $event->customer->notify(new WholesaleApplicationReviewedNotification($event->decision, $event->reason));
26:     }
27: }
```

## File: app/Listeners/SendWholesaleApplicationSubmitted.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Listeners;
 6: 
 7: use App\Events\WholesaleApplicationSubmitted;
 8: use App\Notifications\StaffRecipients;
 9: use App\Notifications\WholesaleApplicationSubmittedNotification;
10: use Illuminate\Contracts\Queue\ShouldQueue;
11: use Illuminate\Support\Facades\DB;
12: use Illuminate\Support\Facades\Notification;
13: 
14: /** تنبيه الإدارة (حاملي customers.approve) بتسجيل تاجر جديد. */
15: class SendWholesaleApplicationSubmitted implements ShouldQueue
16: {
17:     public function handle(WholesaleApplicationSubmitted $event): void
18:     {
19:         $recipients = StaffRecipients::withAnyPermission(['customers.approve']);
20:         if ($recipients->isNotEmpty()) {
21:             DB::transaction(fn () => Notification::sendNow($recipients, new WholesaleApplicationSubmittedNotification($event->customer), ['database']));
22:         }
23:     }
24: }
```

## File: app/Models/Concerns/SerializesAccountEmail.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models\Concerns;
 6: 
 7: use App\Models\Customer;
 8: use App\Rules\EmailNotUsedByOtherAccountType;
 9: use Illuminate\Support\Facades\Validator;
10: 
11: trait SerializesAccountEmail
12: {
13:     public static function bootSerializesAccountEmail(): void
14:     {
15:         static::saving(function ($account): void {
16:             if (! $account->isDirty(['email', 'email_verified_at', 'deleted_at'])
17:                 || ! $account->email
18:                 || ($account instanceof Customer && ($account->email_verified_at === null || $account->trashed()))) {
19:                 return;
20:             }
21: 
22:             $account->getConnection()->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
23:                 mb_strtolower(trim($account->email)),
24:             ]);
25:             Validator::make(['email' => $account->email], [
26:                 'email' => [new EmailNotUsedByOtherAccountType($account->getTable())],
27:             ])->validate();
28:         });
29:     }
30: 
31:     public function save(array $options = []): bool
32:     {
33:         // Keep the advisory lock until the write commits, including standalone model saves.
34:         return $this->getConnection()->transaction(fn () => parent::save($options));
35:     }
36: }
```

## File: app/Models/City.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Illuminate\Database\Eloquent\Attributes\Fillable;
 8: use Illuminate\Database\Eloquent\Factories\HasFactory;
 9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\HasMany;
11: 
12: #[Fillable(['name_ar', 'name_en', 'shipping_cost', 'is_active'])]
13: class City extends Model
14: {
15:     use HasFactory;
16: 
17:     public function addresses(): HasMany
18:     {
19:         return $this->hasMany(CustomerAddress::class);
20:     }
21: 
22:     protected function casts(): array
23:     {
24:         return [
25:             'shipping_cost' => 'decimal:2',
26:             'is_active' => 'boolean',
27:         ];
28:     }
29: }
```

## File: app/Models/Customer.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Models;
  6: 
  7: use App\Enums\CustomerStatus;
  8: use App\Enums\CustomerType;
  9: use App\Models\Concerns\SerializesAccountEmail;
 10: use Illuminate\Database\Eloquent\Attributes\Fillable;
 11: use Illuminate\Database\Eloquent\Attributes\Hidden;
 12: use Illuminate\Database\Eloquent\Factories\HasFactory;
 13: use Illuminate\Database\Eloquent\Relations\BelongsTo;
 14: use Illuminate\Database\Eloquent\Relations\HasMany;
 15: use Illuminate\Database\Eloquent\SoftDeletes;
 16: use Illuminate\Foundation\Auth\User as Authenticatable;
 17: use Illuminate\Notifications\Notifiable;
 18: use Laravel\Sanctum\HasApiTokens;
 19: 
 20: #[Fillable([
 21:     'name',
 22:     'email',
 23:     'phone',
 24:     'password',
 25:     'type',
 26:     'customer_group_id',
 27:     'company_name',
 28:     'tax_number',
 29:     'commercial_register',
 30:     'rejection_reason',
 31:     'suspension_reason',
 32:     'phone_verified_at',
 33: ])]
 34: #[Hidden(['password', 'remember_token'])]
 35: class Customer extends Authenticatable
 36: {
 37:     use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
 38:     use SerializesAccountEmail;
 39: 
 40:     protected static function booted(): void
 41:     {
 42:         static::creating(function (Customer $customer): void {
 43:             if (! array_key_exists('status', $customer->getAttributes())) {
 44:                 $customer->status = in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
 45:                     ? CustomerStatus::Pending : CustomerStatus::Approved;
 46:             }
 47:         });
 48:     }
 49: 
 50:     public function customerGroup(): BelongsTo
 51:     {
 52:         return $this->belongsTo(CustomerGroup::class);
 53:     }
 54: 
 55:     public function canShop(): bool
 56:     {
 57:         return ! in_array($this->status, [CustomerStatus::Suspended, CustomerStatus::Rejected], true);
 58:     }
 59: 
 60:     public function getEmailForPasswordReset(): string
 61:     {
 62:         return 'customer:'.$this->getKey();
 63:     }
 64: 
 65:     public function setEmailAttribute(?string $email): void
 66:     {
 67:         $normalized = $email === null ? null : mb_strtolower(trim($email));
 68:         if (($this->attributes['email'] ?? null) !== $normalized) {
 69:             $this->attributes['email_verified_at'] = null;
 70:         }
 71:         $this->attributes['email'] = $normalized;
 72:     }
 73: 
 74:     /** خريطة تحويل الأرقام العربية والفارسية إلى أرقام لاتينية */
 75:     private const ARABIC_PERSIAN_DIGITS_MAP = [
 76:         '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
 77:         '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
 78:         '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
 79:         '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
 80:     ];
 81: 
 82:     public function setPhoneAttribute(string $phone): void
 83:     {
 84:         $this->attributes['phone'] = self::normalizePhone($phone);
 85:     }
 86: 
 87:     public static function normalizePhone(string $phone): string
 88:     {
 89:         if (preg_match('/^legacy-[0-9]+$/', trim($phone))) {
 90:             return trim($phone); // L8: ما نبوّظش الـ placeholder
 91:         }
 92:         $phone = strtr(trim($phone), self::ARABIC_PERSIAN_DIGITS_MAP);
 93:         $phone = preg_replace('/[\s().-]+/u', '', $phone);
 94:         if (preg_match('/^201[0125][0-9]{8}$/', $phone)) {
 95:             return '0'.substr($phone, 2);
 96:         }
 97:         if (str_starts_with($phone, '+20')) {
 98:             return '0'.substr($phone, 3);
 99:         }
100:         if (str_starts_with($phone, '0020')) {
101:             return '0'.substr($phone, 4);
102:         }
103: 
104:         return $phone;
105:     }
106: 
107:     public function auditLogs(): HasMany
108:     {
109:         return $this->hasMany(CustomerAuditLog::class);
110:     }
111: 
112:     public function approver(): BelongsTo
113:     {
114:         return $this->belongsTo(User::class, 'approved_by');
115:     }
116: 
117:     public function documents(): HasMany
118:     {
119:         return $this->hasMany(CustomerDocument::class);
120:     }
121: 
122:     public function addresses(): HasMany
123:     {
124:         return $this->hasMany(CustomerAddress::class);
125:     }
126: 
127:     public function returnRequests(): HasMany
128:     {
129:         return $this->hasMany(ReturnRequest::class);
130:     }
131: 
132:     protected function casts(): array
133:     {
134:         return [
135:             'password' => 'hashed',
136:             'type' => CustomerType::class,
137:             'status' => CustomerStatus::class,
138:             'credit_limit' => 'decimal:2',
139:             'wallet_balance' => 'decimal:2',
140:             'approved_at' => 'datetime',
141:             'phone_verified_at' => 'datetime',
142:             'email_verified_at' => 'datetime',
143:         ];
144:     }
145: }
```

## File: app/Models/CustomerAddress.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Illuminate\Database\Eloquent\Attributes\Fillable;
 8: use Illuminate\Database\Eloquent\Model;
 9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: 
11: #[Fillable(['customer_id', 'city_id', 'address_line', 'building_no', 'floor_no', 'apartment_no', 'is_default'])]
12: class CustomerAddress extends Model
13: {
14:     public function customer(): BelongsTo
15:     {
16:         return $this->belongsTo(Customer::class);
17:     }
18: 
19:     public function city(): BelongsTo
20:     {
21:         return $this->belongsTo(City::class);
22:     }
23: 
24:     protected function casts(): array
25:     {
26:         return [
27:             'is_default' => 'boolean',
28:         ];
29:     }
30: }
```

## File: app/Models/CustomerAuditLog.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Illuminate\Database\Eloquent\Model;
 8: use Illuminate\Database\Eloquent\Relations\BelongsTo;
 9: 
10: class CustomerAuditLog extends Model
11: {
12:     protected $guarded = [];
13: 
14:     public function actor(): BelongsTo
15:     {
16:         return $this->belongsTo(User::class, 'actor_id');
17:     }
18: 
19:     protected function casts(): array
20:     {
21:         return ['metadata' => 'array'];
22:     }
23: 
24:     public static function record(?Customer $customer, string $action, ?int $actorId, array $metadata = []): void
25:     {
26:         self::query()->create(['customer_id' => $customer?->id, 'actor_id' => $actorId, 'action' => $action, 'metadata' => $metadata]);
27:     }
28: }
```

## File: app/Models/CustomerDocument.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use App\Enums\CustomerDocumentStatus;
 8: use App\Enums\CustomerDocumentType;
 9: use Illuminate\Database\Eloquent\Attributes\Fillable;
10: use Illuminate\Database\Eloquent\Model;
11: use Illuminate\Database\Eloquent\Relations\BelongsTo;
12: 
13: /**
14:  * مستند تحقق تابع لعميل (سجل تجاري/بطاقة ضريبية/بطاقة رقم قومي).
15:  * الملفات نفسها في تخزين خاص (storage/app/private) — لا روابط عامة أبدًا.
16:  */
17: #[Fillable(['customer_id', 'document_type', 'file_path', 'original_name', 'mime_type', 'status', 'review_note'])]
18: class CustomerDocument extends Model
19: {
20:     public static function safeName(string $name): string
21:     {
22:         $name = basename(str_replace('\\', '/', $name));
23:         $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '_', $name) ?? 'document';
24: 
25:         return mb_substr($name, 0, 250) ?: 'document';
26:     }
27: 
28:     public function customer(): BelongsTo
29:     {
30:         return $this->belongsTo(Customer::class);
31:     }
32: 
33:     protected function casts(): array
34:     {
35:         return [
36:             'document_type' => CustomerDocumentType::class,
37:             'status' => CustomerDocumentStatus::class,
38:             'reviewed_at' => 'datetime',
39:         ];
40:     }
41: }
```

## File: app/Models/CustomerGroup.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Illuminate\Database\Eloquent\Model;
 8: use Illuminate\Database\Eloquent\Relations\HasMany;
 9: 
10: class CustomerGroup extends Model
11: {
12:     protected $fillable = ['name', 'code', 'description', 'discount_percentage', 'is_active'];
13: 
14:     protected $casts = [
15:         'discount_percentage' => 'decimal:2',
16:         'is_active' => 'boolean',
17:     ];
18: 
19:     public function customers(): HasMany
20:     {
21:         return $this->hasMany(Customer::class);
22:     }
23: 
24:     public function priceLists(): HasMany
25:     {
26:         return $this->hasMany(PriceList::class);
27:     }
28: }
```

## File: app/Models/PriceList.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use App\Enums\PriceListType;
 8: use Illuminate\Database\Eloquent\Model;
 9: use Illuminate\Database\Eloquent\Relations\BelongsTo;
10: use Illuminate\Database\Eloquent\Relations\HasMany;
11: 
12: class PriceList extends Model
13: {
14:     protected $fillable = [
15:         'name', 'type', 'customer_id', 'customer_group_id', 'branch_id',
16:         'currency', 'starts_at', 'ends_at', 'priority', 'is_active',
17:     ];
18: 
19:     protected $casts = [
20:         'type' => PriceListType::class,
21:         'starts_at' => 'datetime',
22:         'ends_at' => 'datetime',
23:         'priority' => 'integer',
24:         'is_active' => 'boolean',
25:     ];
26: 
27:     public function items(): HasMany
28:     {
29:         return $this->hasMany(PriceListItem::class);
30:     }
31: 
32:     public function customer(): BelongsTo
33:     {
34:         return $this->belongsTo(Customer::class);
35:     }
36: 
37:     public function customerGroup(): BelongsTo
38:     {
39:         return $this->belongsTo(CustomerGroup::class);
40:     }
41: 
42:     public function branch(): BelongsTo
43:     {
44:         return $this->belongsTo(Branch::class);
45:     }
46: }
```

## File: app/Models/User.php
```php
 1: <?php
 2: 
 3: namespace App\Models;
 4: 
 5: use App\Models\Concerns\SerializesAccountEmail;
 6: use App\Models\Concerns\TracksEditVersion;
 7: use Database\Factories\UserFactory;
 8: // use Illuminate\Contracts\Auth\MustVerifyEmail;
 9: use Illuminate\Database\Eloquent\Attributes\Fillable;
10: use Illuminate\Database\Eloquent\Attributes\Hidden;
11: use Illuminate\Database\Eloquent\Factories\HasFactory;
12: use Illuminate\Database\Eloquent\Relations\BelongsTo;
13: use Illuminate\Foundation\Auth\User as Authenticatable;
14: use Illuminate\Notifications\Notifiable;
15: use Laravel\Sanctum\HasApiTokens;
16: use Spatie\Permission\Traits\HasRoles;
17: 
18: #[Fillable(['name', 'email', 'phone', 'password', 'branch_id', 'is_active', 'must_change_password'])]
19: #[Hidden(['password', 'remember_token'])]
20: class User extends Authenticatable
21: {
22:     /** @use HasFactory<UserFactory> */
23:     use HasApiTokens, HasFactory, HasRoles, Notifiable;
24: 
25:     use SerializesAccountEmail;
26:     use TracksEditVersion;
27: 
28:     protected string $guard_name = 'staff';
29: 
30:     public function branch(): BelongsTo
31:     {
32:         return $this->belongsTo(Branch::class);
33:     }
34: 
35:     public function setEmailAttribute(string $email): void
36:     {
37:         $this->attributes['email'] = mb_strtolower(trim($email));
38:     }
39: 
40:     /**
41:      * Get the attributes that should be cast.
42:      *
43:      * @return array<string, string>
44:      */
45:     protected function casts(): array
46:     {
47:         return [
48:             'email_verified_at' => 'datetime',
49:             'password' => 'hashed',
50:             'is_active' => 'boolean',
51:             'is_system' => 'boolean',
52:             'must_change_password' => 'boolean',
53:         ];
54:     }
55: }
```

## File: app/Modules/Customers/CustomersServiceProvider.php
```php
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace App\Modules\Customers;
6: 
7: use Illuminate\Support\ServiceProvider;
8: 
9: class CustomersServiceProvider extends ServiceProvider {}
```

## File: app/Notifications/Channels/CustomerSmsChannel.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Notifications\Channels;
 6: 
 7: use App\Exceptions\BusinessException;
 8: use Illuminate\Http\Client\ConnectionException;
 9: use Illuminate\Http\Client\RequestException;
10: use Illuminate\Notifications\Notification;
11: use Illuminate\Support\Facades\Http;
12: 
13: class CustomerSmsChannel
14: {
15:     public function send(object $customer, Notification $notification): void
16:     {
17:         $url = config('services.customer_sms.url');
18:         $token = config('services.customer_sms.token');
19:         if (! is_string($url) || ! str_starts_with($url, 'https://') || ! is_string($token) || $token === '') {
20:             throw new BusinessException('خدمة الرسائل النصية غير مهيأة حاليًا.', [], 503);
21:         }
22: 
23:         try {
24:             $response = Http::withToken($token)->connectTimeout(3)->timeout(10)->post($url, [
25:                 'to' => $customer->routeNotificationFor('customer_sms', $notification) ?? $customer->phone,
26:                 'message' => $notification->toSms($customer),
27:             ])->throw();
28:         } catch (ConnectionException|RequestException) {
29:             throw new BusinessException('خدمة الرسائل النصية غير متاحة مؤقتًا؛ أعد المحاولة لاحقًا.', [], 503);
30:         }
31:         if ($response->json('success') !== true) {
32:             throw new BusinessException('رفض مزود الرسائل إرسال الرسالة.', [], 503);
33:         }
34:     }
35: }
```

## File: app/Notifications/CustomerPhoneVerificationCode.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Notifications;
 6: 
 7: use App\Notifications\Channels\CustomerSmsChannel;
 8: use Illuminate\Notifications\Notification;
 9: 
10: class CustomerPhoneVerificationCode extends Notification
11: {
12:     public function __construct(private readonly string $code) {}
13: 
14:     public function via(object $notifiable): array
15:     {
16:         return [CustomerSmsChannel::class];
17:     }
18: 
19:     public function toSms(object $notifiable): string
20:     {
21:         return 'رمز التحقق من رقم الهاتف: '.$this->code;
22:     }
23: }
```

## File: app/Notifications/WholesaleApplicationReviewedNotification.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Notifications;
 6: 
 7: use Illuminate\Bus\Queueable;
 8: use Illuminate\Contracts\Queue\ShouldQueue;
 9: use Illuminate\Notifications\Notification;
10: 
11: /** نتيجة مراجعة حساب التاجر (قبول/رفض) — للتاجر نفسه. */
12: class WholesaleApplicationReviewedNotification extends Notification implements ShouldQueue
13: {
14:     use Queueable;
15: 
16:     public function __construct(
17:         private readonly string $decision,
18:         private readonly ?string $reason = null,
19:     ) {}
20: 
21:     /** @return list<string> */
22:     public function via(object $notifiable): array
23:     {
24:         return ['database'];
25:     }
26: 
27:     /** @return array<string, mixed> */
28:     public function toArray(object $notifiable): array
29:     {
30:         $approved = $this->decision === 'approved';
31:         $message = $approved
32:             ? 'تمت الموافقة على حساب الجملة الخاص بك — يمكنك الآن الشراء بأسعار الجملة.'
33:             : 'نأسف، تم رفض طلب تفعيل حساب الجملة الخاص بك'.($this->reason !== null ? " — السبب: {$this->reason}" : '.');
34: 
35:         return [
36:             'kind' => 'wholesale_application_reviewed',
37:             'decision' => $this->decision,
38:             'reason' => $this->reason,
39:             'message' => $message,
40:             // شكل موحد لبوابة الجملة: {title, body, icon, link:{screen,id}}
41:             'title' => $approved ? 'تمت الموافقة على حساب الجملة' : 'تم رفض طلب تفعيل حساب الجملة',
42:             'body' => $message,
43:             'icon' => $approved ? 'fa-circle-check' : 'fa-circle-xmark',
44:             'link' => ['screen' => null, 'id' => null],
45:         ];
46:     }
47: }
```

## File: app/Notifications/WholesaleApplicationSubmittedNotification.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Notifications;
 6: 
 7: use App\Models\Customer;
 8: use Illuminate\Bus\Queueable;
 9: use Illuminate\Contracts\Queue\ShouldQueue;
10: use Illuminate\Notifications\Notification;
11: 
12: /** طلب تسجيل تاجر جملة جديد بانتظار المراجعة — للإدارة. */
13: class WholesaleApplicationSubmittedNotification extends Notification implements ShouldQueue
14: {
15:     use Queueable;
16: 
17:     public function __construct(private readonly Customer $customer) {}
18: 
19:     /** @return list<string> */
20:     public function via(object $notifiable): array
21:     {
22:         return ['database'];
23:     }
24: 
25:     /** @return array<string, mixed> */
26:     public function toArray(object $notifiable): array
27:     {
28:         return [
29:             'kind' => 'wholesale_application_submitted',
30:             'customer_id' => $this->customer->id,
31:             'customer_name' => $this->customer->name,
32:             'customer_type' => $this->customer->type,
33:             'message' => "سجّل تاجر جديد ({$this->customer->name}) وبانتظار مراجعة حسابه.",
34:         ];
35:     }
36: }
```

## File: app/Rules/EmailNotUsedByOtherAccountType.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Rules;
 6: 
 7: use Closure;
 8: use Illuminate\Contracts\Validation\ValidationRule;
 9: use Illuminate\Support\Facades\DB;
10: 
11: /** يمنع تسجيل بريد مستخدم أصلًا في نوع حساب آخر (users / supplier_users / customers). */
12: final class EmailNotUsedByOtherAccountType implements ValidationRule
13: {
14:     public function __construct(private readonly string $ownTable) {}
15: 
16:     public function validate(string $attribute, mixed $value, Closure $fail): void
17:     {
18:         if (! is_string($value) || trim($value) === '') {
19:             return;
20:         }
21:         $email = mb_strtolower(trim($value));
22:         foreach (['users', 'supplier_users', 'customers'] as $table) {
23:             if ($table === $this->ownTable) {
24:                 continue;
25:             }
26:             $query = DB::table($table)->whereRaw('LOWER(email) = ?', [$email]);
27:             if ($table === 'customers') {
28:                 $query->whereNull('deleted_at');
29:                 // Trader contact emails are login identifiers even before verification.
30:                 $query->where(fn ($query) => $query->whereNotNull('email_verified_at')
31:                     ->orWhereIn('type', ['wholesale', 'distributor']));
32:             }
33:             if ($query->exists()) {
34:                 $fail('هذا البريد مستخدم بحساب آخر في النظام.');
35: 
36:                 return;
37:             }
38:         }
39:     }
40: }
```

## File: app/Rules/PasswordByteLimit.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Rules;
 6: 
 7: use Closure;
 8: use Illuminate\Contracts\Validation\ValidationRule;
 9: 
10: final class PasswordByteLimit implements ValidationRule
11: {
12:     public function validate(string $attribute, mixed $value, Closure $fail): void
13:     {
14:         if (is_string($value) && strlen($value) > 72) {
15:             $fail('كلمة المرور يجب ألا تتجاوز 72 بايت.');
16:         }
17:     }
18: }
```

## File: app/Rules/UniqueCustomerEmail.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Rules;
 6: 
 7: use Closure;
 8: use Illuminate\Contracts\Validation\ValidationRule;
 9: use Illuminate\Support\Facades\DB;
10: 
11: final class UniqueCustomerEmail implements ValidationRule
12: {
13:     public function __construct(private readonly ?int $ignoreId = null) {}
14: 
15:     public function validate(string $attribute, mixed $value, Closure $fail): void
16:     {
17:         if (! is_string($value) || trim($value) === '') {
18:             return;
19:         }
20:         $exists = DB::table('customers')->whereNull('deleted_at')
21:             ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($value))])
22:             ->when($this->ignoreId, fn ($q) => $q->where('id', '!=', $this->ignoreId))
23:             ->exists();
24:         if ($exists) {
25:             $fail('هذا البريد مستخدم بحساب آخر في النظام.');
26:         }
27:     }
28: }
```

## File: app/Services/BranchAccess.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use App\Enums\StaffRole;
  8: use App\Models\Branch;
  9: use App\Models\Treasury;
 10: use App\Models\User;
 11: use Illuminate\Auth\Access\AuthorizationException;
 12: use Illuminate\Database\Eloquent\ModelNotFoundException;
 13: use Illuminate\Support\Collection;
 14: 
 15: /**
 16:  * Enforces staff branch scoping using a fail-closed access policy:
 17:  * Multi-branch access is an explicit privilege (Super Admin role or inventory.all_branches permission).
 18:  * A branch-assigned staff member is strictly confined to their branch.
 19:  * Users with no branch assignment and no all-branch privilege are denied access by default.
 20:  */
 21: class BranchAccess
 22: {
 23:     /** @var array<int, User> Per-request memory cache to avoid reloading user instances on repeated checks. */
 24:     private array $usersById = [];
 25: 
 26:     /** @var array<int, bool> M4: حالة تفعيل الفرع للمستخدم المرتبط — cache بمستوى الطلب. */
 27:     private array $branchActive = [];
 28: 
 29:     /**
 30:      * Determine branch scope restriction for the given user.
 31:      *
 32:      * Returns null if user possesses global multi-branch access, returns branch ID if confined,
 33:      * or throws an authorization exception if unassigned without global privileges.
 34:      *
 35:      * @param  User|null  $user
 36:      * @return int|null
 37:      *
 38:      * @throws AuthorizationException
 39:      */
 40:     public function restrictedBranch(?User $user): ?int
 41:     {
 42:         if ($user === null) {
 43:             throw new AuthorizationException;
 44:         }
 45: 
 46:         if ($user->hasRole(StaffRole::SuperAdmin->value, 'staff') || $user->can('inventory.all_branches')) {
 47:             return null;
 48:         }
 49: 
 50:         if ($user->branch_id !== null) {
 51:             // M4: الفرع المعطّل مقفول — مقيّد بفرع معطّل مالوش أي وصول
 52:             // (fail-closed) بدل ما يكمل POS/مخزون عادي على فرع مزعّم إنه مقفول.
 53:             $branchId = (int) $user->branch_id;
 54:             $this->branchActive[$branchId] ??= (bool) (Branch::query()->find($branchId)?->is_active);
 55:             if (! $this->branchActive[$branchId]) {
 56:                 throw new AuthorizationException('الفرع المرتبط بحسابك متوقف حالياً.');
 57:             }
 58: 
 59:             return $branchId;
 60:         }
 61: 
 62:         throw new AuthorizationException('حسابك غير مرتبط بأي فرع ولا تملك صلاحية الوصول لكل الفروع (inventory.all_branches).');
 63:     }
 64: 
 65:     /**
 66:      * Assert that the user is authorized to access at least one of the specified branches.
 67:      *
 68:      * @param  int  $userId
 69:      * @param  int  ...$branchIds
 70:      * @return void
 71:      *
 72:      * @throws AuthorizationException
 73:      */
 74:     public function assertBranch(int $userId, int ...$branchIds): void
 75:     {
 76:         $branchId = $this->restrictedBranch($this->usersById[$userId] ??= User::findOrFail($userId));
 77:         if ($branchId !== null && ! in_array($branchId, $branchIds, true)) {
 78:             throw new AuthorizationException('لا تملك صلاحية الوصول إلى هذا الفرع.');
 79:         }
 80:     }
 81: 
 82:     /**
 83:      * Assert that the user possesses global multi-branch access required for central headquarters operations.
 84:      *
 85:      * @param  User|null  $user
 86:      * @return void
 87:      *
 88:      * @throws AuthorizationException
 89:      */
 90:     public function assertCentralAccess(?User $user): void
 91:     {
 92:         if ($this->restrictedBranch($user) !== null) {
 93:             throw new AuthorizationException('هذه العملية المركزية تتطلب صلاحية الوصول لكل الفروع.');
 94:         }
 95:     }
 96: 
 97:     public function lockActiveBranch(int $branchId): Branch
 98:     {
 99:         return $this->lockActiveBranches([$branchId])->get($branchId)
100:             ?? throw (new ModelNotFoundException)->setModel(Branch::class, [$branchId]);
101:     }
102: 
103:     /**
104:      * @param  array<int, int>|Collection<int, int>  $branchIds
105:      * @return Collection<int, Branch>
106:      */
107:     public function lockActiveBranches(array|Collection $branchIds): Collection
108:     {
109:         $ids = collect($branchIds)->map(fn ($id) => (int) $id)->unique()->sort()->values();
110:         if ($ids->isEmpty()) {
111:             return collect();
112:         }
113: 
114:         $branches = Branch::query()->whereIn('id', $ids->all())->orderBy('id')->sharedLock()->get()->keyBy('id');
115:         foreach ($ids as $id) {
116:             $branch = $branches->get($id);
117:             if ($branch === null) {
118:                 throw (new ModelNotFoundException)->setModel(Branch::class, [$id]);
119:             }
120:             if (! $branch->is_active) {
121:                 throw new AuthorizationException('الفرع متوقف حالياً.');
122:             }
123:         }
124: 
125:         return $branches;
126:     }
127: 
128:     /**
129:      * Assert that the user is authorized to perform operations on the specified treasury.
130:      *
131:      * Central treasuries (branch_id = null) require global access; branch treasuries require branch access.
132:      *
133:      * @param  int  $userId
134:      * @param  Treasury  $treasury
135:      * @return void
136:      *
137:      * @throws AuthorizationException
138:      */
139:     public function assertTreasury(int $userId, Treasury $treasury): void
140:     {
141:         if ($treasury->branch_id === null) {
142:             $this->assertCentralAccess(User::findOrFail($userId));
143:         } else {
144:             $this->assertBranch($userId, (int) $treasury->branch_id);
145:         }
146:     }
147: }
```

## File: app/Services/CustomerAuthService.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use App\Enums\CustomerStatus;
  8: use App\Enums\CustomerType;
  9: use App\Exceptions\BusinessException;
 10: use App\Jobs\SendCustomerPasswordReset;
 11: use App\Models\Customer;
 12: use App\Models\CustomerAuditLog;
 13: use App\Notifications\Channels\CustomerSmsChannel;
 14: use App\Notifications\CustomerPasswordReset;
 15: use App\Rules\EmailNotUsedByOtherAccountType;
 16: use App\Rules\UniqueCustomerEmail;
 17: use Illuminate\Support\Facades\DB;
 18: use Illuminate\Support\Facades\Hash;
 19: use Illuminate\Support\Facades\Validator;
 20: use Illuminate\Support\Str;
 21: use Illuminate\Validation\ValidationException;
 22: 
 23: /**
 24:  * Customer authentication lifecycle coordinator (retail store and wholesale portal).
 25:  * Handles self-registration, credential authentication, password recovery, and token issuance.
 26:  * Security boundaries: sensitive attributes (type/status) are strictly assigned programmatically.
 27:  */
 28: class CustomerAuthService
 29: {
 30:     /** Resolve a unique trader contact email without treating it as verified. */
 31:     public function findForLogin(string $identifier): ?Customer
 32:     {
 33:         if (! str_contains($identifier, '@')) {
 34:             return Customer::query()->where('phone', Customer::normalizePhone($identifier))->first();
 35:         }
 36: 
 37:         $query = Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($identifier))]);
 38:         $verified = (clone $query)->whereNotNull('email_verified_at')->first();
 39:         if ($verified !== null) {
 40:             return $verified;
 41:         }
 42:         $contacts = $query->limit(2)->get();
 43:         $customer = $contacts->count() === 1 ? $contacts->first() : null;
 44: 
 45:         return $customer !== null && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
 46:                 ? $customer : null;
 47:     }
 48: 
 49:     /**
 50:      * Generate or return a cached dummy bcrypt/hasher hash to prevent user-enumeration timing attacks.
 51:      */
 52:     public static function dummyHash(): string
 53:     {
 54:         if (config('hashing.driver', 'bcrypt') === 'bcrypt') {
 55:             return sprintf('$2y$%02d$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', (int) config('hashing.bcrypt.rounds', 12));
 56:         }
 57:         static $hashes = [];
 58:         $key = json_encode(config('hashing'));
 59: 
 60:         return $hashes[$key] ??= Hash::make('not-a-customer-password');
 61:     }
 62: 
 63:     /**
 64:      * Compute HMAC key for phone/email to prevent rainbow-table reversal on small phone number spaces in audit logs.
 65:      */
 66:     private function identifierKey(string $identifier): string
 67:     {
 68:         return hash_hmac('sha256', $identifier, (string) config('app.key'));
 69:     }
 70: 
 71:     /**
 72:      * Self-register a retail store customer.
 73:      * Enforces Retail type and Approved status unconditionally.
 74:      */
 75:     public function registerRetail(array $data): Customer
 76:     {
 77:         try {
 78:             return DB::transaction(function () use ($data): Customer {
 79:                 $this->lockRegistrationEmail($data['email'] ?? null);
 80:                 $verified = app(CustomerPhoneVerificationService::class)->consume($data);
 81: 
 82:                 $customer = Customer::query()->make([
 83:                     'name' => $data['name'],
 84:                     'email' => $data['email'] ?? null,
 85:                     'phone' => $data['phone'],
 86:                     'password' => $data['password'],
 87:                     'type' => CustomerType::Retail,
 88:                     'phone_verified_at' => $verified ? now() : null,
 89:                 ]);
 90:                 $customer->forceFill(['status' => CustomerStatus::Approved, 'approved_at' => now()])->save();
 91:                 CustomerAuditLog::record($customer, 'self_registered', null, ['channel' => 'store']);
 92: 
 93:                 return $customer;
 94:             });
 95:         } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
 96:             throw $this->translateRegistrationConflict($e);
 97:         }
 98:     }
 99: 
100:     /** Recheck registration rules while reserving the email until the surrounding transaction commits. */
101:     /** M9: تسجيلين متزامنين بنفس الموبايل → 23505 من الـ unique index؛ نحوله لخطأ تحقق بدل 500. */
102:     public function translateRegistrationConflict(\Throwable $e): \Throwable
103:     {
104:         if ($e instanceof \Illuminate\Database\UniqueConstraintViolationException && str_contains($e->getMessage(), 'phone')) {
105:             return ValidationException::withMessages(['phone' => 'رقم الموبايل مسجل بالفعل.']);
106:         }
107: 
108:         return $e;
109:     }
110: 
111:     public function lockRegistrationEmail(?string $email): void
112:     {
113:         if ($email === null || trim($email) === '') {
114:             return;
115:         }
116:         if (DB::transactionLevel() === 0) {
117:             throw new \LogicException('Registration email claims require a transaction.');
118:         }
119:         $email = mb_strtolower(trim($email));
120:         DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$email]);
121:         Validator::make(['email' => $email], [
122:             'email' => [new UniqueCustomerEmail, new EmailNotUsedByOtherAccountType('customers')],
123:         ])->validate();
124:     }
125: 
126:     /**
127:      * Authenticate customer credentials via phone or verified email.
128:      * Suspended accounts are rejected with 403; pending/rejected wholesale accounts may log in
129:      * to check application status, with channel guards restricting subsequent actions.
130:      *
131:      * @throws BusinessException If credentials invalid or account suspended
132:      */
133:     public function login(string $identifier, string $password): Customer
134:     {
135:         $emailLogin = str_contains($identifier, '@');
136:         $identifier = $emailLogin ? mb_strtolower(trim($identifier)) : Customer::normalizePhone($identifier);
137:         $dummyHash = self::dummyHash();
138:         $customer = $this->findForLogin($identifier);
139: 
140:         $validPassword = Hash::check($password, $customer?->password ?? $dummyHash);
141:         if ($customer === null || ! $validPassword) {
142:             // Avoid inserting unbounded audit log rows for nonexistent identifiers during stuffing attacks;
143:             // log sampled info metric instead, keyed by HMAC.
144:             if ($customer === null) {
145:                 if (random_int(1, 10) === 1) {
146:                     logger()->info('customer.login_failed', ['identifier_key' => $this->identifierKey($identifier)]);
147:                 }
148:             } else {
149:                 defer(fn () => CustomerAuditLog::record($customer, 'login_failed', null, ['identifier_key' => $this->identifierKey($identifier)]))->always();
150:             }
151:             throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
152:         }
153: 
154:         if ($customer->status === CustomerStatus::Suspended) {
155:             CustomerAuditLog::record($customer, 'login_failed', null, ['reason' => 'suspended']);
156:             throw new BusinessException(
157:                 'هذا الحساب موقوف.'.($customer->suspension_reason ? ' السبب: '.$customer->suspension_reason : ''),
158:                 ['suspension_reason' => $customer->suspension_reason],
159:                 403,
160:             );
161:         }
162: 
163:         if (Hash::needsRehash($customer->password)) {
164:             $original = $customer->password;
165:             $replacement = Hash::make($password);
166:             if ($customer->newQuery()->whereKey($customer->id)->where('password', $original)->update(['password' => $replacement]) === 1) {
167:                 $customer->forceFill(['password' => $replacement])->syncOriginalAttribute('password');
168:             } else {
169:                 // L2: جهاز تاني عمل rehash في نفس اللحظة — نتأكد من الـ hash الحالي بدل 401 غلط.
170:                 $fresh = $customer->newQuery()->whereKey($customer->id)->value('password');
171:                 if (! is_string($fresh) || ! Hash::check($password, $fresh)) {
172:                     throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
173:                 }
174:                 $customer->forceFill(['password' => $fresh])->syncOriginalAttribute('password');
175:             }
176:         }
177:         CustomerAuditLog::record($customer, 'login_succeeded', null);
178: 
179:         return $customer;
180:     }
181: 
182:     /**
183:      * Initiate asynchronous password reset workflow.
184:      * Returns generic success regardless of account existence to prevent user enumeration.
185:      */
186:     public function forgotPassword(?string $email, ?string $phone): void
187:     {
188:         if (! $email && ! $phone) {
189:             return;
190:         }
191: 
192:         SendCustomerPasswordReset::dispatch($email, $phone)
193:             ->onConnection(config('auth.customer_password_reset.connection', 'database'));
194:     }
195: 
196:     /**
197:      * Generate password reset token and dispatch notification.
198:      * Enforces token throttling and message budget limits.
199:      */
200:     public function sendPasswordReset(?string $email, ?string $phone): void
201:     {
202:         if (! $email && ! $phone) {
203:             return;
204:         }
205: 
206:         $customer = Customer::query()
207:             ->when($email, fn ($q) => $q->whereNotNull('email_verified_at')->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]),
208:                 fn ($q) => $q->where('phone', Customer::normalizePhone($phone)))
209:             ->first();
210: 
211:         if ($customer === null || $customer->status === CustomerStatus::Suspended) {
212:             return;
213:         }
214: 
215:         $plainToken = Str::random(64);
216:         $key = $customer->getEmailForPasswordReset();
217: 
218:         $stored = DB::transaction(function () use ($customer, $key, $plainToken, $email): bool {
219:             $current = Customer::query()->lockForUpdate()->find($customer->id);
220:             if ($current === null || $current->status === CustomerStatus::Suspended) {
221:                 return false;
222:             }
223:             if ($email && ($current->email_verified_at === null || $current->email !== mb_strtolower(trim($email)))) {
224:                 return false;
225:             }
226:             $existing = DB::table('password_reset_tokens')->where('email', $key)->first();
227:             if ($existing && now()->subSeconds(60)->lt($existing->created_at)) {
228:                 return false;
229:             }
230:             if (! app(CustomerMessageBudget::class)->claim('reset:customer:'.$customer->id, sms: ! $email)) {
231:                 return false;
232:             }
233:             DB::table('password_reset_tokens')->updateOrInsert(
234:                 ['email' => $key], ['token' => hash('sha256', $plainToken), 'created_at' => now()],
235:             );
236: 
237:             return true;
238:         });
239:         if (! $stored) {
240:             return;
241:         }
242: 
243:         try {
244:             $customer->notify(new CustomerPasswordReset($plainToken, $email ? 'mail' : CustomerSmsChannel::class));
245:         } catch (\Throwable $exception) {
246:             report($exception);
247:         }
248:     }
249: 
250:     /**
251:      * Validate reset token and update customer password.
252:      * Revokes all active sessions upon successful reset.
253:      *
254:      * @throws BusinessException If token is invalid or expired
255:      */
256:     public function resetPassword(array $data): void
257:     {
258:         $dummyHash = self::dummyHash();
259:         DB::transaction(function () use ($data, $dummyHash): void {
260:             $customer = Customer::query()
261:                 ->when(! empty($data['email']), fn ($q) => $q->whereNotNull('email_verified_at')->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))]),
262:                     fn ($q) => $q->where('phone', $data['phone']))
263:                 ->lockForUpdate()->first();
264:             $key = $customer?->getEmailForPasswordReset();
265:             $row = DB::table('password_reset_tokens')->where('email', $key)->lockForUpdate()->first();
266:             $storedToken = $row?->token ?? '';
267:             $validToken = strlen($storedToken) === 64 ? hash_equals($storedToken, hash('sha256', $data['token']))
268:                 : Hash::check($data['token'], $storedToken ?: $dummyHash);
269:             if ($customer === null || $customer->status === CustomerStatus::Suspended || $row === null
270:                 || $row->created_at === null || now()->subMinutes(60)->gte($row->created_at)
271:                 || now()->lt($row->created_at) || ! $validToken) {
272:                 throw new BusinessException('رمز الاستعادة غير صالح أو انتهت صلاحيته.');
273:             }
274:             if (in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
275:                 && mb_strlen($data['password']) < 12) {
276:                 throw ValidationException::withMessages(['password' => 'كلمة المرور يجب أن تكون 12 حرفًا على الأقل.']);
277:             }
278:             $customer->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
279:             $customer->tokens()->delete();
280:             DB::table('password_reset_tokens')->where('email', $key)->delete();
281:             CustomerAuditLog::record($customer, 'password_reset', null);
282:         });
283:     }
284: 
285:     /**
286:      * Issue a channel-scoped Sanctum access token (e.g. store-token, wholesale-token).
287:      * Prunes expired tokens and limits maximum active concurrent sessions.
288:      *
289:      * @throws BusinessException If account is suspended
290:      */
291:     public function issueToken(Customer $customer, string $name): string
292:     {
293:         return DB::transaction(function () use ($customer, $name): string {
294:             $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
295:             if ($customer->status === CustomerStatus::Suspended) {
296:                 throw new BusinessException('هذا الحساب موقوف.', [], 403);
297:             }
298: 
299:             $expiry = config('sanctum.expiration');
300:             $customer->tokens()->where(fn ($q) => $q->where('expires_at', '<=', now())
301:                 ->when($expiry, fn ($q) => $q->orWhere('created_at', '<=', now()->subMinutes((int) $expiry))))->delete();
302:             $limit = max(1, (int) config('auth.customer_max_tokens', 10));
303:             $old = $customer->tokens()->orderByDesc('id')->skip($limit - 1)->pluck('id');
304:             if ($old->isNotEmpty()) {
305:                 $customer->tokens()->whereIn('id', $old)->delete();
306:             }
307: 
308:             $token = $customer->createToken($name, ['*'], now()->addMinutes(max(1, (int) config('auth.customer_token_minutes', 1440))));
309:             CustomerAuditLog::record($customer, 'token_issued', null, ['token_id' => $token->accessToken->id, 'channel' => $name]);
310: 
311:             return $token->plainTextToken;
312:         });
313:     }
314: }
```

## File: app/Services/CustomerGroupService.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use App\Exceptions\BusinessException;
  8: use App\Models\CustomerGroup;
  9: use App\Models\User;
 10: use Illuminate\Contracts\Pagination\LengthAwarePaginator;
 11: use Illuminate\Database\QueryException;
 12: use Illuminate\Database\UniqueConstraintViolationException;
 13: use Illuminate\Support\Facades\DB;
 14: use Illuminate\Validation\ValidationException;
 15: 
 16: /**
 17:  * Manages customer groups and their wholesale discount configurations.
 18:  */
 19: class CustomerGroupService
 20: {
 21:     /**
 22:      * Paginate customer groups with search filtering and active counts.
 23:      */
 24:     public function paginate(array $filters): LengthAwarePaginator
 25:     {
 26:         return CustomerGroup::query()
 27:             ->withCount('customers')
 28:             ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($search) => $search
 29:                 ->whereLike('name', SearchTerm::pattern($v), caseSensitive: false)
 30:                 ->orWhereLike('code', SearchTerm::pattern($v), caseSensitive: false)))
 31:             ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($q) => $q->where('is_active', $filters['is_active']))
 32:             ->orderBy('name')
 33:             ->orderBy('id')
 34:             ->paginate($filters['per_page'] ?? 15);
 35:     }
 36: 
 37:     /**
 38:      * Create a new customer group record.
 39:      */
 40:     public function create(array $data, ?User $actor = null, array $via = []): CustomerGroup
 41:     {
 42:         $data = array_filter($data, fn ($value, $key) => $value !== null || ! in_array($key, ['discount_percentage', 'is_active'], true), ARRAY_FILTER_USE_BOTH);
 43:         try {
 44:             return DB::transaction(function () use ($data, $actor, $via): CustomerGroup {
 45:                 $group = CustomerGroup::query()->create($data);
 46:                 // L-7: الخصم بيأثر على التسعير.
 47:                 if ($actor !== null) {
 48:                     StaffAdministration::audit($actor, 'customer_group.created', 'customer_group', $group->id, array_keys($data), [], $group->only(array_keys($data)), $via);
 49:                 }
 50: 
 51:                 return $group;
 52:             });
 53:         } catch (UniqueConstraintViolationException) {
 54:             throw ValidationException::withMessages(['code' => 'كود المجموعة مستخدم بالفعل.']); // L-4
 55:         }
 56:     }
 57: 
 58:     /**
 59:      * Update customer group attributes.
 60:      */
 61:     public function update(CustomerGroup $group, array $data, ?User $actor = null, array $via = []): CustomerGroup
 62:     {
 63:         try {
 64:             return DB::transaction(function () use ($group, $data, $actor, $via): CustomerGroup {
 65:                 $group = CustomerGroup::query()->lockForUpdate()->findOrFail($group->id);
 66:                 $before = $group->only(array_keys($data));
 67:                 $group->update($data);
 68:                 // M13: مسار REST كان مش بيسجل؛ مسار المساعد بيسجل بنفسه فمش بيبعت actor.
 69:                 if ($actor !== null) {
 70:                     StaffAdministration::audit($actor, 'customer_group.updated', 'customer_group', $group->id, array_keys($data), $before, $group->only(array_keys($data)), $via);
 71:                 }
 72: 
 73:                 return $group;
 74:             });
 75:         } catch (UniqueConstraintViolationException) {
 76:             throw ValidationException::withMessages(['code' => 'كود المجموعة مستخدم بالفعل.']); // L-4
 77:         }
 78:     }
 79: 
 80:     /**
 81:      * Delete customer group safely under lock, preventing orphaned customer associations.
 82:      *
 83:      * @throws BusinessException If assigned to customers or price lists
 84:      */
 85:     public function delete(CustomerGroup $group, ?User $actor = null): void
 86:     {
 87:         try {
 88:             DB::transaction(function () use ($group, $actor): void {
 89:                 // Lock row before existence check to prevent concurrent customer assignment that would silently lose group via nullOnDelete
 90:                 $group = CustomerGroup::query()->lockForUpdate()->findOrFail($group->id);
 91: 
 92:                 // L-1: العملاء المحذوفين لما يترجعوا لازم يلاقوا مجموعتهم.
 93:                 if ($group->customers()->withTrashed()->exists()) {
 94:                     throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بعملاء؛ انقل العملاء أولًا أو عطّل المجموعة.');
 95:                 }
 96: 
 97:                 if ($group->priceLists()->exists()) {
 98:                     throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بقوائم أسعار.');
 99:                 }
100: 
101:                 $before = $group->only(['name', 'code', 'description', 'discount_percentage', 'is_active']);
102:                 $group->delete();
103:                 if ($actor !== null) {
104:                     StaffAdministration::audit($actor, 'customer_group.deleted', 'customer_group', $group->id, array_keys($before), $before, [], []);
105:                 }
106:             });
107:         } catch (QueryException $e) {
108:             // Convert foreign key violation (PostgreSQL 23503) into friendly 422 business error
109:             if (($e->errorInfo[0] ?? $e->getCode()) === '23503') {
110:                 throw new BusinessException('لا يمكن حذف مجموعة مرتبطة بعملاء أو قوائم أسعار.');
111:             }
112: 
113:             throw $e;
114:         }
115:     }
116: }
```

## File: app/Services/CustomerManagementService.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use App\Enums\CustomerDocumentStatus;
  8: use App\Enums\CustomerDocumentType;
  9: use App\Enums\CustomerStatus;
 10: use App\Enums\CustomerType;
 11: use App\Events\WholesaleApplicationReviewed;
 12: use App\Exceptions\BusinessException;
 13: use App\Models\Customer;
 14: use App\Models\CustomerAuditLog;
 15: use App\Models\CustomerDocument;
 16: use App\Models\CustomerGroup;
 17: use App\Rules\EmailNotUsedByOtherAccountType;
 18: use App\Rules\UniqueCustomerEmail;
 19: use Illuminate\Contracts\Pagination\LengthAwarePaginator;
 20: use Illuminate\Database\UniqueConstraintViolationException;
 21: use Illuminate\Http\UploadedFile;
 22: use Illuminate\Support\Arr;
 23: use Illuminate\Support\Facades\DB;
 24: use Illuminate\Support\Facades\Validator;
 25: use Illuminate\Support\Str;
 26: use Illuminate\Validation\ValidationException;
 27: 
 28: /**
 29:  * Customer administration and lifecycle management (CRUD, wholesale verification, approval, rejection, and suspension).
 30:  * Immediate suspension invalidates all active sessions; rejection keeps tokens active to review rejection reasons.
 31:  */
 32: class CustomerManagementService
 33: {
 34:     private const MAX_ACTIVE_DOCUMENTS = 5;
 35: 
 36:     private const MAX_TOTAL_DOCUMENTS = 10;
 37: 
 38:     /**
 39:      * Paginate customers with search, type, and status filtering.
 40:      */
 41:     public function paginate(array $filters): LengthAwarePaginator
 42:     {
 43:         return Customer::query()
 44:             ->with('customerGroup')
 45:             ->when(($filters['trashed'] ?? null) === 'only', fn ($q) => $q->onlyTrashed())
 46:             ->when($filters['business_only'] ?? false, fn ($q) => $q->whereIn('type', ['wholesale', 'distributor']))
 47:             ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
 48:             ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
 49:             ->when($filters['customer_group_id'] ?? null, fn ($q, $v) => $q->where('customer_group_id', $v))
 50:             ->when($filters['search'] ?? null, fn ($q, $v) => $q->whereLike('search_text', SearchTerm::pattern($v), caseSensitive: false))
 51:             ->orderByDesc('id')
 52:             ->paginate($filters['per_page'] ?? 15)->withQueryString();
 53:     }
 54: 
 55:     /**
 56:      * Retrieve customer with complete profile relations.
 57:      */
 58:     public function find(int $id): Customer
 59:     {
 60:         return Customer::query()
 61:             ->with(['customerGroup', 'documents', 'addresses.city', 'approver'])
 62:             ->findOrFail($id);
 63:     }
 64: 
 65:     /**
 66:      * Merge the execution channel marker (AI vs REST) into audit metadata.
 67:      * REST calls pass an empty $via, so their recorded metadata stays
 68:      * byte-identical to before; AI calls carry channel/conversation/action.
 69:      */
 70:     private function viaMeta(array $via, array $meta = []): array
 71:     {
 72:         return $via === [] ? $meta : [...$meta, 'via' => $via];
 73:     }
 74: 
 75:     /** M-1: نفس مفتاح القفل بتاع lockRegistrationEmail وSerializesAccountEmail + إعادة فحص تحت القفل. */
 76:     private function claimEmail(?string $email, ?int $ignoreId = null): void
 77:     {
 78:         if ($email === null || trim($email) === '') {
 79:             return;
 80:         }
 81:         $email = mb_strtolower(trim($email));
 82:         DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$email]);
 83:         Validator::make(['email' => $email], [
 84:             'email' => [new UniqueCustomerEmail($ignoreId), new EmailNotUsedByOtherAccountType('customers')],
 85:         ])->validate();
 86:     }
 87: 
 88:     /** L-4: تعارض متزامن على فهرس فريد → 422 بدل 500. */
 89:     private function translateUniqueViolation(UniqueConstraintViolationException $e): \Throwable
 90:     {
 91:         $message = $e->getMessage();
 92: 
 93:         return match (true) {
 94:             str_contains($message, 'phone') => ValidationException::withMessages(['phone' => 'رقم الموبايل مسجل بالفعل.']),
 95:             str_contains($message, 'email') => ValidationException::withMessages(['email' => 'البريد الإلكتروني مستخدم بالفعل.']),
 96:             default => $e,
 97:         };
 98:     }
 99: 
100:     /** L-8: منع تعيين مجموعة معطلة (يغطي REST والوكيل). */
101:     private function assertActiveGroup(?int $groupId): void
102:     {
103:         if ($groupId !== null && ! CustomerGroup::query()->whereKey($groupId)->where('is_active', true)->exists()) {
104:             throw new BusinessException('مجموعة العملاء غير موجودة أو معطلة.', ['customer_group_id' => ['اختر مجموعة مفعّلة.']]);
105:         }
106:     }
107: 
108:     /**
109:      * Create customer record from admin console.
110:      * Enforces Approved status for retail and Pending for wholesale accounts.
111:      *
112:      * @throws BusinessException If attempting to directly create an approved wholesale account
113:      */
114:     public function create(array $data, ?int $actorId = null, array $via = []): Customer
115:     {
116:         $data['type'] ??= CustomerType::Retail->value;
117:         $data['credit_limit'] ??= 0;
118:         // Default status by type unless explicitly provided: retail is approved, wholesale requires review
119:         $data['status'] ??= ($data['type'] ?? CustomerType::Retail->value) === CustomerType::Retail->value
120:             ? CustomerStatus::Approved
121:             : CustomerStatus::Pending;
122: 
123:         if ($data['status'] === CustomerStatus::Approved || $data['status'] === CustomerStatus::Approved->value) {
124:             if ($data['type'] !== CustomerType::Retail->value) {
125:                 throw new BusinessException('أنشئ حساب الجملة قيد المراجعة ثم استخدم مسار الاعتماد.');
126:             }
127:         }
128: 
129:         try {
130:             return DB::transaction(function () use ($data, $actorId, $via): Customer {
131:                 $this->claimEmail($data['email'] ?? null);
132:                 $this->assertActiveGroup(isset($data['customer_group_id']) ? (int) $data['customer_group_id'] : null);
133:                 if (in_array($data['status'], [CustomerStatus::Approved, CustomerStatus::Approved->value], true)) {
134:                     $data['approved_at'] = now();
135:                     $data['approved_by'] = $actorId;
136:                 }
137:                 $customer = (new Customer)->forceFill($data);
138:                 $customer->save();
139:                 CustomerAuditLog::record($customer, 'created', $actorId, $this->viaMeta($via));
140: 
141:                 return $customer->load('customerGroup');
142:             });
143:         } catch (UniqueConstraintViolationException $e) {
144:             throw $this->translateUniqueViolation($e);
145:         }
146:     }
147: 
148:     /**
149:      * Update customer profile, credentials, and business identity.
150:      * Invalidates active sessions and reset tokens if credentials change.
151:      */
152:     public function update(Customer $customer, array $data, ?int $actorId = null, array $via = []): Customer
153:     {
154:         try {
155:             return DB::transaction(function () use ($customer, $data, $actorId, $via): Customer {
156:                 $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
157:                 if (isset($data['email']) && $data['email'] !== $customer->email) {
158:                     $this->claimEmail($data['email'], $customer->id);
159:                 }
160:                 if (array_key_exists('customer_group_id', $data) && $data['customer_group_id'] !== null
161:                     && (int) $data['customer_group_id'] !== (int) $customer->customer_group_id) {
162:                     $this->assertActiveGroup((int) $data['customer_group_id']);
163:                 }
164:                 if (isset($data['type']) && $data['type'] !== $customer->type->value) {
165:                     throw new BusinessException('تغيير نوع الحساب المعتمد يتطلب استخدام مسار الاعتماد.');
166:                 }
167:                 if ($customer->status === CustomerStatus::Approved && $customer->type !== CustomerType::Retail) {
168:                     foreach (['company_name', 'tax_number', 'commercial_register'] as $field) {
169:                         if (array_key_exists($field, $data) && blank($data[$field])) {
170:                             throw new BusinessException('لا يمكن مسح بيانات الجملة لحساب معتمد.', [$field => ['هذا الحقل مطلوب.']]);
171:                         }
172:                     }
173:                 }
174:                 $businessIdentityChanged = collect(['company_name', 'tax_number', 'commercial_register'])
175:                     ->contains(fn ($field) => array_key_exists($field, $data) && $data[$field] !== $customer->{$field});
176:                 $credentialsChanged = array_key_exists('password', $data)
177:                     || (isset($data['email']) && $data['email'] !== $customer->email)
178:                     || (isset($data['phone']) && $data['phone'] !== $customer->phone)
179:                     || (array_key_exists('email', $data) && $data['email'] === null && $customer->email !== null);
180:                 if (isset($data['phone']) && $data['phone'] !== $customer->phone) {
181:                     $customer->forceFill(['phone_verified_at' => null]);
182:                 }
183:                 // M13: القيمة القديمة والجديدة للحقول المالية.
184:                 $financial = [];
185:                 if (array_key_exists('credit_limit', $data) && (float) $data['credit_limit'] !== (float) $customer->credit_limit) {
186:                     $financial['credit_limit'] = ['before' => (string) $customer->credit_limit, 'after' => (string) $data['credit_limit']];
187:                 }
188:                 if (array_key_exists('customer_group_id', $data)) {
189:                     $old = $customer->customer_group_id === null ? null : (int) $customer->customer_group_id;
190:                     $new = $data['customer_group_id'] === null ? null : (int) $data['customer_group_id'];
191:                     if ($old !== $new) {
192:                         $financial['customer_group_id'] = ['before' => $old, 'after' => $new];
193:                     }
194:                 }
195:                 $customer->fill(Arr::except($data, ['credit_limit']));
196:                 if (array_key_exists('credit_limit', $data)) {
197:                     $customer->forceFill(['credit_limit' => $data['credit_limit']]);
198:                 }
199:                 if ($credentialsChanged) {
200:                     $customer->forceFill(['remember_token' => Str::random(60)]);
201:                     $customer->tokens()->delete();
202:                     DB::table('password_reset_tokens')->where('email', $customer->getEmailForPasswordReset())->delete();
203:                 }
204:                 $customer->save();
205:                 CustomerAuditLog::record($customer, $credentialsChanged ? 'credentials_changed' : 'updated', $actorId, $this->viaMeta($via, ['fields' => array_keys($data)] + ($financial !== [] ? ['changes' => $financial] : [])));
206:                 if ($businessIdentityChanged) {
207:                     CustomerAuditLog::record($customer, 'business_identity_changed', $actorId, $this->viaMeta($via, ['fields' => array_keys(array_intersect_key($data, array_flip(['company_name', 'tax_number', 'commercial_register'])))]));
208:                 }
209: 
210:                 return $customer->load('customerGroup');
211:             });
212:         } catch (UniqueConstraintViolationException $e) {
213:             throw $this->translateUniqueViolation($e);
214:         }
215:     }
216: 
217:     /**
218:      * Soft-delete customer and revoke all active tokens/sessions.
219:      */
220:     public function delete(Customer $customer, ?int $actorId = null): void
221:     {
222:         DB::transaction(function () use ($customer, $actorId): void {
223:             $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
224:             $customer->tokens()->delete();
225:             DB::table('password_reset_tokens')->where('email', $customer->getEmailForPasswordReset())->delete();
226:             CustomerAuditLog::record($customer, 'deleted', $actorId);
227:             $customer->delete();
228:         });
229:     }
230: 
231:     /**
232:      * Approve customer application (wholesale/distributor).
233:      * Validates verified supporting documents and assigns group, credit limit, and approver timestamp.
234:      *
235:      * @throws BusinessException If documents unverified or required legal info missing
236:      */
237:     public function approve(Customer $customer, array $data, int $adminId, array $via = []): Customer
238:     {
239:         return DB::transaction(function () use ($customer, $data, $adminId, $via): Customer {
240:             $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
241:             if ($customer->status === CustomerStatus::Suspended) {
242:                 throw new BusinessException('الحساب موقوف؛ استخدم إعادة التفعيل بدل الاعتماد.');
243:             }
244:             $this->assertActiveGroup(array_key_exists('customer_group_id', $data) && $data['customer_group_id'] !== null ? (int) $data['customer_group_id'] : null); // L-8
245:             if ($customer->status === CustomerStatus::Approved && ($data['type'] ?? $customer->type->value) === $customer->type->value) {
246:                 throw new BusinessException('هذا الحساب معتمد بالفعل.');
247:             }
248:             $legal = [];
249:             // H-1: المرفوض مستبعد من القرار ويفضل في السجل للتدقيق؛ المعلّق بس هو اللي يمنع.
250:             if ($customer->documents()->where('status', CustomerDocumentStatus::Pending->value)->exists()) {
251:                 throw new BusinessException('راجع المستندات المعلقة (اعتمد السليم وارفض غير السليم) قبل اعتماد الحساب.');
252:             }
253:             if (($data['type'] ?? $customer->type->value) !== CustomerType::Retail->value) {
254:                 if (! $customer->documents()->where('status', 'verified')->exists()) {
255:                     throw new BusinessException('يلزم مستند موثّق واحد على الأقل لاعتماد حساب الجملة أو الموزع.');
256:                 }
257:                 foreach (['company_name', 'tax_number', 'commercial_register'] as $field) {
258:                     $legal[$field] = $data[$field] ?? $customer->{$field};
259:                     if (blank($legal[$field])) {
260:                         throw new BusinessException('بيانات الجملة مطلوبة قبل الاعتماد.', [$field => ['هذا الحقل مطلوب لاعتماد حساب الجملة أو الموزع.']]);
261:                     }
262:                 }
263:             }
264: 
265:             $customer->forceFill([
266:                 ...$legal,
267:                 'status' => CustomerStatus::Approved,
268:                 'approved_by' => $adminId,
269:                 'approved_at' => now(),
270:                 'rejection_reason' => null,
271:                 'suspension_reason' => null,
272:                 'pre_suspension_status' => null,
273:                 'customer_group_id' => array_key_exists('customer_group_id', $data) ? $data['customer_group_id'] : $customer->customer_group_id,
274:                 'credit_limit' => $data['credit_limit'] ?? $customer->credit_limit,
275:                 'type' => $data['type'] ?? $customer->type,
276:             ])->save();
277:             CustomerAuditLog::record($customer, 'approved', $adminId, $this->viaMeta($via, ['type' => $customer->type->value, 'credit_limit' => $customer->credit_limit, 'customer_group_id' => $customer->customer_group_id]));
278: 
279:             if ($customer->type !== CustomerType::Retail) {
280:                 WholesaleApplicationReviewed::dispatch($customer, 'approved');
281:             }
282: 
283:             return $customer->load(['customerGroup', 'documents']);
284:         });
285:     }
286: 
287:     /**
288:      * Reject a pending customer application with a recorded reason.
289:      *
290:      * @throws BusinessException If customer is not in Pending status
291:      */
292:     public function reject(Customer $customer, string $reason, ?int $actorId = null, array $via = []): Customer
293:     {
294:         return DB::transaction(function () use ($customer, $reason, $actorId, $via): Customer {
295:             $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
296:             if ($customer->status !== CustomerStatus::Pending) {
297:                 throw new BusinessException('يمكن رفض الطلبات قيد المراجعة فقط؛ استخدم الإيقاف للحسابات المعتمدة.');
298:             }
299: 
300:             $customer->forceFill([
301:                 'status' => CustomerStatus::Rejected,
302:                 'rejection_reason' => $reason,
303:             ])->save();
304:             CustomerAuditLog::record($customer, 'rejected', $actorId, $this->viaMeta($via, ['reason' => $reason]));
305: 
306:             if ($customer->type !== CustomerType::Retail) {
307:                 WholesaleApplicationReviewed::dispatch($customer, 'rejected', $reason);
308:             }
309: 
310:             return $customer->load(['customerGroup', 'documents']);
311:         });
312:     }
313: 
314:     /**
315:      * Suspend customer account and revoke all active sessions immediately.
316:      */
317:     public function suspend(Customer $customer, string $reason, ?int $actorId = null, array $via = []): Customer
318:     {
319:         return DB::transaction(function () use ($customer, $reason, $actorId, $via): Customer {
320:             $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
321:             if ($customer->status === CustomerStatus::Suspended) {
322:                 throw new BusinessException('هذا الحساب موقوف بالفعل.');
323:             }
324:             $previous = $customer->status->value;
325:             $customer->forceFill([
326:                 'status' => CustomerStatus::Suspended,
327:                 'suspension_reason' => $reason,
328:                 'pre_suspension_status' => $previous, // M12
329:             ])->save();
330:             $customer->tokens()->delete();
331:             DB::table('password_reset_tokens')->where('email', $customer->getEmailForPasswordReset())->delete();
332:             CustomerAuditLog::record($customer, 'suspended', $actorId, $this->viaMeta($via, ['reason' => $reason, 'previous_status' => $previous]));
333: 
334:             return $customer->load('customerGroup');
335:         });
336:     }
337: 
338:     /**
339:      * Restore a soft-deleted customer account.
340:      */
341:     public function restore(Customer $customer, ?int $actorId, array $via = []): Customer
342:     {
343:         return DB::transaction(function () use ($customer, $actorId, $via): Customer {
344:             $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($customer->id);
345:             if (! $customer->trashed()) {
346:                 throw new BusinessException('الحساب غير محذوف.');
347:             }
348:             // M-1: ممكن حد تاني سجّل بنفس البريد وقت ما الحساب كان محذوف.
349:             try {
350:                 $this->claimEmail($customer->email, $customer->id);
351:             } catch (ValidationException) {
352:                 throw new BusinessException('لا يمكن الاستعادة: البريد '.$customer->email.' مستخدم الآن بحساب نشط آخر. غيّر بريد الحساب الآخر أولاً.');
353:             }
354:             $customer->restore();
355:             CustomerAuditLog::record($customer, 'restored', $actorId, $this->viaMeta($via));
356: 
357:             return $customer->load('customerGroup');
358:         });
359:     }
360: 
361:     /**
362:      * Review supporting customer verification document status (verified/rejected).
363:      */
364:     public function reviewDocument(Customer $customer, CustomerDocument $document, string $status, int $actorId, ?string $note = null, array $via = []): CustomerDocument
365:     {
366:         return DB::transaction(function () use ($customer, $document, $status, $actorId, $note, $via) {
367:             $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
368:             $document = $customer->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
369:             $document->forceFill(['status' => $status, 'reviewed_by' => $actorId, 'reviewed_at' => now(), 'review_note' => $note])->save();
370:             CustomerAuditLog::record($customer, 'document_reviewed', $actorId, $this->viaMeta($via, ['document_id' => $document->id, 'status' => $status]));
371: 
372:             return $document;
373:         });
374:     }
375: 
376:     /**
377:      * 0.1: إعادة تفعيل تاجر موقوف — كان معتمدًا قبل الإيقاف يرجع معتمدًا،
378:      * وإلا يرجع قيد المراجعة. قيد customers_approved_business يحمي من
379:      * إعادة اعتماد تاجر ببيانات قانونية ناقصة.
380:      */
381:     public function reactivate(Customer $customer, ?int $actorId = null, array $via = [], ?string $restoreTo = null): Customer
382:     {
383:         return DB::transaction(function () use ($customer, $actorId, $via, $restoreTo) {
384:             $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
385:             if ($locked->status !== CustomerStatus::Suspended) {
386:                 throw new BusinessException('الحساب مش موقوف.');
387:             }
388:             // M12: نرجّع الحالة اللي كانت قبل الإيقاف — المرفوض يفضل مرفوض وما يرجعش للطابور.
389:             $target = CustomerStatus::tryFrom((string) ($restoreTo ?? $locked->pre_suspension_status));
390:             if ($target === null || $target === CustomerStatus::Suspended) {
391:                 $target = $locked->approved_at ? CustomerStatus::Approved : CustomerStatus::Pending;
392:             }
393:             // M2: فحص صريح بدل الاعتماد على CHECK constraint اللي كان بيرجع 500.
394:             if ($target === CustomerStatus::Approved && $locked->type !== CustomerType::Retail) {
395:                 $missing = array_values(array_filter(['company_name', 'tax_number', 'commercial_register'], fn ($f) => blank($locked->{$f})));
396:                 if ($missing !== []) {
397:                     throw new BusinessException('لا يمكن إعادة تفعيل حساب جملة وبياناته القانونية ناقصة؛ كمّلها الأول.', array_fill_keys($missing, ['هذا الحقل مطلوب.']));
398:                 }
399:             }
400:             $locked->forceFill(['status' => $target, 'suspension_reason' => null, 'pre_suspension_status' => null])->save();
401:             CustomerAuditLog::record($locked, 'reactivated', $actorId, $this->viaMeta($via, ['status' => $target->value]));
402: 
403:             return $locked->fresh(['customerGroup']);
404:         });
405:     }
406: 
407:     /**
408:      * Store and associate a private customer document up to a limit of 5 documents.
409:      */
410:     public function uploadDocument(Customer $customer, UploadedFile $file, ?int $actorId, ?CustomerDocumentType $type = null, bool $selfService = false): CustomerDocument
411:     {
412:         return PrivateAttachments::transaction(function (PrivateAttachments $files) use ($customer, $file, $actorId, $type, $selfService): CustomerDocument {
413:             $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
414:             if ($customer->type === CustomerType::Retail) {
415:                 throw new BusinessException('رفع المستندات متاح للتجار والموزعين فقط.');
416:             }
417:             if ($selfService && $customer->status !== CustomerStatus::Pending) {
418:                 throw new BusinessException('رفع المستندات متاح للطلبات قيد المراجعة فقط.', [], 403);
419:             }
420:             // H-1: الحد على غير المرفوض، مع سقف إجمالي يمنع الإغراق.
421:             $active = $customer->documents()->where('status', '!=', CustomerDocumentStatus::Rejected->value)->count();
422:             $total = $customer->documents()->count();
423:             if ($active >= self::MAX_ACTIVE_DOCUMENTS || $total >= self::MAX_TOTAL_DOCUMENTS) {
424:                 throw new BusinessException('وصلت للحد الأقصى للمستندات ('.self::MAX_ACTIVE_DOCUMENTS.' فعّالة / '.self::MAX_TOTAL_DOCUMENTS.' إجمالي).');
425:             }
426: 
427:             $path = $files->store($file, 'customer_documents/'.$customer->id);
428:             $document = $customer->documents()->create([
429:                 'document_type' => $type ?? CustomerDocumentType::Other,
430:                 'file_path' => $path,
431:                 'original_name' => CustomerDocument::safeName($file->getClientOriginalName()),
432:                 'mime_type' => $file->getMimeType(),
433:                 'status' => CustomerDocumentStatus::Pending,
434:             ]);
435:             CustomerAuditLog::record($customer, 'document_uploaded', $actorId, ['document_id' => $document->id] + ($selfService ? ['channel' => 'wholesale_self'] : []));
436: 
437:             return $document;
438:         }, [[$file, 'customer_documents/'.$customer->id]]);
439:     }
440: }
```

## File: app/Services/CustomerMessageBudget.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Services;
 6: 
 7: use Illuminate\Support\Facades\DB;
 8: 
 9: /**
10:  * Manages daily and hourly outbound SMS/message rate-limit budgets.
11:  * Tracks usage in customer_message_budgets table using atomic pessimistic row locks.
12:  */
13: class CustomerMessageBudget
14: {
15:     /**
16:      * Atomically claim a messaging quota slot for the given scope and channel.
17:      * Enforces global hourly/daily SMS quotas as well as per-recipient limits.
18:      *
19:      * @param  string  $scope  Distinct scope identifier (e.g. "verify:<phone_hash>" or "reset:customer:<id>")
20:      * @param  bool  $sms  Whether this message consumes global SMS quota
21:      */
22:     /** M10/H-2: مفتاح subnet (/24 لـ IPv4، /48 لـ IPv6) — الـ VPS بياخد /48 أو /56 فـ /64 كان سهل التدوير. */
23:     public static function subnetKey(?string $ip): ?string
24:     {
25:         $bin = $ip ? @inet_pton($ip) : false;
26:         if ($bin === false) {
27:             return null;
28:         }
29:         $bin = strlen($bin) === 4 ? substr($bin, 0, 3)."\0" : substr($bin, 0, 6).str_repeat("\0", 10);
30: 
31:         return hash_hmac('sha256', (string) inet_ntop($bin), (string) config('app.key'));
32:     }
33: 
34:     public function claim(string $scope, bool $sms = true, ?string $networkKey = null): bool
35:     {
36:         $now = now(config('app.business_timezone', 'Africa/Cairo'));
37:         $date = $now->toDateString();
38:         $budgets = $sms ? [
39:             'sms:global:day' => (int) config('auth.customer_sms_daily_limit', 1000),
40:             'sms:global:hour:'.$now->format('H') => (int) config('auth.customer_sms_hourly_limit', 100),
41:         ] : [];
42:         if ($sms && str_starts_with($scope, 'verify:')) {
43:             $fraction = max(0, min(0.6, (float) config('auth.customer_sms_verification_fraction', 0.6)));
44:             $budgets['sms:verify:day'] = (int) floor($budgets['sms:global:day'] * $fraction);
45:             $budgets['sms:verify:hour:'.$now->format('H')] = (int) floor($budgets['sms:global:hour:'.$now->format('H')] * $fraction);
46:             // H-2: من غير مفتاح شبكة حد الشبكة بيتعطل بصمت — نرفض بدل ما نفتح الباب.
47:             if ($networkKey === null) {
48:                 logger()->warning('Verification SMS claimed without network key.');
49: 
50:                 return false;
51:             }
52:             $budgets['sms:verify:network:'.$networkKey] = max(0, (int) config('auth.customer_sms_verification_network_daily_limit', 10));
53:             $budgets['sms:verify:network-hour:'.$now->format('H').':'.$networkKey] = max(0, (int) config('auth.customer_sms_verification_network_hourly_limit', 3));
54:         }
55:         $budgets[$scope] = (int) config('auth.customer_daily_messages', 5);
56: 
57:         return DB::transaction(function () use ($budgets, $date): bool {
58:             $claims = [];
59:             foreach ($budgets as $scope => $limit) {
60:                 $key = hash('sha256', $scope);
61:                 DB::table('customer_message_budgets')->insertOrIgnore(['key' => $key, 'date' => $date, 'sends' => 0]);
62:                 $query = DB::table('customer_message_budgets')->where('key', $key)->where('date', $date);
63:                 $row = (clone $query)->lockForUpdate()->first();
64:                 if ($row->sends >= $limit) {
65:                     return false;
66:                 }
67:                 $claims[] = [$query, $scope, (int) $row->sends, $limit];
68:             }
69:             foreach ($claims as [$query, $scope, $sends, $limit]) {
70:                 $query->increment('sends');
71:                 if (str_starts_with($scope, 'sms:') && $sends + 1 === $limit) {
72:                     DB::afterCommit(fn () => logger()->warning('Customer SMS budget exhausted.', ['scope' => $scope, 'date' => $date, 'limit' => $limit]));
73:                 }
74:                 foreach ([0.5, 0.8] as $ratio) {
75:                     $mark = (int) ceil($limit * $ratio);
76:                     if ((str_starts_with($scope, 'sms:global:') || str_starts_with($scope, 'sms:verify:day')) && $sends < $mark && $sends + 1 >= $mark) {
77:                         DB::afterCommit(fn () => logger()->warning('Customer SMS budget reached '.($ratio * 100).'%.', ['scope' => $scope, 'date' => $date, 'limit' => $limit]));
78:                     }
79:                 }
80:             }
81: 
82:             return true;
83:         });
84:     }
85: }
```

## File: app/Services/CustomerPhoneVerificationService.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use App\Exceptions\BusinessException;
  8: use App\Models\Customer;
  9: use App\Notifications\CustomerPhoneVerificationCode;
 10: use Illuminate\Http\Request;
 11: use Illuminate\Support\Facades\DB;
 12: use Illuminate\Support\Facades\Hash;
 13: use Illuminate\Support\Facades\Notification;
 14: use Illuminate\Support\Str;
 15: use Illuminate\Validation\ValidationException;
 16: 
 17: /**
 18:  * Handles phone verification OTP delivery, verification, and proof token validation.
 19:  */
 20: class CustomerPhoneVerificationService
 21: {
 22:     /**
 23:      * Generate and dispatch a 6-digit OTP code to the provided phone number.
 24:      * Enforces a 60-second cooldown and daily message quota per phone key.
 25:      */
 26:     public function send(string $phone, ?string $networkKey = null): void
 27:     {
 28:         // L9: سياسة موحّدة — التسجيل بيكشف وجود الرقم (UX). السكوت هنا لحماية رصيد الـ SMS، مش لمنع الكشف.
 29:         if (Customer::withTrashed()->where('phone', $phone)->exists()) {
 30:             return;
 31:         }
 32:         $code = (string) random_int(100000, 999999);
 33:         $key = $this->phoneKey($phone);
 34:         $created = DB::transaction(function () use ($key, $code, $networkKey): bool {
 35:             DB::table('customer_phone_verifications')->insertOrIgnore([
 36:                 'phone_key' => $key, 'code_hash' => '', 'created_at' => now()->subDay(), 'expires_at' => now()->subDay(),
 37:             ]);
 38:             $row = DB::table('customer_phone_verifications')->where('phone_key', $key)->lockForUpdate()->first();
 39:             if ($row->verified_at !== null && now()->lt($row->expires_at)) {
 40:                 return false;
 41:             }
 42:             if (now()->subSeconds(60)->lt($row->created_at) || ! app(CustomerMessageBudget::class)->claim('verify:'.$key, networkKey: $networkKey)) {
 43:                 return false;
 44:             }
 45:             DB::table('customer_phone_verifications')->where('phone_key', $key)->update([
 46:                 'code_hash' => 'hmac:'.hash_hmac('sha256', $key.':'.$code, (string) config('app.key')), 'proof_hash' => null, 'attempts' => 0, 'verified_at' => null,
 47:                 'created_at' => now(), 'expires_at' => now()->addMinutes(10),
 48:             ]);
 49: 
 50:             return true;
 51:         });
 52:         if ($created) {
 53:             try {
 54:                 Notification::route('customer_sms', $phone)->notify(new CustomerPhoneVerificationCode($code));
 55:                 logger()->info('Customer OTP delivered.');
 56:             } catch (\Throwable $exception) {
 57:                 logger()->error('Customer OTP delivery failed.', ['exception' => $exception::class]);
 58:                 throw $exception;
 59:             }
 60:         }
 61:     }
 62: 
 63:     /**
 64:      * Verify an OTP code and issue a one-time 64-character verification proof token.
 65:      * Enforces a maximum of 5 attempts and a 10-minute expiration window.
 66:      *
 67:      * @throws BusinessException If code is invalid, expired, or maximum attempts exceeded
 68:      */
 69:     public function verify(string $phone, string $code): string
 70:     {
 71:         $proof = DB::transaction(function () use ($phone, $code): ?string {
 72:             $key = $this->phoneKey($phone);
 73:             $row = DB::table('customer_phone_verifications')->where('phone_key', $key)->lockForUpdate()->first();
 74:             if ($row === null || $row->attempts >= 5 || now()->gte($row->expires_at) || $row->verified_at !== null) {
 75:                 return null;
 76:             }
 77:             // Legacy bcrypt challenges remain valid during their ten-minute lifetime.
 78:             $valid = str_starts_with($row->code_hash, 'hmac:')
 79:                 ? hash_equals($row->code_hash, 'hmac:'.hash_hmac('sha256', $key.':'.$code, (string) config('app.key')))
 80:                 : ($row->code_hash !== '' && Hash::check($code, $row->code_hash));
 81:             DB::table('customer_phone_verifications')->where('phone_key', $key)->increment('attempts');
 82:             if (! $valid) {
 83:                 return null;
 84:             }
 85:             $proof = Str::random(64);
 86:             DB::table('customer_phone_verifications')->where('phone_key', $key)->update([
 87:                 'proof_hash' => hash('sha256', $proof), 'verified_at' => now(), 'expires_at' => now()->addMinutes(10),
 88:             ]);
 89: 
 90:             return $proof;
 91:         });
 92:         if ($proof === null) {
 93:             throw new BusinessException('رمز التحقق غير صالح أو انتهت صلاحيته.');
 94:         }
 95: 
 96:         return $proof;
 97:     }
 98: 
 99:     /**
100:      * Validate the presence of a valid phone verification proof token on incoming request.
101:      *
102:      * @throws ValidationException
103:      */
104:     public function requireProof(Request $request): void
105:     {
106:         if (! config('auth.require_phone_verification')) {
107:             return;
108:         }
109:         $phone = $request->input('phone');
110:         $proof = $request->input('phone_verification_token');
111:         if (! is_string($phone) || ! is_string($proof) || strlen($proof) !== 64 || ! $this->validProof($phone, $proof)) {
112:             throw ValidationException::withMessages(['phone_verification_token' => 'تحقق من ملكية رقم الهاتف أولًا.']);
113:         }
114:     }
115: 
116:     /**
117:      * Verify phone ownership proof token against stored hash and check expiration.
118:      */
119:     /** L3: HMAC بدل sha256 خام — نطاق أرقام الموبايل صغير وسهل عكسه. */
120:     private function phoneKey(string $phone): string
121:     {
122:         return hash_hmac('sha256', $phone, (string) config('app.key'));
123:     }
124: 
125:     private function validProof(string $phone, string $proof, bool $consume = false): bool
126:     {
127:         $query = DB::table('customer_phone_verifications')->where('phone_key', $this->phoneKey($phone));
128:         $row = ($consume ? $query->lockForUpdate() : $query)->first();
129:         $stored = $row?->proof_hash ?? '';
130:         $matches = strlen($stored) === 64 ? hash_equals($stored, hash('sha256', $proof))
131:             : ($stored !== '' && Hash::check($proof, $stored));
132:         $valid = $matches && $row !== null && $row->verified_at !== null && now()->lt($row->expires_at);
133:         if ($valid && $consume) {
134:             $query->delete();
135:         }
136: 
137:         return $valid;
138:     }
139: 
140:     /**
141:      * Validate and atomically consume the phone verification token during registration.
142:      *
143:      * @throws BusinessException If token is missing, invalid, or expired
144:      */
145:     public function consume(array $data): bool
146:     {
147:         if (! config('auth.require_phone_verification')) {
148:             return false;
149:         }
150:         if (! $this->validProof($data['phone'], $data['phone_verification_token'] ?? '', true)) {
151:             throw new BusinessException('إثبات ملكية الهاتف غير صالح أو تم استخدامه.');
152:         }
153: 
154:         return true;
155:     }
156: 
157:     /** L-10: فحص رخيص غير مستهلك قبل رفع الملفات لـ R2. */
158:     public function assertProofPresent(array $data): void
159:     {
160:         if (! config('auth.require_phone_verification')) {
161:             return;
162:         }
163:         $proof = $data['phone_verification_token'] ?? '';
164:         if (! is_string($proof) || strlen($proof) !== 64 || ! $this->validProof($data['phone'], $proof)) {
165:             throw new BusinessException('إثبات ملكية الهاتف غير صالح أو تم استخدامه.');
166:         }
167:     }
168: }
```

## File: app/Services/PrivateAttachments.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use Illuminate\Http\UploadedFile;
  8: use Illuminate\Support\Facades\DB;
  9: use Illuminate\Support\Facades\Storage;
 10: use Illuminate\Support\Str;
 11: use RuntimeException;
 12: use Throwable;
 13: 
 14: /**
 15:  * Transactional file upload manager for private cloud storage (R2).
 16:  *
 17:  * Automatically tracks and deletes uploaded files if the database transaction fails.
 18:  */
 19: class PrivateAttachments
 20: {
 21:     private array $stored = [];
 22: 
 23:     private array $prepared = [];
 24: 
 25:     private array $claimed = [];
 26: 
 27:     /**
 28:      * Execute a callback within a transactional scope, deleting stored files if rolled back.
 29:      *
 30:      * @param  callable(self): mixed  $callback
 31:      *
 32:      * @throws Throwable
 33:      */
 34:     public static function transaction(callable $callback, array $uploads = []): mixed
 35:     {
 36:         $files = new self;
 37:         try {
 38:             foreach ($uploads as [$file, $directory]) {
 39:                 if ($file instanceof UploadedFile) {
 40:                     if (isset($files->prepared[spl_object_id($file)])) {
 41:                         continue;
 42:                     }
 43:                     $files->prepared[spl_object_id($file)] = $files->store($file, $directory.'/'.Str::uuid());
 44:                 }
 45:             }
 46:         } catch (Throwable $exception) {
 47:             $files->deleteStoredQuietly();
 48:             throw $exception;
 49:         }
 50: 
 51:         // A successful savepoint is not a committed operation. Keep rollback
 52:         // cleanup attached to an existing outer transaction. Invoke this API
 53:         // before opening an outer transaction to keep network I/O outside it.
 54:         // The attachment sweeper handles process death and failed deletes.
 55:         if (DB::transactionLevel() > 0) {
 56:             $pending = $files;
 57:             DB::afterRollBack(static function () use ($pending): void {
 58:                 $pending->deleteStoredQuietly();
 59:             });
 60:         }
 61: 
 62:         try {
 63:             $result = DB::transaction(fn () => $callback($files));
 64:             $unused = array_diff(array_values($files->prepared), $files->claimed);
 65:             $files->deletePathsQuietly($unused);
 66:             $files->stored = array_values(array_diff($files->stored, $unused));
 67: 
 68:             return $result;
 69:         } catch (Throwable $exception) {
 70:             // H2: never let a failing R2 cleanup replace the original error
 71:             // (disk has throw => true). The caller must see the real cause.
 72:             $files->deleteStoredQuietly();
 73: 
 74:             throw $exception;
 75:         }
 76:     }
 77: 
 78:     /**
 79:      * Store an uploaded file on private R2 storage and track it for transaction rollback cleanup.
 80:      *
 81:      *
 82:      * @throws RuntimeException
 83:      */
 84:     public function store(UploadedFile $file, string $directory): string
 85:     {
 86:         if (isset($this->prepared[spl_object_id($file)])) {
 87:             $path = $this->prepared[spl_object_id($file)];
 88:             $this->claimed[] = $path;
 89: 
 90:             return $path;
 91:         }
 92:         $path = $file->store($directory, 'r2');
 93:         if ($path === false) {
 94:             throw new RuntimeException('تعذر حفظ المرفق؛ لم يتم تسجيل العملية.');
 95:         }
 96:         $this->stored[] = $path;
 97: 
 98:         return $path;
 99:     }
100: 
101:     /**
102:      * Best-effort R2 cleanup that never throws: a failing delete must not
103:      * replace the original business exception (disk has throw => true).
104:      */
105:     public function deleteStoredQuietly(): void
106:     {
107:         if ($this->stored === []) {
108:             return;
109:         }
110: 
111:         $paths = $this->stored;
112:         $this->stored = [];
113: 
114:         $this->deletePathsQuietly($paths);
115:     }
116: 
117:     private function deletePathsQuietly(array $paths): void
118:     {
119:         if ($paths === []) {
120:             return;
121:         }
122:         try {
123:             Storage::disk('r2')->delete($paths);
124:         } catch (Throwable $exception) {
125:             report($exception);
126:         }
127:     }
128: }
```

## File: app/Services/SearchTerm.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Services;
 6: 
 7: /**
 8:  * Escapes user input to treat SQL LIKE wildcard characters as literal strings.
 9:  */
10: class SearchTerm
11: {
12:     public static function escape(string $term): string
13:     {
14:         return strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
15:     }
16: 
17:     public static function pattern(string $term): string
18:     {
19:         return '%'.self::escape($term).'%';
20:     }
21: 
22:     public static function prefix(string $term): string
23:     {
24:         return self::escape($term).'%';
25:     }
26: }
```

## File: app/Services/StaffAdministration.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use App\Enums\StaffRole;
  8: use App\Models\Branch;
  9: use App\Models\User;
 10: use Illuminate\Database\Eloquent\Model;
 11: use Illuminate\Database\QueryException;
 12: use Illuminate\Support\Facades\DB;
 13: use Illuminate\Validation\ValidationException;
 14: use Spatie\Permission\Models\Role;
 15: use Spatie\Permission\PermissionRegistrar;
 16: 
 17: /**
 18:  * Staff administration guardrails and security coordinator.
 19:  * Manages concurrency locks, optimistic locking, role invariants, and audit logging.
 20:  */
 21: final class StaffAdministration
 22: {
 23:     /**
 24:      * Acquire a transactional PostgreSQL advisory lock to serialize staff membership and role changes.
 25:      * Prevents race conditions during critical checks such as last-active-admin validation.
 26:      */
 27:     public static function lock(): void
 28:     {
 29:         // L3: SET LOCAL يسري على الـ transaction كلها — 55P03 (lock timeout)
 30:         // تتحول لـ 409 قابلة لإعادة المحاولة بدل 500.
 31:         try {
 32:             DB::statement("SET LOCAL lock_timeout = '5s'");
 33:             DB::select('SELECT pg_advisory_xact_lock(79301, 1)');
 34:         } catch (QueryException $e) {
 35:             if (($e->errorInfo[0] ?? null) === '55P03') {
 36:                 abort(409, 'النظام مشغول حاليًا؛ حاول مرة أخرى.');
 37:             }
 38:             throw $e;
 39:         }
 40:     }
 41: 
 42:     /**
 43:      * Verify record lock_version matches expected optimistic concurrency version.
 44:      */
 45:     public static function assertVersion(Model $record, ?int $expected): void
 46:     {
 47:         // Detects concurrent permission/profile updates occurring within the same second
 48:         abort_if($expected !== null && (int) $record->lock_version !== $expected, 409,
 49:             'تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.');
 50:     }
 51: 
 52:     /**
 53:      * Increment monotonic lock_version counter for optimistic concurrency tracking.
 54:      */
 55:     public static function advanceVersion(Model $record): void
 56:     {
 57:         $record->forceFill(['lock_version' => (int) $record->lock_version + 1])->save();
 58:     }
 59: 
 60:     /**
 61:      * Invalidate cached Spatie permissions across all application instances after commit.
 62:      */
 63:     public static function clearPermissionCacheAfterCommit(): void
 64:     {
 65:         DB::afterCommit(function (): void {
 66:             try {
 67:                 app(PermissionRegistrar::class)->forgetCachedPermissions();
 68:             } catch (\Throwable $exception) {
 69:                 report($exception);
 70:                 logger()->error('staff.permission_cache_invalidation_failed');
 71:             }
 72:         });
 73:     }
 74: 
 75:     /**
 76:      * Capture snapshot of user role assignments, branch scoping, and active status.
 77:      *
 78:      * @return array{roles: list<string>, branch_id: int|null, is_active: bool}
 79:      */
 80:     public static function userState(User $user): array
 81:     {
 82:         return ['roles' => $user->roles()->orderBy('name')->pluck('name')->all(),
 83:             // L-5: البريد مقنّع (أول حرفين + النطاق) — تغيير بريد موظف
 84:             // هو الطريق الطبيعي للاستيلاء على حساب عبر استعادة كلمة
 85:             // المرور، فلا بد أن يظهر في before/after، لكن بلا قيمة كاملة.
 86:             'email' => self::maskEmail($user->email),
 87:             'branch_id' => $user->branch_id, 'is_active' => (bool) $user->is_active];
 88:     }
 89: 
 90:     /**
 91:      * Capture snapshot of role definition and its assigned permissions.
 92:      *
 93:      * @return array{name: string, permissions: list<string>}
 94:      */
 95:     /** @return array{name: string, is_active: bool} */
 96:     public static function mayGrantPermissions(?User $actor, array $permissions): bool
 97:     {
 98:         if (! $actor instanceof User) {
 99:             return false;
100:         }
101:         $actor->unsetRelation('roles')->unsetRelation('permissions');
102: 
103:         return $actor->hasRole(StaffRole::SuperAdmin->value, 'staff') || $actor->hasAllPermissions(array_values(array_unique($permissions)));
104:     }
105: 
106:     /** @param list<string> $roles */
107:     public static function mayManageUser(mixed $actor, ?User $target = null, array $roles = []): bool
108:     {
109:         if (! $actor instanceof User) {
110:             return false;
111:         }
112:         $actor->unsetRelation('roles')->unsetRelation('permissions');
113:         if ($target?->is_system) {
114:             return false;
115:         }
116:         if ($actor->hasRole(StaffRole::SuperAdmin->value, 'staff')) {
117:             return true;
118:         }
119:         if (in_array(StaffRole::SuperAdmin->value, $roles, true) || $target?->hasRole(StaffRole::SuperAdmin->value, 'staff')) {
120:             return false;
121:         }
122:         $permissions = Role::query()->where('guard_name', 'staff')->whereIn('name', $roles)->with('permissions')->get()
123:             ->flatMap(fn (Role $role) => $role->permissions->pluck('name'));
124:         if ($target !== null) {
125:             $permissions = $permissions->merge($target->getAllPermissions()->pluck('name'));
126:         }
127: 
128:         return $actor->hasAllPermissions($permissions->unique()->all());
129:     }
130: 
131:     public static function branchState(Branch $branch): array
132:     {
133:         return ['name' => $branch->name, 'is_active' => (bool) $branch->is_active];
134:     }
135: 
136:     public static function roleState(Role $role): array
137:     {
138:         return ['name' => $role->name, 'permissions' => $role->permissions()->orderBy('name')->pluck('name')->all()];
139:     }
140: 
141:     /**
142:      * Shared-lock roles in deterministic ID order to ensure they exist and prevent deletion during assignment.
143:      *
144:      * @param  list<string>  $names
145:      */
146:     public static function lockRoles(array $names): void
147:     {
148:         $roles = Role::query()->where('guard_name', 'staff')->whereIn('name', $names)
149:             ->orderBy('id')->sharedLock()->get();
150:         if ($roles->count() !== count(array_unique($names))) {
151:             throw ValidationException::withMessages(['roles' => 'قائمة الأدوار تغيرت؛ أعد التحميل ثم حاول مرة أخرى.']);
152:         }
153:     }
154: 
155:     /**
156:      * Re-verify active status and permission grant against database state.
157:      *
158:      * @param  list<string>  $permissions
159:      */
160:     public static function authorize(User $actor, array $permissions): void
161:     {
162:         $actor->refresh();
163:         abort_unless($actor->is_active && ! $actor->is_system && $actor->canAny($permissions), 403);
164:     }
165: 
166:     /**
167:      * Check if another active, non-system SuperAdmin user exists.
168:      * Used to prevent orphaning the system by deactivating or demoting the last admin.
169:      */
170:     public static function hasOtherActiveSuperAdmin(User $target): bool
171:     {
172:         return User::query()->whereKeyNot($target->id)->where('is_active', true)->where('is_system', false)
173:             ->whereHas('roles', fn ($query) => $query->where('name', StaffRole::SuperAdmin->value)->where('guard_name', 'staff'))
174:             ->exists();
175:     }
176: 
177:     /**
178:      * Record an administrative audit entry with sanitized before/after metadata.
179:      *
180:      * @param  list<string>  $fields
181:      * @param  array<string, mixed>  $before
182:      * @param  array<string, mixed>  $after
183:      */
184:     public static function maskEmail(mixed $email): ?string
185:     {
186:         if (! is_string($email) || trim($email) === '') {
187:             return null;
188:         }
189:         [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
190: 
191:         return mb_substr($local, 0, 2).'***'.($domain !== '' ? '@'.$domain : '');
192:     }
193: 
194:     public static function audit(?User $actor, string $action, string $targetType, int|string|null $targetId, array $fields, array $before = [], array $after = [], array $via = []): void
195:     {
196:         $allowed = array_flip(['roles', 'permissions', 'name', 'branch_id', 'is_active', 'settings', 'code', 'type', 'is_active_branch', 'email', 'discount_percentage', 'description']);
197:         $before = array_intersect_key($before, $allowed);
198:         $after = array_intersect_key($after, $allowed);
199:         if (array_key_exists('email', $before)) {
200:             $before['email'] = self::maskEmail($before['email']);
201:         }
202:         if (array_key_exists('email', $after)) {
203:             $after['email'] = self::maskEmail($after['email']);
204:         }
205:         DB::table('staff_audit_logs')->insert([
206:             'actor_id' => $actor?->id,
207:             'action' => $action,
208:             'target_type' => $targetType,
209:             'target_id' => $targetId,
210:             // Sensitive fields (passwords, tokens) are stripped; only allowed state keys are stored
211:             'metadata' => json_encode(['fields' => array_values(array_diff($fields, ['password', 'current_password', 'expected_version'])),
212:                 'before' => $before, 'after' => $after,
213:                 'via' => array_intersect_key($via, array_flip(['channel', 'conversation_id', 'action_id'])),
214:                 'ip' => request()->ip(), 'method' => request()->method()], JSON_THROW_ON_ERROR),
215:             'created_at' => now(),
216:         ]);
217:     }
218: }
```

## File: app/Services/WholesaleRegistrationService.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use App\Enums\CustomerDocumentStatus;
  8: use App\Enums\CustomerDocumentType;
  9: use App\Enums\CustomerStatus;
 10: use App\Enums\CustomerType;
 11: use App\Events\WholesaleApplicationSubmitted;
 12: use App\Models\Customer;
 13: use App\Models\CustomerAuditLog;
 14: use App\Models\CustomerDocument;
 15: use Illuminate\Http\UploadedFile;
 16: use Illuminate\Support\Facades\DB;
 17: use Illuminate\Support\Facades\Storage;
 18: use Illuminate\Support\Str;
 19: 
 20: /**
 21:  * Wholesale customer self-registration service.
 22:  * Registers customer in Pending status and stages uploaded verification documents into private storage.
 23:  * Final approval is performed by administrators via CustomerManagementService.
 24:  */
 25: class WholesaleRegistrationService
 26: {
 27:     public function __construct(private readonly CustomerAuthService $auth) {}
 28: 
 29:     /**
 30:      * Register a new wholesale customer and store supporting verification documents.
 31:      * Staged files are moved into customer directory upon successful transaction.
 32:      *
 33:      * @param  list<UploadedFile>  $files
 34:      * @param  list<string|null>  $documentTypes  Parallel document type tags (defaults to "other")
 35:      * @return array{customer: Customer, token: string}
 36:      */
 37:     public function register(array $data, array $files, array $documentTypes = []): array
 38:     {
 39:         $files = array_values($files);
 40:         $documentTypes = array_values($documentTypes);
 41:         $storedPaths = [];
 42:         $staged = [];
 43:         $stagingDirectory = 'customer_documents/pending/'.Str::uuid();
 44: 
 45:         // L-10: الاستهلاك الفعلي لسه جوه الـ transaction.
 46:         app(CustomerPhoneVerificationService::class)->assertProofPresent($data);
 47: 
 48:         try {
 49:             foreach ($files as $i => $file) {
 50:                 $filename = Str::uuid().'.'.($file->extension() ?: 'bin');
 51:                 $path = Storage::disk('r2')->putFileAs($stagingDirectory, $file, $filename);
 52:                 if ($path === false) {
 53:                     throw new \RuntimeException('تعذر حفظ مستند العميل.');
 54:                 }
 55:                 $storedPaths[] = $path;
 56:                 $staged[] = [
 57:                     'filename' => $filename, 'path' => $path,
 58:                     'document_type' => $documentTypes[$i] ?? CustomerDocumentType::Other->value,
 59:                     'original_name' => CustomerDocument::safeName($file->getClientOriginalName()),
 60:                     'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
 61:                 ];
 62:             }
 63: 
 64:             // Reserve the sequence value before storage I/O, without holding row locks.
 65:             $customerId = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('customers', 'id')) AS id")->id;
 66:             foreach ($staged as &$document) {
 67:                 $path = "customer_documents/{$customerId}/".$document['filename'];
 68:                 $storedPaths[] = $path;
 69:                 if (! Storage::disk('r2')->move($document['path'], $path)) {
 70:                     throw new \RuntimeException('تعذر حفظ مستند العميل.');
 71:                 }
 72:                 $document['path'] = $path;
 73:             }
 74:             unset($document);
 75: 
 76:             $registration = DB::transaction(function () use ($data, $staged, $customerId): array {
 77:                 $this->auth->lockRegistrationEmail($data['email'] ?? null);
 78:                 $verified = app(CustomerPhoneVerificationService::class)->consume($data);
 79:                 $customer = Customer::query()->make([
 80:                     'name' => $data['name'],
 81:                     'email' => $data['email'] ?? null,
 82:                     'phone' => $data['phone'],
 83:                     'password' => $data['password'],
 84:                     'type' => CustomerType::Wholesale,
 85:                     'phone_verified_at' => $verified ? now() : null,
 86:                     'company_name' => $data['company_name'],
 87:                     'tax_number' => $data['tax_number'],
 88:                     'commercial_register' => $data['commercial_register'],
 89:                 ]);
 90:                 $customer->forceFill(['id' => $customerId, 'status' => CustomerStatus::Pending])->save();
 91: 
 92:                 foreach ($staged as $document) {
 93:                     $customer->documents()->create([
 94:                         'document_type' => $document['document_type'],
 95:                         'file_path' => $document['path'],
 96:                         'original_name' => $document['original_name'],
 97:                         'mime_type' => $document['mime_type'],
 98:                         'status' => CustomerDocumentStatus::Pending,
 99:                     ]);
100:                 }
101: 
102:                 CustomerAuditLog::record($customer, 'self_registered', null, ['channel' => 'wholesale']);
103:                 DB::afterCommit(static function () use ($customer): void {
104:                     try {
105:                         WholesaleApplicationSubmitted::dispatch($customer);
106:                     } catch (\Throwable $exception) {
107:                         // The account is committed; a queue outage must not turn it into a failed registration.
108:                         report($exception);
109:                     }
110:                 });
111: 
112:                 return [
113:                     'customer' => $customer->load('documents'),
114:                     'token' => $this->auth->issueToken($customer, 'wholesale-token'),
115:                 ];
116:             });
117:         } catch (\Throwable $e) {
118:             // Transaction failure: clean up uploaded files on cloud storage to prevent orphaned assets
119:             foreach ($storedPaths as $path) {
120:                 try {
121:                     Storage::disk('r2')->delete($path);
122:                 } catch (\Throwable $exception) {
123:                     report($exception);
124:                 }
125:             }
126:             if ($storedPaths !== []) {
127:                 try {
128:                     Storage::disk('r2')->deleteDirectory($stagingDirectory);
129:                 } catch (\Throwable $exception) {
130:                     report($exception);
131:                 }
132:             }
133: 
134:             throw $this->auth->translateRegistrationConflict($e); // M9 — الملفات اتمسحت فوق
135:         }
136: 
137:         try {
138:             Storage::disk('r2')->deleteDirectory($stagingDirectory);
139:         } catch (\Throwable $exception) {
140:             report($exception);
141:         }
142: 
143:         return $registration;
144:     }
145: }
```

## File: bootstrap/app.php
```php
  1: <?php
  2: 
  3: use App\Http\Controllers\HealthReadyController;
  4: use App\Http\Middleware\AssignRequestId;
  5: use App\Http\Middleware\EnsureCustomerAuthenticated;
  6: use App\Http\Middleware\EnsureOpenPosShift;
  7: use App\Http\Middleware\EnsureStaffAuthenticated;
  8: use App\Http\Middleware\EnsureSupplierAuthenticated;
  9: use App\Http\Middleware\EnsureWholesaleApproved;
 10: use App\Http\Middleware\ManufacturingBusiness;
 11: use Illuminate\Database\Eloquent\ModelNotFoundException;
 12: use Illuminate\Database\QueryException;
 13: use Illuminate\Database\UniqueConstraintViolationException;
 14: use Illuminate\Foundation\Application;
 15: use Illuminate\Foundation\Configuration\Exceptions;
 16: use Illuminate\Foundation\Configuration\Middleware;
 17: use Illuminate\Http\Request;
 18: use Illuminate\Support\Facades\Route;
 19: use Spatie\Permission\Middleware\PermissionMiddleware;
 20: use Spatie\Permission\Middleware\RoleMiddleware;
 21: use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
 22: 
 23: return Application::configure(basePath: dirname(__DIR__))
 24:     ->withRouting(
 25:         web: __DIR__.'/../routes/web.php',
 26:         commands: __DIR__.'/../routes/console.php',
 27:         health: '/up',
 28:         then: function (): void {
 29:             Route::get('health/ready', HealthReadyController::class);
 30:             Route::middleware('api')
 31:                 ->prefix('api/v1/admin')
 32:                 ->group(base_path('routes/api/admin.php'));
 33:             Route::middleware('api')
 34:                 ->prefix('api/v1/store')
 35:                 ->name('store.')
 36:                 ->group(base_path('routes/api/store.php'));
 37:             Route::middleware('api')
 38:                 ->prefix('api/v1/wholesale')
 39:                 ->name('wholesale.')
 40:                 ->group(base_path('routes/api/wholesale.php'));
 41:             // بوابة المورد: مصادقة supplier_users وكل الاستعلامات مقيدة بمورد المستخدم
 42:             Route::middleware('api')
 43:                 ->prefix('api/v1/supplier')
 44:                 ->name('supplier.')
 45:                 ->group(base_path('routes/api/supplier.php'));
 46:             // ويب هوكس خارجية: بلا مصادقة — الحماية بالتوقيع الرقمي داخل المتحكمات
 47:             Route::middleware('api')
 48:                 ->prefix('api/v1/webhooks')
 49:                 ->name('webhooks.')
 50:                 ->group(base_path('routes/api/webhooks.php'));
 51:             // بوابة الـ POS: كاشير الفرع — توكن موظف + وردية مفتوحة لعمليات البيع
 52:             Route::middleware('api')
 53:                 ->prefix('api/v1/pos')
 54:                 ->name('pos.')
 55:                 ->group(base_path('routes/api/pos.php'));
 56:             // الدخول الموحد: users ← supplier_users ← customers + handoff للموظفين
 57:             Route::middleware('api')
 58:                 ->prefix('api/v1/auth')
 59:                 ->name('unified-auth.')
 60:                 ->group(base_path('routes/api/auth.php'));
 61:         },
 62:     )
 63:     ->withMiddleware(function (Middleware $middleware): void {
 64:         // Resolve trusted forwarding headers before recording client_ip.
 65:         $middleware->append([AssignRequestId::class]);
 66:         $middleware->api(prepend: [ManufacturingBusiness::class]);
 67:         $middleware->alias([
 68:             'auth.staff' => EnsureStaffAuthenticated::class,
 69:             'auth.customer' => EnsureCustomerAuthenticated::class,
 70:             'auth.supplier' => EnsureSupplierAuthenticated::class,
 71:             'wholesale.approved' => EnsureWholesaleApproved::class,
 72:             'role' => RoleMiddleware::class,
 73:             'permission' => PermissionMiddleware::class,
 74:             'pos.shift' => EnsureOpenPosShift::class,
 75:         ]);
 76:     })
 77:     ->withExceptions(function (Exceptions $exceptions): void {
 78:         $exceptions->report(function (QueryException $e) {
 79:             $state = (string) ($e->errorInfo[0] ?? $e->getCode());
 80:             if ($state === '57014' || str_starts_with($state, '08')) {
 81:                 logger()->warning('Database operation unavailable.', ['sqlstate' => $state]);
 82: 
 83:                 return false;
 84:             }
 85: 
 86:             return null;
 87:         });
 88:         $exceptions->render(function (QueryException $e, Request $request) {
 89:             $state = (string) ($e->errorInfo[0] ?? $e->getCode());
 90:             if (($request->is('api/*') || $request->expectsJson())
 91:                 && ($state === '57014' || str_starts_with($state, '08'))) {
 92:                 return response()->json(['message' => 'الخدمة غير متاحة مؤقتًا؛ أعد المحاولة بعد لحظات.'], 503)
 93:                     ->header('Retry-After', '1');
 94:             }
 95:             // F7: أخطاء القفل والتزامن (مهلة قفل 55P03، جمود 40P01، فشل
 96:             // تسلسل 40001) رد مضبوط: 503 للاستعلام الآمن مع Retry-After،
 97:             // و409 للكتابة حتى لا تُعاد طفرة بلا idempotency تلقائيًا.
 98:             if (($request->is('api/*') || $request->expectsJson())
 99:                 && in_array($e->errorInfo[0] ?? $e->getCode(), ['55P03', '40P01', '40001'], true)) {
100:                 return response()->json(['message' => 'العملية مشغولة بطلب آخر؛ أعد المحاولة بعد لحظات.'], $request->isMethodSafe() ? 503 : 409)->header('Retry-After', '1');
101:             }
102: 
103:             // M4: حذف مورد له مستخدمون (restrictOnDelete) يرمي 23503 — رد 409
104:             // مفهوم بدل 500 في لوحة الإدارة.
105:             if (($request->is('api/*') || $request->expectsJson())
106:                 && in_array($e->errorInfo[0] ?? $e->getCode(), ['23503'], true)) {
107:                 return response()->json(['message' => 'تعذر تنفيذ العملية لوجود سجلات مرتبطة أو لتغيّر السجل المرجعي؛ حدّث البيانات وأعد المحاولة.'], 409);
108:             }
109: 
110:             if (($request->is('api/*') || $request->expectsJson())
111:                 && ! $e instanceof UniqueConstraintViolationException) {
112:                 return response()->json(['message' => 'تعذر حفظ البيانات بسبب خطأ داخلي؛ أعد المحاولة، وإذا استمرت المشكلة تواصل مع الدعم.'], 500);
113:             }
114: 
115:             return null;
116:         });
117:         $exceptions->render(function (NotFoundHttpException $e, Request $request) {
118:             if ($e->getPrevious() instanceof ModelNotFoundException && ($request->is('api/*') || $request->expectsJson())) {
119:                 return response()->json(['message' => 'العنصر المطلوب غير موجود'], 404);
120:             }
121: 
122:             return null;
123:         });
124:         $exceptions->shouldRenderJsonWhen(
125:             fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
126:         );
127: 
128:         // سباق على قيد unique (فحص exists وبعده إدخال متزامن): القيد سليم، فالرد يبقى 409 متحكم فيه بدل 500
129:         $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
130:             if ($request->is('api/*') || $request->expectsJson()) {
131:                 return response()->json(['message' => 'توجد بيانات متعارضة. أعد المحاولة'], 409);
132:             }
133: 
134:             return null;
135:         });
136:     })->create();
```

## File: config/auth.php
```php
  1: <?php
  2: 
  3: use App\Models\Customer;
  4: use App\Models\SupplierUser;
  5: use App\Models\User;
  6: 
  7: return [
  8:     'require_phone_verification' => env('CUSTOMER_REQUIRE_PHONE_VERIFICATION', false),
  9:     'customer_max_tokens' => (int) env('CUSTOMER_MAX_TOKENS', 10),
 10:     'customer_token_minutes' => (int) env('CUSTOMER_TOKEN_MINUTES', 1440),
 11:     'supplier_max_tokens' => (int) env('SUPPLIER_MAX_TOKENS', 5),
 12:     'staff_token_minutes' => (int) env('STAFF_TOKEN_MINUTES', 120),
 13:     'staff_current_password_failures' => (int) env('STAFF_CURRENT_PASSWORD_FAILURES', 5),
 14:     'staff_admin_mutations_minute' => (int) env('STAFF_ADMIN_MUTATIONS_MINUTE', 60),
 15:     'staff_audit_retention_days' => (int) env('STAFF_AUDIT_RETENTION_DAYS', 365),
 16:     'staff_max_tokens' => (int) env('STAFF_MAX_TOKENS', 20),
 17:     'legacy_login_enabled' => env('LEGACY_LOGIN_ENABLED', false),
 18:     'unified_login_ip_minute' => (int) env('UNIFIED_LOGIN_IP_MINUTE', 120),
 19:     'unified_login_failures_hour' => (int) env('UNIFIED_LOGIN_FAILURES_HOUR', 10),
 20:     'unified_login_ip_failures_hour' => (int) env('UNIFIED_LOGIN_IP_FAILURES_HOUR', 1000),
 21:     'unified_login_failures_day' => (int) env('UNIFIED_LOGIN_FAILURES_DAY', 100),
 22:     'unified_login_identifier_failures_hour' => (int) env('UNIFIED_LOGIN_IDENTIFIER_FAILURES_HOUR', 30),
 23:     'unified_handoff_ip_minute' => (int) env('UNIFIED_HANDOFF_IP_MINUTE', 60),
 24:     'customer_sms_verification_fraction' => (float) env('CUSTOMER_SMS_VERIFICATION_FRACTION', 0.6),
 25:     'customer_sms_verification_network_daily_limit' => (int) env('CUSTOMER_SMS_VERIFICATION_NETWORK_DAILY_LIMIT', 10),
 26:     'customer_sms_daily_limit' => (int) env('CUSTOMER_SMS_DAILY_LIMIT', 1000),
 27:     'customer_sms_hourly_limit' => (int) env('CUSTOMER_SMS_HOURLY_LIMIT', 100),
 28:     'customer_limits' => [
 29:         'phone_verify_ip_minute' => (int) env('CUSTOMER_PHONE_VERIFY_IP_MINUTE', 120),
 30:         'reset_confirm_ip_minute' => (int) env('CUSTOMER_RESET_CONFIRM_IP_MINUTE', 120),
 31:         'login_ip_minute' => (int) env('CUSTOMER_LOGIN_IP_MINUTE', 120),
 32:         'reset_ip_minute' => (int) env('CUSTOMER_RESET_IP_MINUTE', 20),
 33:         'reset_ip_day' => (int) env('CUSTOMER_RESET_IP_DAY', 500),
 34:         'registration_ip_minute' => (int) env('CUSTOMER_REGISTRATION_IP_MINUTE', 20),
 35:         'registration_ip_day' => (int) env('CUSTOMER_REGISTRATION_IP_DAY', 500),
 36:         'phone_ip_minute' => (int) env('CUSTOMER_PHONE_IP_MINUTE', 20),
 37:         'phone_ip_day' => (int) env('CUSTOMER_PHONE_IP_DAY', 500),
 38:         'catalog_ip_minute' => (int) env('STORE_CATALOG_IP_MINUTE', 600),
 39:         'price_ip_minute' => (int) env('STORE_PRICE_IP_MINUTE', 60),
 40:         'login_id_hour' => (int) env('CUSTOMER_LOGIN_ID_HOUR', 30),
 41:         'login_id_day' => (int) env('CUSTOMER_LOGIN_ID_DAY', 100),
 42:         'phone_id_day' => (int) env('CUSTOMER_PHONE_ID_DAY', 5),
 43:         'phone_verify_id_day' => (int) env('CUSTOMER_PHONE_VERIFY_ID_DAY', 20),
 44:         'cart_guest_ip_minute' => (int) env('CART_GUEST_IP_MINUTE', 120),
 45:         'cart_guest_ip_day' => (int) env('CART_GUEST_IP_DAY', 2000),
 46:         'checkout_guest_ip_minute' => (int) env('CHECKOUT_GUEST_IP_MINUTE', 30),
 47:         'checkout_guest_ip_day' => (int) env('CHECKOUT_GUEST_IP_DAY', 200),
 48:         'checkout_phone_day' => (int) env('CHECKOUT_PHONE_DAY', 20),
 49:     ],
 50:     'customer_daily_messages' => (int) env('CUSTOMER_DAILY_MESSAGES', 5),
 51:     'customer_password_reset' => [
 52:         'connection' => env('CUSTOMER_PASSWORD_RESET_QUEUE_CONNECTION', 'database'),
 53:     ],
 54: 
 55:     /*
 56:     |--------------------------------------------------------------------------
 57:     | Authentication Defaults
 58:     |--------------------------------------------------------------------------
 59:     |
 60:     | This option defines the default authentication "guard" and password
 61:     | reset "broker" for your application. You may change these values
 62:     | as required, but they're a perfect start for most applications.
 63:     |
 64:     */
 65: 
 66:     'defaults' => [
 67:         'guard' => env('AUTH_GUARD', 'web'),
 68:         'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
 69:     ],
 70: 
 71:     /*
 72:     |--------------------------------------------------------------------------
 73:     | Authentication Guards
 74:     |--------------------------------------------------------------------------
 75:     |
 76:     | Next, you may define every authentication guard for your application.
 77:     | Of course, a great default configuration has been defined for you
 78:     | which utilizes session storage plus the Eloquent user provider.
 79:     |
 80:     | All authentication guards have a user provider, which defines how the
 81:     | users are actually retrieved out of your database or other storage
 82:     | system used by the application. Typically, Eloquent is utilized.
 83:     |
 84:     | Supported: "session"
 85:     |
 86:     */
 87: 
 88:     'guards' => [
 89:         'web' => [
 90:             'driver' => 'session',
 91:             'provider' => 'users',
 92:         ],
 93:         'staff' => [
 94:             'driver' => 'sanctum',
 95:             'provider' => 'users',
 96:         ],
 97:         'customer' => [
 98:             'driver' => 'sanctum',
 99:             'provider' => 'customers',
100:         ],
101:         'supplier' => [
102:             'driver' => 'sanctum',
103:             'provider' => 'supplier_users',
104:         ],
105:     ],
106: 
107:     /*
108:     |--------------------------------------------------------------------------
109:     | User Providers
110:     |--------------------------------------------------------------------------
111:     |
112:     | All authentication guards have a user provider, which defines how the
113:     | users are actually retrieved out of your database or other storage
114:     | system used by the application. Typically, Eloquent is utilized.
115:     |
116:     | If you have multiple user tables or models you may configure multiple
117:     | providers to represent the model / table. These providers may then
118:     | be assigned to any extra authentication guards you have defined.
119:     |
120:     | Supported: "database", "eloquent"
121:     |
122:     */
123: 
124:     'providers' => [
125:         'users' => [
126:             'driver' => 'eloquent',
127:             'model' => env('AUTH_MODEL', User::class),
128:         ],
129: 
130:         'customers' => [
131:             'driver' => 'eloquent',
132:             'model' => Customer::class,
133:         ],
134: 
135:         'supplier_users' => [
136:             'driver' => 'eloquent',
137:             'model' => SupplierUser::class,
138:         ],
139: 
140:         // 'users' => [
141:         //     'driver' => 'database',
142:         //     'table' => 'users',
143:         // ],
144:     ],
145: 
146:     /*
147:     |--------------------------------------------------------------------------
148:     | Resetting Passwords
149:     |--------------------------------------------------------------------------
150:     |
151:     | These configuration options specify the behavior of Laravel's password
152:     | reset functionality, including the table utilized for token storage
153:     | and the user provider that is invoked to actually retrieve users.
154:     |
155:     | The expiry time is the number of minutes that each reset token will be
156:     | considered valid. This security feature keeps tokens short-lived so
157:     | they have less time to be guessed. You may change this as needed.
158:     |
159:     | The throttle setting is the number of seconds a user must wait before
160:     | generating more password reset tokens. This prevents the user from
161:     | quickly generating a very large amount of password reset tokens.
162:     |
163:     */
164: 
165:     'passwords' => [
166:         'users' => [
167:             'provider' => 'users',
168:             'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
169:             'expire' => 60,
170:             'throttle' => 60,
171:         ],
172:     ],
173: 
174:     /*
175:     |--------------------------------------------------------------------------
176:     | Password Confirmation Timeout
177:     |--------------------------------------------------------------------------
178:     |
179:     | Here you may define the number of seconds before a password confirmation
180:     | window expires and users are asked to re-enter their password via the
181:     | confirmation screen. By default, the timeout lasts for three hours.
182:     |
183:     */
184: 
185:     'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
186: 
187: ];
```

## File: database/factories/CustomerFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Enums\CustomerStatus;
 8: use App\Enums\CustomerType;
 9: use App\Models\Customer;
10: use Illuminate\Database\Eloquent\Factories\Factory;
11: use Illuminate\Support\Facades\Hash;
12: 
13: /**
14:  * @extends Factory<Customer>
15:  */
16: class CustomerFactory extends Factory
17: {
18:     protected $model = Customer::class;
19: 
20:     public function definition(): array
21:     {
22:         return [
23:             'name' => fake()->name(),
24:             'email' => fake()->unique()->safeEmail(),
25:             'email_verified_at' => now(),
26:             'phone' => fake()->unique()->numerify('010########'),
27:             'company_name' => fn (array $attributes) => in_array($attributes['type'], [CustomerType::Retail, 'retail'], true) ? null : fake()->company(),
28:             'tax_number' => fn (array $attributes) => in_array($attributes['type'], [CustomerType::Retail, 'retail'], true) ? null : fake()->numerify('#########'),
29:             'commercial_register' => fn (array $attributes) => in_array($attributes['type'], [CustomerType::Retail, 'retail'], true) ? null : fake()->numerify('######'),
30:             'password' => Hash::make('password'),
31:             'type' => CustomerType::Retail,
32:             'status' => CustomerStatus::Approved,
33:         ];
34:     }
35: 
36:     public function wholesale(): static
37:     {
38:         return $this->state(fn (array $attributes): array => [
39:             'type' => CustomerType::Wholesale,
40:             'status' => CustomerStatus::Pending,
41:             'company_name' => fake()->company(),
42:             'tax_number' => fake()->unique()->numerify('#########'),
43:             'commercial_register' => fake()->unique()->numerify('######'),
44:         ]);
45:     }
46: }
```

## File: database/factories/UserFactory.php
```php
 1: <?php
 2: 
 3: namespace Database\Factories;
 4: 
 5: use App\Models\User;
 6: use Illuminate\Database\Eloquent\Factories\Factory;
 7: use Illuminate\Support\Facades\Hash;
 8: use Illuminate\Support\Str;
 9: 
10: /**
11:  * @extends Factory<User>
12:  */
13: class UserFactory extends Factory
14: {
15:     /**
16:      * The current password being used by the factory.
17:      */
18:     protected static ?string $password;
19: 
20:     /**
21:      * Define the model's default state.
22:      *
23:      * @return array<string, mixed>
24:      */
25:     public function definition(): array
26:     {
27:         return [
28:             'name' => fake()->name(),
29:             'email' => fake()->unique()->safeEmail(),
30:             'email_verified_at' => now(),
31:             'password' => static::$password ??= Hash::make('password'),
32:             'remember_token' => Str::random(10),
33:         ];
34:     }
35: 
36:     /**
37:      * Indicate that the model's email address should be unverified.
38:      */
39:     public function unverified(): static
40:     {
41:         return $this->state(fn (array $attributes) => [
42:             'email_verified_at' => null,
43:         ]);
44:     }
45: }
```

## File: database/migrations/0001_01_01_000000_create_users_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('users', function (Blueprint $table) {
15:             $table->id();
16:             $table->string('name');
17:             $table->string('email')->unique();
18:             $table->timestamp('email_verified_at')->nullable();
19:             $table->string('password');
20:             $table->rememberToken();
21:             $table->timestamps();
22:         });
23: 
24:         Schema::create('password_reset_tokens', function (Blueprint $table) {
25:             $table->string('email')->primary();
26:             $table->string('token');
27:             $table->timestamp('created_at')->nullable();
28:         });
29: 
30:         Schema::create('sessions', function (Blueprint $table) {
31:             $table->string('id')->primary();
32:             $table->foreignId('user_id')->nullable()->index();
33:             $table->string('ip_address', 45)->nullable();
34:             $table->text('user_agent')->nullable();
35:             $table->longText('payload');
36:             $table->integer('last_activity')->index();
37:         });
38:     }
39: 
40:     /**
41:      * Reverse the migrations.
42:      */
43:     public function down(): void
44:     {
45:         Schema::dropIfExists('users');
46:         Schema::dropIfExists('password_reset_tokens');
47:         Schema::dropIfExists('sessions');
48:     }
49: };
```

## File: database/migrations/2026_10_14_224514_create_permission_tables.php
```php
  1: <?php
  2: 
  3: use Illuminate\Database\Migrations\Migration;
  4: use Illuminate\Database\Schema\Blueprint;
  5: use Illuminate\Support\Facades\Schema;
  6: 
  7: return new class extends Migration
  8: {
  9:     /**
 10:      * Run the migrations.
 11:      */
 12:     public function up(): void
 13:     {
 14:         $teams = config('permission.teams');
 15:         $tableNames = config('permission.table_names');
 16:         $columnNames = config('permission.column_names');
 17:         $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
 18:         $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
 19: 
 20:         throw_if(empty($tableNames), 'Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
 21:         throw_if($teams && empty($columnNames['team_foreign_key'] ?? null), 'Error: team_foreign_key on config/permission.php not loaded. Run [php artisan config:clear] and try again.');
 22: 
 23:         /**
 24:          * See `docs/prerequisites.md` for suggested lengths on 'name' and 'guard_name' if "1071 Specified key was too long" errors are encountered.
 25:          */
 26:         Schema::create($tableNames['permissions'], static function (Blueprint $table) {
 27:             $table->id(); // permission id
 28:             $table->string('name');
 29:             $table->string('guard_name');
 30:             $table->timestamps();
 31: 
 32:             $table->unique(['name', 'guard_name']);
 33:         });
 34: 
 35:         /**
 36:          * See `docs/prerequisites.md` for suggested lengths on 'name' and 'guard_name' if "1071 Specified key was too long" errors are encountered.
 37:          */
 38:         Schema::create($tableNames['roles'], static function (Blueprint $table) use ($teams, $columnNames) {
 39:             $table->id(); // role id
 40:             if ($teams || config('permission.testing')) { // permission.testing is a fix for sqlite testing
 41:                 $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
 42:                 $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');
 43:             }
 44:             $table->string('name');
 45:             $table->string('guard_name');
 46:             $table->timestamps();
 47:             if ($teams || config('permission.testing')) {
 48:                 $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
 49:             } else {
 50:                 $table->unique(['name', 'guard_name']);
 51:             }
 52:         });
 53: 
 54:         Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teams) {
 55:             $table->unsignedBigInteger($pivotPermission);
 56: 
 57:             $table->string('model_type');
 58:             $table->unsignedBigInteger($columnNames['model_morph_key']);
 59:             $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');
 60: 
 61:             $table->foreign($pivotPermission)
 62:                 ->references('id') // permission id
 63:                 ->on($tableNames['permissions'])
 64:                 ->cascadeOnDelete();
 65:             if ($teams) {
 66:                 $table->unsignedBigInteger($columnNames['team_foreign_key']);
 67:                 $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');
 68: 
 69:                 $table->primary([$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
 70:                     'model_has_permissions_permission_model_type_primary');
 71:             } else {
 72:                 $table->primary([$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
 73:                     'model_has_permissions_permission_model_type_primary');
 74:             }
 75:         });
 76: 
 77:         Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $teams) {
 78:             $table->unsignedBigInteger($pivotRole);
 79: 
 80:             $table->string('model_type');
 81:             $table->unsignedBigInteger($columnNames['model_morph_key']);
 82:             $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');
 83: 
 84:             $table->foreign($pivotRole)
 85:                 ->references('id') // role id
 86:                 ->on($tableNames['roles'])
 87:                 ->cascadeOnDelete();
 88:             if ($teams) {
 89:                 $table->unsignedBigInteger($columnNames['team_foreign_key']);
 90:                 $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');
 91: 
 92:                 $table->primary([$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
 93:                     'model_has_roles_role_model_type_primary');
 94:             } else {
 95:                 $table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'],
 96:                     'model_has_roles_role_model_type_primary');
 97:             }
 98:         });
 99: 
100:         Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
101:             $table->unsignedBigInteger($pivotPermission);
102:             $table->unsignedBigInteger($pivotRole);
103: 
104:             $table->foreign($pivotPermission)
105:                 ->references('id') // permission id
106:                 ->on($tableNames['permissions'])
107:                 ->cascadeOnDelete();
108: 
109:             $table->foreign($pivotRole)
110:                 ->references('id') // role id
111:                 ->on($tableNames['roles'])
112:                 ->cascadeOnDelete();
113: 
114:             $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
115:         });
116: 
117:         app('cache')
118:             ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
119:             ->forget(config('permission.cache.key'));
120:     }
121: 
122:     /**
123:      * Reverse the migrations.
124:      */
125:     public function down(): void
126:     {
127:         $tableNames = config('permission.table_names');
128: 
129:         throw_if(empty($tableNames), 'Error: config/permission.php not found and defaults could not be merged. Please publish the package configuration before proceeding, or drop the tables manually.');
130: 
131:         Schema::dropIfExists($tableNames['role_has_permissions']);
132:         Schema::dropIfExists($tableNames['model_has_roles']);
133:         Schema::dropIfExists($tableNames['model_has_permissions']);
134:         Schema::dropIfExists($tableNames['roles']);
135:         Schema::dropIfExists($tableNames['permissions']);
136:     }
137: };
```

## File: database/migrations/2026_10_14_224514_create_personal_access_tokens_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         // H4: قد يوجد الجدول أصلًا (Sanctum كان مستخدمًا قبل هذا التاريخ).
15:         if (Schema::hasTable('personal_access_tokens')) {
16:             return;
17:         }
18:         Schema::create('personal_access_tokens', function (Blueprint $table) {
19:             $table->id();
20:             $table->morphs('tokenable');
21:             $table->text('name');
22:             $table->string('token', 64)->unique();
23:             $table->text('abilities')->nullable();
24:             $table->timestamp('last_used_at')->nullable();
25:             $table->timestamp('expires_at')->nullable()->index();
26:             $table->timestamps();
27:         });
28:     }
29: 
30:     /**
31:      * Reverse the migrations.
32:      */
33:     public function down(): void
34:     {
35:         Schema::dropIfExists('personal_access_tokens');
36:     }
37: };
```

## File: database/migrations/2026_10_15_014601_create_customers_and_supplier_users_tables.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         Schema::create('customers', function (Blueprint $table): void {
14:             $table->id();
15:             $table->string('name');
16:             $table->string('email')->unique();
17:             $table->string('phone')->nullable();
18:             $table->string('password');
19:             $table->enum('type', ['retail', 'wholesale', 'distributor'])->default('retail');
20:             $table->enum('status', ['pending', 'approved', 'rejected', 'blocked'])->default('pending');
21:             $table->string('tax_number')->nullable();
22:             $table->string('commercial_register')->nullable();
23:             $table->timestamps();
24:         });
25: 
26:         Schema::create('supplier_users', function (Blueprint $table): void {
27:             $table->id();
28:             $table->unsignedBigInteger('supplier_id')->nullable();
29:             $table->string('name');
30:             $table->string('email')->unique();
31:             $table->string('phone')->nullable();
32:             $table->string('password');
33:             $table->boolean('is_active')->default(true);
34:             $table->timestamps();
35:         });
36:     }
37: 
38:     public function down(): void
39:     {
40:         Schema::dropIfExists('supplier_users');
41:         Schema::dropIfExists('customers');
42:     }
43: };
```

## File: database/migrations/2026_10_15_032900_add_remember_tokens_to_customer_auth_tables.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         foreach (['customers', 'supplier_users'] as $tableName) {
14:             if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'remember_token')) {
15:                 Schema::table($tableName, function (Blueprint $table): void {
16:                     $table->rememberToken();
17:                 });
18:             }
19:         }
20:     }
21: 
22:     public function down(): void
23:     {
24:         foreach (['customers', 'supplier_users'] as $tableName) {
25:             if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'remember_token')) {
26:                 Schema::table($tableName, function (Blueprint $table): void {
27:                     $table->dropColumn('remember_token');
28:                 });
29:             }
30:         }
31:     }
32: };
```

## File: database/migrations/2026_10_15_034200_add_lookup_indexes_to_customer_and_inventory_tables.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         Schema::table('customers', function (Blueprint $table): void {
14:             $table->index(['type', 'status'], 'customers_type_status_index');
15:             $table->index('phone', 'customers_phone_index');
16:         });
17: 
18:         Schema::table('supplier_users', function (Blueprint $table): void {
19:             $table->index(['supplier_id', 'is_active'], 'supplier_users_supplier_active_index');
20:         });
21: 
22:         Schema::table('branches', function (Blueprint $table): void {
23:             $table->index(['type', 'is_active'], 'branches_type_active_index');
24:         });
25: 
26:         Schema::table('locations', function (Blueprint $table): void {
27:             $table->index(['branch_id', 'is_active', 'type'], 'locations_branch_active_type_index');
28:         });
29:     }
30: 
31:     public function down(): void
32:     {
33:         Schema::table('locations', function (Blueprint $table): void {
34:             $table->dropIndex('locations_branch_active_type_index');
35:         });
36: 
37:         Schema::table('branches', function (Blueprint $table): void {
38:             $table->dropIndex('branches_type_active_index');
39:         });
40: 
41:         Schema::table('supplier_users', function (Blueprint $table): void {
42:             $table->dropIndex('supplier_users_supplier_active_index');
43:         });
44: 
45:         Schema::table('customers', function (Blueprint $table): void {
46:             $table->dropIndex('customers_phone_index');
47:             $table->dropIndex('customers_type_status_index');
48:         });
49:     }
50: };
```

## File: database/migrations/2026_10_15_170001_create_customer_groups_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     public function up(): void
10:     {
11:         Schema::create('customer_groups', function (Blueprint $table): void {
12:             $table->id();
13:             $table->string('name');
14:             $table->string('code')->unique();
15:             $table->text('description')->nullable();
16:             $table->decimal('discount_percentage', 5, 2)->default(0);
17:             $table->boolean('is_active')->default(true);
18:             $table->timestamps();
19:         });
20:     }
21: 
22:     public function down(): void
23:     {
24:         Schema::dropIfExists('customer_groups');
25:     }
26: };
```

## File: database/migrations/2026_10_15_170002_add_customer_group_id_to_customers_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     public function up(): void
10:     {
11:         Schema::table('customers', function (Blueprint $table): void {
12:             $table->foreignId('customer_group_id')->nullable()->after('status')
13:                 ->constrained('customer_groups')->nullOnDelete();
14:             $table->index('customer_group_id');
15:         });
16:     }
17: 
18:     public function down(): void
19:     {
20:         Schema::table('customers', function (Blueprint $table): void {
21:             $table->dropConstrainedForeignId('customer_group_id');
22:         });
23:     }
24: };
```

## File: database/migrations/2026_10_16_000001_complete_customers_structure.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: use Illuminate\Database\Migrations\Migration;
  6: use Illuminate\Database\Schema\Blueprint;
  7: use Illuminate\Support\Facades\DB;
  8: use Illuminate\Support\Facades\Schema;
  9: 
 10: /**
 11:  * المرحلة 5.1: استكمال بنية العملاء.
 12:  * - customers: حالة suspended بدل blocked، بريد nullable، هاتف unique وإلزامي،
 13:  *   وبيانات الجملة والاعتماد (شركة/ائتمان/أسباب/اعتماد) + حذف ناعم.
 14:  * - جداول جديدة: customer_documents وcities وcustomer_addresses.
 15:  */
 16: return new class extends Migration
 17: {
 18:     public function up(): void
 19:     {
 20:         // --- customers: الحالة suspended بدل blocked (بيانات قديمة تُرحَّل) ---
 21:         DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_status_check');
 22:         DB::statement("UPDATE customers SET status = 'suspended' WHERE status = 'blocked'");
 23:         DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'suspended'))");
 24:         // الافتراضي العام approved؛ تسجيل الجملة يفرض pending برمجيًا (مهمة 5.3)
 25:         DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'approved'");
 26: 
 27:         // --- البريد يصبح اختياريًا، والهاتف هو المعرّف الإلزامي الفريد ---
 28:         DB::statement('ALTER TABLE customers ALTER COLUMN email DROP NOT NULL');
 29:         DB::statement("UPDATE customers SET phone = 'legacy-' || id WHERE phone IS NULL");
 30:         DB::statement('ALTER TABLE customers ALTER COLUMN phone SET NOT NULL');
 31:         DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS customers_phone_unique ON customers (phone)');
 32: 
 33:         Schema::table('customers', function (Blueprint $table): void {
 34:             $table->string('company_name')->nullable();
 35:             $table->decimal('credit_limit', 12, 2)->default(0);
 36:             $table->text('rejection_reason')->nullable();
 37:             $table->text('suspension_reason')->nullable();
 38:             $table->timestamp('approved_at')->nullable();
 39:             $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
 40:             $table->softDeletes();
 41:         });
 42: 
 43:         Schema::create('customer_documents', function (Blueprint $table): void {
 44:             $table->id();
 45:             $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
 46:             $table->enum('document_type', ['commercial_register', 'tax_card', 'national_id', 'other'])->default('other');
 47:             $table->string('file_path');
 48:             $table->string('original_name');
 49:             $table->string('mime_type');
 50:             $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
 51:             $table->timestamps();
 52: 
 53:             $table->index(['customer_id', 'status']);
 54:         });
 55: 
 56:         Schema::create('cities', function (Blueprint $table): void {
 57:             $table->id();
 58:             $table->string('name_ar');
 59:             $table->string('name_en');
 60:             $table->decimal('shipping_cost', 10, 2)->default(0);
 61:             $table->boolean('is_active')->default(true);
 62:             $table->timestamps();
 63:         });
 64: 
 65:         Schema::create('customer_addresses', function (Blueprint $table): void {
 66:             $table->id();
 67:             $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
 68:             $table->foreignId('city_id')->constrained();
 69:             $table->string('address_line');
 70:             $table->string('building_no')->nullable();
 71:             $table->string('floor_no')->nullable();
 72:             $table->string('apartment_no')->nullable();
 73:             $table->boolean('is_default')->default(false);
 74:             $table->timestamps();
 75: 
 76:             $table->index(['customer_id', 'is_default']);
 77:         });
 78:     }
 79: 
 80:     public function down(): void
 81:     {
 82:         Schema::dropIfExists('customer_addresses');
 83:         Schema::dropIfExists('cities');
 84:         Schema::dropIfExists('customer_documents');
 85: 
 86:         Schema::table('customers', function (Blueprint $table): void {
 87:             $table->dropConstrainedForeignId('approved_by');
 88:             $table->dropColumn(['company_name', 'credit_limit', 'rejection_reason', 'suspension_reason', 'approved_at']);
 89:             $table->dropSoftDeletes();
 90:         });
 91: 
 92:         DB::statement('DROP INDEX IF EXISTS customers_phone_unique');
 93:         DB::statement('ALTER TABLE customers ALTER COLUMN phone DROP NOT NULL');
 94:         DB::statement("UPDATE customers SET email = 'legacy-customer-' || id || '@rollback.invalid' WHERE email IS NULL");
 95:         DB::statement('ALTER TABLE customers ALTER COLUMN email SET NOT NULL');
 96:         DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_status_check');
 97:         DB::statement("UPDATE customers SET status = 'blocked' WHERE status = 'suspended'");
 98:         DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'blocked'))");
 99:         DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'pending'");
100:     }
101: };
```

## File: database/migrations/2026_10_16_115322_normalize_customer_identity.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\DB;
 6: use Illuminate\Support\Facades\Schema;
 7: 
 8: return new class extends Migration
 9: {
10:     /**
11:      * Run the migrations.
12:      */
13:     public function up(): void
14:     {
15:         if (DB::table('customers')->whereNotNull('email')->selectRaw('LOWER(TRIM(email))')
16:             ->groupByRaw('LOWER(TRIM(email))')->havingRaw('COUNT(*) > 1')->exists()
17:             || DB::table('customers')->selectRaw('TRIM(phone)')->groupByRaw('TRIM(phone)')->havingRaw('COUNT(*) > 1')->exists()) {
18:             throw new RuntimeException('Resolve duplicate customer identities before normalizing them.');
19:         }
20:         DB::statement('UPDATE customers SET email = LOWER(TRIM(email)), phone = TRIM(phone)');
21:         DB::statement('CREATE UNIQUE INDEX customers_email_ci_unique ON customers (LOWER(email))');
22:         DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
23:         DB::statement("ALTER TABLE customers ADD COLUMN search_text TEXT GENERATED ALWAYS AS (
24:             COALESCE(name, '') || ' ' || COALESCE(phone, '') || ' ' || COALESCE(email, '') || ' ' ||
25:             COALESCE(commercial_register, '') || ' ' || COALESCE(company_name, '') || ' ' || COALESCE(tax_number, '')
26:         ) STORED");
27:         DB::statement('CREATE INDEX customers_search_trgm_index ON customers USING GIN (search_text gin_trgm_ops)');
28:     }
29: 
30:     /**
31:      * Reverse the migrations.
32:      */
33:     public function down(): void
34:     {
35:         DB::statement('DROP INDEX IF EXISTS customers_email_ci_unique');
36:         Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('search_text'));
37:     }
38: };
```

## File: database/migrations/2026_10_16_123837_harden_customer_authentication_and_audit.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\DB;
 6: use Illuminate\Support\Facades\Schema;
 7: 
 8: return new class extends Migration
 9: {
10:     /**
11:      * Run the migrations.
12:      */
13:     public function up(): void
14:     {
15:         DB::statement("SET LOCAL lock_timeout = '2s'");
16:         Schema::table('customers', function (Blueprint $table): void {
17:             $table->timestamp('phone_verified_at')->nullable();
18:         });
19:         Schema::table('customer_documents', function (Blueprint $table): void {
20:             $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
21:             $table->timestamp('reviewed_at')->nullable();
22:         });
23:         Schema::create('customer_audit_logs', function (Blueprint $table): void {
24:             $table->id();
25:             $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
26:             $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
27:             $table->string('action', 50);
28:             $table->jsonb('metadata')->nullable();
29:             $table->timestamps();
30:             $table->index(['customer_id', 'created_at']);
31:         });
32:         Schema::create('customer_message_budgets', function (Blueprint $table): void {
33:             $table->string('key', 64);
34:             $table->date('date')->index();
35:             $table->unsignedSmallInteger('sends')->default(0);
36:             $table->primary(['key', 'date']);
37:         });
38:         Schema::create('customer_phone_verifications', function (Blueprint $table): void {
39:             $table->string('phone_key', 64)->primary();
40:             $table->string('code_hash');
41:             $table->string('proof_hash')->nullable();
42:             $table->unsignedSmallInteger('attempts')->default(0);
43:             $table->timestamp('verified_at')->nullable();
44:             $table->timestamp('created_at');
45:             $table->timestamp('expires_at')->index();
46:         });
47:         DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'pending'");
48:         DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_nonnegative_credit CHECK (credit_limit >= 0) NOT VALID');
49:         DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_approved_business CHECK (
50:             status <> 'approved' OR type = 'retail' OR (
51:                 NULLIF(TRIM(company_name), '') IS NOT NULL AND NULLIF(TRIM(tax_number), '') IS NOT NULL
52:                 AND NULLIF(TRIM(commercial_register), '') IS NOT NULL
53:             )) NOT VALID");
54:         DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_valid_phone CHECK (phone ~ '^01[0125][0-9]{8}$' OR phone ~ '^legacy-[0-9]+$') NOT VALID");
55:     }
56: 
57:     /**
58:      * Reverse the migrations.
59:      */
60:     public function down(): void
61:     {
62:         DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_nonnegative_credit');
63:         DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_approved_business');
64:         DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_valid_phone');
65:         DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'approved'");
66:         Schema::dropIfExists('customer_phone_verifications');
67:         Schema::dropIfExists('customer_message_budgets');
68:         Schema::dropIfExists('customer_audit_logs');
69:         Schema::table('customer_documents', function (Blueprint $table): void {
70:             $table->dropConstrainedForeignId('reviewed_by');
71:             $table->dropColumn('reviewed_at');
72:         });
73:         Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('phone_verified_at'));
74:     }
75: };
```

## File: database/migrations/2026_10_16_123839_drop_redundant_customer_email_index.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Support\Facades\DB;
 5: 
 6: return new class extends Migration
 7: {
 8:     public $withinTransaction = false;
 9: 
10:     /**
11:      * Run the migrations.
12:      */
13:     public function up(): void
14:     {
15:         DB::statement("SET lock_timeout = '2s'");
16:         try {
17:             DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_email_unique');
18:             DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_email_unique');
19:         } finally {
20:             DB::statement('RESET lock_timeout');
21:         }
22:     }
23: 
24:     /**
25:      * Reverse the migrations.
26:      */
27:     public function down(): void
28:     {
29:         DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS customers_email_unique ON customers (email)');
30:     }
31: };
```

## File: database/migrations/2026_10_16_141204_harden_customer_email_and_catalog_limits.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\DB;
 6: use Illuminate\Support\Facades\Schema;
 7: 
 8: return new class extends Migration
 9: {
10:     public $withinTransaction = false;
11: 
12:     /**
13:      * Run the migrations.
14:      */
15:     public function up(): void
16:     {
17:         DB::statement("SET lock_timeout = '2s'");
18:         try {
19:             if (! Schema::hasColumn('customers', 'email_verified_at')) {
20:                 Schema::table('customers', fn (Blueprint $table) => $table->timestamp('email_verified_at')->nullable());
21:             }
22:             $this->dropInvalidIndex('customers_verified_email_unique');
23:             DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS customers_verified_email_unique ON customers (LOWER(email)) WHERE email_verified_at IS NOT NULL');
24:             DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_email_ci_unique');
25:             $this->dropInvalidIndex('price_lists_active_type_index');
26:             DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS price_lists_active_type_index ON price_lists (type) WHERE is_active');
27:         } finally {
28:             DB::statement('RESET lock_timeout');
29:         }
30:     }
31: 
32:     /**
33:      * Reverse the migrations.
34:      */
35:     public function down(): void
36:     {
37:         DB::statement("SET lock_timeout = '2s'");
38:         try {
39:             $this->dropInvalidIndex('customers_email_ci_unique');
40:             DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS customers_email_ci_unique ON customers (LOWER(email))');
41:             DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_verified_email_unique');
42:             DB::statement('DROP INDEX CONCURRENTLY IF EXISTS price_lists_active_type_index');
43:             Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('email_verified_at'));
44:         } finally {
45:             DB::statement('RESET lock_timeout');
46:         }
47:     }
48: 
49:     private function dropInvalidIndex(string $name): void
50:     {
51:         $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
52:         if ($index !== null && ! $index->indisvalid) {
53:             DB::statement('DROP INDEX CONCURRENTLY '.$name);
54:         }
55:     }
56: };
```

## File: database/migrations/2026_10_25_000002_validate_customer_constraints.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Support\Facades\DB;
 7: 
 8: return new class extends Migration
 9: {
10:     public function up(): void
11:     {
12:         DB::statement("SET LOCAL lock_timeout = '2s'");
13:         // Fail explicitly if legacy rows need remediation; do not silently rewrite identities.
14:         foreach (['customers_nonnegative_credit', 'customers_approved_business', 'customers_valid_phone'] as $constraint) {
15:             DB::statement("ALTER TABLE customers VALIDATE CONSTRAINT {$constraint}");
16:         }
17:     }
18: 
19:     public function down(): void
20:     {
21:         // Validation does not change the constraint or accepted data.
22:     }
23: };
```

## File: database/migrations/2026_11_04_000001_add_review_note_to_customer_documents.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: // 0.3: سبب رفض المستند — كان التاجر يشوف "مرفوض" من غير ما يعرف ليه.
10: return new class extends Migration
11: {
12:     public function up(): void
13:     {
14:         Schema::table('customer_documents', function (Blueprint $table): void {
15:             $table->text('review_note')->nullable();
16:         });
17:     }
18: 
19:     public function down(): void
20:     {
21:         Schema::table('customer_documents', function (Blueprint $table): void {
22:             $table->dropColumn('review_note');
23:         });
24:     }
25: };
```

## File: database/migrations/2026_11_05_000002_enforce_customer_identity_indexes.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Support\Facades\DB;
 7: 
 8: return new class extends Migration
 9: {
10:     public $withinTransaction = false;
11: 
12:     public function up(): void
13:     {
14:         $dup = DB::selectOne("SELECT 1 AS x FROM customers WHERE email IS NOT NULL AND deleted_at IS NULL
15:             AND type IN ('wholesale', 'distributor') GROUP BY LOWER(email) HAVING COUNT(*) > 1 LIMIT 1");
16:         if ($dup) {
17:             throw new RuntimeException('Resolve duplicate trader emails before adding customers_business_email_unique.');
18:         }
19:         $c = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
20: 
21:         // M-1: بريد التاجر معرّف دخول حتى قبل التحقق.
22:         DB::statement("CREATE UNIQUE INDEX {$c}IF NOT EXISTS customers_business_email_unique ON customers (LOWER(email))
23:             WHERE email IS NOT NULL AND deleted_at IS NULL AND type IN ('wholesale', 'distributor')");
24: 
25:         // INFO: مكرر مع customers_phone_unique وcustomers_phone_pattern_index.
26:         DB::statement("DROP INDEX {$c}IF EXISTS customers_phone_index");
27: 
28:         // L-3: الافتراضي الآمن (موجود من 2026_10_16_123837، تأكيد فقط).
29:         DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'pending'");
30:     }
31: 
32:     public function down(): void
33:     {
34:         $c = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
35:         DB::statement("CREATE INDEX {$c}IF NOT EXISTS customers_phone_index ON customers (phone)");
36:         DB::statement("DROP INDEX {$c}IF EXISTS customers_business_email_unique");
37:     }
38: };
```

## File: database/seeders/CustomerPermissionSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Enums\StaffRole;
 8: use Illuminate\Database\Seeder;
 9: use Spatie\Permission\Models\Permission;
10: use Spatie\Permission\Models\Role;
11: use Spatie\Permission\PermissionRegistrar;
12: 
13: class CustomerPermissionSeeder extends Seeder
14: {
15:     /**
16:      * Run the database seeds.
17:      */
18:     public function run(): void
19:     {
20:         app(PermissionRegistrar::class)->forgetCachedPermissions();
21: 
22:         $permissions = [
23:             'customers.view', 'customers.create', 'customers.update', 'customers.delete',
24:             'customers.approve',
25:             'customers.documents.view',
26:             'customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete',
27:         ];
28: 
29:         $created = [];
30:         foreach ($permissions as $permission) {
31:             $created[] = Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'staff']);
32:         }
33: 
34:         Role::where('name', StaffRole::SuperAdmin->value)
35:             ->where('guard_name', 'staff')
36:             ->first()
37:             ?->givePermissionTo($created);
38:     }
39: }
```

## File: database/seeders/RolePermissionSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Enums\StaffRole;
 8: use Illuminate\Database\Seeder;
 9: use Spatie\Permission\Models\Permission;
10: use Spatie\Permission\Models\Role;
11: 
12: class RolePermissionSeeder extends Seeder
13: {
14:     /**
15:      * @var array<string, list<string>>
16:      */
17:     private const PERMISSIONS_BY_MODULE = [
18:         'branches' => ['view', 'create', 'update', 'delete', 'manage'],
19:         'locations' => ['view', 'create', 'update', 'delete', 'manage'],
20:         'users' => ['view', 'create', 'update', 'delete', 'manage'],
21:         'roles' => ['view', 'create', 'update', 'delete', 'manage'],
22:         'permissions' => ['view'],
23:         'customers' => ['view', 'create', 'update', 'delete', 'manage'],
24:         'suppliers' => ['view', 'create', 'update', 'delete', 'manage'],
25:         'products' => ['view', 'create', 'update', 'delete', 'manage'],
26:         'categories' => ['view', 'create', 'update', 'delete', 'manage'],
27:         'inventory' => ['view', 'adjust', 'all_branches', 'view_cost'],
28:         'orders' => ['view', 'create', 'update', 'delete', 'manage'],
29:         'purchases' => ['view', 'create', 'update', 'delete', 'manage'],
30:         'returns' => ['view', 'create', 'update', 'delete', 'manage'],
31:         'payments' => ['view', 'create', 'update', 'delete', 'manage'],
32:         'shipping' => ['view', 'create', 'update', 'delete', 'manage'],
33:         'transfers' => ['view', 'create', 'update', 'delete', 'manage'],
34:         'reports' => ['view', 'export'],
35:         'notifications' => ['view', 'create', 'update', 'delete', 'manage'],
36:         'pricing' => ['view', 'create', 'update', 'delete', 'manage'],
37:         'settings' => ['view', 'update', 'manage'],
38:         'store' => ['manage'],
39:         'pos' => ['access', 'sales.view', 'sales.create', 'sales.refund'],
40:     ];
41: 
42:     public function run(): void
43:     {
44:         foreach (self::PERMISSIONS_BY_MODULE as $module => $actions) {
45:             foreach ($actions as $action) {
46:                 Permission::findOrCreate("{$module}.{$action}", 'staff');
47:             }
48:         }
49: 
50:         $superAdmin = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
51:         $this->call(AiAgentPermissionSeeder::class);
52:         $this->call(FinancialPermissionSeeder::class);
53:         $superAdmin->syncPermissions(
54:             Permission::query()->where('guard_name', 'staff')->get(),
55:         );
56:     }
57: }
```

## File: routes/api/admin.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: use App\Http\Controllers\Api\V1\Admin\Ai\AiActionController;
  6: use App\Http\Controllers\Api\V1\Admin\Ai\AiAdministrationController;
  7: use App\Http\Controllers\Api\V1\Admin\Ai\AiConversationController;
  8: use App\Http\Controllers\Api\V1\Admin\Ai\AiMentionController;
  9: use App\Http\Controllers\Api\V1\Admin\Ai\AiMessageController;
 10: use App\Http\Controllers\Api\V1\Admin\Ai\AiWelcomeController;
 11: use App\Http\Controllers\Api\V1\Admin\AuthController;
 12: use App\Http\Controllers\Api\V1\Admin\BannerController;
 13: use App\Http\Controllers\Api\V1\Admin\BranchController;
 14: use App\Http\Controllers\Api\V1\Admin\BrandController;
 15: use App\Http\Controllers\Api\V1\Admin\CategoryController;
 16: use App\Http\Controllers\Api\V1\Admin\CouponController;
 17: use App\Http\Controllers\Api\V1\Admin\CustomerController;
 18: use App\Http\Controllers\Api\V1\Admin\CustomerGroupController;
 19: use App\Http\Controllers\Api\V1\Admin\DashboardController;
 20: use App\Http\Controllers\Api\V1\Admin\ExpenseController;
 21: use App\Http\Controllers\Api\V1\Admin\InventoryController;
 22: use App\Http\Controllers\Api\V1\Admin\LocationController;
 23: use App\Http\Controllers\Api\V1\Admin\OrderController;
 24: use App\Http\Controllers\Api\V1\Admin\PermissionController;
 25: use App\Http\Controllers\Api\V1\Admin\PosShiftAdminController;
 26: use App\Http\Controllers\Api\V1\Admin\PriceListController;
 27: use App\Http\Controllers\Api\V1\Admin\PriceListItemController;
 28: use App\Http\Controllers\Api\V1\Admin\ProductController;
 29: use App\Http\Controllers\Api\V1\Admin\ProductionReceiptController;
 30: use App\Http\Controllers\Api\V1\Admin\ProductMediaController;
 31: use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
 32: use App\Http\Controllers\Api\V1\Admin\PromotionController;
 33: use App\Http\Controllers\Api\V1\Admin\PurchaseOrderController;
 34: use App\Http\Controllers\Api\V1\Admin\PurchaseRequestController;
 35: use App\Http\Controllers\Api\V1\Admin\QuotationController;
 36: use App\Http\Controllers\Api\V1\Admin\ReportController;
 37: use App\Http\Controllers\Api\V1\Admin\ReturnController;
 38: use App\Http\Controllers\Api\V1\Admin\RoleController;
 39: use App\Http\Controllers\Api\V1\Admin\SettingController;
 40: use App\Http\Controllers\Api\V1\Admin\ShippingCompanyController;
 41: use App\Http\Controllers\Api\V1\Admin\ShippingRateController;
 42: use App\Http\Controllers\Api\V1\Admin\ShippingZoneController;
 43: use App\Http\Controllers\Api\V1\Admin\StocktakeController;
 44: use App\Http\Controllers\Api\V1\Admin\SupplierController;
 45: use App\Http\Controllers\Api\V1\Admin\SupplierInvoiceController;
 46: use App\Http\Controllers\Api\V1\Admin\SupplierPaymentController;
 47: use App\Http\Controllers\Api\V1\Admin\TransferController;
 48: use App\Http\Controllers\Api\V1\Admin\TreasuryController;
 49: use App\Http\Controllers\Api\V1\Admin\TreasuryTransferController;
 50: use App\Http\Controllers\Api\V1\Admin\UserController;
 51: use App\Http\Controllers\Api\V1\Admin\WholesalePaymentNoticeController;
 52: use App\Http\Controllers\Api\V1\AttachmentController;
 53: use App\Http\Controllers\Api\V1\Shared\NotificationController;
 54: use App\Http\Middleware\AuditStaffAdministration;
 55: use Illuminate\Support\Facades\Route;
 56: 
 57: // معرّفات الكتالوج رقمية بس: أي قيمة غير رقمية تبقى 404 بدل ما Postgres يرمي خطأ نوع ويرجع 500
 58: Route::pattern('category', '[0-9]{1,18}');
 59: Route::pattern('brand', '[0-9]{1,18}');
 60: Route::pattern('product', '[0-9]{1,18}');
 61: Route::pattern('variant', '[0-9]{1,18}');
 62: Route::pattern('media', '[0-9]{1,18}');
 63: Route::pattern('transfer', '[0-9]{1,18}');
 64: Route::pattern('stocktake', '[0-9]{1,18}');
 65: Route::pattern('customer_group', '[0-9]{1,18}');
 66: Route::pattern('customer', '[0-9]{1,18}');
 67: Route::pattern('document', '[0-9]{1,18}');
 68: Route::pattern('price_list', '[0-9]{1,18}');
 69: Route::pattern('item', '[0-9]{1,18}');
 70: Route::pattern('order', '[0-9]{1,18}');
 71: Route::pattern('coupon', '[0-9]{1,18}');
 72: Route::pattern('promotion', '[0-9]{1,18}');
 73: Route::pattern('return_request', '[0-9]{1,18}');
 74: Route::pattern('shipping_company', '[0-9]{1,18}');
 75: Route::pattern('shipping_zone', '[0-9]{1,18}');
 76: Route::pattern('shipping_rate', '[0-9]{1,18}');
 77: Route::pattern('supplier', '[0-9]{1,18}');
 78: Route::pattern('role', '[0-9]{1,18}');
 79: Route::pattern('branch', '[0-9]{1,18}');
 80: Route::pattern('location', '[0-9]{1,18}');
 81: Route::pattern('user', '[0-9]{1,18}');
 82: Route::pattern('banner', '[0-9]{1,18}');
 83: Route::pattern('purchase_request', '[0-9]{1,18}');
 84: Route::pattern('purchase_order', '[0-9]{1,18}');
 85: Route::pattern('supplier_invoice', '[0-9]{1,18}');
 86: Route::pattern('supplier_payment', '[0-9]{1,18}');
 87: // L4: معاملات المساعد مقيدة النمط — المحادثة integer والفعل ULID.
 88: // قيمة رمزية خارج النمط تفضي لـ 404 بدل 22P02 في Postgres.
 89: // (كانا UUID خطأً فكسرا كل مسارات المساعد: 404 دائم.)
 90: Route::pattern('conversation', '[0-9]{1,18}');
 91: Route::pattern('action', '[0-9A-Za-z]{26}');
 92: 
 93: // Public admin authentication endpoint (legacy — prefer /api/v1/auth/login).
 94: // M5: تُعطَّل بضبط LEGACY_LOGIN_ENABLED=false حتى لا تتضاعف ميزانية التخمين
 95: // عبر limiters مستقلة ولا يظهر توكن موظف على دومين الويب.
 96: if (config('auth.legacy_login_enabled', false)) {
 97:     Route::post('auth/login', [AuthController::class, 'login'])
 98:         ->middleware('throttle:admin-login')
 99:         ->name('admin.auth.login');
100: }
101: 
102: // Staff-only API endpoints: require a Sanctum token and an active staff account.
103: Route::middleware(['auth:sanctum', 'auth.staff', AuditStaffAdministration::class])
104:     ->name('admin.')
105:     ->group(function (): void {
106:         Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
107:         Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:reports.view')->name('dashboard');
108:         Route::prefix('reports')->name('reports.')->middleware(['permission:reports.view', 'throttle:30,1'])->group(function (): void {
109:             Route::get('sales', [ReportController::class, 'sales'])->name('sales');
110:             Route::get('inventory', [ReportController::class, 'inventory'])->name('inventory');
111:             Route::get('profit', [ReportController::class, 'profit'])->name('profit');
112:             Route::get('suppliers', [ReportController::class, 'suppliers'])->name('suppliers');
113:             Route::get('shifts', [ReportController::class, 'shifts'])->name('shifts');
114:         });
115:         // M3: notifications.id is uuid — non-uuid values stay 404 instead of
116:         // throwing Postgres 22P02 (500 + log spam) via findOrFail.
117:         Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id')->name('notifications.read');
118:         Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
119: 
120:         Route::get('me', [AuthController::class, 'me'])->name('auth.me');
121:         Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
122: 
123:         Route::apiResource('branches', BranchController::class)
124:             ->only(['index', 'show'])
125:             ->middleware('permission:branches.view|branches.manage');
126:         Route::apiResource('branches', BranchController::class)
127:             ->only(['store'])
128:             ->middleware('permission:branches.create|branches.manage');
129:         Route::apiResource('branches', BranchController::class)
130:             ->only(['update'])
131:             ->middleware('permission:branches.update|branches.manage');
132:         Route::apiResource('branches', BranchController::class)
133:             ->only(['destroy'])
134:             ->middleware('permission:branches.delete|branches.manage');
135: 
136:         Route::scopeBindings()->group(function (): void {
137:             Route::apiResource('branches.locations', LocationController::class)
138:                 ->only(['index', 'show'])
139:                 ->middleware('permission:locations.view|locations.manage');
140:             Route::apiResource('branches.locations', LocationController::class)
141:                 ->only(['store'])
142:                 ->middleware('permission:locations.create|locations.manage');
143:             Route::apiResource('branches.locations', LocationController::class)
144:                 ->only(['update'])
145:                 ->middleware('permission:locations.update|locations.manage');
146:             Route::apiResource('branches.locations', LocationController::class)
147:                 ->only(['destroy'])
148:                 ->middleware('permission:locations.delete|locations.manage');
149:         });
150: 
151:         // L8: self-service rotation for flagged temp passwords — no
152:         // users.update required (defined before the resource routes).
153:         Route::put('me/password', [UserController::class, 'updateOwnPassword'])->name('me.password');
154:         Route::apiResource('users', UserController::class)
155:             ->only(['index', 'show'])
156:             ->middleware('permission:users.view|users.manage');
157:         Route::apiResource('users', UserController::class)
158:             ->only(['store'])
159:             ->middleware('permission:users.create|users.manage');
160:         Route::apiResource('users', UserController::class)
161:             ->only(['update'])
162:             ->middleware('permission:users.update|users.manage');
163:         Route::apiResource('users', UserController::class)
164:             ->only(['destroy'])
165:             ->middleware('permission:users.delete|users.manage');
166: 
167:         Route::apiResource('roles', RoleController::class)
168:             ->only(['index', 'show'])
169:             ->middleware('permission:roles.view|roles.manage');
170:         Route::apiResource('roles', RoleController::class)
171:             ->only(['store'])
172:             ->middleware('permission:roles.create|roles.manage');
173:         Route::apiResource('roles', RoleController::class)
174:             ->only(['update'])
175:             ->middleware('permission:roles.update|roles.manage');
176:         Route::apiResource('roles', RoleController::class)
177:             ->only(['destroy'])
178:             ->middleware('permission:roles.delete|roles.manage');
179: 
180:         Route::get('permissions', [PermissionController::class, 'index'])
181:             ->middleware('permission:permissions.view|roles.manage')
182:             ->name('permissions.index');
183: 
184:         // Catalog: authorization is handled in the controllers via can:* middleware.
185:         // مهم: reorder قبل apiResource عشان ميتقريش كأنه {category}
186:         Route::put('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
187:         Route::apiResource('categories', CategoryController::class);
188: 
189:         Route::apiResource('brands', BrandController::class);
190: 
191:         Route::apiResource('products', ProductController::class);
192:         Route::apiResource('products.variants', ProductVariantController::class)->scoped();
193: 
194:         Route::put('products/{product}/media/reorder', [ProductMediaController::class, 'reorder'])->name('products.media.reorder');
195:         Route::get('products/{product}/media', [ProductMediaController::class, 'index'])->name('products.media.index');
196:         // F18: each image spawns two GD conversions in-request and the UI
197:         // fires two uploads in parallel — throttle the admin upload path so
198:         // one account cannot saturate every PHP worker.
199:         Route::post('products/{product}/media', [ProductMediaController::class, 'store'])->middleware('throttle:30,1')->name('products.media.store');
200:         Route::delete('products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])->name('products.media.destroy');
201: 
202:         // ================== المرحلة 3: المخزون ==================
203:         Route::get('production-receipts/options', [ProductionReceiptController::class, 'options'])->name('production-receipts.options');
204:         Route::get('production-receipts', [ProductionReceiptController::class, 'index'])->name('production-receipts.index');
205:         Route::post('production-receipts', [ProductionReceiptController::class, 'store'])->name('production-receipts.store');
206:         Route::prefix('inventory')->name('inventory.')->group(function () {
207:             Route::get('/', [InventoryController::class, 'index'])->name('index');
208:             Route::get('/movements', [InventoryController::class, 'movements'])->name('movements');
209:             Route::post('/adjustments', [InventoryController::class, 'adjustments'])->name('adjustments');
210:         });
211: 
212:         // التحويلات المخزنية
213:         Route::apiResource('transfers', TransferController::class);
214:         Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
215:         Route::post('transfers/{transfer}/ship', [TransferController::class, 'ship'])->name('transfers.ship');
216:         Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
217:         Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');
218: 
219:         // الجرد المخزني
220:         Route::apiResource('stocktakes', StocktakeController::class);
221:         Route::patch('stocktakes/{stocktake}/items', [StocktakeController::class, 'updateItems'])->name('stocktakes.items.update');
222:         Route::post('stocktakes/{stocktake}/approve', [StocktakeController::class, 'approve'])->name('stocktakes.approve');
223: 
224:         // ================== المرحلة 4: الأسعار ==================
225:         Route::apiResource('customer-groups', CustomerGroupController::class);
226: 
227:         Route::apiResource('price-lists', PriceListController::class);
228:         Route::post('price-lists/{price_list}/import', [PriceListController::class, 'import'])->name('price-lists.import');
229:         Route::get('price-lists/{price_list}/export', [PriceListController::class, 'export'])->name('price-lists.export');
230: 
231:         Route::scopeBindings()->group(function () {
232:             Route::apiResource('price-lists.items', PriceListItemController::class);
233:         });
234: 
235:         // ================== المرحلة 5: العملاء ==================
236:         Route::apiResource('customers', CustomerController::class);
237:         Route::post('customers/{customer}/documents', [CustomerController::class, 'storeDocument'])->name('customers.documents.store');
238:         Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->withTrashed()->name('customers.restore');
239:         Route::post('customers/{customer}/approve', [CustomerController::class, 'approve'])->name('customers.approve');
240:         Route::post('customers/{customer}/reject', [CustomerController::class, 'reject'])->name('customers.reject');
241:         Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
242:         Route::post('customers/{customer}/reactivate', [CustomerController::class, 'reactivate'])->name('customers.reactivate');
243:         Route::get('customers/{customer}/history', [CustomerController::class, 'history'])->name('customers.history');
244:         Route::scopeBindings()->group(function (): void {
245:             Route::patch('customers/{customer}/documents/{document}', [CustomerController::class, 'reviewDocument'])
246:                 ->name('customers.documents.update');
247:             Route::get('customers/{customer}/documents/{document}/download', [CustomerController::class, 'downloadDocument'])
248:                 ->name('customers.documents.download');
249:         });
250: 
251:         // ================== المرحلة 7: الطلبات ==================
252:         Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
253:         Route::get('orders/stats', [OrderController::class, 'stats'])->name('orders.stats');
254:         Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
255:         Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
256:         Route::post('orders/{order}/payment', [OrderController::class, 'updatePayment'])->name('orders.update-payment');
257:         Route::post('orders/{order}/allocate', [OrderController::class, 'allocate'])->name('orders.allocate');
258:         // M5: فك التوزيع — كانت رسالة allocate توجّه لعملية غير موجودة
259:         Route::post('orders/{order}/deallocate', [OrderController::class, 'deallocate'])->name('orders.deallocate');
260:         Route::post('orders/{order}/shipment', [OrderController::class, 'createShipment'])->name('orders.shipment');
261: 
262:         // ================== المرحلة 8: الشحن ==================
263:         Route::apiResource('shipping-companies', ShippingCompanyController::class);
264:         Route::apiResource('shipping-zones', ShippingZoneController::class);
265:         Route::apiResource('shipping-rates', ShippingRateController::class);
266: 
267:         // ================== المرحلة 8: العروض والكوبونات ==================
268:         Route::apiResource('promotions', PromotionController::class);
269:         Route::apiResource('coupons', CouponController::class);
270: 
271:         Route::get('return-items/{return_item}/attachments/{attachment}', [AttachmentController::class, 'returnFile'])->whereNumber(['return_item', 'attachment'])->name('return-items.attachments.show');
272:         Route::get('supplier-invoices/{invoice}/attachment', [AttachmentController::class, 'invoice'])->whereNumber('invoice')->name('supplier-invoices.attachment');
273:         Route::get('supplier-deliveries/{delivery}/invoice', [AttachmentController::class, 'delivery'])->whereNumber('delivery')->name('deliveries.invoice');
274:         // ================== المرحلة 12: بوابة الجملة المتقدمة ==================
275:         Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
276:         Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->whereNumber('quotation')->name('quotations.show');
277:         Route::post('quotations/{quotation}/price', [QuotationController::class, 'price'])->whereNumber('quotation')->name('quotations.price');
278:         Route::post('quotations/{quotation}/expire', [QuotationController::class, 'expire'])->whereNumber('quotation')->name('quotations.expire');
279:         Route::get('payment-notices', [WholesalePaymentNoticeController::class, 'index'])->name('payment-notices.index');
280:         Route::get('payment-notices/{payment_notice}', [WholesalePaymentNoticeController::class, 'show'])->whereNumber('payment_notice')->name('payment-notices.show');
281:         Route::post('payment-notices/{payment_notice}/approve', [WholesalePaymentNoticeController::class, 'approve'])->whereNumber('payment_notice')->name('payment-notices.approve');
282:         Route::post('payment-notices/{payment_notice}/reject', [WholesalePaymentNoticeController::class, 'reject'])->whereNumber('payment_notice')->name('payment-notices.reject');
283:         Route::get('payment-notices/{notice}/receipt', [AttachmentController::class, 'paymentNotice'])->whereNumber('notice')->name('payment-notices.receipt');
284: 
285:         // ================== المرحلة 9: المرتجعات ==================
286:         Route::apiResource('returns', ReturnController::class)->parameters(['returns' => 'return_request']);
287:         Route::post('returns/{return_request}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
288:         Route::post('returns/{return_request}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
289:         Route::post('returns/{return_request}/refund', [ReturnController::class, 'refund'])->name('returns.refund');
290: 
291:         // ================== المرحلة 10: المشتريات والموردين ==================
292:         Route::apiResource('suppliers', SupplierController::class);
293:         Route::get('suppliers/{supplier}/users', [SupplierController::class, 'users'])->name('suppliers.users.index');
294:         Route::post('suppliers/{supplier}/users', [SupplierController::class, 'storeUser'])->name('suppliers.users.store');
295:         Route::put('suppliers/{supplier}/users/{user}', [SupplierController::class, 'updateUser'])->name('suppliers.users.update');
296:         Route::delete('suppliers/{supplier}/users/{user}', [SupplierController::class, 'destroyUser'])->name('suppliers.users.destroy');
297: 
298:         Route::apiResource('purchase-requests', PurchaseRequestController::class)
299:             ->parameters(['purchase-requests' => 'purchase_request']);
300:         Route::post('purchase-requests/{purchase_request}/submit', [PurchaseRequestController::class, 'submit'])->name('purchase-requests.submit');
301:         Route::post('purchase-requests/{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])->name('purchase-requests.approve');
302:         Route::post('purchase-requests/{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])->name('purchase-requests.reject');
303: 
304:         Route::apiResource('purchase-orders', PurchaseOrderController::class)
305:             ->parameters(['purchase-orders' => 'purchase_order']);
306:         Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
307:         Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
308:         Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
309:         Route::post('purchase-orders/{purchase_order}/close-short', [PurchaseOrderController::class, 'closeShort'])->name('purchase-orders.close-short');
310:         Route::post('purchase-orders/{purchase_order}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
311: 
312:         Route::apiResource('supplier-invoices', SupplierInvoiceController::class)
313:             ->parameters(['supplier-invoices' => 'supplier_invoice']);
314:         Route::post('supplier-invoices/{supplier_invoice}/post', [SupplierInvoiceController::class, 'post'])->name('supplier-invoices.post');
315:         Route::post('supplier-invoices/{supplier_invoice}/cancel', [SupplierInvoiceController::class, 'cancel'])->name('supplier-invoices.cancel');
316: 
317:         Route::apiResource('supplier-payments', SupplierPaymentController::class)
318:             ->parameters(['supplier-payments' => 'supplier_payment']);
319:         Route::post('supplier-payments/{supplier_payment}/allocate', [SupplierPaymentController::class, 'allocate'])->name('supplier-payments.allocate');
320:         Route::post('supplier-payments/{supplier_payment}/void', [SupplierPaymentController::class, 'void'])->name('supplier-payments.void');
321: 
322:         Route::middleware('permission:store.manage|settings.view')->group(function (): void {
323:             Route::get('banners', [BannerController::class, 'index'])->name('banners.index');
324:             Route::get('banners/{banner}', [BannerController::class, 'show'])->name('banners.show');
325:         });
326:         Route::middleware('permission:store.manage')->group(function (): void {
327:             Route::put('banners/reorder', [BannerController::class, 'reorder'])->name('banners.reorder');
328:             Route::post('banners', [BannerController::class, 'store'])->middleware('throttle:30,1')->name('banners.store');
329:             Route::post('banners/{banner}', [BannerController::class, 'update'])->middleware('throttle:30,1')->name('banners.update');
330:             Route::patch('banners/{banner}/toggle', [BannerController::class, 'toggle'])->name('banners.toggle');
331:             Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->name('banners.destroy');
332:         });
333: 
334:         Route::get('settings', [SettingController::class, 'index'])
335:             ->middleware('permission:settings.view|settings.manage')
336:             ->name('settings.index');
337:         Route::put('settings', [SettingController::class, 'update'])
338:             ->middleware('permission:settings.update|settings.manage')
339:             ->name('settings.update');
340: 
341:         // ================== المرحلة 11: الخزائن، المصروفات، تحويلات الخزائن، الورديات ==================
342:         Route::pattern('treasury', '[0-9]{1,18}');
343:         Route::pattern('expense', '[0-9]{1,18}');
344:         Route::pattern('treasury_transfer', '[0-9]{1,18}');
345:         Route::pattern('shift', '[0-9]{1,18}');
346: 
347:         Route::apiResource('treasuries', TreasuryController::class);
348: 
349:         Route::apiResource('expenses', ExpenseController::class);
350:         Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');
351: 
352:         Route::apiResource('treasury-transfers', TreasuryTransferController::class)
353:             ->parameters(['treasury-transfers' => 'treasuryTransfer'])
354:             ->only(['index', 'store', 'show']);
355:         Route::post('treasury-transfers/{treasuryTransfer}/approve', [TreasuryTransferController::class, 'approve'])
356:             ->name('treasury-transfers.approve');
357:         Route::post('treasury-transfers/{treasuryTransfer}/reject', [TreasuryTransferController::class, 'reject'])
358:             ->name('treasury-transfers.reject');
359: 
360:         Route::get('shifts', [PosShiftAdminController::class, 'index'])
361:             ->name('shifts.index');
362:         Route::get('shifts/{shift}', [PosShiftAdminController::class, 'show'])
363:             ->name('shifts.show');
364:         Route::post('shifts/{shift}/approve', [PosShiftAdminController::class, 'approve'])
365:             ->name('shifts.approve');
366: 
367:         Route::prefix('ai')->name('ai.')->middleware('permission:ai_agent.use')->group(function (): void {
368:             Route::get('settings', [AiAdministrationController::class, 'settings'])->middleware('permission:ai_agent.settings')->name('settings');
369:             Route::put('settings', [AiAdministrationController::class, 'updateSettings'])->middleware('permission:ai_agent.settings')->name('settings.update');
370:             Route::get('usage', [AiAdministrationController::class, 'usage'])->name('usage');
371:             Route::patch('conversations/{conversation}/pin', [AiConversationController::class, 'pin'])->name('conversations.pin');
372:             Route::post('conversations/{conversation}/stream', [AiMessageController::class, 'stream'])->middleware('throttle:ai-chat')->name('conversations.stream');
373:             Route::get('conversations', [AiConversationController::class, 'index'])->name('conversations.index');
374:             Route::post('conversations', [AiConversationController::class, 'store'])->name('conversations.store');
375:             Route::get('conversations/{conversation}', [AiConversationController::class, 'show'])->name('conversations.show');
376:             Route::delete('conversations/{conversation}', [AiConversationController::class, 'destroy'])->name('conversations.destroy');
377:             Route::post('conversations/{conversation}/messages', [AiMessageController::class, 'store'])->middleware('throttle:ai-chat')->name('conversations.messages.store');
378:             Route::post('actions/{action}/confirm', [AiActionController::class, 'confirm'])->middleware('permission:ai_agent.execute')->name('actions.confirm');
379:             Route::post('actions/{action}/cancel', [AiActionController::class, 'cancel'])->name('actions.cancel');
380:             Route::post('actions/{action}/undo', [AiActionController::class, 'undo'])->middleware('permission:ai_agent.execute')->name('actions.undo');
381:             // M6: الزر بتاع mentions بيتنادى مع كل ضغطة بعد debounce — GAS
382:             // (AuditStaffAdministration) يعدّ POST بس ففلتر GET مش مغطّى.
383:             Route::get('mentions', [AiMentionController::class, 'search'])->middleware('throttle:60,1')->name('mentions');
384:             Route::get('welcome', [AiWelcomeController::class, 'show'])->name('welcome');
385:         });
386:     });
```

## File: routes/api/wholesale.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use App\Http\Controllers\Api\V1\AttachmentController;
 6: use App\Http\Controllers\Api\V1\Shared\NotificationController;
 7: use App\Http\Controllers\Api\V1\Wholesale\DashboardController;
 8: use App\Http\Controllers\Api\V1\Wholesale\InvoiceController;
 9: use App\Http\Controllers\Api\V1\Wholesale\OrderController;
10: use App\Http\Controllers\Api\V1\Wholesale\PaymentNoticeController;
11: use App\Http\Controllers\Api\V1\Wholesale\ProductController;
12: use App\Http\Controllers\Api\V1\Wholesale\QuickOrderController;
13: use App\Http\Controllers\Api\V1\Wholesale\QuotationController;
14: use App\Http\Controllers\Api\V1\Wholesale\RegisterController;
15: use Illuminate\Support\Facades\Route;
16: 
17: Route::prefix('auth')->name('auth.')->group(function (): void {
18:     // التسجيل ورفع المستندات لتاجر الجملة
19:     Route::post('register', [RegisterController::class, 'register'])->middleware('throttle:customer-registration')->name('register');
20: 
21:     // متابعة حالة الطلب (يتطلب توكن عميل فقط، ولا يتطلب wholesale.approved)
22:     Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
23:         Route::get('application-status', [RegisterController::class, 'applicationStatus'])->name('application-status');
24:         // H-1: إعادة رفع بديل للمستند المرفوض لتاجر pending.
25:         Route::post('documents', [RegisterController::class, 'uploadDocument'])
26:             ->middleware('throttle:customer-registration')->name('documents.store');
27:     });
28: });
29: 
30: // بوابة الجملة المتقدمة (المرحلتان 12 و13): الحماية الثلاثية إلزامية — لا يصل
31: // أي عميل قطاعي أو تاجر قيد المراجعة إلى أسعار الجملة وشرائح الكميات والسداد الآجل.
32: Route::middleware(['auth:sanctum', 'auth.customer', 'wholesale.approved'])->group(function (): void {
33:     Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
34: 
35:     // الكتالوج وتصدير قوائم الأسعار PDF / Excel
36:     Route::get('products', [ProductController::class, 'index'])->name('products.index');
37:     Route::get('price-list/export', [ProductController::class, 'exportPriceList'])->name('price-list.export');
38: 
39:     // الطلب السريع واستيراد Excel وإعادة الطلب
40:     Route::post('quick-order/lookup', [QuickOrderController::class, 'lookup'])->name('quick-order.lookup');
41:     Route::post('quick-order/import', [QuickOrderController::class, 'import'])->name('quick-order.import');
42:     Route::post('quick-order/add-to-cart', [QuickOrderController::class, 'addToCart'])->name('quick-order.add-to-cart');
43:     Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
44:     Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
45:     Route::post('orders/{order}/reorder', [OrderController::class, 'reorder'])->whereNumber('order')->name('orders.reorder');
46: 
47:     // الفواتير، كشف الحساب، وإشعارات السداد البنكي
48:     Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
49:     Route::get('invoices/{order}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('order')->name('invoices.pdf');
50:     Route::get('statement', [InvoiceController::class, 'statement'])->name('statement');
51:     Route::post('payment-notices', [InvoiceController::class, 'storePaymentNotice'])->name('payment-notices.store');
52:     // قراءة الإشعارات والمديونية وتنزيل الإيصالات (تكملة من 12.1)
53:     Route::get('payment-notices', [PaymentNoticeController::class, 'index'])->name('payment-notices.index');
54:     Route::get('payment-notices/outstanding', [PaymentNoticeController::class, 'outstanding'])->name('payment-notices.outstanding');
55:     Route::get('payment-notices/{payment_notice}', [PaymentNoticeController::class, 'show'])->whereNumber('payment_notice')->name('payment-notices.show');
56:     Route::get('payment-notices/{notice}/receipt', [AttachmentController::class, 'paymentNotice'])->whereNumber('notice')->name('payment-notices.receipt');
57: 
58:     // عروض الأسعار (submit خطوة التاجر قبل تسعير الإدارة — من 12.1)
59:     Route::apiResource('quotations', QuotationController::class)->parameters(['quotations' => 'quotation']);
60:     Route::post('quotations/{quotation}/submit', [QuotationController::class, 'submit'])->whereNumber('quotation')->name('quotations.submit');
61:     Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept'])->whereNumber('quotation')->name('quotations.accept');
62:     Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject'])->whereNumber('quotation')->name('quotations.reject');
63: 
64:     // الإشعارات (المرحلة 13)
65:     Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
66:     Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
67:     Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
68: });
```

## File: phpunit.xml
```xml
 1: <?xml version="1.0" encoding="UTF-8"?>
 2: <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
 3:          xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
 4:          bootstrap="vendor/autoload.php"
 5:          colors="true"
 6: >
 7:     <testsuites>
 8:         <testsuite name="Unit">
 9:             <directory>tests/Unit</directory>
10:         </testsuite>
11:         <testsuite name="Feature">
12:             <directory>tests/Feature</directory>
13:         </testsuite>
14:     </testsuites>
15:     <source>
16:         <include>
17:             <directory>app</directory>
18:         </include>
19:     </source>
20:     <php>
21:         <ini name="memory_limit" value="512M"/>
22:         <env name="APP_ENV" value="testing"/>
23:         <env name="MEDIA_DISK" value="r2" force="true"/>
24:         <env name="MEDIA_CONVERSIONS_DISK" value="r2" force="true"/>
25:         <env name="APP_LOCALE" value="ar"/>
26:         <!-- Compatibility endpoints are explicitly enabled only for their regression suite. -->
27:         <env name="LEGACY_LOGIN_ENABLED" value="true" force="true"/>
28:         <env name="APP_MAINTENANCE_DRIVER" value="file"/>
29:         <env name="BCRYPT_ROUNDS" value="4"/>
30:         <env name="BROADCAST_CONNECTION" value="null"/>
31:         <env name="CACHE_STORE" value="array"/>
32:         <env name="DB_CONNECTION" value="pgsql" force="true"/>
33:         <env name="DB_DATABASE" value="alfarid_testing" force="true"/>
34:         <env name="DB_URL" value="" force="true"/>
35:         <env name="MAIL_MAILER" value="array"/>
36:         <env name="QUEUE_CONNECTION" value="sync"/>
37:         <env name="MEDIA_QUEUE_CONNECTION" value="sync" force="true"/>
38:         <env name="CUSTOMER_PASSWORD_RESET_QUEUE_CONNECTION" value="sync"/>
39:         <env name="CUSTOMER_REQUIRE_PHONE_VERIFICATION" value="false"/>
40:         <env name="WHOLESALE_DEMO_PASSWORD" value="demo-password-1234"/>
41:         <env name="SESSION_DRIVER" value="array"/>
42:         <env name="PULSE_ENABLED" value="false"/>
43:         <env name="TELESCOPE_ENABLED" value="false"/>
44:         <env name="NIGHTWATCH_ENABLED" value="false"/>
45:     </php>
46: </phpunit>
```

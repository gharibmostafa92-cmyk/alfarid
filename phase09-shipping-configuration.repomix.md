This file is a merged representation of a subset of the codebase, containing specifically included files and files not matching ignore patterns, combined into a single document by Repomix.
The content has been processed where line numbers have been added.

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
- Only files matching these patterns are included: bags-platform/backend/app/Services/ShippingQuoteService.php, bags-platform/backend/app/Services/ShippingWebhookService.php, bags-platform/backend/app/Services/StorePaymentPolicy.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ShippingCompanyController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ShippingZoneController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ShippingRateController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Store/CatalogController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Store/CheckoutController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Webhook/ShippingWebhookController.php, bags-platform/backend/app/Models/ShippingCompany.php, bags-platform/backend/app/Models/ShippingZone.php, bags-platform/backend/app/Models/ShippingRate.php, bags-platform/backend/app/Models/City.php, bags-platform/backend/app/Models/Shipment.php, bags-platform/backend/app/Models/WebhookLog.php, bags-platform/backend/app/Enums/ShipmentStatus.php, bags-platform/backend/app/Http/Requests/Admin/Shipping/ShippingCompanyRequest.php, bags-platform/backend/app/Http/Requests/Admin/Shipping/ShippingZoneRequest.php, bags-platform/backend/app/Http/Requests/Admin/Shipping/ShippingRateRequest.php, bags-platform/backend/app/Http/Requests/Store/Checkout/ShippingOptionsRequest.php, bags-platform/backend/app/Http/Resources/Admin/ShippingCompanyResource.php, bags-platform/backend/app/Http/Resources/Admin/ShippingZoneResource.php, bags-platform/backend/app/Http/Resources/Admin/ShippingRateResource.php, bags-platform/backend/app/Http/Resources/Store/StoreCityResource.php, bags-platform/backend/app/Modules/Shipping/ShippingServiceProvider.php, bags-platform/backend/routes/api/admin.php, bags-platform/backend/routes/api/store.php, bags-platform/backend/routes/api/webhooks.php, bags-platform/backend/database/migrations/2026_10_16_000001_complete_customers_structure.php, bags-platform/backend/database/migrations/2026_10_17_100004_create_shipping_tables.php, bags-platform/backend/database/factories/ShippingCompanyFactory.php, bags-platform/backend/database/factories/ShippingZoneFactory.php, bags-platform/backend/database/factories/ShippingRateFactory.php, bags-platform/backend/database/factories/CityFactory.php, bags-platform/backend/database/factories/ShipmentFactory.php, bags-platform/backend/database/seeders/ShippingPermissionSeeder.php, bags-platform/backend/database/seeders/RolePermissionSeeder.php, bags-platform/frontend/packages/api-client/src/endpoints/operations/commerce.ts, bags-platform/frontend/packages/api-client/src/endpoints/index.ts, bags-platform/frontend/packages/api-client/src/types/index.ts, bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/commerce.tsx, bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/data.ts, bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/api/mappers.ts, bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/OperationsMockPage.tsx, bags-platform/frontend/apps/backoffice/src/App.tsx, bags-platform/frontend/apps/storefront/src/components/sophie/checkout/ShippingSection.tsx, bags-platform/frontend/apps/storefront/src/components/sophie/checkout/useCheckout.ts, bags-platform/docs/api-samples/cities.json, bags-platform/docs/api-samples/shipping-options.json, bags-platform/docs/api-samples/checkout-shipping-options.json
- Files matching these patterns are excluded: **/*Test.php, **/*.test.*, **/*.spec.*, **/tests/**, **/__tests__/**, **/test-results/**, **/node_modules/**, **/vendor/**, **/dist/**, **/.turbo/**, **/.next/**
- Files matching default ignore patterns are excluded
- Line numbers have been added to the beginning of each line
- Files are sorted by Git change count (files with more changes are at the bottom)

# User Provided Header
Phase 09: Shipping Configuration — إعداد الشحن وتسعيره [P09] & Module 09.01: Shipping Setup & Quotes [P09-M01] (All test files excluded)

# Directory Structure
```
bags-platform/
  backend/
    app/
      Enums/
        ShipmentStatus.php
      Http/
        Controllers/
          Api/
            V1/
              Admin/
                ShippingCompanyController.php
                ShippingRateController.php
                ShippingZoneController.php
              Store/
                CatalogController.php
                CheckoutController.php
              Webhook/
                ShippingWebhookController.php
        Requests/
          Admin/
            Shipping/
              ShippingCompanyRequest.php
              ShippingRateRequest.php
              ShippingZoneRequest.php
          Store/
            Checkout/
              ShippingOptionsRequest.php
        Resources/
          Admin/
            ShippingCompanyResource.php
            ShippingRateResource.php
            ShippingZoneResource.php
          Store/
            StoreCityResource.php
      Models/
        City.php
        Shipment.php
        ShippingCompany.php
        ShippingRate.php
        ShippingZone.php
        WebhookLog.php
      Modules/
        Shipping/
          ShippingServiceProvider.php
      Services/
        ShippingQuoteService.php
        ShippingWebhookService.php
        StorePaymentPolicy.php
    database/
      factories/
        CityFactory.php
        ShipmentFactory.php
        ShippingCompanyFactory.php
        ShippingRateFactory.php
        ShippingZoneFactory.php
      migrations/
        2026_10_16_000001_complete_customers_structure.php
        2026_10_17_100004_create_shipping_tables.php
      seeders/
        RolePermissionSeeder.php
        ShippingPermissionSeeder.php
    routes/
      api/
        admin.php
        store.php
        webhooks.php
  docs/
    api-samples/
      checkout-shipping-options.json
      cities.json
      shipping-options.json
  frontend/
    apps/
      backoffice/
        src/
          pages/
            admin/
              operations-mock/
                api/
                  mappers.ts
                commerce.tsx
                data.ts
                OperationsMockPage.tsx
          App.tsx
      storefront/
        src/
          components/
            sophie/
              checkout/
                ShippingSection.tsx
                useCheckout.ts
    packages/
      api-client/
        src/
          endpoints/
            operations/
              commerce.ts
            index.ts
          types/
            index.ts
```

# Files

## File: bags-platform/backend/app/Enums/ShipmentStatus.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: /** حالات بوليصة الشحن. */
 8: enum ShipmentStatus: string
 9: {
10:     case Created = 'created';
11:     case PickedUp = 'picked_up';
12:     case InTransit = 'in_transit';
13:     case OutForDelivery = 'out_for_delivery';
14:     case Delivered = 'delivered';
15:     case Failed = 'failed';
16:     case Returned = 'returned';
17: }
```

## File: bags-platform/backend/app/Http/Resources/Admin/ShippingCompanyResource.php
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
10: class ShippingCompanyResource extends JsonResource
11: {
12:     /** @return array<string, mixed> */
13:     public function toArray(Request $request): array
14:     {
15:         return [
16:             'id' => $this->id,
17:             'name' => $this->name,
18:             'code' => $this->code,
19:             // بيانات الاعتماد لا تُعرض أبدًا — مؤشر وجود فقط
20:             'has_api_credentials' => ! empty($this->api_credentials),
21:             'tracking_url_template' => $this->tracking_url_template,
22:             'is_active' => $this->is_active,
23:             'created_at' => $this->created_at,
24:             'updated_at' => $this->updated_at,
25:         ];
26:     }
27: }
```

## File: bags-platform/backend/app/Http/Resources/Admin/ShippingRateResource.php
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
10: class ShippingRateResource extends JsonResource
11: {
12:     /** @return array<string, mixed> */
13:     public function toArray(Request $request): array
14:     {
15:         return [
16:             'id' => $this->id,
17:             'shipping_company_id' => $this->shipping_company_id,
18:             'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
19:             'shipping_zone_id' => $this->shipping_zone_id,
20:             'zone_name' => $this->whenLoaded('zone', fn () => $this->zone?->name),
21:             'base_cost' => $this->base_cost,
22:             'free_shipping_threshold' => $this->free_shipping_threshold,
23:             'cod_fee' => $this->cod_fee,
24:             'estimated_days_min' => $this->estimated_days_min,
25:             'estimated_days_max' => $this->estimated_days_max,
26:             'created_at' => $this->created_at,
27:             'updated_at' => $this->updated_at,
28:         ];
29:     }
30: }
```

## File: bags-platform/backend/app/Http/Resources/Admin/ShippingZoneResource.php
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
10: class ShippingZoneResource extends JsonResource
11: {
12:     /** @return array<string, mixed> */
13:     public function toArray(Request $request): array
14:     {
15:         return [
16:             'id' => $this->id,
17:             'name' => $this->name,
18:             'is_active' => $this->is_active,
19:             'cities' => $this->whenLoaded('cities', fn () => $this->cities->map(fn ($city): array => [
20:                 'id' => $city->id,
21:                 'name_ar' => $city->name_ar,
22:                 'name_en' => $city->name_en,
23:             ])->all()),
24:             'created_at' => $this->created_at,
25:             'updated_at' => $this->updated_at,
26:         ];
27:     }
28: }
```

## File: bags-platform/backend/app/Http/Resources/Store/StoreCityResource.php
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
10: class StoreCityResource extends JsonResource
11: {
12:     /**
13:      * @return array<string, mixed>
14:      */
15:     public function toArray(Request $request): array
16:     {
17:         return [
18:             'id' => $this->id,
19:             'name_ar' => $this->name_ar,
20:             'name_en' => $this->name_en,
21:             'shipping_cost' => $this->shipping_cost,
22:         ];
23:     }
24: }
```

## File: bags-platform/backend/app/Models/City.php
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

## File: bags-platform/backend/app/Models/ShippingCompany.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Database\Factories\ShippingCompanyFactory;
 8: use Illuminate\Database\Eloquent\Factories\HasFactory;
 9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\HasMany;
11: 
12: class ShippingCompany extends Model
13: {
14:     /** @use HasFactory<ShippingCompanyFactory> */
15:     use HasFactory;
16: 
17:     protected $fillable = [
18:         'name',
19:         'code',
20:         'api_credentials',
21:         'tracking_url_template',
22:         'is_active',
23:     ];
24: 
25:     protected function casts(): array
26:     {
27:         return [
28:             // بيانات اعتماد واجهة الشركة — مشفرة في قاعدة البيانات
29:             'api_credentials' => 'encrypted:array',
30:             'is_active' => 'boolean',
31:         ];
32:     }
33: 
34:     /** @return HasMany<ShippingRate, $this> */
35:     public function rates(): HasMany
36:     {
37:         return $this->hasMany(ShippingRate::class);
38:     }
39: 
40:     /** @return HasMany<Shipment, $this> */
41:     public function shipments(): HasMany
42:     {
43:         return $this->hasMany(Shipment::class);
44:     }
45: }
```

## File: bags-platform/backend/app/Models/ShippingRate.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Database\Factories\ShippingRateFactory;
 8: use Illuminate\Database\Eloquent\Factories\HasFactory;
 9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: 
12: class ShippingRate extends Model
13: {
14:     /** @use HasFactory<ShippingRateFactory> */
15:     use HasFactory;
16: 
17:     protected $fillable = [
18:         'shipping_company_id',
19:         'shipping_zone_id',
20:         'base_cost',
21:         'free_shipping_threshold',
22:         'cod_fee',
23:         'estimated_days_min',
24:         'estimated_days_max',
25:     ];
26: 
27:     protected function casts(): array
28:     {
29:         return [
30:             'base_cost' => 'decimal:2',
31:             'free_shipping_threshold' => 'decimal:2',
32:             'cod_fee' => 'decimal:2',
33:             'estimated_days_min' => 'integer',
34:             'estimated_days_max' => 'integer',
35:         ];
36:     }
37: 
38:     /** @return BelongsTo<ShippingCompany, $this> */
39:     public function company(): BelongsTo
40:     {
41:         return $this->belongsTo(ShippingCompany::class, 'shipping_company_id');
42:     }
43: 
44:     /** @return BelongsTo<ShippingZone, $this> */
45:     public function zone(): BelongsTo
46:     {
47:         return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
48:     }
49: }
```

## File: bags-platform/backend/app/Models/ShippingZone.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Database\Factories\ShippingZoneFactory;
 8: use Illuminate\Database\Eloquent\Factories\HasFactory;
 9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsToMany;
11: use Illuminate\Database\Eloquent\Relations\HasMany;
12: 
13: class ShippingZone extends Model
14: {
15:     /** @use HasFactory<ShippingZoneFactory> */
16:     use HasFactory;
17: 
18:     protected $fillable = [
19:         'name',
20:         'is_active',
21:     ];
22: 
23:     protected function casts(): array
24:     {
25:         return [
26:             'is_active' => 'boolean',
27:         ];
28:     }
29: 
30:     /** @return BelongsToMany<City, $this> */
31:     public function cities(): BelongsToMany
32:     {
33:         return $this->belongsToMany(City::class, 'shipping_zone_cities');
34:     }
35: 
36:     /** @return HasMany<ShippingRate, $this> */
37:     public function rates(): HasMany
38:     {
39:         return $this->hasMany(ShippingRate::class);
40:     }
41: }
```

## File: bags-platform/backend/app/Modules/Shipping/ShippingServiceProvider.php
```php
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace App\Modules\Shipping;
6: 
7: use Illuminate\Support\ServiceProvider;
8: 
9: class ShippingServiceProvider extends ServiceProvider {}
```

## File: bags-platform/backend/database/factories/CityFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Models\City;
 8: use Illuminate\Database\Eloquent\Factories\Factory;
 9: 
10: /**
11:  * @extends Factory<City>
12:  */
13: class CityFactory extends Factory
14: {
15:     protected $model = City::class;
16: 
17:     public function definition(): array
18:     {
19:         return [
20:             'name_ar' => fake()->unique()->city(),
21:             'name_en' => fake()->unique()->city(),
22:             'shipping_cost' => fake()->randomFloat(2, 0, 150),
23:             'is_active' => true,
24:         ];
25:     }
26: }
```

## File: bags-platform/backend/database/factories/ShipmentFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Enums\ShipmentStatus;
 8: use App\Models\Order;
 9: use App\Models\Shipment;
10: use App\Models\ShippingCompany;
11: use Illuminate\Database\Eloquent\Factories\Factory;
12: 
13: /**
14:  * @extends Factory<Shipment>
15:  */
16: class ShipmentFactory extends Factory
17: {
18:     public function definition(): array
19:     {
20:         return [
21:             'order_id' => Order::factory(),
22:             'shipping_company_id' => ShippingCompany::factory(),
23:             'awb_number' => 'AWB-'.fake()->unique()->numerify('########'),
24:             'status' => ShipmentStatus::Created,
25:             'label_url' => null,
26:             'shipping_cost' => 50,
27:             'cod_amount' => 0,
28:             'raw_response' => null,
29:         ];
30:     }
31: }
```

## File: bags-platform/backend/database/factories/ShippingCompanyFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Models\ShippingCompany;
 8: use Illuminate\Database\Eloquent\Factories\Factory;
 9: 
10: /**
11:  * @extends Factory<ShippingCompany>
12:  */
13: class ShippingCompanyFactory extends Factory
14: {
15:     public function definition(): array
16:     {
17:         return [
18:             'name' => fake()->company(),
19:             'code' => 'ship-'.fake()->unique()->numerify('###'),
20:             'api_credentials' => null,
21:             'tracking_url_template' => null,
22:             'is_active' => true,
23:         ];
24:     }
25: }
```

## File: bags-platform/backend/database/factories/ShippingRateFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Models\ShippingCompany;
 8: use App\Models\ShippingRate;
 9: use App\Models\ShippingZone;
10: use Illuminate\Database\Eloquent\Factories\Factory;
11: 
12: /**
13:  * @extends Factory<ShippingRate>
14:  */
15: class ShippingRateFactory extends Factory
16: {
17:     public function definition(): array
18:     {
19:         return [
20:             'shipping_company_id' => ShippingCompany::factory(),
21:             'shipping_zone_id' => ShippingZone::factory(),
22:             'base_cost' => 50,
23:             'free_shipping_threshold' => null,
24:             'cod_fee' => 0,
25:             'estimated_days_min' => 2,
26:             'estimated_days_max' => 4,
27:         ];
28:     }
29: }
```

## File: bags-platform/backend/database/factories/ShippingZoneFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Models\ShippingZone;
 8: use Illuminate\Database\Eloquent\Factories\Factory;
 9: 
10: /**
11:  * @extends Factory<ShippingZone>
12:  */
13: class ShippingZoneFactory extends Factory
14: {
15:     public function definition(): array
16:     {
17:         return [
18:             'name' => fake()->city().' الكبرى',
19:             'is_active' => true,
20:         ];
21:     }
22: }
```

## File: bags-platform/backend/database/migrations/2026_10_16_000001_complete_customers_structure.php
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

## File: bags-platform/backend/database/migrations/2026_10_17_100004_create_shipping_tables.php
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
11:  * الشحن: شركات ومناطق وأسعار وبوالص.
12:  */
13: return new class extends Migration
14: {
15:     public function up(): void
16:     {
17:         Schema::create('shipping_companies', function (Blueprint $table): void {
18:             $table->id();
19:             $table->string('name');
20:             $table->string('code')->unique(); // bosta | aramex | internal ...
21:             $table->text('api_credentials')->nullable(); // مشفرة عبر Cast في الموديل — text لأن النص المشفر ليس JSON
22:             $table->string('tracking_url_template')->nullable();
23:             $table->boolean('is_active')->default(true);
24:             $table->timestamps();
25:         });
26: 
27:         Schema::create('shipping_zones', function (Blueprint $table): void {
28:             $table->id();
29:             $table->string('name'); // القاهرة الكبرى | الدلتا | الصعيد ...
30:             $table->boolean('is_active')->default(true);
31:             $table->timestamps();
32:         });
33: 
34:         Schema::create('shipping_zone_cities', function (Blueprint $table): void {
35:             $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
36:             // بلا FK على cities: تراجع هجرة المرحلة 5.1 يُسقط cities مباشرة (نفس سبب orders.city_id)
37:             $table->unsignedBigInteger('city_id')->index();
38: 
39:             $table->primary(['shipping_zone_id', 'city_id']);
40:         });
41: 
42:         Schema::create('shipping_rates', function (Blueprint $table): void {
43:             $table->id();
44:             $table->foreignId('shipping_company_id')->constrained()->cascadeOnDelete();
45:             $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
46:             $table->decimal('base_cost', 10, 2);
47:             // شحن مجاني إذا تخطى الطلب هذا المبلغ
48:             $table->decimal('free_shipping_threshold', 12, 2)->nullable();
49:             // رسوم الدفع عند الاستلام
50:             $table->decimal('cod_fee', 10, 2)->default(0);
51:             $table->unsignedInteger('estimated_days_min');
52:             $table->unsignedInteger('estimated_days_max');
53:             $table->timestamps();
54: 
55:             $table->unique(['shipping_company_id', 'shipping_zone_id']);
56:         });
57: 
58:         Schema::create('shipments', function (Blueprint $table): void {
59:             $table->id();
60:             $table->foreignId('order_id')->constrained()->cascadeOnDelete();
61:             $table->foreignId('shipping_company_id')->constrained();
62:             $table->string('awb_number')->unique();
63:             $table->string('status')->default('created');
64:             $table->string('label_url')->nullable();
65:             $table->decimal('shipping_cost', 10, 2)->default(0);
66:             $table->decimal('cod_amount', 12, 2)->default(0);
67:             $table->json('raw_response')->nullable();
68:             $table->timestamps();
69: 
70:             $table->index('status');
71:         });
72: 
73:         DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_status_check CHECK (status IN ('created', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'))");
74:     }
75: 
76:     public function down(): void
77:     {
78:         Schema::dropIfExists('shipments');
79:         Schema::dropIfExists('shipping_rates');
80:         Schema::dropIfExists('shipping_zone_cities');
81:         Schema::dropIfExists('shipping_zones');
82:         Schema::dropIfExists('shipping_companies');
83:     }
84: };
```

## File: bags-platform/backend/database/seeders/ShippingPermissionSeeder.php
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
13: class ShippingPermissionSeeder extends Seeder
14: {
15:     /**
16:      * Run the database seeds.
17:      */
18:     public function run(): void
19:     {
20:         app(PermissionRegistrar::class)->forgetCachedPermissions();
21: 
22:         $permissions = [
23:             'shipping.view', 'shipping.create', 'shipping.update', 'shipping.delete',
24:         ];
25: 
26:         $created = [];
27:         foreach ($permissions as $permission) {
28:             $created[] = Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'staff']);
29:         }
30: 
31:         $superAdmin = Role::firstOrCreate(['name' => StaffRole::SuperAdmin->value, 'guard_name' => 'staff']);
32:         $superAdmin->givePermissionTo($created);
33:     }
34: }
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ShippingCompanyController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Exceptions\BusinessException;
 8: use App\Http\Controllers\Controller;
 9: use App\Http\Requests\Admin\Shipping\ShippingCompanyRequest;
10: use App\Http\Resources\Admin\ShippingCompanyResource;
11: use App\Models\ShippingCompany;
12: use Illuminate\Http\JsonResponse;
13: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
14: use Illuminate\Routing\Controllers\HasMiddleware;
15: use Illuminate\Routing\Controllers\Middleware;
16: 
17: /** إدارة شركات الشحن. */
18: class ShippingCompanyController extends Controller implements HasMiddleware
19: {
20:     public static function middleware(): array
21:     {
22:         return [
23:             new Middleware('can:shipping.view', only: ['index', 'show']),
24:             new Middleware('can:shipping.create', only: ['store']),
25:             new Middleware('can:shipping.update', only: ['update']),
26:             new Middleware('can:shipping.delete', only: ['destroy']),
27:         ];
28:     }
29: 
30:     public function index(): AnonymousResourceCollection
31:     {
32:         return ShippingCompanyResource::collection(ShippingCompany::query()->latest('id')->paginate(20));
33:     }
34: 
35:     public function store(ShippingCompanyRequest $request): JsonResponse
36:     {
37:         $company = ShippingCompany::create($request->validated());
38: 
39:         return ShippingCompanyResource::make($company)->response()->setStatusCode(201);
40:     }
41: 
42:     public function show(ShippingCompany $shippingCompany): ShippingCompanyResource
43:     {
44:         return ShippingCompanyResource::make($shippingCompany);
45:     }
46: 
47:     public function update(ShippingCompanyRequest $request, ShippingCompany $shippingCompany): ShippingCompanyResource
48:     {
49:         $data = $request->validated();
50:         // الأسرار لا تُرجع في الردود (has_api_credentials فقط)، فشاشة التعديل
51:         // ترسل null — تُتجاهل ولا تمحو الأسرار المحفوظة (M10)
52:         if (array_key_exists('api_credentials', $data)) {
53:             if ($data['api_credentials'] === null) {
54:                 unset($data['api_credentials']);
55:             } else {
56:                 // دمج لا استبدال (F21): تحديث مفتاح واحد لا يمحو بقية أسرار
57:                 // الشركة (مفتاح API، سر الوب هوك...) المعروضة كـ null في الشاشة
58:                 $data['api_credentials'] = array_merge(
59:                     $shippingCompany->api_credentials ?? [],
60:                     $data['api_credentials'],
61:                 );
62:             }
63:         }
64:         $shippingCompany->update($data);
65: 
66:         return ShippingCompanyResource::make($shippingCompany);
67:     }
68: 
69:     public function destroy(ShippingCompany $shippingCompany): JsonResponse
70:     {
71:         // شركة لها بوليصات: الحذف يكسر FK — يُمنع ويُعطَّل بدلًا منه (M9)
72:         if ($shippingCompany->shipments()->exists()) {
73:             throw new BusinessException('لا يمكن حذف شركة شحن لها بوليصات مسجلة؛ عطّلها بدلًا من حذفها.');
74:         }
75:         $shippingCompany->delete();
76: 
77:         return response()->json(['message' => 'تم حذف شركة الشحن']);
78:     }
79: }
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ShippingRateController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Http\Requests\Admin\Shipping\ShippingRateRequest;
 9: use App\Http\Resources\Admin\ShippingRateResource;
10: use App\Models\ShippingRate;
11: use Illuminate\Http\JsonResponse;
12: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
13: use Illuminate\Routing\Controllers\HasMiddleware;
14: use Illuminate\Routing\Controllers\Middleware;
15: 
16: /** إدارة أسعار الشحن (شركة × منطقة). */
17: class ShippingRateController extends Controller implements HasMiddleware
18: {
19:     public static function middleware(): array
20:     {
21:         return [
22:             new Middleware('can:shipping.view', only: ['index', 'show']),
23:             new Middleware('can:shipping.create', only: ['store']),
24:             new Middleware('can:shipping.update', only: ['update']),
25:             new Middleware('can:shipping.delete', only: ['destroy']),
26:         ];
27:     }
28: 
29:     public function index(): AnonymousResourceCollection
30:     {
31:         return ShippingRateResource::collection(
32:             ShippingRate::query()->with(['company', 'zone'])->latest('id')->paginate(20)
33:         );
34:     }
35: 
36:     public function store(ShippingRateRequest $request): JsonResponse
37:     {
38:         $rate = ShippingRate::create($request->validated());
39: 
40:         return $this->rateResource($rate)->response()->setStatusCode(201);
41:     }
42: 
43:     public function show(ShippingRate $shippingRate): ShippingRateResource
44:     {
45:         return $this->rateResource($shippingRate);
46:     }
47: 
48:     public function update(ShippingRateRequest $request, ShippingRate $shippingRate): ShippingRateResource
49:     {
50:         $shippingRate->update($request->validated());
51: 
52:         return $this->rateResource($shippingRate);
53:     }
54: 
55:     public function destroy(ShippingRate $shippingRate): JsonResponse
56:     {
57:         $shippingRate->delete();
58: 
59:         return response()->json(['message' => 'تم حذف سعر الشحن']);
60:     }
61: 
62:     private function rateResource(ShippingRate $rate): ShippingRateResource
63:     {
64:         return ShippingRateResource::make($rate->loadMissing(['company', 'zone']));
65:     }
66: }
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ShippingZoneController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Http\Requests\Admin\Shipping\ShippingZoneRequest;
 9: use App\Http\Resources\Admin\ShippingZoneResource;
10: use App\Models\ShippingZone;
11: use Illuminate\Http\JsonResponse;
12: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
13: use Illuminate\Routing\Controllers\HasMiddleware;
14: use Illuminate\Routing\Controllers\Middleware;
15: use Illuminate\Support\Facades\DB;
16: 
17: /** إدارة مناطق الشحن ومدنها. */
18: class ShippingZoneController extends Controller implements HasMiddleware
19: {
20:     public static function middleware(): array
21:     {
22:         return [
23:             new Middleware('can:shipping.view', only: ['index', 'show']),
24:             new Middleware('can:shipping.create', only: ['store']),
25:             new Middleware('can:shipping.update', only: ['update']),
26:             new Middleware('can:shipping.delete', only: ['destroy']),
27:         ];
28:     }
29: 
30:     public function index(): AnonymousResourceCollection
31:     {
32:         return ShippingZoneResource::collection(
33:             ShippingZone::query()->with('cities')->latest('id')->paginate(20)
34:         );
35:     }
36: 
37:     public function store(ShippingZoneRequest $request): JsonResponse
38:     {
39:         $data = $request->validated();
40:         $zone = DB::transaction(function () use ($data): ShippingZone {
41:             $zone = ShippingZone::create($data);
42:             $zone->cities()->sync($data['city_ids'] ?? []);
43: 
44:             return $zone;
45:         });
46: 
47:         return $this->zoneResource($zone)->response()->setStatusCode(201);
48:     }
49: 
50:     public function show(ShippingZone $shippingZone): ShippingZoneResource
51:     {
52:         return $this->zoneResource($shippingZone);
53:     }
54: 
55:     public function update(ShippingZoneRequest $request, ShippingZone $shippingZone): ShippingZoneResource
56:     {
57:         $data = $request->validated();
58:         DB::transaction(function () use ($shippingZone, $data): void {
59:             $shippingZone->update($data);
60:             if (array_key_exists('city_ids', $data)) {
61:                 $shippingZone->cities()->sync($data['city_ids'] ?? []);
62:             }
63:         });
64: 
65:         return $this->zoneResource($shippingZone);
66:     }
67: 
68:     public function destroy(ShippingZone $shippingZone): JsonResponse
69:     {
70:         $shippingZone->delete();
71: 
72:         return response()->json(['message' => 'تم حذف منطقة الشحن']);
73:     }
74: 
75:     private function zoneResource(ShippingZone $zone): ShippingZoneResource
76:     {
77:         return ShippingZoneResource::make($zone->loadMissing('cities'));
78:     }
79: }
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Webhook/ShippingWebhookController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Webhook;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Services\ShippingWebhookService;
 9: use Illuminate\Http\JsonResponse;
10: use Illuminate\Http\Request;
11: 
12: /**
13:  * ويب هوك شركات الشحن — بلا مصادقة Sanctum؛ الحماية بالتوقيع
14:  * الرقمي HMAC بسر الشركة والتسجيل المسبق في webhook_logs.
15:  */
16: class ShippingWebhookController extends Controller
17: {
18:     public function __construct(private readonly ShippingWebhookService $webhooks) {}
19: 
20:     public function handle(Request $request, string $company): JsonResponse
21:     {
22:         $header = (string) (config('webhooks.shipping.signature_header') ?? 'X-Webhook-Signature');
23:         $raw = $request->getContent();
24: 
25:         // حد الحجم قبل أي فك ترميز (F9): الرفض والتسجيل في الخدمة
26:         $payload = strlen($raw) > 262144 ? null : json_decode($raw, true);
27:         $result = $this->webhooks->handle(
28:             $company,
29:             is_array($payload) ? $payload : [],
30:             $raw,
31:             $request->header($header),
32:             $request->ip(),
33:         );
34: 
35:         return response()->json($result['body'], $result['http']);
36:     }
37: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Shipping/ShippingCompanyRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Shipping;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: use Illuminate\Validation\Rule;
 9: 
10: class ShippingCompanyRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return true;
15:     }
16: 
17:     /** @return array<string, mixed> */
18:     public function rules(): array
19:     {
20:         $company = $this->route('shipping_company');
21: 
22:         return [
23:             'name' => ['required', 'string', 'max:255'],
24:             'code' => ['required', 'string', 'max:50', Rule::unique('shipping_companies', 'code')->ignore($company)],
25:             'api_credentials' => ['nullable', 'array'],
26:             // قالب رابط تتبع آمن (F-25): https فقط (يمنع javascript: ونحوه
27:             // كـ XSS مخزّن) ويجب أن يحوي العنصر النائب {awb}. لا نستخدم قاعدة
28:             // url لأنها ترفض الأقواس المعقوفة في القالب نفسه
29:             'tracking_url_template' => ['nullable', 'string', 'max:500', 'starts_with:https://', 'regex:/\{awb\}/'],
30:             'is_active' => ['nullable', 'boolean'],
31:         ];
32:     }
33: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Shipping/ShippingRateRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Shipping;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: use Illuminate\Validation\Rule;
 9: 
10: /**
11:  * Validates shipping rate configurations per carrier and destination zone.
12:  */
13: class ShippingRateRequest extends FormRequest
14: {
15:     public function authorize(): bool
16:     {
17:         return true;
18:     }
19: 
20:     /**
21:      * @return array<string, mixed>
22:      */
23:     public function rules(): array
24:     {
25:         $rate = $this->route('shipping_rate');
26: 
27:         return [
28:             'shipping_company_id' => ['required', 'integer', 'exists:shipping_companies,id'],
29:             'shipping_zone_id' => [
30:                 'required', 'integer', 'exists:shipping_zones,id',
31:                 Rule::unique('shipping_rates', 'shipping_zone_id')
32:                     ->where('shipping_company_id', $this->input('shipping_company_id'))
33:                     ->ignore($rate),
34:             ],
35:             // Strict upper bounds prevent PostgreSQL numeric overflow exceptions.
36:             'base_cost' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
37:             'free_shipping_threshold' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
38:             'cod_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
39:             'estimated_days_min' => ['required', 'integer', 'min:0', 'max:365'],
40:             'estimated_days_max' => ['required', 'integer', 'gte:estimated_days_min', 'max:365'],
41:         ];
42:     }
43: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Shipping/ShippingZoneRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Shipping;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: 
 9: /**
10:  * Validates shipping zone definitions and city mappings.
11:  */
12: class ShippingZoneRequest extends FormRequest
13: {
14:     public function authorize(): bool
15:     {
16:         return true;
17:     }
18: 
19:     /**
20:      * @return array<string, mixed>
21:      */
22:     public function rules(): array
23:     {
24:         return [
25:             'name' => ['required', 'string', 'max:255'],
26:             'is_active' => ['nullable', 'boolean'],
27:             'city_ids' => ['nullable', 'array', 'max:500'],
28:             'city_ids.*' => ['integer', 'exists:cities,id'],
29:         ];
30:     }
31: }
```

## File: bags-platform/backend/app/Http/Requests/Store/Checkout/ShippingOptionsRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Store\Checkout;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: use Illuminate\Validation\Rule;
 9: 
10: class ShippingOptionsRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return true;
15:     }
16: 
17:     /** @return array<string, mixed> */
18:     public function rules(): array
19:     {
20:         return [
21:             'city_id' => ['required', 'integer', 'exists:cities,id'],
22:             // لا subtotal ولا وزن من العميل (F-29): الإجمالي يُحسب من السلة
23:             // على الخادم، وطريقة الدفع تحدد رسوم COD المعروضة
24:             'payment_method' => ['nullable', Rule::in(['cod', 'card', 'wallet', 'bank_transfer', 'credit'])],
25:         ];
26:     }
27: }
```

## File: bags-platform/backend/app/Models/Shipment.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use App\Enums\ShipmentStatus;
 8: use Database\Factories\ShipmentFactory;
 9: use Illuminate\Database\Eloquent\Factories\HasFactory;
10: use Illuminate\Database\Eloquent\Model;
11: use Illuminate\Database\Eloquent\Relations\BelongsTo;
12: 
13: class Shipment extends Model
14: {
15:     /** @use HasFactory<ShipmentFactory> */
16:     use HasFactory;
17: 
18:     protected $fillable = [
19:         'order_id',
20:         'shipping_company_id',
21:         'awb_number',
22:         'status',
23:         'label_url',
24:         'shipping_cost',
25:         'cod_amount',
26:         'raw_response',
27:     ];
28: 
29:     protected function casts(): array
30:     {
31:         return [
32:             'status' => ShipmentStatus::class,
33:             'shipping_cost' => 'decimal:2',
34:             'cod_amount' => 'decimal:2',
35:             'raw_response' => 'array',
36:         ];
37:     }
38: 
39:     /** @return BelongsTo<Order, $this> */
40:     public function order(): BelongsTo
41:     {
42:         return $this->belongsTo(Order::class);
43:     }
44: 
45:     /** @return BelongsTo<ShippingCompany, $this> */
46:     public function company(): BelongsTo
47:     {
48:         return $this->belongsTo(ShippingCompany::class, 'shipping_company_id');
49:     }
50: 
51:     /** رابط تتبع عام من قالب الشركة إن وُجد. */
52:     public function trackingUrl(): ?string
53:     {
54:         $template = $this->company?->tracking_url_template;
55:         if ($template === null || $template === '') {
56:             return null;
57:         }
58: 
59:         // رقم البوليصة قد يحمل محارف خاصة من إدخال الأدمن — تُرمَّز (L4)
60:         return str_replace('{awb}', rawurlencode((string) $this->awb_number), $template);
61:     }
62: }
```

## File: bags-platform/backend/app/Models/WebhookLog.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Illuminate\Database\Eloquent\Model;
 8: 
 9: class WebhookLog extends Model
10: {
11:     protected $fillable = [
12:         'provider',
13:         'event_id',
14:         'payload',
15:         'signature_valid',
16:         'status',
17:         'error',
18:         'ip_address',
19:     ];
20: 
21:     protected function casts(): array
22:     {
23:         return [
24:             'payload' => 'array',
25:             'signature_valid' => 'boolean',
26:         ];
27:     }
28: 
29:     /**
30:      * تنقية الحمولة قبل التخزين (H1): بوابات مثل Moyasar تُتحقق من
31:      * secret_token داخل الجسم نفسه، وتخزين الجسم كاملًا يضع السر في قاعدة
32:      * البيانات فيكفي تسرّب نسخة احتياطية/قراءة لانتحال الأحداث. تُستبدل
33:      * المفاتيح الحسّاسة بقيمة ثابتة في كل الأعماق قبل الكتابة.
34:      *
35:      * @param  array<string, mixed>|null  $payload
36:      * @return array<string, mixed>|null
37:      */
38:     public static function sanitizePayload(?array $payload): ?array
39:     {
40:         if ($payload === null) {
41:             return null;
42:         }
43: 
44:         return self::scrub($payload);
45:     }
46: 
47:     private const SENSITIVE_KEYS = [
48:         'secret_token', 'secret', 'api_key', 'apikey', 'webhook_secret',
49:         'password', 'token', 'access_token', 'authorization',
50:     ];
51: 
52:     /**
53:      * @param  array<string, mixed>  $data
54:      * @return array<string, mixed>
55:      */
56:     private static function scrub(array $data): array
57:     {
58:         foreach ($data as $key => $value) {
59:             if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
60:                 $data[$key] = '[redacted]';
61:             } elseif (is_array($value)) {
62:                 $data[$key] = self::scrub($value);
63:             }
64:         }
65: 
66:         return $data;
67:     }
68: }
```

## File: bags-platform/docs/api-samples/checkout-shipping-options.json
```json
 1: {
 2:     "data": {
 3:         "city": {
 4:             "id": 7,
 5:             "name_ar": "Port Ardella",
 6:             "name_en": "Walkerhaven"
 7:         },
 8:         "cart_total": 400,
 9:         "payment_methods": [
10:             "cod"
11:         ],
12:         "preview": {
13:             "items_total": 400,
14:             "coupon_discount": 0,
15:             "shipping": 0,
16:             "cod_fee": 12,
17:             "tax": 56,
18:             "grand_total": 468
19:         },
20:         "options": [
21:             {
22:                 "key": "standard",
23:                 "label": "شحن قياسي",
24:                 "cost": 0,
25:                 "cod_fee": 12,
26:                 "currency": "EGP",
27:                 "estimated_days": "من 2 إلى 4 أيام عمل"
28:             }
29:         ]
30:     }
31: }
```

## File: bags-platform/docs/api-samples/cities.json
```json
 1: {
 2:     "data": [
 3:         {
 4:             "id": 7,
 5:             "name_ar": "Port Ardella",
 6:             "name_en": "Walkerhaven",
 7:             "shipping_cost": "30.00"
 8:         }
 9:     ]
10: }
```

## File: bags-platform/docs/api-samples/shipping-options.json
```json
 1: {
 2:     "data": {
 3:         "city": {
 4:             "id": 7,
 5:             "name_ar": "Port Ardella",
 6:             "name_en": "Walkerhaven"
 7:         },
 8:         "cart_total": 400,
 9:         "payment_methods": [
10:             "cod"
11:         ],
12:         "preview": {
13:             "items_total": 400,
14:             "coupon_discount": 0,
15:             "shipping": 0,
16:             "cod_fee": 12,
17:             "tax": 56,
18:             "grand_total": 468
19:         },
20:         "options": [
21:             {
22:                 "key": "standard",
23:                 "label": "شحن قياسي",
24:                 "cost": 0,
25:                 "cod_fee": 12,
26:                 "currency": "EGP",
27:                 "estimated_days": "من 2 إلى 4 أيام عمل"
28:             }
29:         ]
30:     }
31: }
```

## File: bags-platform/backend/app/Services/ShippingQuoteService.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Services;
 6: 
 7: use App\Models\City;
 8: use App\Models\ShippingRate;
 9: 
10: /**
11:  * Computes customer shipping quotes and COD surcharges.
12:  *
13:  * Resolves rates from active carriers matching the destination city zone.
14:  * Waives shipping cost when the merchandise total qualifies for free shipping,
15:  * and falls back to the default city flat rate when no specific carrier rate matches.
16:  */
17: class ShippingQuoteService
18: {
19:     /**
20:      * Calculate shipping rate and cash-on-delivery fees for an order destination.
21:      *
22:      * @param  int  $cityId
23:      * @param  float  $merchandiseTotal
24:      * @param  bool  $cod
25:      * @return array{cost: float, cod_fee: float, rate: ShippingRate|null}
26:      */
27:     public function quoteForCity(int $cityId, float $merchandiseTotal, bool $cod): array
28:     {
29:         $rate = ShippingRate::query()
30:             ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_rates.shipping_zone_id')
31:             ->join('shipping_zone_cities', 'shipping_zone_cities.shipping_zone_id', '=', 'shipping_zones.id')
32:             ->join('shipping_companies', 'shipping_companies.id', '=', 'shipping_rates.shipping_company_id')
33:             ->where('shipping_zone_cities.city_id', $cityId)
34:             ->where('shipping_zones.is_active', true)
35:             ->where('shipping_companies.is_active', true)
36:             ->orderBy('shipping_companies.id')
37:             ->orderBy('shipping_rates.id')
38:             ->select('shipping_rates.*')
39:             ->first();
40: 
41:         if ($rate === null) {
42:             $city = City::query()->find($cityId);
43: 
44:             return ['cost' => (float) ($city?->shipping_cost ?? 0), 'cod_fee' => 0.0, 'rate' => null];
45:         }
46: 
47:         $free = $rate->free_shipping_threshold !== null
48:             && $merchandiseTotal >= (float) $rate->free_shipping_threshold;
49: 
50:         return [
51:             'cost' => $free ? 0.0 : (float) $rate->base_cost,
52:             'cod_fee' => $cod ? (float) $rate->cod_fee : 0.0,
53:             'rate' => $rate,
54:         ];
55:     }
56: }
```

## File: bags-platform/backend/app/Services/StorePaymentPolicy.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Services;
 6: 
 7: use App\Enums\CustomerStatus;
 8: use App\Enums\CustomerType;
 9: use App\Models\Customer;
10: 
11: /**
12:  * Storefront payment method eligibility policy.
13:  *
14:  * Central source of truth for payment methods available at checkout:
15:  * - Wallet payment requires explicit config activation and an approved customer account.
16:  * - Credit (deferred) payment is restricted to approved wholesale or distributor accounts.
17:  * - Cash-on-delivery and gateway methods follow configured storefront settings.
18:  */
19: final class StorePaymentPolicy
20: {
21:     /**
22:      * Resolve eligible payment methods for the given customer.
23:      *
24:      * @param  Customer|null  $customer
25:      * @return array<int, string>
26:      */
27:     public function allowedFor(?Customer $customer): array
28:     {
29:         $configured = array_values(array_filter((array) config('store.payment_methods', ['cod'])));
30:         $walletEnabled = in_array('wallet', $configured, true);
31:         $methods = array_values(array_filter($configured, fn ($method) => ! in_array($method, ['wallet', 'credit'], true)));
32: 
33:         if ($walletEnabled && $customer !== null && $customer->status === CustomerStatus::Approved) {
34:             $methods[] = 'wallet';
35:         }
36:         if ($customer !== null
37:             && $customer->status === CustomerStatus::Approved
38:             && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)) {
39:             $methods[] = 'credit';
40:         }
41: 
42:         return array_values(array_unique($methods));
43:     }
44: }
```

## File: bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/api/mappers.ts
```typescript
  1: import type { AdminOrder, ReturnRequest, ShippingCompany, ShippingZone, ShippingRate, CustomerGroupApi, PriceListApi, PromotionApi, CouponApi } from '@alfarid/api-client';
  2: import type { OrderRow, ReturnRow, ShipCompany, ShipZone, ShipRate, CustomerGroup, PriceListRow, PromoRow, CouponRow } from '../data';
  3: 
  4: const TZ = 'Africa/Cairo';
  5: export const toDate = (iso?: string | null): string => {
  6:   if (!iso) return '';
  7:   const date = new Date(iso);
  8:   if (!Number.isFinite(date.getTime())) return '';
  9:   const parts = new Intl.DateTimeFormat('en', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
 10:   const value = (key: string) => parts.find(part => part.type === key)?.value ?? '';
 11:   return value('year') + '-' + value('month') + '-' + value('day');
 12: };
 13: export const toTime = (iso?: string | null): string => {
 14:   if (!iso) return '';
 15:   const date = new Date(iso);
 16:   if (!Number.isFinite(date.getTime())) return '';
 17:   return date.toLocaleTimeString('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
 18: };
 19: export const num = (v: string | number | null | undefined) => (v === null || v === undefined || v === '' ? 0 : Number(v));
 20: 
 21: export const mapErrors = (e: Record<string, string>, m: Record<string, string>) =>
 22:   Object.fromEntries(Object.entries(e).map(([k, v]) => [m[k] ?? k, v])) as Record<string, string>;
 23: 
 24: const CTYPE_GUEST = 'زائر';
 25: const CTYPE_WHOLESALE = 'جملة';
 26: const CTYPE_RETAIL = 'قطاعي';
 27: 
 28: /** normalize backend values to the keys the current UI maps expect */
 29: const normalizePay = (p?: string | null): string => {
 30:   if (!p) return '';
 31:   return p === 'bank_transfer' ? 'bank' : p;
 32: };
 33: const normalizeRefundMethod = (m?: string | null): string | null => {
 34:   if (!m) return null;
 35:   if (m === 'original_payment') return 'original';
 36:   if (m === 'bank_transfer') return 'bank';
 37:   return m;
 38: };
 39: 
 40: export function mapOrder(o: AdminOrder): OrderRow {
 41:   return {
 42:     id: o.id,
 43:     no: o.order_number,
 44:     customer: o.customer?.name ?? o.guest_name ?? '—',
 45:     guest: !o.customer,
 46:     ctype: !o.customer ? CTYPE_GUEST : o.customer.type === 'wholesale' ? CTYPE_WHOLESALE : CTYPE_RETAIL,
 47:     phone: o.customer?.phone ?? o.guest_phone ?? '',
 48:     channel: o.channel ?? 'web',
 49:     city: (o.city as any)?.name_ar ?? (o.city as any)?.name ?? '',
 50:     status: o.status,
 51:     pay: normalizePay(o.payment_method),
 52:     payStatus: o.payment_status,
 53:     branch: o.allocated_branch?.name ?? null,
 54:     total: num(o.grand_total),
 55:     date: toDate(o.created_at),
 56:     time: toTime(o.created_at),
 57:     followUp: !!o.needs_attention,
 58:     needRefund: !!o.requires_refund,
 59:   };
 60: }
 61: 
 62: export function mapReturn(r: ReturnRequest, branchName?: (id: number | null) => string | null): ReturnRow {
 63:   return {
 64:     id: r.id,
 65:     no: r.return_number,
 66:     order: r.order_number ?? `#${r.order_id}`,
 67:     customer: r.customer_name ?? '—',
 68:     branch: branchName ? branchName(r.branch_id) : r.branch_id ? `#${r.branch_id}` : null,
 69:     status: r.status,
 70:     method: normalizeRefundMethod(r.refund_method),
 71:     total: num(r.total_refund_amount),
 72:     date: toDate(r.created_at),
 73:   };
 74: }
 75: 
 76: export const branchOptions = (list: { id: number; name: string }[]) => list.map((b) => ({ value: String(b.id), label: b.name }));
 77: 
 78: export const mapShipCompany = (c: ShippingCompany): ShipCompany => ({
 79:   id: c.id, name: c.name, code: c.code, url: c.tracking_url_template ?? '', active: c.is_active, api: !!c.has_api_credentials,
 80: });
 81: export const mapShipZone = (z: ShippingZone): ShipZone & { cityIds: number[] } => ({
 82:   id: z.id, name: z.name, active: z.is_active,
 83:   cities: (z.cities ?? []).map((c) => c.name_ar ?? ''), cityIds: (z.cities ?? []).map((c) => c.id),
 84: });
 85: export const mapShipRate = (r: ShippingRate): ShipRate & { companyId: number } => ({
 86:   id: r.id, co: r.company_name ?? String(r.shipping_company_id), companyId: r.shipping_company_id,
 87:   zone: r.shipping_zone_id, base: num(r.base_cost),
 88:   free: r.free_shipping_threshold === null ? null : num(r.free_shipping_threshold),
 89:   cod: num(r.cod_fee ?? 0), min: r.estimated_days_min, max: r.estimated_days_max,
 90: });
 91: export const mapCustomerGroup = (g: CustomerGroupApi): CustomerGroup => ({
 92:   id: g.id, name: g.name, code: g.code, desc: g.description, disc: num(g.discount_percentage), active: g.is_active,
 93: });
 94: export const mapPriceList = (p: PriceListApi): PriceListRow & { raw: PriceListApi } => ({
 95:   id: p.id, name: p.name, type: p.type === 'customer_group' ? 'group' : p.type === 'customer_specific' ? 'customer' : p.type,
 96:   target: p.customer?.name ?? p.customer_group?.name ?? null,
 97:   branch: p.branch?.name ?? null,
 98:   from: p.starts_at?.slice(0, 10) ?? '', to: p.ends_at?.slice(0, 10) ?? null,
 99:   prio: p.priority, active: p.is_active, items: p.items_count ?? 0, raw: p,
100: });
101: const promoToUI = (t: string): string => t === 'percentage_discount' ? 'percent' : t === 'fixed_discount' ? 'amount' : t === 'buy_x_get_y' ? 'bxgy' : 'free_ship';
102: export const promoToBackend = (t: string): any => t === 'percent' ? 'percentage_discount' : t === 'amount' ? 'fixed_discount' : t === 'bxgy' ? 'buy_x_get_y' : 'free_shipping';
103: export const mapPromo = (p: PromotionApi): PromoRow => ({
104:   id: p.id, name: p.name, type: promoToUI(p.type) as PromoRow['type'], value: p.value === null ? null : num(p.value),
105:   min: p.min_order_amount === null ? null : num(p.min_order_amount),
106:   scope: p.applies_to as PromoRow['scope'], targets: [],
107:   from: p.starts_at?.slice(0, 10) ?? '', to: p.ends_at?.slice(0, 10) ?? null, active: p.is_active,
108: });
109: const couponToUI = (t: string): string => t === 'percentage' ? 'percent' : t === 'fixed' ? 'amount' : 'free_ship';
110: export const couponToBackend = (t: string): any => t === 'percent' ? 'percentage' : t === 'amount' ? 'fixed' : 'free_shipping';
111: export const mapCoupon = (c: CouponApi): CouponRow => ({
112:   id: c.id, code: c.code, type: couponToUI(c.type) as CouponRow['type'], value: num(c.value) || null,
113:   maxDisc: c.max_discount_amount === null ? null : num(c.max_discount_amount), min: num(c.min_order_amount),
114:   total: c.usage_limit, perCust: c.usage_limit_per_customer, used: c.used_count,
115:   from: c.starts_at?.slice(0, 10) ?? '', to: c.ends_at?.slice(0, 10) ?? null, active: c.is_active,
116: });
117: 
118: export async function optimisticToggle<T extends { id: number }>(
119:   list: T[], setList: (l: T[]) => void, id: number, patch: Partial<T>, call: () => Promise<unknown>,
120:   toast: (t: string, tone?: 'accent' | 'green' | 'red') => void,
121: ) {
122:   const prev = list;
123:   setList(list.map((x) => (x.id === id ? { ...x, ...patch } : x)));
124:   try { await call(); } catch (e) {
125:     const { parseApiError } = await import('@alfarid/api-client');
126:     setList(prev); toast(parseApiError(e).message, 'red');
127:   }
128: }
```

## File: bags-platform/frontend/apps/storefront/src/components/sophie/checkout/ShippingSection.tsx
```typescript
  1: 'use client';
  2: 
  3: import React from 'react';
  4: import type { CheckoutVM } from './useCheckout';
  5: 
  6: export function ShippingSection({ vm }: { vm: CheckoutVM }) {
  7:   const { form, set, blur, visibleErrors, cities, savedAddresses, applySavedAddress } = vm;
  8:   return (
  9:     <div className="checkout-block" id="checkout-section-shipping">
 10:       <div className="checkout-block-header">
 11:         <div>
 12:           <h2 className="checkout-section-heading">Shipping</h2>
 13:           <p className="checkout-section-subtitle">عنوان التوصيل بالتفصيل لضمان سرعة الوصول</p>
 14:         </div>
 15:       </div>
 16: 
 17:       <h3 className="checkout-subheading">Shipping Information</h3>
 18: 
 19:       {savedAddresses && savedAddresses.length > 0 && (
 20:         <div className="saved-addresses-bar" role="region" aria-label="العناوين المحفوظة">
 21:           <span className="saved-addresses-label">عناوينك المحفوظة:</span>
 22:           <div className="saved-addresses-pills">
 23:             {savedAddresses.map((addr) => {
 24:               const active =
 25:                 form.cityId === addr.city_id && form.street === addr.address_line;
 26:               return (
 27:                 <button
 28:                   key={addr.id}
 29:                   type="button"
 30:                   className={`saved-address-pill${active ? ' is-active' : ''}`}
 31:                   onClick={() => applySavedAddress(addr)}
 32:                 >
 33:                   <i className="fa-solid fa-location-dot" aria-hidden="true" />
 34:                   <span>{addr.label || addr.address_line}</span>
 35:                 </button>
 36:               );
 37:             })}
 38:           </div>
 39:         </div>
 40:       )}
 41: 
 42:       {vm.citiesError && (
 43:         <div className="checkout-inline-alert" role="alert">
 44:           <span>تعذّر تحميل المدن.</span>
 45:           <button type="button" className="bag-remove-btn" onClick={vm.loadCities}>
 46:             إعادة المحاولة
 47:           </button>
 48:         </div>
 49:       )}
 50: 
 51:       <div className="checkout-fields-grid">
 52:         <div className="line-input-wrap">
 53:           <label htmlFor="chk-city" className="checkout-field-label">
 54:             المدينة / المحافظة <span className="required-star" aria-hidden="true">*</span>
 55:           </label>
 56:           {cities === null ? (
 57:             <div className="mega-skel-link" aria-hidden="true" style={{ height: 48, borderRadius: 10 }} />
 58:           ) : (
 59:             <select
 60:               id="chk-city"
 61:               aria-label="المدينة"
 62:               aria-required="true"
 63:               aria-describedby={visibleErrors.cityId ? 'chk-city-error' : undefined}
 64:               className="line-input"
 65:               value={form.cityId ?? ''}
 66:               onChange={(e) =>
 67:                 set('cityId', e.target.value === '' ? null : Number(e.target.value))
 68:               }
 69:               onBlur={() => blur('cityId')}
 70:               aria-invalid={!!visibleErrors.cityId}
 71:               data-checkout-error={visibleErrors.cityId ? 'true' : undefined}
 72:             >
 73:               <option value="">اختار المدينة</option>
 74:               {cities.map((c) => (
 75:                 <option key={c.id} value={c.id}>
 76:                   {c.name_ar}
 77:                   {c.name_en ? ` — ${c.name_en}` : ''}
 78:                 </option>
 79:               ))}
 80:             </select>
 81:           )}
 82:           {visibleErrors.cityId && (
 83:             <p id="chk-city-error" className="luxury-field-error" role="alert">
 84:               {visibleErrors.cityId}
 85:             </p>
 86:           )}
 87:         </div>
 88: 
 89:         <div className="line-input-wrap">
 90:           <label htmlFor="chk-street" className="checkout-field-label">
 91:             الشارع والمنطقة <span className="required-star" aria-hidden="true">*</span>
 92:           </label>
 93:           <input
 94:             type="text"
 95:             id="chk-street"
 96:             aria-label="عنوان الشحن"
 97:             aria-required="true"
 98:             aria-describedby={visibleErrors.street ? 'chk-street-error' : undefined}
 99:             className="line-input"
100:             placeholder="الشارع، الحي، أو أقرب علامة مميزة"
101:             autoComplete="street-address"
102:             enterKeyHint="next"
103:             value={form.street}
104:             onChange={(e) => set('street', e.target.value)}
105:             onBlur={() => blur('street')}
106:             aria-invalid={!!visibleErrors.street}
107:             data-checkout-error={visibleErrors.street ? 'true' : undefined}
108:           />
109:           {visibleErrors.street && (
110:             <p id="chk-street-error" className="luxury-field-error" role="alert">
111:               {visibleErrors.street}
112:             </p>
113:           )}
114:         </div>
115: 
116:         {/* Compact 3-column sub-grid on mobile/tablet to minimize vertical scrolling */}
117:         <div className="checkout-address-subgrid checkout-field-full">
118:           <div className="line-input-wrap">
119:             <label htmlFor="chk-building" className="checkout-field-label">
120:               العمارة <span className="optional-tag">(اختياري)</span>
121:             </label>
122:             <input
123:               type="text"
124:               id="chk-building"
125:               aria-label="رقم العمارة"
126:               className="line-input"
127:               placeholder="رقم العمارة (اختياري)"
128:               inputMode="numeric"
129:               enterKeyHint="next"
130:               value={form.building}
131:               onChange={(e) => set('building', e.target.value)}
132:               onBlur={() => blur('building')}
133:             />
134:           </div>
135: 
136:           <div className="line-input-wrap">
137:             <label htmlFor="chk-floor" className="checkout-field-label">
138:               الدور <span className="optional-tag">(اختياري)</span>
139:             </label>
140:             <input
141:               type="text"
142:               id="chk-floor"
143:               aria-label="الدور"
144:               className="line-input"
145:               placeholder="الدور (اختياري)"
146:               inputMode="numeric"
147:               enterKeyHint="next"
148:               value={form.floor}
149:               onChange={(e) => set('floor', e.target.value)}
150:               onBlur={() => blur('floor')}
151:             />
152:           </div>
153: 
154:           <div className="line-input-wrap">
155:             <label htmlFor="chk-apartment" className="checkout-field-label">
156:               الشقة <span className="optional-tag">(اختياري)</span>
157:             </label>
158:             <input
159:               type="text"
160:               id="chk-apartment"
161:               aria-label="الشقة"
162:               className="line-input"
163:               placeholder="الشقة (اختياري)"
164:               inputMode="numeric"
165:               enterKeyHint="next"
166:               value={form.apartment}
167:               onChange={(e) => set('apartment', e.target.value)}
168:               onBlur={() => blur('apartment')}
169:             />
170:           </div>
171:         </div>
172: 
173:         <div className="line-input-wrap checkout-field-full">
174:           <label htmlFor="chk-notes" className="checkout-field-label">
175:             ملاحظات للمندوب <span className="optional-tag">(اختياري)</span>
176:           </label>
177:           <input
178:             type="text"
179:             id="chk-notes"
180:             aria-label="ملاحظات للشحن"
181:             className="line-input"
182:             placeholder="ملاحظات للشحن (اختياري) — مثل: بجوار صيدلية، اتصل قبل الوصول"
183:             enterKeyHint="done"
184:             value={form.notes}
185:             onChange={(e) => set('notes', e.target.value)}
186:             onBlur={() => blur('notes')}
187:           />
188:         </div>
189:       </div>
190:     </div>
191:   );
192: }
```

## File: bags-platform/frontend/apps/storefront/src/components/sophie/checkout/useCheckout.ts
```typescript
  1: 'use client';
  2: 
  3: import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
  4: import { useRouter, useSearchParams } from 'next/navigation';
  5: import {
  6:   accountEndpoints,
  7:   checkoutEndpoints,
  8:   type CheckoutPaymentMethod,
  9:   type ShippingOptionsResponse,
 10:   type StoreCity,
 11:   type CheckoutPayload,
 12: } from '@alfarid/api-client';
 13: import { getCartSession, useCartStore } from '@/stores/cart-store';
 14: import { useAuthStore } from '@/store/auth.store';
 15: import { useAuthHydrated } from '@/hooks/use-auth-hydrated';
 16: import { viewerKey } from '@/lib/viewer-key';
 17: 
 18: export interface SavedAddressItem {
 19:   id: number;
 20:   city_id: number;
 21:   address_line: string;
 22:   is_default?: boolean;
 23:   label?: string;
 24: }
 25: 
 26: export interface CheckoutForm {
 27:   fullName: string;
 28:   phone: string;
 29:   email: string;
 30:   cityId: number | null;
 31:   street: string;
 32:   building: string;
 33:   floor: string;
 34:   apartment: string;
 35:   notes: string;
 36:   paymentMethod: CheckoutPaymentMethod;
 37:   agreeTerms: boolean;
 38: }
 39: 
 40: const initialForm: CheckoutForm = {
 41:   fullName: '',
 42:   phone: '',
 43:   email: '',
 44:   cityId: null,
 45:   street: '',
 46:   building: '',
 47:   floor: '',
 48:   apartment: '',
 49:   notes: '',
 50:   paymentMethod: 'cod',
 51:   agreeTerms: false,
 52: };
 53: 
 54: /** Server field -> form field, for 422 mapping. */
 55: const fieldMap: Record<string, keyof CheckoutForm | 'banner'> = {
 56:   recipient_name: 'fullName',
 57:   recipient_phone: 'phone',
 58:   recipient_email: 'email',
 59:   city_id: 'cityId',
 60:   shipping_address_line: 'street',
 61:   shipping_notes: 'notes',
 62:   payment_method: 'paymentMethod',
 63:   expected_total: 'banner',
 64: };
 65: 
 66: export function buildAddressLine(
 67:   form: Pick<CheckoutForm, 'street' | 'building' | 'floor' | 'apartment'>,
 68: ) {
 69:   return [
 70:     form.street.trim(),
 71:     form.building.trim() && `Bldg ${form.building.trim()}`,
 72:     form.floor.trim() && `Floor ${form.floor.trim()}`,
 73:     form.apartment.trim() && `Apt ${form.apartment.trim()}`,
 74:   ]
 75:     .filter(Boolean)
 76:     .join(', ');
 77: }
 78: 
 79: export function useCheckout() {
 80:   const router = useRouter();
 81:   const params = useSearchParams();
 82:   const [form, setForm] = useState<CheckoutForm>(initialForm);
 83:   const [touched, setTouched] = useState<Partial<Record<keyof CheckoutForm, boolean>>>({});
 84:   const [fieldErrors, setFieldErrors] = useState<Partial<Record<keyof CheckoutForm, string>>>({});
 85:   const [banner, setBanner] = useState<{
 86:     kind: 'error' | 'info';
 87:     text: string;
 88:     action?: { label: string; onClick: () => void };
 89:   } | null>(null);
 90:   const [cities, setCities] = useState<StoreCity[] | null>(null);
 91:   const [savedAddresses, setSavedAddresses] = useState<SavedAddressItem[]>([]);
 92:   const [quote, setQuote] = useState<ShippingOptionsResponse | null>(null);
 93:   const [quoteLoading, setQuoteLoading] = useState(false);
 94:   const [citiesError, setCitiesError] = useState(false);
 95:   const [quoteError, setQuoteError] = useState(false);
 96:   const [quoteRevision, refreshQuote] = useState(0);
 97:   const [quotedFor, setQuotedFor] = useState('');
 98:   const [uncertain, setUncertain] = useState(false);
 99:   const submittingRef = useRef(false);
100:   const pending = useRef<{ body: CheckoutPayload; session: string | null } | null>(null);
101:   const [promoInput, setPromoInput] = useState('');
102:   const [submitting, setSubmitting] = useState(false);
103:   const keyRef = useRef<string>(
104:     typeof crypto !== 'undefined' && 'randomUUID' in crypto
105:       ? crypto.randomUUID()
106:       : `${Date.now()}-${Math.random()}`,
107:   );
108:   const promoTried = useRef(false);
109: 
110:   const items = useCartStore((s) => s.items);
111:   const cartLoading = useCartStore((s) => s.loading);
112:   const cartBusy = useCartStore((s) => s.busy);
113:   const cartError = useCartStore((s) => s.error);
114:   const totals = useCartStore((s) => s.totals);
115:   const couponCode = useCartStore((s) => s.couponCode);
116:   const loadCart = useCartStore((s) => s.loadCart);
117:   const updateQuantity = useCartStore((s) => s.updateQuantity);
118:   const removeItem = useCartStore((s) => s.removeItem);
119:   const applyCoupon = useCartStore((s) => s.applyCoupon);
120:   const removeCoupon = useCartStore((s) => s.removeCoupon);
121:   const user = useAuthStore((s) => s.user);
122:   const token = useAuthStore((s) => s.token);
123:   const hydrated = useAuthHydrated();
124:   const owner = viewerKey(token);
125:   const attemptOwner = token
126:     ? `${useAuthStore.getState().actorType}:${String(user?.id ?? 'unknown')}`
127:     : 'guest';
128:   const quoteKey = JSON.stringify([
129:     owner,
130:     form.cityId,
131:     form.paymentMethod,
132:     couponCode,
133:     items,
134:     quoteRevision,
135:   ]);
136:   const liveQuote = quotedFor === quoteKey && !cartBusy && !cartLoading ? quote : null;
137: 
138:   // A reload after a lost response must recover the same attempt, never create a second order.
139:   useEffect(() => {
140:     if (!hydrated) return;
141:     pending.current = null;
142:     setUncertain(false);
143:     try {
144:       const saved = JSON.parse(sessionStorage.getItem('pending_checkout') ?? 'null');
145:       if (
146:         saved?.owner === attemptOwner &&
147:         typeof saved.key === 'string' &&
148:         saved.body?.city_id &&
149:         saved.body?.recipient_phone &&
150:         Number.isFinite(saved.body.expected_total)
151:       ) {
152:         keyRef.current = saved.key;
153:         pending.current = { body: saved.body, session: saved.session ?? null };
154:         setUncertain(true);
155:         setBanner({
156:           kind: 'info',
157:           text: 'في محاولة طلب سابقة لم يتم تأكيدها. اضغط إعادة المحاولة لتأكيد نفس الطلب.',
158:         });
159:         setForm((f) => ({
160:           ...f,
161:           fullName: saved.body.recipient_name,
162:           phone: saved.body.recipient_phone,
163:           email: saved.body.recipient_email ?? '',
164:           cityId: saved.body.city_id,
165:           street: saved.body.shipping_address_line,
166:           notes: saved.body.shipping_notes ?? '',
167:           paymentMethod: saved.body.payment_method,
168:           agreeTerms: true,
169:         }));
170:       }
171:     } catch {
172:       /* Storage is optional; the in-memory attempt remains safe. */
173:     }
174:   }, [hydrated, attemptOwner]);
175: 
176:   const loadCities = useCallback(() => {
177:     setCitiesError(false);
178:     setCities(null);
179:     checkoutEndpoints
180:       .cities()
181:       .then(setCities)
182:       .catch(() => {
183:         setCities([]);
184:         setCitiesError(true);
185:       });
186:   }, []);
187: 
188:   const set = useCallback(<K extends keyof CheckoutForm>(key: K, value: CheckoutForm[K]) => {
189:     setForm((f) => ({ ...f, [key]: value }));
190:     setFieldErrors((e) => ({ ...e, [key]: undefined }));
191:   }, []);
192: 
193:   const blur = useCallback((key: keyof CheckoutForm) => {
194:     setTouched((t) => ({ ...t, [key]: true }));
195:   }, []);
196: 
197:   const applySavedAddress = useCallback((addr: SavedAddressItem) => {
198:     setForm((f) => ({
199:       ...f,
200:       cityId: addr.city_id,
201:       street: addr.address_line,
202:     }));
203:     setFieldErrors((e) => ({ ...e, cityId: undefined, street: undefined }));
204:   }, []);
205: 
206:   // Prefill contact fields for signed-in shoppers.
207:   useEffect(() => {
208:     if (!user) return;
209:     setForm((f) => ({
210:       ...f,
211:       fullName: f.fullName || (user.name as string) || '',
212:       email: f.email || (user.email as string) || '',
213:       phone: f.phone || (user.phone as string) || '',
214:     }));
215:   }, [user]);
216: 
217:   // Default saved address (first default) for signed-in shoppers, plus list for one-tap switching.
218:   useEffect(() => {
219:     if (!token) {
220:       setSavedAddresses([]);
221:       return;
222:     }
223:     let cancelled = false;
224:     accountEndpoints
225:       .addresses()
226:       .then((list) => {
227:         if (cancelled || list.length === 0) return;
228:         setSavedAddresses(list as SavedAddressItem[]);
229:         const d = list.find((a) => a.is_default) ?? list[0];
230:         setForm((f) =>
231:           f.street || f.cityId ? f : { ...f, cityId: d.city_id, street: d.address_line },
232:         );
233:       })
234:       .catch(() => {});
235:     return () => {
236:       cancelled = true;
237:     };
238:   }, [token]);
239: 
240:   // Initial load: cart + cities in parallel.
241:   useEffect(() => {
242:     if (!hydrated) return;
243:     loadCart();
244:     loadCities();
245:   }, [hydrated, owner, loadCart, loadCities]);
246: 
247:   const addressLine = useMemo(() => buildAddressLine(form), [form]);
248: 
249:   // Live quote: city / payment method / coupon / cart changes (debounced 300ms).
250:   useEffect(() => {
251:     if (!hydrated || form.cityId === null || cartBusy || cartLoading) {
252:       setQuote(null);
253:       setQuoteLoading(false);
254:       return;
255:     }
256:     let current = true;
257:     const controller = new AbortController();
258:     setQuote(null);
259:     setQuoteError(false);
260:     setQuoteLoading(true);
261:     const t = setTimeout(() => {
262:       checkoutEndpoints
263:         .shippingOptions(
264:           { city_id: form.cityId as number, payment_method: form.paymentMethod },
265:           getCartSession(),
266:           controller.signal,
267:         )
268:         .then((res) => {
269:           if (!current) return;
270:           const allowed = res.payment_methods.filter((m) =>
271:             ['cod', 'wallet', 'credit'].includes(m),
272:           );
273:           if (!allowed.includes(form.paymentMethod) && allowed.length) {
274:             setForm((f) => ({ ...f, paymentMethod: allowed[0] as CheckoutPaymentMethod }));
275:             return;
276:           }
277:           setQuote(res);
278:           setQuotedFor(quoteKey);
279:         })
280:         .catch(() => {
281:           if (current) setQuoteError(true);
282:         })
283:         .finally(() => {
284:           if (current) setQuoteLoading(false);
285:         });
286:     }, 300);
287:     return () => {
288:       current = false;
289:       clearTimeout(t);
290:       controller.abort();
291:     };
292:   }, [quoteKey, hydrated, cartBusy, cartLoading, form.cityId, form.paymentMethod]);
293: 
294:   // ?promo=CODE auto-applies once.
295:   useEffect(() => {
296:     if (promoTried.current || !hydrated || cartLoading || !totals) return;
297:     const code = params.get('promo');
298:     if (!code) return;
299:     promoTried.current = true;
300:     setPromoInput(code);
301:     applyCoupon(code).then((ok) => {
302:       if (!ok) {
303:         setBanner({
304:           kind: 'error',
305:           text: useCartStore.getState().error || 'كود الخصم غير صالح أو منتهي.',
306:         });
307:       }
308:     });
309:   }, [params, hydrated, cartLoading, totals, applyCoupon]);
310: 
311:   const errors = useMemo(() => {
312:     const e: Partial<Record<keyof CheckoutForm, string>> = {};
313:     if (!form.fullName.trim()) e.fullName = 'الاسم مطلوب';
314:     if (!form.phone.trim()) e.phone = 'رقم الموبايل مطلوب';
315:     else if (!/^[0-9+\s().-]{7,}$/.test(form.phone.trim())) e.phone = 'رقم موبايل غير صالح';
316:     if (form.email.trim() && !/^\S+@\S+\.\S+$/.test(form.email.trim())) {
317:       e.email = 'بريد إلكتروني غير صالح';
318:     }
319:     if (form.cityId === null) e.cityId = 'اختار المدينة';
320:     if (!form.street.trim()) e.street = 'العنوان مطلوب';
321:     if (!form.agreeTerms) e.agreeTerms = 'لازم توافق على الشروط عشان تكمل الطلب';
322:     return e;
323:   }, [form]);
324: 
325:   const visibleErrors = useMemo(() => {
326:     const out: typeof errors = {};
327:     (Object.keys({ ...errors, ...fieldErrors }) as (keyof CheckoutForm)[]).forEach((k) => {
328:       if (touched[k] || fieldErrors[k]) out[k] = fieldErrors[k] ?? errors[k];
329:     });
330:     return out;
331:   }, [errors, touched, fieldErrors]);
332: 
333:   const validate = useCallback(() => {
334:     setTouched({
335:       fullName: true,
336:       phone: true,
337:       email: true,
338:       cityId: true,
339:       street: true,
340:       agreeTerms: true,
341:     });
342:     if (Object.keys(errors).length > 0) {
343:       requestAnimationFrame(() =>
344:         document.querySelector<HTMLElement>('[data-checkout-error="true"]')?.focus(),
345:       );
346:       return false;
347:     }
348:     return true;
349:   }, [errors]);
350: 
351:   const handleError = useCallback(
352:     (err: unknown) => {
353:       const status = (
354:         err as {
355:           response?: {
356:             status?: number;
357:             headers?: Record<string, string>;
358:             data?: { message?: string; errors?: Record<string, string[]>; code?: string };
359:           };
360:         }
361:       )?.response;
362:       const code = status?.data?.code;
363:       if (code === 'price_changed') {
364:         loadCart();
365:         setQuote(null);
366:         refreshQuote((v) => v + 1);
367:         setBanner({
368:           kind: 'error',
369:           text: 'الأسعار اتحدثت، راجع الإجمالي واضغط إتمام الطلب تاني.',
370:         });
371:         return;
372:       }
373:       if (code === 'coupon_invalid') {
374:         setBanner({
375:           kind: 'error',
376:           text: status?.data?.message || 'الكوبون مبقاش صالح.',
377:           action: {
378:             label: 'إزالة الكوبون',
379:             onClick: () => {
380:               removeCoupon();
381:               setBanner(null);
382:             },
383:           },
384:         });
385:         return;
386:       }
387:       if (status?.status === 429) {
388:         const retry = status.headers?.['retry-after'] ?? 60;
389:         setBanner({ kind: 'error', text: `محاولات كتيرة، حاول بعد ${retry} ثانية.` });
390:         return;
391:       }
392:       if (status?.status === 422) {
393:         const apiErrors = status.data?.errors ?? {};
394:         const mapped: Partial<Record<keyof CheckoutForm, string>> = {};
395:         Object.entries(apiErrors).forEach(([key, messages]) => {
396:           const target = fieldMap[key];
397:           if (target && target !== 'banner' && messages?.[0]) mapped[target] = messages[0];
398:         });
399:         if (Object.keys(mapped).length > 0) {
400:           setFieldErrors(mapped);
401:           setTouched((t) => ({
402:             ...t,
403:             ...Object.fromEntries(Object.keys(mapped).map((k) => [k, true])),
404:           }));
405:           setTimeout(
406:             () =>
407:               document
408:                 .querySelector('[data-checkout-error="true"]')
409:                 ?.scrollIntoView({ behavior: 'smooth', block: 'center' }),
410:             50,
411:           );
412:           return;
413:         }
414:       }
415:       setBanner({ kind: 'error', text: status?.data?.message || 'حصل خطأ، حاول تاني.' });
416:     },
417:     [loadCart, removeCoupon],
418:   );
419: 
420:   const submit = useCallback(async () => {
421:     if (submittingRef.current) return;
422:     if (
423:       !pending.current &&
424:       (!validate() || !liveQuote?.preview || cartBusy || cartLoading || items.length === 0)
425:     ) {
426:       return;
427:     }
428:     if (!pending.current && items.some((i) => !i.priced || i.quantity > i.available)) {
429:       setBanner({
430:         kind: 'error',
431:         text: 'في منتجات مش متاحة بالكمية دي، شيلها أو قلل الكمية الأول.',
432:       });
433:       return;
434:     }
435:     if (
436:       !pending.current &&
437:       (form.cityId === null || !liveQuote?.payment_methods.includes(form.paymentMethod))
438:     ) {
439:       return;
440:     }
441:     submittingRef.current = true;
442:     setSubmitting(true);
443:     try {
444:       pending.current ??= {
445:         body: {
446:           recipient_name: form.fullName.trim(),
447:           recipient_phone: form.phone.trim(),
448:           recipient_email: form.email.trim() || undefined,
449:           city_id: form.cityId!,
450:           shipping_address_line: addressLine,
451:           shipping_notes: form.notes.trim() || undefined,
452:           payment_method: form.paymentMethod,
453:           expected_total: liveQuote!.preview!.grand_total,
454:         },
455:         session: getCartSession(),
456:       };
457:       try {
458:         sessionStorage.setItem(
459:           'pending_checkout',
460:           JSON.stringify({ owner: attemptOwner, key: keyRef.current, ...pending.current }),
461:         );
462:       } catch {
463:         /* In-memory retry still uses the same key. */
464:       }
465:       const res = await checkoutEndpoints.place(
466:         pending.current.body,
467:         pending.current.session,
468:         keyRef.current,
469:       );
470:       try {
471:         sessionStorage.setItem('last_order', JSON.stringify(res.data));
472:       } catch {
473:         /* Receipt storage must never turn a successful order into a retry. */
474:       }
475:       pending.current = null;
476:       try {
477:         sessionStorage.removeItem('pending_checkout');
478:       } catch {
479:         /* Optional storage. */
480:       }
481:       setUncertain(false);
482:       await loadCart();
483:       router.replace(`/checkout/success?order=${encodeURIComponent(res.data.order_number)}`);
484:     } catch (e) {
485:       handleError(e);
486:       const status = (e as { response?: { status: number } }).response?.status;
487:       // Network errors and server failures may have committed the order. Retry the exact request and key.
488:       if (
489:         status &&
490:         status >= 400 &&
491:         status < 500 &&
492:         status !== 408 &&
493:         status !== 409 &&
494:         status !== 429
495:       ) {
496:         pending.current = null;
497:         try {
498:           sessionStorage.removeItem('pending_checkout');
499:         } catch {
500:           /* Optional storage. */
501:         }
502:         setUncertain(false);
503:         keyRef.current = crypto.randomUUID();
504:       } else {
505:         setUncertain(true);
506:         const retryAfter = (e as { response?: { headers?: Record<string, string> } }).response
507:           ?.headers?.['retry-after'];
508:         setBanner({
509:           kind: 'error',
510:           text:
511:             'تعذّر تأكيد نتيجة الطلب. اضغط إعادة المحاولة لتأكيد نفس الطلب بدون تكراره.' +
512:             (status === 429 ? ` محاولات كتيرة، حاول بعد ${retryAfter ?? 60} ثانية.` : ''),
513:         });
514:       }
515:     } finally {
516:       submittingRef.current = false;
517:       setSubmitting(false);
518:     }
519:   }, [
520:     validate,
521:     items,
522:     form,
523:     addressLine,
524:     liveQuote,
525:     cartBusy,
526:     cartLoading,
527:     loadCart,
528:     router,
529:     handleError,
530:     attemptOwner,
531:   ]);
532: 
533:   const step =
534:     !form.fullName.trim() || !form.phone.trim()
535:       ? 1
536:       : form.cityId === null || !form.street.trim()
537:         ? 2
538:         : 3;
539: 
540:   return {
541:     form,
542:     set,
543:     blur,
544:     visibleErrors,
545:     fieldErrors,
546:     banner,
547:     setBanner,
548:     cities,
549:     citiesError,
550:     loadCities,
551:     savedAddresses,
552:     applySavedAddress,
553:     quote: liveQuote,
554:     quoteLoading,
555:     quoteError,
556:     retryQuote: () => refreshQuote((v) => v + 1),
557:     locked: submitting || uncertain,
558:     canSubmit:
559:       !submitting &&
560:       (uncertain ||
561:         (!!liveQuote?.preview &&
562:           !!liveQuote.options.length &&
563:           liveQuote.payment_methods.includes(form.paymentMethod) &&
564:           !cartBusy &&
565:           !cartLoading &&
566:           items.length > 0)),
567:     uncertain,
568:     promoInput,
569:     setPromoInput,
570:     items,
571:     cartLoading,
572:     cartBusy,
573:     cartError,
574:     totals,
575:     couponCode,
576:     updateQuantity,
577:     removeItem,
578:     applyCoupon,
579:     removeCoupon,
580:     loadCart,
581:     submit,
582:     submitting,
583:     step,
584:     addressLine,
585:     user,
586:     token,
587:   };
588: }
589: 
590: export type CheckoutVM = ReturnType<typeof useCheckout>;
```

## File: bags-platform/frontend/packages/api-client/src/endpoints/operations/commerce.ts
```typescript
  1: import { api, ADMIN, asPage, crud, downloadFile, cleanParams, type Id } from './http';
  2: 
  3: export interface ShippingCompany {
  4:   id: number;
  5:   name: string;
  6:   code: string;
  7:   tracking_url_template: string | null;
  8:   is_active: boolean;
  9:   has_api_credentials?: boolean;
 10: }
 11: export interface ShippingCompanyPayload {
 12:   name: string;
 13:   code: string;
 14:   tracking_url_template?: string | null;
 15:   is_active: boolean;
 16: }
 17: export const shippingCompaniesApi = { ...crud<ShippingCompany, ShippingCompanyPayload, { is_active?: boolean; per_page?: number }>(`${ADMIN}/shipping-companies`), all: (signal?: AbortSignal) => allRecords<ShippingCompany>(`${ADMIN}/shipping-companies`, {}, signal) };
 18: 
 19: export interface ShippingZone {
 20:   id: number;
 21:   name: string;
 22:   is_active: boolean;
 23:   cities?: { id: number; name_ar: string; name_en?: string | null }[];
 24: }
 25: export interface ShippingZonePayload { name: string; is_active: boolean; city_ids: number[] }
 26: export const shippingZonesApi = { ...crud<ShippingZone, ShippingZonePayload>(`${ADMIN}/shipping-zones`), all: (signal?: AbortSignal) => allRecords<ShippingZone>(`${ADMIN}/shipping-zones`, {}, signal) };
 27: 
 28: export interface ShippingRate {
 29:   id: number;
 30:   shipping_company_id: number;
 31:   shipping_zone_id: number;
 32:   base_cost: string;
 33:   free_shipping_threshold: string | null;
 34:   cod_fee: string | null;
 35:   estimated_days_min: number;
 36:   estimated_days_max: number;
 37:   company_name?: string | null;
 38:   zone_name?: string | null;
 39: }
 40: export interface ShippingRatePayload {
 41:   shipping_company_id: number;
 42:   shipping_zone_id: number;
 43:   base_cost: number;
 44:   free_shipping_threshold: number | null;
 45:   cod_fee: number | null;
 46:   estimated_days_min: number;
 47:   estimated_days_max: number;
 48: }
 49: export const shippingRatesApi = { ...crud<ShippingRate, ShippingRatePayload>(`${ADMIN}/shipping-rates`), all: (signal?: AbortSignal) => allRecords<ShippingRate>(`${ADMIN}/shipping-rates`, {}, signal) };
 50: 
 51: /* ---------- Customer groups ---------- */
 52: export interface CustomerGroupApi {
 53:   id: number; name: string; code: string; description: string | null;
 54:   discount_percentage: string; is_active: boolean; customers_count?: number;
 55: }
 56: export interface CustomerGroupPayload { name: string; code: string; description?: string | null; discount_percentage: number; is_active: boolean }
 57: export const customerGroupsApi = { ...crud<CustomerGroupApi, CustomerGroupPayload, { search?: string; is_active?: boolean; per_page?: number }>(`${ADMIN}/customer-groups`), all: (signal?: AbortSignal) => allRecords<CustomerGroupApi>(`${ADMIN}/customer-groups`, {}, signal) };
 58: 
 59: /* ---------- Price lists ---------- */
 60: export type PriceListType = 'retail' | 'wholesale' | 'distributor' | 'customer_group' | 'customer_specific';
 61: export interface PriceListApi {
 62:   id: number; name: string; type: PriceListType;
 63:   customer?: { id: number; name: string } | null;
 64:   customer_group?: { id: number; name: string; code: string } | null;
 65:   branch?: { id: number; name: string } | null;
 66:   currency: string; starts_at: string | null; ends_at: string | null;
 67:   priority: number; is_active: boolean; items_count?: number; created_at: string;
 68: }
 69: export interface PriceListPayload {
 70:   name: string; type: PriceListType;
 71:   customer_id?: number | null; customer_group_id?: number | null; branch_id?: number | null;
 72:   currency: 'EGP'; starts_at?: string | null; ends_at?: string | null;
 73:   priority: number; is_active: boolean;
 74: }
 75: export interface PriceListParams {
 76:   page?: number; per_page?: number; type?: PriceListType; customer_id?: number;
 77:   customer_group_id?: number; branch_id?: number; is_active?: boolean; search?: string;
 78: }
 79: export interface PriceListImportReport {
 80:   created: number; updated: number; skipped: number; errors: { line: number; message: string }[];
 81:   diff?: { line: number; variant_id: number; min_qty: string; old_price: string | null; new_price: string }[];
 82: }
 83: const PL = `${ADMIN}/price-lists`;
 84: export const priceListsApi = {
 85:   ...crud<PriceListApi, PriceListPayload, PriceListParams>(PL),
 86:   import: (id: Id, file: File, dryRun = false) => {
 87:     const fd = new FormData(); fd.append('file', file);
 88:     return api.post<{ message: string; data: PriceListImportReport }>(`${PL}/${id}/import`, fd, {
 89:       params: dryRun ? { dry_run: 1 } : undefined,
 90:       headers: { 'Content-Type': 'multipart/form-data' },
 91:     }).then((r) => r.data);
 92:   },
 93:   exportCsv: (id: Id, name: string) => downloadFile(`${PL}/${id}/export`, `${name}.csv`),
 94:   applyPercentage: (id: Id, percent: number) =>
 95:     api.post<{ data: { updated: number } }>(`${PL}/${id}/apply-percentage`, { percent }).then((response) => response.data.data),
 96: };
 97: 
 98: export interface PriceListItemApi {
 99:   id: number; price_list_id: number; product_variant_id: number;
100:   variant?: { id: number; sku: string; product_id?: number; product_name_ar?: string | null };
101:   min_qty: number; price: string; starts_at: string | null; ends_at: string | null;
102: }
103: export interface PriceListItemPayload { product_variant_id: number; min_qty: number; price: number; starts_at?: string | null; ends_at?: string | null }
104: export const priceListItemsApi = (priceListId: Id) => ({
105:   ...crud<PriceListItemApi, PriceListItemPayload, { page?: number; per_page?: number; product_variant_id?: number }>(`${PL}/${priceListId}/items`),
106:   all: (signal?: AbortSignal) => allRecords<PriceListItemApi>(`${PL}/${priceListId}/items`, {}, signal),
107: });
108: 
109: /* ---------- Promotions & Coupons (backend truth) ---------- */
110: export type PromotionType = 'percentage_discount' | 'fixed_discount' | 'buy_x_get_y' | 'free_shipping';
111: export type PromotionScope = 'all' | 'categories' | 'brands' | 'variants';
112: export interface PromotionApi {
113:   id: number; name: string; type: PromotionType; value: string | null;
114:   min_order_amount: string | null; applies_to: PromotionScope;
115:   target_ids?: number[] | null;
116:   starts_at: string | null; ends_at: string | null; is_active: boolean;
117: }
118: export interface PromotionPayload {
119:   name: string; type: PromotionType; value?: number; min_order_amount?: number | null;
120:   applies_to?: PromotionScope; target_ids?: number[]; starts_at?: string | null; ends_at?: string | null; is_active?: boolean;
121: }
122: export const promotionsApi = { ...crud<PromotionApi, PromotionPayload>(`${ADMIN}/promotions`), all: (signal?: AbortSignal) => allRecords<PromotionApi>(`${ADMIN}/promotions`, {}, signal) };
123: 
124: export type CouponType = 'percentage' | 'fixed' | 'free_shipping';
125: export interface CouponApi {
126:   id: number; code: string; type: CouponType; value: string;
127:   max_discount_amount: string | null; min_order_amount: string;
128:   usage_limit: number | null; usage_limit_per_customer: number | null; used_count: number;
129:   starts_at: string | null; ends_at: string | null; is_active: boolean;
130: }
131: export interface CouponPayload {
132:   code: string; type: CouponType; value?: number; max_discount_amount?: number | null; min_order_amount: number;
133:   usage_limit?: number | null; usage_limit_per_customer?: number; starts_at?: string | null; ends_at?: string | null; is_active?: boolean;
134: }
135: export const couponsApi = { ...crud<CouponApi, CouponPayload>(`${ADMIN}/coupons`), all: (signal?: AbortSignal) => allRecords<CouponApi>(`${ADMIN}/coupons`, {}, signal) };
136: 
137: /** Lookup controls must never invent IDs from the demonstration data. */
138: export interface CommerceOption { id: number; label: string }
139: async function allRecords<T>(path: string, params: object = {}, signal?: AbortSignal): Promise<T[]> {
140:   const rows: T[] = [];
141:   let page = 1, lastPage = 1;
142:   do {
143:     const response = await api.get(path, { params: cleanParams({ ...params, per_page: 100, page }), signal });
144:     const result = asPage<T>(response);
145:     rows.push(...result.data);
146:     lastPage = result.meta.last_page;
147:     page++;
148:   } while (page <= lastPage);
149:   return rows;
150: }
151: export const commerceLookupsApi = {
152:   customers: (signal?: AbortSignal) => allRecords<{ id: number; name: string }>(ADMIN + '/customers', { type: 'wholesale' }, signal),
153:   categories: (signal?: AbortSignal) => allRecords<{ id: number; name_ar: string }>(ADMIN + '/categories', {}, signal)
154:     .then(rows => rows.map(row => ({ id: row.id, label: row.name_ar }))),
155:   brands: (signal?: AbortSignal) => allRecords<{ id: number; name_ar: string }>(ADMIN + '/brands', {}, signal)
156:     .then(rows => rows.map(row => ({ id: row.id, label: row.name_ar }))),
157:   variants: async (signal?: AbortSignal): Promise<CommerceOption[]> => {
158:     const products = await allRecords<{ id: number; name_ar: string }>(ADMIN + '/products', {}, signal);
159:     const result: CommerceOption[] = [];
160:     for (const product of products) {
161:       const response = await api.get(ADMIN + '/products/' + product.id + '/variants', { signal });
162:       const variants = asPage<{ id: number; sku: string }>(response).data;
163:       result.push(...variants.map(variant => ({ id: variant.id, label: product.name_ar + ' · ' + variant.sku })));
164:     }
165:     return result;
166:   },
167: };
168: export type { Id };
```

## File: bags-platform/backend/routes/api/webhooks.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use App\Http\Controllers\Api\V1\Webhook\PaymentWebhookController;
 6: use App\Http\Controllers\Api\V1\Webhook\ShippingWebhookController;
 7: use App\Http\Controllers\Api\V1\Webhook\WhatsAppInboundController;
 8: use Illuminate\Support\Facades\Route;
 9: 
10: // روابط خارجية (بوابات دفع وشركات شحن): لا مصادقة Sanctum —
11: // التحقق من التوقيع الرقمي HMAC داخل كل متحكم، وكل طلب يُسجل في webhook_logs.
12: Route::middleware('throttle:webhooks')->group(function (): void {
13:     // قيود طول/نمط على المعاملات: اسم بوابة/شركة طويل كان يتجاوز عمود
14:     // provider في سجل الويب هوك فيحوّل الطلب لـ 500 بدل 404 نظيف (F-07)
15:     Route::post('payment/{gateway}', [PaymentWebhookController::class, 'handle'])
16:         ->where('gateway', '[a-z0-9_-]{1,40}')->name('payment');
17:     Route::post('shipping/{company}', [ShippingWebhookController::class, 'handle'])
18:         ->where('company', '[A-Za-z0-9_-]{1,40}')->name('shipping');
19:     Route::post('whatsapp/inbound', [WhatsAppInboundController::class, 'handle'])->name('whatsapp.inbound');
20: });
```

## File: bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/commerce.tsx
```typescript
   1: import React, { useEffect, useMemo, useState } from 'react';
   2: import {
   3:   SHIP_COMPANIES,
   4:   SHIP_ZONES,
   5:   SHIP_RATES,
   6:   CUSTOMER_GROUPS,
   7:   PRICE_LISTS,
   8:   PROMOS,
   9:   COUPONS,
  10:   BRANDS,
  11:   BRANCHES,
  12:   CITIES,
  13:   WHOLESALE,
  14:   VARIANTS,
  15:   ShipCompany,
  16:   ShipZone,
  17:   ShipRate,
  18:   PriceListRow,
  19:   CustomerGroup,
  20:   PromoRow,
  21:   CouponRow,
  22:   BrandRow,
  23: } from './data';
  24: import { shippingCompaniesApi, shippingZonesApi, shippingRatesApi, customerGroupsApi, priceListsApi, priceListItemsApi, promotionsApi, couponsApi, brandsApi, commerceLookupsApi, parseApiError, type PriceListImportReport, type Paginated, type PriceListApi, type ShippingCompanyPayload, type ShippingZonePayload, type ShippingRatePayload, type PriceListType } from '@alfarid/api-client';
  25: import { useRemote, useDebounced, useAction } from './api/useRemote';
  26: import { useFormState } from './api/useFormState';
  27: import { mapShipCompany, mapShipZone, mapShipRate, mapCustomerGroup, mapPriceList, mapPromo, mapCoupon, mapErrors, promoToBackend, couponToBackend } from './api/mappers';
  28: import { checkoutEndpoints, branchesApi } from '@alfarid/api-client';
  29: import { RemoteFeedback } from './api/RemoteFeedback';
  30: import {
  31:   Icon,
  32:   useToast,
  33:   Badge,
  34:   ActiveDot,
  35:   PageHead,
  36:   Card,
  37:   SearchPill,
  38:   FSelect,
  39:   TabsBar,
  40:   DataTable,
  41:   Pager,
  42:   RowActions,
  43:   Modal,
  44:   ConfirmModal,
  45:   Field,
  46:   TextArea,
  47:   ToggleRow,
  48:   Note,
  49:   Dropzone,
  50:   Num,
  51:   fmtNum,
  52:   fmtMoney,
  53:   fmtInt,
  54:   HatchBar,
  55:   Seg,
  56: } from './ui';
  57: 
  58: export const PL_TYPES: Record<string, string> = {
  59:   retail: 'تجزئة عامة',
  60:   wholesale: 'جملة',
  61:   distributor: 'موزعون',
  62:   group: 'مجموعة عملاء',
  63:   customer: 'عميل مخصص',
  64: };
  65: 
  66: export const PROMO_TYPES: Record<string, string> = {
  67:   percent: 'خصم نسبة',
  68:   amount: 'خصم مبلغ',
  69:   free_ship: 'شحن مجاني',
  70: };
  71: 
  72: export const SCOPES: Record<string, string> = {
  73:   all: 'الكل',
  74:   categories: 'أقسام',
  75:   brands: 'ماركات',
  76:   variants: 'متغيرات منتجات',
  77: };
  78: 
  79: export const COUPON_TYPES: Record<string, string> = {
  80:   percent: 'خصم نسبة',
  81:   amount: 'خصم مبلغ',
  82:   free_ship: 'شحن مجاني',
  83: };
  84: 
  85: /* ---------------- SHIPPING ---------------- */
  86: export function ShippingPage() {
  87:   const [tab, setTab] = useState<'companies' | 'zones' | 'rates'>('companies');
  88:   const [edit, setEditState] = useState<{ kind: 'companies' | 'zones' | 'rates'; row: any } | null>(null);
  89:   const toast = useToast();
  90:   const companies = useRemote((s) => shippingCompaniesApi.all(s).then((rows) => rows.map(mapShipCompany)), [], { mock: () => SHIP_COMPANIES });
  91:   const zones = useRemote((s) => shippingZonesApi.all(s).then((rows) => rows.map(mapShipZone)), [], { mock: () => SHIP_ZONES as any });
  92:   const rates = useRemote((s) => shippingRatesApi.all(s).then((rows) => rows.map(mapShipRate)), [], { mock: () => SHIP_RATES as any });
  93:   const C = companies.data ?? [], Z = zones.data ?? [], RT = rates.data ?? [];
  94:   const setEdit = (next: typeof edit) => {
  95:     if (next && (companies.usingMock || zones.usingMock || rates.usingMock)) return toast('البيانات تجريبية — لا يمكن الحفظ', 'red');
  96:     setEditState(next);
  97:   };
  98: 
  99:   return (
 100:     <div className="space-y-5">
 101:       <PageHead
 102:         title="الشحن"
 103:         sub="شركات الشحن، ومناطق التغطية، وأسعار كل شركة لكل منطقة."
 104:         actions={
 105:           <button className="btn btn-dark" onClick={() => setEdit({ kind: tab, row: null })}>
 106:             <Icon name="plus" size={14} />
 107:             {{ companies: 'شركة شحن', zones: 'منطقة شحن', rates: 'سعر شحن' }[tab]}
 108:           </button>
 109:         }
 110:       />
 111:       <RemoteFeedback state={tab === 'companies' ? companies : tab === 'zones' ? zones : rates} />
 112:       <TabsBar
 113:         value={tab}
 114:         onChange={(v: any) => setTab(v)}
 115:         tabs={[
 116:           { value: 'companies', label: 'شركات الشحن', icon: 'truck', count: C.length },
 117:           { value: 'zones', label: 'مناطق الشحن', icon: 'globe', count: Z.length },
 118:           { value: 'rates', label: 'أسعار الشحن', icon: 'tag', count: RT.length },
 119:         ]}
 120:       />
 121:       {tab === 'companies' && (
 122:         <Card stg={3}>
 123:           <DataTable
 124:             rows={C}
 125:             onRow={(r: ShipCompany) => setEdit({ kind: 'companies', row: r })}
 126:             cols={[
 127:               {
 128:                 h: 'الشركة',
 129:                 r: (c: ShipCompany) => (
 130:                   <div className="flex items-center gap-2.5">
 131:                     <span className="w-9 h-9 rounded-[11px] v-sub flex items-center justify-center tx2">
 132:                       <Icon name="truck" size={15} />
 133:                     </span>
 134:                     <span className="font-semibold tx1">{c.name}</span>
 135:                   </div>
 136:                 ),
 137:               },
 138:               {
 139:                 h: 'الكود',
 140:                 r: (c: ShipCompany) => (
 141:                   <span className="num text-[11.5px] font-semibold v-sub px-2 py-1 rounded-md tx2">{c.code}</span>
 142:                 ),
 143:               },
 144:               {
 145:                 h: 'قالب رابط التتبع',
 146:                 r: (c: ShipCompany) =>
 147:                   c.url ? (
 148:                     <span className="num text-[11.5px] tx2 ltr clamp1 max-w-[340px]" dir="ltr">
 149:                       {c.url.split('{awb}')[0]}
 150:                       <b className="accent">{'{awb}'}</b>
 151:                       {c.url.split('{awb}')[1]}
 152:                     </span>
 153:                   ) : (
 154:                     <span className="tx3 text-[11.5px]">بدون تتبع</span>
 155:                   ),
 156:               },
 157:               {
 158:                 h: 'تكامل API',
 159:                 r: (c: ShipCompany) => (c.api ? <Badge tone="blue">متصل</Badge> : <span className="tx3 text-[11.5px]">—</span>),
 160:               },
 161:               { h: 'التفعيل', r: (c: ShipCompany) => <ActiveDot on={c.active} /> },
 162:               {
 163:                 h: '',
 164:                 r: (c: ShipCompany) => (
 165:                   <RowActions>
 166:                     <button className="icon-btn !w-7 !h-7" onClick={() => setEdit({ kind: 'companies', row: c })}>
 167:                       <Icon name="edit" size={13} />
 168:                     </button>
 169:                   </RowActions>
 170:                 ),
 171:               },
 172:             ]}
 173:           />
 174:         </Card>
 175:       )}
 176:       {tab === 'zones' && (
 177:         <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
 178:           {(Z as any[]).map((z: ShipZone) => (
 179:             <div
 180:               key={z.id}
 181:               onClick={() => setEdit({ kind: 'zones', row: z })}
 182:               className="v-card p-5 cursor-pointer hover:border-[var(--border-strong)] transition space-y-3"
 183:             >
 184:               <div className="flex items-start justify-between">
 185:                 <div>
 186:                   <div className="font-semibold tx1 text-[14px]">{z.name}</div>
 187:                   <div className="text-[11.5px] tx3 mt-0.5">
 188:                     <Num>{z.cities.length}</Num> مدن مشمولة
 189:                   </div>
 190:                 </div>
 191:                 <ActiveDot on={z.active} />
 192:               </div>
 193:               <div className="flex flex-wrap gap-1.5">
 194:                 {z.cities.map((c) => (
 195:                   <span key={c} className="v-sub px-2 py-0.5 rounded-md text-[11.5px] tx2">
 196:                     {c}
 197:                   </span>
 198:                 ))}
 199:               </div>
 200:             </div>
 201:           ))}
 202:         </div>
 203:       )}
 204:       {tab === 'rates' && (
 205:         <Card stg={3}>
 206:           <DataTable
 207:             rows={RT}
 208:             onRow={(r: ShipRate) => setEdit({ kind: 'rates', row: r })}
 209:             cols={[
 210:               {
 211:                 h: 'الشركة',
 212:                 r: (r: ShipRate) => (
 213:                   <span className="font-semibold tx1">
 214:                     {(C as ShipCompany[]).find((c) => c.code === (r as any).co || c.id === (r as any).companyId)?.name || (r as any).co}
 215:                   </span>
 216:                 ),
 217:               },
 218:               {
 219:                 h: 'المنطقة',
 220:                 r: (r: ShipRate) => (
 221:                   <span className="tx2">{(Z as ShipZone[]).find((z) => z.id === r.zone)?.name}</span>
 222:                 ),
 223:               },
 224:               { h: 'السعر الأساسي', r: (r: ShipRate) => <span className="font-semibold">{fmtMoney(r.base)}</span> },
 225:               {
 226:                 h: 'حد الشحن المجاني',
 227:                 r: (r: ShipRate) =>
 228:                   r.free === 0 ? (
 229:                     <Badge tone="green">مجاني دائمًا</Badge>
 230:                   ) : r.free ? (
 231:                     <span className="tx2 font-medium">{fmtMoney(r.free)}</span>
 232:                   ) : (
 233:                     <span className="tx3 text-[11.5px]">لا يوجد</span>
 234:                   ),
 235:               },
 236:               {
 237:                 h: 'رسوم COD',
 238:                 r: (r: ShipRate) => (r.cod ? fmtMoney(r.cod) : <span className="tx3 text-[11.5px]">بدون رسوم</span>),
 239:               },
 240:               {
 241:                 h: 'مدة التوصيل',
 242:                 r: (r: ShipRate) => (
 243:                   <span className="num tx2">
 244:                     {r.min}–{r.max} يوم
 245:                   </span>
 246:                 ),
 247:               },
 248:               {
 249:                 h: '',
 250:                 r: (r: ShipRate) => (
 251:                   <RowActions>
 252:                     <button className="icon-btn !w-7 !h-7" onClick={() => setEdit({ kind: 'rates', row: r })}>
 253:                       <Icon name="edit" size={13} />
 254:                     </button>
 255:                   </RowActions>
 256:                 ),
 257:               },
 258:             ]}
 259:           />
 260:         </Card>
 261:       )}
 262:       {edit?.kind === 'companies' && <CompanyModal row={edit.row} onClose={() => setEdit(null)} onSaved={companies.reload} />}
 263:       {edit?.kind === 'zones' && <ZoneModal row={edit.row} onClose={() => setEdit(null)} onSaved={zones.reload} />}
 264:       {edit?.kind === 'rates' && <RateModal row={edit.row} companies={C} zones={Z} onClose={() => setEdit(null)} onSaved={rates.reload} />}
 265:     </div>
 266:   );
 267: }
 268: 
 269: export function CompanyModal({ row, onClose, onSaved }: { row: ShipCompany | null; onClose: () => void; onSaved: () => void }) {
 270:   const toast = useToast();
 271:   const { busy, run } = useAction();
 272:   const f = useFormState({ name: row?.name ?? '', code: row?.code ?? '', url: row?.url ?? '', active: row?.active ?? true, api: row?.api ?? false });
 273:   const urlErr = f.v.url && (!f.v.url.startsWith('https://') || !f.v.url.includes('{awb}')) ? 'يجب أن يبدأ القالب بـ https:// ويحتوي على متغير {awb}' : null;
 274:   const save = async () => {
 275:     const body: ShippingCompanyPayload = { name: f.v.name, code: f.v.code, tracking_url_template: f.v.url || null, is_active: f.v.active };
 276:     const r = await run(() => (row ? shippingCompaniesApi.update(row.id, body) : shippingCompaniesApi.create(body)),
 277:       { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { name: 'name', code: 'code', tracking_url_template: 'url' })) });
 278:     if (r) { onSaved(); onClose(); }
 279:   };
 280:   return (
 281:     <Modal
 282:       open
 283:       onClose={onClose}
 284:       title={row ? 'تعديل شركة شحن' : 'شركة شحن جديدة'}
 285:       icon="truck"
 286:       footer={
 287:         <>
 288:           <button className="btn btn-soft" onClick={onClose}>
 289:             إلغاء
 290:           </button>
 291:           <button
 292:             className="btn btn-dark"
 293:             disabled={!!urlErr || busy}
 294:             onClick={save}
 295:           >
 296:             حفظ
 297:           </button>
 298:         </>
 299:       }
 300:     >
 301:       <div className="space-y-4">
 302:         <div className="grid grid-cols-2 gap-3">
 303:           <Field label="اسم الشركة" req err={f.errors.name}>
 304:             <input className="inp" value={f.v.name} onChange={f.on('name')} />
 305:           </Field>
 306:           <Field label="الكود" req hint="فريد بالإنجليزية" err={f.errors.code}>
 307:             <input className="inp num" dir="ltr" value={f.v.code} onChange={f.on('code')} />
 308:           </Field>
 309:         </div>
 310:         <Field label="قالب رابط التتبع" err={urlErr ?? f.errors.url} hint="استخدم {awb} مكان رقم البوليصة">
 311:           <input
 312:             className={'inp num ' + (urlErr ? 'err' : '')}
 313:             dir="ltr"
 314:             value={f.v.url}
 315:             onChange={f.on('url')}
 316:             placeholder="https://tracker.com/track/{awb}"
 317:           />
 318:         </Field>
 319:         <ToggleRow label="مفعّلة" on={f.v.active} onChange={(v) => f.set('active', v)} />
 320:         <ToggleRow label="بيانات تكامل API موجودة" sub="مؤشر للقراءة؛ تُدار بيانات الاعتماد من إعدادات التكامل" on={f.v.api} onChange={() => toast('تُدار بيانات الاعتماد من إعدادات التكامل', 'accent')} />
 321:       </div>
 322:     </Modal>
 323:   );
 324: }
 325: 
 326: export function ZoneModal({ row, onClose, onSaved }: { row: (ShipZone & { cityIds?: number[] }) | null; onClose: () => void; onSaved: () => void }) {
 327:   const toast = useToast();
 328:   const { busy, run } = useAction();
 329:   const f = useFormState({ name: row?.name ?? '', active: row?.active ?? true });
 330:   const cities = useRemote(() => checkoutEndpoints.cities(), []);
 331:   const [cityIds, setCityIds] = useState<number[]>((row as any)?.cityIds ?? []);
 332:   const tg = (id: number) => setCityIds(cityIds.includes(id) ? cityIds.filter((x) => x !== id) : [...cityIds, id]);
 333:   const save = async () => {
 334:     if (!f.v.name.trim()) return toast('اسم المنطقة مطلوب', 'red');
 335:     const body: ShippingZonePayload = { name: f.v.name, is_active: f.v.active, city_ids: cityIds };
 336:     const r = await run(() => (row ? shippingZonesApi.update(row.id, body) : shippingZonesApi.create(body)),
 337:       { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { name: 'name', city_ids: 'cities' })) });
 338:     if (r) { onSaved(); onClose(); }
 339:   };
 340:   return (
 341:     <Modal
 342:       open
 343:       onClose={onClose}
 344:       title={row ? 'تعديل منطقة شحن' : 'منطقة شحن جديدة'}
 345:       icon="globe"
 346:       width={600}
 347:       footer={
 348:         <>
 349:           <button className="btn btn-soft" onClick={onClose}>
 350:             إلغاء
 351:           </button>
 352:           <button
 353:             className="btn btn-dark"
 354:             disabled={busy}
 355:             onClick={save}
 356:           >
 357:             حفظ
 358:           </button>
 359:         </>
 360:       }
 361:     >
 362:       <div className="space-y-4">
 363:         <RemoteFeedback state={cities} />
 364:         <Field label="اسم المنطقة" req err={f.errors.name}>
 365:           <input className="inp" value={f.v.name} onChange={f.on('name')} />
 366:         </Field>
 367:         <div>
 368:           <div className="flex items-center justify-between mb-2">
 369:             <span className="lbl">المدن المشمولة</span>
 370:             <span className="text-[11px] tx3">
 371:               <Num>{cityIds.length}</Num> مختارة
 372:             </span>
 373:           </div>
 374:           <div className="flex flex-wrap gap-1.5 p-3 rounded-[14px] v-sub max-h-[160px] overflow-y-auto">
 375:             {(cities.data ?? []).map((c: { id: number; name_ar: string }) => (
 376:               <button
 377:                 key={c.id}
 378:                 type="button"
 379:                 onClick={() => tg(c.id)}
 380:                 className={'pill-inp text-[12px] ' + (cityIds.includes(c.id) ? 'on' : '')}
 381:               >
 382:                 {c.name_ar}
 383:               </button>
 384:             ))}
 385:           </div>
 386:         </div>
 387:         <ToggleRow label="مفعّلة" on={f.v.active} onChange={(v) => f.set('active', v)} />
 388:       </div>
 389:     </Modal>
 390:   );
 391: }
 392: 
 393: export function RateModal({ row, companies, zones, onClose, onSaved }: { row: (ShipRate & { companyId?: number }) | null; companies: ShipCompany[]; zones: ShipZone[]; onClose: () => void; onSaved: () => void }) {
 394:   const toast = useToast();
 395:   const { busy, run } = useAction();
 396:   const f = useFormState({
 397:     companyId: String((row as any)?.companyId ?? ''),
 398:     zone: String(row?.zone ?? ''),
 399:     base: row?.base ?? '',
 400:     free: (row?.free ?? '') as any,
 401:     cod: (row?.cod ?? 0) as any,
 402:     min: row?.min ?? 1,
 403:     max: row?.max ?? 3,
 404:   });
 405:   const min = Number(f.v.min), max = Number(f.v.max);
 406:   const err = min > max ? 'الحد الأدنى لا يمكن أن يتجاوز الحد الأقصى' : null;
 407:   const save = async () => {
 408:     if (!f.v.companyId || !f.v.zone) return toast('اختر الشركة والمنطقة', 'red');
 409:     if (err) return;
 410:     const body: ShippingRatePayload = {
 411:       shipping_company_id: Number(f.v.companyId), shipping_zone_id: Number(f.v.zone),
 412:       base_cost: Number(f.v.base), free_shipping_threshold: f.v.free === '' ? null : Number(f.v.free),
 413:       cod_fee: f.v.cod === '' ? null : Number(f.v.cod), estimated_days_min: Number(f.v.min), estimated_days_max: Number(f.v.max),
 414:     };
 415:     const r = await run(() => (row ? shippingRatesApi.update(row.id, body) : shippingRatesApi.create(body)),
 416:       { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { shipping_company_id: 'companyId', shipping_zone_id: 'zone', base_cost: 'base', free_shipping_threshold: 'free', cod_fee: 'cod', estimated_days_min: 'min', estimated_days_max: 'max' })) });
 417:     if (r) { onSaved(); onClose(); }
 418:   };
 419:   return (
 420:     <Modal
 421:       open
 422:       onClose={onClose}
 423:       title={row ? 'تعديل سعر شحن' : 'سعر شحن جديد'}
 424:       icon="tag"
 425:       footer={
 426:         <>
 427:           <button className="btn btn-soft" onClick={onClose}>
 428:             إلغاء
 429:           </button>
 430:           <button
 431:             className="btn btn-dark"
 432:             disabled={!!err || busy}
 433:             onClick={save}
 434:           >
 435:             حفظ
 436:           </button>
 437:         </>
 438:       }
 439:     >
 440:       <div className="space-y-4">
 441:         <div className="grid grid-cols-2 gap-3">
 442:           <Field label="شركة الشحن" req err={f.errors.companyId}>
 443:             <select className="inp" value={f.v.companyId} onChange={f.on('companyId' as any)}>
 444:               <option value="">اختر الشركة</option>
 445:               {companies.map((c) => (
 446:                 <option key={c.id} value={String(c.id)}>
 447:                   {c.name}
 448:                 </option>
 449:               ))}
 450:             </select>
 451:           </Field>
 452:           <Field label="المنطقة" req err={f.errors.zone}>
 453:             <select className="inp" value={f.v.zone} onChange={f.on('zone' as any)}>
 454:               <option value="">اختر المنطقة</option>
 455:               {zones.map((z) => (
 456:                 <option key={z.id} value={String(z.id)}>
 457:                   {z.name}
 458:                 </option>
 459:               ))}
 460:             </select>
 461:           </Field>
 462:         </div>
 463:         <div className="grid grid-cols-3 gap-3">
 464:           <Field label="السعر الأساسي" req err={f.errors.base}>
 465:             <input type="number" step="0.01" className="inp num" value={f.v.base} onChange={f.on('base' as any)} />
 466:           </Field>
 467:           <Field label="حد الشحن المجاني" hint="0 = مجاني دائمًا، فارغ = لا يوجد" err={f.errors.free}>
 468:             <input type="number" step="0.01" className="inp num" value={f.v.free} onChange={f.on('free' as any)} placeholder="لا يوجد" />
 469:           </Field>
 470:           <Field label="رسوم الدفع عند الاستلام" err={f.errors.cod}>
 471:             <input type="number" step="0.01" className="inp num" value={f.v.cod} onChange={f.on('cod' as any)} />
 472:           </Field>
 473:         </div>
 474:         <div className="grid grid-cols-2 gap-3">
 475:           <Field label="أقل مدة تسليم (أيام)" err={f.errors.min}>
 476:             <input
 477:               type="number"
 478:               min="0"
 479:               className="inp num"
 480:               value={f.v.min}
 481:               onChange={f.on('min' as any)}
 482:             />
 483:           </Field>
 484:           <Field label="أقصى مدة تسليم (أيام)" err={err ?? f.errors.max}>
 485:             <input
 486:               type="number"
 487:               min="0"
 488:               className={'inp num ' + (err ? 'err' : '')}
 489:               value={f.v.max}
 490:               onChange={f.on('max' as any)}
 491:             />
 492:           </Field>
 493:         </div>
 494:       </div>
 495:     </Modal>
 496:   );
 497: }
 498: 
 499: /* ---------------- PRICING ---------------- */
 500: export function PricingPage({ go }: { go: (r: { m: string; id?: any; sub?: any }) => void }) {
 501:   const toast = useToast();
 502:   const { busy, run } = useAction();
 503:   const [tab, setTab] = useState<'lists' | 'groups'>('lists');
 504:   const [q, setQ] = useState('');
 505:   const [ty, setTy] = useState('');
 506:   const [cus, setCus] = useState('');
 507:   const [grp, setGrp] = useState('');
 508:   const [br, setBr] = useState('');
 509:   const [act, setAct] = useState('');
 510:   const [form, setForm] = useState<PriceListRow | { id?: any } | null>(null);
 511:   const [gform, setGform] = useState<CustomerGroup | { id?: any; name?: string; code?: string; desc?: string; disc?: number; active?: boolean } | null>(null);
 512:   const [page, setPage] = useState(1);
 513:   const dq = useDebounced(q);
 514:   useEffect(() => { setPage(1); }, [dq, ty, cus, grp, br, act]);
 515: 
 516:   const typeToBackend = (t: string): PriceListType | undefined => {
 517:     if (!t) return undefined;
 518:     if (t === 'group') return 'customer_group';
 519:     if (t === 'customer') return 'customer_specific';
 520:     return t as PriceListType;
 521:   };
 522:   const lists = useRemote<Paginated<PriceListApi>>((s) => priceListsApi.list({
 523:     page, per_page: 20, search: dq || undefined, type: typeToBackend(ty),
 524:     customer_id: cus ? Number(cus) : undefined, customer_group_id: grp ? Number(grp) : undefined,
 525:     branch_id: br ? Number(br) : undefined, is_active: act === '' ? undefined : act === 'true',
 526:   }, s), [page, dq, ty, cus, grp, br, act], { mock: () => ({ data: [], meta: { current_page: 1, last_page: 1, total: PRICE_LISTS.length, per_page: 20, from: 1, to: PRICE_LISTS.length } }), onError: (e) => toast(e.message, 'red') });
 527:   const rows = lists.usingMock ? PRICE_LISTS : (lists.data?.data ?? []).map((p: any) => mapPriceList(p));
 528: 
 529:   const groups = useRemote((s) => customerGroupsApi.all(s).then((rows) => rows.map(mapCustomerGroup)), [], { mock: () => CUSTOMER_GROUPS });
 530:   const branchesList = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
 531:   const customers = useRemote((s) => commerceLookupsApi.customers(s), []);
 532: 
 533:   return (
 534:     <div className="space-y-5">
 535:       <PageHead
 536:         title="التسعير ومجموعات العملاء"
 537:         sub="قوائم أسعار التجزئة والجملة، وخصومات مجموعات العملاء، واستيراد الأسعار."
 538:         actions={
 539:           <>
 540:             <button className="btn btn-soft" onClick={() => toast('افتح قائمة الأسعار ثم استورد', 'accent')}>
 541:               <Icon name="upload" size={14} />
 542:               استيراد أسعار
 543:             </button>
 544:             <button className="btn btn-dark" onClick={() => (lists.usingMock || groups.usingMock) ? toast('البيانات تجريبية — لا يمكن الحفظ', 'red') : (tab === 'lists' ? setForm({}) : setGform({}))}>
 545:               <Icon name="plus" size={14} />
 546:               {tab === 'lists' ? 'قائمة أسعار' : 'مجموعة عملاء'}
 547:             </button>
 548:           </>
 549:         }
 550:       />
 551:       <RemoteFeedback state={tab === 'lists' ? lists : groups} />
 552:       <TabsBar
 553:         value={tab}
 554:         onChange={(v: any) => setTab(v)}
 555:         tabs={[
 556:           { value: 'lists', label: 'قوائم الأسعار', icon: 'tag', count: lists.data?.meta.total ?? 0 },
 557:           { value: 'groups', label: 'مجموعات العملاء', icon: 'users', count: groups.data?.length ?? 0 },
 558:         ]}
 559:       />
 560:       {tab === 'lists' && (
 561:         <Card stg={3}>
 562:           <div className="flex flex-wrap items-center gap-2 mb-4">
 563:             <SearchPill value={q} onChange={setQ} placeholder="اسم القائمة" />
 564:             <FSelect
 565:               value={ty}
 566:               onChange={setTy}
 567:               placeholder="النوع"
 568:               options={Object.entries(PL_TYPES).map(([v, l]) => ({ value: v, label: l }))}
 569:             />
 570:             <FSelect value={cus} onChange={setCus} placeholder="العميل" options={(customers.data ?? []).map((c: { id: number; name: string }) => ({ value: String(c.id), label: c.name }))} />
 571:             <FSelect
 572:               value={grp}
 573:               onChange={setGrp}
 574:               placeholder="المجموعة"
 575:               options={(groups.data ?? []).map((g: CustomerGroup) => ({ value: String(g.id), label: g.name }))}
 576:             />
 577:             <FSelect value={br} onChange={setBr} placeholder="الفرع" options={(branchesList.data ?? []).map((b: { id: number; name: string }) => ({ value: String(b.id), label: b.name }))} />
 578:             <FSelect
 579:               value={act}
 580:               onChange={setAct}
 581:               placeholder="التفعيل"
 582:               options={[
 583:                 { value: 'true', label: 'مفعّلة' },
 584:                 { value: 'false', label: 'معطّلة' },
 585:               ]}
 586:             />
 587:           </div>
 588:           <DataTable
 589:             rows={lists.loading ? [] : rows}
 590:             empty={lists.loading ? 'جارٍ التحميل…' : undefined}
 591:             onRow={(p: PriceListRow) => lists.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : go({ m: 'pricing', id: p.id })}
 592:             cols={[
 593:               { h: 'الاسم', r: (p: PriceListRow) => <span className="font-semibold tx1">{p.name}</span> },
 594:               {
 595:                 h: 'النوع',
 596:                 r: (p: PriceListRow) => (
 597:                   <Badge
 598:                     dot={false}
 599:                     tone={
 600:                       ({
 601:                         retail: 'gray',
 602:                         wholesale: 'violet',
 603:                         distributor: 'blue',
 604:                         group: 'orange',
 605:                         customer: 'green',
 606:                       } as const)[p.type as 'retail'] || 'gray'
 607:                     }
 608:                   >
 609:                     {PL_TYPES[p.type] || p.type}
 610:                   </Badge>
 611:                 ),
 612:               },
 613:               {
 614:                 h: 'العميل / المجموعة',
 615:                 r: (p: PriceListRow) => (p.target ? <span className="tx1 text-[12px]">{p.target}</span> : <span className="tx3">—</span>),
 616:               },
 617:               {
 618:                 h: 'الفرع',
 619:                 r: (p: PriceListRow) => (p.branch ? <span className="tx2 text-[12px]">{p.branch}</span> : <span className="tx3 text-[11.5px]">كل الفروع</span>),
 620:               },
 621:               { h: 'العملة', r: () => <span className="num text-[11.5px] font-semibold tx2">EGP</span> },
 622:               {
 623:                 h: 'السريان',
 624:                 r: (p: PriceListRow) => (
 625:                   <span className="num text-[11.5px] tx2 ltr">
 626:                     {p.from} → {p.to || '∞'}
 627:                   </span>
 628:                 ),
 629:               },
 630:               {
 631:                 h: 'الأولوية',
 632:                 align: 'center',
 633:                 r: (p: PriceListRow) => <Num className="font-semibold">{p.prio}</Num>,
 634:               },
 635:               {
 636:                 h: 'البنود',
 637:                 align: 'center',
 638:                 r: (p: PriceListRow) => <Num className="tx2">{fmtInt(p.items)}</Num>,
 639:               },
 640:               { h: 'التفعيل', r: (p: PriceListRow) => <ActiveDot on={p.active} /> },
 641:             ]}
 642:             foot={<Pager total={lists.data?.meta.total ?? rows.length} per={20} page={page} onPage={setPage} />}
 643:           />
 644:         </Card>
 645:       )}
 646:       {tab === 'groups' && (
 647:         <Card stg={3}>
 648:           <DataTable
 649:             rows={groups.data ?? []}
 650:             onRow={(g: CustomerGroup) => groups.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setGform(g)}
 651:             cols={[
 652:               {
 653:                 h: 'المجموعة',
 654:                 r: (g: CustomerGroup) => (
 655:                   <div className="leading-tight">
 656:                     <div className="font-semibold tx1">{g.name}</div>
 657:                     {g.desc && <div className="text-[11.5px] tx3 mt-0.5">{g.desc}</div>}
 658:                   </div>
 659:                 ),
 660:               },
 661:               {
 662:                 h: 'الكود',
 663:                 r: (g: CustomerGroup) => (
 664:                   <span className="num text-[11.5px] font-semibold v-sub px-2 py-1 rounded-md tx2">{g.code}</span>
 665:                 ),
 666:               },
 667:               {
 668:                 h: 'نسبة الخصم',
 669:                 r: (g: CustomerGroup) => (
 670:                   <div className="flex items-center gap-2.5 min-w-[140px]">
 671:                     <div className="flex-1">
 672:                       <HatchBar pct={g.disc * 4} h={10} />
 673:                     </div>
 674:                     <Num className="font-semibold">{g.disc}%</Num>
 675:                   </div>
 676:                 ),
 677:               },
 678:               { h: 'التفعيل', r: (g: CustomerGroup) => <ActiveDot on={g.active} /> },
 679:               {
 680:                 h: '',
 681:                 r: (g: CustomerGroup) => (
 682:                   <RowActions>
 683:                     <button className="icon-btn !w-7 !h-7" onClick={() => groups.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setGform(g)}>
 684:                       <Icon name="edit" size={13} />
 685:                     </button>
 686:                   </RowActions>
 687:                 ),
 688:               },
 689:             ]}
 690:           />
 691:         </Card>
 692:       )}
 693:       {form && <PriceListForm row={form.id ? (form as PriceListRow) : null} onClose={() => setForm(null)} onSaved={() => lists.reload()} />}
 694:       {gform && (
 695:         <GroupForm row={(gform as CustomerGroup).id ? (gform as CustomerGroup) : null} onClose={() => setGform(null)} onSaved={() => groups.reload()} />
 696:       )}
 697:     </div>
 698:   );
 699: }
 700: 
 701: export function PriceImportModal({ open, priceListId, onClose, onSaved }: { open: boolean; priceListId: number; onClose: () => void; onSaved: () => void }) {
 702:   const toast = useToast();
 703:   const { busy, run } = useAction();
 704:   const [file, setFile] = useState<File | null>(null);
 705:   const [report, setReport] = useState<PriceListImportReport | null>(null);
 706:   if (!open) return null;
 707:   const submit = async (dryRun: boolean) => {
 708:     if (!file) return toast('اختر ملف CSV', 'red');
 709:     if (file.size > 10 * 1024 * 1024) return toast('حجم الملف يتجاوز 10MB', 'red');
 710:     const r = await run(() => priceListsApi.import(priceListId, file, dryRun), { toast, success: dryRun ? undefined : 'تم الاستيراد' });
 711:     if (r) {
 712:       setReport(r.data);
 713:       if (!dryRun) {
 714:         setFile(null);
 715:         onSaved();
 716:       }
 717:     }
 718:   };
 719:   return (
 720:     <Modal open onClose={onClose} title="استيراد قوائم الأسعار" icon="upload" width={540}
 721:       footer={<>
 722:         <button className="btn btn-soft" onClick={onClose}>إغلاق</button>
 723:         <button className="btn btn-dark" disabled={busy || !file || report?.diff?.length === 0} onClick={() => void submit(!report?.diff)}>
 724:           {report?.diff ? 'تأكيد الاستيراد' : 'معاينة الملف'}
 725:         </button>
 726:       </>}>
 727:       <div className="space-y-4">
 728:         <Dropzone accept=".csv" max="10MB" label="اسحب ملف CSV هنا" onFile={(next) => { setFile(next); setReport(null); }} />
 729:         {report && (
 730:           <div>
 731:             <div className="lbl mb-2">{report.diff ? 'معاينة الاستيراد' : 'نتيجة الاستيراد'}</div>
 732:             <div className="grid grid-cols-3 gap-2 mb-2">
 733:               <div className="v-sub p-3"><div className="text-[11px] tx2">صفوف ناجحة</div><div className="font-display text-[20px] font-bold num c-green">{report.created}</div></div>
 734:               <div className="v-sub p-3"><div className="text-[11px] tx2">تم تحديثها</div><div className="font-display text-[20px] font-bold num tx1">{report.updated}</div></div>
 735:               <div className="v-sub p-3"><div className="text-[11px] tx2">أخطاء</div><div className="font-display text-[20px] font-bold num c-red">{report.errors.length}</div></div>
 736:             </div>
 737:             {!!report.diff?.length && (
 738:               <div className="v-sub p-1 max-h-[200px] overflow-y-auto mb-2">
 739:                 <table className="tbl compact">
 740:                   <thead><tr><th>الصف</th><th>المتغير</th><th>الكمية</th><th>السعر الحالي</th><th>السعر الجديد</th></tr></thead>
 741:                   <tbody>{report.diff.map((row) => (
 742:                     <tr key={row.line}><td>{row.line}</td><td>#{row.variant_id}</td><td>{row.min_qty}</td><td>{row.old_price ?? '—'}</td><td>{row.new_price}</td></tr>
 743:                   ))}</tbody>
 744:                 </table>
 745:               </div>
 746:             )}
 747:             {!!report.errors.length && (
 748:               <div className="v-sub p-1 max-h-[160px] overflow-y-auto">
 749:                 <table className="tbl compact"><tbody>
 750:                   {report.errors.map((e) => (<tr key={e.line}><td className="num tx3 !w-14">#{e.line}</td><td className="text-[12px] c-red">{e.message}</td></tr>))}
 751:                 </tbody></table>
 752:               </div>
 753:             )}
 754:           </div>
 755:         )}
 756:       </div>
 757:     </Modal>
 758:   );
 759: }
 760: 
 761: export function GroupForm({ row, onClose, onSaved }: { row: CustomerGroup | null; onClose: () => void; onSaved: () => void }) {
 762:   const toast = useToast();
 763:   const { busy, run } = useAction();
 764:   const f = useFormState({ name: row?.name ?? '', code: row?.code ?? '', desc: row?.desc ?? '', disc: row?.disc ?? 0, active: row?.active ?? true });
 765:   const save = async () => {
 766:     if (!f.v.name.trim() || !f.v.code.trim()) return toast('الاسم والكود مطلوبان', 'red');
 767:     if (f.v.code.length > 64) return toast('الكود حتى 64 حرفًا', 'red');
 768:     const body = { name: f.v.name, code: f.v.code, description: (f.v.desc as string) || null, discount_percentage: Number(f.v.disc || 0), is_active: !!f.v.active };
 769:     const r = await run(() => (row ? customerGroupsApi.update(row.id, body) : customerGroupsApi.create(body)),
 770:       { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { name: 'name', code: 'code', description: 'desc', discount_percentage: 'disc' })) });
 771:     if (r) { onSaved(); onClose(); }
 772:   };
 773:   return (
 774:     <Modal open onClose={onClose} title={row ? 'تعديل مجموعة عملاء' : 'مجموعة عملاء جديدة'} icon="users"
 775:       footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={busy} onClick={save}>حفظ</button></>}>
 776:       <div className="space-y-4">
 777:         <div className="grid grid-cols-2 gap-3">
 778:           <Field label="الاسم" req err={f.errors.name}><input className="inp" value={f.v.name} onChange={f.on('name')} /></Field>
 779:           <Field label="الكود" req hint="فريد" err={f.errors.code}><input className="inp num" dir="ltr" value={f.v.code} onChange={f.on('code')} /></Field>
 780:         </div>
 781:         <TextArea label="الوصف" value={f.v.desc} onChange={(v) => f.set('desc', v)} />
 782:         <Field label="نسبة الخصم" hint="من 0 إلى 100" err={f.errors.disc}>
 783:           <div className="relative"><input className="inp num" type="number" min="0" max="100" step="0.01" value={f.v.disc} onChange={f.on('disc' as any)} /><span className="absolute top-1/2 -translate-y-1/2 tx3 text-[12px]" style={{ insetInlineEnd: 14 }}>%</span></div>
 784:         </Field>
 785:         <ToggleRow label="مفعّلة" on={f.v.active} onChange={(v) => f.set('active', v)} />
 786:       </div>
 787:     </Modal>
 788:   );
 789: }
 790: 
 791: export function PriceListForm({ row, onClose, onSaved }: { row: (PriceListRow & { raw?: any }) | null; onClose: () => void; onSaved?: () => void }) {
 792:   const toast = useToast();
 793:   const { busy, run } = useAction();
 794:   const [type, setType] = useState(row?.type || 'retail');
 795:   const f = useFormState({
 796:     name: row?.name ?? '', customerId: String(row?.raw?.customer?.id ?? ''), groupId: String(row?.raw?.customer_group?.id ?? ''), branchId: String(row?.raw?.branch?.id ?? ''),
 797:     from: row?.from ?? '', to: (row?.to ?? '') as string, prio: row?.prio ?? 0, active: row?.active ?? true,
 798:   });
 799:   const [prio, setPrio] = useState(row?.prio ?? 0);
 800:   const prErr = prio < -10000 || prio > 10000 ? 'الأولوية من ‎-10000 إلى 10000' : null;
 801:   const groups = useRemote((s) => customerGroupsApi.all(s).then((rows) => rows.map(mapCustomerGroup)), [], { mock: () => CUSTOMER_GROUPS });
 802:   const branchesList = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
 803:   const customers = useRemote((s) => commerceLookupsApi.customers(s), []);
 804:   const toBackendType = (t: string): PriceListType => (t === 'group' ? 'customer_group' : t === 'customer' ? 'customer_specific' : t) as PriceListType;
 805:   const save = async () => {
 806:     if (!f.v.name.trim()) return toast('اسم القائمة مطلوب', 'red');
 807:     if (prErr) return;
 808:     const raw = (row as any)?.raw;
 809:     const body: any = {
 810:       name: f.v.name, type: toBackendType(type),
 811:       customer_id: type === 'customer' ? (f.v.customerId ? Number(f.v.customerId) : (raw?.customer?.id ?? null)) : null,
 812:       customer_group_id: type === 'group' ? (f.v.groupId ? Number(f.v.groupId) : (raw?.customer_group?.id ?? null)) : null,
 813:       branch_id: f.v.branchId ? Number(f.v.branchId) : null,
 814:       currency: 'EGP', starts_at: f.v.from || null, ends_at: f.v.to || null,
 815:       priority: prio, is_active: f.v.active,
 816:     };
 817:     const r = await run(() => (row && (row as any).id ? priceListsApi.update((row as any).id, body) : priceListsApi.create(body)),
 818:       { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { starts_at: 'from', ends_at: 'to', priority: 'prio', customer_id: 'customerId', customer_group_id: 'groupId', branch_id: 'branchId' })) });
 819:     if (r) { onSaved?.(); onClose(); }
 820:   };
 821: 
 822:   return (
 823:     <Modal
 824:       open
 825:       onClose={onClose}
 826:       title={row ? 'تعديل قائمة أسعار' : 'قائمة أسعار جديدة'}
 827:       icon="tag"
 828:       width={620}
 829:       footer={
 830:         <>
 831:           <button className="btn btn-soft" onClick={onClose}>
 832:             إلغاء
 833:           </button>
 834:           <button
 835:             className="btn btn-dark"
 836:             disabled={!!prErr || busy}
 837:             onClick={save}
 838:           >
 839:             حفظ
 840:           </button>
 841:         </>
 842:       }
 843:     >
 844:       <div className="space-y-4">
 845:         <Field label="الاسم" req err={f.errors.name}>
 846:           <input className="inp" value={f.v.name} onChange={f.on('name')} />
 847:         </Field>
 848:         <Field label="النوع" req>
 849:           <div className="flex flex-wrap gap-1.5">
 850:             {Object.entries(PL_TYPES).map(([k, l]) => (
 851:               <button
 852:                 key={k}
 853:                 type="button"
 854:                 onClick={() => setType(k)}
 855:                 className={'pill-inp font-medium ' + (type === k ? 'on' : '')}
 856:               >
 857:                 {l}
 858:               </button>
 859:             ))}
 860:           </div>
 861:         </Field>
 862:         {type === 'customer' && (
 863:           <Field label="العميل" req err={f.errors.customerId}>
 864:             <select className="inp" value={f.v.customerId} onChange={f.on('customerId' as any)}>
 865:               <option value="">اختر العميل</option>
 866:               {(customers.data ?? []).map((w: { id: number; name: string }) => (
 867:                 <option key={w.id} value={String(w.id)}>{w.name}</option>
 868:               ))}
 869:             </select>
 870:           </Field>
 871:         )}
 872:         {type === 'group' && (
 873:           <Field label="مجموعة العملاء" req err={f.errors.groupId}>
 874:             <select className="inp" value={f.v.groupId} onChange={f.on('groupId' as any)}>
 875:               <option value="">اختر المجموعة</option>
 876:               {(groups.data ?? []).map((g: CustomerGroup) => (
 877:                 <option key={g.id} value={String(g.id)}>{g.name}</option>
 878:               ))}
 879:             </select>
 880:           </Field>
 881:         )}
 882:         <div className="grid grid-cols-2 gap-3">
 883:           <Field label="الفرع" hint="اتركه فارغًا لكل الفروع" err={f.errors.branchId}>
 884:             <select className="inp" value={f.v.branchId} onChange={f.on('branchId' as any)}>
 885:               <option value="">كل الفروع</option>
 886:               {(branchesList.data ?? []).map((b: { id: number; name: string }) => (
 887:                 <option key={b.id} value={String(b.id)}>{b.name}</option>
 888:               ))}
 889:             </select>
 890:           </Field>
 891:           <Field label="العملة">
 892:             <input className="inp num" value="EGP" readOnly dir="ltr" />
 893:           </Field>
 894:           <Field label="بداية السريان" err={f.errors.from}>
 895:             <input type="date" className="inp" value={f.v.from} onChange={f.on('from' as any)} />
 896:           </Field>
 897:           <Field label="نهاية السريان" err={f.errors.to}>
 898:             <input type="date" className="inp" value={f.v.to} onChange={f.on('to' as any)} />
 899:           </Field>
 900:         </div>
 901:         <Field label="الأولوية" err={prErr ?? f.errors.prio} hint="الأعلى يُطبَّق أولًا عند تعارض القوائم · من ‎-10000 إلى 10000">
 902:           <input
 903:             type="number"
 904:             className={'inp num ' + (prErr ? 'err' : '')}
 905:             value={prio}
 906:             onChange={(e) => setPrio(Number(e.target.value))}
 907:           />
 908:         </Field>
 909:         <ToggleRow label="مفعّلة" on={f.v.active} onChange={(v) => f.set('active', v)} />
 910:       </div>
 911:     </Modal>
 912:   );
 913: }
 914: 
 915: export function AddPriceItemModal({ priceListId, onClose, onSaved }: { priceListId: number; onClose: () => void; onSaved: () => void }) {
 916:   const toast = useToast();
 917:   const { busy, run } = useAction();
 918:   const f = useFormState({ variantId: '', min: 1, price: '' as any, from: '', to: '' });
 919:   const variants = useRemote((signal) => commerceLookupsApi.variants(signal), []);
 920:   const variantOptions = variants.data ?? [];
 921:   const save = async () => {
 922:     if (!f.v.variantId) return toast('اختر متغير المنتج', 'red');
 923:     if (!f.v.price && f.v.price !== 0) return toast('أدخل السعر', 'red');
 924:     const r = await run(() => priceListItemsApi(priceListId).create({
 925:       product_variant_id: Number(f.v.variantId), min_qty: Number(f.v.min || 1), price: Number(f.v.price),
 926:       starts_at: (f.v.from as string) || null, ends_at: (f.v.to as string) || null,
 927:     }), { toast, success: 'تمت الإضافة', onFieldErrors: (e) => f.setErrors(e as any) });
 928:     if (r) { onSaved(); onClose(); }
 929:   };
 930:   return (
 931:     <Modal open onClose={onClose} title="إضافة بند للقائمة" icon="tag" width={560}
 932:       footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={busy} onClick={save}>إضافة</button></>}>
 933:       <div className="space-y-4">
 934:         <RemoteFeedback state={variants} />
 935:         <Field label="متغير المنتج" req err={f.errors.product_variant_id}>
 936:           <select className="inp" value={f.v.variantId} onChange={f.on('variantId' as any)}>
 937:             <option value="">اختر المتغير</option>
 938:             {variantOptions.map((v) => (<option key={v.id} value={String(v.id)}>{v.label}</option>))}
 939:           </select>
 940:         </Field>
 941:         <div className="grid grid-cols-2 gap-3">
 942:           <Field label="أقل كمية لتطبيق السعر" req hint="1 أو أكثر" err={f.errors.min_qty}><input type="number" min="1" className="inp num" value={f.v.min} onChange={f.on('min' as any)} /></Field>
 943:           <Field label="السعر (EGP)" req hint="حتى 4 منازل عشرية" err={f.errors.price}><input type="number" step="0.0001" min="0" className="inp num" value={f.v.price} onChange={f.on('price' as any)} /></Field>
 944:         </div>
 945:         <div className="grid grid-cols-2 gap-3">
 946:           <Field label="بداية سريان البند" hint="اختياري"><input type="date" className="inp" value={f.v.from} onChange={f.on('from' as any)} /></Field>
 947:           <Field label="نهاية سريان البند" hint="اختياري"><input type="date" className="inp" value={f.v.to} onChange={f.on('to' as any)} /></Field>
 948:         </div>
 949:       </div>
 950:     </Modal>
 951:   );
 952: }
 953: 
 954: export function PriceListDetail({ id, go }: { id: number; go: (r: { m: string; id?: any; sub?: any }) => void }) {
 955:   const toast = useToast();
 956:   const { busy, run } = useAction();
 957:   const pl = useRemote((s) => priceListsApi.show(id, s).then(mapPriceList), [id], { mock: () => (PRICE_LISTS.find((row) => row.id === id) ?? PRICE_LISTS[0]) as any, onError: (e) => toast(e.message, 'red') });
 958:   const p = pl.data;
 959:   const itemsApi = useMemo(() => priceListItemsApi(id), [id]);
 960:   const [search, setSearch] = useState('');
 961:   const dsearch = useDebounced(search);
 962:   const remoteItems = useRemote((s) => itemsApi.all(s), [id], { mock: () => [] });
 963:   const [items, setItems] = useState<any[]>([]);
 964:   useEffect(() => {
 965:     setItems((remoteItems.data ?? []).map((it) => ({
 966:       id: it.id, v: { name: it.variant?.product_name_ar ?? `#${it.product_variant_id}`, sku: it.variant?.sku ?? '' },
 967:       variantId: it.product_variant_id, min: it.min_qty, price: it.price, from: it.starts_at?.slice(0, 10) ?? '', to: it.ends_at?.slice(0, 10) ?? '',
 968:     })));
 969:   }, [remoteItems.data]);
 970:   const [editRow, setEditRow] = useState<any>(null);
 971:   const [form, setForm] = useState(false);
 972:   const [pct, setPct] = useState('');
 973:   const [imp, setImp] = useState(false);
 974:   const upd = (rowId: number, k: string, v: any) =>
 975:     setItems(items.map((it) => (it.id === rowId ? { ...it, [k]: v } : it)));
 976:   const saveRow = async (r: any) => {
 977:     await run(() => itemsApi.update(r.id, { product_variant_id: r.variantId, min_qty: Number(r.min), price: Number(r.price), starts_at: r.from || null, ends_at: r.to || null }),
 978:       { toast, success: 'تم حفظ البند' });
 979:   };
 980:   const deleteRow = async (r: any) => {
 981:     const ok = await run(() => itemsApi.remove(r.id), { toast, success: 'تم حذف البند' });
 982:     if (ok) setItems(items.filter((x) => x.id !== r.id));
 983:   };
 984:   const applyPercent = async (pctVal: number) => {
 985:     if (pctVal < -100) return toast('النسبة لا تقل عن -100%', 'red');
 986:     const result = await run(() => priceListsApi.applyPercentage(id, pctVal), { toast, success: 'تم تطبيق التعديل' });
 987:     remoteItems.reload();
 988:     if (result) setPct('');
 989:   };
 990:   if (!p) return <RemoteFeedback state={pl} />;
 991: 
 992:   return (
 993:     <div className="space-y-5">
 994:       <PageHead
 995:         back={{ label: 'قوائم الأسعار', onClick: () => go({ m: 'pricing' }) }}
 996:         title={p.name}
 997:         sub={
 998:           <span className="flex flex-wrap items-center gap-2">
 999:             <Badge dot={false} tone="violet">
1000:               {PL_TYPES[p.type] || p.type}
1001:             </Badge>
1002:             {p.target && <span>{p.target} ·</span>}
1003:             <span>{p.branch || 'كل الفروع'}</span>·
1004:             <span className="num ltr">
1005:               {p.from} → {p.to || '∞'}
1006:             </span>
1007:             ·
1008:             <span>
1009:               أولوية <Num>{p.prio}</Num>
1010:             </span>
1011:           </span>
1012:         }
1013:         actions={
1014:           <>
1015:             <button className="btn btn-soft" disabled={pl.usingMock} onClick={() => setForm(true)}>
1016:               <Icon name="edit" size={14} />
1017:               تعديل القائمة
1018:             </button>
1019:             <button className="btn btn-soft" disabled={busy || pl.usingMock || remoteItems.usingMock} onClick={() => run(() => priceListsApi.exportCsv(id, p.name), { toast, success: 'تم تصدير CSV' })}>
1020:               <Icon name="download" size={14} />
1021:               تصدير CSV
1022:             </button>
1023:             <button className="btn btn-soft" disabled={pl.usingMock} onClick={() => setImp(true)}><Icon name="upload" size={14} />استيراد أسعار</button>
1024:             <button className="btn btn-dark" disabled={pl.usingMock || remoteItems.usingMock} onClick={() => setEditRow({})}>
1025:               <Icon name="plus" size={14} />
1026:               إضافة بند
1027:             </button>
1028:           </>
1029:         }
1030:       />
1031:       <RemoteFeedback state={pl} />
1032:       <RemoteFeedback state={remoteItems} />
1033:       <Card stg={2}>
1034:         <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
1035:           <div className="flex items-center gap-2">
1036:             <SearchPill value={search} onChange={setSearch} placeholder="بحث بالاسم أو SKU" />
1037:             <span className="text-[11.5px] tx3">
1038:               <Num>{items.length}</Num> بند مسجّل
1039:             </span>
1040:           </div>
1041:           <div className="flex items-center gap-2">
1042:             <span className="text-[12px] tx2">تعديل نسبة مئوية جماعي:</span>
1043:             <input type="number" step="0.5" placeholder="+5%" className="inp inp-sm num w-20" value={pct} onChange={(e) => setPct(e.target.value)} />
1044:             <button className="btn btn-soft btn-sm" disabled={busy || pl.usingMock || remoteItems.usingMock || !pct} onClick={() => { const v = Number(pct); if (!Number.isFinite(v)) return toast('أدخل نسبة صحيحة', 'red'); void applyPercent(v); }}>
1045:               تطبيق
1046:             </button>
1047:           </div>
1048:         </div>
1049:         <DataTable
1050:           rows={items.filter((row) => !dsearch || (row.v.name + ' ' + row.v.sku).toLowerCase().includes(dsearch.toLowerCase()))}
1051:           cols={[
1052:             {
1053:               h: 'المنتج / المتغير',
1054:               r: (r: any) => (
1055:                 <div className="leading-tight">
1056:                   <div className="font-medium tx1">{r.v.name}</div>
1057:                   <div className="text-[11px] tx3 num">{r.v.sku}</div>
1058:                 </div>
1059:               ),
1060:             },
1061:             {
1062:               h: 'أقل كمية',
1063:               align: 'center',
1064:               r: (r: any) => (
1065:                 <input
1066:                   type="number"
1067:                   min="1"
1068:                   className="inp inp-sm num !w-20 text-center"
1069:                   disabled={pl.usingMock || remoteItems.usingMock}
1070:                   value={r.min}
1071:                   onChange={(e) => upd(r.id, 'min', e.target.value)}
1072:                   onBlur={() => saveRow(r)}
1073:                 />
1074:               ),
1075:             },
1076:             {
1077:               h: 'السعر (حتى 4 منازل)',
1078:               r: (r: any) => (
1079:                 <div className="flex items-center gap-1.5">
1080:                   <input
1081:                     type="number"
1082:                     step="0.0001"
1083:                     className="inp inp-sm num !w-28 text-end"
1084:                     value={r.price}
1085:                     onChange={(e) => upd(r.id, 'price', e.target.value)}
1086:                     onBlur={() => saveRow(r)}
1087:                   />
1088:                   <span className="text-[11px] tx3">EGP</span>
1089:                 </div>
1090:               ),
1091:             },
1092:             {
1093:               h: 'فترة سريان البند',
1094:               r: (r: any) => (
1095:                 <div className="flex items-center gap-1 num text-[11px]">
1096:                   <input
1097:                     type="date"
1098:                     className="inp inp-sm text-[11px] !py-0.5"
1099:                     value={r.from}
1100:                     onChange={(e) => upd(r.id, 'from', e.target.value)}
1101:                     onBlur={() => saveRow(r)}
1102:                   />
1103:                   <span className="tx3">→</span>
1104:                   <input
1105:                     type="date"
1106:                     className="inp inp-sm text-[11px] !py-0.5"
1107:                     value={r.to}
1108:                     onChange={(e) => upd(r.id, 'to', e.target.value)}
1109:                     onBlur={() => saveRow(r)}
1110:                   />
1111:                 </div>
1112:               ),
1113:             },
1114:             {
1115:               h: '',
1116:               r: (r: any) => (
1117:                 <RowActions>
1118:                   <button className="icon-btn !w-7 !h-7" disabled={busy} onClick={() => deleteRow(r)}>
1119:                     <Icon name="trash" size={13} />
1120:                   </button>
1121:                 </RowActions>
1122:               ),
1123:             },
1124:           ]}
1125:         />
1126:       </Card>
1127:       {imp && <PriceImportModal key={id} open priceListId={id} onClose={() => setImp(false)} onSaved={() => { remoteItems.reload(); pl.reload(); }} />}
1128:       {editRow && (
1129:         <AddPriceItemModal priceListId={id} onClose={() => setEditRow(null)} onSaved={() => { remoteItems.reload(); pl.reload(); }} />
1130:       )}
1131:       {form && <PriceListForm row={p as any} onClose={() => setForm(false)} onSaved={() => pl.reload()} />}
1132:     </div>
1133:   );
1134: }
1135: 
1136: /* ---------------- PROMOS & COUPONS ---------------- */
1137: export function PromosPage() {
1138:   const toast = useToast();
1139:   const { run } = useAction();
1140:   const [tab, setTab] = useState<'promos' | 'coupons'>('promos');
1141:   const [pf, setPf] = useState<PromoRow | null>(null);
1142:   const [cf, setCf] = useState<CouponRow | null>(null);
1143:   const [newPromo, setNewPromo] = useState(false);
1144:   const [newCoupon, setNewCoupon] = useState(false);
1145:   const promos = useRemote((s) => promotionsApi.all(s).then((rows) => rows.map((p) => ({ ...mapPromo(p), targetIds: p.target_ids ?? [], targets: (p.target_ids ?? []).map(String) }))), [], { mock: () => PROMOS });
1146:   const coupons = useRemote((s) => couponsApi.all(s).then((rows) => rows.map(mapCoupon)), [], { mock: () => COUPONS });
1147: 
1148:   const promoVal = (p: PromoRow) =>
1149:     p.type === 'percent' ? (
1150:       <Num className="font-semibold">{p.value}%</Num>
1151:     ) : p.type === 'amount' ? (
1152:       <span className="font-semibold">{fmtMoney(p.value || 0, 0)}</span>
1153:     ) : (
1154:       <span className="tx3">—</span>
1155:     );
1156: 
1157:   return (
1158:     <div className="space-y-5">
1159:       <PageHead
1160:         title="العروض والكوبونات"
1161:         sub="العروض تُطبق على نطاق محدد، والكوبونات بأكواد يُدخلها العميل."
1162:         actions={
1163:           <button className="btn btn-dark" onClick={() => (promos.usingMock || coupons.usingMock) ? toast('البيانات تجريبية — لا يمكن الحفظ', 'red') : (tab === 'promos' ? setNewPromo(true) : setNewCoupon(true))}>
1164:             <Icon name="plus" size={14} />
1165:             {tab === 'promos' ? 'عرض ترويجي' : 'كوبون جديد'}
1166:           </button>
1167:         }
1168:       />
1169:       <RemoteFeedback state={tab === 'promos' ? promos : coupons} />
1170:       <Note>العروض مسجلة للإدارة ولا يطبقها الخادم تلقائيًا عند الشراء.</Note>
1171:       <TabsBar
1172:         value={tab}
1173:         onChange={(v: any) => setTab(v)}
1174:         tabs={[
1175:           { value: 'promos', label: 'العروض الترويجية', icon: 'ticket', count: (promos.data ?? []).length },
1176:           { value: 'coupons', label: 'كوبونات الخصم', icon: 'tag', count: (coupons.data ?? []).length },
1177:         ]}
1178:       />
1179:       {tab === 'promos' && (
1180:         <Card stg={3}>
1181:           <DataTable
1182:             rows={promos.data ?? []}
1183:             onRow={(p: PromoRow) => promos.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setPf(p)}
1184:             cols={[
1185:               { h: 'اسم العرض', r: (p: PromoRow) => <span className="font-semibold tx1">{p.name}</span> },
1186:               {
1187:                 h: 'النوع',
1188:                 r: (p: PromoRow) => (
1189:                   <Badge
1190:                     dot={false}
1191:                     tone={
1192:                       ({
1193:                         percent: 'orange',
1194:                         amount: 'blue',
1195:                         free_ship: 'green',
1196:                         bxgy: 'violet',
1197:                       } as const)[p.type as 'percent'] || 'gray'
1198:                     }
1199:                   >
1200:                     {PROMO_TYPES[p.type] || p.type}
1201:                   </Badge>
1202:                 ),
1203:               },
1204:               { h: 'القيمة', r: promoVal },
1205:               {
1206:                 h: 'الحد الأدنى',
1207:                 r: (p: PromoRow) =>
1208:                   p.min ? <span className="tx2 font-medium">{fmtMoney(p.min, 0)}</span> : <span className="tx3">—</span>,
1209:               },
1210:               {
1211:                 h: 'النطاق والمستهدفات',
1212:                 r: (p: PromoRow) => (
1213:                   <div className="leading-tight">
1214:                     <span className="text-[12px] font-medium">{SCOPES[p.scope]}</span>
1215:                     {p.targets?.length > 0 && (
1216:                       <div className="text-[11px] tx3 clamp1 mt-0.5">{p.targets.join('، ')}</div>
1217:                     )}
1218:                   </div>
1219:                 ),
1220:               },
1221:               {
1222:                 h: 'السريان',
1223:                 r: (p: PromoRow) => (
1224:                   <span className="num text-[11.5px] tx2 ltr">
1225:                     {p.from} → {p.to || '∞'}
1226:                   </span>
1227:                 ),
1228:               },
1229:               { h: 'التفعيل', r: (p: PromoRow) => <ActiveDot on={p.active} /> },
1230:               {
1231:                 h: '',
1232:                 r: (p: PromoRow) => (
1233:                   <RowActions>
1234:                     <button className="icon-btn !w-7 !h-7" onClick={() => promos.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setPf(p)}>
1235:                       <Icon name="edit" size={13} />
1236:                     </button>
1237:                   </RowActions>
1238:                 ),
1239:               },
1240:             ]}
1241:           />
1242:         </Card>
1243:       )}
1244:       {tab === 'coupons' && (
1245:         <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
1246:           {(coupons.data ?? []).map((c, i) => {
1247:             const pct = c.total ? Math.min(100, (c.used / c.total) * 100) : null;
1248:             return (
1249:               <div
1250:                 key={c.id}
1251:                 className={'v-card p-5 stg stg-' + (2 + i) + ' cursor-pointer hover:shadow-sm transition'}
1252:                 onClick={() => coupons.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setCf(c)}
1253:               >
1254:                 <div className="flex items-start justify-between gap-3">
1255:                   <div>
1256:                     <div className="font-display text-[22px] font-bold num tracking-[.06em] tx1 ltr">{c.code}</div>
1257:                     <div className="flex items-center gap-2 mt-1.5">
1258:                       <Badge dot={false} tone="orange">
1259:                         {COUPON_TYPES[c.type]}
1260:                       </Badge>
1261:                       {c.value && (
1262:                         <span className="font-semibold text-[13px]">
1263:                           {c.type === 'percent' ? <Num>{c.value}%</Num> : fmtMoney(c.value, 0)}
1264:                         </span>
1265:                       )}
1266:                       {c.maxDisc && (
1267:                         <span className="text-[11.5px] tx3">حد أقصى {fmtMoney(c.maxDisc, 0)}</span>
1268:                       )}
1269:                     </div>
1270:                   </div>
1271:                   <ActiveDot on={c.active} />
1272:                 </div>
1273:                 <div className="mt-4">
1274:                   <div className="flex items-center justify-between text-[12px] mb-1.5">
1275:                     <span>
1276:                       <b className="num tx1">{fmtInt(c.used)}</b> <span className="tx3">مرة استخدام</span>
1277:                     </span>
1278:                     <span className="tx2">{c.total ? <>من <Num>{fmtInt(c.total)}</Num></> : 'غير محدود'}</span>
1279:                   </div>
1280:                   {pct != null ? <HatchBar pct={pct} h={12} /> : <div className="h-3 rounded-full hatch-track"></div>}
1281:                 </div>
1282:                 <div className="grid grid-cols-3 gap-2 mt-4">
1283:                   <div className="v-sub px-3 py-2">
1284:                     <div className="text-[10.5px] tx3">الحد الأدنى</div>
1285:                     <div className="text-[12px] font-semibold">{fmtMoney(c.min, 0)}</div>
1286:                   </div>
1287:                   <div className="v-sub px-3 py-2">
1288:                     <div className="text-[10.5px] tx3">لكل عميل</div>
1289:                     <div className="text-[12px] font-semibold">{c.perCust ? <Num>{c.perCust}</Num> : '∞'}</div>
1290:                   </div>
1291:                   <div className="v-sub px-3 py-2">
1292:                     <div className="text-[10.5px] tx3">السريان</div>
1293:                     <div className="text-[11px] font-semibold num ltr">{c.to ? c.to.slice(5) : '∞'}</div>
1294:                   </div>
1295:                 </div>
1296:               </div>
1297:             );
1298:           })}
1299:         </div>
1300:       )}
1301:       {(pf || newPromo) && <PromoForm row={pf} onClose={() => { setPf(null); setNewPromo(false); }} onSaved={() => promos.reload()} />}
1302:       {(cf || newCoupon) && <CouponForm row={cf} onClose={() => { setCf(null); setNewCoupon(false); }} onSaved={() => coupons.reload()} />}
1303:     </div>
1304:   );
1305: }
1306: 
1307: export function PromoForm({ row, onClose, onSaved }: { row: PromoRow | null; onClose: () => void; onSaved?: () => void }) {
1308:   const toast = useToast();
1309:   const { busy, run } = useAction();
1310:   const [deleting, setDeleting] = useState(false);
1311:   const [type, setType] = useState(row?.type || 'percent');
1312:   const [scope, setScope] = useState(row?.scope || 'all');
1313:   const [targets, setTargets] = useState<number[]>((row as PromoRow & { targetIds?: number[] } | null)?.targetIds ?? []);
1314:   const options = useRemote((signal) => scope === 'categories' ? commerceLookupsApi.categories(signal) : scope === 'brands' ? commerceLookupsApi.brands(signal) : commerceLookupsApi.variants(signal), [scope], { enabled: scope !== 'all' && scope !== 'brands' });
1315:   const f = useFormState({ name: row?.name ?? '', value: (row?.value ?? '') as any, min: (row?.min ?? '') as any, from: row?.from ?? '', to: (row?.to ?? '') as string, active: row?.active ?? true });
1316:   const save = async () => {
1317:     if (!f.v.name.trim()) return toast('اسم العرض مطلوب', 'red');
1318:     const body: any = {
1319:       name: f.v.name, type: promoToBackend(type),
1320:       value: type === 'percent' || type === 'amount' ? (f.v.value === '' ? undefined : Number(f.v.value)) : undefined,
1321:       min_order_amount: f.v.min === '' ? null : Number(f.v.min),
1322:       applies_to: scope, target_ids: scope === 'all' ? [] : targets,
1323:       starts_at: f.v.from || null, ends_at: (f.v.to as string) || null, is_active: !!f.v.active,
1324:     };
1325:     const r = await run(() => (row ? promotionsApi.update(row.id, body) : promotionsApi.create(body)),
1326:       { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { min_order_amount: 'min', applies_to: 'scope', target_ids: 'targets', starts_at: 'from', ends_at: 'to' })) });
1327:     if (r) { onSaved?.(); onClose(); }
1328:   };
1329: 
1330:   const opts = options.data ?? [];
1331:   const on = (id: number) => setTargets(targets.includes(id) ? targets.filter((target) => target !== id) : [...targets, id]);
1332: 
1333:   return (
1334:     <Modal
1335:       open
1336:       onClose={onClose}
1337:       title={row ? 'تعديل عرض ترويجي' : 'عرض ترويجي جديد'}
1338:       icon="ticket"
1339:       width={640}
1340:       footer={
1341:         <>
1342:           {row && <button className="btn btn-danger" disabled={busy} onClick={() => setDeleting(true)}>حذف</button>}
1343:           <button className="btn btn-soft" onClick={onClose}>
1344:             إلغاء
1345:           </button>
1346:           <button
1347:             className="btn btn-dark"
1348:             disabled={busy}
1349:             onClick={save}
1350:           >
1351:             حفظ
1352:           </button>
1353:         </>
1354:       }
1355:     >
1356:       <div className="space-y-4">
1357:         {scope !== 'all' && <RemoteFeedback state={options} />}
1358:         <Field label="اسم العرض" req err={f.errors.name}>
1359:           <input className="inp" value={f.v.name} onChange={f.on('name')} />
1360:         </Field>
1361:         <Field label="النوع" req>
1362:           <div className="flex flex-wrap gap-1.5">
1363:             {Object.entries(PROMO_TYPES).map(([k, l]) => (
1364:               <button
1365:                 key={k}
1366:                 type="button"
1367:                 onClick={() => setType(k as any)}
1368:                 className={'pill-inp font-medium ' + (type === k ? 'on' : '')}
1369:               >
1370:                 {l}
1371:               </button>
1372:             ))}
1373:           </div>
1374:         </Field>
1375:         <div className="grid grid-cols-2 gap-3">
1376:           {type !== 'free_ship' && type !== 'bxgy' && (
1377:             <Field label={type === 'percent' ? 'نسبة الخصم %' : 'مبلغ الخصم (EGP)'} req err={f.errors.value}>
1378:               <input type="number" step="0.01" min="0" className="inp num" value={f.v.value} onChange={f.on('value' as any)} />
1379:             </Field>
1380:           )}
1381:           {type === 'bxgy' && (
1382:             <>
1383:               <Field label="اشترِ (كمية X)" hint="الخادم لا يدعم حفظ كميات X/Y حاليًا">
1384:                 <input type="number" min="1" className="inp num" value="2" readOnly disabled />
1385:               </Field>
1386:               <Field label="واحصل على (كمية Y)" hint="العرض يُحفظ للإدارة فقط ولا يُطبق تلقائيًا">
1387:                 <input type="number" min="1" className="inp num" value="1" readOnly disabled />
1388:               </Field>
1389:             </>
1390:           )}
1391:           <Field label="الحد الأدنى لقيمة الطلب" hint="اختياري" err={f.errors.min}>
1392:             <input type="number" step="0.01" min="0" className="inp num" value={f.v.min} onChange={f.on('min' as any)} />
1393:           </Field>
1394:         </div>
1395:         <Field label="نطاق التطبيق" req err={f.errors.scope}>
1396:           <div className="flex flex-wrap gap-1.5">
1397:             {Object.entries(SCOPES).map(([k, l]) => (
1398:               <button
1399:                 key={k}
1400:                 type="button"
1401:                 onClick={() => {
1402:                   setScope(k as any);
1403:                   setTargets([]);
1404:                 }}
1405:                 className={'pill-inp font-medium ' + (scope === k ? 'on' : '')}
1406:               >
1407:                 {l}
1408:               </button>
1409:             ))}
1410:           </div>
1411:         </Field>
1412:         {f.errors.targets && <Note tone="red">{f.errors.targets}</Note>}
1413:         {scope !== 'all' && (
1414:           <div>
1415:             <div className="flex items-center justify-between mb-2">
1416:               <span className="lbl">المستهدفات المشمولة</span>
1417:               <span className="text-[11px] tx3">
1418:                 <Num>{targets.length}</Num> مختارة
1419:               </span>
1420:             </div>
1421:             <div className="flex flex-wrap gap-1.5 p-3 rounded-[14px] v-sub max-h-[140px] overflow-y-auto">
1422:               {opts.map((o) => (
1423:                 <button
1424:                   key={o.id}
1425:                   type="button"
1426:                   onClick={() => on(o.id)}
1427:                   className={'pill-inp text-[12px] ' + (targets.includes(o.id) ? 'on' : '')}
1428:                 >
1429:                   {o.label}
1430:                 </button>
1431:               ))}
1432:             </div>
1433:           </div>
1434:         )}
1435:         <div className="grid grid-cols-2 gap-3">
1436:           <Field label="بداية السريان" req err={f.errors.from}>
1437:             <input type="date" className="inp" value={f.v.from} onChange={f.on('from' as any)} />
1438:           </Field>
1439:           <Field label="نهاية السريان" hint="اتركه فارغًا لمستمر" err={f.errors.to}>
1440:             <input type="date" className="inp" value={f.v.to} onChange={f.on('to' as any)} />
1441:           </Field>
1442:         </div>
1443:         <ToggleRow label="مفعّل" on={f.v.active} onChange={(v) => f.set('active', v)} />
1444:       </div>
1445:       <ConfirmModal open={deleting} danger title="حذف العرض" text="سيُحذف السجل نهائيًا." cta="حذف" onClose={() => setDeleting(false)} onConfirm={async () => {
1446:         if (!row || busy) return false;
1447:         const deleted = await run(() => promotionsApi.remove(row.id), { toast, success: 'تم الحذف' });
1448:         if (deleted) { onSaved?.(); onClose(); }
1449:         return !!deleted;
1450:       }} />
1451:     </Modal>
1452:   );
1453: }
1454: 
1455: export function CouponForm({ row, onClose, onSaved }: { row: CouponRow | null; onClose: () => void; onSaved?: () => void }) {
1456:   const toast = useToast();
1457:   const { busy, run } = useAction();
1458:   const [deleting, setDeleting] = useState(false);
1459:   const [code, setCode] = useState(row?.code || '');
1460:   const [type, setType] = useState(row?.type || 'percent');
1461:   const f = useFormState({
1462:     value: (row?.value ?? '') as any, maxDisc: (row?.maxDisc ?? '') as any, min: (row?.min ?? '') as any,
1463:     total: (row?.total ?? '') as any, perCust: (row?.perCust ?? '') as any,
1464:     from: row?.from ?? '', to: (row?.to ?? '') as string, active: row?.active ?? true,
1465:   });
1466:   const save = async () => {
1467:     if (!code.trim()) return toast('كود الكوبون مطلوب', 'red');
1468:     const body: any = {
1469:       code: code.toUpperCase().replace(/\s/g, ''), type: couponToBackend(type),
1470:       value: type === 'free_ship' ? undefined : Number(f.v.value || 0),
1471:       max_discount_amount: (f.v.maxDisc as string) === '' ? null : Number(f.v.maxDisc || 0),
1472:       min_order_amount: Number(f.v.min || 0),
1473:       usage_limit: (f.v.total as string) === '' ? null : Number(f.v.total),
1474:       usage_limit_per_customer: (f.v.perCust as string) === '' ? 0 : Number(f.v.perCust),
1475:       starts_at: f.v.from, ends_at: (f.v.to as string) || null, is_active: !!f.v.active,
1476:     };
1477:     const r = await run(() => (row ? couponsApi.update(row.id, body) : couponsApi.create(body)),
1478:       { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { max_discount_amount: 'maxDisc', min_order_amount: 'min', usage_limit: 'total', usage_limit_per_customer: 'perCust', starts_at: 'from', ends_at: 'to' })) });
1479:     if (r) { onSaved?.(); onClose(); }
1480:   };
1481:   return (
1482:     <Modal
1483:       open
1484:       onClose={onClose}
1485:       title={row ? 'تعديل كوبون' : 'كوبون جديد'}
1486:       icon="ticket"
1487:       width={600}
1488:       footer={
1489:         <>
1490:           {row && <button className="btn btn-danger" disabled={busy} onClick={() => setDeleting(true)}>حذف</button>}
1491:           <button className="btn btn-soft" onClick={onClose}>
1492:             إلغاء
1493:           </button>
1494:           <button
1495:             className="btn btn-dark"
1496:             disabled={!code || busy}
1497:             onClick={save}
1498:           >
1499:             حفظ
1500:           </button>
1501:         </>
1502:       }
1503:     >
1504:       <div className="space-y-4">
1505:         <Field label="الكود" req count={`${code.length} / 50`} hint="فريد · يُحوَّل تلقائيًا لحروف كبيرة" err={f.errors.code}>
1506:           <input
1507:             className="inp num font-bold tracking-[.08em]"
1508:             dir="ltr"
1509:             maxLength={50}
1510:             value={code}
1511:             onChange={(e) => setCode(e.target.value.toUpperCase().replace(/\s/g, ''))}
1512:             placeholder="SUMMER25"
1513:           />
1514:         </Field>
1515:         <Field label="النوع" req>
1516:           <Seg
1517:             value={type}
1518:             onChange={(v: any) => setType(v)}
1519:             options={Object.entries(COUPON_TYPES).map(([v, l]) => ({ value: v, label: l }))}
1520:           />
1521:         </Field>
1522:         <div className="grid grid-cols-2 gap-3">
1523:           {type !== 'free_ship' && (
1524:             <Field label="قيمة الخصم" req err={f.errors.value}>
1525:               <div className="relative">
1526:                 <input className="inp num" type="number" value={f.v.value} onChange={f.on('value' as any)} />
1527:                 <span
1528:                   className="absolute top-1/2 -translate-y-1/2 tx3 text-[12px]"
1529:                   style={{ insetInlineEnd: 14 }}
1530:                 >
1531:                   {type === 'percent' ? '%' : 'EGP'}
1532:                 </span>
1533:               </div>
1534:             </Field>
1535:           )}
1536:           {type === 'percent' && (
1537:             <Field label="أقصى مبلغ خصم" err={f.errors.maxDisc}>
1538:               <input className="inp num" type="number" value={f.v.maxDisc} onChange={f.on('maxDisc' as any)} placeholder="بدون حد" />
1539:             </Field>
1540:           )}
1541:           <Field label="الحد الأدنى للطلب" err={f.errors.min}>
1542:             <input className="inp num" type="number" value={f.v.min} onChange={f.on('min' as any)} />
1543:           </Field>
1544:           <Field label="الحد الإجمالي للاستخدام" err={f.errors.total}>
1545:             <input className="inp num" type="number" value={f.v.total} onChange={f.on('total' as any)} placeholder="غير محدود" />
1546:           </Field>
1547:           <Field label="حد الاستخدام لكل عميل" err={f.errors.perCust}>
1548:             <input className="inp num" type="number" value={f.v.perCust} onChange={f.on('perCust' as any)} placeholder="غير محدود" />
1549:           </Field>
1550:         </div>
1551:         <div className="grid grid-cols-2 gap-3">
1552:           <Field label="بداية السريان" err={f.errors.from}>
1553:             <input type="date" className="inp" value={f.v.from} onChange={f.on('from' as any)} />
1554:           </Field>
1555:           <Field label="نهاية السريان" err={f.errors.to}>
1556:             <input type="date" className="inp" value={f.v.to} onChange={f.on('to' as any)} />
1557:           </Field>
1558:         </div>
1559:         <ToggleRow label="مفعّل" on={f.v.active} onChange={(v) => f.set('active', v)} />
1560:       </div>
1561:       <ConfirmModal open={deleting} danger title="حذف الكوبون" text="سيُحذف السجل نهائيًا." cta="حذف" onClose={() => setDeleting(false)} onConfirm={async () => {
1562:         if (!row || busy) return false;
1563:         const deleted = await run(() => couponsApi.remove(row.id), { toast, success: 'تم الحذف' });
1564:         if (deleted) { onSaved?.(); onClose(); }
1565:         return !!deleted;
1566:       }} />
1567:     </Modal>
1568:   );
1569: }
1570: 
1571: /* ---------------- BRANDS ---------------- */
1572: export function BrandForm({ row, onClose, onSaved }: { row: BrandRow | null; onClose: () => void; onSaved: () => void }) {
1573:   const toast = useToast();
1574:   const { busy, run } = useAction();
1575:   const f = useFormState({
1576:     ar: row?.ar ?? '', en: row?.en ?? '', slug: row?.slug ?? '', sort: row?.sort ?? 0,
1577:     active: row?.active ?? true, descAr: (row as BrandRow & { descAr?: string } | null)?.descAr ?? '', descEn: (row as BrandRow & { descEn?: string } | null)?.descEn ?? '',
1578:   });
1579:   const [logoFile, setLogoFile] = useState<File | null>(null);
1580:   const [removeLogo, setRemoveLogo] = useState(false);
1581:   useEffect(() => {
1582:     if (!logoFile) return;
1583:     const url = URL.createObjectURL(logoFile);
1584:     setLogoPreview(url);
1585:     return () => URL.revokeObjectURL(url);
1586:   }, [logoFile]);
1587:   const [logoPreview, setLogoPreview] = useState<string | null>(typeof row?.logo === 'string' && /^(https?:\/\/|\/)/.test(row.logo) ? row.logo : null);
1588:   const onFile = (file: File | null) => {
1589:     if (!file) return;
1590:     if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return toast('نوع الملف غير مدعوم', 'red');
1591:     if (file.size > 4 * 1024 * 1024) return toast('الملف أكبر من 4MB', 'red');
1592:     setLogoFile(file);
1593:     setRemoveLogo(false);
1594:   };
1595:   const save = async () => {
1596:     if (!f.v.ar.trim() || !f.v.en.trim()) return toast('الاسم العربي والإنجليزي مطلوبان', 'red');
1597:     const body: any = {
1598:       name_ar: f.v.ar, name_en: f.v.en, slug: (f.v.slug as string) || null,
1599:       description_ar: (f.v.descAr as string) || null, description_en: (f.v.descEn as string) || null,
1600:       sort_order: Number(f.v.sort || 0), is_active: !!f.v.active, logo: logoFile, remove_logo: removeLogo,
1601:     };
1602:     const r = await run(() => row ? brandsApi.update(row.id, body) : brandsApi.create(body), { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { name_ar: 'ar', name_en: 'en', sort_order: 'sort' })) });
1603:     if (r) { onSaved(); onClose(); }
1604:   };
1605:   return (
1606:     <Modal open onClose={onClose} title={row ? 'تعديل براند' : 'براند جديد'} icon="star" width={620}
1607:       footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={busy} onClick={save}>حفظ</button></>}>
1608:       <div className="space-y-4">
1609:         <div className="flex items-start gap-4">
1610:           <div className="shrink-0 text-center">
1611:             {logoPreview ? (
1612:               <div className="relative">
1613:                 <img src={logoPreview ?? String(row?.logo)} alt="" className="w-[88px] h-[88px] rounded-[20px] object-cover" />
1614:                 <button className="btn btn-danger btn-xs mt-2 w-full" onClick={() => { setLogoFile(null); setLogoPreview(null); setRemoveLogo(true); }}>
1615:                   <Icon name="trash" size={11} /> إزالة الشعار
1616:                 </button>
1617:               </div>
1618:             ) : (
1619:               <label className="w-[88px] h-[88px] rounded-[20px] drop !p-0 flex flex-col items-center justify-center gap-1 cursor-pointer">
1620:                 <input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => onFile(e.target.files?.[0] ?? null)} />
1621:                 <Icon name="upload" size={18} className="tx3" />
1622:                 <span className="text-[10.5px] tx3">رفع شعار</span>
1623:               </label>
1624:             )}
1625:             <div className="text-[10px] tx3 mt-1.5 leading-tight num">JPG · PNG · WebP<br />حتى 4MB</div>
1626:           </div>
1627:           <div className="flex-1 grid grid-cols-1 gap-3">
1628:             <Field label="الاسم العربي" req hint="حتى 150 حرفًا" err={f.errors.ar}><input className="inp" maxLength={150} value={f.v.ar} onChange={f.on('ar')} /></Field>
1629:             <Field label="الاسم الإنجليزي" req hint="حتى 150 حرفًا" err={f.errors.en}><input className="inp num" dir="ltr" maxLength={150} value={f.v.en} onChange={f.on('en' as any)} /></Field>
1630:           </div>
1631:         </div>
1632:         <div className="grid grid-cols-2 gap-3">
1633:           <Field label="slug" hint="اختياري" err={f.errors.slug}><input className="inp num" dir="ltr" value={f.v.slug} onChange={f.on('slug' as any)} placeholder="auto-generated" /></Field>
1634:           <Field label="ترتيب العرض" err={f.errors.sort}><input className="inp num" type="number" value={f.v.sort} onChange={f.on('sort' as any)} /></Field>
1635:         </div>
1636:         <div className="grid grid-cols-2 gap-3">
1637:           <TextArea label="الوصف العربي" value={f.v.descAr} onChange={(v) => f.set('descAr', v)} />
1638:           <Field label="الوصف الإنجليزي"><textarea className="inp num" dir="ltr" value={f.v.descEn} onChange={f.on('descEn' as any)} /></Field>
1639:         </div>
1640:         <ToggleRow label="مفعّل" on={f.v.active} onChange={(v) => f.set('active', v)} />
1641:       </div>
1642:     </Modal>
1643:   );
1644: }
1645: 
1646: export function BrandsPage() {
1647:   const toast = useToast();
1648:   const { run } = useAction();
1649:   const [page, setPage] = useState(1);
1650:   const [form, setForm] = useState<BrandRow | { id?: any; logo?: any; ar?: string; en?: string; slug?: string; sort?: number; active?: boolean; products?: number } | null>(null);
1651:   const [del, setDel] = useState<BrandRow | null>(null);
1652:   const [blocked, setBlocked] = useState<BrandRow | null>(null);
1653:   const [brandTotal, setBrandTotal] = useState(0);
1654:   const list = useRemote((s) => brandsApi.list({ per_page: 20, page }, s).then((p) => { setBrandTotal(p.meta?.total ?? p.data.length); return p.data.map((b: any) => ({
1655:     id: b.id, ar: b.name_ar, en: b.name_en, slug: b.slug, logo: b.logo_url, sort: b.sort_order, active: b.is_active, products: b.all_products_count ?? b.products_count ?? 0, descAr: b.description_ar ?? '', descEn: b.description_en ?? '',
1656:   }) as BrandRow); }), [page], { mock: () => BRANDS });
1657: 
1658:   return (
1659:     <div className="space-y-5">
1660:       <PageHead
1661:         title="البراندات"
1662:         sub="العلامات التجارية المعروضة في المتجر وترتيب ظهورها."
1663:         actions={
1664:           <button className="btn btn-dark" onClick={() => list.usingMock ? toast('البيانات تجريبية — لا يمكن الحفظ', 'red') : setForm({})}>
1665:             <Icon name="plus" size={14} />
1666:             براند جديد
1667:           </button>
1668:         }
1669:       />
1670:       <RemoteFeedback state={list} />
1671:       {blocked && (
1672:         <Note tone="red" icon="ban" className="stg stg-2">
1673:           لا يمكن حذف «{blocked.ar}» لأنه مرتبط بـ <Num>{blocked.products}</Num> منتجًا. انقل المنتجات لبراند آخر أو عطّل البراند بدلًا من حذفه.
1674:         </Note>
1675:       )}
1676:       <Card stg={2}>
1677:         <DataTable
1678:           rows={list.data ?? []}
1679:           foot={<Pager total={list.usingMock ? BRANDS.length : brandTotal} per={20} page={page} onPage={setPage} />}
1680:           onRow={(row) => list.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setForm(row)}
1681:           cols={[
1682:             {
1683:               h: 'الشعار',
1684:               w: 64,
1685:               r: (b: BrandRow) =>
1686:                 b.logo ? (
1687:                   <span
1688:                     className="w-10 h-10 rounded-[12px] flex items-center justify-center text-white font-display font-bold"
1689:                     style={{ background: /^(https?:\/\/|\/)/.test(b.logo) ? `url("${b.logo}") center / cover` : b.logo }}
1690:                   >
1691:                     {!/^(https?:\/\/|\/)/.test(b.logo) && b.en[0]}
1692:                   </span>
1693:                 ) : (
1694:                   <span className="w-10 h-10 rounded-[12px] v-sub flex items-center justify-center tx3">
1695:                     <Icon name="image" size={15} />
1696:                   </span>
1697:                 ),
1698:             },
1699:             { h: 'الاسم العربي', r: (b: BrandRow) => <span className="font-semibold tx1">{b.ar}</span> },
1700:             { h: 'الاسم الإنجليزي', r: (b: BrandRow) => <span className="num tx2 ltr">{b.en}</span> },
1701:             {
1702:               h: 'الرابط المختصر',
1703:               r: (b: BrandRow) =>
1704:                 b.slug ? (
1705:                   <span className="num text-[11.5px] v-sub px-2 py-1 rounded-md tx2 ltr">/{b.slug}</span>
1706:                 ) : (
1707:                   <span className="tx3 text-[11.5px]">يُولّد تلقائيًا</span>
1708:                 ),
1709:             },
1710:             {
1711:               h: 'الترتيب',
1712:               align: 'center',
1713:               r: (b: BrandRow) => <Num className="font-semibold">{b.sort}</Num>,
1714:             },
1715:             { h: 'التفعيل', r: (b: BrandRow) => <ActiveDot on={b.active} /> },
1716:             {
1717:               h: '',
1718:               r: (b: BrandRow) => (
1719:                 <RowActions>
1720:                   <button className="icon-btn !w-7 !h-7" onClick={() => list.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setForm(b)}>
1721:                     <Icon name="edit" size={13} />
1722:                   </button>
1723:                   <button className="icon-btn !w-7 !h-7" onClick={() => list.usingMock ? toast('البيانات تجريبية — لا يمكن الحذف', 'red') : setDel(b)}>
1724:                     <Icon name="trash" size={13} />
1725:                   </button>
1726:                 </RowActions>
1727:               ),
1728:             },
1729:           ]}
1730:         />
1731:       </Card>
1732:       {form && (
1733:         <BrandForm row={form.id ? (form as BrandRow) : null} onClose={() => setForm(null)} onSaved={() => list.reload()} />
1734:       )}
1735:       <ConfirmModal
1736:         open={!!del}
1737:         onClose={() => setDel(null)}
1738:         danger
1739:         title={'حذف «' + (del?.ar || '') + '»'}
1740:         cta="حذف"
1741:         text="سيُحذف البراند نهائيًا. سيرفض النظام الحذف إن كان مرتبطًا بمنتجات."
1742:         onConfirm={async () => {
1743:           if (!del) return false;
1744:           const r = await run(async () => {
1745:             try { return await brandsApi.remove(del.id); }
1746:             catch (error) {
1747:               const parsed = parseApiError(error);
1748:               if (/منتج|product/i.test(parsed.message)) setBlocked(del);
1749:               throw error;
1750:             }
1751:           }, { toast, success: 'تم الحذف' });
1752:           if (r) { setDel(null); list.reload(); }
1753:           return !!r;
1754:         }}
1755:       />
1756:     </div>
1757:   );
1758: }
```

## File: bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/data.ts
```typescript
  1: /* ============ Status maps + mock data (Vortix Admin) ============ */
  2: 
  3: export const BRANCHES = [
  4:   'فرع مدينة نصر',
  5:   'فرع المعادي',
  6:   'فرع الإسكندرية - سموحة',
  7:   'فرع المنصورة',
  8:   'المخزن الرئيسي',
  9: ];
 10: 
 11: export const CITIES = [
 12:   'القاهرة',
 13:   'الجيزة',
 14:   'الإسكندرية',
 15:   'المنصورة',
 16:   'طنطا',
 17:   'الزقازيق',
 18:   'أسيوط',
 19:   'سوهاج',
 20:   'الأقصر',
 21:   'أسوان',
 22:   'بورسعيد',
 23:   'الإسماعيلية',
 24:   'السويس',
 25:   'دمياط',
 26:   'المنيا',
 27:   'بني سويف',
 28:   'الفيوم',
 29:   'الغردقة',
 30: ];
 31: 
 32: export const CHANNELS = ['المتجر الإلكتروني', 'تطبيق الجوال', 'نقطة البيع', 'بوابة الجملة'];
 33: export const CASHIERS = ['محمود سعيد', 'منة الله عادل', 'كريم فتحي', 'سارة حمدي', 'يوسف عاطف'];
 34: 
 35: export interface StatusMeta {
 36:   label: string;
 37:   tone: 'gray' | 'amber' | 'blue' | 'violet' | 'orange' | 'green' | 'red';
 38: }
 39: 
 40: export const ORDER_STATUS: Record<string, StatusMeta> = {
 41:   pending: { label: 'قيد المراجعة', tone: 'amber' },
 42:   confirmed: { label: 'مؤكد', tone: 'blue' },
 43:   processing: { label: 'قيد التجهيز', tone: 'violet' },
 44:   shipped: { label: 'تم الشحن', tone: 'orange' },
 45:   delivered: { label: 'تم التسليم', tone: 'green' },
 46:   cancelled: { label: 'ملغي', tone: 'red' },
 47:   returned: { label: 'مرتجع', tone: 'gray' },
 48: };
 49: 
 50: export const ORDER_NEXT: Record<string, string[]> = {
 51:   pending: ['confirmed', 'cancelled'],
 52:   confirmed: ['processing', 'shipped', 'cancelled'],
 53:   processing: ['shipped', 'cancelled'],
 54:   shipped: ['delivered', 'returned'],
 55:   delivered: ['returned'],
 56:   cancelled: [],
 57:   returned: [],
 58: };
 59: 
 60: export const PAY_STATUS: Record<string, StatusMeta> = {
 61:   unpaid: { label: 'غير مدفوع', tone: 'amber' },
 62:   paid: { label: 'مدفوع', tone: 'green' },
 63:   failed: { label: 'فشل الدفع', tone: 'red' },
 64:   refunded: { label: 'مسترد', tone: 'gray' },
 65: };
 66: 
 67: export const PAY_METHODS: Record<string, string> = {
 68:   cod: 'عند الاستلام',
 69:   card: 'بطاقة',
 70:   wallet: 'محفظة',
 71:   bank: 'تحويل بنكي',
 72:   credit: 'آجل',
 73: };
 74: 
 75: export const RETURN_STATUS: Record<string, StatusMeta> = {
 76:   pending: { label: 'قيد المراجعة', tone: 'amber' },
 77:   approved: { label: 'موافق عليه', tone: 'blue' },
 78:   rejected: { label: 'مرفوض', tone: 'red' },
 79:   received: { label: 'مستلم', tone: 'violet' },
 80:   refunded: { label: 'تم رد المبلغ', tone: 'green' },
 81:   cancelled: { label: 'ملغي', tone: 'gray' },
 82: };
 83: 
 84: export const REFUND_METHODS: Record<string, string> = {
 85:   original: 'وسيلة الدفع الأصلية',
 86:   wallet: 'المحفظة',
 87:   bank: 'تحويل بنكي',
 88:   cash: 'نقدي',
 89: };
 90: 
 91: export const PR_STATUS: Record<string, StatusMeta> = {
 92:   draft: { label: 'مسودة', tone: 'gray' },
 93:   pending: { label: 'بانتظار الاعتماد', tone: 'amber' },
 94:   approved: { label: 'معتمد', tone: 'green' },
 95:   rejected: { label: 'مرفوض', tone: 'red' },
 96:   converted_to_po: { label: 'تحول لأمر شراء', tone: 'violet' },
 97: };
 98: 
 99: export const PO_STATUS: Record<string, StatusMeta> = {
100:   draft: { label: 'مسودة', tone: 'gray' },
101:   sent: { label: 'مرسل للمورد', tone: 'blue' },
102:   accepted: { label: 'مقبول من المورد', tone: 'green' },
103:   revision: { label: 'المورد طلب تعديلًا', tone: 'amber' },
104:   partial: { label: 'مستلم جزئيًا', tone: 'violet' },
105:   completed: { label: 'مكتمل', tone: 'green' },
106:   cancelled: { label: 'ملغي', tone: 'red' },
107: };
108: 
109: export const INV_STATUS: Record<string, StatusMeta> = {
110:   draft: { label: 'مسودة', tone: 'gray' },
111:   posted: { label: 'مُرحّلة', tone: 'blue' },
112:   partial: { label: 'مدفوعة جزئيًا', tone: 'amber' },
113:   paid: { label: 'مدفوعة', tone: 'green' },
114:   cancelled: { label: 'ملغاة', tone: 'red' },
115: };
116: 
117: export const TRANSFER_STATUS: Record<string, StatusMeta> = {
118:   pending: { label: 'بانتظار الاعتماد', tone: 'amber' },
119:   approved: { label: 'معتمد', tone: 'green' },
120:   rejected: { label: 'مرفوض', tone: 'red' },
121: };
122: 
123: export const SHIFT_STATUS: Record<string, StatusMeta> = {
124:   open: { label: 'مفتوحة', tone: 'blue' },
125:   closed: { label: 'مغلقة بانتظار الاعتماد', tone: 'amber' },
126:   approved: { label: 'معتمدة', tone: 'green' },
127: };
128: 
129: export const QUOTE_STATUS: Record<string, StatusMeta> = {
130:   draft: { label: 'مسودة', tone: 'gray' },
131:   requested: { label: 'مطلوب تسعيره', tone: 'amber' },
132:   priced: { label: 'مسعّر من الإدارة', tone: 'blue' },
133:   accepted: { label: 'مقبول', tone: 'green' },
134:   rejected: { label: 'مرفوض', tone: 'red' },
135:   expired: { label: 'منتهي', tone: 'gray' },
136:   converted: { label: 'تحول لطلب', tone: 'violet' },
137: };
138: 
139: export const NOTICE_STATUS: Record<string, StatusMeta> = {
140:   pending: { label: 'بانتظار المراجعة', tone: 'amber' },
141:   approved: { label: 'موافق عليه', tone: 'green' },
142:   rejected: { label: 'مرفوض', tone: 'red' },
143: };
144: 
145: /* ---------- products / variants ---------- */
146: export interface ProductVariant {
147:   id: number;
148:   name: string;
149:   sku: string;
150:   price: number;
151:   cost: number;
152: }
153: 
154: export const VARIANTS: ProductVariant[] = [
155:   { id: 1, name: 'زيت زيتون بكر ممتاز 1 لتر', sku: 'OIL-EV-1000', price: 385, cost: 290 },
156:   { id: 2, name: 'أرز مصري فاخر 5 كجم', sku: 'RCE-EG-5000', price: 210, cost: 168 },
157:   { id: 3, name: 'سكر أبيض ناعم 1 كجم', sku: 'SGR-WH-1000', price: 38.5, cost: 31 },
158:   { id: 4, name: 'شاي أسود ناعم 250 جم', sku: 'TEA-BL-250', price: 96, cost: 72 },
159:   { id: 5, name: 'مكرونة بيني 400 جم', sku: 'PST-PN-400', price: 22, cost: 15.5 },
160:   { id: 6, name: 'سمن نباتي 2 كجم', sku: 'GHE-VG-2000', price: 268, cost: 214 },
161:   { id: 7, name: 'عسل نحل طبيعي 500 جم', sku: 'HNY-NT-500', price: 240, cost: 175 },
162:   { id: 8, name: 'تونة قطع فاتح 185 جم', sku: 'TNA-LT-185', price: 64, cost: 49 },
163:   { id: 9, name: 'بن محوّج 200 جم', sku: 'COF-SP-200', price: 145, cost: 104 },
164:   { id: 10, name: 'دقيق فاخر 1 كجم', sku: 'FLR-PR-1000', price: 32, cost: 25 },
165: ];
166: 
167: /* ---------- orders ---------- */
168: export const NAMES = [
169:   'أحمد عبد الرحمن',
170:   'مريم خالد',
171:   'شركة النور للتجارة',
172:   'محمد إبراهيم',
173:   'زائر',
174:   'نورهان سامي',
175:   'عمر مصطفى',
176:   'هبة الله فوزي',
177:   'سوبر ماركت الأمل',
178:   'إسلام حسن',
179:   'زائر',
180:   'دينا وليد',
181: ];
182: 
183: export interface OrderRow {
184:   id: number;
185:   no: string;
186:   customer: string;
187:   guest: boolean;
188:   ctype: string;
189:   phone: string;
190:   channel: string;
191:   status: string;
192:   pay: string;
193:   payStatus: string;
194:   branch: string | null;
195:   total: number;
196:   date: string;
197:   time: string;
198:   followUp: boolean;
199:   needRefund: boolean;
200:   city: string;
201: }
202: 
203: export const ORDERS: OrderRow[] = (() => {
204:   const sts = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'delivered', 'cancelled', 'returned', 'pending', 'processing', 'shipped', 'confirmed'];
205:   const pays = ['cod', 'card', 'wallet', 'bank', 'credit', 'cod', 'card', 'card', 'cod', 'wallet', 'cod', 'credit'];
206:   const pst = ['unpaid', 'paid', 'paid', 'unpaid', 'paid', 'paid', 'refunded', 'paid', 'failed', 'paid', 'unpaid', 'unpaid'];
207:   return NAMES.map((n, i) => {
208:     const guest = n === 'زائر';
209:     return {
210:       id: 10482 - i * 7,
211:       no: 'ORD-' + (10482 - i * 7),
212:       customer: guest ? (i === 4 ? 'رانيا محمود' : 'طارق سليم') : n,
213:       guest,
214:       ctype: guest ? 'زائر' : (n.includes('شركة') || n.includes('سوبر')) ? 'جملة' : 'قطاعي',
215:       phone: '01' + ['0', '1', '2', '5'][i % 4] + String(23456781 + i * 1093217).slice(0, 8),
216:       channel: CHANNELS[i % 4],
217:       status: sts[i],
218:       pay: pays[i],
219:       payStatus: pst[i],
220:       branch: i === 8 ? null : BRANCHES[i % 5],
221:       total: [1284.5, 3920, 642, 18750, 455.75, 2310, 980, 1675, 799, 5420, 312.5, 24800][i],
222:       date: `2026-10-${String(27 - Math.floor(i / 2)).padStart(2, '0')}`,
223:       time: ['09:14', '10:42', '11:05', '12:30', '13:18', '14:02', '15:47', '16:20', '17:11', '18:35', '19:08', '20:44'][i],
224:       followUp: [true, false, false, true, false, false, false, false, true, false, false, true][i],
225:       needRefund: [false, false, false, false, false, false, true, true, false, false, false, false][i],
226:       city: CITIES[i % 8],
227:     };
228:   });
229: })();
230: 
231: /* ---------- returns ---------- */
232: export interface ReturnRow {
233:   id: number;
234:   no: string;
235:   order: string;
236:   customer: string;
237:   branch: string | null;
238:   status: string;
239:   method: string | null;
240:   total: number;
241:   date: string;
242: }
243: 
244: export const RETURNS: ReturnRow[] = [
245:   { id: 1, no: 'RET-2041', order: 'ORD-10433', customer: 'نورهان سامي', branch: 'فرع المعادي', status: 'pending', method: 'original', total: 770, date: '2026-10-27' },
246:   { id: 2, no: 'RET-2040', order: 'ORD-10426', customer: 'عمر مصطفى', branch: 'فرع مدينة نصر', status: 'approved', method: 'wallet', total: 1240, date: '2026-10-26' },
247:   { id: 3, no: 'RET-2039', order: 'ORD-10398', customer: 'سوبر ماركت الأمل', branch: 'المخزن الرئيسي', status: 'received', method: 'bank', total: 6480, date: '2026-10-25' },
248:   { id: 4, no: 'RET-2038', order: 'ORD-10377', customer: 'هبة الله فوزي', branch: 'فرع الإسكندرية - سموحة', status: 'refunded', method: 'cash', total: 385, date: '2026-10-23' },
249:   { id: 5, no: 'RET-2037', order: 'ORD-10361', customer: 'إسلام حسن', branch: 'فرع المنصورة', status: 'rejected', method: null, total: 0, date: '2026-10-22' },
250:   { id: 6, no: 'RET-2036', order: 'ORD-10340', customer: 'مريم خالد', branch: null, status: 'cancelled', method: 'original', total: 210, date: '2026-10-20' },
251: ];
252: 
253: /* ---------- suppliers ---------- */
254: export interface SupplierRow {
255:   id: number;
256:   code: string;
257:   name: string;
258:   company: string | null;
259:   phone: string;
260:   email: string | null;
261:   terms: number;
262:   balance: number;
263:   active: boolean;
264: }
265: 
266: export const SUPPLIERS: SupplierRow[] = [
267:   { id: 1, code: 'SUP-001', name: 'مصطفى الشريف', company: 'الشريف للزيوت والسمن', phone: '01001234567', email: 'orders@sherif-oils.com', terms: 30, balance: 184250, active: true },
268:   { id: 2, code: 'SUP-002', name: 'هاني عبد الله', company: 'مطاحن الدلتا', phone: '01112233445', email: 'sales@deltamills.eg', terms: 45, balance: 92600.5, active: true },
269:   { id: 3, code: 'SUP-003', name: 'إيمان رشاد', company: 'النيل للتعبئة', phone: '01223344556', email: null, terms: 15, balance: -4200, active: true },
270:   { id: 4, code: 'SUP-004', name: 'شريف منير', company: 'بن الحرمين', phone: '01098877665', email: 'info@haramain-coffee.com', terms: 60, balance: 38900, active: true },
271:   { id: 5, code: 'SUP-005', name: 'وليد قاسم', company: null, phone: '01556677889', email: null, terms: 0, balance: 0, active: false },
272:   { id: 6, code: 'SUP-006', name: 'رامي جمال', company: 'جمال للأغذية المحفوظة', phone: '01067788990', email: 'rami@gamal-foods.com', terms: 30, balance: 61340, active: true },
273: ];
274: 
275: export interface TreasuryRow {
276:   id: number;
277:   name: string;
278:   code: string;
279:   type: 'main' | 'drawer' | 'bank';
280:   branch: string | null;
281:   balance: number;
282:   active: boolean;
283: }
284: 
285: export const TREASURIES: TreasuryRow[] = [
286:   { id: 1, name: 'الخزنة الرئيسية', code: 'TR-MAIN', type: 'main', branch: null, balance: 1284600, active: true },
287:   { id: 2, name: 'درج كاشير 1 — مدينة نصر', code: 'CD-NC-01', type: 'drawer', branch: 'فرع مدينة نصر', balance: 18420.5, active: true },
288:   { id: 3, name: 'درج كاشير 2 — مدينة نصر', code: 'CD-NC-02', type: 'drawer', branch: 'فرع مدينة نصر', balance: 9310, active: true },
289:   { id: 4, name: 'درج كاشير — المعادي', code: 'CD-MA-01', type: 'drawer', branch: 'فرع المعادي', balance: 12780, active: true },
290:   { id: 5, name: 'البنك الأهلي — جاري', code: 'BK-NBE-01', type: 'bank', branch: null, balance: 3420150.75, active: true },
291:   { id: 6, name: 'بنك مصر — جاري', code: 'BK-MSR-01', type: 'bank', branch: null, balance: 865300, active: true },
292:   { id: 7, name: 'درج كاشير — سموحة', code: 'CD-AX-01', type: 'drawer', branch: 'فرع الإسكندرية - سموحة', balance: 0, active: false },
293: ];
294: 
295: export const TREASURY_TYPES: Record<string, string> = {
296:   main: 'خزنة رئيسية',
297:   drawer: 'درج كاشير',
298:   bank: 'حساب بنكي',
299: };
300: 
301: export const WHOLESALE = [
302:   'شركة النور للتجارة',
303:   'سوبر ماركت الأمل',
304:   'هايبر الفتح',
305:   'مؤسسة الرحمة للتوزيع',
306:   'ماركت الصفا',
307: ];
308: 
309: /* ---------- Shipping, Pricing, Promos & Brands ---------- */
310: export interface ShipCompany {
311:   id: number;
312:   name: string;
313:   code: string;
314:   url: string;
315:   active: boolean;
316:   api: boolean;
317: }
318: 
319: export const SHIP_COMPANIES: ShipCompany[] = [
320:   { id: 1, name: 'بوسطة', code: 'BOSTA', url: 'https://bosta.co/tracking-shipments?shipment-number={awb}', active: true, api: true },
321:   { id: 2, name: 'أرامكس', code: 'ARAMEX', url: 'https://www.aramex.com/track/results?ShipmentNumber={awb}', active: true, api: true },
322:   { id: 3, name: 'مرسول إكسبريس', code: 'MRSOOL', url: 'https://track.mrsool.eg/{awb}', active: true, api: false },
323:   { id: 4, name: 'مندوب داخلي', code: 'INHOUSE', url: '', active: true, api: false },
324:   { id: 5, name: 'J&T Express', code: 'JNT', url: 'https://www.jtexpress.eg/track?awb={awb}', active: false, api: false },
325: ];
326: 
327: export interface ShipZone {
328:   id: number;
329:   name: string;
330:   cities: string[];
331:   active: boolean;
332: }
333: 
334: export const SHIP_ZONES: ShipZone[] = [
335:   { id: 1, name: 'القاهرة الكبرى', cities: ['القاهرة', 'الجيزة'], active: true },
336:   { id: 2, name: 'الإسكندرية والساحل', cities: ['الإسكندرية'], active: true },
337:   { id: 3, name: 'الدلتا', cities: ['المنصورة', 'طنطا', 'الزقازيق', 'دمياط'], active: true },
338:   { id: 4, name: 'القناة', cities: ['بورسعيد', 'الإسماعيلية', 'السويس'], active: true },
339:   { id: 5, name: 'الصعيد', cities: ['أسيوط', 'سوهاج', 'الأقصر', 'أسوان', 'المنيا', 'بني سويف', 'الفيوم'], active: true },
340:   { id: 6, name: 'البحر الأحمر', cities: ['الغردقة'], active: false },
341: ];
342: 
343: export interface ShipRate {
344:   id: number;
345:   co: string;
346:   zone: number;
347:   base: number;
348:   free: number | null;
349:   cod: number;
350:   min: number;
351:   max: number;
352: }
353: 
354: export const SHIP_RATES: ShipRate[] = [
355:   { id: 1, co: 'BOSTA', zone: 1, base: 45, free: 1500, cod: 10, min: 1, max: 2 },
356:   { id: 2, co: 'BOSTA', zone: 3, base: 60, free: 2000, cod: 12, min: 2, max: 3 },
357:   { id: 3, co: 'ARAMEX', zone: 2, base: 65, free: null, cod: 15, min: 2, max: 4 },
358:   { id: 4, co: 'ARAMEX', zone: 5, base: 85, free: 0, cod: 15, min: 3, max: 6 },
359:   { id: 5, co: 'INHOUSE', zone: 1, base: 30, free: 800, cod: 0, min: 0, max: 1 },
360:   { id: 6, co: 'MRSOOL', zone: 4, base: 70, free: null, cod: 10, min: 2, max: 4 },
361: ];
362: 
363: export interface CustomerGroup {
364:   id: number;
365:   name: string;
366:   code: string;
367:   desc: string | null;
368:   disc: number;
369:   active: boolean;
370: }
371: 
372: export const CUSTOMER_GROUPS: CustomerGroup[] = [
373:   { id: 1, name: 'عملاء VIP', code: 'VIP', desc: 'عملاء التجزئة الأعلى إنفاقًا', disc: 7.5, active: true },
374:   { id: 2, name: 'تجار الجملة — فئة أ', code: 'WHL-A', desc: 'حجم شراء شهري يتجاوز 250 ألف', disc: 12, active: true },
375:   { id: 3, name: 'تجار الجملة — فئة ب', code: 'WHL-B', desc: null, disc: 8, active: true },
376:   { id: 4, name: 'الموظفون', code: 'STAFF', desc: 'خصم موظفي الشركة', disc: 15, active: true },
377:   { id: 5, name: 'المطاعم والكافيهات', code: 'HORECA', desc: 'قطاع الضيافة', disc: 10, active: false },
378: ];
379: 
380: export interface PriceListRow {
381:   id: number;
382:   name: string;
383:   type: string;
384:   target: string | null;
385:   branch: string | null;
386:   from: string;
387:   to: string | null;
388:   prio: number;
389:   active: boolean;
390:   items: number;
391: }
392: 
393: export const PRICE_LISTS: PriceListRow[] = [
394:   { id: 1, name: 'قائمة القطاعي الأساسية', type: 'retail', target: null, branch: null, from: '2026-01-01', to: null, prio: 0, active: true, items: 1240 },
395:   { id: 2, name: 'أسعار الجملة 2026', type: 'wholesale', target: null, branch: null, from: '2026-01-01', to: '2026-12-31', prio: 100, active: true, items: 860 },
396:   { id: 3, name: 'موزعي الدلتا', type: 'distributor', target: null, branch: 'فرع المنصورة', from: '2026-07-01', to: null, prio: 150, active: true, items: 412 },
397:   { id: 4, name: 'خصومات VIP', type: 'group', target: 'عملاء VIP', branch: null, from: '2026-10-01', to: '2026-12-31', prio: 200, active: true, items: 95 },
398:   { id: 5, name: 'تعاقد سوبر ماركت الأمل', type: 'customer', target: 'سوبر ماركت الأمل', branch: null, from: '2026-09-15', to: '2027-03-15', prio: 500, active: true, items: 64 },
399:   { id: 6, name: 'عروض فرع سموحة', type: 'retail', target: null, branch: 'فرع الإسكندرية - سموحة', from: '2026-06-01', to: '2026-08-31', prio: 50, active: false, items: 38 },
400: ];
401: 
402: export interface PromoRow {
403:   id: number;
404:   name: string;
405:   type: 'percent' | 'amount' | 'bxgy' | 'free_ship';
406:   value: number | null;
407:   min: number | null;
408:   scope: 'all' | 'categories' | 'brands' | 'variants';
409:   targets: string[];
410:   from: string;
411:   to: string | null;
412:   active: boolean;
413: }
414: 
415: export const PROMOS: PromoRow[] = [
416:   { id: 1, name: 'تخفيضات نوفمبر', type: 'percent', value: 15, min: 500, scope: 'categories', targets: ['البقالة', 'المعلبات'], from: '2026-11-01', to: '2026-11-30', active: true },
417:   { id: 2, name: 'خصم 50 جنيه على الزيوت', type: 'amount', value: 50, min: 400, scope: 'brands', targets: ['الشريف'], from: '2026-10-15', to: '2026-11-15', active: true },
418:   { id: 3, name: 'شحن مجاني للطلبات الكبيرة', type: 'free_ship', value: null, min: 1200, scope: 'all', targets: [], from: '2026-10-01', to: null, active: true },
419:   { id: 4, name: 'اشترِ 2 شاي واحصل على 1', type: 'bxgy', value: null, min: null, scope: 'variants', targets: ['TEA-BL-250'], from: '2026-09-01', to: '2026-09-30', active: false },
420: ];
421: 
422: export interface CouponRow {
423:   id: number;
424:   code: string;
425:   type: 'percent' | 'amount' | 'free_ship';
426:   value: number | null;
427:   maxDisc: number | null;
428:   min: number;
429:   total: number | null;
430:   perCust: number | null;
431:   used: number;
432:   from: string;
433:   to: string | null;
434:   active: boolean;
435: }
436: 
437: export const COUPONS: CouponRow[] = [
438:   { id: 1, code: 'WELCOME50', type: 'amount', value: 50, maxDisc: null, min: 300, total: 5000, perCust: 1, used: 1842, from: '2026-01-01', to: null, active: true },
439:   { id: 2, code: 'RAMADAN20', type: 'percent', value: 20, maxDisc: 150, min: 500, total: 2000, perCust: 2, used: 2000, from: '2026-02-18', to: '2026-03-20', active: false },
440:   { id: 3, code: 'FREESHIP', type: 'free_ship', value: null, maxDisc: null, min: 600, total: null, perCust: 3, used: 412, from: '2026-10-01', to: '2026-12-31', active: true },
441:   { id: 4, code: 'VIP10', type: 'percent', value: 10, maxDisc: 300, min: 0, total: null, perCust: null, used: 96, from: '2026-10-10', to: null, active: true },
442: ];
443: 
444: export interface BrandRow {
445:   id: number;
446:   ar: string;
447:   en: string;
448:   slug: string | null;
449:   sort: number;
450:   active: boolean;
451:   logo: string | null;
452:   products: number;
453: }
454: 
455: export const BRANDS: BrandRow[] = [
456:   { id: 1, ar: 'الشريف', en: 'El Sherif', slug: 'el-sherif', sort: 1, active: true, logo: '#e0491f', products: 48 },
457:   { id: 2, ar: 'الضحى', en: 'Al Doha', slug: 'al-doha', sort: 2, active: true, logo: '#18151c', products: 31 },
458:   { id: 3, ar: 'العروسة', en: 'El Arousa', slug: 'el-arousa', sort: 3, active: true, logo: '#0ea5a4', products: 22 },
459:   { id: 4, ar: 'بن الحرمين', en: 'Haramain Coffee', slug: 'haramain-coffee', sort: 4, active: true, logo: '#92400e', products: 14 },
460:   { id: 5, ar: 'جهينة', en: 'Juhayna', slug: 'juhayna', sort: 5, active: true, logo: '#2f5bd3', products: 57 },
461:   { id: 6, ar: 'ريف مصر', en: 'Reef Masr', slug: null, sort: 6, active: false, logo: null, products: 0 },
462: ];
463: 
464: /* ---------- Purchasing Data ---------- */
465: export interface PurchaseRequest {
466:   id: number;
467:   no: string;
468:   branch: string;
469:   status: string;
470:   by: string;
471:   date: string;
472:   items: number;
473: }
474: 
475: export const PRS: PurchaseRequest[] = [
476:   { id: 1, no: 'PR-0318', branch: 'فرع مدينة نصر', status: 'pending', by: 'كريم فتحي', date: '2026-10-27', items: 6 },
477:   { id: 2, no: 'PR-0317', branch: 'المخزن الرئيسي', status: 'draft', by: 'أحمد ناصر', date: '2026-10-26', items: 14 },
478:   { id: 3, no: 'PR-0316', branch: 'فرع المعادي', status: 'approved', by: 'سارة حمدي', date: '2026-10-25', items: 4 },
479:   { id: 4, no: 'PR-0315', branch: 'فرع المنصورة', status: 'converted_to_po', by: 'يوسف عاطف', date: '2026-10-22', items: 9 },
480:   { id: 5, no: 'PR-0314', branch: 'فرع الإسكندرية - سموحة', status: 'rejected', by: 'منة الله عادل', date: '2026-10-20', items: 3 },
481: ];
482: 
483: export interface PurchaseOrder {
484:   id: number;
485:   no: string;
486:   sup: number;
487:   branch: string;
488:   status: string;
489:   eta: string | null;
490:   total: number;
491: }
492: 
493: export const POS: PurchaseOrder[] = [
494:   { id: 1, no: 'PO-1207', sup: 1, branch: 'المخزن الرئيسي', status: 'sent', eta: '2026-11-02', total: 148500 },
495:   { id: 2, no: 'PO-1206', sup: 2, branch: 'فرع مدينة نصر', status: 'revision', eta: '2026-10-30', total: 62400 },
496:   { id: 3, no: 'PO-1205', sup: 4, branch: 'المخزن الرئيسي', status: 'partial', eta: '2026-10-28', total: 38900 },
497:   { id: 4, no: 'PO-1204', sup: 6, branch: 'فرع المعادي', status: 'accepted', eta: '2026-10-29', total: 27350 },
498:   { id: 5, no: 'PO-1203', sup: 1, branch: 'فرع المنصورة', status: 'completed', eta: '2026-10-20', total: 91200 },
499:   { id: 6, no: 'PO-1202', sup: 3, branch: 'المخزن الرئيسي', status: 'draft', eta: null, total: 14800 },
500:   { id: 7, no: 'PO-1201', sup: 2, branch: 'فرع المعادي', status: 'cancelled', eta: '2026-10-15', total: 22000 },
501: ];
502: 
503: export interface SupplierInvoice {
504:   id: number;
505:   sys: string;
506:   supNo: string;
507:   sup: number;
508:   po: string | null;
509:   issue: string;
510:   due: string | null;
511:   subtotal: number;
512:   tax: number;
513:   paid: number;
514:   status: string;
515:   file: boolean;
516: }
517: 
518: export const INVOICES: SupplierInvoice[] = [
519:   { id: 1, sys: 'SINV-0892', supNo: 'SH-2026-4471', sup: 1, po: 'PO-1203', issue: '2026-10-21', due: '2026-11-20', subtotal: 80000, tax: 11200, paid: 50000, status: 'partial', file: true },
520:   { id: 2, sys: 'SINV-0891', supNo: 'DM/10/882', sup: 2, po: null, issue: '2026-10-18', due: '2026-12-02', subtotal: 54000, tax: 7560, paid: 0, status: 'posted', file: true },
521:   { id: 3, sys: 'SINV-0890', supNo: 'HC-3310', sup: 4, po: 'PO-1205', issue: '2026-10-28', due: null, subtotal: 18500, tax: 2590, paid: 0, status: 'draft', file: false },
522:   { id: 4, sys: 'SINV-0889', supNo: 'GF-77120', sup: 6, po: null, issue: '2026-09-30', due: '2026-10-30', subtotal: 24000, tax: 3360, paid: 27360, status: 'paid', file: true },
523:   { id: 5, sys: 'SINV-0888', supNo: 'NP-0091', sup: 3, po: null, issue: '2026-09-12', due: '2026-09-27', subtotal: 6200, tax: 0, paid: 0, status: 'cancelled', file: false },
524: ];
525: 
526: export interface SupplierPayment {
527:   id: number;
528:   no: string;
529:   sup: number;
530:   inv: string | null;
531:   amount: number;
532:   method: string;
533:   ref: string | null;
534:   date: string;
535:   notes: string | null;
536:   void: { reason: string; by: string; at: string } | null;
537: }
538: 
539: export const SPAYMENTS: SupplierPayment[] = [
540:   { id: 1, no: 'SPAY-0441', sup: 1, inv: 'SINV-0892', amount: 50000, method: 'bank', ref: 'NBE-TRX-882140', date: '2026-10-24', notes: 'دفعة أولى', void: null },
541:   { id: 2, no: 'SPAY-0440', sup: 6, inv: 'SINV-0889', amount: 27360, method: 'cheque', ref: 'CHQ-004127', date: '2026-10-22', notes: null, void: null },
542:   { id: 3, no: 'SPAY-0439', sup: 2, inv: null, amount: 15000, method: 'cash', ref: null, date: '2026-10-19', notes: 'دفعة تحت الحساب', void: null },
543:   { id: 4, no: 'SPAY-0438', sup: 4, inv: null, amount: 8000, method: 'bank', ref: 'MSR-55120', date: '2026-10-11', notes: null, void: { reason: 'تحويل مكرر', by: 'أحمد ناصر', at: '2026-10-12 10:14' } },
544: ];
545: 
546: export const SP_METHODS: Record<string, string> = { cash: 'نقدي', bank: 'تحويل بنكي', cheque: 'شيك' };
547: export const supName = (id: number): string => SUPPLIERS.find(s => s.id === id)?.company || SUPPLIERS.find(s => s.id === id)?.name || '';
548: 
549: /* ---------- Finance, Shifts, Wholesale ---------- */
550: export interface ExpenseRow {
551:   hasAttachment?: boolean;
552:   voidedAt?: string | null;
553:   voidReason?: string | null;
554:   id: number;
555:   no: string;
556:   branch: string;
557:   tr: number;
558:   cat: string;
559:   amount: number;
560:   date: string;
561:   by: string;
562:   shift: string | null;
563:   notes: string | null;
564: }
565: 
566: export const EXPENSES: ExpenseRow[] = [
567:   { id: 1, no: 'EXP-2214', branch: 'فرع مدينة نصر', tr: 2, cat: 'نظافة', amount: 350, date: '2026-10-27', by: 'محمود سعيد', shift: 'SH-5521', notes: 'أدوات تنظيف' },
568:   { id: 2, no: 'EXP-2213', branch: 'المخزن الرئيسي', tr: 1, cat: 'صيانة', amount: 4800, date: '2026-10-26', by: 'أحمد ناصر', shift: null, notes: 'صيانة ثلاجة العرض' },
569:   { id: 3, no: 'EXP-2212', branch: 'فرع المعادي', tr: 4, cat: 'نقل وتحميل', amount: 600, date: '2026-10-26', by: 'كريم فتحي', shift: 'SH-5518', notes: null },
570:   { id: 4, no: 'EXP-2211', branch: 'المخزن الرئيسي', tr: 5, cat: 'إيجار', amount: 45000, date: '2026-10-25', by: 'أحمد ناصر', shift: null, notes: 'إيجار نوفمبر' },
571:   { id: 5, no: 'EXP-2210', branch: 'فرع مدينة نصر', tr: 3, cat: 'ضيافة', amount: 180, date: '2026-10-24', by: 'سارة حمدي', shift: 'SH-5512', notes: null },
572: ];
573: 
574: export interface TreasuryTransfer {
575:   id: number;
576:   from: number;
577:   to: number;
578:   amount: number;
579:   status: string;
580:   by: string;
581:   appr: string | null;
582:   date: string;
583:   notes: string | null;
584: }
585: 
586: export const TRANSFERS: TreasuryTransfer[] = [
587:   { id: 1, from: 2, to: 1, amount: 15000, status: 'pending', by: 'محمود سعيد', appr: null, date: '2026-10-27', notes: 'توريد نقدية نهاية اليوم' },
588:   { id: 2, from: 1, to: 5, amount: 250000, status: 'pending', by: 'أحمد ناصر', appr: null, date: '2026-10-27', notes: 'إيداع بنكي' },
589:   { id: 3, from: 4, to: 1, amount: 10500, status: 'approved', by: 'كريم فتحي', appr: 'أحمد ناصر', date: '2026-10-26', notes: null },
590:   { id: 4, from: 5, to: 6, amount: 120000, status: 'approved', by: 'أحمد ناصر', appr: 'هشام رضا', date: '2026-10-24', notes: null },
591:   { id: 5, from: 3, to: 1, amount: 8000, status: 'rejected', by: 'سارة حمدي', appr: 'أحمد ناصر', date: '2026-10-23', notes: 'المبلغ غير مطابق للعد' },
592: ];
593: 
594: export interface ShiftRow {
595:   id: number;
596:   no: string;
597:   cashier: string;
598:   branch: string;
599:   tr: number;
600:   open: string;
601:   close: string | null;
602:   status: string;
603:   exp: number | null;
604:   act: number | null;
605: }
606: 
607: export const SHIFTS: ShiftRow[] = [
608:   { id: 1, no: 'SH-5524', cashier: 'محمود سعيد', branch: 'فرع مدينة نصر', tr: 2, open: '2026-10-27 08:00', close: null, status: 'open', exp: null, act: null },
609:   { id: 2, no: 'SH-5521', cashier: 'منة الله عادل', branch: 'فرع مدينة نصر', tr: 3, open: '2026-10-26 16:00', close: '2026-10-27 00:10', status: 'closed', exp: 21840, act: 21640 },
610:   { id: 3, no: 'SH-5518', cashier: 'كريم فتحي', branch: 'فرع المعادي', tr: 4, open: '2026-10-26 08:00', close: '2026-10-26 16:05', status: 'closed', exp: 14260.5, act: 14310.5 },
611:   { id: 4, no: 'SH-5515', cashier: 'سارة حمدي', branch: 'فرع مدينة نصر', tr: 2, open: '2026-10-25 16:00', close: '2026-10-26 00:02', status: 'approved', exp: 18900, act: 18900 },
612:   { id: 5, no: 'SH-5512', cashier: 'يوسف عاطف', branch: 'فرع المنصورة', tr: 2, open: '2026-10-25 08:00', close: '2026-10-25 16:00', status: 'approved', exp: 9420, act: 9300 },
613: ];
614: 
615: export const trName = (id: number): string => TREASURIES.find(t => t.id === id)?.name || '';
616: 
617: export interface QuoteRow {
618:   id: number;
619:   no: string;
620:   cust: string;
621:   company: string;
622:   status: string;
623:   total: number | null;
624:   valid: string | null;
625:   date: string;
626:   order?: string;
627: }
628: 
629: export const QUOTES: QuoteRow[] = [
630:   { id: 1, no: 'QT-0612', cust: 'أحمد حمدي', company: 'شركة النور للتجارة', status: 'requested', total: null, valid: null, date: '2026-10-27' },
631:   { id: 2, no: 'QT-0611', cust: 'سامح نبيل', company: 'ماركت الصفا', status: 'priced', total: 64200, valid: '2026-11-05', date: '2026-10-26' },
632:   { id: 3, no: 'QT-0610', cust: 'عادل رمضان', company: 'هايبر الفتح', status: 'accepted', total: 118400, valid: '2026-10-31', date: '2026-10-22', order: 'ORD-10452' },
633:   { id: 4, no: 'QT-0609', cust: 'ياسر فهمي', company: 'مؤسسة الرحمة للتوزيع', status: 'converted', total: 24800, valid: '2026-10-28', date: '2026-10-18', order: 'ORD-10475' },
634:   { id: 5, no: 'QT-0608', cust: 'محمد الصاوي', company: 'سوبر ماركت الأمل', status: 'expired', total: 42000, valid: '2026-10-20', date: '2026-10-10' },
635: ];
636: 
637: export interface PaymentNoticeRow {
638:   id: number;
639:   no: string;
640:   cust: string;
641:   company: string;
642:   order: string | null;
643:   amount: number;
644:   method: string;
645:   bank: string;
646:   ref: string;
647:   date: string;
648:   file: boolean;
649:   status: string;
650:   tr?: number;
651:   review?: string;
652: }
653: 
654: export const NOTICES: PaymentNoticeRow[] = [
655:   { id: 1, no: 'PN-0284', cust: 'محمد الصاوي', company: 'شركة النور للتجارة', order: 'ORD-10475', amount: 24800, method: 'bank', bank: 'البنك الأهلي المصري', ref: 'FT26300118842', date: '2026-10-27', file: true, status: 'pending' },
656:   { id: 2, no: 'PN-0283', cust: 'ياسر فهمي', company: 'مؤسسة الرحمة للتوزيع', order: null, amount: 100000, method: 'cheque', bank: 'بنك مصر', ref: 'CHQ-883104', date: '2026-10-26', file: true, status: 'pending' },
657:   { id: 3, no: 'PN-0282', cust: 'عادل رمضان', company: 'هايبر الفتح', order: 'ORD-10452', amount: 71400, method: 'deposit', bank: 'CIB', ref: 'DEP-55021', date: '2026-10-24', file: false, status: 'approved', tr: 5, review: 'تمت المطابقة مع كشف الحساب' },
658:   { id: 4, no: 'PN-0281', cust: 'سامح نبيل', company: 'ماركت الصفا', order: null, amount: 9000, method: 'bank', bank: 'QNB', ref: 'QNB-1192', date: '2026-10-21', file: true, status: 'rejected', review: 'المبلغ غير ظاهر في كشف الحساب' },
659: ];
660: 
661: export const NOTICE_METHODS: Record<string, string> = { bank: 'تحويل بنكي', cheque: 'شيك', deposit: 'إيداع' };
662: 
663: /* ---------- Notifications ---------- */
664: export interface NotificationItem {
665:   id: string;
666:   type: string;
667:   data: any;
668:   read_at: string | null;
669:   created_at: string;
670: }
671: 
672: export const NOTIFS: NotificationItem[] = [
673:   { id: 'n1', type: 'order.created', data: { order_no: 'ORD-10482', total: 1284.5, channel: 'المتجر الإلكتروني', target: { m: 'orders', id: 10482 } }, read_at: null, created_at: 'منذ 4 دقائق' },
674:   { id: 'n2', type: 'return.requested', data: { return_no: 'RET-2041', order_no: 'ORD-10433', target: { m: 'returns', id: 1 } }, read_at: null, created_at: 'منذ 22 دقيقة' },
675:   { id: 'n3', type: 'payment_notice.submitted', data: { notice_no: 'PN-0284', company: 'شركة النور للتجارة', amount: 24800, target: { m: 'wholesale', sub: 'notices' } }, read_at: null, created_at: 'منذ ساعة' },
676:   { id: 'n4', type: 'shift.closed', data: { shift_no: 'SH-5521', cashier: 'منة الله عادل', difference: -200, target: { m: 'shifts' } }, read_at: null, created_at: 'منذ 9 ساعات' },
677:   { id: 'n5', type: 'purchase_order.revision_requested', data: { po_no: 'PO-1206', supplier: 'مطاحن الدلتا', target: { m: 'purchasing', sub: 'po' } }, read_at: '2026-10-26', created_at: 'أمس 18:40' },
678:   { id: 'n6', type: 'quotation.requested', data: { quote_no: 'QT-0611', company: 'شركة النور للتجارة', target: { m: 'wholesale', sub: 'quotes' } }, read_at: '2026-10-26', created_at: 'أمس 14:12' },
679:   { id: 'n7', type: 'stock.low', data: { sku: 'OIL-EV-1000', branch: 'فرع المعادي', qty: 4 }, read_at: '2026-10-25', created_at: '25 أكتوبر' },
680:   { id: 'n8', type: 'system.message', data: { message: 'تمت جدولة صيانة الخادم يوم الجمعة 2:00 ص' }, read_at: '2026-10-24', created_at: '24 أكتوبر' },
681: ];
```

## File: bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/OperationsMockPage.tsx
```typescript
  1: import React, { useLayoutEffect, useEffect, useRef, useState, useMemo } from 'react';
  2: import { createPortal } from 'react-dom';
  3: import styles from './operations-reference.css?inline';
  4: import { useNotifications } from './api/useNotifications';
  5: import { USE_MOCK } from './api/source';
  6: import { ToastHost, Icon, Note, Empty } from './ui';
  7: import { OrdersList, OrderDetail, ReturnsList, ReturnDetail } from './orders';
  8: import { ShippingPage, PricingPage, PriceListDetail, PromosPage } from './commerce';
  9: import { FinancePage, ShiftsPage, WholesalePage } from './finance';
 10: import { ReportsPage, NotificationsPage, NotifDrawer } from './reports';
 11: 
 12: export interface OperationsRoute {
 13:   m: string;
 14:   id?: number | string;
 15:   sub?: string;
 16: }
 17: 
 18: export const NAV_GROUPS = [
 19:   {
 20:     key: 'sales',
 21:     label: 'المبيعات',
 22:     items: [
 23:       { m: 'orders', label: 'الطلبات', icon: 'orders' },
 24:       { m: 'returns', label: 'المرتجعات', icon: 'returns' },
 25:       { m: 'wholesale', label: 'الجملة', icon: 'quote' },
 26:       { m: 'shipping', label: 'الشحن', icon: 'truck' },
 27:     ],
 28:   },
 29:   {
 30:     key: 'catalog',
 31:     label: 'التسعير والعروض',
 32:     items: [
 33:       { m: 'pricing', label: 'التسعير ومجموعات العملاء', icon: 'tag' },
 34:       { m: 'promos', label: 'العروض والكوبونات', icon: 'ticket' },
 35:     ],
 36:   },
 37:   {
 38:     key: 'finance',
 39:     label: 'المالية والتقارير',
 40:     items: [
 41:       { m: 'finance', label: 'المالية', icon: 'vault' },
 42:       { m: 'shifts', label: 'مراجعة الورديات', icon: 'clock' },
 43:       { m: 'reports', label: 'التقارير', icon: 'chart' },
 44:     ],
 45:   },
 46: ];
 47: 
 48: export const groupOf = (m: string) => NAV_GROUPS.find((g) => g.items.some((i) => i.m === m))?.key;
 49: 
 50: export function canViewOperation(page: string, can: (permission: string) => boolean): boolean {
 51:   const permissions: Record<string, string[]> = {
 52:     orders: ['orders.view'], returns: ['returns.view'], shipping: ['shipping.view'],
 53:     pricing: ['price_lists.view', 'customer_groups.view'], promos: ['promotions.view', 'coupons.view'],
 54:     finance: ['treasuries.view', 'expenses.view', 'treasury_transfers.view'],
 55:     shifts: ['pos_shifts.view'], wholesale: ['quotations.view', 'payment_notices.view'], reports: ['reports.view'],
 56:   };
 57:   return !permissions[page] || permissions[page].some(can);
 58: }
 59: 
 60: export interface OperationsMockPageProps {
 61:   page: string;
 62:   id?: string | number;
 63:   sub?: string;
 64:   darkMode?: boolean;
 65:   theme?: string;
 66:   can?: (perm: string) => boolean;
 67: }
 68: 
 69: export default function OperationsMockPage({
 70:   page,
 71:   id: initialId,
 72:   sub: initialSub,
 73:   darkMode,
 74:   theme,
 75:   can = () => true,
 76: }: OperationsMockPageProps) {
 77:   const host = useRef<HTMLDivElement>(null);
 78:   const [root, setRoot] = useState<ShadowRoot | null>(null);
 79: 
 80:   const [route, setRoute] = useState<OperationsRoute>(() => ({
 81:     m: page || 'orders',
 82:     id: initialId,
 83:     sub: initialSub,
 84:   }));
 85: 
 86:   const notif = useNotifications();
 87:   const { items: notifs, markRead } = notif;
 88:   const [drawer, setDrawer] = useState(false);
 89: 
 90:   useEffect(() => {
 91:     setRoute({ m: page, id: initialId, sub: initialSub });
 92:   }, [page, initialId, initialSub]);
 93: 
 94:   // Dark mode detection and synchronization
 95:   const [detectedDark, setDetectedDark] = useState(() => {
 96:     if (typeof darkMode === 'boolean') return darkMode;
 97:     if (typeof theme === 'string') return theme === 'dark';
 98:     try {
 99:       return localStorage.getItem('vortix_theme') === 'dark' || document.documentElement.classList.contains('dark');
100:     } catch {
101:       return false;
102:     }
103:   });
104: 
105:   const isDark = typeof darkMode === 'boolean' ? darkMode : theme ? theme === 'dark' : detectedDark;
106: 
107:   useLayoutEffect(() => {
108:     if (host.current) {
109:       setRoot(host.current.shadowRoot || host.current.attachShadow({ mode: 'open' }));
110:     }
111:     const fonts = document.createElement('link');
112:     fonts.rel = 'stylesheet';
113:     fonts.href =
114:       'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Readex+Pro:wght@400;500;600;700&display=swap';
115:     document.head.append(fonts);
116:     return () => fonts.remove();
117:   }, []);
118: 
119:   useEffect(() => {
120:     if (host.current) {
121:       host.current.setAttribute('data-theme', isDark ? 'dark' : 'light');
122:     }
123:   }, [isDark]);
124: 
125:   useEffect(() => {
126:     const handleThemeChange = (e: any) => {
127:       if (typeof e.detail?.darkMode === 'boolean') {
128:         setDetectedDark(e.detail.darkMode);
129:       }
130:     };
131:     window.addEventListener('vortix:theme-change', handleThemeChange);
132: 
133:     const checkParent = () => {
134:       const rootNode = host.current?.getRootNode() as ShadowRoot | undefined;
135:       const parentViewport = host.current?.closest('#app-viewport');
136:       const target =
137:         parentViewport ||
138:         (rootNode?.host?.querySelector?.('#app-viewport') || rootNode?.host) ||
139:         document.querySelector('#app-viewport') ||
140:         document.querySelector('[data-vortix-host]') ||
141:         document.documentElement;
142:       if (!target) return null;
143:       const dark = target.classList?.contains('theme-dark') || target.classList?.contains('dark') || target.getAttribute?.('data-theme') === 'dark';
144:       setDetectedDark(dark);
145:       return target;
146:     };
147: 
148:     const target = checkParent();
149:     let observer: MutationObserver | undefined;
150:     if (target) {
151:       observer = new MutationObserver(() => checkParent());
152:       observer.observe(target, { attributes: true, attributeFilter: ['class', 'data-theme'] });
153:     }
154: 
155:     return () => {
156:       window.removeEventListener('vortix:theme-change', handleThemeChange);
157:       observer?.disconnect();
158:     };
159:   }, []);
160: 
161:   const go = (r: OperationsRoute) => {
162:     setRoute(r);
163:     let hash = `/${r.m}`;
164:     if (r.id != null) {
165:       hash += `/${r.id}`;
166:     }
167:     if (r.sub) {
168:       hash += `?sub=${encodeURIComponent(r.sub)}`;
169:     }
170:     window.location.hash = hash;
171:     window.scrollTo({ top: 0, behavior: 'smooth' });
172:   };
173: 
174:   const unread = notif.unread;
175:   const curGroup = groupOf(route.m);
176: 
177:   const navGroups = NAV_GROUPS.map(g => ({ ...g, items: g.items.filter(it => canViewOperation(it.m, can)) })).filter(g => g.items.length > 0);
178:   const activeGroupObj = navGroups.find((g) => g.key === curGroup);
179: 
180:   const pageContent = useMemo(() => {
181:     const { m, id, sub } = route;
182:     const numId = id != null ? Number(id) : undefined;
183:     if (['suppliers', 'purchasing', 'brands'].includes(m)) return <Empty icon="lock" title="هذا القسم متوقف" text="منتجات الفريد من تصنيعنا؛ التجار يُدارون من العملاء والجملة." />;
184:     if (!canViewOperation(m, can)) return <Empty icon="lock" title="ليس لديك صلاحية عرض هذه الصفحة." />;
185:     switch (m) {
186:       case 'orders':
187:         return numId ? <OrderDetail id={numId} go={go} /> : <OrdersList go={go} />;
188:       case 'returns':
189:         return numId ? <ReturnDetail id={numId} go={go} /> : <ReturnsList go={go} />;
190:       case 'shipping':
191:         return <ShippingPage />;
192:       case 'pricing':
193:         return numId ? <PriceListDetail id={numId} go={go} /> : <PricingPage go={go} />;
194:       case 'promos':
195:         return <PromosPage />;
196:       case 'finance':
197:         return <FinancePage sub={sub} id={route.id} can={can} />;
198:       case 'shifts':
199:         return <ShiftsPage />;
200:       case 'wholesale':
201:         return <WholesalePage sub={sub} />;
202:       case 'reports':
203:         return <ReportsPage />;
204:       case 'notifications':
205:         return <NotificationsPage items={notifs} onRead={markRead} onReadAll={notif.markAllRead} onMore={notif.hasMore ? notif.loadMore : undefined} loading={notif.loading} go={go} />;
206:       default:
207:         return <OrdersList go={go} />;
208:     }
209:   }, [route, notifs, notif.loading, notif.hasMore, can]);
210: 
211:   return (
212:     <div
213:       ref={host}
214:       data-operations-mock={route.m}
215:       data-theme={isDark ? 'dark' : 'light'}
216:       dir="rtl"
217:     >
218:       {root &&
219:         createPortal(
220:           <>
221:             <style>{styles}</style>
222:             <ToastHost portalHost={root}>
223:               <div className={`operations-mock-container ${isDark ? 'theme-dark dark' : 'theme-light'} max-w-[1400px] mx-auto px-4 sm:px-7 pt-4 pb-12`} dir="rtl" lang="ar">
224:                 {/* Operations Top Sub-Header & Navigation Bar */}
225:                 <div className="flex flex-wrap items-center justify-between gap-4 mb-6 pb-4 border-b border-[var(--border-subtle)]">
226:                   {/* Secondary Navigation Pills */}
227:                   <div className="flex items-center gap-2 flex-wrap">
228:                     <div className="v-pill p-1 flex items-center gap-1 flex-wrap">
229:                       {navGroups.map((g) => (
230:                         <button
231:                           key={g.key}
232:                           onClick={() => go({ m: g.items[0].m })}
233:                           className="px-4 py-1.5 rounded-full text-[12.5px] font-medium transition"
234:                           style={
235:                             curGroup === g.key
236:                               ? {
237:                                   background: 'var(--pill-active-bg)',
238:                                   color: 'var(--pill-active-text)',
239:                                   fontWeight: 600,
240:                                 }
241:                               : { color: 'var(--text-secondary)' }
242:                           }
243:                         >
244:                           {g.label}
245:                         </button>
246:                       ))}
247:                     </div>
248: 
249:                     {/* Sub-items in current group */}
250:                     {activeGroupObj && activeGroupObj.items.length > 1 && (
251:                       <div className="flex items-center gap-1.5 v-sub px-3 py-1 rounded-full text-[12px]">
252:                         {activeGroupObj.items.map((it) => {
253:                           const active = route.m === it.m;
254:                           return (
255:                             <button
256:                               key={it.m}
257:                               onClick={() => go({ m: it.m })}
258:                               className={`px-2.5 py-1 rounded-full transition flex items-center gap-1.5 ${
259:                                 active
260:                                   ? 'bg-[var(--accent)] text-white font-semibold shadow-xs'
261:                                   : 'tx2 hover:tx1'
262:                               }`}
263:                             >
264:                               <Icon name={it.icon} size={12} />
265:                               <span>{it.label}</span>
266:                             </button>
267:                           );
268:                         })}
269:                       </div>
270:                     )}
271:                   </div>
272: 
273:                   {/* Notifications & Quick Controls */}
274:                   <div className="flex items-center gap-3">
275:                     <button
276:                       className="v-pill px-3 py-1.5 flex items-center gap-2 tx2 hover:tx1 text-[12.5px] font-medium relative"
277:                       title="الإشعارات"
278:                       onClick={() => setDrawer(true)}
279:                     >
280:                       <Icon name="bell" size={15} />
281:                       <span>الإشعارات</span>
282:                       {unread > 0 && (
283:                         <span
284:                           className="min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold text-white flex items-center justify-center num"
285:                           style={{ background: 'var(--accent)' }}
286:                         >
287:                           {unread}
288:                         </span>
289:                       )}
290:                     </button>
291:                   </div>
292:                 </div>
293: 
294:                 {/* Main Active Page Content */}
295:                 <main>{USE_MOCK && <Note tone="orange">وضع البيانات التجريبية مفعّل — لا يتم حفظ أي تغيير.</Note>}{notif.error && <Note tone="red">{notif.error}{notif.usingMock && '؛ الإشعارات المعروضة تجريبية.'}<button className="btn btn-ghost btn-xs" onClick={notif.reload}>إعادة المحاولة</button></Note>}{pageContent}</main>
296: 
297:                 {/* Notifications Drawer */}
298:                 <NotifDrawer
299:                   open={drawer}
300:                   onClose={() => setDrawer(false)}
301:                   items={notifs}
302:                   onRead={markRead}
303:                   go={go}
304:                   container={root as unknown as Element}
305:                 />
306:               </div>
307:             </ToastHost>
308:           </>,
309:           root
310:         )}
311:     </div>
312:   );
313: }
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Store/CatalogController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Store;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Http\Requests\Store\ProductFilterRequest;
 9: use App\Http\Resources\Store\StoreBrandResource;
10: use App\Http\Resources\Store\StoreCategoryResource;
11: use App\Http\Resources\Store\StoreCityResource;
12: use App\Models\Brand;
13: use App\Models\Category;
14: use App\Models\City;
15: use App\Services\StoreCatalogService;
16: use Illuminate\Http\JsonResponse;
17: use Illuminate\Http\Request;
18: 
19: /** قوائم التصفح الثابتة: أقسام (شجرة بعدّادات منتجات منشورة) وعلامات ومدن الشحن. */
20: class CatalogController extends Controller
21: {
22:     public function __construct(private readonly StoreCatalogService $catalog) {}
23: 
24:     public function filters(ProductFilterRequest $request): JsonResponse
25:     {
26:         return response()->json(['data' => $this->catalog->facets($request->validated())]);
27:     }
28: 
29:     public function categories(Request $request): JsonResponse
30:     {
31:         $childrenScope = fn ($q) => $q->where('is_active', true)
32:             ->with('media')
33:             ->withCount('activeProducts')
34:             ->orderBy('sort_order')
35:             ->orderBy('id');
36: 
37:         $roots = Category::query()
38:             ->whereNull('parent_id')
39:             ->where('is_active', true)
40:             ->with('media')
41:             ->withCount('activeProducts')
42:             ->with([
43:                 // L6: id tie-break keeps sibling order deterministic across pages.
44:                 'children' => fn ($q) => $childrenScope($q)->with(['children' => $childrenScope]),
45:             ])
46:             ->orderBy('sort_order')->orderBy('id')
47:             ->get();
48: 
49:         return response()->json(['data' => StoreCategoryResource::collection($roots)->resolve($request)]);
50:     }
51: 
52:     /**
53:      * F19: brands are a legacy surface — products no longer accept a brand_id
54:      * (prohibited in admin requests, forced to null in the service) and the
55:      * storefront never filters by brand. This endpoint is retained read-only
56:      * for old clients; do not build new features on it.
57:      */
58:     public function brands(Request $request): JsonResponse
59:     {
60:         $brands = Brand::query()
61:             ->with('media')
62:             ->where('is_active', true)
63:             ->orderBy('sort_order')
64:             ->orderBy('name_ar')
65:             ->get();
66: 
67:         return response()->json(['data' => StoreBrandResource::collection($brands)->resolve($request)]);
68:     }
69: 
70:     public function cities(Request $request): JsonResponse
71:     {
72:         $cities = City::query()
73:             ->where('is_active', true)
74:             ->orderBy('name_ar')
75:             ->get();
76: 
77:         return response()->json(['data' => StoreCityResource::collection($cities)->resolve($request)]);
78:     }
79: }
```

## File: bags-platform/backend/database/seeders/RolePermissionSeeder.php
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

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Store/CheckoutController.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Http\Controllers\Api\V1\Store;
  6: 
  7: use App\Exceptions\BusinessException;
  8: use App\Http\Controllers\Controller;
  9: use App\Http\Requests\Store\Checkout\CheckoutRequest;
 10: use App\Http\Requests\Store\Checkout\ShippingOptionsRequest;
 11: use App\Http\Resources\Store\OrderResource;
 12: use App\Models\Cart;
 13: use App\Models\City;
 14: use App\Models\Coupon;
 15: use App\Models\Customer;
 16: use App\Services\CartService;
 17: use App\Services\OrderService;
 18: use App\Services\SettingService;
 19: use App\Services\ShippingQuoteService;
 20: use App\Services\StorePaymentPolicy;
 21: use Illuminate\Http\JsonResponse;
 22: use Illuminate\Http\Request;
 23: 
 24: /** إتمام الشراء (زائر/مسجل) وخيارات الشحن. */
 25: class CheckoutController extends Controller
 26: {
 27:     public function __construct(
 28:         private readonly CartService $carts,
 29:         private readonly OrderService $orders,
 30:         private readonly ShippingQuoteService $shippingQuote,
 31:         private readonly SettingService $settings,
 32:         private readonly StorePaymentPolicy $paymentPolicy,
 33:     ) {}
 34: 
 35:     public function shippingOptions(ShippingOptionsRequest $request): JsonResponse
 36:     {
 37:         $validated = $request->validated();
 38:         $city = City::query()->where('is_active', true)->findOrFail((int) $validated['city_id']);
 39:         [$customer, $cart] = $this->currentCart($request, create: false);
 40:         $totals = $this->carts->totals($cart, $customer);
 41: 
 42:         // نفس المدخلات التي سيحاسب عليها إتمام الشراء (M12 + F-29): البضاعة
 43:         // بعد خصم الكوبون، ورسوم COD حسب طريقة الدفع الفعلية لا cod دائمًا
 44:         $merchandise = max($totals['items_total'] - $totals['coupon_discount'], 0.0);
 45:         $methods = $this->paymentPolicy->allowedFor($customer);
 46:         $method = in_array($validated['payment_method'] ?? null, $methods, true)
 47:             ? $validated['payment_method'] : ($methods[0] ?? null);
 48:         $cod = $method === 'cod';
 49:         $quote = $this->shippingQuote->quoteForCity($city->id, $merchandise, $cod);
 50:         $rate = $quote['rate'];
 51: 
 52:         // كوبون الشحن المجاني يعفي تكلفة الشحن (لا رسوم COD) كما عند الشراء
 53:         $cost = $quote['cost'];
 54:         if ($totals['coupon_code'] !== null && $cart->coupon_id !== null
 55:             && Coupon::query()->whereKey($cart->coupon_id)->value('type') === 'free_shipping') {
 56:             $cost = 0.0;
 57:         }
 58: 
 59:         $days = $rate !== null ? "من {$rate->estimated_days_min} إلى {$rate->estimated_days_max} أيام عمل" : null;
 60: 
 61:         // معاينة الإجمالي بنفس معادلة OrderService::createFromCart (شاملة
 62:         // الكوبون والشحن المجاني وCOD والضريبة) — الفرونت يعرضها ويُرسلها
 63:         // كـ expected_total، فأي تغيّر سعري يُرفض بـ price_changed بدل تحصيل صامت.
 64:         $discountAmount = round($totals['pricing_discount'] + $totals['coupon_discount'], 2);
 65:         $taxAmount = 0.0;
 66:         if ($this->settings->getBool('tax_enabled') && ! $this->settings->getBool('prices_include_tax')) {
 67:             $taxRate = (float) $this->settings->get('default_tax_rate', 0);
 68:             $taxAmount = round(($totals['subtotal'] - $discountAmount) * $taxRate / 100, 2);
 69:         }
 70:         $grandTotal = round(($totals['subtotal'] - $discountAmount) + $cost + $quote['cod_fee'] + $taxAmount, 2);
 71: 
 72:         return response()->json(['data' => [
 73:             'city' => ['id' => $city->id, 'name_ar' => $city->name_ar, 'name_en' => $city->name_en],
 74:             'cart_total' => $totals['total'],
 75:             'payment_methods' => $methods,
 76:             'preview' => [
 77:                 'items_total' => $totals['items_total'],
 78:                 'coupon_discount' => $totals['coupon_discount'],
 79:                 'shipping' => $cost,
 80:                 'cod_fee' => $quote['cod_fee'],
 81:                 'tax' => $taxAmount,
 82:                 'grand_total' => $grandTotal,
 83:             ],
 84:             'options' => [[
 85:                 'key' => 'standard',
 86:                 'label' => 'شحن قياسي',
 87:                 'cost' => $cost,
 88:                 'cod_fee' => $quote['cod_fee'],
 89:                 'currency' => 'EGP',
 90:                 'estimated_days' => $days,
 91:             ]],
 92:         ]]);
 93:     }
 94: 
 95:     public function store(CheckoutRequest $request): JsonResponse
 96:     {
 97:         [$customer, $cart] = $this->currentCart($request);
 98:         $data = $request->validated();
 99:         // هيدر اختياري: إعادة الإرسال بنفس المفتاح ترجع الطلب نفسه (F-13)
100:         $idempotencyKey = $request->header('Idempotency-Key');
101:         if (is_string($idempotencyKey) && $idempotencyKey !== '') {
102:             $data['idempotency_key'] = $idempotencyKey;
103:         }
104: 
105:         if ($customer === null && (empty($data['recipient_name']) || empty($data['recipient_phone']))) {
106:             throw new BusinessException('بيانات المستلم (الاسم ورقم الهاتف) مطلوبة لإتمام الشراء كزائر.');
107:         }
108: 
109:         $order = $this->orders->createFromCart($cart, $data, $customer);
110: 
111:         return response()->json([
112:             'data' => OrderResource::make($order)->resolve($request),
113:             // لا بوابة دفع مدمجة بعد: الدفع الإلكتروني يرجع رابطًا فارغًا حتى ربط البوابة
114:             'payment_url' => null,
115:             'message' => 'تم إنشاء الطلب بنجاح',
116:         ], 201);
117:     }
118: 
119:     /** @return array{0: ?Customer, 1: Cart} */
120:     private function currentCart(Request $request, bool $create = true): array
121:     {
122:         $user = auth('sanctum')->user();
123:         // عميل موقوف/مرفوض بتوكن صالح يُعامل كزائر (F-20): لا تُربط سلاته
124:         // وطلباته بحساب غير نشط ولا تُمنح له أسعار/حدود العميل
125:         $customer = $user instanceof Customer && $user->canShop() ? $user : null;
126:         $cart = $this->carts->resolveCart($customer, $request->header('X-Cart-Session'), $create);
127: 
128:         return [$customer, $cart];
129:     }
130: }
```

## File: bags-platform/backend/app/Services/ShippingWebhookService.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use App\Enums\OrderStatus;
  8: use App\Enums\ShipmentStatus;
  9: use App\Models\Shipment;
 10: use App\Models\ShippingCompany;
 11: use App\Models\WebhookLog;
 12: use Illuminate\Database\QueryException;
 13: use Illuminate\Support\Facades\DB;
 14: use Throwable;
 15: 
 16: /**
 17:  * Inbound shipping carrier webhook processing service:
 18:  * - Logs payload fingerprint before signature verification to guard against malformed data.
 19:  * - Validates HMAC signature using the carrier's configured webhook secret.
 20:  * - Serializes processing atomically via PostgreSQL advisory locks on (provider:event_id).
 21:  * - Enforces status progression rules to ignore stale or regressive out-of-order events.
 22:  * - Updates shipment records and automatically triggers order delivery or flags for manual review.
 23:  */
 24: class ShippingWebhookService
 25: {
 26:     /** Common carrier status aliases mapped to internal shipment enum cases. */
 27:     private const STATUS_MAP = [
 28:         'created' => ShipmentStatus::Created,
 29:         'picked_up' => ShipmentStatus::PickedUp,
 30:         'picked' => ShipmentStatus::PickedUp,
 31:         'collected' => ShipmentStatus::PickedUp,
 32:         'in_transit' => ShipmentStatus::InTransit,
 33:         'transit' => ShipmentStatus::InTransit,
 34:         'out_for_delivery' => ShipmentStatus::OutForDelivery,
 35:         'out' => ShipmentStatus::OutForDelivery,
 36:         'delivered' => ShipmentStatus::Delivered,
 37:         'failed' => ShipmentStatus::Failed,
 38:         'failure' => ShipmentStatus::Failed,
 39:         'delivery_failed' => ShipmentStatus::Failed,
 40:         'returned' => ShipmentStatus::Returned,
 41:         'return' => ShipmentStatus::Returned,
 42:         'rto' => ShipmentStatus::Returned,
 43:     ];
 44: 
 45:     /** Lifecycle hierarchy rank — events with lower rank than current state represent out-of-order deliveries. */
 46:     private const RANK = [
 47:         'created' => 0,
 48:         'picked_up' => 1,
 49:         'in_transit' => 2,
 50:         'out_for_delivery' => 3,
 51:         'delivered' => 4,
 52:         'returned' => 4,
 53:     ];
 54: 
 55:     public function __construct(
 56:         protected WebhookSignatureVerifier $verifier,
 57:         protected OrderService $orders,
 58:     ) {}
 59: 
 60:     /**
 61:      * Process an inbound carrier tracking webhook event.
 62:      *
 63:      * @param  string  $companyCode
 64:      * @param  array<string, mixed>  $payload
 65:      * @param  string  $rawBody
 66:      * @param  string|null  $signature
 67:      * @param  string|null  $ip
 68:      * @return array{http: int, body: array<string, mixed>}
 69:      */
 70:     public function handle(string $companyCode, array $payload, string $rawBody, ?string $signature, ?string $ip): array
 71:     {
 72:         $provider = 'shipping:'.$companyCode;
 73:         // Pre-verification key uses raw body hash because unverified payload fields cannot be trusted
 74:         $preVerifyKey = 'sha256:'.hash('sha256', $rawBody);
 75: 
 76:         if (strlen($rawBody) > 262144) {
 77:             $this->slimLog($provider, $preVerifyKey, 'rejected', 'payload too large', $ip);
 78: 
 79:             return ['http' => 413, 'body' => ['received' => false, 'message' => 'payload too large']];
 80:         }
 81: 
 82:         $company = ShippingCompany::query()->where('code', $companyCode)->first();
 83:         if ($company === null || ! $company->is_active) {
 84:             // Generic 403 response prevents carrier code enumeration via distinct HTTP response codes
 85:             $this->slimLog($provider, $preVerifyKey, 'failed', 'شركة شحن غير معروفة أو معطّلة', $ip);
 86: 
 87:             return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
 88:         }
 89: 
 90:         // Validate HMAC signature exclusively using the carrier's designated webhook_secret
 91:         $credentials = $company->api_credentials ?? [];
 92:         $secret = $credentials['webhook_secret'] ?? null;
 93:         if (! is_string($secret) || $secret === '' || ! $this->verifier->verify($secret, $rawBody, $signature)) {
 94:             $this->slimLog($provider, $preVerifyKey, 'invalid_signature',
 95:                 (! is_string($secret) || $secret === '') ? 'لا يوجد webhook_secret مضبوط للشركة' : null, $ip);
 96: 
 97:             return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
 98:         }
 99: 
100:         // Derive sanitized event identifier after cryptographic verification succeeds
101:         $eventId = $this->effectiveEventId($payload, $rawBody);
102:         $log = $this->startLog($provider, $eventId, $payload, $ip);
103:         $log->update(['signature_valid' => true]);
104: 
105:         return DB::transaction(function () use ($provider, $payload, $log, $company): array {
106:             DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$provider.':'.$log->event_id]);
107: 
108:             if ($this->alreadyProcessed($provider, $log)) {
109:                 $log->update(['status' => 'duplicate']);
110: 
111:                 return ['http' => 200, 'body' => ['received' => true, 'duplicate' => true]];
112:             }
113: 
114:             $awb = $this->firstValue($payload, ['awb_number', 'tracking_number', 'awb', 'data.awb_number', 'data.tracking_number']);
115:             $shipment = $awb !== null
116:                 ? Shipment::query()->where('awb_number', (string) $awb)->where('shipping_company_id', $company->id)->lockForUpdate()->first()
117:                 : null;
118:             if ($shipment === null) {
119:                 $log->update(['status' => 'failed', 'error' => 'بوليصة غير موجودة: '.($awb !== null ? (string) $awb : '—')]);
120: 
121:                 return ['http' => 200, 'body' => ['received' => false, 'message' => 'shipment not found']];
122:             }
123: 
124:             $status = $this->mapStatus($payload);
125:             if ($status === null) {
126:                 $log->update(['status' => 'ignored', 'error' => 'حالة شحن غير معروفة في الحمولة']);
127: 
128:                 return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
129:             }
130: 
131:             // Reject regressive status transitions or movements out of terminal states
132:             if (! $this->transitionAllowed($shipment->status, $status)) {
133:                 $log->update(['status' => 'ignored', 'error' => "حدث متأخر/متراجع: {$shipment->status->value} ← {$status->value}"]);
134: 
135:                 return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
136:             }
137: 
138:             $shipment->update(['status' => $status]);
139: 
140:             $note = null;
141:             $failed = false;
142:             if ($status === ShipmentStatus::Returned) {
143:                 // Return notification marks order for attention; physical stock re-entry happens upon manual warehouse intake
144:                 $order = $shipment->order;
145:                 if ($order !== null && in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true)) {
146:                     $order->update(['needs_attention' => true]);
147:                     $note = 'أبلغت شركة الشحن بمرتجع — بانتظار استلام المستودع الفعلي قبل إعادة البضاعة للمخزون';
148:                 }
149:             }
150:             if ($status === ShipmentStatus::Failed) {
151:                 // Mark order for staff review unless a newer replacement shipment is already active
152:                 $order = $shipment->order;
153:                 if ($order !== null && ! $this->hasNewerActiveShipment($shipment)) {
154:                     $order->update(['needs_attention' => true]);
155:                     $note = 'فشلت البوليصة لدى شركة الشحن — يحتاج متابعة أو إصدار بوليصة بديلة';
156:                 } else {
157:                     $note = 'بلاغ فشل لبوليصة استُبدلت بأحدث منها — يُتجاهل';
158:                 }
159:             }
160:             if ($status === ShipmentStatus::Delivered) {
161:                 $order = $shipment->order;
162:                 if ($order !== null && $order->status === OrderStatus::Shipped) {
163:                     try {
164:                         // Order delivery transitions settle cash-on-delivery payments automatically
165:                         $this->orders->changeStatus($order, OrderStatus::Delivered, null, 'تم التسليم — إشعار شركة الشحن');
166:                     } catch (Throwable $e) {
167:                         // Re-throw transient database exceptions so the webhook provider retries delivery
168:                         if ($this->isTransient($e)) {
169:                             throw $e;
170:                         }
171:                         // Non-transient errors flag the order for staff intervention without dropping the event
172:                         $order->update(['needs_attention' => true]);
173:                         $failed = true;
174:                         $note = 'تم تسليم البوليصة لكن تحويل الطلب فشل: '.$e->getMessage();
175:                     }
176:                 }
177:             }
178: 
179:             $log->update(['status' => $failed ? 'failed' : 'processed', 'error' => $note]);
180: 
181:             return ['http' => 200, 'body' => ['received' => true]];
182:         });
183:     }
184: 
185:     /**
186:      * Determine whether an exception is a transient concurrency or connection error eligible for webhook retry.
187:      */
188:     private function isTransient(Throwable $e): bool
189:     {
190:         if (! $e instanceof QueryException) {
191:             return false;
192:         }
193: 
194:         // Postgres codes: 55P03 = lock timeout, 40P01 = deadlock, 08006/08003 = connection failure
195:         return in_array($e->errorInfo[0] ?? $e->getCode(), ['55P03', '40P01', '08006', '08003'], true);
196:     }
197: 
198:     /**
199:      * Enforce status state machine rules to prevent lifecycle regression.
200:      */
201:     private function transitionAllowed(ShipmentStatus $current, ShipmentStatus $new): bool
202:     {
203:         if ($current === $new) {
204:             return true;
205:         }
206:         // Terminal states cannot transition further
207:         if (in_array($current, [ShipmentStatus::Delivered, ShipmentStatus::Returned], true)) {
208:             return false;
209:         }
210:         // Failed shipments may resume progression on redelivery attempts
211:         if ($new === ShipmentStatus::Failed || $current === ShipmentStatus::Failed) {
212:             return true;
213:         }
214:         // Returns are permissible from any non-terminal state
215:         if ($new === ShipmentStatus::Returned) {
216:             return true;
217:         }
218: 
219:         return (self::RANK[$new->value] ?? 0) >= (self::RANK[$current->value] ?? 0);
220:     }
221: 
222:     /**
223:      * Build an effective deduplication key by combining event ID with normalized status.
224:      *
225:      * Prevents carriers that reuse event IDs for subsequent milestone updates from being dropped as duplicates.
226:      */
227:     private function effectiveEventId(array $payload, string $rawBody): string
228:     {
229:         $eventId = $this->firstValue($payload, ['event_id', 'eventId', 'data.event_id']);
230:         if (is_scalar($eventId)) {
231:             $status = $this->rawStatus($payload);
232:             $suffix = $status !== null ? ':'.$status : '';
233: 
234:             return mb_substr((string) $eventId.$suffix, 0, 191);
235:         }
236: 
237:         $awb = $this->firstValue($payload, ['awb_number', 'tracking_number', 'awb', 'data.awb_number']);
238:         $status = $this->firstValue($payload, ['status', 'state', 'shipment_status', 'data.status']);
239:         $timestamp = $this->firstValue($payload, ['timestamp', 'occurred_at', 'updated_at', 'data.timestamp', 'data.updated_at']);
240:         if (is_scalar($awb) && is_scalar($status) && is_scalar($timestamp)) {
241:             return mb_substr($awb.'|'.$status.'|'.$timestamp, 0, 191);
242:         }
243: 
244:         return 'sha256:'.hash('sha256', $rawBody);
245:     }
246: 
247:     /**
248:      * Extract and normalize raw status string for key generation.
249:      */
250:     private function rawStatus(array $payload): ?string
251:     {
252:         $raw = $this->firstValue($payload, ['status', 'state', 'shipment_status', 'data.status', 'data.state']);
253:         if (! is_scalar($raw)) {
254:             return null;
255:         }
256: 
257:         return $this->mapStatus($payload)?->value
258:             ?? str_replace([' ', '-'], '_', strtolower(trim((string) $raw)));
259:     }
260: 
261:     private function mapStatus(array $payload): ?ShipmentStatus
262:     {
263:         $raw = $this->firstValue($payload, ['status', 'state', 'shipment_status', 'data.status', 'data.state']);
264:         if (! is_scalar($raw)) {
265:             return null;
266:         }
267:         $key = str_replace([' ', '-'], '_', strtolower(trim((string) $raw)));
268: 
269:         return self::STATUS_MAP[$key] ?? null;
270:     }
271: 
272:     /**
273:      * Check whether a newer active shipment exists for the order to ignore stale failure events.
274:      */
275:     private function hasNewerActiveShipment(Shipment $shipment): bool
276:     {
277:         return Shipment::query()
278:             ->where('order_id', $shipment->order_id)
279:             ->where('id', '>', $shipment->id)
280:             ->whereNotIn('status', [
281:                 ShipmentStatus::Failed->value,
282:                 ShipmentStatus::Returned->value,
283:                 ShipmentStatus::Delivered->value,
284:             ])
285:             ->exists();
286:     }
287: 
288:     /** @param array<int, string> $keys */
289:     private function firstValue(array $payload, array $keys): mixed
290:     {
291:         foreach ($keys as $key) {
292:             $value = data_get($payload, $key);
293:             if ($value !== null && $value !== '') {
294:                 return $value;
295:             }
296:         }
297: 
298:         return null;
299:     }
300: 
301:     private function startLog(string $provider, string $eventId, array $payload, ?string $ip): WebhookLog
302:     {
303:         return WebhookLog::create([
304:             'provider' => $provider,
305:             'event_id' => $eventId,
306:             // Sanitize sensitive credentials before persisting webhook body
307:             'payload' => WebhookLog::sanitizePayload($payload),
308:             'status' => 'received',
309:             'ip_address' => $ip,
310:         ]);
311:     }
312: 
313:     /**
314:      * Create a lightweight log entry for rejected requests without storing untrusted payload bytes.
315:      */
316:     private function slimLog(string $provider, string $eventId, string $status, ?string $error, ?string $ip): void
317:     {
318:         WebhookLog::create([
319:             'provider' => $provider,
320:             'event_id' => $eventId,
321:             'payload' => null,
322:             'signature_valid' => false,
323:             'status' => $status,
324:             'error' => $error,
325:             'ip_address' => $ip,
326:         ]);
327:     }
328: 
329:     private function alreadyProcessed(string $provider, WebhookLog $current): bool
330:     {
331:         return WebhookLog::query()
332:             ->where('provider', $provider)
333:             ->where('event_id', $current->event_id)
334:             ->where('id', '!=', $current->id)
335:             ->where('status', 'processed')
336:             ->exists();
337:     }
338: }
```

## File: bags-platform/frontend/packages/api-client/src/types/index.ts
```typescript
  1: export * from './inventory';
  2: 
  3: export interface ApiResponse<T = any> {
  4:   success?: boolean;
  5:   message?: string;
  6:   data: T;
  7:   meta?: {
  8:     current_page?: number;
  9:     last_page?: number;
 10:     per_page?: number;
 11:     total?: number;
 12:     from?: number;
 13:     to?: number;
 14:   };
 15: }
 16: 
 17: export interface PaginatedResponse<T = any> {
 18:   data: T[];
 19:   current_page: number;
 20:   last_page: number;
 21:   per_page: number;
 22:   total: number;
 23:   from?: number;
 24:   to?: number;
 25: }
 26: 
 27: export interface ApiErrorResponse {
 28:   message: string;
 29:   errors?: Record<string, string[]>;
 30:   status_code?: number;
 31: }
 32: 
 33: export interface UserPermissions {
 34:   id: number;
 35:   name: string;
 36:   email: string;
 37:   roles: string[];
 38:   permissions: string[];
 39: }
 40: 
 41: export interface AdminCategory {
 42:   id: number;
 43:   parent_id: number | null;
 44:   name_ar: string;
 45:   name_en: string;
 46:   slug: string;
 47:   description_ar: string | null;
 48:   description_en: string | null;
 49:   sort_order: number;
 50:   is_active: boolean;
 51:   image_url: string | null;
 52:   products_count?: number;      // مباشر فقط (مش شامل الأقسام الفرعية)
 53:   children?: AdminCategory[];
 54:   parent?: AdminCategory;
 55:   created_at: string;
 56: }
 57: 
 58: export interface CategoryPayload {
 59:   parent_id?: number | null;
 60:   name_ar?: string;
 61:   name_en?: string;
 62:   slug?: string | null;
 63:   description_ar?: string | null;
 64:   description_en?: string | null;
 65:   sort_order?: number;
 66:   is_active?: boolean;
 67:   image?: File | null;          // ملف جديد فقط
 68:   remove_image?: boolean;       // في التعديل فقط
 69: }
 70: 
 71: export interface CategoryReorderItem {
 72:   id: number;
 73:   parent_id: number | null;
 74:   sort_order: number;
 75: }
 76: 
 77: // Laravel Resource::collection(paginate()) shape: { data, links, meta }
 78: export interface ResourceCollection<T> {
 79:   data: T[];
 80:   links?: Record<string, string | null>;
 81:   meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
 82: }
 83: 
 84: export type ProductStatus = 'draft' | 'published' | 'archived';
 85: export type SalesChannel = 'retail' | 'wholesale' | 'both';
 86: 
 87: export interface AdminBrand {
 88:   id: number; name_ar: string; name_en: string; slug: string;
 89:   description_ar: string | null; description_en: string | null;
 90:   sort_order: number; is_active: boolean; logo_url: string | null;
 91:   products_count?: number; created_at: string;
 92: }
 93: 
 94: export interface AdminMedia {
 95:   id: number; collection: 'images' | 'video' | 'logo'; url: string;
 96:   thumb_url: string | null; medium_url: string | null;
 97:   mime_type: string; size: number; order: number; variant_id: number | null;
 98: }
 99: 
100: export interface AdminVariant {
101:   lock_version?: number;
102:   id: number; sku: string; barcode: string | null;
103:   color: { name_ar: string | null; name_en: string | null; hex: string | null };
104:   color_name_ar?: string | null; color_name_en?: string | null; color_hex?: string | null;
105:   size: string | null; capacity: string | null;
106:   cost_price?: string | null;          // بيختفي من غير products.view_cost
107:   retail_price: string | null; wholesale_price: string | null; compare_at_price: string | null;
108:   reorder_level: number | null; weight_kg: string | null; sort_order: number; is_active: boolean;
109:   media?: AdminMedia[];
110:   images?: AdminMedia[];
111: }
112: 
113: export interface AdminProduct {
114:   lock_version?: number;
115:   id: number; code: string; name_ar: string; name_en: string; slug: string;
116:   occasions: StoreOccasion[] | null; badge_en: string | null; badge_ar: string | null;
117:   description_ar: string | null; description_en: string | null;
118:   material_ar: string | null; material_en: string | null;
119:   dimensions: { length_cm: string | null; width_cm: string | null; height_cm: string | null };
120:   weight_kg: string | null; warranty_months: number | null; warranty_details: string | null;
121:   country_of_origin: string | null; status: ProductStatus; sales_channel: SalesChannel;
122:   is_featured: boolean; published_at: string | null;
123:   category?: AdminCategory; brand?: AdminBrand | null; supplier_id: number | null;
124:   main_image: string | null; images?: AdminMedia[]; video?: AdminMedia | null;
125:   variants_count?: number; min_retail_price?: string | null; max_retail_price?: string | null;
126:   variants?: AdminVariant[]; created_at: string; updated_at: string;
127: }
128: 
129: // نفس حقول الكتابة اللي في Store/Update Requests
130: export interface BrandPayload {
131:   name_ar?: string; name_en?: string; slug?: string | null;
132:   description_ar?: string | null; description_en?: string | null;
133:   sort_order?: number; is_active?: boolean;
134:   logo?: File | null;                 // ملف جديد فقط
135:   remove_logo?: boolean;              // في التعديل فقط
136: }
137: 
138: export interface ProductPayload {
139:   expected_version?: number;
140:   expected_updated_at?: string;
141:   category_id?: number; brand_id?: number | null; supplier_id?: number | null;
142:   create_key?: string; // F9: idempotency key for product creation
143:   name_ar?: string; name_en?: string; slug?: string | null;
144:   description_ar?: string | null; description_en?: string | null;
145:   material_ar?: string | null; material_en?: string | null;
146:   length_cm?: string | number | null; width_cm?: string | number | null; height_cm?: string | number | null;
147:   weight_kg?: string | number | null; warranty_months?: number | null; warranty_details?: string | null;
148:   country_of_origin?: string | null; sales_channel?: SalesChannel; is_featured?: boolean;
149: }
150: 
151: export type StoreBannerPosition = 'main_slider' | 'middle_banner' | 'popup';
152: export interface PublicBranding {
153:   name: string;
154:   logo: string | null;
155:   email: string | null;
156:   phone: string | null;
157:   address: string | null;
158: }
159: 
160: export interface StoreCart {
161:   id: number | null;
162:   cart_token: string | null;
163:   customer_id: number | null;
164:   items: Array<{
165:     id: number; product_variant_id: number; product_id: number; product_name: string | null; sku: string;
166:     qty: number; unit_price: number | null; base_price: number | null; line_discount: number; line_total: number;
167:     available: number; max_qty: number; priced: boolean;
168:     product_slug: string | null; product_name_en: string | null; image: string | null;
169:     color: string | null; size: string | null;
170:   }>;
171:   totals: { subtotal: number; pricing_discount: number; items_total: number; coupon_discount: number; total: number; coupon_code: string | null };
172: }
173: 
174: export interface StoreCity { id: number; name_ar: string; name_en: string | null; shipping_cost: string | number }
175: 
176: export type CheckoutPaymentMethod = 'cod' | 'wallet' | 'credit';
177: 
178: export interface CheckoutPayload {
179:   recipient_name?: string; recipient_phone?: string; recipient_email?: string;
180:   city_id: number; shipping_address_line: string; shipping_notes?: string;
181:   expected_total?: number; payment_method: CheckoutPaymentMethod;
182: }
183: 
184: export interface StoreOrderItem {
185:   id: number; product_variant_id: number; product_name: string; sku: string;
186:   qty: number; unit_price: number | string; discount_amount: number | string; line_total: number | string;
187: }
188: 
189: export interface StoreOrder {
190:   id: number; order_number: string; channel: string; status: string;
191:   payment_method: string; payment_status: string;
192:   recipient_name: string | null; recipient_phone: string | null;
193:   city_id: number | null; shipping_address_line: string | null;
194:   subtotal: number | string; discount_amount: number | string; coupon_code: string | null;
195:   shipping_cost: number | string; cod_fee: number | string; tax_amount: number | string; grand_total: number | string;
196:   items: StoreOrderItem[]; created_at: string;
197: }
198: 
199: export interface CheckoutResponse { data: StoreOrder; payment_url: string | null; message: string }
200: 
201: export interface ShippingOption { key: string; label: string; cost: number; cod_fee: number; currency: string; estimated_days: string | null }
202: 
203: export interface ShippingQuotePreview {
204:   items_total: number; coupon_discount: number; shipping: number;
205:   cod_fee: number; tax: number; grand_total: number;
206: }
207: 
208: export interface ShippingOptionsResponse {
209:   city: { id: number; name_ar: string; name_en: string | null };
210:   cart_total: number;
211:   payment_methods: string[];
212:   preview: ShippingQuotePreview | null;
213:   options: ShippingOption[];
214: }
215: 
216: export interface CartCouponResponse { cart: StoreCart; coupon_discount: number }
217: 
218: export interface TrackOrderResponse {
219:   order_number: string; status: string;
220:   items: Array<{ product_name: string; qty: number }>;
221:   status_history: Array<{ status: string; at: string }>;
222:   created_at: string;
223: }
224: export type StoreBannerLinkType = 'product' | 'category' | 'brand' | 'external' | 'none';
225: 
226: export interface StoreBanner {
227:   id: number;
228:   title: string;
229:   image: string;
230:   position: StoreBannerPosition;
231:   link_type: StoreBannerLinkType;
232:   link_value: string | null;
233:   href: string | null;
234:   is_external: boolean;
235:   kicker: string | null;
236:   headline: string | null;
237:   headline_bold: string | null;
238:   description: string | null;
239:   cta_label: string | null;
240:   bg_color: string | null;
241: }
242: 
243: export type StoreOccasion = 'casual' | 'work' | 'weekend' | 'evening';
244: export type StoreSort = 'featured' | 'newest' | 'best_selling' | 'price_asc' | 'price_desc' | 'name';
245: 
246: export interface StoreImage { id: number; url: string; thumb: string | null }
247: export interface StoreColor { name_en: string | null; name_ar: string | null; hex: string | null }
248: 
249: export interface StoreCategory {
250:   id: number; name_ar: string; name_en: string | null; slug: string;
251:   description_ar: string | null; description_en: string | null;
252:   image: string | null; direct_products_count?: number; products_count: number;
253:   children: StoreCategory[];
254: }
255: 
256: export interface StoreProductCard {
257:   id: number;
258:   name_ar: string;
259:   name_en: string | null;
260:   slug: string;
261:   image: string | null;
262:   brand: null;
263:   category?: { id: number; name_ar: string; name_en: string | null; slug: string } | null;
264:   price: number | string | null;
265:   default_variant_id?: number | null;
266:   variant_count?: number;
267:   old_price: number | string | null;
268:   discount_percentage: number | null;
269:   is_featured: boolean;
270:   /** F19: removed from the API payload; kept optional for stale caches. */
271:   sales_count?: number;
272:   in_stock: boolean;
273:   is_new?: boolean; badge_en?: string | null; badge_ar?: string | null; colors?: StoreColor[];
274: }
275: 
276: export interface StoreProductVariant {
277:   id: number; sku: string;
278:   color_name_ar: string | null; color_name_en: string | null; color_hex: string | null;
279:   size: string | null; capacity: string | null;
280:   images: StoreImage[];
281:   price: number | string | null; old_price: number | string | null; in_stock: boolean;
282:   max_qty: number;
283: }
284: 
285: export interface StoreProduct extends StoreProductCard {
286:   description_ar: string | null; description_en: string | null;
287:   material_ar: string | null; material_en: string | null;
288:   warranty_months: number | null; warranty_details?: string | null; country_of_origin: string | null;
289:   occasions: StoreOccasion[];
290:   dimensions?: { length_cm: number | null; width_cm: number | null; height_cm: number | null; weight_kg: number | null };
291:   images: StoreImage[]; variants: StoreProductVariant[];
292: }
293: 
294: export interface StoreProductListParams {
295:   category?: string; color?: string; price_min?: number; price_max?: number;
296:   occasions?: StoreOccasion[]; in_stock?: 0 | 1; search?: string;
297:   sort?: StoreSort; page?: number; per_page?: number;
298: }
299: 
300: export interface StoreCatalogFilters {
301:   total: number;
302:   colors: Array<{ id: string; name_en: string; name_ar: string | null; hex: string | null; count: number }>;
303:   price_ranges: Array<{ id: string; min: number | null; max: number | null; count: number }>;
304:   occasions: Array<{ id: StoreOccasion; count: number }>;
305: }
306: 
307: export interface StoreHomeData {
308:   banners: StoreBanner[];
309:   latest_products: StoreProductCard[];
310:   best_sellers: StoreProductCard[];
311:   discounted_products: StoreProductCard[];
312: }
313: 
314: export interface VariantPayload {
315:   expected_version?: number;
316:   sku?: string; barcode?: string | null;
317:   color_name_ar?: string | null; color_name_en?: string | null; color_hex?: string | null;
318:   size?: string | null; capacity?: string | null;
319:   cost_price?: string | number | null; retail_price?: string | number | null;
320:   wholesale_price?: string | number | null; compare_at_price?: string | number | null;
321:   reorder_level?: number | null; weight_kg?: string | number | null;
322:   sort_order?: number | null; is_active?: boolean;
323: }
```

## File: bags-platform/backend/routes/api/store.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use App\Http\Controllers\Api\V1\AttachmentController;
 6: use App\Http\Controllers\Api\V1\Shared\NotificationController;
 7: use App\Http\Controllers\Api\V1\Store\AccountController;
 8: use App\Http\Controllers\Api\V1\Store\AuthController;
 9: use App\Http\Controllers\Api\V1\Store\BrandingController;
10: use App\Http\Controllers\Api\V1\Store\CartController;
11: use App\Http\Controllers\Api\V1\Store\CatalogController;
12: use App\Http\Controllers\Api\V1\Store\CheckoutController;
13: use App\Http\Controllers\Api\V1\Store\HomeController;
14: use App\Http\Controllers\Api\V1\Store\OrderController as StoreOrderController;
15: use App\Http\Controllers\Api\V1\Store\ProductController;
16: use App\Http\Controllers\Api\V1\Store\ReturnController;
17: use App\Http\Controllers\Api\V1\Store\WalletController;
18: use App\Http\Middleware\ProtectStorefrontCaching;
19: use Illuminate\Support\Facades\Route;
20: 
21: // مسارات المصادقة للعميل القطاعي
22: Route::prefix('auth')->name('auth.')->group(function (): void {
23:     Route::get('registration-options', [AuthController::class, 'registrationOptions'])->middleware('throttle:store-branding')->name('registration-options');
24:     Route::post('register', [AuthController::class, 'register'])->middleware('throttle:customer-registration')->name('register');
25:     Route::post('phone-verification/send', [AuthController::class, 'sendPhoneVerification'])->middleware('throttle:customer-phone-send')->name('phone-verification.send');
26:     Route::post('phone-verification/verify', [AuthController::class, 'verifyPhone'])->middleware('throttle:customer-phone-verify')->name('phone-verification.verify');
27:     // حد محاولات الدخول (مرحلة 5) محفوظ فوق الشكل المطلوب: حماية من التخمين
28:     // M5: مسار legacy — يُعطَّل مع LEGACY_LOGIN_ENABLED=false لصالح /auth/login.
29:     if (config('auth.legacy_login_enabled', false)) {
30:         Route::post('login', [AuthController::class, 'login'])->middleware('throttle:customer-login')->name('login');
31:     }
32:     Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:customer-password-reset')->name('forgot-password');
33:     Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:customer-reset-confirm')->name('reset-password');
34: 
35:     Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
36:         Route::post('logout', [AuthController::class, 'logout'])->name('logout');
37:         Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
38:         // M3: notifications.id is uuid — non-uuid values stay 404 instead of
39:         // throwing Postgres 22P02 (500 + log spam) via findOrFail.
40:         Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id')->name('notifications.read');
41:     });
42: });
43: 
44: Route::get('branding', [BrandingController::class, 'show'])->middleware('throttle:store-branding')->name('branding');
45: 
46: // مسارات تصفح المتجر (متاحة للزوار والعملاء المسجلين)
47: Route::middleware(['throttle:store-catalog', ProtectStorefrontCaching::class])->group(function (): void {
48:     Route::get('home', [HomeController::class, 'index'])->name('home');
49:     Route::get('categories', [CatalogController::class, 'categories'])->name('categories');
50:     Route::get('brands', [CatalogController::class, 'brands'])->name('brands');
51:     Route::get('cities', [CatalogController::class, 'cities'])->name('cities');
52: 
53:     Route::get('catalog/filters', [CatalogController::class, 'filters'])->name('catalog.filters');
54:     Route::get('showcase', [ProductController::class, 'showcase'])->name('showcase');
55:     Route::get('products', [ProductController::class, 'index'])->name('products.index');
56:     Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
57:     Route::get('products/{slug}/similar', [ProductController::class, 'similar'])->name('products.similar');
58: });
59: 
60: // معرّفات بنود السلة وطلبات الحساب رقمية فقط: غير الرقمي 404 لا 500 (M7)
61: Route::pattern('item', '[0-9]{1,18}');
62: Route::pattern('order', '[0-9]{1,18}');
63: 
64: // مسارات السلة وإتمام الطلب (تعمل للزائر بـ X-Cart-Session وللعميل المسجل بـ Sanctum Token)
65: Route::middleware('throttle:cart')->prefix('cart')->name('cart.')->group(function (): void {
66:     Route::get('items', [CartController::class, 'index'])->name('items.index');
67:     Route::post('items', [CartController::class, 'store'])->name('items.store');
68:     Route::delete('items', [CartController::class, 'clear'])->name('items.clear');
69:     Route::put('items/{item}', [CartController::class, 'update'])->name('items.update');
70:     Route::delete('items/{item}', [CartController::class, 'destroy'])->name('items.destroy');
71:     Route::post('coupon', [CartController::class, 'applyCoupon'])->middleware('throttle:coupon')->name('coupon.apply');
72:     Route::delete('coupon', [CartController::class, 'removeCoupon'])->name('coupon.remove');
73: });
74: 
75: Route::middleware('throttle:checkout')->prefix('checkout')->name('checkout.')->group(function (): void {
76:     Route::post('shipping-options', [CheckoutController::class, 'shippingOptions'])->name('shipping-options');
77:     Route::post('/', [CheckoutController::class, 'store'])->name('store');
78: });
79: 
80: // تتبع الطلب للزائر والعميل — حد صارم ضد التعداد (M5)
81: Route::get('orders/{number}/track', [StoreOrderController::class, 'track'])
82:     ->middleware('throttle:order-tracking')->name('orders.track');
83: 
84: // مسارات حساب العميل المسجل فقط
85: Route::middleware(['auth:sanctum', 'auth.customer'])->prefix('account')->name('account.')->group(function (): void {
86:     Route::get('addresses', [AccountController::class, 'addresses'])->name('addresses.index');
87:     Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');
88:     Route::get('orders', [StoreOrderController::class, 'index'])->name('orders.index');
89:     Route::post('orders/{order}/cancel', [StoreOrderController::class, 'cancel'])->name('orders.cancel');
90:     // المرحلة 9: تقديم طلب إرجاع لطلب مُسلَّم يخص العميل
91:     Route::get('return-items/{return_item}/attachments/{attachment}', [AttachmentController::class, 'returnFile'])->whereNumber(['return_item', 'attachment'])->name('return-items.attachments.show');
92:     Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
93:     Route::get('returns/{return_request}', [ReturnController::class, 'show'])->whereNumber('return_request')->name('returns.show');
94:     Route::post('returns', [ReturnController::class, 'store'])->middleware('throttle:10,60')->name('returns.store');
95: });
```

## File: bags-platform/frontend/packages/api-client/src/endpoints/index.ts
```typescript
  1: export * from './inventory';
  2: export * from './operations';
  3: 
  4: import { createApiClient, defaultApiClient as apiClient } from '../axios-instance';
  5: import { SLOW_REQUEST_TIMEOUT } from './operations/http';
  6: import type { CartCouponResponse, CheckoutPayload, CheckoutResponse, PublicBranding, ShippingOptionsResponse, StoreCart, StoreCity, StoreOrder, TrackOrderResponse } from '../types';
  7: 
  8: const publicApiClient = createApiClient({ getToken: () => null, onUnauthorized: () => {} });
  9: 
 10: export const brandingEndpoints = {
 11:   get: (signal?: AbortSignal) => publicApiClient.get<{ data: PublicBranding }>('/api/v1/store/branding', {
 12:     baseURL: apiClient.defaults.baseURL,
 13:     signal,
 14:     timeout: 5000,
 15:   }).then((response) => response.data.data),
 16: };
 17: 
 18: const cartHeaders = (session: string | null) => session ? { 'X-Cart-Session': session } : {};
 19: export const cartEndpoints = {
 20:   clear: (session: string | null) => apiClient.delete<{ data: StoreCart }>('/api/v1/store/cart/items', { headers: cartHeaders(session) }).then(response => response.status === 204 ? cartEndpoints.get(session) : response.data.data),
 21:   get: (session: string | null) => apiClient.get<{ data: StoreCart }>('/api/v1/store/cart/items', { headers: cartHeaders(session) }).then(response => response.data.data),
 22:   add: (variantId: number, qty: number, session: string | null) => apiClient.post<{ data: StoreCart }>('/api/v1/store/cart/items', { product_variant_id: variantId, qty }, { headers: cartHeaders(session) }).then(response => response.data.data),
 23:   update: (id: number, qty: number, session: string | null) => apiClient.put<{ data: StoreCart }>(`/api/v1/store/cart/items/${id}`, { qty }, { headers: cartHeaders(session) }).then(response => response.data.data),
 24:   remove: (id: number, session: string | null) => apiClient.delete<{ data: StoreCart }>(`/api/v1/store/cart/items/${id}`, { headers: cartHeaders(session) }).then(response => response.status === 204 ? cartEndpoints.get(session) : response.data.data),
 25:   applyCoupon: (code: string, session: string | null) => apiClient.post<{ data: CartCouponResponse }>('/api/v1/store/cart/coupon', { code }, { headers: cartHeaders(session) }).then(response => response.data.data),
 26:   removeCoupon: (session: string | null) => apiClient.delete<{ data: StoreCart }>('/api/v1/store/cart/coupon', { headers: cartHeaders(session) }).then(response => response.data.data),
 27: };
 28: import type { AdminBrand, AdminCategory, AdminMedia, AdminProduct, AdminVariant, BrandPayload, CategoryPayload, CategoryReorderItem, ProductPayload, ProductStatus, ResourceCollection, SalesChannel, StoreCatalogFilters, StoreCategory, StoreHomeData, StoreProduct, StoreProductCard, StoreProductListParams, VariantPayload } from '../types';
 29: 
 30: export type ActorType = 'staff' | 'supplier' | 'customer';
 31: export type Destination = 'admin' | 'pos' | 'supplier' | 'wholesale' | 'wholesale_pending' | 'retail';
 32: 
 33: export interface LoginRedirect {
 34:   app: 'web' | 'backoffice';
 35:   path: string;
 36:   destination?: Destination;
 37: }
 38: export interface UnifiedLoginResponse {
 39:   message: string;
 40:   actor: { type: ActorType; subtype: Destination; status: string | null; customer_type?: string };
 41:   redirect: LoginRedirect;
 42:   token: string | null;
 43:   handoff_code: string | null;
 44:   data: Record<string, unknown>;
 45:   cart_merged?: boolean | null;
 46: }
 47: export interface HandoffExchangeResponse {
 48:   data: StaffUserResource;
 49:   token: string;
 50:   redirect: LoginRedirect;
 51: }
 52: export interface StaffUserResource {
 53:   id: number;
 54:   name: string;
 55:   email: string;
 56:   phone: string | null;
 57:   branch_id: number | null;
 58:   branch: { id: number; name: string } | null;
 59:   is_active: boolean;
 60:   roles: { id: number; name: string }[];
 61:   permissions: string[];
 62: }
 63: 
 64: export const authEndpoints = {
 65:   login: (body: { login: string; password: string; redirect?: string }, cartSession?: string | null) =>
 66:     apiClient
 67:       .post<UnifiedLoginResponse>('/api/v1/auth/login', body, {
 68:         headers: cartSession ? { 'X-Cart-Session': cartSession } : {},
 69:       })
 70:       .then((r) => r.data),
 71:   me: () => apiClient.get('/api/v1/auth/me').then((r) => r.data),
 72:   logout: () => apiClient.post('/api/v1/auth/logout'),
 73:   exchangeHandoff: (code: string) =>
 74:     apiClient.post<HandoffExchangeResponse>('/api/v1/auth/handoff/exchange', { code }).then((r) => r.data),
 75:   staffMe: () => apiClient.get<{ data: StaffUserResource }>('/api/v1/admin/me').then((r) => r.data.data),
 76:   staffLogout: () => apiClient.post('/api/v1/admin/auth/logout'),
 77: };
 78: 
 79: export const API_ENDPOINTS = {
 80:   AUTH: {
 81:     LOGIN: '/api/v1/auth/login',
 82:     LOGOUT: '/api/v1/auth/logout',
 83:     ME: '/api/v1/auth/me',
 84:   },
 85:   ADMIN: {
 86:     DASHBOARD: '/api/v1/admin/dashboard',
 87:     PRODUCTS: '/api/v1/admin/products',
 88:     CATEGORIES: '/api/v1/admin/categories',
 89:     ORDERS: '/api/v1/admin/orders',
 90:     CUSTOMERS: '/api/v1/admin/customers',
 91:     INVENTORY: '/api/v1/admin/inventory',
 92:     SUPPLIERS: '/api/v1/admin/suppliers',
 93:     REPORTS: '/api/v1/admin/reports',
 94:     TREASURY: '/api/v1/admin/treasuries',
 95:     EXPENSES: '/api/v1/admin/expenses',
 96:   },
 97:   POS: {
 98:     AUTH_LOGIN: '/api/v1/pos/auth/login',
 99:     AUTH_LOGOUT: '/api/v1/pos/auth/logout',
100:     SHIFTS_CURRENT: '/api/v1/pos/shifts/current',
101:     SHIFTS_OPEN: '/api/v1/pos/shifts/open',
102:     SHIFTS_CLOSE: '/api/v1/pos/shifts/close',
103:     SALES: '/api/v1/pos/sales',
104:     STOCK: '/api/v1/pos/stock',
105:   },
106:   STORE: {
107:     HOME: '/api/v1/store/home',
108:     PRODUCTS: '/api/v1/store/products',
109:     CATEGORIES: '/api/v1/store/categories',
110:     CATALOG_FILTERS: '/api/v1/store/catalog/filters',
111:     SHOWCASE: '/api/v1/store/showcase',
112:     CART: '/api/v1/store/cart',
113:     CHECKOUT: '/api/v1/store/checkout',
114:     ORDERS: '/api/v1/store/orders',
115:   },
116:   WHOLESALE: {
117:     PRODUCTS: '/api/v1/wholesale/products',
118:     QUICK_ORDER: '/api/v1/wholesale/quick-order',
119:     QUOTATIONS: '/api/v1/wholesale/quotations',
120:     INVOICES: '/api/v1/wholesale/invoices',
121:     STATEMENT: '/api/v1/wholesale/statement',
122:   },
123:   SUPPLIER: {
124:     PURCHASE_ORDERS: '/api/v1/supplier/purchase-orders',
125:     DELIVERIES: '/api/v1/supplier/deliveries',
126:     INVOICES: '/api/v1/supplier/invoices',
127:   },
128: } as const;
129: 
130: export const storeEndpoints = {
131:   home: () => apiClient.get<{ data: StoreHomeData }>(API_ENDPOINTS.STORE.HOME).then((r) => r.data.data),
132:   categories: (signal?: AbortSignal) =>
133:     apiClient.get<{ data: StoreCategory[] }>(API_ENDPOINTS.STORE.CATEGORIES, { signal }).then((r) => r.data.data),
134:   products: (params: StoreProductListParams, signal?: AbortSignal) =>
135:     apiClient.get<ResourceCollection<StoreProductCard>>(API_ENDPOINTS.STORE.PRODUCTS, { params, signal }).then((r) => r.data),
136:   product: (slug: string, signal?: AbortSignal) =>
137:     apiClient.get<{ data: StoreProduct }>(`${API_ENDPOINTS.STORE.PRODUCTS}/${encodeURIComponent(slug)}`, { signal }).then((r) => r.data.data),
138:   similar: (slug: string, signal?: AbortSignal) =>
139:     apiClient.get<{ data: StoreProductCard[] }>(`${API_ENDPOINTS.STORE.PRODUCTS}/${encodeURIComponent(slug)}/similar`, { signal }).then((r) => r.data.data),
140:   filters: (params: StoreProductListParams, signal?: AbortSignal) =>
141:     apiClient.get<{ data: StoreCatalogFilters }>(API_ENDPOINTS.STORE.CATALOG_FILTERS, { params, signal }).then((r) => r.data.data),
142:   showcase: (signal?: AbortSignal) =>
143:     apiClient.get<{ data: StoreProduct[] }>(API_ENDPOINTS.STORE.SHOWCASE, { signal }).then((r) => r.data.data),
144: };
145: 
146: export const checkoutEndpoints = {
147:   cities: () => apiClient.get<{ data: StoreCity[] }>('/api/v1/store/cities').then((r) => r.data.data),
148:   shippingOptions: (body: { city_id: number; payment_method?: string }, session: string | null, signal?: AbortSignal) =>
149:     apiClient.post<{ data: ShippingOptionsResponse }>('/api/v1/store/checkout/shipping-options', body, { headers: cartHeaders(session), signal }).then((r) => r.data.data),
150:   place: (body: CheckoutPayload, session: string | null, idempotencyKey: string) =>
151:     apiClient.post<CheckoutResponse>('/api/v1/store/checkout', body, {
152:       headers: { ...cartHeaders(session), 'Idempotency-Key': idempotencyKey },
153:     }).then((r) => r.data),
154:   track: (number: string, phone?: string) => apiClient.get<{ data: TrackOrderResponse }>(`/api/v1/store/orders/${encodeURIComponent(number)}/track`, { params: phone ? { phone } : {} }).then((r) => r.data.data),
155: };
156: 
157: export const accountEndpoints = {
158:   orders: () => apiClient.get<{ data: StoreOrder[] }>('/api/v1/store/account/orders').then((r) => r.data.data),
159:   cancelOrder: (id: number) => apiClient.post<{ data: StoreOrder }>(`/api/v1/store/account/orders/${id}/cancel`).then((r) => r.data.data),
160:   wallet: (page = 1, filters: { direction?: 'credit' | 'debit'; type?: string; from?: string; to?: string } = {}) => apiClient.get<{ balance: number | string; transactions: { data: Array<{ id: number; type: string; type_label?: string | null; amount: number | string; balance_after: number | string; reference_type: string | null; reference_id: number | null; reference_label?: string | null; created_at: string }>; current_page: number; last_page: number; total: number } }>('/api/v1/store/account/wallet', { params: { page, ...filters } }).then((r) => r.data),
161:   returns: (page = 1) => apiClient.get<{ data: unknown[]; meta: { current_page: number; last_page: number; total: number } }>(`/api/v1/store/account/returns?page=${page}`).then((r) => r.data),
162:   returnDetail: (id: number) => apiClient.get<{ data: unknown }>(`/api/v1/store/account/returns/${id}`).then((r) => r.data.data),
163:   createReturn: (fd: FormData) => apiClient.post<{ data: { id: number } }>('/api/v1/store/account/returns', fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data),
164:   addresses: () => apiClient.get<{ data: Array<{ id: number; city_id: number; city_name_ar: string | null; city_name_en: string | null; address_line: string; is_default: boolean }> }>('/api/v1/store/account/addresses').then((r) => r.data.data),
165: };
166: 
167: const W = '/api/v1/wholesale';
168: export const wholesaleEndpoints = {
169:   applicationStatus: (signal?: AbortSignal) => apiClient.get(`${W}/auth/application-status`, { signal }).then((r) => r.data.data),
170:   dashboard: (signal?: AbortSignal) => apiClient.get(`${W}/dashboard`, { signal }).then((r) => r.data.data),
171:   products: (p: { search?: string; page?: number; per_page?: number }, signal?: AbortSignal) => apiClient.get(`${W}/products`, { params: p, signal }).then((r) => r.data),
172:   exportPriceList: (format: 'pdf' | 'xlsx') => apiClient.get(`${W}/price-list/export`, { params: { format }, responseType: 'blob', timeout: SLOW_REQUEST_TIMEOUT }),
173:   quickLookup: (items: Array<{ sku?: string; barcode?: string; qty: number }>) => apiClient.post(`${W}/quick-order/lookup`, { items }).then((r) => r.data),
174:   quickImport: (file: File) => { const f = new FormData(); f.append('file', file); return apiClient.post(`${W}/quick-order/import`, f, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data); },
175:   quickAddToCart: (items: Array<{ sku: string; qty: number }>) => apiClient.post(`${W}/quick-order/add-to-cart`, { items }).then((r) => r.data.data),
176:   orders: (p: { group?: string; page?: number }, signal?: AbortSignal) => apiClient.get(`${W}/orders`, { params: p, signal }).then((r) => r.data),
177:   order: (id: number, signal?: AbortSignal) => apiClient.get(`${W}/orders/${id}`, { signal }).then((r) => r.data.data),
178:   reorder: (id: number) => apiClient.post(`${W}/orders/${id}/reorder`).then((r) => r.data.data),
179:   invoices: (p: { payment_status?: 'paid' | 'unpaid'; page?: number }, signal?: AbortSignal) => apiClient.get(`${W}/invoices`, { params: p, signal }).then((r) => r.data),
180:   invoicePdf: (id: number) => apiClient.get(`${W}/invoices/${id}/pdf`, { responseType: 'blob' }),
181:   statement: (p: { from?: string; to?: string }, signal?: AbortSignal) => apiClient.get(`${W}/statement`, { params: p, signal }).then((r) => r.data.data),
182:   notices: (p: { status?: string; page?: number }, signal?: AbortSignal) => apiClient.get(`${W}/payment-notices`, { params: p, signal }).then((r) => r.data),
183:   notice: (id: number, signal?: AbortSignal) => apiClient.get(`${W}/payment-notices/${id}`, { signal }).then((r) => r.data.data),
184:   outstanding: (signal?: AbortSignal) => apiClient.get(`${W}/payment-notices/outstanding`, { signal }).then((r) => r.data.data),
185:   createNotice: (fd: FormData) => apiClient.post(`${W}/payment-notices`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data),
186:   noticeReceipt: (id: number) => apiClient.get(`${W}/payment-notices/${id}/receipt`, { responseType: 'blob', params: { signed: 1 } }),
187:   quotes: (p: { status?: string; page?: number }, signal?: AbortSignal) => apiClient.get(`${W}/quotations`, { params: p, signal }).then((r) => r.data),
188:   quote: (id: number, signal?: AbortSignal) => apiClient.get(`${W}/quotations/${id}`, { signal }).then((r) => r.data.data),
189:   createQuote: (body: unknown) => apiClient.post(`${W}/quotations`, body).then((r) => r.data.data),
190:   updateQuote: (id: number, body: unknown) => apiClient.put(`${W}/quotations/${id}`, body).then((r) => r.data.data),
191:   deleteQuote: (id: number) => apiClient.delete(`${W}/quotations/${id}`),
192:   submitQuote: (id: number) => apiClient.post(`${W}/quotations/${id}/submit`).then((r) => r.data.data),
193:   acceptQuote: (id: number) => apiClient.post(`${W}/quotations/${id}/accept`).then((r) => r.data.data),
194:   rejectQuote: (id: number) => apiClient.post(`${W}/quotations/${id}/reject`).then((r) => r.data.data),
195:   notifications: (page = 1, signal?: AbortSignal) => apiClient.get(`${W}/notifications`, { params: { page }, signal }).then((r) => r.data),
196:   readNotification: (id: string) => apiClient.post(`${W}/notifications/${id}/read`).then((r) => r.data),
197:   readAllNotifications: () => apiClient.post(`${W}/notifications/read-all`).then((r) => r.data),
198: };
199: 
200: const MULTIPART = { headers: { 'Content-Type': 'multipart/form-data' } };
201: 
202: function categoryFormData(p: CategoryPayload, spoofPut = false): FormData {
203:   const fd = new FormData();
204:   if (spoofPut) fd.append('_method', 'PUT');
205:   const put = (key: string, v: unknown) => {
206:     if (v === undefined) return;                       // مش هيتبعت
207:     if (v === null) return fd.append(key, '');         // Laravel يحوّلها null
208:     if (typeof v === 'boolean') return fd.append(key, v ? '1' : '0'); // مهم: مش "true"
209:     if (v instanceof Blob) return fd.append(key, v);
210:     fd.append(key, String(v));
211:   };
212:   put('parent_id', p.parent_id);
213:   put('name_ar', p.name_ar);
214:   put('name_en', p.name_en);
215:   put('slug', p.slug);
216:   put('description_ar', p.description_ar);
217:   put('description_en', p.description_en);
218:   put('sort_order', p.sort_order);
219:   put('is_active', p.is_active);
220:   if (p.image) put('image', p.image);
221:   if (p.remove_image) put('remove_image', true);
222:   return fd;
223: }
224: 
225: const CAT = API_ENDPOINTS.ADMIN.CATEGORIES; // '/api/v1/admin/categories'
226: 
227: export const categoriesApi = {
228:   tree: (signal?: AbortSignal) =>
229:     apiClient.get<{ data: AdminCategory[] }>(CAT, { params: { tree: 1 }, signal }).then((r) => r.data.data),
230:   show: (id: number) =>
231:     apiClient.get<{ data: AdminCategory }>(`${CAT}/${id}`).then((r) => r.data.data),
232:   create: (p: CategoryPayload, onProgress?: (pct: number) => void) =>
233:     apiClient.post<{ data: AdminCategory }>(CAT, categoryFormData(p), {
234:       ...MULTIPART,
235:       onUploadProgress: (e) => onProgress?.(Math.round((e.loaded / (e.total || 1)) * 100)),
236:     }).then((r) => r.data.data),
237:   update: (id: number, p: CategoryPayload, onProgress?: (pct: number) => void) =>
238:     apiClient.post<{ data: AdminCategory }>(`${CAT}/${id}`, categoryFormData(p, true), {
239:       ...MULTIPART,
240:       onUploadProgress: (e) => onProgress?.(Math.round((e.loaded / (e.total || 1)) * 100)),
241:     }).then((r) => r.data.data),
242:   remove: (id: number) =>
243:     apiClient.delete<{ message: string }>(`${CAT}/${id}`).then((r) => r.data),
244:   reorder: (items: CategoryReorderItem[]) =>
245:     apiClient.put<{ message: string }>(`${CAT}/reorder`, { items }).then((r) => r.data),
246: };
247: 
248: const BR = '/api/v1/admin/brands';
249: const PR = '/api/v1/admin/products';
250: 
251: function progress(cb?: (n: number) => void) {
252:   return (e: { loaded: number; total?: number }) => cb?.(Math.round((e.loaded / (e.total || 1)) * 100));
253: }
254: 
255: export interface BrandListParams { search?: string; is_active?: 0 | 1; page?: number; per_page?: number }
256: export interface ProductListParams {
257:   search?: string; category_id?: number; include_children?: 0 | 1; brand_id?: number;
258:   status?: ProductStatus; sales_channel?: SalesChannel; is_featured?: 0 | 1;
259:   sort?: 'latest' | 'oldest' | 'name'; page?: number; per_page?: number;
260: }
261: 
262: function brandFormData(p: BrandPayload, spoofPut = false): FormData {
263:   const fd = new FormData();
264:   if (spoofPut) fd.append('_method', 'PUT');
265:   const put = (key: string, v: unknown) => {
266:     if (v === undefined) return;                       // مش هيتبعت
267:     if (v === null) return fd.append(key, '');         // Laravel يحوّلها null
268:     if (typeof v === 'boolean') return fd.append(key, v ? '1' : '0'); // مهم: مش "true"
269:     if (v instanceof Blob) return fd.append(key, v);
270:     fd.append(key, String(v));
271:   };
272:   put('name_ar', p.name_ar);
273:   put('name_en', p.name_en);
274:   put('slug', p.slug);
275:   put('description_ar', p.description_ar);
276:   put('description_en', p.description_en);
277:   put('sort_order', p.sort_order);
278:   put('is_active', p.is_active);
279:   if (p.logo) put('logo', p.logo);
280:   if (p.remove_logo) put('remove_logo', true);
281:   return fd;
282: }
283: 
284: export const brandsApi = {
285:   list: (params: BrandListParams, signal?: AbortSignal) =>
286:     apiClient.get<ResourceCollection<AdminBrand>>(BR, { params, signal }).then(r => r.data),
287:   show: (id: number) => apiClient.get<{ data: AdminBrand }>(`${BR}/${id}`).then(r => r.data.data),
288:   create: (p: BrandPayload, onProgress?: (n: number) => void) =>
289:     apiClient.post<{ data: AdminBrand }>(BR, brandFormData(p), { ...MULTIPART, onUploadProgress: progress(onProgress) }).then(r => r.data.data),
290:   update: (id: number, p: BrandPayload, onProgress?: (n: number) => void) =>
291:     apiClient.post<{ data: AdminBrand }>(`${BR}/${id}`, brandFormData(p, true), { ...MULTIPART, onUploadProgress: progress(onProgress) }).then(r => r.data.data),
292:   remove: (id: number) => apiClient.delete<{ message: string }>(`${BR}/${id}`).then(r => r.data),
293: };
294: 
295: export const productsApi = {
296:   list: (params: ProductListParams, signal?: AbortSignal) =>
297:     apiClient.get<ResourceCollection<AdminProduct>>(PR, { params, signal }).then(r => r.data),
298:   show: (id: number, signal?: AbortSignal) => apiClient.get<{ data: AdminProduct }>(`${PR}/${id}`, { signal }).then(r => r.data.data),
299:   create: (p: ProductPayload & { variants?: VariantPayload[] }) => apiClient.post<{ data: AdminProduct }>(PR, p).then(r => r.data.data),
300:   update: (id: number, p: Partial<ProductPayload> & { status?: ProductStatus; expected_updated_at?: string }) =>
301:     apiClient.put<{ data: AdminProduct }>(`${PR}/${id}`, p).then(r => r.data.data),
302:   remove: (id: number) => apiClient.delete<{ message: string }>(`${PR}/${id}`).then(r => r.data),
303: };
304: 
305: export const variantsApi = {
306:   list: (productId: number) => apiClient.get<{ data: AdminVariant[] }>(`${PR}/${productId}/variants`).then(r => r.data.data),
307:   create: (productId: number, p: VariantPayload) => apiClient.post<{ data: AdminVariant }>(`${PR}/${productId}/variants`, p).then(r => r.data.data),
308:   update: (productId: number, id: number, p: Partial<VariantPayload>) => apiClient.put<{ data: AdminVariant }>(`${PR}/${productId}/variants/${id}`, p).then(r => r.data.data),
309:   remove: (productId: number, id: number) => apiClient.delete(`${PR}/${productId}/variants/${id}`).then(r => r.data),
310: };
311: 
312: export const productMediaApi = {
313:   list: (productId: number) => apiClient.get<{ data: AdminMedia[] }>(`${PR}/${productId}/media`).then(r => r.data.data),
314:   uploadImages: (productId: number, files: File[], variantId?: number | null, onProgress?: (n: number) => void, clientUploadIds?: string[]) => {
315:     const fd = new FormData();
316:     fd.append('collection', 'images');
317:     if (variantId) fd.append('variant_id', String(variantId));
318:     files.forEach((f, index) => {
319:       fd.append('files[]', f); // max 4 per request
320:       fd.append('client_upload_ids[]', clientUploadIds?.[index] ?? crypto.randomUUID());
321:     });
322:     return apiClient.post<{ data: AdminMedia[] }>(`${PR}/${productId}/media`, fd, { ...MULTIPART, timeout: 180000, onUploadProgress: progress(onProgress) }).then(r => r.data.data);
323:   },
324:   uploadVideo: (productId: number, file: File, onProgress?: (n: number) => void) => {
325:     const fd = new FormData();
326:     fd.append('collection', 'video');
327:     fd.append('file', file);
328:     return apiClient.post<{ data: AdminMedia[] }>(`${PR}/${productId}/media`, fd, { ...MULTIPART, onUploadProgress: progress(onProgress), timeout: 0 }).then(r => r.data.data);
329:   },
330:   reorder: (productId: number, ids: number[]) => apiClient.put(`${PR}/${productId}/media/reorder`, { ids }).then(r => r.data),
331:   remove: (productId: number, mediaId: number) => apiClient.delete(`${PR}/${productId}/media/${mediaId}`).then(r => r.data),
332: };
```

## File: bags-platform/frontend/apps/backoffice/src/App.tsx
```typescript
  1: import { hasPermission } from '@alfarid/utils';
  2: import { defaultApiClient, resolveServiceUrl } from '@alfarid/api-client';
  3: import { ProtectedRoute } from './components/auth/ProtectedRoute';
  4: import AuthCallbackPage from './pages/auth/AuthCallbackPage';
  5: import LoginPage from './pages/auth/LoginPage';
  6: import SessionProbePage from './pages/auth/SessionProbePage';
  7: import { FinancialDashboard } from '@alfarid/ui';
  8: import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
  9: import { useAuthStore } from './stores/auth.store';
 10: import CategoriesMockPage from './pages/admin/categories-mock/CategoriesMockPage';
 11: import PosPage from './pages/pos/PosPage';
 12: import CatalogMockPage from './pages/admin/catalog-mock/CatalogMockPage';
 13: import AdminMockPage from './pages/admin/admin-mock/AdminMockPage';
 14: const AssistantPage = lazy(() => import('./pages/admin/assistant/AssistantPage'));
 15: import OperationsMockPage, { canViewOperation } from './pages/admin/operations-mock/OperationsMockPage';
 16: const InventoryPage = lazy(() => import('./pages/admin/inventory-mock/InventoryPage'));
 17: 
 18: defaultApiClient.defaults.baseURL = resolveServiceUrl(import.meta.env.VITE_API_URL ?? 'http://localhost:8000');
 19: 
 20: export function App() {
 21:   if (window.location.pathname === '/auth/session') return <SessionProbePage />;
 22:   if (window.location.pathname === '/auth/callback') return <AuthCallbackPage />;
 23:   if (window.location.pathname === '/login') return <LoginPage />;
 24:   return <ProtectedRoute><AuthenticatedApp /></ProtectedRoute>;
 25: }
 26: 
 27: function AuthenticatedApp() {
 28:   const user = useAuthStore((state) => state.user);
 29:   const logoutRemote = useAuthStore((state) => state.logoutRemote);
 30:   const readRoute = () => window.location.hash.replace(/^#/, '')
 31:     || window.location.pathname + window.location.search;
 32:   const [routeWithQuery, setRouteWithQuery] = useState(readRoute);
 33:   const route = routeWithQuery.split('?')[0];
 34:   const inventorySelection = useRef<{ entity: string; id: number } | undefined>(undefined);
 35:   useEffect(() => {
 36:     if (route === '/assistant') return;
 37:     const params = new URLSearchParams(routeWithQuery.split('?')[1] ?? '');
 38:     const entity = route === '/inventory/transfer' ? 'transfer' : route === '/inventory/count' ? 'stocktake' : route === '/production' ? 'production_receipt' : undefined;
 39:     const id = Number(params.get('id') ?? params.get('receipt_id'));
 40:     inventorySelection.current = entity && Number.isSafeInteger(id) && id > 0 ? { entity, id } : undefined;
 41:   }, [routeWithQuery, route]);
 42:   const can = useCallback((permission: string) =>
 43:     hasPermission(user?.permissions, permission), [user]);
 44:   const cashierOnly = (user?.roles ?? []).includes('cashier') && ![
 45:     'orders.view', 'treasuries.view', 'expenses.view', 'treasury_transfers.view', 'reports.view',
 46:     'products.view', 'inventory.view', 'users.view', 'customers.view', 'roles.view', 'settings.view',
 47:     'returns.view', 'shipping.view', 'price_lists.view', 'promotions.view', 'pos_shifts.view',
 48:     'quotations.view', 'payment_notices.view',
 49:   ].some(can);
 50: 
 51:   useEffect(() => {
 52:     // Stocktake navigation guards may synchronously restore the accepted hash.
 53:     const changed = () => queueMicrotask(() => setRouteWithQuery(readRoute()));
 54:     window.addEventListener('hashchange', changed);
 55:     window.addEventListener('popstate', changed);
 56:     return () => {
 57:       window.removeEventListener('hashchange', changed);
 58:       window.removeEventListener('popstate', changed);
 59:     };
 60:   }, []);
 61: 
 62:   const opMatch = route.match(/^\/(orders|returns|wholesale|shipping|pricing|promos|brands|suppliers|purchasing|finance|shifts|reports|notifications)(?:\/([^/?#]+))?$/);
 63:   const isOperation = Boolean(opMatch);
 64:   const operationsActive = opMatch ? opMatch[1] : null;
 65:   // AI deep links use ?tab= + ?id= (e.g. #/finance?tab=trf&id=12); the legacy ?sub= form keeps working.
 66:   const operationsQuery = new URLSearchParams(routeWithQuery.split('?')[1] ?? '');
 67:   const operationsId = opMatch?.[2] ?? (opMatch?.[1] === 'finance' ? operationsQuery.get('id') || undefined : undefined);
 68:   const operationsSub = (() => {
 69:     const raw = routeWithQuery.split('?')[1] ?? '';
 70:     return new URLSearchParams(raw).get('sub') || new URLSearchParams(raw).get('tab') || undefined;
 71:   })();
 72: 
 73:   useEffect(() => {
 74:     const isStore = ['/store', '/banners', '/settings/store', '/settings/banners'].includes(route);
 75:     if (cashierOnly && !['/', '/dashboard', '/pos', ...(can('ai_agent.use') ? ['/assistant'] : [])].includes(route)) {
 76:       window.location.replace('/dashboard');
 77:     } else if (
 78:       !['/', '/dashboard', '/categories', '/dealers', '/production', '/pos', '/users', '/roles', '/settings', '/assistant'].includes(route) &&
 79:       !isStore &&
 80:       !isOperation &&
 81:       !/^\/products(?:\/(?:new|[0-9]+))?$/.test(route) &&
 82:       !/^\/inventory(?:\/[a-z-]+)?$/.test(route)
 83:     ) {
 84:       window.location.replace('/dashboard');
 85:     }
 86:   }, [cashierOnly, route, isOperation, can]);
 87: 
 88:   if (cashierOnly && !['/', '/dashboard', '/pos', ...(can('ai_agent.use') ? ['/assistant'] : [])].includes(route)) return null;
 89:   if (route === '/pos') return <PosPage />;
 90: 
 91:   const categoriesActive = route === '/categories';
 92:   const inventoryActive = route === '/inventory' || route.startsWith('/inventory/');
 93:   const isStoreRoute = route === '/store' || route === '/banners' || route === '/settings/store' || route === '/settings/banners';
 94:   const adminActive = route === '/users' ? 'users' : route === '/roles' ? 'roles' : (route === '/settings' || isStoreRoute) ? 'settings' : null;
 95:   const catalogActive = route === '/assistant' ? 'assistant' : isOperation
 96:     ? operationsActive
 97:     : inventoryActive
 98:     ? 'inventory'
 99:     : route.startsWith('/products')
100:     ? 'products'
101:     : route === '/dealers'
102:     ? 'dealers'
103:     : route === '/production'
104:     ? 'production'
105:     : adminActive;
106: 
107:   // 4.4: seed جاهز من شاشة التجار — #/assistant?seed=…&mention_entity=customer&mention_id=…&mention_label=…
108:   const assistantPrompt = (() => {
109:     if (route !== '/assistant') return undefined;
110:     const params = new URLSearchParams(routeWithQuery.split('?')[1] ?? '');
111:     const text = params.get('seed');
112:     if (!text) return undefined;
113:     const id = Number(params.get('mention_id'));
114:     const entity = params.get('mention_entity');
115:     const label = params.get('mention_label');
116:     return { text, mentions: (entity === 'customer' || entity === 'transfer' || entity === 'stocktake') && Number.isInteger(id) && id > 0 ? [{ entity, id, label: label || text }] : undefined };
117:   })();
118:   const assistantNav = can('ai_agent.use') ? [{ id: 'assistant', label: 'المساعد الذكي', onOpen: () => { window.location.hash = '/assistant'; } }] : [];
119:   return <FinancialDashboard
120:     onLogout={logoutRemote}
121:     catalogActive={catalogActive}
122:     catalogPage={
123:       route === '/assistant' ? <Suspense fallback={<div dir="rtl">جارٍ تحميل المساعد…</div>}><AssistantPage can={can} initialPrompt={assistantPrompt} initialSelection={inventorySelection.current} /></Suspense> : isOperation && operationsActive ? (
124:         !canViewOperation(operationsActive, can) ? (
125:           <div dir="rtl" className="p-6">ليس لديك صلاحية عرض هذه الصفحة.</div>
126:         ) : (
127:         <OperationsMockPage
128:           key={`${operationsActive}-${operationsId || ''}-${operationsSub || ''}`}
129:           page={operationsActive}
130:           id={operationsId}
131:           sub={operationsSub}
132:           can={can}
133:         />
134:         )
135:       ) : inventoryActive ? (
136:         can('inventory.view') ? (
137:           <Suspense fallback={<div dir="rtl" className="p-6">جارٍ تحميل المخزون…</div>}>
138:             <InventoryPage can={can}/>
139:           </Suspense>
140:         ) : (
141:           <div dir="rtl" className="p-6">ليس لديك صلاحية عرض المخزون.</div>
142:         )
143:       ) : adminActive ? (
144:         <AdminMockPage
145:           key={adminActive}
146:           page={adminActive}
147:           can={can}
148:           initialTab={isStoreRoute ? 'store' : undefined}
149:         />
150:       ) : (
151:         catalogActive && <CatalogMockPage key={catalogActive} can={can} page={catalogActive} />
152:       )
153:     }
154:     catalogNav={!cashierOnly ? [
155:       { id: 'orders', label: 'الطلبات', onOpen: () => { window.location.hash = '/orders'; } },
156:       ...(can('inventory.view') ? [{ id: 'inventory', label: 'المخزون', onOpen: () => { window.location.hash = '/inventory/stock'; } }] : []),
157:       ...(can('products.view') ? [{ id: 'products', label: 'المنتجات', onOpen: () => { window.location.hash = '/products'; } }] : []),
158:       ...(can('customers.view') ? [{ id: 'dealers', label: 'التجار والموزعون', onOpen: () => { window.location.hash = '/dealers'; } }] : []),
159:       ...(can('inventory.view') ? [{ id: 'production', label: 'استلام الإنتاج', onOpen: () => { window.location.hash = '/production'; } }] : []),
160:       ...(canViewOperation('finance', can) ? [{ id: 'finance', label: 'المالية', onOpen: () => { window.location.hash = '/finance'; } }] : []),
161:       ...(can('reports.view') ? [{ id: 'reports', label: 'التقارير', onOpen: () => { window.location.hash = '/reports'; } }] : []),
162:       ...((can('users.view') || can('users.manage')) ? [{ id: 'users', label: 'المستخدمون', onOpen: () => { window.location.hash = '/users'; } }] : []),
163:       ...((can('roles.view') || can('roles.manage')) ? [{ id: 'roles', label: 'الأدوار', onOpen: () => { window.location.hash = '/roles'; } }] : []),
164:       ...((can('settings.view') || can('settings.manage')) ? [{ id: 'settings', label: 'الإعدادات', onOpen: () => { window.location.hash = '/settings'; } }] : []),
165:       ...assistantNav,
166:     ] : assistantNav}
167:     onCloseCatalog={() => { window.location.hash = '/dashboard'; }}
168:     onOpenNotifications={() => { window.location.hash = '/notifications'; }}
169:     categoriesActive={categoriesActive}
170:     categoriesPage={<CategoriesMockPage can={can} embedded />}
171:     onOpenCategories={!cashierOnly && can('categories.view')
172:       ? () => { window.location.hash = '/categories'; }
173:       : undefined}
174:     onCloseCategories={() => { window.location.hash = '/dashboard'; }}
175:   />;
176: }
177: 
178: export default App;
```

## File: bags-platform/backend/routes/api/admin.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: use App\Http\Controllers\Api\V1\Admin\Ai\AiActionController;
  6: use App\Http\Controllers\Api\V1\Admin\Ai\AiAdministrationController;
  7: use App\Http\Controllers\Api\V1\Admin\Ai\AiAttachmentController;
  8: use App\Http\Controllers\Api\V1\Admin\Ai\AiConversationController;
  9: use App\Http\Controllers\Api\V1\Admin\Ai\AiMentionController;
 10: use App\Http\Controllers\Api\V1\Admin\Ai\AiMessageController;
 11: use App\Http\Controllers\Api\V1\Admin\Ai\AiWelcomeController;
 12: use App\Http\Controllers\Api\V1\Admin\Ai\WhatsAppAdminController;
 13: use App\Http\Controllers\Api\V1\Admin\AuthController;
 14: use App\Http\Controllers\Api\V1\Admin\BannerController;
 15: use App\Http\Controllers\Api\V1\Admin\BranchController;
 16: use App\Http\Controllers\Api\V1\Admin\BrandController;
 17: use App\Http\Controllers\Api\V1\Admin\CategoryController;
 18: use App\Http\Controllers\Api\V1\Admin\CouponController;
 19: use App\Http\Controllers\Api\V1\Admin\CustomerController;
 20: use App\Http\Controllers\Api\V1\Admin\CustomerGroupController;
 21: use App\Http\Controllers\Api\V1\Admin\CustomerWalletController;
 22: use App\Http\Controllers\Api\V1\Admin\DashboardController;
 23: use App\Http\Controllers\Api\V1\Admin\ExpenseController;
 24: use App\Http\Controllers\Api\V1\Admin\InventoryController;
 25: use App\Http\Controllers\Api\V1\Admin\LocationController;
 26: use App\Http\Controllers\Api\V1\Admin\OrderController;
 27: use App\Http\Controllers\Api\V1\Admin\PermissionController;
 28: use App\Http\Controllers\Api\V1\Admin\PosShiftAdminController;
 29: use App\Http\Controllers\Api\V1\Admin\PriceListController;
 30: use App\Http\Controllers\Api\V1\Admin\PriceListItemController;
 31: use App\Http\Controllers\Api\V1\Admin\ProductController;
 32: use App\Http\Controllers\Api\V1\Admin\ProductionReceiptController;
 33: use App\Http\Controllers\Api\V1\Admin\ProductMediaController;
 34: use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
 35: use App\Http\Controllers\Api\V1\Admin\PromotionController;
 36: use App\Http\Controllers\Api\V1\Admin\PurchaseOrderController;
 37: use App\Http\Controllers\Api\V1\Admin\PurchaseRequestController;
 38: use App\Http\Controllers\Api\V1\Admin\QuotationController;
 39: use App\Http\Controllers\Api\V1\Admin\ReportController;
 40: use App\Http\Controllers\Api\V1\Admin\ReturnController;
 41: use App\Http\Controllers\Api\V1\Admin\RoleController;
 42: use App\Http\Controllers\Api\V1\Admin\SettingController;
 43: use App\Http\Controllers\Api\V1\Admin\ShippingCompanyController;
 44: use App\Http\Controllers\Api\V1\Admin\ShippingRateController;
 45: use App\Http\Controllers\Api\V1\Admin\ShippingZoneController;
 46: use App\Http\Controllers\Api\V1\Admin\StocktakeController;
 47: use App\Http\Controllers\Api\V1\Admin\SupplierController;
 48: use App\Http\Controllers\Api\V1\Admin\SupplierInvoiceController;
 49: use App\Http\Controllers\Api\V1\Admin\SupplierPaymentController;
 50: use App\Http\Controllers\Api\V1\Admin\TransferController;
 51: use App\Http\Controllers\Api\V1\Admin\TreasuryController;
 52: use App\Http\Controllers\Api\V1\Admin\TreasuryTransferController;
 53: use App\Http\Controllers\Api\V1\Admin\UserController;
 54: use App\Http\Controllers\Api\V1\Admin\WholesalePaymentNoticeController;
 55: use App\Http\Controllers\Api\V1\AttachmentController;
 56: use App\Http\Controllers\Api\V1\Shared\NotificationController;
 57: use App\Http\Middleware\AuditStaffAdministration;
 58: use Illuminate\Support\Facades\Route;
 59: 
 60: // معرّفات الكتالوج رقمية بس: أي قيمة غير رقمية تبقى 404 بدل ما Postgres يرمي خطأ نوع ويرجع 500
 61: Route::pattern('category', '[0-9]{1,18}');
 62: Route::pattern('brand', '[0-9]{1,18}');
 63: Route::pattern('product', '[0-9]{1,18}');
 64: Route::pattern('variant', '[0-9]{1,18}');
 65: Route::pattern('media', '[0-9]{1,18}');
 66: Route::pattern('transfer', '[0-9]{1,18}');
 67: Route::pattern('stocktake', '[0-9]{1,18}');
 68: Route::pattern('customer_group', '[0-9]{1,18}');
 69: Route::pattern('customer', '[0-9]{1,18}');
 70: Route::pattern('document', '[0-9]{1,18}');
 71: Route::pattern('price_list', '[0-9]{1,18}');
 72: Route::pattern('item', '[0-9]{1,18}');
 73: Route::pattern('order', '[0-9]{1,18}');
 74: Route::pattern('coupon', '[0-9]{1,18}');
 75: Route::pattern('promotion', '[0-9]{1,18}');
 76: Route::pattern('return_request', '[0-9]{1,18}');
 77: Route::pattern('shipping_company', '[0-9]{1,18}');
 78: Route::pattern('shipping_zone', '[0-9]{1,18}');
 79: Route::pattern('shipping_rate', '[0-9]{1,18}');
 80: Route::pattern('supplier', '[0-9]{1,18}');
 81: Route::pattern('role', '[0-9]{1,18}');
 82: Route::pattern('branch', '[0-9]{1,18}');
 83: Route::pattern('location', '[0-9]{1,18}');
 84: Route::pattern('user', '[0-9]{1,18}');
 85: Route::pattern('banner', '[0-9]{1,18}');
 86: Route::pattern('purchase_request', '[0-9]{1,18}');
 87: Route::pattern('purchase_order', '[0-9]{1,18}');
 88: Route::pattern('supplier_invoice', '[0-9]{1,18}');
 89: Route::pattern('supplier_payment', '[0-9]{1,18}');
 90: // L4: معاملات المساعد مقيدة النمط — المحادثة integer والفعل ULID.
 91: // قيمة رمزية خارج النمط تفضي لـ 404 بدل 22P02 في Postgres.
 92: // (كانا UUID خطأً فكسرا كل مسارات المساعد: 404 دائم.)
 93: Route::pattern('conversation', '[0-9]{1,18}');
 94: Route::pattern('action', '[0-9A-Za-z]{26}');
 95: 
 96: // Public admin authentication endpoint (legacy — prefer /api/v1/auth/login).
 97: // M5: تُعطَّل بضبط LEGACY_LOGIN_ENABLED=false حتى لا تتضاعف ميزانية التخمين
 98: // عبر limiters مستقلة ولا يظهر توكن موظف على دومين الويب.
 99: if (config('auth.legacy_login_enabled', false)) {
100:     Route::post('auth/login', [AuthController::class, 'login'])
101:         ->middleware('throttle:admin-login')
102:         ->name('admin.auth.login');
103: }
104: 
105: // Staff-only API endpoints: require a Sanctum token and an active staff account.
106: Route::middleware(['auth:sanctum', 'auth.staff', AuditStaffAdministration::class])
107:     ->name('admin.')
108:     ->group(function (): void {
109:         Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
110:         Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:reports.view')->name('dashboard');
111:         Route::prefix('reports')->name('reports.')->middleware(['permission:reports.view', 'throttle:30,1'])->group(function (): void {
112:             Route::get('sales', [ReportController::class, 'sales'])->name('sales');
113:             Route::get('inventory', [ReportController::class, 'inventory'])->name('inventory');
114:             Route::get('profit', [ReportController::class, 'profit'])->name('profit');
115:             Route::get('suppliers', [ReportController::class, 'suppliers'])->name('suppliers');
116:             Route::get('shifts', [ReportController::class, 'shifts'])->name('shifts');
117:         });
118:         // M3: notifications.id is uuid — non-uuid values stay 404 instead of
119:         // throwing Postgres 22P02 (500 + log spam) via findOrFail.
120:         Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id')->name('notifications.read');
121:         Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
122: 
123:         Route::get('me', [AuthController::class, 'me'])->name('auth.me');
124:         Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
125: 
126:         Route::apiResource('branches', BranchController::class)
127:             ->only(['index', 'show'])
128:             ->middleware('permission:branches.view|branches.manage');
129:         Route::apiResource('branches', BranchController::class)
130:             ->only(['store'])
131:             ->middleware('permission:branches.create|branches.manage');
132:         Route::apiResource('branches', BranchController::class)
133:             ->only(['update'])
134:             ->middleware('permission:branches.update|branches.manage');
135:         Route::apiResource('branches', BranchController::class)
136:             ->only(['destroy'])
137:             ->middleware('permission:branches.delete|branches.manage');
138: 
139:         Route::scopeBindings()->group(function (): void {
140:             Route::apiResource('branches.locations', LocationController::class)
141:                 ->only(['index', 'show'])
142:                 ->middleware('permission:locations.view|locations.manage');
143:             Route::apiResource('branches.locations', LocationController::class)
144:                 ->only(['store'])
145:                 ->middleware('permission:locations.create|locations.manage');
146:             Route::apiResource('branches.locations', LocationController::class)
147:                 ->only(['update'])
148:                 ->middleware('permission:locations.update|locations.manage');
149:             Route::apiResource('branches.locations', LocationController::class)
150:                 ->only(['destroy'])
151:                 ->middleware('permission:locations.delete|locations.manage');
152:         });
153: 
154:         // L8: self-service rotation for flagged temp passwords — no
155:         // users.update required (defined before the resource routes).
156:         Route::put('me/password', [UserController::class, 'updateOwnPassword'])->name('me.password');
157:         Route::apiResource('users', UserController::class)
158:             ->only(['index', 'show'])
159:             ->middleware('permission:users.view|users.manage');
160:         Route::apiResource('users', UserController::class)
161:             ->only(['store'])
162:             ->middleware('permission:users.create|users.manage');
163:         Route::apiResource('users', UserController::class)
164:             ->only(['update'])
165:             ->middleware('permission:users.update|users.manage');
166:         Route::apiResource('users', UserController::class)
167:             ->only(['destroy'])
168:             ->middleware('permission:users.delete|users.manage');
169: 
170:         Route::apiResource('roles', RoleController::class)
171:             ->only(['index', 'show'])
172:             ->middleware('permission:roles.view|roles.manage');
173:         Route::apiResource('roles', RoleController::class)
174:             ->only(['store'])
175:             ->middleware('permission:roles.create|roles.manage');
176:         Route::apiResource('roles', RoleController::class)
177:             ->only(['update'])
178:             ->middleware('permission:roles.update|roles.manage');
179:         Route::apiResource('roles', RoleController::class)
180:             ->only(['destroy'])
181:             ->middleware('permission:roles.delete|roles.manage');
182: 
183:         Route::get('permissions', [PermissionController::class, 'index'])
184:             ->middleware('permission:permissions.view|roles.manage')
185:             ->name('permissions.index');
186: 
187:         // Catalog: authorization is handled in the controllers via can:* middleware.
188:         // مهم: reorder قبل apiResource عشان ميتقريش كأنه {category}
189:         Route::put('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
190:         Route::apiResource('categories', CategoryController::class);
191: 
192:         Route::apiResource('brands', BrandController::class);
193: 
194:         Route::apiResource('products', ProductController::class);
195:         Route::apiResource('products.variants', ProductVariantController::class)->scoped();
196: 
197:         Route::put('products/{product}/media/reorder', [ProductMediaController::class, 'reorder'])->name('products.media.reorder');
198:         Route::get('products/{product}/media', [ProductMediaController::class, 'index'])->name('products.media.index');
199:         // F18: each image spawns two GD conversions in-request and the UI
200:         // fires two uploads in parallel — throttle the admin upload path so
201:         // one account cannot saturate every PHP worker.
202:         Route::post('products/{product}/media', [ProductMediaController::class, 'store'])->middleware('throttle:30,1')->name('products.media.store');
203:         Route::delete('products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])->name('products.media.destroy');
204: 
205:         // ================== المرحلة 3: المخزون ==================
206:         Route::get('production-receipts/options', [ProductionReceiptController::class, 'options'])->name('production-receipts.options');
207:         Route::get('production-receipts', [ProductionReceiptController::class, 'index'])->name('production-receipts.index');
208:         Route::post('production-receipts', [ProductionReceiptController::class, 'store'])->name('production-receipts.store');
209:         Route::prefix('inventory')->name('inventory.')->group(function () {
210:             Route::get('/', [InventoryController::class, 'index'])->name('index');
211:             Route::get('/movements', [InventoryController::class, 'movements'])->name('movements');
212:             Route::post('/adjustments', [InventoryController::class, 'adjustments'])->name('adjustments');
213:         });
214: 
215:         // التحويلات المخزنية
216:         Route::apiResource('transfers', TransferController::class);
217:         Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
218:         Route::post('transfers/{transfer}/ship', [TransferController::class, 'ship'])->name('transfers.ship');
219:         Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
220:         Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');
221: 
222:         // الجرد المخزني
223:         Route::apiResource('stocktakes', StocktakeController::class);
224:         Route::patch('stocktakes/{stocktake}/items', [StocktakeController::class, 'updateItems'])->name('stocktakes.items.update');
225:         Route::post('stocktakes/{stocktake}/approve', [StocktakeController::class, 'approve'])->name('stocktakes.approve');
226: 
227:         // ================== المرحلة 4: الأسعار ==================
228:         Route::apiResource('customer-groups', CustomerGroupController::class);
229: 
230:         Route::apiResource('price-lists', PriceListController::class);
231:         Route::post('price-lists/{price_list}/import', [PriceListController::class, 'import'])->name('price-lists.import');
232:         Route::get('price-lists/{price_list}/export', [PriceListController::class, 'export'])->name('price-lists.export');
233:         Route::post('price-lists/{price_list}/apply-percentage', [PriceListController::class, 'applyPercentage'])->name('price-lists.apply-percentage');
234: 
235:         Route::scopeBindings()->group(function () {
236:             Route::apiResource('price-lists.items', PriceListItemController::class);
237:         });
238: 
239:         // ================== المرحلة 5: العملاء ==================
240:         Route::apiResource('customers', CustomerController::class);
241:         Route::post('customers/{customer}/documents', [CustomerController::class, 'storeDocument'])->name('customers.documents.store');
242:         Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->withTrashed()->name('customers.restore');
243:         Route::post('customers/{customer}/approve', [CustomerController::class, 'approve'])->name('customers.approve');
244:         Route::post('customers/{customer}/reject', [CustomerController::class, 'reject'])->name('customers.reject');
245:         Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
246:         Route::post('customers/{customer}/reactivate', [CustomerController::class, 'reactivate'])->name('customers.reactivate');
247:         Route::get('customers/{customer}/history', [CustomerController::class, 'history'])->name('customers.history');
248:         Route::get('customers/{customer}/wallet', [CustomerWalletController::class, 'show'])->name('customers.wallet.show');
249:         Route::get('customers/{customer}/wallet/summary', [CustomerWalletController::class, 'summary'])->name('customers.wallet.summary');
250:         Route::get('customers/{customer}/wallet/adjustments', [CustomerWalletController::class, 'adjustments'])->name('customers.wallet.adjustments.index');
251:         Route::post('customers/{customer}/wallet/adjustments', [CustomerWalletController::class, 'storeAdjustment'])->name('customers.wallet.adjustments.store');
252:         Route::delete('wallet-adjustments/{adjustment}', [CustomerWalletController::class, 'reverseAdjustment'])->name('wallet.adjustments.reverse');
253:         Route::scopeBindings()->group(function (): void {
254:             Route::patch('customers/{customer}/documents/{document}', [CustomerController::class, 'reviewDocument'])
255:                 ->name('customers.documents.update');
256:             Route::get('customers/{customer}/documents/{document}/download', [CustomerController::class, 'downloadDocument'])
257:                 ->name('customers.documents.download');
258:         });
259: 
260:         // ================== المرحلة 7: الطلبات ==================
261:         Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
262:         Route::get('orders/stats', [OrderController::class, 'stats'])->name('orders.stats');
263:         Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
264:         Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
265:         Route::post('orders/{order}/payment', [OrderController::class, 'updatePayment'])->name('orders.update-payment');
266:         Route::post('orders/{order}/allocate', [OrderController::class, 'allocate'])->name('orders.allocate');
267:         // M5: فك التوزيع — كانت رسالة allocate توجّه لعملية غير موجودة
268:         Route::post('orders/{order}/deallocate', [OrderController::class, 'deallocate'])->name('orders.deallocate');
269:         Route::post('orders/{order}/shipment', [OrderController::class, 'createShipment'])->name('orders.shipment');
270: 
271:         // ================== المرحلة 8: الشحن ==================
272:         Route::apiResource('shipping-companies', ShippingCompanyController::class);
273:         Route::apiResource('shipping-zones', ShippingZoneController::class);
274:         Route::apiResource('shipping-rates', ShippingRateController::class);
275: 
276:         // ================== المرحلة 8: العروض والكوبونات ==================
277:         Route::apiResource('promotions', PromotionController::class);
278:         Route::apiResource('coupons', CouponController::class);
279: 
280:         Route::get('return-items/{return_item}/attachments/{attachment}', [AttachmentController::class, 'returnFile'])->whereNumber(['return_item', 'attachment'])->name('return-items.attachments.show');
281:         Route::get('supplier-invoices/{invoice}/attachment', [AttachmentController::class, 'invoice'])->whereNumber('invoice')->name('supplier-invoices.attachment');
282:         Route::get('supplier-deliveries/{delivery}/invoice', [AttachmentController::class, 'delivery'])->whereNumber('delivery')->name('deliveries.invoice');
283:         // ================== المرحلة 12: بوابة الجملة المتقدمة ==================
284:         Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
285:         Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->whereNumber('quotation')->name('quotations.show');
286:         Route::post('quotations/{quotation}/price', [QuotationController::class, 'price'])->whereNumber('quotation')->name('quotations.price');
287:         Route::post('quotations/{quotation}/expire', [QuotationController::class, 'expire'])->whereNumber('quotation')->name('quotations.expire');
288:         Route::get('payment-notices', [WholesalePaymentNoticeController::class, 'index'])->name('payment-notices.index');
289:         Route::get('payment-notices/{payment_notice}', [WholesalePaymentNoticeController::class, 'show'])->whereNumber('payment_notice')->name('payment-notices.show');
290:         Route::post('payment-notices/{payment_notice}/approve', [WholesalePaymentNoticeController::class, 'approve'])->whereNumber('payment_notice')->name('payment-notices.approve');
291:         Route::post('payment-notices/{payment_notice}/reject', [WholesalePaymentNoticeController::class, 'reject'])->whereNumber('payment_notice')->name('payment-notices.reject');
292:         Route::get('payment-notices/{notice}/receipt', [AttachmentController::class, 'paymentNotice'])->whereNumber('notice')->name('payment-notices.receipt');
293: 
294:         // ================== المرحلة 9: المرتجعات ==================
295:         Route::apiResource('returns', ReturnController::class)->parameters(['returns' => 'return_request']);
296:         Route::post('returns/{return_request}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
297:         Route::post('returns/{return_request}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
298:         Route::post('returns/{return_request}/refund', [ReturnController::class, 'refund'])->name('returns.refund');
299: 
300:         // ================== المرحلة 10: المشتريات والموردين ==================
301:         Route::apiResource('suppliers', SupplierController::class);
302:         Route::get('suppliers/{supplier}/users', [SupplierController::class, 'users'])->name('suppliers.users.index');
303:         Route::post('suppliers/{supplier}/users', [SupplierController::class, 'storeUser'])->name('suppliers.users.store');
304:         Route::put('suppliers/{supplier}/users/{user}', [SupplierController::class, 'updateUser'])->name('suppliers.users.update');
305:         Route::delete('suppliers/{supplier}/users/{user}', [SupplierController::class, 'destroyUser'])->name('suppliers.users.destroy');
306: 
307:         Route::apiResource('purchase-requests', PurchaseRequestController::class)
308:             ->parameters(['purchase-requests' => 'purchase_request']);
309:         Route::post('purchase-requests/{purchase_request}/submit', [PurchaseRequestController::class, 'submit'])->name('purchase-requests.submit');
310:         Route::post('purchase-requests/{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])->name('purchase-requests.approve');
311:         Route::post('purchase-requests/{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])->name('purchase-requests.reject');
312: 
313:         Route::apiResource('purchase-orders', PurchaseOrderController::class)
314:             ->parameters(['purchase-orders' => 'purchase_order']);
315:         Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
316:         Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
317:         Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
318:         Route::post('purchase-orders/{purchase_order}/close-short', [PurchaseOrderController::class, 'closeShort'])->name('purchase-orders.close-short');
319:         Route::post('purchase-orders/{purchase_order}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
320: 
321:         Route::apiResource('supplier-invoices', SupplierInvoiceController::class)
322:             ->parameters(['supplier-invoices' => 'supplier_invoice']);
323:         Route::post('supplier-invoices/{supplier_invoice}/post', [SupplierInvoiceController::class, 'post'])->name('supplier-invoices.post');
324:         Route::post('supplier-invoices/{supplier_invoice}/cancel', [SupplierInvoiceController::class, 'cancel'])->name('supplier-invoices.cancel');
325: 
326:         Route::apiResource('supplier-payments', SupplierPaymentController::class)
327:             ->parameters(['supplier-payments' => 'supplier_payment']);
328:         Route::post('supplier-payments/{supplier_payment}/allocate', [SupplierPaymentController::class, 'allocate'])->name('supplier-payments.allocate');
329:         Route::post('supplier-payments/{supplier_payment}/void', [SupplierPaymentController::class, 'void'])->name('supplier-payments.void');
330: 
331:         Route::middleware('permission:store.manage|settings.view')->group(function (): void {
332:             Route::get('banners', [BannerController::class, 'index'])->name('banners.index');
333:             Route::get('banners/{banner}', [BannerController::class, 'show'])->name('banners.show');
334:         });
335:         Route::middleware('permission:store.manage')->group(function (): void {
336:             Route::put('banners/reorder', [BannerController::class, 'reorder'])->name('banners.reorder');
337:             Route::post('banners', [BannerController::class, 'store'])->middleware('throttle:30,1')->name('banners.store');
338:             Route::post('banners/{banner}', [BannerController::class, 'update'])->middleware('throttle:30,1')->name('banners.update');
339:             Route::patch('banners/{banner}/toggle', [BannerController::class, 'toggle'])->name('banners.toggle');
340:             Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->name('banners.destroy');
341:         });
342: 
343:         Route::get('settings', [SettingController::class, 'index'])
344:             ->middleware('permission:settings.view|settings.manage')
345:             ->name('settings.index');
346:         Route::put('settings', [SettingController::class, 'update'])
347:             ->middleware('permission:settings.update|settings.manage')
348:             ->name('settings.update');
349: 
350:         // ================== المرحلة 11: الخزائن، المصروفات، تحويلات الخزائن، الورديات ==================
351:         Route::pattern('treasury', '[0-9]{1,18}');
352:         Route::pattern('expense', '[0-9]{1,18}');
353:         Route::pattern('treasury_transfer', '[0-9]{1,18}');
354:         Route::pattern('shift', '[0-9]{1,18}');
355: 
356:         Route::apiResource('treasuries', TreasuryController::class);
357: 
358:         Route::apiResource('expenses', ExpenseController::class);
359:         Route::get('expenses/{expense}/attachment', [ExpenseController::class, 'attachment'])->name('expenses.attachment');
360:         Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');
361: 
362:         Route::apiResource('treasury-transfers', TreasuryTransferController::class)
363:             ->parameters(['treasury-transfers' => 'treasuryTransfer'])
364:             ->only(['index', 'store', 'show']);
365:         Route::post('treasury-transfers/{treasuryTransfer}/approve', [TreasuryTransferController::class, 'approve'])
366:             ->name('treasury-transfers.approve');
367:         Route::post('treasury-transfers/{treasuryTransfer}/reject', [TreasuryTransferController::class, 'reject'])
368:             ->name('treasury-transfers.reject');
369: 
370:         Route::get('shifts', [PosShiftAdminController::class, 'index'])
371:             ->name('shifts.index');
372:         Route::get('shifts/{shift}', [PosShiftAdminController::class, 'show'])
373:             ->name('shifts.show');
374:         Route::post('shifts/{shift}/approve', [PosShiftAdminController::class, 'approve'])
375:             ->name('shifts.approve');
376: 
377:         Route::prefix('ai')->name('ai.')->middleware('permission:ai_agent.use')->group(function (): void {
378:             Route::get('settings', [AiAdministrationController::class, 'settings'])->middleware('permission:ai_agent.settings')->name('settings');
379:             Route::put('settings', [AiAdministrationController::class, 'updateSettings'])->middleware('permission:ai_agent.settings')->name('settings.update');
380:             Route::get('usage', [AiAdministrationController::class, 'usage'])->name('usage');
381:             Route::middleware('permission:ai_agent.settings')->group(function (): void {
382:                 Route::get('whatsapp/status', [WhatsAppAdminController::class, 'status'])->name('whatsapp.status');
383:                 Route::get('whatsapp/qr', [WhatsAppAdminController::class, 'qr'])->name('whatsapp.qr');
384:                 Route::post('whatsapp/logout', [WhatsAppAdminController::class, 'logout'])->name('whatsapp.logout');
385:                 Route::get('whatsapp/identities', [WhatsAppAdminController::class, 'identities'])->name('whatsapp.identities');
386:                 Route::post('whatsapp/identities', [WhatsAppAdminController::class, 'storeIdentity'])->name('whatsapp.identities.store');
387:                 Route::post('whatsapp/identities/{identity}/link', [WhatsAppAdminController::class, 'link'])->name('whatsapp.identities.link');
388:                 Route::post('whatsapp/identities/{identity}/revoke', [WhatsAppAdminController::class, 'revoke'])->name('whatsapp.identities.revoke');
389:                 Route::get('whatsapp/staff', [WhatsAppAdminController::class, 'staff'])->name('whatsapp.staff');
390:                 Route::get('whatsapp/monitor', [WhatsAppAdminController::class, 'monitor'])->name('whatsapp.monitor');
391:             });
392:             Route::patch('conversations/{conversation}/pin', [AiConversationController::class, 'pin'])->name('conversations.pin');
393:             Route::post('conversations/{conversation}/stream', [AiMessageController::class, 'stream'])->middleware('throttle:ai-chat')->name('conversations.stream');
394:             Route::get('conversations', [AiConversationController::class, 'index'])->name('conversations.index');
395:             // L5: إنشاء المحادثات مكلف (سجل + عنوان أول) فمقيّد مثل الرفع.
396:             Route::post('conversations', [AiConversationController::class, 'store'])->middleware('throttle:30,1')->name('conversations.store');
397:             Route::get('conversations/{conversation}', [AiConversationController::class, 'show'])->name('conversations.show');
398:             Route::delete('conversations/{conversation}', [AiConversationController::class, 'destroy'])->name('conversations.destroy');
399:             Route::post('conversations/{conversation}/messages', [AiMessageController::class, 'store'])->middleware('throttle:ai-chat')->name('conversations.messages.store');
400:             Route::post('conversations/{conversation}/attachments', [AiAttachmentController::class, 'store'])->middleware('throttle:30,1')->name('conversations.attachments.store');
401:             Route::post('actions/{action}/confirm', [AiActionController::class, 'confirm'])->middleware('permission:ai_agent.execute')->name('actions.confirm');
402:             Route::post('actions/{action}/cancel', [AiActionController::class, 'cancel'])->name('actions.cancel');
403:             Route::post('actions/{action}/undo', [AiActionController::class, 'undo'])->middleware('permission:ai_agent.execute')->name('actions.undo');
404:             // M6: الزر بتاع mentions بيتنادى مع كل ضغطة بعد debounce — GAS
405:             // (AuditStaffAdministration) يعدّ POST بس ففلتر GET مش مغطّى.
406:             Route::get('mentions', [AiMentionController::class, 'search'])->middleware('throttle:60,1')->name('mentions');
407:             Route::get('welcome', [AiWelcomeController::class, 'show'])->name('welcome');
408:         });
409:     });
```

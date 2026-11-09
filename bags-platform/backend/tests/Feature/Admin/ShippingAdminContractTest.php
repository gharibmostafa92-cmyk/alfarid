<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Orders\ShipmentStatus;
use App\Models\City;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Orders\ShippingQuoteService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShippingAdminContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_shipping_permissions_cover_every_admin_endpoint(): void
    {
        [, $view] = $this->staff(['shipping.view']);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $super = $admin->createToken('super')->plainTextToken;
        [, $none] = $this->staff([]);
        $customer = Customer::factory()->create()->createToken('customer')->plainTextToken;
        $foreign = $this->foreignGuardToken();

        $company = ShippingCompany::factory()->create();
        $zone = ShippingZone::factory()->create();
        $city = City::factory()->create();
        $zone->cities()->sync([$city->id]);
        $rate = ShippingRate::factory()->create([
            'shipping_company_id' => $company->id,
            'shipping_zone_id' => $zone->id,
        ]);
        $reads = [
            ['GET', '/api/v1/admin/shipping-companies'],
            ['GET', '/api/v1/admin/shipping-companies/'.$company->id],
            ['GET', '/api/v1/admin/shipping-zones'],
            ['GET', '/api/v1/admin/shipping-zones/'.$zone->id],
            ['GET', '/api/v1/admin/shipping-rates'],
            ['GET', '/api/v1/admin/shipping-rates/'.$rate->id],
        ];
        $writes = [
            ['POST', '/api/v1/admin/shipping-companies', ['name' => 'ممنوع', 'code' => 'nope']],
            ['PUT', '/api/v1/admin/shipping-companies/'.$company->id, ['name' => 'تعديل', 'code' => $company->code]],
            ['DELETE', '/api/v1/admin/shipping-companies/'.$company->id, []],
            ['POST', '/api/v1/admin/shipping-zones', ['name' => 'منطقة']],
            ['PUT', '/api/v1/admin/shipping-zones/'.$zone->id, ['name' => 'تعديل منطقة']],
            ['DELETE', '/api/v1/admin/shipping-zones/'.$zone->id, []],
            ['POST', '/api/v1/admin/shipping-rates', $this->ratePayload($company->id, $zone->id)],
            ['PUT', '/api/v1/admin/shipping-rates/'.$rate->id, $this->ratePayload($company->id, $zone->id)],
            ['DELETE', '/api/v1/admin/shipping-rates/'.$rate->id, []],
        ];

        foreach ([...$reads, ...$writes] as [$method, $uri]) {
            Auth::forgetGuards();
            $open = $this->flushHeaders()->json($method, $uri);
            $this->assertSame(401, $open->status(), $method.' '.$uri.' '.$open->getContent());
            $this->api($customer, $method, $uri)->assertForbidden();
            $this->api($foreign, $method, $uri)->assertForbidden();
            $this->api($none, $method, $uri)->assertForbidden();
        }
        $this->assertSame($company->name, $company->fresh()->name);
        $this->assertNotNull($zone->fresh());
        $this->assertNotNull($rate->fresh());

        foreach ($reads as [$method, $uri]) {
            $this->api($view, $method, $uri)->assertOk();
        }
        foreach ($writes as [$method, $uri, $payload]) {
            $this->api($view, $method, $uri, $payload)->assertForbidden();
        }
        $this->assertSame(1, ShippingCompany::query()->count());

        $created = $this->api($super, 'POST', '/api/v1/admin/shipping-companies', [
            'name' => 'بوسطة', 'code' => 'bosta',
        ])->assertCreated();
        $id = $created->json('data.id');
        $this->api($super, 'GET', '/api/v1/admin/shipping-companies/'.$id)->assertOk();
        $this->api($super, 'PUT', '/api/v1/admin/shipping-companies/'.$id, [
            'name' => 'بوسطة محدّثة', 'code' => 'bosta',
        ])->assertOk();
        $this->api($super, 'DELETE', '/api/v1/admin/shipping-companies/'.$id)->assertOk();
        $this->assertNull(ShippingCompany::query()->find($id));

        $zoneId = $this->api($super, 'POST', '/api/v1/admin/shipping-zones', [
            'name' => 'القاهرة', 'city_ids' => [$city->id],
        ])->assertCreated()->json('data.id');
        $this->api($super, 'PUT', '/api/v1/admin/shipping-zones/'.$zoneId, ['name' => 'القاهرة الكبرى'])->assertOk();
        $rateId = $this->api($super, 'POST', '/api/v1/admin/shipping-rates', $this->ratePayload(
            ShippingCompany::factory()->create()->id, $zoneId,
        ))->assertCreated()->json('data.id');
        $this->api($super, 'PUT', '/api/v1/admin/shipping-rates/'.$rateId, $this->ratePayload(
            ShippingRate::query()->findOrFail($rateId)->shipping_company_id, $zoneId, 60,
        ))->assertOk();
        $this->api($super, 'DELETE', '/api/v1/admin/shipping-rates/'.$rateId)->assertOk();
        $this->api($super, 'DELETE', '/api/v1/admin/shipping-zones/'.$zoneId)->assertOk();
    }

    public function test_shipping_manage_permission_is_not_accepted_by_the_controllers(): void
    {
        [, $token] = $this->staff(['shipping.manage']);
        $this->api($token, 'GET', '/api/v1/admin/shipping-companies')->assertForbidden();
    }

    public function test_credentials_are_encrypted_merged_and_absent_from_responses_and_audit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $token = $admin->createToken('super')->plainTextToken;
        $secret = 'webhook-secret-SENTINEL';
        $key = 'api-key-SENTINEL';
        $replacement = 'replacement-api-key-SENTINEL';
        $created = $this->api($token, 'POST', '/api/v1/admin/shipping-companies', [
            'name' => 'سرية', 'code' => 'secretco',
            'api_credentials' => ['api_key' => $key, 'webhook_secret' => $secret],
        ])->assertCreated()->assertJsonMissingPath('data.api_credentials');
        $id = $created->json('data.id');
        $this->assertTrue($created->json('data.has_api_credentials'));
        $this->assertStringNotContainsString($secret, $created->getContent());
        $this->assertStringNotContainsString($key, $created->getContent());

        foreach (['' => 'data.0', '/'.$id => 'data'] as $suffix => $path) {
            $body = $this->api($token, 'GET', '/api/v1/admin/shipping-companies'.$suffix)
                ->assertOk()
                ->assertJsonPath($path.'.has_api_credentials', true)
                ->assertJsonMissingPath($path.'.api_credentials')
                ->getContent();
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString($key, $body);
        }

        $this->api($token, 'PUT', '/api/v1/admin/shipping-companies/'.$id, [
            'name' => 'سرية', 'code' => 'secretco', 'api_credentials' => null,
        ])->assertOk();
        $this->assertSame($secret, ShippingCompany::query()->findOrFail($id)->api_credentials['webhook_secret']);

        $updated = $this->api($token, 'PUT', '/api/v1/admin/shipping-companies/'.$id, [
            'name' => 'سرية', 'code' => 'secretco', 'api_credentials' => ['api_key' => $replacement],
        ])->assertOk()->assertJsonMissingPath('data.api_credentials');
        $this->assertStringNotContainsString($replacement, $updated->getContent());
        $this->assertStringNotContainsString($secret, $updated->getContent());
        $stored = ShippingCompany::query()->findOrFail($id)->api_credentials;
        $this->assertSame(['api_key' => $replacement, 'webhook_secret' => $secret], $stored);

        $raw = (string) DB::table('shipping_companies')->where('id', $id)->value('api_credentials');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertStringNotContainsString($replacement, $raw);
        $decoded = json_decode($raw, true);
        $this->assertTrue(! is_array($decoded) || ! array_key_exists('api_key', $decoded));

        DB::table('shipping_companies')->where('id', $id)->update(['api_credentials' => 'not-decryptable']);
        $this->api($token, 'GET', '/api/v1/admin/shipping-companies')->assertOk()
            ->assertJsonPath('data.0.has_api_credentials', true);

        $this->assertSame(
            0,
            DB::table('staff_audit_logs')->where('metadata', 'like', '%SENTINEL%')->count(),
        );
        $this->api($token, 'POST', '/api/v1/admin/shipping-companies', [
            'name' => 'نص', 'code' => 'textco', 'api_credentials' => 'not-an-array',
        ])->assertStatus(422);
    }

    public function test_null_fees_and_flags_do_not_violate_not_null_columns(): void
    {
        $token = $this->superToken();
        $company = ShippingCompany::factory()->create();
        $zone = ShippingZone::factory()->create();
        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', [
            'shipping_company_id' => $company->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 50,
            'cod_fee' => null,
            'estimated_days_min' => 1,
            'estimated_days_max' => 3,
        ])->assertCreated()->assertJsonPath('data.cod_fee', '0.00');

        $this->api($token, 'POST', '/api/v1/admin/shipping-companies', [
            'name' => 'افتراضي', 'code' => 'defaultco', 'is_active' => null,
        ])->assertCreated()->assertJsonPath('data.is_active', true);
        $this->api($token, 'POST', '/api/v1/admin/shipping-zones', [
            'name' => 'منطقة', 'is_active' => null,
        ])->assertCreated()->assertJsonPath('data.is_active', true);
    }

    public function test_company_codes_must_fit_the_webhook_route(): void
    {
        $token = $this->superToken();
        foreach (['bosta.eg', 'my carrier', str_repeat('a', 45)] as $code) {
            $this->api($token, 'POST', '/api/v1/admin/shipping-companies', [
                'name' => 'شركة', 'code' => $code,
            ])->assertStatus(422)->assertJsonValidationErrors('code');
        }
        $this->postJson('/api/v1/webhooks/shipping/bosta.eg', [])->assertNotFound();

        $id = $this->api($token, 'POST', '/api/v1/admin/shipping-companies', [
            'name' => 'بوسطة', 'code' => 'BOSTA',
            'api_credentials' => ['webhook_secret' => 'S'],
        ])->assertCreated()->json('data.id');
        $this->assertSame('bosta', ShippingCompany::query()->findOrFail($id)->code);
        $body = json_encode(['awb_number' => 'NONE', 'status' => 'delivered'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/webhooks/shipping/bosta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $body, 'S'),
        ], $body)->assertOk()->assertJsonPath('message', 'shipment not found');
    }

    public function test_tracking_templates_reject_unsafe_urls_and_encode_the_waybill(): void
    {
        $token = $this->superToken();
        foreach ([
            'javascript:alert(1)/{awb}',
            'http://x/{awb}',
            'https://x/track',
            'HTTPS://x/{awb}',
            'https://x/{awb}'.str_repeat('a', 490),
        ] as $template) {
            $this->api($token, 'POST', '/api/v1/admin/shipping-companies', [
                'name' => 'تتبع', 'code' => 'track'.substr(md5($template), 0, 8),
                'tracking_url_template' => $template,
            ])->assertStatus(422)->assertJsonValidationErrors('tracking_url_template');
        }
        $company = ShippingCompany::factory()->create(['tracking_url_template' => 'https://x/{awb}']);
        $shipment = Shipment::factory()->create([
            'shipping_company_id' => $company->id,
            'awb_number' => 'A B/"<x>',
            'status' => ShipmentStatus::Created,
        ]);
        $this->assertSame('https://x/A%20B%2F%22%3Cx%3E', $shipment->trackingUrl());
    }

    public function test_deleting_a_company_with_shipments_is_blocked_and_zones_cascade(): void
    {
        $token = $this->superToken();
        $city = City::factory()->create(['shipping_cost' => 30, 'is_active' => true]);
        $company = ShippingCompany::factory()->create();
        $zone = ShippingZone::factory()->create(['is_active' => true]);
        $zone->cities()->sync([$city->id]);
        ShippingRate::factory()->create([
            'shipping_company_id' => $company->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 80,
        ]);
        $order = Order::factory()->create();
        Shipment::factory()->create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'status' => ShipmentStatus::Created,
        ]);

        $this->api($token, 'DELETE', '/api/v1/admin/shipping-companies/'.$company->id)->assertStatus(422);
        $this->assertNotNull($company->fresh());
        $this->assertSame(1, $company->shipments()->count());

        $empty = ShippingCompany::factory()->create();
        $rate = ShippingRate::factory()->create([
            'shipping_company_id' => $empty->id,
            'shipping_zone_id' => ShippingZone::factory()->create()->id,
        ]);
        $this->api($token, 'DELETE', '/api/v1/admin/shipping-companies/'.$empty->id)->assertOk();
        $this->assertNull(ShippingRate::query()->find($rate->id));

        $this->assertSame(80.0, app(ShippingQuoteService::class)->quoteForCity($city->id, 10, false)['cost']);
        $this->api($token, 'DELETE', '/api/v1/admin/shipping-zones/'.$zone->id)->assertOk();
        $this->assertSame(0, DB::table('shipping_zone_cities')->where('shipping_zone_id', $zone->id)->count());
        $this->assertSame(0, ShippingRate::query()->where('shipping_zone_id', $zone->id)->count());
        $fallback = app(ShippingQuoteService::class)->quoteForCity($city->id, 10, true);
        $this->assertSame(30.0, $fallback['cost']);
        $this->assertSame(0.0, $fallback['cod_fee']);
    }

    public function test_rate_and_zone_validation_boundaries(): void
    {
        $token = $this->superToken();
        $company = ShippingCompany::factory()->create();
        $zone = ShippingZone::factory()->create();
        $city = City::factory()->create(['is_active' => false]);
        $base = $this->ratePayload($company->id, $zone->id);

        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', [...$base, 'base_cost' => 100000000])->assertStatus(422);
        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', [...$base, 'base_cost' => -1])->assertStatus(422);
        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', [...$base, 'base_cost' => 1.234])->assertStatus(422);
        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', [...$base, 'base_cost' => 99999999.99])->assertCreated();
        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', [...$base, 'estimated_days_max' => 0])->assertStatus(422);
        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', [...$base, 'estimated_days_min' => 3, 'estimated_days_max' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('estimated_days_max');
        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', [...$base, 'estimated_days_min' => 366])->assertStatus(422);
        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', [...$base, 'shipping_company_id' => 999999])->assertStatus(422);
        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', [...$base, 'shipping_zone_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('shipping_zone_id');

        $second = ShippingZone::factory()->create();
        $this->api($token, 'POST', '/api/v1/admin/shipping-rates', $this->ratePayload($company->id, $zone->id))
            ->assertStatus(422)->assertJsonValidationErrors('shipping_zone_id');
        $existing = ShippingRate::factory()->create(['shipping_company_id' => $company->id, 'shipping_zone_id' => $second->id]);
        $this->api($token, 'PUT', '/api/v1/admin/shipping-rates/'.$existing->id, $this->ratePayload($company->id, $zone->id))
            ->assertUnprocessable()->assertJsonValidationErrors('shipping_zone_id');
        $this->assertSame($second->id, $existing->fresh()->shipping_zone_id);

        $this->api($token, 'POST', '/api/v1/admin/shipping-zones', [
            'name' => 'مدن', 'city_ids' => array_fill(0, 501, $city->id),
        ])->assertStatus(422);
        $this->api($token, 'POST', '/api/v1/admin/shipping-zones', [
            'name' => 'ناقصة', 'city_ids' => [999999],
        ])->assertStatus(422);
        $created = $this->api($token, 'POST', '/api/v1/admin/shipping-zones', [
            'name' => 'مكررة', 'city_ids' => [$city->id, $city->id],
        ])->assertCreated();
        $this->assertSame(1, DB::table('shipping_zone_cities')->where('shipping_zone_id', $created->json('data.id'))->count());
        $this->api($token, 'PUT', '/api/v1/admin/shipping-zones/'.$created->json('data.id'), [
            'name' => 'بدون مدن',
        ])->assertOk();
        $this->assertSame($city->id, (int) DB::table('shipping_zone_cities')->where('shipping_zone_id', $created->json('data.id'))->value('city_id'));
        $this->api($token, 'PUT', '/api/v1/admin/shipping-zones/'.$created->json('data.id'), [
            'name' => 'فاضية', 'city_ids' => [],
        ])->assertOk();
        $this->assertSame(0, DB::table('shipping_zone_cities')->where('shipping_zone_id', $created->json('data.id'))->count());
        $this->api($token, 'POST', '/api/v1/admin/shipping-zones', [
            'name' => 'معطّلة', 'city_ids' => [$city->id],
        ])->assertCreated();
        $this->api($token, 'PATCH', '/api/v1/admin/shipping-companies/'.$company->id, ['name' => 'جزء'])->assertStatus(422);
    }

    public function test_rate_index_query_count_stays_flat_as_rows_grow(): void
    {
        $token = $this->superToken();
        $company = ShippingCompany::factory()->create();
        $zone = ShippingZone::factory()->create();
        ShippingRate::factory()->create(['shipping_company_id' => $company->id, 'shipping_zone_id' => $zone->id]);
        $this->api($token, 'GET', '/api/v1/admin/shipping-rates')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->api($token, 'GET', '/api/v1/admin/shipping-rates')->assertOk();
        $few = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(1, 5) as $ignored) {
            ShippingRate::factory()->create([
                'shipping_company_id' => ShippingCompany::factory()->create()->id,
                'shipping_zone_id' => ShippingZone::factory()->create()->id,
            ]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->api($token, 'GET', '/api/v1/admin/shipping-rates')->assertOk();
        $this->assertSame($few, count(DB::getQueryLog()));

        $big = ShippingZone::factory()->create();
        $ids = [];
        foreach (range(1, 30) as $i) {
            $ids[] = City::factory()->create(['name_ar' => 'مدينة '.$i])->id;
        }
        $big->cities()->sync($ids);
        DB::flushQueryLog();
        $this->api($token, 'GET', '/api/v1/admin/shipping-zones')->assertOk();
        $withCities = count(DB::getQueryLog());
        foreach (range(1, 20) as $i) {
            $ids[] = City::factory()->create(['name_ar' => 'أخرى '.$i])->id;
        }
        $big->cities()->sync($ids);
        DB::flushQueryLog();
        $this->api($token, 'GET', '/api/v1/admin/shipping-zones')->assertOk();
        $this->assertSame($withCities, count(DB::getQueryLog()));
    }

    /** @return array<string, int|float> */
    private function ratePayload(int $companyId, int $zoneId, float $base = 50): array
    {
        return [
            'shipping_company_id' => $companyId,
            'shipping_zone_id' => $zoneId,
            'base_cost' => $base,
            'cod_fee' => 0,
            'estimated_days_min' => 1,
            'estimated_days_max' => 3,
        ];
    }

    private function superToken(): string
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));

        return $admin->createToken('super')->plainTextToken;
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions): array
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $user = User::factory()->create();
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return [$user, $user->createToken('staff')->plainTextToken];
    }

    private function foreignGuardToken(): string
    {
        $user = User::factory()->create();
        $permission = Permission::findOrCreate('shipping.view', 'web');
        DB::table('model_has_permissions')->insert([
            'permission_id' => $permission->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
        ]);

        return $user->createToken('foreign')->plainTextToken;
    }

    private function api(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }
}

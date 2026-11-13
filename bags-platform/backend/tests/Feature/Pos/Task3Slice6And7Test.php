<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

class Task3Slice6And7Test extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    public function test_pos_customer_search_returns_empty_when_query_is_short_or_empty(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.access', 'pos.customers']);

        Customer::factory()->create([
            'name' => 'Ahmed Mohamed',
            'phone' => '01012345678',
            'status' => CustomerStatus::Approved,
        ]);

        // Empty query
        $resEmpty = $this->as($token)->getJson('/api/v1/pos/customers?q=')->assertOk()->json('data');
        $this->assertSame([], $resEmpty);

        // Single character query (< 2 characters)
        $resShort = $this->as($token)->getJson('/api/v1/pos/customers?q=A')->assertOk()->json('data');
        $this->assertSame([], $resShort);

        // >= 2 characters matches
        $resMatch = $this->as($token)->getJson('/api/v1/pos/customers?q=01012')->assertOk()->json('data');
        $this->assertCount(1, $resMatch);
        $this->assertSame('Ahmed Mohamed', $resMatch[0]['name']);
    }

    public function test_pos_customer_store_reports_informative_status_for_trashed_or_suspended(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.access', 'pos.customers']);

        $trashed = Customer::factory()->create([
            'name' => 'Old Customer',
            'phone' => '01099999999',
            'status' => CustomerStatus::Approved,
        ]);
        $trashed->delete();

        $suspended = Customer::factory()->create([
            'name' => 'Bad Customer',
            'phone' => '01088888888',
            'status' => CustomerStatus::Suspended,
        ]);

        // 1. Trying to register with trashed phone
        $this->as($token)->postJson('/api/v1/pos/customers', [
            'name' => 'New Trashed',
            'phone' => '01099999999',
            'type' => 'retail',
        ])->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $res1 = $this->as($token)->postJson('/api/v1/pos/customers', [
            'name' => 'New Trashed',
            'phone' => '01099999999',
            'type' => 'retail',
        ])->json('errors.phone.0');
        $this->assertStringContainsString('محذوف ناعمًا', $res1);

        // 2. Trying to register with suspended phone
        $res2 = $this->as($token)->postJson('/api/v1/pos/customers', [
            'name' => 'New Suspended',
            'phone' => '01088888888',
            'type' => 'retail',
        ])->assertStatus(422)->json('errors.phone.0');
        $this->assertStringContainsString('موقوف', $res2);
    }

    public function test_pos_bootstrap_includes_return_window_days_in_settings(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.access']);

        $res = $this->as($token)->getJson('/api/v1/pos/bootstrap')->assertOk()->json('data');

        $this->assertArrayHasKey('settings', $res);
        $this->assertArrayHasKey('return_window_days', $res['settings']);
        $this->assertSame(30, $res['settings']['return_window_days']);
    }
}

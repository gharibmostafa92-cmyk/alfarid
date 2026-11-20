<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Models\City;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Services\Finance\WalletService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ACCOUNT = '/api/v1/store/account';

    private const NOTIFICATIONS = '/api/v1/store/notifications';

    public function test_account_and_notification_endpoints_require_authentication(): void
    {
        foreach (['orders', 'wallet', 'returns', 'addresses'] as $resource) {
            $this->getJson(self::ACCOUNT.'/'.$resource)->assertUnauthorized();
        }
        $this->getJson(self::NOTIFICATIONS)->assertUnauthorized();
        $this->postJson(self::NOTIFICATIONS.'/read-all')->assertUnauthorized();
    }

    public function test_customer_cancellation_respects_order_state_and_ownership(): void
    {
        $customer = $this->signIn();
        $cancel = self::ACCOUNT.'/orders/';
        foreach (['pending', 'confirmed'] as $status) {
            $order = Order::factory()->for($customer)->create(['status' => $status]);
            $this->postJson($cancel.$order->id.'/cancel')->assertOk()
                ->assertJsonPath('data.status', 'cancelled');
            $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        }
        foreach (['processing', 'shipped', 'delivered'] as $status) {
            $order = Order::factory()->for($customer)->create(['status' => $status]);
            $this->postJson($cancel.$order->id.'/cancel')->assertUnprocessable()
                ->assertJsonPath('message', 'لا يمكن إلغاء الطلب في حالته الحالية؛ الإلغاء متاح قبل بدء التجهيز والشحن فقط.');
        }
        $otherOrder = Order::factory()->for(Customer::factory())->create();
        $this->postJson($cancel.$otherOrder->id.'/cancel')->assertNotFound();
    }

    public function test_wallet_ledger_paginates_and_filters_amounts_and_dates(): void
    {
        $customer = $this->signIn();
        $url = self::ACCOUNT.'/wallet';
        $this->getJson($url)->assertOk()->assertJsonPath('balance', '0.00')->assertJsonCount(0, 'transactions.data');

        $wallet = app(WalletService::class);
        $firstCredit = $wallet->record($customer->id, '10.00', 'topup', 'topup', 1)->refresh();
        foreach (range(2, 20) as $reference) {
            $wallet->record($customer->id, '10.00', 'topup', 'topup', $reference);
        }
        $order = Order::factory()->for($customer)->create();
        $debit = $wallet->record($customer->id, '-20.00', 'checkout_payment', 'order', $order->id)->refresh();
        $timezone = config('app.business_timezone');
        $creditDate = $firstCredit->created_at->setTimezone($timezone);
        $from = $creditDate->toDateString();
        $to = $debit->created_at->setTimezone($timezone)->toDateString();
        $before = $creditDate->subDay()->toDateString();

        $this->getJson($url)->assertOk()->assertJsonPath('balance', '180.00')
            ->assertJsonCount(20, 'transactions.data')->assertJsonPath('transactions.last_page', 2)
            ->assertJsonPath('transactions.data.0.type_label', 'دفع طلب')
            ->assertJsonPath('transactions.data.0.reference_label', 'طلب #'.$order->id);
        $this->getJson($url.'?page=2')->assertOk()->assertJsonPath('balance', '180.00')
            ->assertJsonCount(1, 'transactions.data');
        $this->getJson($url."?direction=debit&from={$from}&to={$to}")->assertOk()
            ->assertJsonCount(1, 'transactions.data')->assertJsonPath('transactions.data.0.amount', '-20.00');
        $this->getJson($url."?direction=credit&from={$from}&to={$to}")->assertOk()
            ->assertJsonPath('transactions.total', 20)->assertJsonPath('transactions.data.0.amount', '10.00');
        $this->getJson($url."?to={$before}")->assertOk()->assertJsonCount(0, 'transactions.data');
    }

    public function test_return_upload_validation_and_customer_listing(): void
    {
        $customer = $this->signIn();
        $url = self::ACCOUNT.'/returns';
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');

        $order = Order::factory()->for($customer)->create(['status' => 'delivered']);
        $order->statusHistories()->create(['from_status' => 'shipped', 'to_status' => 'delivered']);
        $items = OrderItem::factory()->count(3)->for($order)->create();
        $payload = ['order_id' => $order->id, 'reason' => 'المقاس غير مناسب', 'items' => [
            ['order_item_id' => $items[0]->id, 'requested_qty' => 1],
        ]];
        $otherOrder = Order::factory()->for(Customer::factory())->create(['status' => 'delivered']);
        $this->postJson($url, array_replace($payload, ['order_id' => $otherOrder->id]))->assertNotFound();

        $this->withHeader('Accept', 'application/json');
        foreach ([UploadedFile::fake()->create('proof.pdf', 1, 'application/pdf'), UploadedFile::fake()->image('large.png')->size(5121)] as $file) {
            $payload['items'][0]['attachments'] = [$file];
            $this->post($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.attachments.0');
        }
        foreach ([[[5, 5, 1], 1, 'الحد الأقصى لمرفقات طلب الإرجاع 10 ملفات.'],
            [[3, 3, 0], 4500, 'الحجم الإجمالي لمرفقات طلب الإرجاع يجب ألا يتجاوز 25 ميغابايت.']] as [$counts, $size, $error]) {
            $lines = $items->map(fn (OrderItem $item, int $index): array => [
                'order_item_id' => $item->id,
                'requested_qty' => 1,
                'attachments' => array_map(fn () => UploadedFile::fake()->image('proof.png')->size($size), array_fill(0, $counts[$index], null)),
            ])->all();
            $this->post($url, array_replace($payload, ['items' => $lines]))->assertUnprocessable()
                ->assertJsonPath('errors.items.0', $error);
        }
        $this->assertSame([], Storage::disk('r2')->allFiles());

        ReturnRequest::factory()->count(20)->for($customer)->for($order)->create();
        $payload['items'][0]['attachments'] = [UploadedFile::fake()->image('proof.png')];
        $created = $this->post($url, $payload)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertCount(1, Storage::disk('r2')->allFiles());
        $this->getJson($url)->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.id', $created)->assertJsonPath('meta.last_page', 2);
        $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_customer_addresses_and_notification_read_flow(): void
    {
        $customer = $this->signIn();
        $city = City::factory()->create(['name_ar' => 'القاهرة', 'name_en' => 'Cairo']);
        foreach (['Default address' => true, 'Older address' => false, 'Newer address' => false] as $line => $default) {
            $customer->addresses()->create(['address_line' => $line, 'city_id' => $city->id, 'is_default' => $default]);
        }
        $result = $this->getJson(self::ACCOUNT.'/addresses')->assertOk()
            ->assertJsonPath('data.0.city_name_ar', 'القاهرة')->assertJsonPath('data.0.city_name_en', 'Cairo')
            ->assertJsonPath('data.0.is_default', true);
        $this->assertSame(['Default address', 'Newer address', 'Older address'], array_column($result->json('data'), 'address_line'));

        $ids = [];
        foreach ([now()->subMinute(), null, null] as $readAt) {
            $ids[] = $id = (string) Str::uuid();
            $customer->notifications()->create(['id' => $id, 'type' => 'test', 'data' => [], 'read_at' => $readAt]);
        }
        $url = self::NOTIFICATIONS;
        $this->getJson($url)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.unread_count', 2);
        $this->getJson('/api/v1/store/auth/notifications')->assertOk()->assertJsonPath('meta.unread_count', 2);
        $this->getJson($url.'?unread_only=1')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissing(['id' => $ids[0]]);
        $this->postJson($url.'/not-a-uuid/read')->assertNotFound();
        $read = $this->postJson($url.'/'.$ids[1].'/read')->assertOk()->assertJsonPath('meta.unread_count', 1);
        $this->assertNotNull($read->json('data.read_at'));
        $this->postJson($url.'/read-all')->assertOk()->assertJsonPath('meta.unread_count', 0);
        $this->getJson($url.'?unread_only=1')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNotNull($customer->notifications()->findOrFail($ids[2])->read_at);
    }

    private function signIn(): Customer
    {
        $customer = Customer::factory()->create();
        $this->withToken($customer->createToken('store-token')->plainTextToken);

        return $customer;
    }
}

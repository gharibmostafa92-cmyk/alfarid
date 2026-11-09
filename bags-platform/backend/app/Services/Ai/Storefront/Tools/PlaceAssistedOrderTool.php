<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Models\AiPendingAction;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Storefront\CheckoutPreview;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Orders\OrderService;
use Illuminate\Support\Str;

final class PlaceAssistedOrderTool implements WriteTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private CheckoutPreview $preview,
        private OrderService $orders,
    ) {}

    public function name(): string
    {
        return 'place_assisted_order';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'high';
    }

    public function permissions(): array
    {
        return ['orders.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء طلب لعميل معروف من سلته بعد المعاينة. بيحجز المخزون. مفيش رابط دفع. الزائر ممنوع. يحتاج تأكيدًا بآخر 4 أرقام من موبايل العميل.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['customer_id', 'city_id', 'payment_method', 'shipping_address_line'],
                'properties' => (object) [
                    'customer_id' => ['type' => 'INTEGER'],
                    'cart_id' => ['type' => 'INTEGER'],
                    'city_id' => ['type' => 'INTEGER'],
                    'payment_method' => ['type' => 'STRING'],
                    'shipping_address_line' => ['type' => 'STRING'],
                    'shipping_notes' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $customer = Customer::query()->find((int) ($args['customer_id'] ?? 0));
        if (! $customer || ! $customer->canShop()) {
            return ToolOutcome::denied('الطلب المساعد لعميل معروف ومسموح له بالشراء فقط.', auditable: false);
        }
        $cart = ! empty($args['cart_id'])
            ? Cart::query()->with('items')->find((int) $args['cart_id'])
            : Cart::query()->with('items')->where('customer_id', $customer->id)->latest('id')->first();
        if (! $cart || (int) $cart->customer_id !== $customer->id) {
            return ToolOutcome::denied('مفيش سلة لهذا العميل.', auditable: false);
        }
        $address = trim((string) ($args['shipping_address_line'] ?? ''));
        if ($address === '') {
            return ToolOutcome::denied('عنوان الشحن مطلوب.', auditable: false);
        }
        $data = [
            'city_id' => (int) ($args['city_id'] ?? 0),
            'payment_method' => (string) ($args['payment_method'] ?? ''),
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'shipping_address_line' => mb_substr($address, 0, 500),
            'shipping_notes' => isset($args['shipping_notes']) ? mb_substr((string) $args['shipping_notes'], 0, 500) : null,
        ];
        $diagnosis = $this->preview->diagnose($cart, $customer, $data);
        if ($diagnosis['ok'] !== true) {
            return ToolOutcome::denied((string) $diagnosis['message'], auditable: false);
        }
        $preview = $diagnosis['preview'];
        $phone = (string) $customer->phone;
        $typed = strlen($phone) >= 4 ? substr($phone, -4) : $phone;

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data + [
                'customer_id' => $customer->id,
                'cart_id' => $cart->id,
                'expected_total' => $preview['grand_total'],
                'idempotency_key' => (string) Str::uuid(),
            ],
            risk: $this->risk(),
            title: 'طلب بمساعدة الموظف للعميل '.$customer->name,
            before: null,
            after: $preview,
            warnings: ['سيتم حجز المخزون. لا يوجد رابط دفع؛ الطلب يتكمل بطريقة الدفع المتاحة في النظام.'],
            typedConfirmation: $typed,
            typedConfirmationHint: 'آخر 4 أرقام من موبايل العميل',
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $customer = Customer::query()->findOrFail((int) $action->args['customer_id']);
        if (! $customer->canShop()) {
            return ToolOutcome::result(false, 'العميل لم يعد مسموحًا له بالشراء.');
        }
        $cart = Cart::query()->with('items')->findOrFail((int) $action->args['cart_id']);
        $order = $this->orders->createFromCart($cart, $action->args, $customer);

        return ToolOutcome::result(true, 'تم إنشاء الطلب. لا يوجد رابط دفع.', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'grand_total' => (float) $order->grand_total,
            'payment_url' => null,
            'payment_collection' => 'not_integrated',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Storefront\CheckoutPreview;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class PreviewCheckoutTool implements AgentTool
{
    public function __construct(private CheckoutPreview $preview) {}

    public function name(): string
    {
        return 'preview_checkout';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'عاين إتمام الشراء من غير ما تنشئ طلب ولا تحجز مخزون. payment_url دايمًا null لأن مفيش بوابة دفع.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'cart_id' => ['type' => 'INTEGER'],
                    'customer_id' => ['type' => 'INTEGER'],
                    'city_id' => ['type' => 'INTEGER'],
                    'payment_method' => ['type' => 'STRING'],
                    'recipient_name' => ['type' => 'STRING'],
                    'recipient_phone' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $cart = ! empty($args['cart_id'])
            ? Cart::query()->with('items')->find((int) $args['cart_id'])
            : (! empty($args['customer_id']) ? Cart::query()->with('items')->where('customer_id', (int) $args['customer_id'])->latest('id')->first() : null);
        if (! $cart) {
            return ToolOutcome::denied('حدد سلة موجودة بـ cart_id أو customer_id.', auditable: false);
        }
        $customer = $cart->customer_id ? Customer::query()->find($cart->customer_id) : null;

        return ToolOutcome::data($this->preview->diagnose($cart, $customer, $args));
    }
}

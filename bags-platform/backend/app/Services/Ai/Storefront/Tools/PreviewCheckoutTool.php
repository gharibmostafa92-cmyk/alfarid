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
        if (! empty($args['cart_id'])) {
            $cart = Cart::query()->with('items')->find((int) $args['cart_id']);
            if (! $cart) {
                return ToolOutcome::denied('السلة غير موجودة.', auditable: false);
            }
            if (! empty($args['customer_id']) && (int) $cart->customer_id !== (int) $args['customer_id']) {
                return ToolOutcome::denied('السلة مش بتاعة العميل ده.', auditable: false);
            }
        } elseif (! empty($args['customer_id'])) {
            $cart = Cart::query()->with('items')->where('customer_id', (int) $args['customer_id'])->latest('id')->first();
        } else {
            $cart = null;
        }
        if (! $cart) {
            return ToolOutcome::denied('حدد سلة موجودة بـ cart_id أو customer_id.', auditable: false);
        }
        if ($cart->customer_id === null) {
            return ToolOutcome::denied('فحص سلات الزوار غير مسموح بمعرف السلة.', auditable: false);
        }
        if (! $actor->can('customers.view')) {
            return ToolOutcome::denied('محتاج صلاحية عرض العملاء لمعاينة سلة العميل.', auditable: false);
        }
        $customer = Customer::query()->find($cart->customer_id);

        return ToolOutcome::data($this->preview->diagnose($cart, $customer, $args));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\CartService;
use App\Services\CouponService;

final class DiagnoseCartTool implements AgentTool
{
    public function __construct(private CartService $carts, private CouponService $coupons) {}

    public function name(): string
    {
        return 'diagnose_cart';
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
            'description' => 'اشرح سلة عميل أو سلة معروفة برقمها: السعر والتوفر وسقف الكمية وسبب رفض الكوبون. متدورش بتوكن الزائر.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'cart_id' => ['type' => 'INTEGER'],
                    'customer_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $cart = $this->cart($args);
        if ($cart instanceof ToolOutcome) {
            return $cart;
        }
        $customer = $cart->customer_id ? Customer::query()->find($cart->customer_id) : null;
        $lines = $this->carts->pricedLines($cart, $customer);
        $cap = $this->carts->lineQtyCap($customer);
        $presented = [];
        foreach ($lines as $line) {
            $issues = [];
            if ($line['unit_price'] === null) {
                $issues[] = 'غير مسعّر أو غير متاح للبيع.';
            }
            if ($cap > 0 && (float) $line['qty'] > $cap) {
                $issues[] = "الكمية أعلى من السقف ({$cap}).";
            }
            foreach ([
                fn () => $this->carts->assertLinesPurchasable([$line]),
                fn () => $this->carts->assertLinesAvailable([$line]),
            ] as $check) {
                try {
                    $check();
                } catch (BusinessException $e) {
                    $issues[] = $e->getMessage();
                }
            }
            $presented[] = [
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant']->id,
                'sku' => $line['variant']->sku,
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
                'qty_cap' => $cap,
                'issues' => array_values(array_unique($issues)),
            ];
        }
        $coupon = null;
        if ($cart->coupon_id !== null) {
            $totals = $this->carts->totals($cart, $customer, $lines);
            try {
                $model = $this->coupons->redeemableById((int) $cart->coupon_id, $totals['items_total'], $customer);
                $coupon = ['code' => $model->code, 'ok' => true, 'discount' => $this->coupons->discountFor($model, $totals['items_total'])];
            } catch (BusinessException $e) {
                $coupon = ['ok' => false, 'code' => 'coupon_invalid', 'message' => $e->getMessage()];
            }
        }

        return ToolOutcome::data([
            'cart_id' => $cart->id,
            'customer_id' => $cart->customer_id,
            'lines' => $presented,
            'coupon' => $coupon,
        ]);
    }

    private function cart(array $args): Cart|ToolOutcome
    {
        if (! empty($args['cart_id'])) {
            $cart = Cart::query()->with('items')->find((int) $args['cart_id']);
            if (! $cart) {
                return ToolOutcome::denied('السلة غير موجودة.', auditable: false);
            }
            if (! empty($args['customer_id']) && (int) $cart->customer_id !== (int) $args['customer_id']) {
                return ToolOutcome::denied('السلة مش بتاعة العميل ده.', auditable: false);
            }

            return $cart;
        }
        if (! empty($args['customer_id'])) {
            $cart = Cart::query()->with('items')->where('customer_id', (int) $args['customer_id'])->latest('id')->first();
            if (! $cart) {
                return ToolOutcome::denied('العميل معندوش سلة.', auditable: false);
            }

            return $cart;
        }

        return ToolOutcome::denied('حدد cart_id أو customer_id. توكن الزائر مش بيتبحث.', auditable: false);
    }
}

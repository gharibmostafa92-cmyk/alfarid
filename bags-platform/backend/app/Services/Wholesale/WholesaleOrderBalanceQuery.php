<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\RefundMethod;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds scoped per-order net balances for unpaid wholesale credit orders.
 */
final class WholesaleOrderBalanceQuery
{
    /**
     * Returns one SQL row per unpaid, non-cancelled, non-deleted credit order.
     *
     * Approved linked notices, approved allocations, and valid refunded credit notes are deducted
     * independently for each order before the remaining balance is clamped at zero.
     */
    public function unpaidCreditOrderBalances(): Builder
    {
        $linkedNotices = DB::table('wholesale_payment_notices')
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(amount) as total');
        $allocations = DB::table('wholesale_payment_allocations as a')
            ->join('wholesale_payment_notices as n', 'n.id', '=', 'a.notice_id')
            ->where('n.status', WholesalePaymentNoticeStatus::Approved->value)
            ->groupBy('a.order_id')
            ->selectRaw('a.order_id, SUM(a.amount) as total');
        $creditNotes = DB::table('return_requests as rr')
            ->join('orders as credit_note_order', 'credit_note_order.id', '=', 'rr.order_id')
            ->where('rr.status', 'refunded')
            ->where('rr.refund_method', RefundMethod::CreditNote->value)
            ->where('credit_note_order.payment_method', 'credit')
            ->where('credit_note_order.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('rr.order_id')
            ->selectRaw('rr.order_id, SUM(rr.total_refund_amount) as total');

        $orders = Order::query()
            ->leftJoinSub($linkedNotices, 'linked_notices', 'linked_notices.order_id', '=', 'orders.id')
            ->leftJoinSub($allocations, 'approved_allocations', 'approved_allocations.order_id', '=', 'orders.id')
            ->leftJoinSub($creditNotes, 'credit_notes', 'credit_notes.order_id', '=', 'orders.id')
            ->where('orders.payment_method', 'credit')
            ->where('orders.payment_status', 'unpaid')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->select(['orders.id', 'orders.customer_id', 'orders.due_at'])
            ->selectRaw('GREATEST(orders.grand_total - COALESCE(linked_notices.total, 0) - COALESCE(approved_allocations.total, 0) - COALESCE(credit_notes.total, 0), 0) as remaining');

        // Apply Order's global scopes (including SoftDeletes) before the builder becomes a SQL subquery.
        return $orders->applyScopes()->getQuery();
    }
}

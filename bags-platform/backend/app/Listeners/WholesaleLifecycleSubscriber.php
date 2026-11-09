<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderBecameOverdue;
use App\Events\Wholesale\PaymentNoticeReviewed;
use App\Events\Wholesale\PaymentNoticeSubmitted;
use App\Events\Wholesale\QuotationAccepted;
use App\Events\Wholesale\QuotationExpired;
use App\Events\Wholesale\QuotationPriced;
use App\Events\Wholesale\QuotationSubmitted;
use App\Jobs\ScoreWholesaleReceipt;
use App\Models\Customer;
use App\Notifications\StaffRecipients;
use App\Notifications\Wholesale\WholesaleDeskNotification;
use App\Services\Wholesale\WholesaleOutbox;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Handles wholesale lifecycle domain events: notifies staff and queues customer outbox notifications.
 * Notification delivery failures are caught and reported so they do not roll back the initiating transaction.
 */
final class WholesaleLifecycleSubscriber
{
    public function __construct(private readonly WholesaleOutbox $outbox) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(QuotationSubmitted::class, [self::class, 'onQuotationSubmitted']);
        $events->listen(QuotationPriced::class, [self::class, 'onQuotationPriced']);
        $events->listen(QuotationAccepted::class, [self::class, 'onQuotationAccepted']);
        $events->listen(QuotationExpired::class, [self::class, 'onQuotationExpired']);
        $events->listen(PaymentNoticeSubmitted::class, [self::class, 'onNoticeSubmitted']);
        $events->listen(PaymentNoticeReviewed::class, [self::class, 'onNoticeReviewed']);
        $events->listen(OrderBecameOverdue::class, [self::class, 'onOverdue']);
    }

    public function onQuotationSubmitted(QuotationSubmitted $event): void
    {
        $this->guard(function () use ($event): void {
            $quotation = $event->quotation;
            $this->notifyStaff(['quotations.price'], 'quotation_submitted', "عرض سعر {$quotation->quotation_number} مستني تسعير.", [
                'quotation_id' => $quotation->id,
            ]);
        });
    }

    public function onQuotationPriced(QuotationPriced $event): void
    {
        $this->guard(function () use ($event): void {
            $quotation = $event->quotation->loadMissing('customer');
            $customer = $quotation->customer;
            $message = "عرض السعر {$quotation->quotation_number} اتسعّر بإجمالي {$quotation->grand_total}.";
            $this->notifyCustomer($customer, 'quotation_priced', $message, ['quotation_id' => $quotation->id]);
            $this->outbox->queue(
                "quotation-priced:{$quotation->id}:{$quotation->revision}",
                'customer_whatsapp',
                $customer?->id,
                'quotation_priced',
                ['quotation_id' => $quotation->id, 'quotation_number' => $quotation->quotation_number, 'grand_total' => (string) $quotation->grand_total],
                $this->outbox->nextContactAt($customer),
                $this->outbox->customerAllowsWhatsapp($customer),
            );
        });
    }

    public function onQuotationAccepted(QuotationAccepted $event): void
    {
        $this->guard(function () use ($event): void {
            $quotation = $event->quotation;
            $this->notifyStaff(['quotations.view'], 'quotation_accepted', "التاجر قبل عرض السعر {$quotation->quotation_number}.", [
                'quotation_id' => $quotation->id,
                'order_id' => $quotation->converted_order_id,
            ]);
        });
    }

    public function onQuotationExpired(QuotationExpired $event): void
    {
        $this->guard(function () use ($event): void {
            $quotation = $event->quotation->loadMissing('customer');
            $this->notifyCustomer($quotation->customer, 'quotation_expired', "صلاحية عرض السعر {$quotation->quotation_number} انتهت.", [
                'quotation_id' => $quotation->id,
            ]);
        });
    }

    public function onNoticeSubmitted(PaymentNoticeSubmitted $event): void
    {
        $this->guard(function () use ($event): void {
            $notice = $event->notice->loadMissing('customer');
            $this->notifyStaff(['payment_notices.approve'], 'payment_notice_submitted', "إشعار سداد {$notice->notice_number} مستني مراجعة.", [
                'notice_id' => $notice->id,
            ]);
            $this->outbox->queue(
                "payment-notice-submitted:{$notice->id}",
                'customer_whatsapp',
                $notice->customer_id,
                'payment_notice_submitted',
                ['notice_id' => $notice->id, 'notice_number' => $notice->notice_number, 'amount' => (string) $notice->amount],
                $this->outbox->nextContactAt($notice->customer),
                $this->outbox->customerAllowsWhatsapp($notice->customer),
            );
            ScoreWholesaleReceipt::dispatch($notice->id);
        });
    }

    public function onNoticeReviewed(PaymentNoticeReviewed $event): void
    {
        $this->guard(function () use ($event): void {
            $notice = $event->notice->loadMissing('customer');
            $status = $notice->status instanceof \BackedEnum ? $notice->status->value : (string) $notice->status;
            $message = $status === 'approved'
                ? "إشعار السداد {$notice->notice_number} اتعمد."
                : "إشعار السداد {$notice->notice_number} اترفض." . ($notice->admin_notes ? ' السبب: ' . $notice->admin_notes : '');
            $this->notifyCustomer($notice->customer, 'payment_notice_reviewed', $message, [
                'notice_id' => $notice->id,
                'status' => $status,
            ]);
            $this->outbox->queue(
                "payment-notice-reviewed:{$notice->id}:{$status}",
                'customer_whatsapp',
                $notice->customer_id,
                'payment_notice_reviewed',
                ['notice_id' => $notice->id, 'notice_number' => $notice->notice_number, 'status' => $status],
                $this->outbox->nextContactAt($notice->customer),
                $this->outbox->customerAllowsWhatsapp($notice->customer),
            );
        });
    }

    public function onOverdue(OrderBecameOverdue $event): void
    {
        $this->guard(function () use ($event): void {
            $order = $event->order->loadMissing('customer');
            $due = $order->due_at?->toDateString() ?? '';
            $message = "طلب {$order->order_number} تأخر عن الاستحقاق {$due}.";
            $this->notifyCustomer($order->customer, 'order_overdue', $message, ['order_id' => $order->id]);
            $this->notifyStaff(['payment_notices.approve'], 'order_overdue', $message, ['order_id' => $order->id]);
            $this->outbox->queue(
                "order-overdue:{$order->id}:{$due}",
                'customer_whatsapp',
                $order->customer_id,
                'order_overdue_reminder',
                ['order_id' => $order->id, 'order_number' => $order->order_number, 'due_at' => $due],
                $this->outbox->nextContactAt($order->customer),
                $this->outbox->customerAllowsWhatsapp($order->customer),
            );
        });
    }

    /** @param  list<string>  $permissions @param  array<string, mixed>  $payload */
    private function notifyStaff(array $permissions, string $kind, string $message, array $payload): void
    {
        $recipients = StaffRecipients::withAnyPermission($permissions);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new WholesaleDeskNotification($kind, $message, $payload));
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function notifyCustomer(?Customer $customer, string $kind, string $message, array $payload): void
    {
        $customer?->notify(new WholesaleDeskNotification($kind, $message, $payload));
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}

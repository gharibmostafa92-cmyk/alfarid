<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\QuotationStatus;
use App\Models\OutboundMessage;
use App\Models\Quotation;
use App\Notifications\WholesaleDeskNotification;
use App\Services\Wholesale\WholesaleOutbox;
use Illuminate\Console\Command;

/**
 * Sends quotation expiry reminder notifications to merchants ahead of quote validity deadlines.
 */
class QuoteExpiryReminders extends Command
{
    protected $signature = 'wholesale:quote-expiry-reminders';

    protected $description = 'Remind opted-in traders 48 hours before a priced quotation expires';

    public function handle(WholesaleOutbox $outbox): int
    {
        $sent = 0;
        $until = now()->addDays(2)->toDateString();
        Quotation::query()
            ->with('customer')
            ->where('status', QuotationStatus::PricedByAdmin)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '>', now()->toDateString())
            ->whereDate('valid_until', '<=', $until)
            ->orderBy('id')
            ->each(function (Quotation $quotation) use ($outbox, &$sent): void {
                $valid = $quotation->valid_until?->toDateString() ?? '';
                $key = "quote-expiry:{$quotation->id}:{$valid}";
                if (OutboundMessage::query()->where('idempotency_key', $key)->exists()) {
                    return;
                }
                $customer = $quotation->customer;
                $message = "عرض السعر {$quotation->quotation_number} هينتهي يوم {$valid}.";
                $customer?->notify(new WholesaleDeskNotification('quotation_expiring', $message, [
                    'quotation_id' => $quotation->id,
                ]));
                $outbox->queue(
                    $key,
                    'customer_whatsapp',
                    $customer?->id,
                    'quotation_expiring',
                    ['quotation_id' => $quotation->id, 'quotation_number' => $quotation->quotation_number, 'valid_until' => $valid],
                    $outbox->nextContactAt($customer),
                    $outbox->customerAllowsWhatsapp($customer),
                );
                $sent++;
            });

        $this->info("Queued {$sent} quotation expiry reminders.");

        return self::SUCCESS;
    }
}

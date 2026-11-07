<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Transitions priced quotations past their validity window to expired status.
 */
class ExpireQuotations extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'Expire priced quotations whose valid_until date has passed';

    public function handle(QuotationService $quotations): int
    {
        $expired = 0;
        Quotation::query()
            ->where('status', QuotationStatus::PricedByAdmin)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->orderBy('id')
            ->each(function (Quotation $quotation) use ($quotations, &$expired): void {
                try {
                    $quotations->expire($quotation);
                    $expired++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info("Expired {$expired} quotations.");

        return self::SUCCESS;
    }
}

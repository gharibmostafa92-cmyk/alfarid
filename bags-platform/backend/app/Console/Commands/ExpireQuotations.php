<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Services\Wholesale\QuotationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Transitions priced quotations past their validity window to expired status.
 */
class ExpireQuotations extends Command
{
    protected $signature = 'quotations:expire {--dry-run : Count expired quotations without changing them}';

    protected $description = 'Expire priced quotations whose valid_until date has passed';

    public function handle(QuotationService $quotations): int
    {
        $query = Quotation::query()
            ->where('status', QuotationStatus::PricedByAdmin)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString());

        if ($this->option('dry-run')) {
            $this->info("Would expire {$query->count()} quotations.");

            return self::SUCCESS;
        }

        $expired = 0;
        $query->orderBy('id')
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

<?php

declare(strict_types=1);

namespace App\Console\Commands\Orders;

use Illuminate\Console\Command;

/**
 * Legacy command alias for migrating uploaded attachments to private R2 storage.
 */
class PrivatizeReturnAttachments extends Command
{
    protected $signature = 'returns:privatize-attachments';

    // Kept as a backwards-compatible alias for existing runbooks and cron entries.
    // Migrates all legacy uploads (not returns only) via uploads:migrate-to-r2.
    protected $description = 'Migrate all legacy uploads to private R2 storage (alias of uploads:migrate-to-r2, not returns-only)';

    public function handle(): int
    {
        return $this->call('uploads:migrate-to-r2', ['--delete-source' => true]);
    }
}

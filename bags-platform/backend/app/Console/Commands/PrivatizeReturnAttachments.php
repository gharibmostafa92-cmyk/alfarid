<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PrivatizeReturnAttachments extends Command
{
    protected $signature = 'returns:privatize-attachments';

    // L10: the name is misleading — this migrates ALL legacy uploads to
    // private R2 (uploads:migrate-to-r2), not just return attachments.
    // Kept as a thin alias for existing runbooks/cron; prefer calling
    // uploads:migrate-to-r2 directly for new schedules.
    protected $description = 'Migrate all legacy uploads to private R2 storage (alias of uploads:migrate-to-r2, not returns-only)';

    public function handle(): int
    {
        return $this->call('uploads:migrate-to-r2', ['--delete-source' => true]);
    }
}

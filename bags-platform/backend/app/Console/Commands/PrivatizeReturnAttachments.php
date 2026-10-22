<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PrivatizeReturnAttachments extends Command
{
    protected $signature = 'returns:privatize-attachments';

    protected $description = 'Move legacy uploads to private R2 storage and remove verified local copies';

    public function handle(): int
    {
        return $this->call('uploads:migrate-to-r2', ['--delete-source' => true]);
    }
}

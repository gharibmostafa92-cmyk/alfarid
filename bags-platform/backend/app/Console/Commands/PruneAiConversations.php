<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PruneAiConversations as Job;
use Illuminate\Console\Command;

final class PruneAiConversations extends Command
{
    protected $signature = 'ai:prune-conversations';

    protected $description = 'Prune expired AI conversations and pending actions';

    public function handle(): int
    {
        Job::dispatchSync();

        return self::SUCCESS;
    }
}

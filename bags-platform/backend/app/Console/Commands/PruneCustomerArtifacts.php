<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneCustomerArtifacts extends Command
{
    protected $signature = 'customers:prune-artifacts';

    protected $description = 'Remove abandoned private staging files, expired phone challenges and old message counters';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        foreach ($disk->directories('customer_documents/pending') as $directory) {
            if (! preg_match('~^customer_documents/pending/[a-f0-9-]{36}$~D', $directory)) {
                continue;
            }
            $newest = filemtime($disk->path($directory));
            foreach ($disk->allFiles($directory) as $file) {
                $newest = max($newest, $disk->lastModified($file));
            }
            if ($newest < now()->subHour()->timestamp) {
                $disk->deleteDirectory($directory);
            }
        }
        foreach ($disk->directories('customer_documents') as $directory) {
            if (! preg_match('~^customer_documents/([0-9]+)$~D', $directory, $matches)
                || Customer::withTrashed()->whereKey($matches[1])->exists()) {
                continue;
            }
            $newest = filemtime($disk->path($directory));
            foreach ($disk->allFiles($directory) as $file) {
                $newest = max($newest, $disk->lastModified($file));
            }
            if ($newest < now()->subHour()->timestamp) {
                $disk->deleteDirectory($directory);
            }
        }
        DB::table('customer_phone_verifications')->where('expires_at', '<', now()->subHour())->delete();
        DB::table('customer_message_budgets')->where('date', '<', now()->subDays(30)->toDateString())->delete();
        $this->info('Expired customer artifacts pruned.');

        return self::SUCCESS;
    }
}

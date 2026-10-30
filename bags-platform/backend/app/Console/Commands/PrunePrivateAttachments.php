<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PrunePrivateAttachments extends Command
{
    protected $signature = 'attachments:prune-orphans {--hours=24} {--dry-run}';

    protected $description = 'Remove old private attachments with no database reference';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 24) {
            $this->error('Use a grace period of at least 24 hours.');

            return self::FAILURE;
        }
        $disk = Storage::disk('r2');
        $cutoff = now()->subHours($hours)->timestamp;
        $failures = 0;
        foreach ([
            'return_requests' => ['return_request_items', 'attachments'],
            'supplier_invoices' => ['supplier_invoices', 'attachment_path'],
            'supplier_deliveries' => ['supplier_deliveries', 'invoice_file_path'],
            'wholesale_payment_notices' => ['wholesale_payment_notices', 'receipt_file_path'],
        ] as $prefix => [$table, $column]) {
            try {
                $batch = [];
                foreach ($disk->getDriver()->listContents($prefix, true) as $entry) {
                    if ($entry->isFile() && ($entry->lastModified() ?? PHP_INT_MAX) < $cutoff) {
                        $batch[] = $entry->path();
                        if (count($batch) === 500) {
                            $this->prune($batch, $table, $column);
                            $batch = [];
                        }
                    }
                }
                if ($batch !== []) {
                    $this->prune($batch, $table, $column);
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Private attachment cleanup failed for '.$prefix);
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function prune(array $paths, string $table, string $column): void
    {
        $query = DB::table($table);
        if ($column === 'attachments') {
            $query->where(function ($query) use ($paths): void {
                foreach ($paths as $path) {
                    $query->orWhereJsonContains('attachments', $path);
                }
            });
            $referenced = $query->pluck($column)->flatMap(fn ($value) => json_decode($value, true, 512, JSON_THROW_ON_ERROR))->all();
        } else {
            $referenced = $query->whereIn($column, $paths)->pluck($column)->all();
        }
        foreach (array_diff($paths, $referenced) as $path) {
            // A reference may have committed since the batch lookup.
            $query = DB::table($table);
            $exists = $column === 'attachments'
                ? $query->whereJsonContains($column, $path)->exists()
                : $query->where($column, $path)->exists();
            if ($exists) {
                continue;
            }
            if ($this->option('dry-run')) {
                $this->line('[dry-run] '.$path);
            } elseif (! Storage::disk('r2')->delete($path)) {
                throw new \RuntimeException('Storage did not confirm attachment deletion.');
            }
        }
    }
}

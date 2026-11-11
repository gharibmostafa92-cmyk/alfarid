<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use App\Support\StorageCleanupOwner;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PrunePrivateAttachments extends Command
{
    private const REFERENCES = [
        'return_requests' => ['return_request_items', 'attachments'],
        'supplier_invoices' => ['supplier_invoices', 'attachment_path'],
        'supplier_deliveries' => ['supplier_deliveries', 'invoice_file_path'],
        'wholesale_payment_notices' => ['wholesale_payment_notices', 'receipt_file_path'],
        'customer_documents' => ['customer_documents', 'file_path'],
    ];

    protected $signature = 'attachments:prune-orphans {--hours=24} {--dry-run} {--max-deletions=1000}';

    protected $description = 'Remove old private attachments with no database reference';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 24) {
            $this->error('Use a grace period of at least 24 hours.');

            return self::FAILURE;
        }

        $max = filter_var($this->option('max-deletions'), FILTER_VALIDATE_INT);
        if ($max === false || $max < 1) {
            $this->error('--max-deletions must be a positive integer.');

            return self::FAILURE;
        }
        $disk = Storage::disk('r2');
        if (! $this->option('dry-run')) {
            try {
                StorageCleanupOwner::assertMatches($disk);
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Storage cleanup ownership could not be verified.');

                return self::FAILURE;
            }
        }
        $cutoff = now()->subHours($hours)->timestamp;
        $candidates = [];
        $references = array_values(self::REFERENCES);
        $collect = function (array $batch) use (&$candidates, $references, $max): void {
            foreach ($this->unreferenced($batch, $references) as $path) {
                if (count($candidates) >= $max) {
                    throw new \OverflowException('Deletion budget of '.$max.' exceeded; no files were deleted.');
                }
                $candidates[] = $path;
            }
        };
        foreach (array_keys(self::REFERENCES) as $prefix) {
            try {
                $batch = [];
                foreach ($disk->getDriver()->listContents($prefix, true) as $entry) {
                    // Reclaim only the UUID staging layout used by new customer document uploads.
                    if ($prefix === 'customer_documents' && ! preg_match('~^customer_documents/\d+/[a-f0-9-]{36}/[^/]+$~D', $entry->path())) {
                        continue;
                    }
                    if ($entry->isFile() && ($entry->lastModified() ?? PHP_INT_MAX) < $cutoff) {
                        $batch[] = $entry->path();
                        if (count($batch) === 500) {
                            $collect($batch);
                            $batch = [];
                        }
                    }
                }
                if ($batch !== []) {
                    $collect($batch);
                }
            } catch (\OverflowException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Private attachment cleanup failed for '.$prefix);

                return self::FAILURE;
            }
        }

        $failures = 0;
        foreach (array_chunk($candidates, 500) as $batch) {
            try {
                // Protect references written while the rest of the disk was scanned.
                $failures += $this->prune($disk, $this->unreferenced($batch, $references));
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Could not recheck attachment references.');

                return self::FAILURE;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function prune(FilesystemAdapter $disk, array $paths): int
    {
        $failures = 0;
        foreach ($paths as $path) {
            try {
                if ($this->option('dry-run')) {
                    $this->line('[dry-run] '.$path);
                } elseif (! $disk->delete($path)) {
                    throw new \RuntimeException('Storage did not confirm attachment deletion.');
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Could not delete attachment '.$path);
                $failures++;
            }
        }

        return $failures;
    }

    private function unreferenced(array $paths, array $references): array
    {
        foreach ($references as [$table, $column]) {
            $paths = array_values(array_diff($paths, $this->referencedPaths($paths, $table, $column)));
            if ($paths === []) {
                return [];
            }
        }

        return $paths;
    }

    private function referencedPaths(array $paths, string $table, string $column): array
    {
        if ($column !== 'attachments') {
            return DB::table($table)->useWritePdo()->whereIn($column, $paths)->pluck($column)->all();
        }

        // The existing GIN index supports both containment shapes.
        return array_column(DB::selectFromWriteConnection(<<<'SQL'
            SELECT candidate.path
            FROM jsonb_array_elements_text(?::jsonb) AS candidate(path)
            WHERE EXISTS (
                SELECT 1 FROM return_request_items item
                WHERE item.attachments::jsonb @> jsonb_build_array(candidate.path)
                   OR item.attachments::jsonb @> jsonb_build_array(jsonb_build_object('path', candidate.path))
            )
            SQL, [json_encode($paths, JSON_THROW_ON_ERROR)]), 'path');
    }
}

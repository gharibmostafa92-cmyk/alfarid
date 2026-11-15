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
        $budgetReached = false;
        $collect = function (array $batch, array $prefixRefs) use (&$candidates, &$budgetReached, $max): void {
            foreach ($this->unreferenced($batch, $prefixRefs) as $path) {
                if (count($candidates) >= $max) {
                    $budgetReached = true;

                    return;
                }
                $candidates[] = $path;
            }
        };
        foreach (self::REFERENCES as $prefix => $ref) {
            if ($budgetReached) {
                break;
            }
            $prefixRefs = [$ref];
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
                            $collect($batch, $prefixRefs);
                            $batch = [];
                            if ($budgetReached) {
                                break;
                            }
                        }
                    }
                }
                if ($batch !== [] && ! $budgetReached) {
                    $collect($batch, $prefixRefs);
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Private attachment cleanup failed for '.$prefix);

                return self::FAILURE;
            }
        }
        if ($budgetReached) {
            $this->warn('Deletion budget of '.$max.' reached; pruning collected batch.');
        }

        $failures = 0;
        $allReferences = array_values(self::REFERENCES);
        foreach (array_chunk($candidates, 500) as $batch) {
            try {
                // Protect references written while the rest of the disk was scanned.
                $failures += $this->prune($disk, $this->unreferenced($batch, $allReferences));
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
        if ($paths === []) {
            return 0;
        }
        if ($this->option('dry-run')) {
            foreach ($paths as $path) {
                $this->line('[dry-run] '.$path);
            }

            return 0;
        }
        try {
            if ($disk->delete($paths)) {
                return 0;
            }
        } catch (Throwable) {
            // Fall back to per-file deletion to isolate failures.
        }

        $failures = 0;
        foreach ($paths as $path) {
            try {
                if (! $disk->delete($path)) {
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
        try {
            return array_column(DB::selectFromWriteConnection(<<<'SQL'
                SELECT candidate.path
                FROM jsonb_array_elements_text(?::jsonb) AS candidate(path)
                WHERE EXISTS (
                    SELECT 1 FROM return_request_items item
                    WHERE item.attachments::jsonb @> jsonb_build_array(candidate.path)
                       OR item.attachments::jsonb @> jsonb_build_array(jsonb_build_object('path', candidate.path))
                )
                SQL, [json_encode($paths, JSON_THROW_ON_ERROR)]), 'path');
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($exception->getCode() !== '22P02') {
                throw $exception;
            }

            $lookup = array_flip($paths);
            $matched = [];
            DB::table('return_request_items')
                ->useWritePdo()
                ->select(['id', 'attachments'])
                ->whereNotNull('attachments')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($lookup, &$matched): void {
                    foreach ($rows as $row) {
                        $decoded = is_string($row->attachments) ? json_decode($row->attachments, true) : $row->attachments;
                        if (! is_array($decoded)) {
                            continue;
                        }
                        foreach ($decoded as $entry) {
                            $candidate = is_array($entry) ? ($entry['path'] ?? null) : $entry;
                            if (is_string($candidate) && isset($lookup[$candidate])) {
                                $matched[$candidate] = true;
                            }
                        }
                    }
                });

            return array_keys($matched);
        }
    }
}

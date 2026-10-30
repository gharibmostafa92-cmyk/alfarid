<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class BackfillMediaPathLayouts extends Command
{
    protected $signature = 'media:backfill-path-layouts';

    protected $description = 'Record existing numeric/UUID media layouts without moving files';

    public function handle(): int
    {
        $count = 0;
        $missing = 0;
        // L7: per-item try/catch — one R2 error must not abort the whole
        // backfill. Files missing on BOTH layouts are logged as missing
        // (not silently stamped uuid) so the orphan sweeper can reclaim them.
        Media::query()->whereNull('custom_properties->path_layout')->chunkById(100, function ($items) use (&$count, &$missing): void {
            foreach ($items as $item) {
                try {
                    DB::transaction(function () use ($item, &$count, &$missing): void {
                        $media = Media::whereKey($item->id)->lockForUpdate()->first();
                        if (! $media || $media->hasCustomProperty('path_layout')) {
                            return;
                        }
                        $disk = Storage::disk($media->disk);
                        // One-time console probe (never at request time): legacy rows
                        // live under id/<file>, newer rows under uuid/<file>.
                        $hasIdPath = $disk->exists($media->id.'/'.$media->file_name);
                        $hasUuidPath = ! $hasIdPath && $disk->exists($media->uuid.'/'.$media->file_name);
                        if (! $hasIdPath && ! $hasUuidPath) {
                            $missing++;
                            logger()->warning('Backfill: media file missing on both layouts.', ['media_id' => $media->id]);

                            return;
                        }
                        $media->setCustomProperty('path_layout', $hasIdPath ? 'id' : 'uuid');
                        $media->save();
                        $count++;
                    });
                } catch (Throwable $exception) {
                    report($exception);
                    $this->warn('Skipped media '.$item->id.': '.$exception->getMessage());
                }
            }
        });
        $this->info("Updated {$count} media layouts.");
        if ($missing > 0) {
            $this->warn("{$missing} media files were missing on both layouts; their layout was left unset.");
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class BackfillMediaPathLayouts extends Command
{
    protected $signature = 'media:backfill-path-layouts';

    protected $description = 'Record existing numeric/UUID media layouts without moving files';

    public function handle(): int
    {
        $count = 0;
        Media::query()->whereNull('custom_properties->path_layout')->chunkById(100, function ($items) use (&$count): void {
            foreach ($items as $item) {
                DB::transaction(function () use ($item, &$count): void {
                    $media = Media::whereKey($item->id)->lockForUpdate()->first();
                    if (! $media || $media->hasCustomProperty('path_layout')) {
                        return;
                    }
                    // One-time console probe (never at request time): legacy rows
                    // live under id/<file>, newer rows under uuid/<file>.
                    $layout = Storage::disk($media->disk)->exists($media->id.'/'.$media->file_name) ? 'id' : 'uuid';
                    $media->setCustomProperty('path_layout', $layout);
                    $media->save();
                    $count++;
                });
            }
        });
        $this->info("Updated {$count} media layouts.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\UuidPathGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class BackfillMediaPathLayouts extends Command
{
    protected $signature = 'media:backfill-path-layouts';

    protected $description = 'Record existing numeric/UUID media layouts without moving files';

    public function handle(UuidPathGenerator $paths): int
    {
        $count = 0;
        Media::query()->whereNull('custom_properties->path_layout')->chunkById(100, function ($items) use ($paths, &$count): void {
            foreach ($items as $item) {
                DB::transaction(function () use ($item, $paths, &$count): void {
                    $media = Media::whereKey($item->id)->lockForUpdate()->first();
                    if (! $media || $media->hasCustomProperty('path_layout')) {
                        return;
                    }
                    $path = $paths->getPath($media);
                    $media->setCustomProperty('path_layout', $path === $media->id.'/' ? 'id' : 'uuid');
                    $media->save();
                    $count++;
                });
            }
        });
        $this->info("Updated {$count} media layouts.");

        return self::SUCCESS;
    }
}

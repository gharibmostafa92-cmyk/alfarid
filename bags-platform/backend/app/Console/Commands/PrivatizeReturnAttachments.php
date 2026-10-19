<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * نقل مرفقات المرتجعات من القرص العام إلى الخاص — قابل لإعادة التشغيل خارج الهجرة.
 */
class PrivatizeReturnAttachments extends Command
{
    protected $signature = 'returns:privatize-attachments';

    protected $description = 'نسخ مرفقات المرتجعات من القرص العام إلى الخاص ثم حذف النسخ العامة';

    public function handle(): int
    {
        $legacyPaths = [];
        DB::table('return_request_items')->whereNotNull('attachments')->orderBy('id')->chunkById(100, function ($items) use (&$legacyPaths): void {
            foreach ($items as $item) {
                foreach (json_decode($item->attachments, true) ?? [] as $path) {
                    $public = Storage::disk('public');
                    $private = Storage::disk('local');
                    if (! $public->exists($path) || $private->exists($path)) {
                        if ($public->exists($path) && $private->exists($path)) {
                            $legacyPaths[] = $path;
                        }

                        continue;
                    }
                    $stream = $public->readStream($path);
                    try {
                        if (! $private->put($path, $stream)) {
                            throw new RuntimeException('Could not privatize a return attachment.');
                        }
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    $legacyPaths[] = $path;
                }
            }
        });
        Storage::disk('public')->delete($legacyPaths);
        $this->info('تم نقل '.count($legacyPaths).' مرفق مرتجع إلى التخزين الخاص.');

        return self::SUCCESS;
    }
}

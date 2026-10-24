<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

trait TracksEditVersion
{
    public static function bootTracksEditVersion(): void
    {
        static::updating(function (Model $model): void {
            // A timestamp rounded to seconds cannot distinguish two edits in
            // the same second. Updates occur under the service's row lock.
            $model->lock_version = (int) $model->getOriginal('lock_version') + 1;
        });
    }
}

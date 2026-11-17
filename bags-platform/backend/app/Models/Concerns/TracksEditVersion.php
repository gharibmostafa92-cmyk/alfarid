<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Bumps lock_version on every Eloquent save. This hook is a version STAMP,
 * not an atomic guard: the generated UPDATE has no
 * "WHERE lock_version = <original>" clause, and Model::where()->update()
 * mass updates never fire the updating event at all. Callers that need
 * lost-update protection MUST hold a row lock (lockForUpdate inside a
 * transaction) before mutating, or enforce the version match themselves.
 * Do not rely on lock_version alone outside a locked transaction.
 */
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

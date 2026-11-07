<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping;

use App\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Model;

final class ShippingVersions
{
    public static function of(Model $model): string
    {
        $at = $model->updated_at;

        return $at instanceof \DateTimeInterface ? $at->format('Y-m-d H:i:s.u') : 'missing';
    }

    public static function assertSame(Model $model, string $expected): void
    {
        if ($expected === '' || ! hash_equals($expected, self::of($model))) {
            throw new BusinessException('حد عدّل السجل ده بعد الاقتراح. تحب أجهّز اقتراح جديد؟', [], 409);
        }
    }
}

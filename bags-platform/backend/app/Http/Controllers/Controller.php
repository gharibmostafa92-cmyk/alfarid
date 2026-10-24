<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use Illuminate\Http\Request;

abstract class Controller
{
    /** مفتاح عدم التكرار من الترويسة بعد التحقق من شكله (1–128 حرفًا). */
    protected function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return null;
        }

        $key = trim($key);
        if ($key === '' || strlen($key) > 128) {
            throw new BusinessException('قيمة Idempotency-Key غير صالحة (1 إلى 128 حرفًا).');
        }

        return $key;
    }

    /**
     * M2: مفتاح عدم التكرار إلزامي في العمليات المالية-المخزنية (تسوية،
     * تحويل، جرد) — إعادة المحاولة بعد timeout بلا مفتاح كانت تكرر الحركة
     * وتفسد الأرصدة بصمت.
     */
    protected function requiredIdempotencyKey(Request $request): string
    {
        $key = $this->idempotencyKey($request);

        if ($key === null) {
            throw new BusinessException('ترويسة Idempotency-Key مطلوبة لهذه العملية؛ أعد الإرسال بنفس المفتاح عند إعادة المحاولة.');
        }

        return $key;
    }
}

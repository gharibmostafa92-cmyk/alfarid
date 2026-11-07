<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Extract and validate the optional Idempotency-Key request header.
     *
     * @param  Request  $request  Incoming HTTP request.
     * @return string|null Validated key or null if omitted.
     *
     * @throws BusinessException If key is present but empty or exceeds 128 characters.
     */
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
     * Enforce mandatory Idempotency-Key header for sensitive state-mutating operations.
     *
     * Prevents duplicate financial and inventory movements during client retries after network timeouts.
     *
     * @param  Request  $request  Incoming HTTP request.
     * @return string Validated idempotency key.
     *
     * @throws BusinessException If the header is missing or malformed.
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

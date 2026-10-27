<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

// الاستثناء اللي بنرميه لما قاعدة من قواعد البيزنس تتكسر، وبيرجّع 422.
class BusinessException extends Exception
{
    public function __construct(
        string $message,
        public array $errors = [],
        public int $status = 422,
        public ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $payload = ['message' => $this->getMessage(), 'errors' => $this->errors];
        if ($this->errorCode !== null) {
            // رمز خطأ مميز تتصرف الواجهة على أساسه (مثل coupon_invalid)
            $payload['code'] = $this->errorCode;
        }

        $response = response()->json($payload, $this->status);
        if ($this->status === 503) {
            $response->headers->set('Retry-After', '1');
        }

        return $response;
    }
}

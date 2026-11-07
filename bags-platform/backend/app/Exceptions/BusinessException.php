<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Exception thrown when domain business constraints or state preconditions are violated.
 */
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

    /**
     * Render the exception into a JSON HTTP response.
     */
    public function render(): JsonResponse
    {
        $payload = ['message' => $this->getMessage(), 'errors' => $this->errors];
        if ($this->errorCode !== null) {
            // Machine-readable error code for client error handling (e.g. 'credit_limit_exceeded').
            $payload['code'] = $this->errorCode;
        }

        $response = response()->json($payload, $this->status);
        if ($this->status === 503) {
            $response->headers->set('Retry-After', '1');
        }

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

// الاستثناء اللي بنرميه لما قاعدة من قواعد البيزنس تتكسر، وبيرجّع 422.
class BusinessException extends Exception
{
    public function __construct(string $message, public array $errors = [], public int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => $this->errors], $this->status);
    }
}

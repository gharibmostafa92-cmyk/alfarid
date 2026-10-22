<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

class GeminiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $providerStatus = null)
    {
        // Provider bodies can contain prompts or credentials; never include them.
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\CustomerEmail;

use InvalidArgumentException;

final readonly class ResendResult
{
    public function __construct(
        public string $kind,
        public ?string $providerId = null,
        public ?int $retryAfterSeconds = null,
        public ?string $safeCode = null,
    ) {
        if (! in_array($kind, ['accepted', 'retry', 'permanent', 'pause', 'review'], true)) {
            throw new InvalidArgumentException('Unsupported Resend result kind.');
        }
    }
}

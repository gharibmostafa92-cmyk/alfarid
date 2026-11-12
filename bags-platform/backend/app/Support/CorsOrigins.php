<?php

declare(strict_types=1);

namespace App\Support;

/**
 * L8: localhost development origins are defaults only outside production.
 * In production every origin must be set explicitly, or a missing env var
 * silently keeps localhost trusted.
 */
final class CorsOrigins
{
    /** @return list<string> */
    public static function defaults(string $environment): array
    {
        if ($environment === 'production') {
            return [];
        }

        return [
            'http://localhost:3000',
            'http://localhost:5173',
            'http://localhost:3002',
        ];
    }
}

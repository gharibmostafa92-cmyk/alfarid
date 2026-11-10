<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;

final class NetworkIdentity
{
    public static function forRequest(Request $request): string
    {
        $ip = (string) ($request->ip() ?? 'unknown');
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            // IPv4-mapped IPv6 addresses retain the underlying IPv4 bucket.
            if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                return 'ip:'.inet_ntop(substr($packed, 12));
            }

            return 'net64:'.bin2hex(substr($packed, 0, 8));
        }

        return 'ip:'.$ip;
    }

    public static function key(Request $request): string
    {
        return hash_hmac('sha256', self::forRequest($request), (string) config('app.key'));
    }

    /**
     * H1: broader /48 bucket for IPv6 (one /48 holds 65,536 /64 networks).
     * IPv4 and IPv4-mapped addresses reuse the base bucket.
     */
    public static function forRequestWide(Request $request): string
    {
        $ip = (string) ($request->ip() ?? 'unknown');
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                return 'ip:'.inet_ntop(substr($packed, 12));
            }

            return 'net48:'.bin2hex(substr($packed, 0, 6));
        }

        return 'ip:'.$ip;
    }

    public static function wideKey(Request $request): string
    {
        return hash_hmac('sha256', self::forRequestWide($request), (string) config('app.key'));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\WhatsApp;

use Illuminate\Validation\ValidationException;

final class E164Phone
{
    public static function normalize(string $raw): string
    {
        $compact = preg_replace('/[^\d+]/', '', trim($raw)) ?? '';
        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }
        if (str_starts_with($compact, '0')) {
            $compact = '+20'.substr($compact, 1);
        }
        if ($compact !== '' && ! str_starts_with($compact, '+')) {
            $compact = '+'.$compact;
        }
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $compact)) {
            throw ValidationException::withMessages([
                'phone' => 'اكتب الرقم بصيغة دولية، مثل +201012345678.',
            ]);
        }

        return $compact;
    }

    public static function tryNormalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return self::normalize($raw);
        } catch (ValidationException) {
            return null;
        }
    }

    public static function fromWhatsappJid(?string $jid): ?string
    {
        if (! is_string($jid) || ! str_ends_with($jid, '@s.whatsapp.net')) {
            return null;
        }
        $user = explode(':', explode('@', $jid)[0])[0];
        if (! ctype_digit($user)) {
            return null;
        }

        return self::tryNormalize('+'.$user);
    }
}

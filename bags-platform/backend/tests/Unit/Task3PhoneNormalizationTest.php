<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\CustomerChannel;
use Tests\TestCase;

// task3 MEDIUM-03 (backend): invisible Bidi/format marks pasted from
// phone apps must not break normalization.
class Task3PhoneNormalizationTest extends TestCase
{
    public function test_normalize_phone_strips_bidi_and_format_marks(): void
    {
        // U+202A LEFT-TO-RIGHT EMBEDDING + U+202C POP DIRECTIONAL FORMATTING
        // as injected by iOS/Android contact copy-paste.
        $this->assertSame(
            '01012345678',
            Customer::normalizePhone("\u{202A}+20 101 234 5678\u{202C}")
        );
        // U+200E LEFT-TO-RIGHT MARK / U+200F RIGHT-TO-LEFT MARK / U+2066..U+2069 isolates.
        $this->assertSame(
            '01012345678',
            Customer::normalizePhone("\u{200E}0101\u{200F}234\u{2066}5678\u{2069}")
        );
        // Zero-width space/non-joiner (also Cf) inside the number.
        $this->assertSame(
            '01012345678',
            Customer::normalizePhone("0101\u{200B}234\u{200C}5678")
        );
    }

    public function test_channel_canonical_phone_strips_bidi_marks(): void
    {
        $this->assertSame(
            '+201012345678',
            CustomerChannel::canonicalPhone("\u{202A}+20 101 234 5678\u{202C}")
        );
    }

    public function test_normalize_phone_still_rejects_letters_and_symbols(): void
    {
        $this->assertSame('0101abc5678', Customer::normalizePhone('0101abc5678'));
        $this->assertNull(CustomerChannel::canonicalPhone('not-a-phone'));
    }
}

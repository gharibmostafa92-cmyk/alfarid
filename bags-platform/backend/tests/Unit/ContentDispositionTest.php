<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ContentDisposition;
use PHPUnit\Framework\TestCase;

class ContentDispositionTest extends TestCase
{
    public function test_backslash_paths_keep_an_extension_in_the_ascii_fallback(): void
    {
        $header = ContentDisposition::attachment('supplier_invoices\\invoice.pdf');

        $this->assertStringContainsString('filename=invoice.pdf', $header);
    }

    public function test_extensionless_names_use_a_bin_fallback(): void
    {
        $header = ContentDisposition::attachment('attachment');

        $this->assertStringContainsString('filename=attachment.bin', $header);
    }
}

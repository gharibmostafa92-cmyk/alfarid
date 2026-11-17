<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rules\MaxImagePixels;
use App\Rules\PdfHeader;
use App\Rules\RejectExecutableSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

// task3 HIGH-05: unified PDF-header detection, #-only active-content scan.
class Task3PdfHeaderParityTest extends TestCase
{
    private function fails(object $rule, UploadedFile $file): bool
    {
        return Validator::make(['document' => $file], ['document' => [$rule]])->fails();
    }

    private function uploaded(string $content, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'task3hdr').'.bin';
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    public function test_bom_prefixed_clean_pdf_passes_both_rules(): void
    {
        $content = "\xEF\xBB\xBF%PDF-1.7\n1 0 obj\n<< >>\nendobj\n";

        $this->assertFalse($this->fails(
            new MaxImagePixels,
            $this->uploaded($content, 'doc.pdf', 'application/pdf')
        ), 'BOM-prefixed clean PDF must pass the pixel rule');
        $this->assertFalse($this->fails(
            new RejectExecutableSignature,
            $this->uploaded($content, 'doc.pdf', 'application/pdf')
        ), 'BOM-prefixed clean PDF must pass the signature rule');
    }

    public function test_binary_prefixed_polyglot_is_not_treated_as_a_clean_pdf(): void
    {
        // Binary garbage before %PDF- is not a legal preamble: both rules
        // must agree it is not a clean PDF with a valid header.
        $content = "\x89PNG\r\n\x1a\nBINARY-GARBAGE-DATA-HERE%PDF-1.4\n";

        $this->assertFalse(PdfHeader::contains($this->tempPath($content)));
    }

    public function test_hash_escaped_active_content_is_still_rejected(): void
    {
        $content = "%PDF-1.7\n1 0 obj\n<< /#4AavaScript (evil) >>\nendobj\n";

        $this->assertTrue($this->fails(
            new RejectExecutableSignature,
            $this->uploaded($content, 'evil.pdf', 'application/pdf')
        ), 'Hex-escaped /JavaScript must still be detected after the scan optimization');
    }

    public function test_plain_names_without_escapes_do_not_blow_up_memory(): void
    {
        // Thousands of plain /Name tokens and no '#' anywhere: the optimized
        // scan must accept the file without building a token copy.
        $content = "%PDF-1.7\n".str_repeat("/Type /Page /Contents ", 5000)."\n";

        $this->assertFalse($this->fails(
            new RejectExecutableSignature,
            $this->uploaded($content, 'big.pdf', 'application/pdf')
        ));
    }

    private function tempPath(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'task3hdr').'.bin';
        file_put_contents($path, $content);

        return $path;
    }
}

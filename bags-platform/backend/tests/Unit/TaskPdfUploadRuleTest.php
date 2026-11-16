<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rules\MaxImagePixels;
use App\Rules\RejectExecutableSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TaskPdfUploadRuleTest extends TestCase
{
    public function test_pdf_with_hex_escaped_javascript_name_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.7\n/ #4A#53\n")
            ->mimeType('application/pdf');
        file_put_contents($file->getRealPath(), "%PDF-1.7\n/#4A#53\n");

        $this->assertRuleFails(new RejectExecutableSignature, $file);
    }

    public function test_pdf_with_a_preamble_before_magic_header_is_rejected_by_both_rules(): void
    {
        $file = UploadedFile::fake()->createWithContent('document.pdf', " \n%PDF-1.7\n/JavaScript\n")
            ->mimeType('application/pdf');

        $this->assertRuleFails(new RejectExecutableSignature, $file);
        $this->assertRuleFails(new MaxImagePixels, $file);
    }

    public function test_pdf_with_direct_active_content_name_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.7\n/AA\n")
            ->mimeType('application/pdf');

        $this->assertRuleFails(new RejectExecutableSignature, $file);
    }

    private function assertRuleFails(object $rule, UploadedFile $file): void
    {
        $validator = Validator::make(['document' => $file], ['document' => [$rule]]);

        $this->assertTrue($validator->fails());
    }
}

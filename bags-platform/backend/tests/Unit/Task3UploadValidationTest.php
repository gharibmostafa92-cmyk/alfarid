<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rules\RejectExecutableSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use ZipArchive;

class Task3UploadValidationTest extends TestCase
{
    private function fails(object $rule, UploadedFile $file): bool
    {
        return Validator::make(['document' => $file], ['document' => [$rule]])->fails();
    }

    private function uploaded(string $path, string $name, string $mime): UploadedFile
    {
        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'task3xlsx').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public function test_valid_small_xlsx_is_accepted(): void
    {
        $path = $this->makeZip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types></Types>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook></workbook>',
        ]);

        $this->assertFalse($this->fails(
            new RejectExecutableSignature(allowOfficeOpenXml: true),
            $this->uploaded($path, 'price.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ));
    }

    public function test_xlsx_with_macro_sheet_is_rejected(): void
    {
        $path = $this->makeZip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types></Types>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook></workbook>',
            'xl/macrosheets/sheet1.xml' => '<macrosheet></macrosheet>',
        ]);

        $this->assertTrue($this->fails(
            new RejectExecutableSignature(allowOfficeOpenXml: true),
            $this->uploaded($path, 'price.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ));
    }

    public function test_xlsx_exceeding_streamed_cap_is_rejected(): void
    {
        // 2KB of incompressible content against a 1KB cap: the central
        // directory header is irrelevant, only streamed bytes count.
        $path = $this->makeZip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types></Types>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook></workbook>',
            'xl/worksheets/sheet1.xml' => random_bytes(2048),
        ]);

        $this->assertTrue($this->fails(
            new RejectExecutableSignature(allowOfficeOpenXml: true, maxOoxmlBytes: 1024),
            $this->uploaded($path, 'price.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ));
    }

    public function test_xlsx_with_too_many_entries_is_rejected(): void
    {
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types></Types>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook></workbook>',
        ];
        for ($i = 0; $i < 250; $i++) {
            $entries['xl/worksheets/sheet'.$i.'.xml'] = '<ws/>';
        }
        $path = $this->makeZip($entries);

        $this->assertTrue($this->fails(
            new RejectExecutableSignature(allowOfficeOpenXml: true),
            $this->uploaded($path, 'price.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ));
    }

    public function test_pdf_with_preamble_header_is_accepted_when_clean(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'task3pdf').'.pdf';
        file_put_contents($path, str_repeat(' ', 100)."%PDF-1.7\n1 0 obj\n<< >>\nendobj\n");

        $this->assertFalse($this->fails(
            new RejectExecutableSignature,
            $this->uploaded($path, 'doc.pdf', 'application/pdf')
        ));
    }

    public function test_pdf_with_preamble_and_javascript_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'task3pdf').'.pdf';
        file_put_contents($path, str_repeat(' ', 100)."%PDF-1.7\n/JavaScript\n");

        $this->assertTrue($this->fails(
            new RejectExecutableSignature,
            $this->uploaded($path, 'doc.pdf', 'application/pdf')
        ));
    }
}

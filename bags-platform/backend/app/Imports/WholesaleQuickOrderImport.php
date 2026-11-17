<?php

declare(strict_types=1);

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithColumnLimit;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\BeforeImport;

/**
 * Raw importer for quick order sheets (SKU, qty).
 * First worksheet rows are captured in $rows; normalization and validation are handled downstream.
 */
class WholesaleQuickOrderImport implements ToArray, WithLimit, WithColumnLimit, WithMultipleSheets, WithEvents, WithCustomCsvSettings
{
    /** @var list<array<int, mixed>> */
    public array $rows = [];

    public function registerEvents(): array
    {
        return [
            BeforeImport::class => static function (BeforeImport $event): void {
                $delegate = $event->reader->getDelegate();
                if (method_exists($delegate, 'setReadDataOnly')) {
                    $delegate->setReadDataOnly(true);
                }
            },
        ];
    }

    public function getCsvSettings(): array
    {
        return [
            'input_encoding' => 'UTF-8',
            'escape_character' => '',
        ];
    }

    public function sheets(): array
    {
        return [0 => $this];
    }

    public function limit(): int
    {
        return 1000;
    }

    public function endColumn(): string
    {
        return 'D';
    }

    public function array(array $array): void
    {
        if ($this->rows === []) {
            $this->rows = $array;
        }
    }
}

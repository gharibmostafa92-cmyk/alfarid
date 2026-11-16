<?php

declare(strict_types=1);

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithColumnLimit;
use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Raw importer for quick order sheets (SKU, qty).
 * First worksheet rows are captured in $rows; normalization and validation are handled downstream.
 */
class WholesaleQuickOrderImport implements ToArray, WithLimit, WithColumnLimit, WithMultipleSheets
{
    /** @var list<array<int, mixed>> */
    public array $rows = [];

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

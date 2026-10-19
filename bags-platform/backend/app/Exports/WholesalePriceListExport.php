<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * قائمة أسعار جملة التاجر (Excel) — الصفوف تُبنى في WholesalePriceListService
 * وتُمرَّر جاهزة حتى يطابق ملف PDF الصادر من نفس المصدر.
 */
class WholesalePriceListExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    /** @param  list<array<string, mixed>>  $rows */
    public function __construct(private readonly array $rows) {}

    public function headings(): array
    {
        return ['SKU', 'Barcode', 'Product Name', 'Base Price', 'Your Wholesale Price', 'Quantity Tiers'];
    }

    public function array(): array
    {
        return collect($this->rows)->map(fn (array $row) => [
            $row['sku'],
            $row['barcode'],
            $row['product_name'],
            $row['base_price'],
            $row['wholesale_price'],
            collect($row['tier_prices'])
                ->map(fn (array $tier) => rtrim(rtrim(number_format($tier['min_qty'], 2, '.', ''), '0'), '.').'+: '.number_format($tier['unit_price'], 2, '.', ''))
                ->implode(' | '),
        ])->all();
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}

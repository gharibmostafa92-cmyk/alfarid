<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #222; }
    .header { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 12px; }
    .company { font-size: 20px; font-weight: bold; }
    .meta { color: #555; margin-top: 4px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #bbb; padding: 5px 6px; text-align: right; }
    th { background: #f0f0f0; }
    .num { text-align: left; }
</style>
</head>
<body>
    <div class="header">
        <div class="company">{{ $company_name }}</div>
        <div class="meta">
            قائمة أسعار الجملة — التاجر: {{ $customer->company_name ?: $customer->name }}
            | صالحة حتى: {{ $valid_until->toDateString() }}
            | تاريخ الإصدار: {{ $generated_at->toDateString() }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>SKU</th>
                <th>Barcode</th>
                <th>Product Name</th>
                <th>Base Price</th>
                <th>Your Wholesale Price</th>
                <th>Quantity Tiers</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['sku'] }}</td>
                    <td>{{ $row['barcode'] }}</td>
                    <td>{{ $row['product_name'] }}</td>
                    <td class="num">{{ $row['base_price'] !== null ? number_format($row['base_price'], 2) : '—' }}</td>
                    <td class="num">{{ $row['wholesale_price'] !== null ? number_format($row['wholesale_price'], 2) : '—' }}</td>
                    <td>
                        @foreach ($row['tier_prices'] as $tier)
                            {{ rtrim(rtrim(number_format($tier['min_qty'], 2, '.', ''), '0'), '.') }}+: {{ number_format($tier['unit_price'], 2) }}@if (! $loop->last) | @endif
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #222; }
    .header { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 12px; }
    .company { font-size: 20px; font-weight: bold; }
    .meta { color: #555; margin-top: 4px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #bbb; padding: 5px 6px; text-align: right; }
    th { background: #f0f0f0; }
    .num { text-align: left; }
    .totals { margin-top: 12px; width: 45%; margin-right: auto; }
    .totals td { border: none; padding: 3px 6px; }
    .grand { font-weight: bold; font-size: 14px; }
</style>
</head>
<body>
    <div class="header">
        <div class="company">{{ $company_name }}</div>
        <div class="meta">
            فاتورة رقم: {{ $order->order_number }}
            | التاريخ: {{ $order->created_at?->toDateString() }}
            | العميل: {{ $customer->company_name ?: $customer->name }}
            | طريقة الدفع: {{ $order->payment_method }}
            | حالة الدفع: {{ $order->payment_status }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>SKU</th>
                <th>الصنف</th>
                <th>الكمية</th>
                <th>سعر الوحدة</th>
                <th>الخصم</th>
                <th>الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $item->sku_snapshot }}</td>
                    <td>{{ $item->product_name_snapshot }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->qty, 2, '.', ''), '0'), '.') }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->discount_amount, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>المجموع الفرعي</td><td class="num">{{ number_format((float) $order->subtotal, 2) }}</td></tr>
        <tr><td>الخصم</td><td class="num">{{ number_format((float) $order->discount_amount, 2) }}</td></tr>
        <tr><td>الشحن</td><td class="num">{{ number_format((float) $order->shipping_cost, 2) }}</td></tr>
        <tr><td>الضريبة</td><td class="num">{{ number_format((float) $order->tax_amount, 2) }}</td></tr>
        <tr class="grand"><td>الإجمالي المستحق</td><td class="num">{{ number_format((float) $order->grand_total, 2) }}</td></tr>
    </table>
</body>
</html>

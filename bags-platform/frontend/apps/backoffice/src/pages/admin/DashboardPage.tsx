import React from 'react';
import { Card, Button, DataTable } from '@alfarid/ui';
import { formatCurrency, OrderStatusLabelsAr, OrderStatus } from '@alfarid/utils';
import { useCan } from '../../hooks/useCan';

export function AdminDashboardPage() {
  const canCancelOrder = useCan('orders.cancel');

  const sampleOrders = [
    { id: 'ORD-1001', customer: 'أحمد محمود', total: 3200, status: OrderStatus.Confirmed, date: '2026-10-20' },
    { id: 'ORD-1002', customer: 'شركة الأمل للتجارة', total: 18500, status: OrderStatus.Processing, date: '2026-10-20' },
    { id: 'ORD-1003', customer: 'سارة إبراهيم', total: 750, status: OrderStatus.Pending, date: '2026-10-19' },
  ];

  const columns = [
    { header: 'رقم الطلب', accessorKey: 'id' as const },
    { header: 'العميل', accessorKey: 'customer' as const },
    { 
      header: 'الإجمالي', 
      cell: (row: typeof sampleOrders[0]) => (
        <span className="font-semibold text-brand-600">{formatCurrency(row.total)}</span>
      ) 
    },
    { 
      header: 'الحالة', 
      cell: (row: typeof sampleOrders[0]) => (
        <span className="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
          {OrderStatusLabelsAr[row.status]}
        </span>
      ) 
    },
    {
      header: 'إجراءات',
      cell: (row: typeof sampleOrders[0]) => (
        <div className="flex items-center gap-2">
          <Button size="sm" variant="outline">تفاصيل</Button>
          {canCancelOrder && (
            <Button size="sm" variant="danger">إلغاء</Button>
          )}
        </div>
      )
    }
  ];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-zinc-900">نظرة عامة على النظام</h1>
        <Button size="sm">تحديث الإحصائيات</Button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <Card title="مبيعات اليوم">
          <p className="text-2xl font-bold text-emerald-600">{formatCurrency(48250)}</p>
          <span className="text-xs text-zinc-500">+12% عن الأمس</span>
        </Card>
        <Card title="الطلبات الجديدة">
          <p className="text-2xl font-bold text-brand-600">24 طلب</p>
          <span className="text-xs text-zinc-500">منها 5 طلبات جملة</span>
        </Card>
        <Card title="أصناف قاربت على النفاد">
          <p className="text-2xl font-bold text-rose-600">7 منتجات</p>
          <span className="text-xs text-zinc-500">تحت حد إعادة الطلب</span>
        </Card>
        <Card title="رصيد الخزينة المتاح">
          <p className="text-2xl font-bold text-zinc-900">{formatCurrency(135400)}</p>
          <span className="text-xs text-zinc-500">خزينة الفرع الرئيسي</span>
        </Card>
      </div>

      <Card title="أحدث الطلبات الواردة">
        <DataTable columns={columns} data={sampleOrders} />
      </Card>
    </div>
  );
}

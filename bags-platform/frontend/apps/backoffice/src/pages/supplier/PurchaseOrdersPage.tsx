import React from 'react';
import { Card, Button, DataTable } from '@alfarid/ui';
import { formatCurrency } from '@alfarid/utils';

export function SupplierPurchaseOrdersPage() {
  const purchaseOrders = [
    { poNumber: 'PO-2026-089', itemsCount: 150, total: 65000, status: 'مؤكد', deliveryDate: '2026-10-25' },
    { poNumber: 'PO-2026-090', itemsCount: 300, total: 120000, status: 'قيد التجهيز', deliveryDate: '2026-11-01' },
  ];

  const columns = [
    { header: 'رقم أمر الشراء', accessorKey: 'poNumber' as const },
    { header: 'عدد القطع', accessorKey: 'itemsCount' as const },
    { 
      header: 'إجمالي القيمة', 
      cell: (row: typeof purchaseOrders[0]) => (
        <span className="font-semibold">{formatCurrency(row.total)}</span>
      ) 
    },
    { 
      header: 'تاريخ التوريد المتوقع', 
      accessorKey: 'deliveryDate' as const 
    },
    { 
      header: 'الحالة', 
      cell: (row: typeof purchaseOrders[0]) => (
        <span className="px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
          {row.status}
        </span>
      ) 
    },
    {
      header: 'إجراءات',
      cell: () => (
        <Button size="sm" variant="outline">إنشاء إشعار شحن (ASN)</Button>
      )
    }
  ];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">أوامر الشراء الموجهة إليك</h1>
          <p className="text-sm text-slate-500 mt-1">تأكيد الاستلام وجدولة مواعيد التوريد مع المخازن</p>
        </div>
      </div>

      <Card>
        <DataTable columns={columns} data={purchaseOrders} />
      </Card>
    </div>
  );
}

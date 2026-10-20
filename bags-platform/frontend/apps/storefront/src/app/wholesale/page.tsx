import { Button, Card, Badge } from '@alfarid/ui';
import { formatCurrency } from '@alfarid/utils';

export default function WholesalePortalPage() {
  return (
    <div className="container mx-auto px-4 py-8">
      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">بوابة عملاء الجملة</h1>
          <p className="text-zinc-500 text-sm mt-1">الوصول لأسعار الجملة، طلبات التسعير، وكشف الحساب الموحد</p>
        </div>
        <Badge variant="warning">حساب تاجر جملة معتمد</Badge>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Card title="الطلب السريع (Quick Order)">
          <p className="text-sm text-zinc-600 mb-4">
            إدخال كود الصنف والكمية المطلوبة بالدزينة أو الكرتونة للإضافة السريعة.
          </p>
          <Button variant="outline" size="sm">فتح نموذج الطلب السريع</Button>
        </Card>

        <Card title="كشف الحساب والفواتير">
          <p className="text-sm text-zinc-600 mb-4">
            متابعة الفواتير الآجلة، الدفعات المسددة، والرصيد المتبقي.
          </p>
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium">الرصيد المستحق: <strong className="text-red-600">{formatCurrency(45000)}</strong></span>
            <Button size="sm">عرض الفواتير</Button>
          </div>
        </Card>
      </div>
    </div>
  );
}

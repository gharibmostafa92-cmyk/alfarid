import { Button, Card } from '@alfarid/ui';
import { formatCurrency } from '@alfarid/utils';

export default function StorefrontHomePage() {
  return (
    <div className="container mx-auto px-4 py-8">
      <div className="rounded-2xl bg-gradient-to-l from-brand-600 to-brand-800 p-8 text-white shadow-lg mb-8">
        <h1 className="text-3xl font-extrabold mb-2">مرحباً بك في متجر الفريد للحقائب</h1>
        <p className="text-brand-100 max-w-xl">
          أحدث تشكيلات الحقائب والمصنوعات الجلدية الفاخرة بأفضل الأسعار وأعلى جودة.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <Card title="حقيبة جلد طبيعي كلاسيك" subtitle="تصنيف: رجالي">
          <p className="text-sm text-zinc-600 mb-4">مصنوعة من أجود أنواع الجلود الطبيعية مع ضمان لمدة عام.</p>
          <div className="flex items-center justify-between">
            <span className="text-lg font-bold text-brand-600">{formatCurrency(1250)}</span>
            <Button size="sm">إضافة للسلة</Button>
          </div>
        </Card>

        <Card title="حقيبة يد نسائية أنيقة" subtitle="تصنيف: نسائي">
          <p className="text-sm text-zinc-600 mb-4">تصميم عصري يناسب المناسبات والعمل مع حزام كتف إضافي.</p>
          <div className="flex items-center justify-between">
            <span className="text-lg font-bold text-brand-600">{formatCurrency(890)}</span>
            <Button size="sm">إضافة للسلة</Button>
          </div>
        </Card>

        <Card title="حقيبة ظهر للسفر واللابتوب" subtitle="تصنيف: ظهر">
          <p className="text-sm text-zinc-600 mb-4">مقاومة للماء ومزودة بمنفذ شحن USB ومقصورة مخصصة للحاسوب.</p>
          <div className="flex items-center justify-between">
            <span className="text-lg font-bold text-brand-600">{formatCurrency(750)}</span>
            <Button size="sm">إضافة للسلة</Button>
          </div>
        </Card>
      </div>
    </div>
  );
}

import React, { useState, useMemo } from 'react';
import { PageHeader } from '../../components/admin/PageHeader';
import { Drawer } from '../../components/admin/Drawer';
import { ConfirmModal } from '../../components/admin/ConfirmModal';
import { Field, Toggle } from '../../components/admin/FormFields';
import { EmptyState } from '../../components/admin/States';
import { Button, Badge } from '@alfarid/ui';
import { useAdminDataStore, Brand, slugify } from '../../stores/admin-data.store';
import { Plus, Search, Edit, Trash2, Award } from 'lucide-react';

export function BrandsPage() {
  const { brands, addBrand, updateBrand, deleteBrand } = useAdminDataStore();

  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [editingBrand, setEditingBrand] = useState<Brand | null>(null);
  const [formData, setFormData] = useState({
    ar: '',
    en: '',
    slug: '',
    logo: '',
    order: 1,
    active: true,
  });

  const [deleteTarget, setDeleteTarget] = useState<Brand | null>(null);

  const filteredBrands = useMemo(() => {
    return brands.filter((b) => {
      if (
        query &&
        !`${b.ar} ${b.en} ${b.slug}`.toLowerCase().includes(query.toLowerCase())
      ) {
        return false;
      }
      if (statusFilter === 'active' && !b.active) return false;
      if (statusFilter === 'inactive' && b.active) return false;
      return true;
    });
  }, [brands, query, statusFilter]);

  const openNewDrawer = () => {
    setEditingBrand(null);
    setFormData({
      ar: '',
      en: '',
      slug: '',
      logo: 'AF',
      order: brands.length + 1,
      active: true,
    });
    setDrawerOpen(true);
  };

  const openEditDrawer = (brand: Brand) => {
    setEditingBrand(brand);
    setFormData({
      ar: brand.ar,
      en: brand.en,
      slug: brand.slug,
      logo: brand.logo,
      order: brand.order,
      active: brand.active,
    });
    setDrawerOpen(true);
  };

  const handleSaveBrand = () => {
    if (!formData.ar || !formData.en) return;
    const slug = formData.slug || slugify(formData.en);
    const logo = formData.logo || formData.en.slice(0, 2).toUpperCase();

    if (editingBrand) {
      updateBrand(editingBrand.id, {
        ar: formData.ar,
        en: formData.en,
        slug,
        logo,
        order: Number(formData.order),
        active: formData.active,
      });
    } else {
      addBrand({
        ar: formData.ar,
        en: formData.en,
        slug,
        logo,
        order: Number(formData.order),
        active: formData.active,
      });
    }
    setDrawerOpen(false);
  };

  return (
    <div>
      <PageHeader
        crumbs={[['الرئيسية', '/admin'], ['الكتالوج'], ['البراندات']]}
        light="العلامات"
        bold="التجارية (البراندات)"
        sub="إدارة الماركات المسجلة لمنتجات الحقائب والمحافظ وشعاراتها وروابطها المخصصة."
        actions={
          <Button size="sm" className="gap-2" onClick={openNewDrawer}>
            <Plus className="w-4 h-4" />
            <span>إضافة براند جديد</span>
          </Button>
        }
      />

      {/* Filter and Search Bar */}
      <div className="bg-white border border-zinc-200 rounded-2xl p-4 shadow-sm mb-6 flex items-center justify-between gap-4 flex-wrap">
        <div className="relative flex-1 max-w-sm">
          <Search className="w-4 h-4 text-zinc-400 absolute right-3 top-3 pointer-events-none" />
          <input
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="ابحث باسم البراند أو الكود..."
            className="w-full h-10 pr-9 pl-3 rounded-xl border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
          />
        </div>

        <div className="flex items-center gap-2 text-xs">
          <button
            onClick={() => setStatusFilter('all')}
            className={`px-3 py-1.5 rounded-lg font-medium transition-colors ${
              statusFilter === 'all' ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-zinc-100'
            }`}
          >
            الكل ({brands.length})
          </button>
          <button
            onClick={() => setStatusFilter('active')}
            className={`px-3 py-1.5 rounded-lg font-medium transition-colors ${
              statusFilter === 'active' ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-zinc-100'
            }`}
          >
            نشط ({brands.filter((b) => b.active).length})
          </button>
          <button
            onClick={() => setStatusFilter('inactive')}
            className={`px-3 py-1.5 rounded-lg font-medium transition-colors ${
              statusFilter === 'inactive' ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-zinc-100'
            }`}
          >
            معطل ({brands.filter((b) => !b.active).length})
          </button>
        </div>
      </div>

      {/* Brands Table */}
      <div className="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
        {filteredBrands.length === 0 ? (
          <EmptyState
            title="لا توجد براندات مطابقة"
            text="جرّب بحثاً آخر أو أضف براند جديد لتبدأ."
            action={
              <Button size="sm" onClick={openNewDrawer}>
                إضافة براند
              </Button>
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-right text-sm">
              <thead className="bg-zinc-50 border-b border-zinc-200 text-xs font-semibold text-zinc-600">
                <tr>
                  <th className="px-5 py-3.5">البراند</th>
                  <th className="px-4 py-3.5">الرابط المخصص</th>
                  <th className="px-4 py-3.5 text-center">المنتجات</th>
                  <th className="px-4 py-3.5 text-center">الترتيب</th>
                  <th className="px-4 py-3.5">الحالة</th>
                  <th className="px-4 py-3.5 text-center">إجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-200">
                {filteredBrands.map((b) => (
                  <tr key={b.id} className="hover:bg-zinc-50/70 transition-colors">
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-zinc-900 text-white font-bold flex items-center justify-center shrink-0 text-xs tracking-wider">
                          {b.logo}
                        </div>
                        <div>
                          <div className="font-bold text-zinc-900">{b.ar}</div>
                          <span className="text-xs text-zinc-400 font-sans ltr block">{b.en}</span>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3.5 font-mono text-xs text-zinc-500 ltr">
                      /{b.slug}
                    </td>
                    <td className="px-4 py-3.5 text-center text-xs font-bold text-brand-600">
                      {b.products}
                    </td>
                    <td className="px-4 py-3.5 text-center font-mono text-xs text-zinc-600">
                      {b.order}
                    </td>
                    <td className="px-4 py-3.5">
                      <Badge variant={b.active ? 'success' : 'warning'}>
                        {b.active ? 'نشط' : 'معطل'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3.5 text-center">
                      <div className="flex items-center justify-center gap-1.5">
                        <button
                          onClick={() => openEditDrawer(b)}
                          className="p-1.5 rounded-lg text-zinc-400 hover:text-brand-600 hover:bg-zinc-100 transition-colors"
                          title="تعديل البراند"
                        >
                          <Edit className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => setDeleteTarget(b)}
                          className="p-1.5 rounded-lg text-zinc-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                          title="حذف البراند"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Brand Create/Edit Drawer */}
      <Drawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        title={editingBrand ? `تعديل براند ${editingBrand.en}` : 'إضافة براند جديد'}
        crumbs={<span>البراندات</span>}
        footer={
          <div className="flex items-center gap-2 w-full justify-end">
            <Button variant="outline" size="sm" onClick={() => setDrawerOpen(false)}>
              إلغاء
            </Button>
            <Button size="sm" onClick={handleSaveBrand}>
              حفظ
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <Field label="الاسم العربي" req>
            <input
              type="text"
              value={formData.ar}
              onChange={(e) => setFormData({ ...formData, ar: e.target.value })}
              placeholder="مثال: الفريد"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <Field label="الاسم الإنجليزي" req>
            <input
              type="text"
              dir="ltr"
              value={formData.en}
              onChange={(e) => setFormData({ ...formData, en: e.target.value })}
              placeholder="ALFARID"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <Field label="شعار البراند (مختصر أو حروف)">
            <input
              type="text"
              value={formData.logo}
              onChange={(e) => setFormData({ ...formData, logo: e.target.value.toUpperCase() })}
              placeholder="AF"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs font-mono focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <Field label="الرابط (Slug)" opt help="يتولد تلقائياً من الاسم الإنجليزي.">
            <input
              type="text"
              dir="ltr"
              value={formData.slug}
              onChange={(e) => setFormData({ ...formData, slug: e.target.value })}
              placeholder={slugify(formData.en || 'brand-slug')}
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs font-mono focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <div className="grid grid-cols-2 gap-4">
            <Field label="ترتيب الظهور">
              <input
                type="number"
                value={formData.order}
                onChange={(e) => setFormData({ ...formData, order: Number(e.target.value) })}
                className="w-full h-9 px-3 rounded-lg border border-zinc-300 text-xs font-mono"
              />
            </Field>
            <div className="pt-6">
              <Toggle
                checked={formData.active}
                onChange={(active) => setFormData({ ...formData, active })}
                label="البراند نشط"
              />
            </div>
          </div>
        </div>
      </Drawer>

      {/* Delete Confirmation Modal */}
      <ConfirmModal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title={`حذف براند «${deleteTarget?.en}»؟`}
        text="سيتم حذف البراند نهائياً."
        rejectWith={
          deleteTarget && deleteTarget.products > 0 ? (
            <span>
              يوجد <b>{deleteTarget.products}</b> منتج مرتبط بهذا البراند. لا يمكن حذفه
              لحماية البيانات — يمكنك تعطيله بدلاً من ذلك.
            </span>
          ) : null
        }
        confirmLabel="حذف"
        onConfirm={() => {
          if (deleteTarget) {
            deleteBrand(deleteTarget.id);
            setDeleteTarget(null);
          }
        }}
      />
    </div>
  );
}

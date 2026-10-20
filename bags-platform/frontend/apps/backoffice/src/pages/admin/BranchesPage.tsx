import React, { useState, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '../../components/admin/PageHeader';
import { Drawer } from '../../components/admin/Drawer';
import { ConfirmModal } from '../../components/admin/ConfirmModal';
import { Field, Toggle, RadioCards } from '../../components/admin/FormFields';
import { EmptyState } from '../../components/admin/States';
import { Button, Badge } from '@alfarid/ui';
import { useAdminDataStore, Branch } from '../../stores/admin-data.store';
import { Plus, Search, Edit, Trash2, Store, Warehouse, MapPin, Phone } from 'lucide-react';

export function BranchesPage() {
  const navigate = useNavigate();
  const { branches, addBranch, updateBranch, deleteBranch } = useAdminDataStore();

  const [query, setQuery] = useState('');
  const [typeFilter, setTypeFilter] = useState<'all' | 'branch' | 'warehouse'>('all');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [editingBranch, setEditingBranch] = useState<Branch | null>(null);
  const [formData, setFormData] = useState({
    name: '',
    code: '',
    type: 'branch' as 'branch' | 'warehouse',
    phone: '',
    email: '',
    city: '',
    address: '',
    active: true,
  });

  const [deleteTarget, setDeleteTarget] = useState<Branch | null>(null);

  const filteredBranches = useMemo(() => {
    return branches.filter((b) => {
      if (
        query &&
        !`${b.name} ${b.code} ${b.city}`.toLowerCase().includes(query.toLowerCase())
      ) {
        return false;
      }
      if (typeFilter !== 'all' && b.type !== typeFilter) return false;
      if (statusFilter === 'active' && !b.active) return false;
      if (statusFilter === 'inactive' && b.active) return false;
      return true;
    });
  }, [branches, query, typeFilter, statusFilter]);

  const openNewDrawer = () => {
    setEditingBranch(null);
    setFormData({
      name: '',
      code: '',
      type: 'branch',
      phone: '',
      email: '',
      city: 'القاهرة',
      address: '',
      active: true,
    });
    setDrawerOpen(true);
  };

  const openEditDrawer = (b: Branch) => {
    setEditingBranch(b);
    setFormData({
      name: b.name,
      code: b.code,
      type: b.type,
      phone: b.phone,
      email: b.email,
      city: b.city,
      address: b.address,
      active: b.active,
    });
    setDrawerOpen(true);
  };

  const handleSaveBranch = () => {
    if (!formData.name || !formData.code) return;
    if (editingBranch) {
      updateBranch(editingBranch.id, formData);
    } else {
      addBranch(formData);
    }
    setDrawerOpen(false);
  };

  return (
    <div>
      <PageHeader
        crumbs={[['الرئيسية', '/admin'], ['التشغيل'], ['الفروع والمخازن']]}
        light="الفروع"
        bold="والمخازن"
        sub="كل موقع له كود فريد. مواقع التخزين (الرفوف، الخانات، مناطق الاستلام) تُدار من داخل صفحة تفاصيل كل فرع."
        actions={
          <Button size="sm" className="gap-2" onClick={openNewDrawer}>
            <Plus className="w-4 h-4" />
            <span>إضافة فرع أو مخزن</span>
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
            placeholder="ابحث بالاسم، الكود، أو المدينة..."
            className="w-full h-10 pr-9 pl-3 rounded-xl border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
          />
        </div>

        <div className="flex items-center gap-2 text-xs">
          <div className="flex items-center gap-1 border border-zinc-200 rounded-xl p-1 bg-zinc-50">
            <button
              onClick={() => setTypeFilter('all')}
              className={`px-3 py-1 rounded-lg font-medium transition-colors ${
                typeFilter === 'all' ? 'bg-white shadow-xs text-zinc-900' : 'text-zinc-600'
              }`}
            >
              الكل
            </button>
            <button
              onClick={() => setTypeFilter('branch')}
              className={`px-3 py-1 rounded-lg font-medium transition-colors ${
                typeFilter === 'branch' ? 'bg-white shadow-xs text-zinc-900' : 'text-zinc-600'
              }`}
            >
              فروع بيع
            </button>
            <button
              onClick={() => setTypeFilter('warehouse')}
              className={`px-3 py-1 rounded-lg font-medium transition-colors ${
                typeFilter === 'warehouse' ? 'bg-white shadow-xs text-zinc-900' : 'text-zinc-600'
              }`}
            >
              مخازن
            </button>
          </div>
        </div>
      </div>

      {/* Branches Table */}
      <div className="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
        {filteredBranches.length === 0 ? (
          <EmptyState
            title="لا توجد فروع أو مخازن"
            text="أضف أول فرع أو مخزن لتبدأ في توزيع وإدارة المخزون."
            action={
              <Button size="sm" onClick={openNewDrawer}>
                إضافة موقع
              </Button>
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-right text-sm">
              <thead className="bg-zinc-50 border-b border-zinc-200 text-xs font-semibold text-zinc-600">
                <tr>
                  <th className="px-5 py-3.5">الموقع / الفرع</th>
                  <th className="px-4 py-3.5">النوع</th>
                  <th className="px-4 py-3.5">المدينة والعنوان</th>
                  <th className="px-4 py-3.5">الهاتف</th>
                  <th className="px-4 py-3.5 text-center">مواقع التخزين</th>
                  <th className="px-4 py-3.5">الحالة</th>
                  <th className="px-4 py-3.5 text-center">إجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-200">
                {filteredBranches.map((b) => (
                  <tr
                    key={b.id}
                    onClick={() => navigate(`/admin/branches/${b.id}`)}
                    className="hover:bg-zinc-50/70 cursor-pointer transition-colors"
                  >
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-3">
                        <div
                          className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${
                            b.type === 'warehouse'
                              ? 'bg-amber-50 text-amber-700'
                              : 'bg-blue-50 text-blue-700'
                          }`}
                        >
                          {b.type === 'warehouse' ? (
                            <Warehouse className="w-5 h-5" />
                          ) : (
                            <Store className="w-5 h-5" />
                          )}
                        </div>
                        <div>
                          <div className="font-bold text-zinc-900">{b.name}</div>
                          <span className="text-xs font-mono text-zinc-400 ltr block">{b.code}</span>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3.5">
                      <span
                        className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                          b.type === 'warehouse'
                            ? 'bg-amber-100 text-amber-800'
                            : 'bg-sky-100 text-sky-800'
                        }`}
                      >
                        {b.type === 'warehouse' ? 'مخزن' : 'فرع بيع'}
                      </span>
                    </td>
                    <td className="px-4 py-3.5 text-xs text-zinc-600">
                      <div className="font-medium text-zinc-800">{b.city || '—'}</div>
                      <span className="text-zinc-400 text-[11px] block">{b.address || '—'}</span>
                    </td>
                    <td className="px-4 py-3.5 font-mono text-xs text-zinc-600 ltr">
                      {b.phone || '—'}
                    </td>
                    <td className="px-4 py-3.5 text-center font-bold text-xs text-brand-600">
                      {b.locations} مواقع
                    </td>
                    <td className="px-4 py-3.5">
                      <Badge variant={b.active ? 'success' : 'warning'}>
                        {b.active ? 'نشط' : 'معطل'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3.5 text-center" onClick={(e) => e.stopPropagation()}>
                      <div className="flex items-center justify-center gap-1.5">
                        <button
                          onClick={() => openEditDrawer(b)}
                          className="p-1.5 rounded-lg text-zinc-400 hover:text-brand-600 hover:bg-zinc-100 transition-colors"
                          title="تعديل البيانات"
                        >
                          <Edit className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => setDeleteTarget(b)}
                          className="p-1.5 rounded-lg text-zinc-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                          title="حذف الفرع"
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

      {/* Branch Create/Edit Drawer */}
      <Drawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        title={editingBranch ? `تعديل ${editingBranch.name}` : 'إضافة فرع أو مخزن'}
        crumbs={<span>الفروع والمخازن</span>}
        footer={
          <div className="flex items-center gap-2 w-full justify-end">
            <Button variant="outline" size="sm" onClick={() => setDrawerOpen(false)}>
              إلغاء
            </Button>
            <Button size="sm" onClick={handleSaveBranch}>
              حفظ
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <Field label="نوع الموقع" req>
            <RadioCards
              value={formData.type}
              onChange={(t) => setFormData({ ...formData, type: t as any })}
              options={[
                { value: 'branch', title: 'فرع بيع قطاعي', description: 'نقطة بيع للعملاء ومخزون عرض' },
                { value: 'warehouse', title: 'مخزن رئيسي / لوجستي', description: 'تخزين وتوزيع وتوريدات' },
              ]}
            />
          </Field>

          <Field label="اسم الفرع أو المخزن" req>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              placeholder="مثال: فرع التجمع الخامس"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <Field label="الكود الفريد" req help="حروف إنجليزية كبيرة وأرقام، يظهر في الفواتير.">
            <input
              type="text"
              dir="ltr"
              value={formData.code}
              onChange={(e) => setFormData({ ...formData, code: e.target.value.toUpperCase() })}
              placeholder="TGM-05"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs font-mono focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <div className="grid grid-cols-2 gap-4">
            <Field label="رقم الهاتف" opt>
              <input
                type="text"
                dir="ltr"
                value={formData.phone}
                onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                placeholder="01xxxxxxxxx"
                className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs font-mono focus:border-brand-500 focus:outline-none"
              />
            </Field>
            <Field label="البريد الإلكتروني" opt>
              <input
                type="email"
                dir="ltr"
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                placeholder="branch@alfarid.eg"
                className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
              />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <Field label="المدينة" opt>
              <input
                type="text"
                value={formData.city}
                onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                placeholder="القاهرة"
                className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
              />
            </Field>
            <Field label="العنوان التفصيلي" opt>
              <input
                type="text"
                value={formData.address}
                onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                placeholder="شارع التسعين الشمالي"
                className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
              />
            </Field>
          </div>

          <div className="pt-2">
            <Toggle
              checked={formData.active}
              onChange={(active) => setFormData({ ...formData, active })}
              label="الموقع نشط وجاهز للعمليات"
            />
          </div>
        </div>
      </Drawer>

      {/* Delete Confirmation Modal */}
      <ConfirmModal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title={`حذف «${deleteTarget?.name}»؟`}
        text="سيتم حذف الفرع نهائياً. لا يمكن التراجع عن هذا الإجراء."
        rejectWith={
          deleteTarget && deleteTarget.locations > 0 ? (
            <span>
              لا يمكن حذف الفرع لوجود <b>{deleteTarget.locations}</b> مواقع تخزين ومخزون مرتبط به.
              يمكنك تعطيله بدلاً من الحذف لحفظ السجلات.
            </span>
          ) : null
        }
        confirmLabel="حذف الفرع"
        onConfirm={() => {
          if (deleteTarget) {
            deleteBranch(deleteTarget.id);
            setDeleteTarget(null);
          }
        }}
      />
    </div>
  );
}

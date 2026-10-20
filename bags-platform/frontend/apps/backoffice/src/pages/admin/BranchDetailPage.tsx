import React, { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { PageHeader } from '../../components/admin/PageHeader';
import { Drawer } from '../../components/admin/Drawer';
import { ConfirmModal } from '../../components/admin/ConfirmModal';
import { Field, Toggle } from '../../components/admin/FormFields';
import { Button, Badge } from '@alfarid/ui';
import { useAdminDataStore, StorageLocation } from '../../stores/admin-data.store';
import { Plus, Edit, Trash2, ArrowRight, Store, Warehouse, MapPin, Phone, Mail } from 'lucide-react';

const LOC_TYPES: Record<string, { label: string; badge: 'default' | 'success' | 'warning' | 'danger' | 'info' }> = {
  shelf: { label: 'رف تخزين', badge: 'default' },
  zone: { label: 'منطقة عرض', badge: 'info' },
  bin: { label: 'خانة إكسسوارات', badge: 'default' },
  damaged: { label: 'تالف ومرتجع', badge: 'danger' },
  receiving: { label: 'منطقة استلام', badge: 'warning' },
};

export function BranchDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { branches, locations, addLocation, updateLocation, deleteLocation, updateBranch } =
    useAdminDataStore();

  const branch = branches.find((b) => b.id === Number(id)) || branches[0];
  const branchLocations = locations.filter((l) => l.branchId === branch.id);

  const [activeTab, setActiveTab] = useState<'locations' | 'info'>('locations');

  // Location Drawer
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [editingLoc, setEditingLoc] = useState<StorageLocation | null>(null);
  const [locData, setLocData] = useState({
    name: '',
    code: '',
    type: 'shelf' as 'shelf' | 'zone' | 'bin' | 'damaged' | 'receiving',
    active: true,
  });

  const [deleteLocTarget, setDeleteLocTarget] = useState<StorageLocation | null>(null);

  const openNewLocDrawer = () => {
    setEditingLoc(null);
    setLocData({
      name: '',
      code: `L-${branchLocations.length + 1}`,
      type: 'shelf',
      active: true,
    });
    setDrawerOpen(true);
  };

  const openEditLocDrawer = (loc: StorageLocation) => {
    setEditingLoc(loc);
    setLocData({
      name: loc.name,
      code: loc.code,
      type: loc.type,
      active: loc.active,
    });
    setDrawerOpen(true);
  };

  const handleSaveLocation = () => {
    if (!locData.name || !locData.code) return;
    if (editingLoc) {
      updateLocation(editingLoc.id, locData);
    } else {
      addLocation({
        branchId: branch.id,
        name: locData.name,
        code: locData.code,
        type: locData.type,
        active: locData.active,
      });
    }
    setDrawerOpen(false);
  };

  return (
    <div>
      <PageHeader
        crumbs={[['الفروع والمخازن', '/admin/branches'], [branch.name]]}
        light={branch.type === 'warehouse' ? 'مخزن' : 'فرع'}
        bold={branch.name}
        sub={
          <div className="flex items-center gap-2 mt-1">
            <span className="font-mono text-zinc-500 font-bold ltr">{branch.code}</span>
            <span>•</span>
            <span>{branch.city}</span>
            <span>•</span>
            <Badge variant={branch.active ? 'success' : 'warning'}>
              {branch.active ? 'نشط' : 'معطل'}
            </Badge>
          </div>
        }
        actions={
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            onClick={() => navigate('/admin/branches')}
          >
            <ArrowRight className="w-4 h-4" />
            <span>العودة للفروع</span>
          </Button>
        }
      />

      {/* Tabs */}
      <div className="flex items-center gap-3 border-b border-zinc-200 mb-6 text-sm">
        <button
          onClick={() => setActiveTab('locations')}
          className={`pb-3 font-bold border-b-2 transition-colors flex items-center gap-2 ${
            activeTab === 'locations'
              ? 'border-brand-600 text-brand-600'
              : 'border-transparent text-zinc-500 hover:text-zinc-800'
          }`}
        >
          <span>مواقع التخزين في الفرع</span>
          <span className="px-2 py-0.5 rounded-full text-xs bg-zinc-100 text-zinc-700">
            {branchLocations.length}
          </span>
        </button>
        <button
          onClick={() => setActiveTab('info')}
          className={`pb-3 font-bold border-b-2 transition-colors ${
            activeTab === 'info'
              ? 'border-brand-600 text-brand-600'
              : 'border-transparent text-zinc-500 hover:text-zinc-800'
          }`}
        >
          بيانات وتفاصيل الفرع
        </button>
      </div>

      {activeTab === 'locations' ? (
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <p className="text-xs text-zinc-500">
              كل مواقع التخزين أدناه تابعة لـ <strong>{branch.name}</strong> فقط، وأكوادها فريدة داخل هذا الموقع.
            </p>
            <Button size="sm" className="gap-2" onClick={openNewLocDrawer}>
              <Plus className="w-4 h-4" />
              <span>إضافة موقع تخزين</span>
            </Button>
          </div>

          <div className="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
            <table className="w-full text-right text-sm">
              <thead className="bg-zinc-50 border-b border-zinc-200 text-xs font-semibold text-zinc-600">
                <tr>
                  <th className="px-5 py-3.5">اسم الموقع</th>
                  <th className="px-4 py-3.5">الكود</th>
                  <th className="px-4 py-3.5">النوع</th>
                  <th className="px-4 py-3.5">الحالة</th>
                  <th className="px-4 py-3.5 text-center">إجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-200">
                {branchLocations.map((loc) => (
                  <tr key={loc.id} className="hover:bg-zinc-50/70">
                    <td className="px-5 py-3.5 font-bold text-zinc-900">{loc.name}</td>
                    <td className="px-4 py-3.5 font-mono text-xs text-zinc-600 ltr">{loc.code}</td>
                    <td className="px-4 py-3.5">
                      <Badge variant={LOC_TYPES[loc.type]?.badge || 'default'}>
                        {LOC_TYPES[loc.type]?.label || loc.type}
                      </Badge>
                    </td>
                    <td className="px-4 py-3.5">
                      <Badge variant={loc.active ? 'success' : 'warning'}>
                        {loc.active ? 'نشط' : 'معطل'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3.5 text-center">
                      <div className="flex items-center justify-center gap-1.5">
                        <button
                          onClick={() => openEditLocDrawer(loc)}
                          className="p-1.5 rounded-lg text-zinc-400 hover:text-brand-600 hover:bg-zinc-100 transition-colors"
                        >
                          <Edit className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => setDeleteLocTarget(loc)}
                          className="p-1.5 rounded-lg text-zinc-400 hover:text-red-600 hover:bg-red-50 transition-colors"
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
        </div>
      ) : (
        <div className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm max-w-2xl space-y-4">
          <h3 className="text-sm font-bold text-zinc-900 border-b border-zinc-100 pb-3">
            المعلومات الأساسية
          </h3>
          <dl className="grid grid-cols-2 gap-4 text-xs">
            <div>
              <dt className="text-zinc-400 mb-1">اسم الموقع:</dt>
              <dd className="font-bold text-zinc-900 text-sm">{branch.name}</dd>
            </div>
            <div>
              <dt className="text-zinc-400 mb-1">الكود الفريد:</dt>
              <dd className="font-mono font-bold text-zinc-900 ltr">{branch.code}</dd>
            </div>
            <div>
              <dt className="text-zinc-400 mb-1">النوع:</dt>
              <dd className="font-semibold text-zinc-800">
                {branch.type === 'warehouse' ? 'مخزن' : 'فرع بيع قطاعي'}
              </dd>
            </div>
            <div>
              <dt className="text-zinc-400 mb-1">المدينة:</dt>
              <dd className="font-semibold text-zinc-800">{branch.city}</dd>
            </div>
            <div>
              <dt className="text-zinc-400 mb-1">الهاتف:</dt>
              <dd className="font-mono text-zinc-800 ltr">{branch.phone || '—'}</dd>
            </div>
            <div>
              <dt className="text-zinc-400 mb-1">البريد:</dt>
              <dd className="font-mono text-zinc-800 ltr">{branch.email || '—'}</dd>
            </div>
            <div className="col-span-2">
              <dt className="text-zinc-400 mb-1">العنوان:</dt>
              <dd className="font-medium text-zinc-800">{branch.address || '—'}</dd>
            </div>
          </dl>
        </div>
      )}

      {/* Location Drawer */}
      <Drawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        title={editingLoc ? 'تعديل موقع تخزين' : 'موقع تخزين جديد'}
        crumbs={<span>{branch.name} / مواقع التخزين</span>}
        footer={
          <div className="flex items-center gap-2 w-full justify-end">
            <Button variant="outline" size="sm" onClick={() => setDrawerOpen(false)}>
              إلغاء
            </Button>
            <Button size="sm" onClick={handleSaveLocation}>
              حفظ
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <Field label="الفرع التابع له" help="الموقع يُنشأ داخل هذا الفرع حصراً.">
            <input
              type="text"
              readOnly
              value={`${branch.name} (${branch.code})`}
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-600"
            />
          </Field>

          <Field label="اسم الموقع" req>
            <input
              type="text"
              value={locData.name}
              onChange={(e) => setLocData({ ...locData, name: e.target.value })}
              placeholder="مثال: رف حقائب اليد A"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <Field label="الكود الفريد للموقع" req>
            <input
              type="text"
              dir="ltr"
              value={locData.code}
              onChange={(e) => setLocData({ ...locData, code: e.target.value.toUpperCase() })}
              placeholder="A-01"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs font-mono focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <Field label="نوع الموقع" req>
            <div className="grid grid-cols-2 gap-2">
              {Object.entries(LOC_TYPES).map(([k, v]) => (
                <button
                  key={k}
                  type="button"
                  onClick={() => setLocData({ ...locData, type: k as any })}
                  className={`p-2.5 rounded-xl border text-xs font-medium text-center transition-all ${
                    locData.type === k
                      ? 'border-brand-600 bg-brand-50 text-brand-700 font-bold'
                      : 'border-zinc-200 text-zinc-700 hover:bg-zinc-50'
                  }`}
                >
                  {v.label}
                </button>
              ))}
            </div>
          </Field>

          <div className="pt-2">
            <Toggle
              checked={locData.active}
              onChange={(active) => setLocData({ ...locData, active })}
              label="الموقع نشط وجاهز للتخزين"
            />
          </div>
        </div>
      </Drawer>

      {/* Delete Location Modal */}
      <ConfirmModal
        open={!!deleteLocTarget}
        onClose={() => setDeleteLocTarget(null)}
        title={`حذف موقع «${deleteLocTarget?.name}»؟`}
        text="سيتم حذف موقع التخزين هذا من الفرع."
        confirmLabel="حذف"
        onConfirm={() => {
          if (deleteLocTarget) {
            deleteLocation(deleteLocTarget.id);
            setDeleteLocTarget(null);
          }
        }}
      />
    </div>
  );
}

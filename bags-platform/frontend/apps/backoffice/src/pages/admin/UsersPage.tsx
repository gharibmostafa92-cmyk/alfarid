import React, { useState, useMemo } from 'react';
import { PageHeader } from '../../components/admin/PageHeader';
import { Drawer } from '../../components/admin/Drawer';
import { ConfirmModal } from '../../components/admin/ConfirmModal';
import { SearchSelect } from '../../components/admin/SearchSelect';
import { Field, Toggle } from '../../components/admin/FormFields';
import { EmptyState } from '../../components/admin/States';
import { Button, Badge } from '@alfarid/ui';
import { useAdminDataStore, StaffMember } from '../../stores/admin-data.store';
import { Plus, Search, Edit, Trash2, ShieldCheck, UserCheck } from 'lucide-react';

export function UsersPage() {
  const { users, roles, branches, addUser, updateUser, deleteUser } = useAdminDataStore();

  const [query, setQuery] = useState('');
  const [roleFilter, setRoleFilter] = useState<number | null>(null);
  const [branchFilter, setBranchFilter] = useState<number | null>(null);

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [editingUser, setEditingUser] = useState<StaffMember | null>(null);
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    branch: null as number | null,
    roles: [] as number[],
    all: false,
    active: true,
  });

  const [deleteTarget, setDeleteTarget] = useState<StaffMember | null>(null);

  const filteredUsers = useMemo(() => {
    return users.filter((u) => {
      if (
        query &&
        !`${u.name} ${u.email} ${u.phone}`.toLowerCase().includes(query.toLowerCase())
      ) {
        return false;
      }
      if (roleFilter && !u.roles.includes(roleFilter)) return false;
      if (branchFilter && u.branch !== branchFilter) return false;
      return true;
    });
  }, [users, query, roleFilter, branchFilter]);

  const openNewDrawer = () => {
    setEditingUser(null);
    setFormData({
      name: '',
      email: '',
      phone: '',
      branch: null,
      roles: [5],
      all: false,
      active: true,
    });
    setDrawerOpen(true);
  };

  const openEditDrawer = (u: StaffMember) => {
    setEditingUser(u);
    setFormData({
      name: u.name,
      email: u.email,
      phone: u.phone,
      branch: u.branch,
      roles: u.roles,
      all: Boolean(u.all),
      active: u.active,
    });
    setDrawerOpen(true);
  };

  const handleSaveUser = () => {
    if (!formData.name || !formData.email) return;
    if (editingUser) {
      updateUser(editingUser.id, formData);
    } else {
      addUser(formData);
    }
    setDrawerOpen(false);
  };

  return (
    <div>
      <PageHeader
        crumbs={[['الرئيسية', '/admin'], ['الإدارة'], ['المستخدمون']]}
        light="إدارة"
        bold="المستخدمين وفريق العمل"
        sub="ربط كل مستخدم بفرع محدد وأدوار وظيفية. الوصول لكل الفروع امتياز صريح منفصل."
        actions={
          <Button size="sm" className="gap-2" onClick={openNewDrawer}>
            <Plus className="w-4 h-4" />
            <span>إضافة مستخدم جديد</span>
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
            placeholder="ابحث بالاسم أو البريد..."
            className="w-full h-10 pr-9 pl-3 rounded-xl border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
          />
        </div>

        <div className="flex items-center gap-3">
          <div className="w-40">
            <SearchSelect
              value={roleFilter}
              onChange={setRoleFilter}
              placeholder="كل الأدوار"
              clearable
              options={roles.map((r) => ({ value: r.id, label: r.name }))}
            />
          </div>
          <div className="w-44">
            <SearchSelect
              value={branchFilter}
              onChange={setBranchFilter}
              placeholder="كل الفروع"
              clearable
              options={branches.map((b) => ({ value: b.id, label: b.name }))}
            />
          </div>
        </div>
      </div>

      {/* Users Table */}
      <div className="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
        {filteredUsers.length === 0 ? (
          <EmptyState
            title="لا يوجد مستخدمون مطابقون"
            text="جرّب بحثاً آخر أو أضف موظفاً جديداً لفريق العمل."
            action={
              <Button size="sm" onClick={openNewDrawer}>
                إضافة مستخدم
              </Button>
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-right text-sm">
              <thead className="bg-zinc-50 border-b border-zinc-200 text-xs font-semibold text-zinc-600">
                <tr>
                  <th className="px-5 py-3.5">الموظف</th>
                  <th className="px-4 py-3.5">الفرع التابع له</th>
                  <th className="px-4 py-3.5">الأدوار الممنوحة</th>
                  <th className="px-4 py-3.5">الحالة</th>
                  <th className="px-4 py-3.5 text-center">إجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-200">
                {filteredUsers.map((u) => {
                  const userBranch = branches.find((b) => b.id === u.branch);
                  const userRoles = roles.filter((r) => u.roles.includes(r.id));
                  const initials = u.name
                    .split(' ')
                    .map((s) => s[0])
                    .join('')
                    .slice(0, 2);

                  return (
                    <tr key={u.id} className="hover:bg-zinc-50/70 transition-colors">
                      <td className="px-5 py-3.5">
                        <div className="flex items-center gap-3">
                          <div className="w-10 h-10 rounded-xl bg-brand-50 text-brand-700 font-bold flex items-center justify-center shrink-0 text-xs">
                            {initials}
                          </div>
                          <div>
                            <div className="font-bold text-zinc-900">{u.name}</div>
                            <span className="text-xs text-zinc-400 font-mono ltr block">
                              {u.email}
                            </span>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3.5 text-xs">
                        {u.all ? (
                          <span className="inline-flex items-center gap-1 bg-amber-50 text-amber-800 border border-amber-200 px-2 py-0.5 rounded-full font-bold">
                            <ShieldCheck className="w-3.5 h-3.5" />
                            <span>كل الفروع (امتياز صريح)</span>
                          </span>
                        ) : userBranch ? (
                          <span className="font-medium text-zinc-800">{userBranch.name}</span>
                        ) : (
                          <span className="text-zinc-400 font-normal">بدون فرع</span>
                        )}
                      </td>
                      <td className="px-4 py-3.5">
                        <div className="flex items-center gap-1.5 flex-wrap">
                          {userRoles.map((r) => (
                            <span
                              key={r.id}
                              className="px-2 py-0.5 rounded-md bg-zinc-100 text-zinc-700 text-xs font-medium"
                            >
                              {r.name}
                            </span>
                          ))}
                        </div>
                      </td>
                      <td className="px-4 py-3.5">
                        <Badge variant={u.active ? 'success' : 'warning'}>
                          {u.active ? 'نشط' : 'معطل'}
                        </Badge>
                      </td>
                      <td className="px-4 py-3.5 text-center">
                        <div className="flex items-center justify-center gap-1.5">
                          <button
                            onClick={() => openEditDrawer(u)}
                            className="p-1.5 rounded-lg text-zinc-400 hover:text-brand-600 hover:bg-zinc-100 transition-colors"
                            title="تعديل المستخدم"
                          >
                            <Edit className="w-4 h-4" />
                          </button>
                          <button
                            onClick={() => setDeleteTarget(u)}
                            className="p-1.5 rounded-lg text-zinc-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                            title="حذف المستخدم"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* User Create/Edit Drawer */}
      <Drawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        title={editingUser ? `تعديل ${editingUser.name}` : 'إضافة مستخدم جديد'}
        crumbs={<span>المستخدمون</span>}
        footer={
          <div className="flex items-center gap-2 w-full justify-end">
            <Button variant="outline" size="sm" onClick={() => setDrawerOpen(false)}>
              إلغاء
            </Button>
            <Button size="sm" onClick={handleSaveUser}>
              حفظ
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <Field label="الاسم الكامل" req>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              placeholder="مثال: كريم سامي"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <Field label="البريد الإلكتروني" req>
            <input
              type="email"
              dir="ltr"
              value={formData.email}
              onChange={(e) => setFormData({ ...formData, email: e.target.value })}
              placeholder="user@alfarid.eg"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none font-mono"
            />
          </Field>

          <Field label="رقم الهاتف" opt>
            <input
              type="text"
              dir="ltr"
              value={formData.phone}
              onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
              placeholder="010xxxxxxxx"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none font-mono"
            />
          </Field>

          <Field
            label="الفرع المرتبط"
            opt
            help="«بدون فرع» يعني أن المستخدم غير مرتبط بأي فرع — وليس الوصول لكل الفروع."
          >
            <SearchSelect
              value={formData.branch}
              onChange={(b) => setFormData({ ...formData, branch: b })}
              placeholder="بدون فرع"
              clearable
              options={[
                { value: null, label: 'بدون فرع', sub: 'غير مقيد بفرع محدد' },
                ...branches.map((b) => ({
                  value: b.id,
                  label: b.name,
                  sub: b.code,
                  disabled: !b.active,
                  why: !b.active ? 'غير نشط' : null,
                })),
              ]}
            />
          </Field>

          {/* Access All Branches Toggle */}
          <div className="p-3.5 bg-zinc-50 rounded-xl border border-zinc-200 space-y-1">
            <Toggle
              checked={formData.all}
              onChange={(all) => setFormData({ ...formData, all })}
              label={<span className="font-bold text-zinc-900">الوصول لجميع الفروع (branches.access_all)</span>}
            />
            <p className="text-[11px] text-zinc-500 leading-normal mr-7">
              امتياز صريح يمنح الاطلاع على بيانات ومخزون جميع الفروع بغض النظر عن الفرع المرتبط به.
            </p>
          </div>

          <Field label="الأدوار والصلاحيات" opt>
            <SearchSelect
              multi
              value={formData.roles}
              onChange={(r) => setFormData({ ...formData, roles: r })}
              placeholder="اختر دوراً أو أكثر..."
              options={roles.map((r) => ({
                value: r.id,
                label: r.name,
                sub: `${r.users} مستخدم`,
              }))}
            />
          </Field>

          <div className="pt-2">
            <Toggle
              checked={formData.active}
              onChange={(active) => setFormData({ ...formData, active })}
              label="الحساب نشط وجاهز لتسجيل الدخول"
            />
          </div>
        </div>
      </Drawer>

      {/* Delete Confirmation Modal */}
      <ConfirmModal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title={`حذف مستخدم «${deleteTarget?.name}»؟`}
        text="سيتم إيقاف صلاحيات هذا المستخدم وحذفه من النظام نهائياً."
        confirmLabel="حذف المستخدم"
        onConfirm={() => {
          if (deleteTarget) {
            deleteUser(deleteTarget.id);
            setDeleteTarget(null);
          }
        }}
      />
    </div>
  );
}

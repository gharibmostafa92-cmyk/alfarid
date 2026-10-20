import React, { useState } from 'react';
import { PageHeader } from '../../components/admin/PageHeader';
import { AlertBanner } from '../../components/admin/States';
import { Button, Badge } from '@alfarid/ui';
import { useAdminDataStore, PERM_GROUPS } from '../../stores/admin-data.store';
import { ShieldCheck, Shield, ChevronDown, ChevronUp, Search, Check, Save } from 'lucide-react';

export function RolesPage() {
  const { roles, updateRole } = useAdminDataStore();
  const [selectedRoleId, setSelectedRoleId] = useState<number>(2);
  const [query, setQuery] = useState('');
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({
    products: true,
    categories: true,
    branches: true,
  });

  const selectedRole = roles.find((r) => r.id === selectedRoleId) || roles[0];
  const [rolePermissions, setRolePermissions] = useState<Set<string>>(
    new Set(selectedRole.permissions || [])
  );
  const [savedNotice, setSavedNotice] = useState(false);

  const handleSelectRole = (rId: number) => {
    setSelectedRoleId(rId);
    const r = roles.find((x) => x.id === rId);
    setRolePermissions(new Set(r?.permissions || []));
    setSavedNotice(false);
  };

  const togglePermission = (key: string) => {
    if (selectedRole.system) return;
    setRolePermissions((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const toggleGroup = (groupPerms: string[][], selectAll: boolean) => {
    if (selectedRole.system) return;
    setRolePermissions((prev) => {
      const next = new Set(prev);
      groupPerms.forEach(([k]) => {
        if (selectAll) next.add(k);
        else next.delete(k);
      });
      return next;
    });
  };

  const handleSaveRole = () => {
    updateRole(selectedRole.id, { permissions: Array.from(rolePermissions) });
    setSavedNotice(true);
    setTimeout(() => setSavedNotice(false), 3000);
  };

  const totalPermsCount = PERM_GROUPS.reduce((acc, g) => acc + g.perms.length, 0);

  return (
    <div>
      <PageHeader
        crumbs={[['الرئيسية', '/admin'], ['الإدارة'], ['الأدوار والصلاحيات']]}
        light="الأدوار"
        bold="والصلاحيات"
        sub="الصلاحيات معروضة بأسماء مفهومة مع مفتاحها التقني. يمكنك تخصيص الصلاحيات لكل دور بسهولة."
      />

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
        {/* Left Column: Roles list */}
        <div className="bg-white border border-zinc-200 rounded-2xl p-3 shadow-sm space-y-1">
          <div className="px-3 py-2 text-xs font-bold text-zinc-400 uppercase">قائمة الأدوار</div>
          {roles.map((r) => {
            const isSelected = r.id === selectedRole.id;
            return (
              <div
                key={r.id}
                onClick={() => handleSelectRole(r.id)}
                className={`flex items-center justify-between p-3 rounded-xl cursor-pointer transition-all ${
                  isSelected
                    ? 'bg-brand-50 border border-brand-200 text-brand-900 shadow-sm'
                    : 'hover:bg-zinc-50 border border-transparent text-zinc-800'
                }`}
              >
                <div className="flex items-center gap-3">
                  <div
                    className={`w-8 h-8 rounded-lg flex items-center justify-center ${
                      isSelected ? 'bg-brand-600 text-white' : 'bg-zinc-100 text-zinc-500'
                    }`}
                  >
                    <Shield className="w-4 h-4" />
                  </div>
                  <div>
                    <div className="font-bold text-xs">{r.name}</div>
                    <span className="text-[11px] text-zinc-400">
                      {r.users} مستخدم {r.system ? '• نظام' : ''}
                    </span>
                  </div>
                </div>
                {r.system && (
                  <span className="text-[10px] bg-zinc-100 text-zinc-600 px-2 py-0.5 rounded font-medium">
                    ثابت
                  </span>
                )}
              </div>
            );
          })}
        </div>

        {/* Right Column: Role Permissions details */}
        <div className="md:col-span-2 bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-5">
          <div className="flex items-center justify-between border-b border-zinc-100 pb-4">
            <div>
              <div className="flex items-center gap-2">
                <h3 className="text-base font-bold text-zinc-900">{selectedRole.name}</h3>
                {selectedRole.system && <Badge variant="warning">دور أساسي بالنظام</Badge>}
              </div>
              <p className="text-xs text-zinc-500 mt-0.5">
                محدد {selectedRole.system ? totalPermsCount : rolePermissions.size} من أصل{' '}
                {totalPermsCount} صلاحية
              </p>
            </div>

            {!selectedRole.system && (
              <Button size="sm" className="gap-2" onClick={handleSaveRole}>
                <Save className="w-4 h-4" />
                <span>حفظ صلاحيات الدور</span>
              </Button>
            )}
          </div>

          {savedNotice && (
            <AlertBanner tone="ok" title="تم بنجاح">
              تم تحديث وحفظ صلاحيات الدور «{selectedRole.name}» في النظام.
            </AlertBanner>
          )}

          {selectedRole.system ? (
            <AlertBanner tone="info" title="صلاحيات كاملة">
              دور «مدير النظام» يملك بطبيعته حق الوصول لكافة وظائف وشاشات المنصة بدون استثناء ولا يمكن تقييده.
            </AlertBanner>
          ) : (
            <>
              {/* Search permissions */}
              <div className="relative">
                <Search className="w-4 h-4 text-zinc-400 absolute right-3 top-2.5 pointer-events-none" />
                <input
                  type="text"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  placeholder="ابحث في الصلاحيات بالعربية أو المفتاح التقني..."
                  className="w-full h-9 pr-9 pl-3 rounded-xl border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
                />
              </div>

              {/* Groups Accordion */}
              <div className="space-y-3">
                {PERM_GROUPS.map((group) => {
                  const filteredPerms = group.perms.filter(
                    ([k, label]) =>
                      !query ||
                      `${k} ${label}`.toLowerCase().includes(query.toLowerCase())
                  );

                  if (filteredPerms.length === 0) return null;

                  const selectedInGroup = group.perms.filter(([k]) => rolePermissions.has(k)).length;
                  const allSelected = selectedInGroup === group.perms.length;
                  const isOpen = openGroups[group.key] || Boolean(query);

                  return (
                    <div
                      key={group.key}
                      className="border border-zinc-200 rounded-xl overflow-hidden"
                    >
                      <div
                        onClick={() =>
                          setOpenGroups((prev) => ({ ...prev, [group.key]: !prev[group.key] }))
                        }
                        className="p-3 bg-zinc-50/70 flex items-center justify-between cursor-pointer hover:bg-zinc-100/60 transition-colors"
                      >
                        <div className="flex items-center gap-3">
                          <input
                            type="checkbox"
                            checked={allSelected}
                            onChange={(e) => {
                              e.stopPropagation();
                              toggleGroup(group.perms, e.target.checked);
                            }}
                            className="rounded text-brand-600 focus:ring-brand-500"
                          />
                          <span className="font-bold text-xs text-zinc-900">{group.label}</span>
                          <span className="text-[11px] font-mono text-zinc-400">
                            ({selectedInGroup}/{group.perms.length})
                          </span>
                        </div>

                        {isOpen ? (
                          <ChevronUp className="w-4 h-4 text-zinc-400" />
                        ) : (
                          <ChevronDown className="w-4 h-4 text-zinc-400" />
                        )}
                      </div>

                      {isOpen && (
                        <div className="p-3 divide-y divide-zinc-100 bg-white">
                          {filteredPerms.map(([key, label]) => {
                            const isChecked = rolePermissions.has(key);
                            return (
                              <label
                                key={key}
                                className="py-2 px-1 flex items-center justify-between hover:bg-zinc-50 rounded-lg cursor-pointer transition-colors text-xs"
                              >
                                <div className="flex items-center gap-2.5">
                                  <input
                                    type="checkbox"
                                    checked={isChecked}
                                    onChange={() => togglePermission(key)}
                                    className="rounded text-brand-600 focus:ring-brand-500"
                                  />
                                  <span className="font-medium text-zinc-800">{label}</span>
                                </div>
                                <span className="font-mono text-[11px] text-zinc-400 ltr">
                                  {key}
                                </span>
                              </label>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

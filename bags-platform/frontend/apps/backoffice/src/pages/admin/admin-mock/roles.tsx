import { canAny } from '@alfarid/utils';
import React, { useState, useEffect, useRef } from 'react';
import {
  Icon,
  Toggle,
  Badge,
  Field,
  TextInput,
  Checkbox,
  GhostButton,
  PageHeader,
  StickyBar,
  Skeleton,
  EmptyState,
  Notice,
  toast,
  Modal,
  Drawer,
} from './ui';
import { AdminRole } from './data';
import { usePermissionGroups, useMe, runMutation, useQueryError } from './hooks';
import { adminApi } from './api/endpoints';
import { buildRolePatch } from './api/mappers';
import { buildPermissionGroups } from './api/permission-catalog';

export interface RolesScreenProps {
  roles: AdminRole[];
  rolesLoading: boolean;
  reloadRoles: () => void;
  can: (p: string) => boolean;
  onNavigate?: (page: 'users' | 'roles' | 'settings', params?: Record<string, any>) => void;
  initialRoleId?: number;
}

export function RolesScreen({ roles, rolesLoading, reloadRoles, can, onNavigate, initialRoleId }: RolesScreenProps) {
  const permissionsQ = usePermissionGroups();
  useQueryError(permissionsQ.error?.status === 403 ? undefined : permissionsQ.error);
  const { iAmSuper, myPerms, centralAccess } = useMe();
  const [nameError, setNameError] = useState<string>();
  const [currentPassword, setCurrentPassword] = useState('');
  const [passwordError, setPasswordError] = useState<string>();
  const [deleteConflict, setDeleteConflict] = useState<string>();
  const loading = rolesLoading || permissionsQ.loading;
  const [selId, setSelId] = useState<number | null>(initialRoleId ?? null);
  const [draft, setDraft] = useState<{ id?: number; name: string; perms: string[]; system?: boolean; builtin?: boolean; isNew?: boolean } | null>(null);
  const [q, setQ] = useState('');
  const [openGroups, setOpenGroups] = useState<Set<string>>(() => new Set(['inventory', 'products', 'pos']));
  const [saving, setSaving] = useState(false);
  const [del, setDel] = useState<AdminRole | null>(null);
  const [roleQ, setRoleQ] = useState('');
  const [activeModule, setActiveModule] = useState<string | null>(null);
  const [rolesSheetOpen, setRolesSheetOpen] = useState(false);
  const appliedInitialRole = useRef<number | undefined>(undefined);
  const passwordField = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (initialRoleId && appliedInitialRole.current !== initialRoleId && roles.some(r => r.id === initialRoleId)) {
      if (!isDirty) {
        setSelId(initialRoleId);
        appliedInitialRole.current = initialRoleId;
      }
    }
  }, [initialRoleId, roles]);

  useEffect(() => {
    if (!draft && roles.length && !roles.some(r => r.id === selId)) {
      setSelId((roles.find(r => !r.system) ?? roles[0]).id);
    }
  }, [roles, selId, draft]);

  const usersCount = (id: number) => (roles.find(r => r.id === id)?.users_count ?? 0);
  const sel = roles.find(r => r.id === selId);

  useEffect(() => {
    if (sel) {
      setDraft(prev => {
        if (prev && prev.id === sel.id && JSON.stringify({ name: prev.name, perms: [...prev.perms].sort() }) !== JSON.stringify({ name: sel.name, perms: [...sel.perms].sort() })) {
          return prev;
        }
        return { id: sel.id, name: sel.name, perms: [...sel.perms], system: sel.system, builtin: sel.builtin };
      });
    }
  }, [selId, roles]);

  useEffect(() => {
    setCurrentPassword('');
    setPasswordError(undefined);
  }, [selId, draft?.isNew]);

  const original = draft?.isNew ? { name: '', perms: [] as string[] } : sel;
  const grantsPermissions = draft?.perms.some(permission => !original?.perms.includes(permission)) ?? false;
  const isDirty = draft && original && (draft.isNew || draft.name !== original.name || draft.perms.length !== original.perms.length || draft.perms.some(p => !original.perms.includes(p)));

  useEffect(() => {
    if (!isDirty) return;
    const guard = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', guard);
    return () => window.removeEventListener('beforeunload', guard);
  }, [isDirty]);
  const changeCount = draft && original && !draft.isNew
    ? draft.perms.filter(p => !original.perms.includes(p)).length + original.perms.filter(p => !draft.perms.includes(p)).length + (draft.name !== original.name ? 1 : 0)
    : null;

  const fallbackPermissions: Record<string, {name: string}[]> = {};
  (draft?.perms ?? []).forEach(name => (fallbackPermissions[name.split('.')[0]] ??= []).push({name}));
  const permissionGroups = permissionsQ.data ?? buildPermissionGroups(fallbackPermissions);
  const allPerms = permissionGroups.flatMap(g => g.perms.map(p => p[0]));
  const isSystem = draft?.system;
  const editable = centralAccess && !permissionsQ.error && (draft?.isNew ? canAny(can, 'roles.create', 'roles.manage') : canAny(can, 'roles.update', 'roles.manage'));
  const togglePerm = (k: string) => {
    if (isSystem || !editable || (!iAmSuper && !myPerms.has(k))) return;
    setDraft(d => d ? { ...d, perms: d.perms.includes(k) ? d.perms.filter(x => x !== k) : [...d.perms, k] } : null);
  };
  const toggleGroup = (g: typeof permissionGroups[0], on: boolean) => {
    if (isSystem || !editable) return;
    const keys = g.perms.map(p => p[0]).filter(key => iAmSuper || myPerms.has(key));
    setDraft(d => d ? { ...d, perms: on ? [...new Set([...d.perms, ...keys])] : d.perms.filter(x => !keys.includes(x)) } : null);
  };
  const toggleOpen = (m: string) => setOpenGroups(s => { const n = new Set(s); n.has(m) ? n.delete(m) : n.add(m); return n; });

  const newRole = () => {
    if (!centralAccess || !canAny(can, 'roles.create', 'roles.manage') || permissionsQ.error) return;
    setNameError(undefined);
    setSelId(null);
    setDraft({ name: '', perms: [], isNew: true });
    setOpenGroups(new Set(permissionGroups.map(g => g.module)));
  };
  const reset = () => {
    if (!draft) return;
    if (draft.isNew) {
      const fallback = roles.find(r => !r.system) || roles[0];
      setDraft(null);
      setSelId(fallback?.id ?? null);
      return;
    }
    if (sel) {
      setDraft({ id: sel.id, name: sel.name, perms: [...sel.perms], system: sel.system, builtin: sel.builtin });
    }
  };
  const save = async () => {
    if (!draft || !editable || saving) return;
    if (!draft.name.trim()) { setNameError('اسم الدور مطلوب'); return; }
    if ((!draft.builtin || draft.isNew) && ['super admin', 'cashier'].includes(draft.name.normalize('NFKC').trim().toLowerCase())) { setNameError('هذا الاسم محجوز'); return; }
    if (grantsPermissions && !currentPassword) {
      setPasswordError('كلمة مرورك الحالية مطلوبة لمنح الصلاحيات');
      passwordField.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      passwordField.current?.querySelector('input')?.focus({ preventScroll: true });
      return;
    }
    setSaving(true); setNameError(undefined); setPasswordError(undefined);
    const confirmation = grantsPermissions ? { current_password: currentPassword } : {};
    const result = await runMutation(() => draft.isNew
      ? adminApi.createRole({name: draft.name.trim(), permissions: draft.perms, ...confirmation})
      : adminApi.updateRole(draft.id!, { ...buildRolePatch(draft, sel), ...confirmation }));
    setSaving(false);
    if (!result.ok) {
      if (result.error.status === 422) {
        setNameError(result.error.fields.name?.[0]);
        setPasswordError(result.error.fields.current_password?.[0]);
        if (result.error.fields.current_password?.[0]) {
          passwordField.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
          passwordField.current?.querySelector('input')?.focus({ preventScroll: true });
        }
      }
      if (result.error.status === 404 || result.error.status === 409) reloadRoles();
      if (result.error.status !== 401) toast(result.error.message, 'err');
      return;
    }
    setCurrentPassword('');
    setDraft({...draft, id: result.data.id, isNew: false}); setSelId(result.data.id); reloadRoles();
    toast(draft.isNew ? 'تم إنشاء الدور بنجاح' : 'تم حفظ صلاحيات الدور');
  };
  const doDelete = async (r: AdminRole) => {
    if (saving || !canAny(can, 'roles.delete', 'roles.manage')) return;
    setSaving(true);
    const result = await runMutation(() => adminApi.deleteRole(r.id));
    setSaving(false);
    if (!result.ok) {
      if (result.error.status === 409) setDeleteConflict(result.error.message);
      else if (result.error.status !== 401) toast(result.error.message, 'err');
      if (result.error.status === 404) reloadRoles();
      return;
    }
    setDel(null); setDeleteConflict(undefined); setDraft(null);
    setSelId(roles.find(x => x.id !== r.id && !x.system)?.id ?? null); reloadRoles();
    toast('تم حذف الدور', 'warn');
  };

  const total = Math.max(1, allPerms.length);
  const granted = isSystem ? allPerms.length : draft ? draft.perms.length : 0;
  const pct = Math.round((granted / total) * 100);
  const ql = q.trim().toLowerCase();
  const allGroups = permissionGroups.map(g => ({
    ...g,
    visible: g.perms.filter(p => !ql || p[1].includes(q.trim()) || p[0].toLowerCase().includes(ql)),
  })).filter(g => g.visible.length > 0);
  const groups = (!ql && activeModule) ? allGroups.filter(g => g.module === activeModule) : allGroups;
  const sensitiveGranted = draft ? permissionGroups.flatMap(g => g.perms).filter(p => p[2] && draft.perms.includes(p[0])).length : 0;
  const shownRoles = roles.filter(r => !roleQ || r.name.includes(roleQ));

  return (
    <section className="space-y-5 pb-20" data-screen-label="02 Roles">
      <PageHeader
        title="الأدوار والصلاحيات"
        subtitle="حدّد الهيكل الإداري ووزّع الصلاحيات بدقة — كل موظف يحصل فقط على ما تحتاجه وظيفته."
      />

      {/* Mobile Horizontal Role Strip (< 1024px) */}
      <div className="lg:hidden space-y-2.5 stagger-item stagger-2">
        <div className="flex items-center justify-between gap-2 px-0.5">
          <div className="flex items-center gap-2">
            <h3 className="font-display text-[15px] font-semibold" style={{ color: 'var(--text-primary)' }}>الأدوار</h3>
            <span className="text-[11.5px] tabular-nums" style={{ color: 'var(--text-muted)' }}>({roles.length})</span>
          </div>
          <div className="flex items-center gap-1.5">
            {roles.length > 3 && (
              <button
                type="button"
                onClick={() => setRolesSheetOpen(true)}
                className="h-9 px-3 rounded-full text-[12px] font-medium flex items-center gap-1.5 v-card active:scale-95 transition"
                style={{ color: 'var(--text-secondary)' }}
              >
                <Icon name="search" className="w-3.5 h-3.5" />عرض الكل
              </button>
            )}
            <button
              type="button"
              disabled={!centralAccess || saving || !!permissionsQ.error || !canAny(can, 'roles.create', 'roles.manage')}
              onClick={newRole}
              className="h-9 px-3.5 rounded-full text-[12px] font-semibold flex items-center gap-1.5 active:scale-95 transition"
              style={{ background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }}
            >
              <Icon name="plus" className="w-3.5 h-3.5" strokeWidth={2.4} />دور جديد
            </button>
          </div>
        </div>
        <div className="flex items-center gap-2 overflow-x-auto pb-1.5 -mx-1 px-1 snap-x" style={{ scrollbarWidth: 'none' }}>
          {loading && Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-[52px] w-36 rounded-2xl shrink-0" />)}
          {!loading && draft?.isNew && (
            <div className="v-card v-role-active rounded-2xl px-3.5 py-2.5 flex items-center gap-2.5 shrink-0 snap-start">
              <span className="w-8 h-8 rounded-xl flex items-center justify-center text-white v-accent-btn shrink-0"><Icon name="plus" className="w-3.5 h-3.5" strokeWidth={2.4} /></span>
              <div className="min-w-0">
                <div className="text-[12.5px] font-semibold truncate max-w-[120px]" style={{ color: 'var(--text-primary)' }}>{draft.name || 'دور جديد'}</div>
                <div className="text-[10.5px]" style={{ color: 'var(--text-muted)' }}>مسودة</div>
              </div>
            </div>
          )}
          {!loading && roles.map(r => {
            const active = r.id === selId && !draft?.isNew;
            const uc = usersCount(r.id);
            return (
              <button
                type="button"
                key={r.id}
                onClick={() => {
                  if (r.id !== selId && isDirty && !window.confirm('لديك تعديلات غير محفوظة. هل تريد تجاهلها؟')) return;
                  setSelId(r.id);
                }}
                className={`v-card rounded-2xl px-3 py-2.5 flex items-center gap-2.5 shrink-0 snap-start text-start active:scale-[0.97] transition ${active ? 'v-role-active ring-2 ring-[var(--accent-orange)]' : ''}`}
              >
                <span className={`w-8 h-8 rounded-xl flex items-center justify-center shrink-0 ${r.system ? 'text-white v-accent-btn' : 'v-role-icon'}`}>
                  <Icon name={r.system ? 'shieldCheck' : 'shield'} className="w-4 h-4" />
                </span>
                <div className="min-w-0">
                  <div className="text-[12.5px] font-semibold truncate max-w-[130px]" style={{ color: 'var(--text-primary)' }}>{r.name}</div>
                  <div className="text-[10.5px] tabular-nums" style={{ color: 'var(--text-muted)' }}>{r.perms.length} صلاحية · {uc} مستخدم</div>
                </div>
              </button>
            );
          })}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
        {/* Desktop Roles list */}
        <div className="hidden lg:block lg:col-span-4 xl:col-span-4 v-card p-4 stagger-item stagger-2 lg:sticky lg:top-5">
          <div className="flex items-center justify-between mb-3 px-1">
            <div className="flex items-center gap-2">
              <h3 className="font-display text-[16px] font-semibold" style={{ color: 'var(--text-primary)' }}>الأدوار</h3>
              <span className="text-[11.5px]" style={{ color: 'var(--text-muted)' }}>| &nbsp;{roles.length} أدوار</span>
            </div>
            <button type="button" disabled={!centralAccess || saving || !!permissionsQ.error || !canAny(can, 'roles.create', 'roles.manage')} onClick={newRole} className="h-8 px-3.5 rounded-full text-[12px] font-semibold flex items-center gap-1.5 hover:opacity-90" style={{ background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }}>
              <Icon name="plus" className="w-3.5 h-3.5" strokeWidth={2.4} />دور جديد
            </button>
          </div>
          <div className="v-subcard px-3.5 h-9 rounded-full flex items-center gap-2 mb-3">
            <Icon name="search" className="w-3.5 h-3.5" style={{ color: 'var(--text-muted)' }} />
            <input value={roleQ} onChange={e => setRoleQ(e.target.value)} placeholder="بحث في الأدوار" className="bg-transparent text-[12.5px] outline-none w-full" style={{ color: 'var(--text-primary)' }} />
          </div>
          <div className="space-y-2">
            {loading && Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-[68px] rounded-2xl" />)}
            {!loading && draft?.isNew && (
              <div className="rounded-2xl p-3.5 flex items-center gap-3 v-role-active">
                <span className="w-10 h-10 rounded-xl flex items-center justify-center text-white v-accent-btn"><Icon name="plus" className="w-4 h-4" strokeWidth={2.4} /></span>
                <div className="flex-1"><div className="text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>{draft.name || 'دور جديد'}</div><div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>مسودة غير محفوظة</div></div>
              </div>
            )}
            {!loading && shownRoles.map(r => {
              const active = r.id === selId && !draft?.isNew;
              const uc = usersCount(r.id);
              return (
                <button type="button" key={r.id} onClick={() => {
                  if (r.id !== selId && isDirty && !window.confirm('لديك تعديلات غير محفوظة. هل تريد تجاهلها؟')) return;
                  setSelId(r.id);
                }}
                  className={`w-full rounded-2xl p-3.5 flex items-center gap-3 text-start transition ${active ? 'v-role-active' : 'v-role-item'}`}>
                  <span className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${r.system ? 'text-white v-accent-btn' : 'v-role-icon'}`}>
                    <Icon name={r.system ? 'shieldCheck' : 'shield'} className="w-[18px] h-[18px]" />
                  </span>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1.5">
                      <span className="text-[13.5px] font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{r.name}</span>
                      {r.system && <Badge tone="orange" className="!py-0 !px-2 !text-[10px]">نظام</Badge>}
                    </div>
                    <div className="flex items-center gap-3 mt-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                      <span className="flex items-center gap-1"><Icon name="users" className="w-3 h-3" />{uc} مستخدم</span>
                      <span className="flex items-center gap-1"><Icon name="key" className="w-3 h-3" />{r.perms.length} صلاحية</span>
                    </div>
                  </div>
                  <div className="w-9 shrink-0">
                    <svg viewBox="0 0 36 36" className="w-9 h-9 -rotate-90">
                      <circle cx="18" cy="18" r="14" fill="none" strokeWidth="3.5" style={{ stroke: 'var(--toggle-off-bg)' }} />
                      <circle cx="18" cy="18" r="14" fill="none" strokeWidth="3.5" strokeLinecap="round" stroke="#e0491f" strokeDasharray={`${(r.perms.length / total) * 88} 88`} />
                    </svg>
                  </div>
                </button>
              );
            })}
          </div>
        </div>

        {/* Detail */}
        <div className="lg:col-span-8 xl:col-span-8 space-y-4 stagger-item stagger-3">
          {!loading && !draft ? <EmptyState title="اختر دوراً لعرض صلاحياته" /> : loading || !draft ? (
            <div className="v-card p-5 space-y-4"><Skeleton className="h-11 rounded-2xl" /><Skeleton className="h-24 rounded-2xl" />{[1, 2, 3].map(i => <Skeleton key={i} className="h-16 rounded-2xl" />)}</div>
          ) : <>
            <div className="v-card p-4 sm:p-5">
              <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 sm:gap-4">
                <div className="flex-1 min-w-0">
                  <Field error={nameError} label="اسم الدور" required aside={isSystem && <span className="flex items-center gap-1 text-[11px] font-medium" style={{ color: 'var(--text-muted)' }}><Icon name="lock" className="w-3 h-3" />دور نظام مقفل</span>}>
                    <TextInput value={draft.name} onChange={v => setDraft(d => d ? { ...d, name: v } : null)} disabled={isSystem || draft.builtin || !editable} icon={isSystem ? 'lock' : 'shield'} placeholder="مثال: مشرف مبيعات" />
                  </Field>
                </div>
                {!draft.isNew && !draft.builtin && !isSystem && centralAccess && canAny(can, 'roles.delete', 'roles.manage') && (
                  <button type="button" onClick={() => sel && (setDeleteConflict(undefined), setDel(sel))} className="sm:mt-7 h-11 px-4 rounded-full text-[12.5px] font-semibold flex items-center justify-center gap-2 v-badge-red hover:opacity-85 active:scale-95 transition w-full sm:w-auto">
                    <Icon name="trash" className="w-4 h-4" />حذف الدور
                  </button>
                )}
              </div>

              {/* Coverage & User Link */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4 sm:mt-5">
                <div className="v-orange-card rounded-[18px] p-4 sm:col-span-2 relative overflow-hidden">
                  <div className="flex items-center justify-between">
                    <span className="text-[12.5px] font-medium text-white/90">تغطية الصلاحيات</span>
                    <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-white/20 tabular-nums">{pct}%</span>
                  </div>
                  <div className="font-display text-[24px] sm:text-[30px] font-bold mt-1 tabular-nums">{granted} <span className="text-[14px] sm:text-[16px] font-medium text-white/75">/ {total} صلاحية محددة</span></div>
                  <div className="mt-3 h-3.5 w-full rounded-full overflow-hidden flex items-center gap-1 p-0.5 bg-white/10">
                    <div className="h-full rounded-full bg-white transition-all duration-500" style={{ width: `${Math.max(pct, 2)}%` }} />
                    <div className="h-full flex-1 rounded-full rewards-hatched-track" />
                  </div>
                </div>
                <div className="v-subcard p-4 flex flex-col justify-between">
                  <div className="flex items-center justify-between">
                    <span className="text-[12.5px] font-medium" style={{ color: 'var(--text-secondary)' }}>المستخدمون بهذا الدور</span>
                    <span className="w-7 h-7 rounded-full flex items-center justify-center v-badge-orange"><Icon name="users" className="w-3.5 h-3.5" /></span>
                  </div>
                  <div className="font-display text-[28px] font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>
                    {draft.id ? usersCount(draft.id) : 0}
                  </div>
                  {draft.id && usersCount(draft.id) > 0 && onNavigate ? (
                    <button
                      type="button"
                      onClick={() => onNavigate('users', { role: draft.id })}
                      className="text-[11px] font-semibold text-start hover:underline flex items-center gap-1"
                      style={{ color: 'var(--accent-orange)' }}
                    >
                      عرض المستخدمين ({usersCount(draft.id)}) <Icon name="chevLeft" className="w-3 h-3" />
                    </button>
                  ) : (
                    <div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                      حساسة: {sensitiveGranted} صلاحية
                    </div>
                  )}
                </div>
              </div>

              {isSystem && <Notice tone="info" className="mt-4" icon="lock" title="دور النظام الأساسي">Super Admin يمتلك جميع الصلاحيات تلقائياً ولا يمكن تعديله أو حذفه.</Notice>}
              {grantsPermissions && !isSystem && editable && (
                <div ref={passwordField} className="mt-4">
                  <Field label="كلمة مرورك الحالية" required error={passwordError}>
                    <TextInput value={currentPassword} onChange={value => { setCurrentPassword(value); setPasswordError(undefined); }}
                      type="password" dir="ltr" placeholder="أدخل كلمة مرور حسابك لتأكيد منح الصلاحيات" error={passwordError} />
                  </Field>
                </div>
              )}
            </div>

            {permissionsQ.error?.status === 403 && <Notice tone="info">لا تملك صلاحية عرض قائمة الصلاحيات؛ صلاحيات الدور الحالي للقراءة فقط.</Notice>}
            {/* Search */}
            <div className="flex flex-col sm:flex-row sm:items-center gap-2.5">
              <div className="v-card !rounded-full px-4 h-11 flex items-center gap-2.5 w-full sm:flex-1">
                <Icon name="search" className="w-4 h-4 shrink-0" style={{ color: 'var(--text-muted)' }} />
                <input value={q} onChange={e => setQ(e.target.value)} placeholder="ابحث بالاسم أو بمفتاح الكود" className="bg-transparent outline-none text-[13px] w-full" style={{ color: 'var(--text-primary)' }} />
                {q && <button type="button" onClick={() => setQ('')} style={{ color: 'var(--text-muted)' }}><Icon name="close" className="w-3.5 h-3.5" strokeWidth={2.4} /></button>}
              </div>
              <GhostButton onClick={() => setOpenGroups(openGroups.size ? new Set() : new Set(permissionGroups.map(g => g.module)))} className="!h-11 v-card !bg-[var(--bg-card)] w-full sm:w-auto justify-center">
                {openGroups.size ? 'طي الكل' : 'توسيع الكل'}
              </GhostButton>
            </div>

            {/* Quick Module Filter Chips (great for many permission groups) */}
            {!ql && permissionGroups.length > 1 && (
              <div className="flex items-center gap-1.5 overflow-x-auto pb-1 -mx-1 px-1" style={{ scrollbarWidth: 'none' }}>
                <button
                  type="button"
                  onClick={() => setActiveModule(null)}
                  className="h-8 px-3 rounded-full text-[12px] font-semibold shrink-0 active:scale-95 transition"
                  style={!activeModule
                    ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }
                    : { background: 'var(--bg-card)', color: 'var(--text-secondary)', border: '1px solid var(--border-subtle)' }}
                >
                  الكل ({permissionGroups.length})
                </button>
                {permissionGroups.map(g => {
                  const active = activeModule === g.module;
                  const onCount = g.perms.filter(p => draft.perms.includes(p[0])).length;
                  return (
                    <button
                      type="button"
                      key={g.module}
                      onClick={() => {
                        if (active) {
                          setActiveModule(null);
                        } else {
                          setActiveModule(g.module);
                          setOpenGroups(prev => new Set([...prev, g.module]));
                        }
                      }}
                      className="h-8 px-3 rounded-full text-[12px] font-medium shrink-0 flex items-center gap-1.5 active:scale-95 transition"
                      style={active
                        ? { background: 'var(--accent-orange)', color: '#fff' }
                        : { background: 'var(--bg-card)', color: 'var(--text-secondary)', border: '1px solid var(--border-subtle)' }}
                    >
                      <span>{g.label}</span>
                      <span className="text-[10.5px] tabular-nums opacity-80">{onCount}/{g.perms.length}</span>
                    </button>
                  );
                })}
              </div>
            )}

            {/* Groups */}
            <div className="space-y-3">
              {groups.length === 0 && <div className="v-card"><EmptyState title="لا توجد صلاحيات مطابقة" text={`لم نجد نتائج لـ «${q}». جرّب كلمة أخرى أو مفتاح كود مختلف.`} action={<GhostButton onClick={() => setQ('')}>مسح البحث</GhostButton>} /></div>}
              {groups.map(g => {
                const keys = g.perms.map(p => p[0]);
                const on = keys.filter(k => draft.perms.includes(k)).length;
                const all = on === keys.length;
                const isOpen = ql || activeModule === g.module ? true : openGroups.has(g.module);
                return (
                  <div key={g.module} className="v-card overflow-hidden">
                    <div className="flex items-center gap-2.5 sm:gap-3 p-3.5 sm:p-4 cursor-pointer select-none active:scale-[0.995] transition-transform" onClick={() => toggleOpen(g.module)}>
                      <span className={`w-9 h-9 sm:w-10 sm:h-10 rounded-xl flex items-center justify-center shrink-0 ${on ? 'v-badge-orange' : 'v-icon-well'}`}><Icon name={g.icon} className="w-[18px] h-[18px]" /></span>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <span className="text-[13.5px] sm:text-[14px] font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{g.label}</span>
                          <span className="sm:hidden text-[10.5px] font-semibold px-1.5 py-0.5 rounded-full v-subcard tabular-nums shrink-0" style={{ color: on ? 'var(--accent-orange)' : 'var(--text-muted)' }}>{on}/{keys.length}</span>
                        </div>
                        <div className="text-[11px] mt-0.5 font-mono truncate" dir="ltr" style={{ color: 'var(--text-muted)', textAlign: 'right' }}>{g.keys}</div>
                      </div>
                      <div className="hidden sm:flex items-center gap-2 w-[120px]">
                        <div className="flex-1 h-1.5 rounded-full overflow-hidden" style={{ background: 'var(--toggle-off-bg)' }}>
                          <div className="h-full rounded-full transition-all duration-300" style={{ width: `${(on / keys.length) * 100}%`, background: 'var(--accent-orange)' }} />
                        </div>
                        <span className="text-[11.5px] font-semibold tabular-nums" style={{ color: 'var(--text-secondary)' }}>{on}/{keys.length}</span>
                      </div>
                      <div onClick={e => e.stopPropagation()} className={`flex items-center gap-2 ps-2.5 sm:ps-3 shrink-0 ${isSystem ? 'opacity-50' : ''}`} style={{ borderInlineStart: '1px solid var(--divider-color)' }}>
                        <span className="text-[11.5px] font-medium hidden md:inline" style={{ color: 'var(--text-secondary)' }}>تحديد الكل</span>
                        <Toggle size="sm" on={!!isSystem || all} onChange={v => toggleGroup(g, v)} disabled={isSystem || !editable} />
                      </div>
                      <Icon name="chevDown" className={`w-4 h-4 shrink-0 transition-transform duration-300 ${isOpen ? 'rotate-180' : ''}`} style={{ color: 'var(--text-muted)' }} />
                    </div>
                    <div className="v-collapse" style={{ gridTemplateRows: isOpen ? '1fr' : '0fr' }}>
                      <div className="overflow-hidden">
                        <div className="px-3.5 sm:px-4 pb-3.5 sm:pb-4 grid grid-cols-1 md:grid-cols-2 gap-2">
                          {g.visible.map(([k, label, sensitive]) => {
                            const checked = !!isSystem || draft.perms.includes(k);
                            const changed = !draft.isNew && sel && checked !== sel.perms.includes(k);
                            return (
                              <div title={!iAmSuper && !myPerms.has(k) ? 'لا تملك هذه الصلاحية لمنحها' : undefined} key={k} onClick={() => togglePerm(k)}
                                className={`v-perm rounded-2xl px-3.5 py-3 flex items-center gap-3 active:scale-[0.985] transition-transform ${checked ? 'is-on' : ''} ${isSystem ? 'cursor-default' : 'cursor-pointer'}`}>
                                <Checkbox checked={checked} disabled={isSystem || !editable || (!iAmSuper && !myPerms.has(k))} />
                                <div className="flex-1 min-w-0">
                                  <div className="text-[12.5px] font-semibold flex items-center gap-1.5 flex-wrap" style={{ color: 'var(--text-primary)' }}>
                                    <span className="break-words">{label}</span>
                                    {changed && <span className="w-1.5 h-1.5 rounded-full shrink-0" style={{ background: 'var(--accent-orange)' }} title="تعديل غير محفوظ" />}
                                  </div>
                                  <div className="text-[10.5px] font-mono mt-0.5 truncate" dir="ltr" style={{ color: 'var(--text-muted)', textAlign: 'right' }}>{k}</div>
                                </div>
                                {sensitive && <Badge tone="orange" className="!text-[10px] !px-2 shrink-0"><Icon name="alert" className="w-3 h-3" />حساسة</Badge>}
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </>}
        </div>
      </div>

      <StickyBar show={!!isDirty && !isSystem && editable} onSave={save} onReset={reset} saving={saving}
        message={draft?.isNew ? 'دور جديد غير محفوظ' : 'تغييرات غير محفوظة على الدور'} count={changeCount} />

      <Drawer open={rolesSheetOpen} onClose={() => setRolesSheetOpen(false)}>
        <div className="px-5 pt-3 pb-3.5 v-border-b flex items-center justify-between gap-3">
          <div>
            <h3 className="font-display text-[17px] font-semibold" style={{ color: 'var(--text-primary)' }}>كل الأدوار ({roles.length})</h3>
            <p className="text-[12px]" style={{ color: 'var(--text-muted)' }}>ابحث أو اختر دوراً لتعديل صلاحياته</p>
          </div>
          <button type="button" onClick={() => setRolesSheetOpen(false)} className="icon-btn">
            <Icon name="close" className="w-4 h-4" />
          </button>
        </div>
        <div className="p-4 pb-2">
          <div className="v-subcard px-3.5 h-10 rounded-full flex items-center gap-2">
            <Icon name="search" className="w-3.5 h-3.5 shrink-0" style={{ color: 'var(--text-muted)' }} />
            <input value={roleQ} onChange={e => setRoleQ(e.target.value)} placeholder="بحث في الأدوار..." className="bg-transparent text-[13px] outline-none w-full" style={{ color: 'var(--text-primary)' }} />
            {roleQ && <button type="button" onClick={() => setRoleQ('')} style={{ color: 'var(--text-muted)' }}><Icon name="close" className="w-3.5 h-3.5" /></button>}
          </div>
        </div>
        <div className="flex-1 overflow-y-auto overscroll-contain p-4 pt-2 space-y-2">
          {shownRoles.length === 0 && (
            <EmptyState title="لا توجد أدوار مطابقة" text={`لم نجد دوراً يطابق «${roleQ}».`} />
          )}
          {shownRoles.map(r => {
            const active = r.id === selId && !draft?.isNew;
            const uc = usersCount(r.id);
            return (
              <button
                type="button"
                key={r.id}
                onClick={() => {
                  if (r.id !== selId && isDirty && !window.confirm('لديك تعديلات غير محفوظة. هل تريد تجاهلها؟')) return;
                  setSelId(r.id);
                  setRolesSheetOpen(false);
                }}
                className={`w-full rounded-2xl p-3.5 flex items-center gap-3 text-start active:scale-[0.985] transition ${active ? 'v-role-active ring-2 ring-[var(--accent-orange)]' : 'v-role-item'}`}
              >
                <span className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${r.system ? 'text-white v-accent-btn' : 'v-role-icon'}`}>
                  <Icon name={r.system ? 'shieldCheck' : 'shield'} className="w-[18px] h-[18px]" />
                </span>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-1.5">
                    <span className="text-[13.5px] font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{r.name}</span>
                    {r.system && <Badge tone="orange" className="!py-0 !px-2 !text-[10px]">نظام</Badge>}
                  </div>
                  <div className="flex items-center gap-3 mt-1 text-[11px] tabular-nums" style={{ color: 'var(--text-muted)' }}>
                    <span>{uc} مستخدم</span>
                    <span>{r.perms.length} صلاحية</span>
                  </div>
                </div>
                {active && <Icon name="check" className="w-4 h-4 shrink-0" style={{ color: 'var(--accent-orange)' }} />}
              </button>
            );
          })}
        </div>
      </Drawer>

      <Modal open={!!del} onClose={() => setDel(null)}>
        {del && (() => {
          const uc = usersCount(del.id);
          const blocked = !!uc || !!deleteConflict;
          return <>
            <div className={`w-12 h-12 rounded-2xl flex items-center justify-center mb-4 ${uc ? 'v-badge-orange' : 'v-badge-red'}`}><Icon name={uc ? 'alert' : 'trash'} className="w-5 h-5" strokeWidth={2.2} /></div>
            <h3 className="font-display text-[18px] font-semibold" style={{ color: 'var(--text-primary)' }}>{blocked ? 'لا يمكن حذف هذا الدور' : `حذف دور «${del.name}»؟`}</h3>
            <p className="text-[13px] mt-2 leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
              {deleteConflict ? deleteConflict : uc ? <>الدور مُسند حالياً إلى <b style={{ color: 'var(--text-primary)' }}>{uc} مستخدم</b>. أزل الدور من المستخدمين أولاً ثم أعد المحاولة.</> : 'سيتم حذف الدور وجميع صلاحياته نهائياً. لا يمكن التراجع عن هذا الإجراء.'}
            </p>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 mt-5">
              <GhostButton onClick={() => setDel(null)} className={blocked ? 'sm:col-span-2' : ''}>{blocked ? 'حسناً' : 'إلغاء'}</GhostButton>
              {!blocked && <button type="button" onClick={() => doDelete(del)} className="h-10 rounded-full text-[13px] font-semibold text-white" style={{ background: 'var(--badge-red-text)' }}>حذف نهائي</button>}
            </div>
          </>;
        })()}
      </Modal>
    </section>
  );
}

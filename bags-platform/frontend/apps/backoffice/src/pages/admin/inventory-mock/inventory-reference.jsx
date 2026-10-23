import React, {useState,useEffect,useRef,useMemo,useContext,createContext,Fragment} from 'react';
import { useApi } from '../catalog/useApi';
import { normalizeError } from '../catalog/errors';
import { adjustmentsApi, branchesApi, locationsApi, movementsApi, productionApi, stockApi, stocktakesApi, transfersApi } from '@alfarid/api-client';
import { useAuthStore } from '../../../stores/auth.store';
import { formatCurrency, formatDate, hasPermission } from '@alfarid/utils';
import { ADJ_MOCK_TO_API, BRANCH_TYPES, LOCATION_TYPES, MOVE_TYPE_LABELS, STOCKTAKE_STATUS, TRANSFER_STATUS } from './inventory/labels';
import { canAny, formatQty, groupItemsErrors, pickName, QTY_MAX, QTY_RE, toLatinDigits } from './inventory/helpers';
import { apiPhase, fetchAllBranches, fetchAllLocations } from './inventory/api';
import { useIdempotencyKey } from './inventory/useIdempotencyKey';
const INV_NAV = [
  { id: 'branches',  label: 'الفروع والمخازن', icon: 'store',     match: ['branches', 'locations'] },
  { id: 'stock',     label: 'الأرصدة',         icon: 'box',       match: ['stock'] },
  { id: 'adjust',    label: 'التسوية',         icon: 'sliders',   match: ['adjust'] },
  { id: 'moves',     label: 'الحركات',         icon: 'history',   match: ['moves'] },
  { id: 'transfers', label: 'التحويلات',       icon: 'swap',      match: ['transfers', 'transfer', 'transfer-edit'] },
  { id: 'counts',    label: 'الجرد',           icon: 'clipboard', match: ['counts', 'count'] },
  { id: 'production',label: 'استلام الإنتاج',  icon: 'factory',   match: ['production'] },
];


// ============ 1. Branches & Warehouses  ·  2. Storage Locations ============

const TypeBadge = ({ type }) => type === 'warehouse'
  ? <Badge cls="b-dark"><Icon n="warehouse" s={11} w={2.2} /> مخزن</Badge>
  : <Badge cls="b-gray"><Icon n="store" s={11} w={2.2} /> فرع</Badge>;

function BranchForm({ open, initial, onClose, onSave }) {
  const blank = { name: '', code: '', type: 'branch', phone: '', email: '', address: '', city: '', active: true };
  const [f, setF] = useState(blank);
  const [err, setErr] = useState({});
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (open) {
    setF(initial ? { id: initial.id, name: initial.name || '', code: initial.code || '', type: initial.type || 'branch', phone: initial.phone || '', email: initial.email || '', address: initial.address || '', city: initial.city || '', active: initial.is_active } : blank);
    setErr({}); setBusy(false);
  } }, [open]);
  const set = (k, v) => setF(p => ({ ...p, [k]: v }));
  const submit = async () => {
    const e = {};
    if (!f.name.trim()) e.name = 'الاسم مطلوب';
    if (!f.code.trim()) e.code = 'الكود مطلوب';
    if (f.email.trim() && !/^\S+@\S+\.\S+$/.test(f.email.trim())) e.email = 'صيغة البريد غير صحيحة';
    // The API leaves city optional (StoreBranchRequest: nullable) — the UI stays
    // optional too on purpose instead of forcing a 422-prone required field.
    setErr(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    const res = await onSave(f);
    setBusy(false);
    if (res && (res.fields || res.message)) setErr({ ...res.fields, ...(res.message ? { _form: res.message } : {}) });
  };
  return (
    <Modal open={open} onClose={onClose} width="max-w-2xl"
      title={initial ? 'تعديل ' + (initial.type === 'warehouse' ? 'المخزن' : 'الفرع') : 'إضافة فرع أو مخزن'}
      sub="المخزن يُسجَّل كنوع من الفروع ويملك مواقع تخزين خاصة به."
      footer={<>
        <button className="btn btn-dark" onClick={submit} disabled={busy}>
          {busy && <span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" />}
          {initial ? 'حفظ التعديلات' : 'إضافة'}
        </button>
        <button className="btn btn-soft" onClick={onClose}>إلغاء</button>
      </>}>
      <div className="space-y-4">
        {err._form && <InlineAlert kind="red">{err._form}</InlineAlert>}
        <div>
          <span className="field-label">النوع</span>
          <div className="grid grid-cols-2 gap-2.5">
            {[['branch', 'فرع', 'store', 'نقطة بيع تستقبل العملاء'], ['warehouse', 'مخزن', 'warehouse', 'تخزين وتوزيع فقط']].map(([k, l, ic, d]) => (
              <button key={k} type="button" onClick={() => set('type', k)}
                className={`rounded-2xl p-3.5 flex items-center gap-3 text-right transition border ${f.type === k ? 'border-[var(--accent-orange)]' : 'border-transparent'}`}
                style={{ background: f.type === k ? 'var(--accent-orange-light)' : 'var(--bg-subcard)' }}>
                <span className={`w-9 h-9 rounded-full flex items-center justify-center ${f.type === k ? 'bg-[var(--accent-orange)] text-white' : 'v-icon-well'}`}><Icon n={ic} s={16} /></span>
                <span><span className="block text-[13px] font-semibold c-primary">{l}</span><span className="block text-[11px] c-secondary">{d}</span></span>
              </button>
            ))}
          </div>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label="الاسم" req error={err.name}><input className={`inp ${err.name ? 'err' : ''}`} value={f.name} onChange={e => set('name', e.target.value)} placeholder="مثال: فرع مدينة نصر" /></Field>
          <Field label="الكود" req error={err.code} hint="فريد على مستوى النظام"><input dir="ltr" maxLength={50} className={`inp mono text-left ${err.code ? 'err' : ''}`} value={f.code} onChange={e => set('code', e.target.value.toUpperCase())} placeholder="BR-XXX" /></Field>
          <Field label="الهاتف" error={err.phone}><input dir="ltr" className="inp num text-left" value={f.phone} onChange={e => set('phone', e.target.value)} placeholder="01xxxxxxxxx" /></Field>
          <Field label="البريد الإلكتروني" error={err.email}><input dir="ltr" className={`inp mono text-left ${err.email ? 'err' : ''}`} value={f.email} onChange={e => set('email', e.target.value)} placeholder="branch@company.com" /></Field>
          <Field label="المدينة" error={err.city}><input className={`inp ${err.city ? 'err' : ''}`} value={f.city} onChange={e => set('city', e.target.value)} maxLength={100} /></Field>
          <Field label="العنوان" error={err.address}><input className="inp" value={f.address} onChange={e => set('address', e.target.value)} /></Field>
        </div>
        <div className="v-subcard p-3.5 flex items-center justify-between">
          <div>
            <div className="text-[13px] font-semibold c-primary">الحالة: {f.active ? 'نشط' : 'غير نشط'}</div>
            <div className="text-[11px] c-secondary">الفروع غير النشطة لا تظهر في عمليات التحويل والتسوية.</div>
          </div>
          <Toggle on={f.active} onChange={v => set('active', v)} />
        </div>
      </div>
    </Modal>
  );
}

function BranchesScreen({ go }) {
  const { toast, can, branchesQuery, reloadBranches } = useInv();
  const [q, setQ] = useState('');
  const [type, setType] = useState('all');
  const [form, setForm] = useState({ open: false, initial: null });
  const [del, setDel] = useState(null);
  const [delBusy, setDelBusy] = useState(false);
  const [delErr, setDelErr] = useState(null);
  const restriction = useRestrictedBranch();

  if (!canAny(can, 'branches.view', 'branches.manage')) return <NoPerm title="الفروع والمخازن" perm="branches.view" go={go} />;

  const list = (branchesQuery.data || []).filter(b => !restriction.locked || b.id === restriction.branchId);
  const { phase, error } = apiPhase(branchesQuery, list.length === 0);
  const rows = list.filter(b => (type === 'all' || b.type === type) && (!q || b.name.includes(q) || (b.code || '').toLowerCase().includes(q.toLowerCase()) || (b.city || '').includes(q)));
  const canAdd = canAny(can, 'branches.create', 'branches.manage');
  const canEdit = canAny(can, 'branches.update', 'branches.manage');
  const canDelete = canAny(can, 'branches.delete', 'branches.manage');

  const payload = f => {
    const p = { name: f.name.trim(), code: f.code.trim(), type: f.type, is_active: !!f.active };
    if (f.phone.trim()) p.phone = f.phone.trim();
    if (f.email.trim()) p.email = f.email.trim();
    if (f.address.trim()) p.address = f.address.trim();
    if (f.city.trim()) p.city = f.city.trim();
    return p;
  };
  const save = async f => {
    try {
      if (f.id) {
        const cur = list.find(b => b.id === f.id) || {};
        const next = payload(f);
        const diff = {};
        ['name', 'code', 'type', 'is_active', 'phone', 'email', 'address', 'city'].forEach(k => {
          if ((next[k] ?? null) !== (cur[k] ?? null)) diff[k] = next[k] ?? null;
        });
        // PUT sends only changed fields, so an untouched unique code never re-triggers 422.
        if (Object.keys(diff).length) await branchesApi.update(f.id, diff);
      } else {
        await branchesApi.create(payload(f));
      }
    } catch (e) {
      const ne = normalizeError(e);
      return { fields: ne.fields, message: ne.message };
    }
    setForm({ open: false, initial: null });
    reloadBranches();
    toast(f.id ? 'تم حفظ التعديلات' : 'تمت إضافة ' + (f.type === 'warehouse' ? 'المخزن' : 'الفرع'));
    return null;
  };
  const doDelete = async () => {
    setDelBusy(true); setDelErr(null);
    try {
      await branchesApi.remove(del.id);
      setDel(null); reloadBranches(); toast('تم الحذف');
    } catch (e) {
      setDelErr(normalizeError(e).message);
    } finally { setDelBusy(false); }
  };
  const disableInstead = async () => {
    setDelBusy(true);
    try {
      await branchesApi.update(del.id, { is_active: false });
      setDel(null); setDelErr(null); reloadBranches(); toast('تم تعطيل «' + del.name + '» بدلًا من الحذف');
    } catch (e) {
      setDelErr(normalizeError(e).message);
    } finally { setDelBusy(false); }
  };

  const counts = { all: list.length, branch: list.filter(b => b.type === 'branch').length, warehouse: list.filter(b => b.type === 'warehouse').length };

  return (
    <section className="space-y-5">
      <PageHead title="الفروع والمخازن" sub="سجل نقاط البيع والمخازن ومواقع التخزين التابعة لكل منها."
        actions={canAdd && <button className="btn btn-dark" onClick={() => setForm({ open: true, initial: null })}><Icon n="plus" s={14} w={2.2} /> إضافة فرع / مخزن</button>} />

      <Card>
        <CardHead title="القائمة" meta={phase === 'ready' ? `${rows.length} من ${list.length} · ${list.filter(b => b.is_active).length} نشط` : null}
          right={<>
            <div className="seg">
              {[['all', 'الكل'], ['branch', 'فروع'], ['warehouse', 'مخازن']].map(([k, l]) => (
                <button key={k} className={type === k ? 'on' : ''} onClick={() => setType(k)}>{l} <span className="num c-muted mr-1">{counts[k]}</span></button>
              ))}
            </div>
            <SearchPill value={q} onChange={setQ} placeholder="الاسم، الكود، المدينة" />
          </>} />
        <Phase phase={phase} cols={8} error={error} onRetry={reloadBranches}
          empty={<EmptyState icon="store" title="لا توجد فروع أو مخازن بعد" sub="ابدأ بإضافة المخزن الرئيسي، ثم أضف مواقع التخزين بداخله."
            action={canAdd && <button className="btn btn-dark btn-sm" onClick={() => setForm({ open: true, initial: null })}><Icon n="plus" s={13} /> إضافة أول فرع</button>} />}>
          {rows.length === 0 ? <EmptyState icon="search" title="لا توجد نتائج" sub="جرّب كلمة بحث أخرى أو غيّر نوع الفلتر." /> : (
            <div className="overflow-x-auto">
              <table className="vt">
                <thead><tr>
                  <th>الاسم</th><th>الكود</th><th>النوع</th><th>المدينة</th><th>الهاتف</th><th>الحالة</th><th>مواقع التخزين</th><th>الموظفون</th><th></th>
                </tr></thead>
                <tbody>
                  {rows.map(b => (
                    <tr key={b.id}>
                      <td>
                        <div className="flex items-center gap-2.5">
                          <span className={`w-8 h-8 rounded-full flex items-center justify-center shrink-0 ${b.type === 'warehouse' ? '' : 'v-icon-well'}`} style={b.type === 'warehouse' ? { background: 'var(--accent-orange-light)', color: 'var(--accent-orange)' } : {}}>
                            <Icon n={b.type === 'warehouse' ? 'warehouse' : 'store'} s={14} />
                          </span>
                          <div><div className="font-semibold c-primary">{b.name}</div><div className="text-[11px] c-muted mono">{b.email || '—'}</div></div>
                        </div>
                      </td>
                      <td><Sku>{b.code}</Sku></td>
                      <td><TypeBadge type={b.type} /></td>
                      <td className="c-secondary">{b.city || '—'}</td>
                      <td className="num c-secondary" dir="ltr">{b.phone || '—'}</td>
                      <td><ActiveBadge on={b.is_active} /></td>
                      <td>
                        <button onClick={() => go('locations', { branchId: b.id })} className="flex items-center gap-2 group">
                          <span className="num font-semibold c-primary">{b.locations_count ?? '—'}</span>
                          <span className="text-[11.5px] c-orange font-semibold opacity-0 group-hover:opacity-100 transition">فتح ←</span>
                        </button>
                      </td>
                      <td className="num c-secondary">{b.employees_count ?? '—'}</td>
                      <td>
                        <div className="flex items-center gap-0.5 justify-end">
                          <button className="icon-btn" title="مواقع التخزين" onClick={() => go('locations', { branchId: b.id })}><Icon n="pin" s={15} /></button>
                          {canEdit && <button className="icon-btn" title="تعديل" onClick={() => setForm({ open: true, initial: b })}><Icon n="edit" s={15} /></button>}
                          {canDelete && <button className="icon-btn hover:!text-[var(--badge-red-text)]" title="حذف" onClick={() => { setDel(b); setDelErr(null); }}><Icon n="trash" s={15} /></button>}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Phase>
      </Card>

      <BranchForm open={form.open} initial={form.initial} onClose={() => setForm({ open: false, initial: null })} onSave={save} />
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} onConfirm={doDelete} danger busy={delBusy} error={delErr}
        title={`حذف ${del?.type === 'warehouse' ? 'المخزن' : 'الفرع'}؟`} confirmLabel="حذف نهائي"
        body={<>سيتم حذف <b className="c-primary">{del?.name}</b> (<span className="mono">{del?.code}</span>). لا يمكن التراجع عن هذا الإجراء، وسيرفض الخادم الحذف إذا كانت هناك مواقع تخزين أو أرصدة أو حركات مرتبطة.
          {delErr && canEdit && <div className="mt-3"><button type="button" className="btn btn-soft btn-sm" disabled={delBusy} onClick={disableInstead}>تعطيل بدلًا من الحذف</button></div>}</>} />
    </section>
  );
}

// ---------------- Storage locations ----------------
function LocationForm({ open, initial, branchId, branchName, onClose, onSave }) {
  const blank = { name: '', code: '', type: 'shelf', active: true, branchId };
  const [f, setF] = useState(blank);
  const [err, setErr] = useState({});
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (open) { setF(initial ? { id: initial.id, name: initial.name || '', code: initial.code || '', type: initial.type || 'shelf', active: initial.is_active, branchId } : { ...blank, branchId }); setErr({}); setBusy(false); } }, [open]);
  const set = (k, v) => setF(p => ({ ...p, [k]: v }));
  const submit = async () => {
    const e = {};
    if (!f.name.trim()) e.name = 'الاسم مطلوب';
    if (!f.code.trim()) e.code = 'الكود مطلوب';
    // Code uniqueness inside the branch is enforced by the server (422 under code).
    setErr(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    const res = await onSave(f);
    setBusy(false);
    if (res && (res.fields || res.message)) setErr({ ...res.fields, ...(res.message ? { _form: res.message } : {}) });
  };
  return (
    <Modal open={open} onClose={onClose} title={initial ? 'تعديل موقع التخزين' : 'إضافة موقع تخزين'} sub={`تابع لـ ${branchName || ''}`}
      footer={<><button className="btn btn-dark" onClick={submit} disabled={busy}>{initial ? 'حفظ' : 'إضافة'}</button><button className="btn btn-soft" onClick={onClose}>إلغاء</button></>}>
      <div className="space-y-4">
        {err._form && <InlineAlert kind="red">{err._form}</InlineAlert>}
        <div className="grid grid-cols-2 gap-4">
          <Field label="الاسم" req error={err.name}><input className={`inp ${err.name ? 'err' : ''}`} value={f.name} onChange={e => set('name', e.target.value)} placeholder="رف A-01" /></Field>
          <Field label="الكود" req error={err.code} hint="فريد داخل الفرع"><input dir="ltr" className={`inp mono text-left ${err.code ? 'err' : ''}`} value={f.code} onChange={e => set('code', e.target.value.toUpperCase())} placeholder="A-01" maxLength={100} /></Field>
        </div>
        <div>
          <span className="field-label">النوع</span>
          <div className="grid grid-cols-5 gap-2">
            {Object.entries(LOCATION_TYPES).map(([k, t]) => (
              <button key={k} type="button" onClick={() => set('type', k)}
                className={`rounded-xl py-2.5 text-[12px] font-medium transition border ${f.type === k ? 'border-[var(--accent-orange)] c-primary font-semibold' : 'border-transparent c-secondary'}`}
                style={{ background: f.type === k ? 'var(--accent-orange-light)' : 'var(--bg-subcard)' }}>{t}</button>
            ))}
          </div>
        </div>
        <div className="v-subcard p-3.5 flex items-center justify-between">
          <span className="text-[13px] font-semibold c-primary">{f.active ? 'نشط' : 'معطّل'}</span>
          <Toggle on={f.active} onChange={v => set('active', v)} />
        </div>
      </div>
    </Modal>
  );
}

function LocationsScreen({ go, params }) {
  const { toast, can, branchesQuery, reloadBranches } = useInv();
  const branchId = Number(params.branchId);
  const [form, setForm] = useState({ open: false, initial: null });
  const [del, setDel] = useState(null);
  const [delBusy, setDelBusy] = useState(false);
  const [delErr, setDelErr] = useState(null);
  const [tf, setTf] = useState('all');

  const shared = branchesQuery.data || [];
  const br = shared.find(b => b.id === branchId);
  const sharedPhase = apiPhase(branchesQuery, shared.length === 0);
  const locQuery = useApi(signal => (br ? fetchAllLocations(br.id, signal) : Promise.resolve([])), [br ? br.id : 0]);
  const locPhase = apiPhase(locQuery, (locQuery.data || []).length === 0);

  const restriction = useRestrictedBranch();
  if (!canAny(can, 'locations.view', 'locations.manage')) return <NoPerm title="مواقع التخزين" perm="locations.view" go={go} />;
  if (restriction.locked && branchId !== Number(restriction.branchId)) return <NoPerm title="مواقع التخزين" perm="inventory.all_branches" go={go} />;
  const branchOptions = (restriction.locked ? shared.filter(b => b.id === restriction.branchId) : shared.filter(b => b.is_active))
    .map(b => ({ value: b.id, label: `${b.name} · ${b.type === 'warehouse' ? 'مخزن' : 'فرع'}` }));

  const phase = sharedPhase.phase === 'ready' ? locPhase.phase : sharedPhase.phase;
  const error = sharedPhase.phase === 'ready' ? locPhase.error : sharedPhase.error;
  const onRetry = () => { reloadBranches(); locQuery.reload(); };
  const onBack = () => go('branches');

  if (sharedPhase.phase === 'ready' && !br) {
    return (
      <section className="space-y-5">
        <PageHead title="مواقع التخزين" back={{ label: 'الفروع والمخازن', onClick: onBack }} />
        <Card><EmptyState icon="pin" title="الفرع غير موجود" sub="ربما تم حذفه. اختر فرعًا آخر من القائمة."
          action={<button className="btn btn-dark btn-sm" onClick={onBack}>رجوع لقائمة الفروع</button>} /></Card>
      </section>
    );
  }

  const all = locQuery.data || [];
  const rows = all.filter(l => tf === 'all' || l.type === tf);
  const canAdd = canAny(can, 'locations.create', 'locations.manage');
  const canEdit = canAny(can, 'locations.update', 'locations.manage');
  const canDelete = canAny(can, 'locations.delete', 'locations.manage');

  const payload = f => ({ name: f.name.trim(), code: f.code.trim(), type: f.type, is_active: !!f.active });
  const save = async f => {
    try {
      if (f.id) {
        const cur = all.find(l => l.id === f.id) || {};
        const next = payload(f);
        const diff = {};
        ['name', 'code', 'type', 'is_active'].forEach(k => { if ((next[k] ?? null) !== (cur[k] ?? null)) diff[k] = next[k] ?? null; });
        if (Object.keys(diff).length) await locationsApi.update(br.id, f.id, diff);
      } else {
        await locationsApi.create(br.id, payload(f));
      }
    } catch (e) {
      const ne = normalizeError(e);
      return { fields: ne.fields, message: ne.message };
    }
    setForm({ open: false, initial: null });
    reloadBranches(); locQuery.reload(); toast('تم حفظ موقع التخزين');
    return null;
  };
  const toggle = async l => {
    try {
      await locationsApi.update(br.id, l.id, { is_active: !l.is_active });
      reloadBranches(); locQuery.reload(); toast(l.is_active ? 'تم تعطيل الموقع' : 'تم تفعيل الموقع');
    } catch (e) { toast(normalizeError(e).message); }
  };
  const doDelete = async () => {
    setDelBusy(true); setDelErr(null);
    try {
      await locationsApi.remove(br.id, del.id);
      setDel(null); reloadBranches(); locQuery.reload(); toast('تم حذف الموقع');
    } catch (e) {
      setDelErr(normalizeError(e).message);
    } finally { setDelBusy(false); }
  };

  const typeCounts = Object.keys(LOCATION_TYPES).reduce((a, k) => ({ ...a, [k]: all.filter(l => l.type === k).length }), {});

  return (
    <section className="space-y-5">
      <PageHead title="مواقع التخزين" back={{ label: 'الفروع والمخازن', onClick: onBack }}
        sub={br && <span className="flex items-center gap-2">{br.name} <span className="mono text-[11.5px] c-muted" dir="ltr">{br.code}</span> <TypeBadge type={br.type} /></span>}
        actions={<>
          <Sel value={br?.id} onChange={id => id && go('locations', { branchId: id })} options={branchOptions} placeholder="اختر الفرع" className="!rounded-full !py-2 w-56" disabled={restriction.locked} />
          {canAdd && <button className="btn btn-dark" onClick={() => setForm({ open: true, initial: null })}><Icon n="plus" s={14} w={2.2} /> إضافة موقع</button>}
        </>} />

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <Card className="lg:col-span-4" stagger={2}>
          <div className="v-orange-card rounded-[18px] p-5">
            <div className="flex items-center justify-between">
              <span className="text-[12.5px] font-medium text-white/90">إجمالي المواقع</span>
              <span className="w-7 h-7 rounded-full bg-white/15 flex items-center justify-center"><Icon n="pin" s={14} /></span>
            </div>
            <div className="font-display text-[40px] font-bold num text-right mt-2">{phase === 'ready' ? all.length : '—'}</div>
            <div className="text-[11.5px] text-white/80">{all.filter(l => l.is_active).length} نشط · {all.filter(l => !l.is_active).length} معطّل</div>
          </div>
          <div className="mt-4 space-y-1">
            {Object.entries(LOCATION_TYPES).map(([k, t]) => (
              <button key={k} onClick={() => setTf(tf === k ? 'all' : k)} className={`w-full flex items-center justify-between px-3 py-2.5 rounded-xl transition ${tf === k ? 'bg-[var(--bg-subcard)]' : 'hover:bg-[var(--bg-subcard)]'}`}>
                <span className="flex items-center gap-2.5 text-[12.5px] c-primary"><Badge cls={({ shelf: 'b-gray', zone: 'b-blue', bin: 'b-gray', damaged: 'b-red', receiving: 'b-amber' })[k]}>{t}</Badge></span>
                <span className="flex items-center gap-3">
                  <span className="w-20 h-1.5 rounded-full hatched overflow-hidden"><span className="block h-full bg-[var(--accent-orange)] rounded-full" style={{ width: `${all.length ? typeCounts[k] / all.length * 100 : 0}%` }} /></span>
                  <span className="num text-[12px] font-semibold c-primary w-4 text-left">{typeCounts[k]}</span>
                </span>
              </button>
            ))}
          </div>
        </Card>

        <Card className="lg:col-span-8" stagger={3}>
          <CardHead title="المواقع" meta={tf === 'all' ? 'كل الأنواع' : LOCATION_TYPES[tf]}
            right={tf !== 'all' && <button className="btn btn-ghost btn-sm" onClick={() => setTf('all')}><Icon n="x" s={12} /> إزالة الفلتر</button>} />
          <Phase phase={phase} cols={5} rows={5} error={error} onRetry={onRetry} onBack={onBack}
            empty={<EmptyState icon="pin" title="لا توجد مواقع تخزين في هذا الفرع" sub="أضف رفوفًا ومناطق وخانات لتحديد مكان كل صنف بدقة."
              action={canAdd && <button className="btn btn-dark btn-sm" onClick={() => setForm({ open: true, initial: null })}><Icon n="plus" s={13} /> إضافة موقع</button>} />}>
            {rows.length === 0 ? <EmptyState icon="pin" title="لا توجد مواقع من هذا النوع" /> : (
              <table className="vt">
                <thead><tr><th>الاسم</th><th>الكود</th><th>النوع</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                  {rows.map(l => (
                    <tr key={l.id} className={l.is_active ? '' : 'opacity-60'}>
                      <td className="font-semibold c-primary">{l.name}</td>
                      <td><Sku>{l.code}</Sku></td>
                      <td><Badge cls={({ shelf: 'b-gray', zone: 'b-blue', bin: 'b-gray', damaged: 'b-red', receiving: 'b-amber' })[l.type] || 'b-gray'}>{LOCATION_TYPES[l.type] || l.type}</Badge></td>
                      <td>
                        <div className="flex items-center gap-2.5">
                          {canEdit && <Toggle on={l.is_active} onChange={() => toggle(l)} />}
                          <span className={`text-[11.5px] font-semibold ${l.is_active ? '' : 'c-muted'}`} style={l.is_active ? { color: 'var(--badge-green-text)' } : {}}>{l.is_active ? 'نشط' : 'معطّل'}</span>
                        </div>
                      </td>
                      <td>
                        {(canEdit || canDelete) && (
                          <div className="flex items-center gap-0.5 justify-end">
                            {canEdit && <button className="icon-btn" title="تعديل" onClick={() => setForm({ open: true, initial: l })}><Icon n="edit" s={15} /></button>}
                            {canDelete && <button className="icon-btn" title="حذف" onClick={() => { setDel(l); setDelErr(null); }}><Icon n="trash" s={15} /></button>}
                          </div>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Phase>
        </Card>
      </div>

      <LocationForm open={form.open} initial={form.initial} branchId={br?.id} branchName={br?.name} onClose={() => setForm({ open: false, initial: null })} onSave={save} />
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} onConfirm={doDelete} danger busy={delBusy} error={delErr} title="حذف موقع التخزين؟" confirmLabel="حذف"
        body={<>سيتم حذف <b className="c-primary">{del?.name}</b> (<span className="mono">{del?.code}</span>) من {br?.name}.</>} />
    </section>
  );
}

// ============ 7. Stocktake · 8. Production receipt ============


function CountsScreen({ go }) {
  const { can, branchesQuery } = useInv();
  const restriction = useRestrictedBranch();
  const [create, setCreate] = useState(false);
  const [branchF, setBranchF] = useState(null);
  const [st, setSt] = useState('all');
  const allowed = canAny(can, 'stocktakes.view', 'stocktakes.manage');

  // Small cardinality like branches: fetch all pages so filters run client-side.
  const query = useApi(signal => (async () => {
    const all = []; let page = 1; let last = 1;
    do {
      const res = await stocktakesApi.list({ per_page: 100, page, branch_id: (restriction.locked ? restriction.branchId : branchF) || undefined, status: st === 'all' ? undefined : st }, signal);
      all.push(...(res.data || [])); last = res.meta?.last_page || 1; page += 1;
    } while (page <= last);
    return all;
  })(), [branchF, st, restriction.branchId]);
  const canCreate = canAny(can, 'stocktakes.create', 'stocktakes.manage');

  if (!allowed) return <NoPerm title="الجرد" perm="stocktakes.view" go={go} />;

  const list = query.data || [];
  const { phase, error } = apiPhase(query, list.length === 0);
  const effBranch = restriction.locked ? restriction.branchId : branchF;
  const rows = list.filter(c => (st === 'all' || c.status === st) && (!effBranch || c.branch?.id === effBranch));
  const shared = branchesQuery.data || [];
  const branchOptions = shared.filter(b => b.is_active && (!restriction.locked || b.id === restriction.branchId)).map(b => ({ value: b.id, label: `${b.name} · ${b.type === 'warehouse' ? 'مخزن' : 'فرع'}` }));
  const active = (branchF ? 1 : 0) + (st !== 'all' ? 1 : 0);

  return (
    <section className="space-y-5">
      <PageHead title="الجرد" sub="مقارنة الكميات الموجودة فعليًا بالمسجّلة في النظام، ثم اعتماد الفروق كتسويات."
        actions={canCreate && <button className="btn btn-dark" onClick={() => setCreate(true)}><Icon n="plus" s={14} w={2.2} /> جرد جديد</button>} />
      <Card stagger={2}>
        <CardHead title="القائمة" meta={phase === 'ready' ? `${rows.length} عملية` : null} right={
          <div className="seg">
            <button className={st === 'all' ? 'on' : ''} onClick={() => setSt('all')}>الكل</button>
            {Object.entries(STOCKTAKE_STATUS).map(([k, t]) => <button key={k} className={st === k ? 'on' : ''} onClick={() => setSt(k)}>{t}</button>)}
          </div>} />
        <FilterBar active={active} onReset={() => { setBranchF(null); setSt('all'); }}>
          <FItem label="الفرع"><Sel small value={effBranch} disabled={restriction.locked} onChange={v => setBranchF(v || null)} placeholder="كل الفروع" options={branchOptions} className="!bg-[var(--bg-card)]" /></FItem>
        </FilterBar>
        <Phase phase={phase} cols={6} error={error} onRetry={() => query.reload()}
          empty={<EmptyState icon="clipboard" title="لم يتم إنشاء أي جرد بعد" sub="ابدأ جردًا لفرع كامل أو لموقع تخزين محدد."
            action={canCreate && <button className="btn btn-dark btn-sm" onClick={() => setCreate(true)}><Icon n="plus" s={13} /> جرد جديد</button>} />}>
          {rows.length === 0 ? <EmptyState icon="filter" title="لا توجد عمليات جرد بهذه الفلاتر" /> : (
          <table className="vt">
            <thead><tr><th>رقم الجرد</th><th>الفرع</th><th>الموقع</th><th>البنود</th><th>الحالة</th><th>المنشئ</th><th>التاريخ</th><th></th></tr></thead>
            <tbody>
              {rows.map(c => (
                <tr key={c.id} className="cursor-pointer" onClick={() => go('count', { id: c.id })}>
                  <td><span className="mono text-[12px] font-semibold c-primary" dir="ltr">{c.stocktake_number}</span></td>
                  <td className="c-primary font-medium">{c.branch?.name || '—'}</td>
                  <td className="mono text-[11.5px] c-secondary" dir="ltr">{c.location ? c.location.name : <span className="c-muted" style={{ fontFamily: 'inherit' }}>كل المواقع</span>}</td>
                  <td className="num text-[11.5px] c-secondary">{c.items_count ?? '—'} بند</td>
                  <td><Badge cls={({ draft: 'b-gray', in_progress: 'b-amber', approved: 'b-green', cancelled: 'b-red' })[c.status] || 'b-gray'} dot>{STOCKTAKE_STATUS[c.status] || c.status}</Badge></td>
                  <td className="c-secondary">{c.creator_name || '—'}</td>
                  <td className="num text-[11.5px] c-secondary">{formatDate(c.created_at)}</td>
                  <td><Icon n="chevL" s={15} className="c-muted" /></td>
                </tr>
              ))}
            </tbody>
          </table>)}
        </Phase>
      </Card>
      <CountCreate open={create} onClose={() => setCreate(false)} go={go} onCreated={() => query.reload()} />
    </section>
  );
}
// NOTE: the stocktake list resource carries items_count but not the items, so the
// old counted/total progress bar was replaced by the items total.

function CountCreate({ open, onClose, go, onCreated }) {
  const { toast, can, branchesQuery, variantCache } = useInv();
  const { keyFor, reset } = useIdempotencyKey();
  const [branchId, setBranchId] = useState(null);
  const [locId, setLocId] = useState(null);
  const [note, setNote] = useState('');
  const [mode, setMode] = useState('all');
  const [picked, setPicked] = useState([]);
  const [tried, setTried] = useState(false);
  const [busy, setBusy] = useState(false);
  const [srvErr, setSrvErr] = useState(null);
  useEffect(() => { if (open) { setBranchId(null); setLocId(null); setNote(''); setMode('all'); setPicked([]); setTried(false); setBusy(false); setSrvErr(null); } }, [open]);
  const restriction = useRestrictedBranch();
  const effBranch = restriction.locked ? restriction.branchId : branchId;

  const scopeQuery = useApi(signal => (effBranch
    ? (async () => {
        const all = []; let page = 1; let last = 1;
        do {
          const res = await stockApi.levels({ branch_id: effBranch, location_id: locId || undefined, per_page: 100, page }, signal);
          all.push(...(res.data || [])); last = res.meta?.last_page || 1; page += 1;
        } while (page <= last);
        return all;
      })()
    : Promise.resolve([])), [effBranch || 0, locId || 0]);
  const scopeIds = useMemo(() => [...new Set((scopeQuery.data || []).map(s => s.product_variant_id))], [scopeQuery.data]);
  useEffect(() => { cacheVariantNames(variantCache, (scopeQuery.data || []).map(s => s.variant)); }, [scopeQuery.data]);

  const locsQuery = useBranchesWithLocations(effBranch);

  const items = mode === 'all' ? scopeIds : picked;
  const valid = effBranch && items.length > 0 && (mode !== 'all' || (!scopeQuery.loading && !scopeQuery.error));

  const submit = async () => {
    setTried(true); if (!valid || busy) return;
    setBusy(true); setSrvErr(null);
    const payload = {
      branch_id: effBranch, ...(locId ? { location_id: locId } : {}),
      notes: note.trim() || null,
      items: items.slice(0, 500).map(id => ({ product_variant_id: id, counted_qty: null })),
    };
    try {
      // No snapshot is taken without items: a full-scope count is built by
      // paging every stock level, then topping up past 500 via PATCH chunks.
      const res = await stocktakesApi.create(payload, keyFor(payload));
      const rest = items.slice(500);
      for (let i = 0; i < rest.length; i += 500) {
        await stocktakesApi.updateItems(res.data.id, rest.slice(i, i + 500).map(id => ({ product_variant_id: id, counted_qty: null })));
      }
      // New counts start as draft: move straight into counting like the old flow.
      try { await stocktakesApi.update(res.data.id, { status: 'in_progress' }); } catch { /* detail still opens as draft */ }
      reset();
      toast(res.message || 'تم إنشاء الجرد'); onClose(); onCreated && onCreated(); go('count', { id: res.data.id });
    } catch (e) {
      setSrvErr(normalizeError(e).message);
      setBusy(false);
    }
  };

  const shared = branchesQuery.data || [];
  const branchOptions = (restriction.locked ? shared.filter(b => b.id === restriction.branchId) : shared.filter(b => b.is_active))
    .map(b => ({ value: b.id, label: `${b.name} · ${b.type === 'warehouse' ? 'مخزن' : 'فرع'}` }));
  const locOptions = (locsQuery.data || []).filter(l => l.is_active).map(l => ({ value: l.id, label: `${l.name} (${l.code})` }));

  return (
    <Modal open={open} onClose={onClose} width="max-w-2xl" title="جرد جديد" sub="حتى 500 بند عند الإنشاء، والباقي يُستكمل تلقائيًا"
      footer={<><button className="btn btn-dark" onClick={submit} disabled={busy}>{busy && <span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" />} إنشاء وبدء العدّ</button><button className="btn btn-soft" onClick={onClose}>إلغاء</button></>}>
      <div className="space-y-4">
        {srvErr && <InlineAlert kind="red">{srvErr}</InlineAlert>}
        <div className="grid grid-cols-2 gap-4">
          <Field label="الفرع" req error={tried && !effBranch ? 'مطلوب' : null}><Sel value={effBranch} onChange={v => { if (!restriction.locked) { setBranchId(v || null); setLocId(null); } }} placeholder="اختر…" disabled={restriction.locked} options={branchOptions} /></Field>
          <Field label="الموقع" hint="اختياري"><Sel value={locId} onChange={v => setLocId(v || null)} placeholder={effBranch ? 'كل المواقع' : '—'} disabled={!effBranch} options={locOptions} /></Field>
        </div>
        <div>
          <span className="field-label">الأصناف</span>
          <div className="seg mb-3">
            <button className={mode === 'all' ? 'on' : ''} onClick={() => setMode('all')}>كل الأصناف في النطاق</button>
            <button className={mode === 'pick' ? 'on' : ''} onClick={() => setMode('pick')}>اختيار أصناف محددة</button>
          </div>
          {mode === 'all' ? (
            <div className="v-subcard p-3.5 flex items-center justify-between">
              <span className="text-[12.5px] c-secondary">{effBranch ? 'سيتم إدراج كل SKU له رصيد في النطاق المحدد' : 'اختر الفرع لعرض عدد الأصناف'}</span>
              <span className="num font-display text-[20px] font-bold c-primary">{scopeQuery.loading ? '…' : items.length}</span>
            </div>
          ) : (
            <div className="space-y-2">
              <VariantPicker value={null} onChange={v => v && setPicked(p => p.length < 500 ? [...p, v] : p)} exclude={picked} placeholder="أضف SKU…" />
              <div className="flex flex-wrap gap-1.5">
                {picked.map(v => <span key={v} className="badge b-gray !py-1"><span className="mono" dir="ltr">{variantCache.get(Number(v))?.sku || `#${v}`}</span><button onClick={() => setPicked(p => p.filter(x => x !== v))}><Icon n="x" s={11} /></button></span>)}
                {picked.length === 0 && <span className="text-[11.5px] c-muted">لم تتم إضافة أصناف</span>}
              </div>
            </div>
          )}
          {tried && effBranch && items.length === 0 && <div className="text-[11px] mt-1.5" style={{ color: 'var(--badge-red-text)' }}>لا توجد أصناف للجرد</div>}
          <div className="text-[11px] c-muted mt-1.5 num">{items.length} / 500</div>
        </div>
        <Field label="ملاحظات"><textarea maxLength={2000} className="inp" rows={2} value={note} onChange={e => setNote(e.target.value)} /></Field>
      </div>
    </Modal>
  );
}

function CountDetail({ go, params }) {
  const { toast, can, variantCache } = useInv();
  const id = Number(params.id);
  const [vals, setVals] = useState({});
  const [filter, setFilter] = useState('all');
  const [confirm, setConfirm] = useState(false);
  const [pendingAction, setPendingAction] = useState(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const [dirty, setDirty] = useState(false);
  const [stale, setStale] = useState({ itemIds: [], variantIds: [] });
  const canEdit = canAny(can, 'stocktakes.update', 'stocktakes.manage');
  const canApprove = canAny(can, 'stocktakes.approve', 'stocktakes.manage');
  const canDelete = canAny(can, 'stocktakes.delete', 'stocktakes.manage');

  const query = useApi(signal => stocktakesApi.show(id, signal).then(t => {
    cacheVariantNames(variantCache, (t.items || []).map(it => ({ id: it.product_variant_id, sku: it.sku })));
    return t;
  }), [id]);

  useEffect(() => {
    const t = query.data;
    if (t && !dirty) {
      setVals(Object.fromEntries((t.items || []).map(it => [it.product_variant_id, it.counted_qty === null ? '' : String(it.counted_qty)])));
    }
  }, [query.data]);

  useEffect(() => {
    if (!dirty) return;
    const warn = e => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);
  useEffect(() => {
    window.dispatchEvent(new CustomEvent('inventory:dirty', { detail: dirty }));
    return () => window.dispatchEvent(new CustomEvent('inventory:dirty', { detail: false }));
  }, [dirty]);

  if (!canAny(can, 'stocktakes.view', 'stocktakes.manage')) return <NoPerm title="تفاصيل الجرد" perm="stocktakes.view" go={go} />;

  const c = query.data;
  const { phase, error } = apiPhase(query, !c);
  const onRetry = () => { query.reload(); };

  // "" means "not counted yet", "0" means an actual zero — never coerce "" to 0.
  const countedOf = v => (v === '' || v === undefined ? null : Number(toLatinDigits(v)));
  const rows = ((c?.items) || []).map(it => {
    const v = vals[it.product_variant_id];
    const a = countedOf(v);
    const changed = a !== (it.counted_qty == null ? null : Number(it.counted_qty));
    const diff = changed ? (a === null ? null : +((a - Number(it.system_qty)).toFixed(4))) : (it.difference == null ? null : Number(it.difference));
    return { ...it, a, diff, isStale: stale.itemIds.includes(it.id) || stale.variantIds.includes(it.product_variant_id) };
  });
  const counted = rows.filter(r => r.a !== null).length;
  const shown = rows.filter(r => filter === 'all' || (filter === 'uncounted' && r.a === null) || (filter === 'diff' && r.diff) || (filter === 'match' && r.diff === 0) || (filter === 'stale' && r.isStale));
  const diffs = rows.filter(r => r.diff);
  const editable = c && !['approved', 'cancelled'].includes(c.status) && canEdit;

  const chunked = arr => { const out = []; for (let i = 0; i < arr.length; i += 500) out.push(arr.slice(i, i + 500)); return out; };
  const saveCounts = async (only = null) => {
    if (busy) return;
    if (rows.some(r => {
      const v = vals[r.product_variant_id];
      return v !== '' && v !== undefined && (!QTY_RE.test(toLatinDigits(v)) || Number(toLatinDigits(v)) > QTY_MAX);
    })) { setErr('الكمية يجب أن تكون صفرًا أو أكبر، وبحد أقصى أربع خانات عشرية.'); return; }
    setBusy(true); setErr(null);
    const targets = only ? rows.filter(r => only.includes(r.product_variant_id)) : rows.filter(r => {
      const cur = c.items.find(it => it.product_variant_id === r.product_variant_id);
      return r.a !== (cur?.counted_qty == null ? null : Number(cur.counted_qty));
    });
    try {
      for (const part of chunked(targets)) {
        await stocktakesApi.updateItems(id, part.map(r => ({
          product_variant_id: r.product_variant_id, counted_qty: r.a,
          ...(r.isStale ? { recount: true } : {}),
        })));
      }
      const savedIds = new Set(targets.map(r => r.product_variant_id));
      setDirty(rows.some(r => !savedIds.has(r.product_variant_id) && r.a !== (c.items.find(it => it.product_variant_id === r.product_variant_id)?.counted_qty == null ? null : Number(c.items.find(it => it.product_variant_id === r.product_variant_id).counted_qty))));
      setStale(s => ({ itemIds: s.itemIds.filter(x => !targets.some(r => r.id === x)), variantIds: s.variantIds.filter(x => !savedIds.has(x)) }));
      toast('تم حفظ العدّ'); query.reload();
    } catch (e) { setErr(normalizeError(e).message); }
    setBusy(false);
  };
  const startCounting = async () => {
    setBusy(true); setErr(null);
    try { await stocktakesApi.update(id, { status: 'in_progress' }); toast('بدأ العدّ'); query.reload(); }
    catch (e) { setErr(normalizeError(e).message); }
    setBusy(false);
  };
  const changeStatus = async () => {
    if (busy) return;
    setBusy(true); setErr(null);
    try {
      if (pendingAction === 'delete') {
        const res = await stocktakesApi.remove(id);
        setDirty(false); window.dispatchEvent(new CustomEvent('inventory:dirty', { detail: false }));
        toast(res.message || 'تم حذف الجرد'); go('counts');
      } else {
        await stocktakesApi.update(id, { status: 'cancelled' });
        setDirty(false); query.reload(); toast('تم إلغاء الجرد');
      }
      setPendingAction(null);
    } catch (e) { setErr(normalizeError(e).message); }
    setBusy(false);
  };
  const approve = async () => {
    setBusy(true); setErr(null);
    try {
      const res = await stocktakesApi.approve(id);
      setConfirm(false); setStale({ itemIds: [], variantIds: [] });
      toast(res.message || 'تم اعتماد الجرد وترحيل الفروق'); query.reload();
    } catch (e) {
      const ne = normalizeError(e);
      if (/لم تُعدّ/.test(ne.message)) { setFilter('uncounted'); query.reload(); }
      if (ne.fields?.recount_item_ids || ne.fields?.recount_variant_ids) {
        setStale({
          itemIds: (ne.fields.recount_item_ids || []).map(Number),
          variantIds: (ne.fields.recount_variant_ids || []).map(Number),
        });
        setFilter('stale');
      }
      setErr(ne.message);
    }
    setBusy(false);
  };

  return (
    <section className="space-y-5">
      <PageHead title={c ? <span className="mono" dir="ltr">{c.stocktake_number}</span> : 'تفاصيل الجرد'} back={{ label: 'الجرد', onClick: () => go('counts') }}
        sub={c && <span className="flex items-center gap-2 flex-wrap">{c.branch?.name || '—'} <span className="c-muted">·</span> {c.location ? <span className="mono" dir="ltr">{c.location.name}</span> : 'كل المواقع'} <Badge cls={({ draft: 'b-gray', in_progress: 'b-amber', approved: 'b-green', cancelled: 'b-red' })[c.status] || 'b-gray'} dot>{STOCKTAKE_STATUS[c.status] || c.status}</Badge></span>}
        actions={c && c.status !== 'approved' && <>
          {canDelete && ['draft', 'cancelled'].includes(c.status) && <button className="btn btn-danger" disabled={busy} onClick={() => { setErr(null); setPendingAction('delete'); }}>حذف الجرد</button>}
          {editable && <button className="btn btn-soft" disabled={busy} onClick={() => { setErr(null); setPendingAction('cancel'); }}>إلغاء الجرد</button>}
          {c.status === 'draft' && canEdit && <button className="btn btn-soft" disabled={busy} onClick={startCounting}>بدء العدّ</button>}
          {editable && <button className="btn btn-soft" disabled={!dirty || busy} onClick={() => saveCounts()}>{busy ? 'جارٍ الحفظ…' : 'حفظ العدّ'}</button>}
          {c.status !== 'cancelled' && (canApprove
            ? <button className="btn btn-orange" onClick={() => { setErr(null); setConfirm(true); }} disabled={busy || dirty || !rows.length || counted < rows.length}><Icon n="check" s={14} w={2.2} /> اعتماد الجرد</button>
            : <span className="inline-flex items-center gap-1.5 text-[11.5px] c-muted px-3 py-1.5 rounded-full v-subcard"><Icon n="lock" s={12} /> الاعتماد يتطلب صلاحية</span>)}
        </>} />

      {dirty && <InlineAlert kind="amber" icon="info" title="تغييرات غير محفوظة">احفظ العدّ قبل مغادرة الصفحة حتى لا تضيع الكميات المدخلة.</InlineAlert>}
      {err && !confirm && <InlineAlert kind="red">{err}<button className="btn btn-soft btn-sm" disabled={busy} onClick={() => saveCounts()}>إعادة المحاولة</button></InlineAlert>}

      <Phase phase={phase} cols={5} rows={5} error={error} onRetry={onRetry} onBack={() => go('counts')} empty={<Card><EmptyState icon="clipboard" title="لا توجد بنود في هذا الجرد" /></Card>}>
        {c && (<>
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5 stagger-item stagger-2">
          <KPI hot label="تم العدّ" icon="clipboard" value={`${counted}/${rows.length}`} sub={counted < rows.length ? `${rows.length - counted} بند لم يُعدّ بعد` : 'اكتمل العدّ'} />
          <KPI label="مطابق" icon="check" value={rows.filter(r => r.diff === 0).length} />
          <KPI label="بفروق" icon="alert" value={diffs.length} />
          <KPI label="صافي الفرق" icon="sliders" value={(() => { const n = diffs.reduce((a, r) => a + r.diff, 0); return (n > 0 ? '+' : '') + formatQty(n); })()} sub="بالوحدات" />
        </div>

        <Card stagger={3}>
          <CardHead title="جدول العدّ" right={
            <div className="seg">
              {[['all', 'الكل'], ['uncounted', 'لم يُعدّ'], ['diff', 'بفروق'], ['match', 'مطابق'], ...(stale.itemIds.length || stale.variantIds.length ? [['stale', 'يحتاج إعادة عدّ']] : [])].map(([k, l]) => <button key={k} className={filter === k ? 'on' : ''} onClick={() => setFilter(k)}>{l}</button>)}
            </div>} />
          {counted < rows.length && c.status !== 'approved' && <div className="mb-3"><InlineAlert kind="amber" icon="info" title="الحقل الفارغ ≠ صفر">الخانة الفارغة المظللة تعني «لم يتم العدّ». إذا كان الصنف غير موجود فعليًا اكتب 0 أو اضغط «صفر».</InlineAlert></div>}
          {stale.itemIds.length + stale.variantIds.length > 0 && <div className="mb-3"><InlineAlert kind="red" icon="alert" title="أصناف تحتاج إعادة عدّ">تغيّر رصيدها بعد عدّها. عدّها من جديد ثم اضغط «إعادة العدّ» على البند.</InlineAlert></div>}
          {shown.length === 0 ? <EmptyState icon="filter" title="لا توجد بنود بهذا الفلتر" /> : (
            <table className="vt">
              <thead><tr><th>SKU</th><th>الصنف</th><th className="t-num">كمية النظام</th><th className="t-num" style={{ width: 200 }}>الكمية الفعلية</th><th className="t-num">الفرق</th><th>الحالة</th></tr></thead>
              <tbody>
                {shown.map(r => (
                  <tr key={r.product_variant_id} className={r.isStale ? 'ring-1 ring-inset' : ''} style={r.isStale ? { background: 'var(--badge-red-bg)' } : {}}>
                    <td><Sku>{r.sku || `#${r.product_variant_id}`}</Sku></td>
                    <td><div className="font-medium c-primary truncate">{variantDisplayName(variantCache, r.product_variant_id, r.sku)}</div></td>
                    <td className="t-num num c-secondary">{formatQty(r.system_qty)}</td>
                    <td className="t-num">
                      {editable ? (
                        <div className="flex items-center gap-1.5 justify-end">
                          {r.a === null && <button className="btn btn-ghost btn-sm !px-2 !py-1 text-[11px]" onClick={() => { setVals(v => ({ ...v, [r.product_variant_id]: '0' })); setDirty(true); }}>صفر</button>}
                          <div className={`rounded-[10px] w-28 ${r.a === null ? 'uncounted' : ''}`}>
                            <NumInput small value={vals[r.product_variant_id] ?? ''} placeholder="لم يُعدّ" onChange={v => { setVals(x => ({ ...x, [r.product_variant_id]: v })); setDirty(true); }} className={r.a === null ? '!bg-transparent' : ''} />
                          </div>
                          {r.a !== null && <button className="icon-btn !w-6 !h-6" title="مسح (لم يُعدّ)" onClick={() => { setVals(v => ({ ...v, [r.product_variant_id]: '' })); setDirty(true); }}><Icon n="x" s={11} /></button>}
                          {r.isStale && <button className="btn btn-soft btn-sm" disabled={busy} onClick={() => saveCounts([r.product_variant_id])}>إعادة العدّ</button>}
                        </div>
                      ) : <span className="num font-semibold c-primary">{r.a === null ? <span className="c-muted font-normal">لم يُعدّ</span> : formatQty(r.a)}</span>}
                    </td>
                    <td className="t-num num font-bold" style={{ color: r.diff === null ? 'var(--text-muted)' : r.diff > 0 ? 'var(--badge-green-text)' : r.diff < 0 ? 'var(--badge-red-text)' : 'var(--text-secondary)' }}>
                      {r.diff === null ? '—' : r.diff === 0 ? '0' : (r.diff > 0 ? '+' : '−') + formatQty(Math.abs(r.diff))}
                    </td>
                    <td>{r.isStale ? <Badge cls="b-red">تحتاج إعادة عدّ</Badge> : r.a === null ? <Badge cls="b-gray">لم يُعدّ</Badge> : r.a === 0 ? <Badge cls={r.diff ? 'b-red' : 'b-green'}>صفر فعلي</Badge> : r.diff === 0 ? <Badge cls="b-green">مطابق</Badge> : <Badge cls={r.diff > 0 ? 'b-blue' : 'b-red'}>{r.diff > 0 ? 'زيادة' : 'عجز'}</Badge>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
        </>)}
      </Phase>

      <ConfirmDialog open={confirm} onClose={() => setConfirm(false)} onConfirm={approve} busy={busy} error={err} title="اعتماد الجرد" confirmLabel="اعتماد وترحيل الفروق"
        body={<>سيتم ترحيل <b className="num c-primary">{diffs.length}</b> فرق كحركات «فرق جرد» وتحديث الأرصدة. لا يمكن تعديل العدّ بعد الاعتماد.</>} />
      <ConfirmDialog open={!!pendingAction} onClose={() => setPendingAction(null)} onConfirm={changeStatus} busy={busy} error={err} danger
        title={pendingAction === 'delete' ? 'حذف الجرد؟' : 'إلغاء الجرد؟'} confirmLabel={pendingAction === 'delete' ? 'حذف الجرد' : 'إلغاء الجرد'}
        body="سيُقفل الجرد وتُفقد أي تغييرات غير محفوظة. لن تتغير الأرصدة بهذه العملية." />
    </section>
  );
}


// ---------------- 8. Production receipt ----------------
const PRD_MAX = 50;
let __prKey = 1;
const newPrLine = () => ({ key: __prKey++, variantId: null, qty: '', cost: '' });

function ProductionScreen({ go }) {
  const { toast, can, variantCache } = useInv();
  const [open, setOpen] = useState(false);
  const [view, setView] = useState(null);
  const [page, setPage] = useState(1);
  const canEnter = can('inventory.adjust') && can('products.view_cost');
  const showCost = can('products.view_cost');

  const history = useApi(signal => productionApi.list({ per_page: 15, page }, signal), [page]);
  const optsQuery = useApi(signal => productionApi.options('', signal), []);
  useEffect(() => {
    const d = optsQuery.data;
    if (d) {
      (d.variants || []).forEach(v => {
        cacheVariantOptions(variantCache, [v]);
      });
    }
  }, [optsQuery.data]);

  const branchName = useMemo(() => {
    const m = new Map();
    (optsQuery.data?.branches || []).forEach(b => m.set(b.id, b.name));
    return id => m.get(id) || `#${id}`;
  }, [optsQuery.data]);
  const locName = useMemo(() => {
    const m = new Map();
    (optsQuery.data?.branches || []).forEach(b => (b.locations || []).forEach(l => m.set(l.id, l.name)));
    return id => (id ? m.get(id) || `#${id}` : '—');
  }, [optsQuery.data]);

  const rows = history.data?.data || [];
  if (!can('inventory.view')) return <NoPerm title="استلام الإنتاج" perm="inventory.view" go={go} />;
  const { phase, error } = apiPhase(history, rows.length === 0);
  // Raw Laravel paginator (data/current_page/last_page/…) — NOT under meta, so it
  // gets its own small mapper instead of the ResourceCollection one.

  return (
    <section className="space-y-5">
      <PageHead title="استلام الإنتاج" sub="تسجيل المنتجات التامة الواردة من التصنيع إلى المخزن مع تكلفة الوحدة."
        actions={canEnter ? <button className="btn btn-dark" onClick={() => setOpen(true)}><Icon n="plus" s={14} w={2.2} /> تسجيل استلام</button>
          : <span className="inline-flex items-center gap-1.5 text-[12px] c-muted px-3.5 py-2 rounded-full v-pill-surface"><Icon n="lock" s={12} /> يتطلب صلاحية تسوية المخزون وعرض التكلفة</span>} />
      <Card stagger={2}>
        <CardHead title="سجل الاستلامات" meta={phase === 'ready' ? `${rows.length} استلام` : null} />
        <Phase phase={phase} cols={6} error={error} onRetry={() => history.reload()}
          empty={<EmptyState icon="factory" title="لا توجد استلامات إنتاج" sub="عند وصول دفعة من التصنيع سجّلها هنا لتُضاف إلى رصيد المخزن." action={canEnter && <button className="btn btn-dark btn-sm" onClick={() => setOpen(true)}><Icon n="plus" s={13} /> تسجيل استلام</button>} />}>
          <table className="vt">
            <thead><tr><th>رقم الدفعة</th><th>الفرع</th><th>الموقع</th><th className="t-num">البنود</th><th className="t-num">إجمالي الكمية</th>{showCost && <th className="t-num">إجمالي التكلفة</th>}<th>التاريخ</th></tr></thead>
            <tbody>
              {rows.map(p => (
                <tr key={p.id} className="cursor-pointer" onClick={() => setView(p)}>
                  <td><Sku>{p.batch_number}</Sku></td>
                  <td className="c-primary font-medium">{branchName(p.branch_id)}</td>
                  <td className="mono text-[11.5px] c-secondary" dir="ltr">{locName(p.location_id)}</td>
                  <td className="t-num num c-secondary">{(p.items || []).length}</td>
                  <td className="t-num num font-semibold c-primary">{(p.items || []).reduce((a, l) => a + Number(l.quantity || 0), 0)}</td>
                  {showCost && <td className="t-num num c-primary">{formatCurrency((p.items || []).reduce((a, l) => a + Number(l.quantity || 0) * Number(l.unit_cost || 0), 0))}</td>}
                  <td className="num text-[11.5px] c-secondary">{formatDate(p.created_at, { includeTime: true })}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {history.data && history.data.last_page > 1 && (
            <div className="flex items-center justify-between mt-4">
              <button className="btn btn-soft btn-sm" disabled={page <= 1} onClick={() => setPage(p => Math.max(1, p - 1))}>السابق</button>
              <span className="text-[12px] c-muted num">صفحة {history.data.current_page} من {history.data.last_page}</span>
              <button className="btn btn-soft btn-sm" disabled={page >= history.data.last_page} onClick={() => setPage(p => p + 1)}>التالي</button>
            </div>
          )}
        </Phase>
      </Card>
      <ProductionForm open={open} onClose={() => setOpen(false)} branches={optsQuery.data?.branches || []} onSaved={() => { history.reload(); }} />
      <Modal open={!!view} onClose={() => setView(null)} width="max-w-xl" title={view ? <span className="mono" dir="ltr">{view.batch_number}</span> : ''} sub={view && `${branchName(view.branch_id)} · ${locName(view.location_id)}`}>
        {view && <table className="vt mb-4"><thead><tr><th>SKU</th><th>الصنف</th><th className="t-num">الكمية</th>{showCost && <th className="t-num">تكلفة الوحدة</th>}</tr></thead>
          <tbody>{(view.items || []).map((l, i) => <tr key={i}><td><Sku>{variantCache.get(Number(l.product_variant_id))?.sku || `#${l.product_variant_id}`}</Sku></td><td><div className="font-medium c-primary truncate">{variantDisplayName(variantCache, l.product_variant_id, null)}</div></td><td className="t-num num font-semibold">{formatQty(l.quantity)}</td>{showCost && <td className="t-num num">{l.unit_cost != null ? formatCurrency(l.unit_cost) : '—'}</td>}</tr>)}</tbody></table>}
      </Modal>
    </section>
  );
}

function ProductionForm({ open, onClose, branches, onSaved }) {
  const { toast, variantCache } = useInv();
  const { keyFor, reset } = useIdempotencyKey();
  const restriction = useRestrictedBranch();
  const [f, setF] = useState({ batch: '', branchId: null, locId: null, note: '' });
  const [lines, setLines] = useState([newPrLine()]);
  const [tried, setTried] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const [rowErrs, setRowErrs] = useState({});
  useEffect(() => { if (open) { setF({ batch: '', branchId: restriction.locked ? restriction.branchId : null, locId: null, note: '' }); setLines([newPrLine()]); setTried(false); setErr(null); setRowErrs({}); setBusy(false); } }, [open]);
  const upd = (k, p) => { setLines(ls => ls.map(l => l.key === k ? { ...l, ...p } : l)); setRowErrs({}); };
  const bad = l => {
    if (!l.variantId) return true;
    const qv = toLatinDigits(l.qty);
    if (!/^\d+$/.test(qv) || !(Number(qv) >= 1) || Number(qv) > 99999999) return true;
    const cv = toLatinDigits(l.cost);
    if (cv === '' || !QTY_RE.test(cv) || Number(cv) < 0 || Number(cv) > QTY_MAX) return true;
    return false;
  };
  const valid = f.batch.trim() && f.batch.trim().length <= 100 && f.branchId && lines.length && lines.length <= PRD_MAX && lines.every(l => !bad(l));
  const dupes = lines.filter((l, i) => l.variantId && lines.findIndex(x => x.variantId === l.variantId) !== i).length;
  const total = lines.reduce((a, l) => a + (Number(toLatinDigits(l.qty)) || 0) * (Number(toLatinDigits(l.cost)) || 0), 0);
  const locOpts = ((branches.find(b => b.id === f.branchId)?.locations) || []).map(l => ({ value: l.id, label: l.name }));
  const submit = async () => {
    setTried(true); if (!valid || dupes || busy) return;
    setBusy(true); setErr(null); setRowErrs({});
    const payload = {
      batch_number: f.batch.trim(), branch_id: f.branchId,
      ...(f.locId ? { location_id: f.locId } : {}),
      notes: f.note.trim() ? f.note.trim().slice(0, 1000) : null,
      items: lines.map(l => ({ product_variant_id: l.variantId, quantity: Number(toLatinDigits(l.qty)), unit_cost: Number(toLatinDigits(l.cost)) })),
    };
    try {
      // Idempotency-Key is REQUIRED here (422 without it); same payload reuses
      // the same key so a double-click records one receipt only.
      const res = await productionApi.create(payload, keyFor(payload));
      reset();
      toast(res.message || 'تم تسجيل استلام الإنتاج');
      onClose(); onSaved();
    } catch (e) {
      const ne = normalizeError(e);
      setRowErrs({ ...ne.fields, ...groupItemsErrors(ne.fields) });
      setErr(ne.message);
      setBusy(false);
    }
  };
  return (
    <Modal open={open} onClose={onClose} width="max-w-4xl" title="تسجيل استلام إنتاج" sub={`حتى ${PRD_MAX} بندًا · الكميات أعداد صحيحة · المتغيرات مميزة`}
      footer={<>
        <button className="btn btn-orange" onClick={submit} disabled={busy}>{busy && <span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" />} تسجيل الاستلام</button>
        <button className="btn btn-soft" onClick={onClose}>إلغاء</button>
        <span className="flex-1" />
        <span className="text-[12px] c-secondary">إجمالي التكلفة <span className="num font-display text-[18px] font-bold c-primary mr-1">{formatCurrency(total)}</span></span>
      </>}>
      <div className="space-y-4">
        {err && <InlineAlert kind="red" title="رفض الخادم العملية">{err}</InlineAlert>}
        {dupes > 0 && <InlineAlert kind="amber" title="بنود مكررة">يوجد {dupes} بند مكرر — المتغيرات يجب أن تكون مميزة، احذف التكرار قبل التسجيل.</InlineAlert>}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <Field label="رقم الدفعة" req error={tried && !f.batch.trim() ? 'مطلوب' : rowErrs.batch_number}><input dir="ltr" className={`inp mono text-left ${tried && !f.batch.trim() ? 'err' : ''}`} maxLength={100} value={f.batch} onChange={e => setF({ ...f, batch: e.target.value.toUpperCase() })} placeholder="B-2610-08" /></Field>
          <Field label="الفرع / المخزن" req error={tried && !f.branchId ? 'مطلوب' : rowErrs.branch_id}><Sel value={f.branchId} disabled={restriction.locked} onChange={v => setF({ ...f, branchId: v || null, locId: null })} placeholder="اختر…" options={branches.filter(b => !restriction.locked || b.id === restriction.branchId).map(b => ({ value: b.id, label: b.name }))} /></Field>
          <Field label="الموقع" hint="اختياري"><Sel value={f.locId} onChange={v => setF({ ...f, locId: v || null })} placeholder={f.branchId ? 'الموقع الافتراضي' : '—'} disabled={!f.branchId} options={locOpts} /></Field>
        </div>
        <div>
          <div className="flex items-center justify-between mb-2">
            <span className="field-label !mb-0">البنود <span className="num c-muted">{lines.length}/{PRD_MAX}</span></span>
            <button className="btn btn-soft btn-sm" disabled={lines.length >= PRD_MAX} onClick={() => setLines(ls => [...ls, newPrLine()])}><Icon n="plus" s={13} /> إضافة بند</button>
          </div>
          <table className="vt">
            <thead><tr><th className="w-8">#</th><th style={{ minWidth: 300 }}>المتغير / SKU</th><th style={{ width: 120 }}>الكمية</th><th style={{ width: 140 }}>تكلفة الوحدة</th><th className="t-num">الإجمالي</th><th></th></tr></thead>
            <tbody>
              {lines.map((l, i) => {
                const re = rowErrs[i] || {};
                return (
                <tr key={l.key}>
                  <td className="num c-muted">{i + 1}</td>
                  <td><div className={(tried && !l.variantId) || re.product_variant_id ? 'rounded-[12px] ring-1 ring-[var(--badge-red-text)]' : ''}><VariantPicker small value={l.variantId} onChange={v => upd(l.key, { variantId: v })} /></div>
                    {re.product_variant_id && <div className="text-[10.5px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{re.product_variant_id}</div>}</td>
                  <td><NumInput small integer value={l.qty} onChange={v => upd(l.key, { qty: v })} err={(tried && bad(l)) || re.quantity} />
                    {re.quantity && <div className="text-[10.5px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{re.quantity}</div>}</td>
                  <td><NumInput small decimals={4} value={l.cost} onChange={v => upd(l.key, { cost: v })} err={(tried && bad(l)) || re.unit_cost} placeholder="0.00" />
                    {re.unit_cost && <div className="text-[10.5px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{re.unit_cost}</div>}</td>
                  <td className="t-num num c-primary font-semibold">{formatCurrency((Number(toLatinDigits(l.qty)) || 0) * (Number(toLatinDigits(l.cost)) || 0))}</td>
                  <td><button className="icon-btn" disabled={lines.length === 1} onClick={() => setLines(ls => ls.filter(x => x.key !== l.key))}><Icon n="trash" s={14} /></button></td>
                </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <Field label="ملاحظات"><textarea maxLength={1000} className="inp" rows={2} value={f.note} onChange={e => setF({ ...f, note: e.target.value })} /></Field>
      </div>
    </Modal>
  );
}


// ============ 3. Stock balances · 4. Adjustment · 5. Movements ============

function FilterBar({ children, onReset, active }) {
  return (
    <div className="v-subcard p-3 flex flex-wrap items-end gap-2.5 mb-4">
      {children}
      {active > 0 && <button onClick={onReset} className="btn btn-ghost btn-sm mb-0.5"><Icon n="x" s={12} /> مسح ({active})</button>}
    </div>
  );
}
const FItem = ({ label, w = 'w-44', children }) => (
  <div className={w}><div className="text-[10.5px] font-medium c-muted mb-1 pr-1">{label}</div>{children}</div>
);

// Product-level then variant-level filter (product narrows the variant list)

// ---------------- 3. Stock balances ----------------
function StockScreen({ go, params }) {
  const { toast, can, branchesQuery, variantCache } = useInv();
  const [page, setPage] = useState(1);
  const restriction = useRestrictedBranch();
  const allowed = canAny(can, 'inventory.view');

  const f = {
    branchId: params.branchId ? Number(params.branchId) : null,
    locationId: params.locationId ? Number(params.locationId) : null,
    variantId: params.variantId ? Number(params.variantId) : null,
    low: params.low === '1',
  };
  // A restricted user is pinned to their branch (the backend scopes them anyway).
  const effBranch = restriction.locked ? restriction.branchId : f.branchId;
  const set = p => {
    const n = { branchId: f.branchId, locationId: f.locationId, variantId: f.variantId, low: f.low ? '1' : undefined, ...p };
    if (n.branchId !== f.branchId) n.locationId = undefined;
    if (!n.low) delete n.low;
    go('stock', n);
  };

  const locsQuery = useBranchesWithLocations(effBranch);
  const query = useApi(signal => stockApi.levels({
    branch_id: effBranch || undefined, location_id: f.locationId || undefined,
    product_variant_id: f.variantId || undefined, low_stock: f.low || undefined,
    per_page: 15, page,
  }, signal).then(res => { cacheVariantNames(variantCache, (res.data || []).map(r => r.variant)); return res; }),
  [effBranch || 0, f.locationId || 0, f.variantId || 0, f.low, page]);

  if (!allowed) return <NoPerm title="أرصدة المخزون" perm="inventory.view" go={go} />;

  const rows = query.data?.data || [];
  const meta = query.data?.meta;
  const { phase, error } = apiPhase(query, rows.length === 0);
  const onRetry = () => query.reload();
  const shared = branchesQuery.data || [];
  const branchOptions = (restriction.locked ? shared.filter(b => b.id === restriction.branchId) : shared.filter(b => b.is_active))
    .map(b => ({ value: b.id, label: `${b.name} · ${b.type === 'warehouse' ? 'مخزن' : 'فرع'}` }));
  const locOptions = (locsQuery.data || []).filter(l => l.is_active).map(l => ({ value: l.id, label: `${l.name} (${l.code})` }));
  const active = (effBranch ? 1 : 0) + (f.locationId ? 1 : 0) + (f.variantId ? 1 : 0) + (f.low ? 1 : 0);
  const sum = k => rows.reduce((a, s) => a + Number(s[k] || 0), 0);
  const canAdjust = canAny(can, 'inventory.adjust');

  return (
    <section className="space-y-5">
      <PageHead title="أرصدة المخزون" sub="الكميات الحالية لكل SKU في كل فرع وموقع. لتغيير أي كمية استخدم عملية مخزنية."
        actions={canAdjust && <button className="btn btn-dark" onClick={() => go('adjust')}><Icon n="sliders" s={14} /> تسوية جديدة</button>} />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5 stagger-item stagger-2">
        <KPI hot label="المتاح" icon="box" value={phase === 'ready' ? formatQty(sum('available')) : '—'} sub={`${rows.length} سطر رصيد · في الصفحة الحالية`} />
        <KPI label="المحجوز" icon="lock" value={phase === 'ready' ? formatQty(sum('reserved')) : '—'} sub="لطلبات مفتوحة" />
        <KPI label="قيد النقل" icon="truck" value={phase === 'ready' ? formatQty(sum('in_transit')) : '—'} sub="في تحويلات مشحونة" />
        <button onClick={() => set({ low: f.low ? undefined : '1' })} className="text-right">
          <KPI label="تحت حد إعادة الطلب" icon="alert" value={phase === 'ready' ? rows.filter(r => r.is_low_stock).length : '—'} sub={f.low ? 'الفلتر مفعّل · اضغط للإلغاء' : 'اضغط لعرضها فقط'} />
        </button>
      </div>

      <Card stagger={3}>
        <CardHead title="الأرصدة" meta={phase === 'ready' ? `${rows.length} سطر` : null}
          right={<span className="text-[11.5px] c-muted flex items-center gap-1.5"><Icon n="eye" s={13} /> للعرض فقط</span>} />
        <FilterBar active={active} onReset={() => go('stock', {})}>
          <FItem label="الفرع"><Sel small value={effBranch} onChange={v => set({ branchId: v || undefined })} placeholder="كل الفروع" disabled={restriction.locked} options={branchOptions} className="!bg-[var(--bg-card)]" /></FItem>
          <FItem label="الموقع" w="w-40"><Sel small value={f.locationId} onChange={v => set({ locationId: v || undefined })} placeholder={effBranch ? 'كل المواقع' : 'اختر فرعًا أولًا'} disabled={!effBranch} options={locOptions} className="!bg-[var(--bg-card)]" /></FItem>
          <FItem label="الصنف / SKU" w="w-64"><VariantPicker small value={f.variantId} onChange={v => set({ variantId: v || undefined })} placeholder="كل الأصناف" allowClear /></FItem>
          <label className="flex items-center gap-2.5 px-3 py-2 rounded-full cursor-pointer mb-0.5" style={{ background: f.low ? 'var(--accent-orange-light)' : 'var(--bg-card)' }}>
            <Toggle on={f.low} onChange={v => set({ low: v ? '1' : undefined })} />
            <span className="text-[12px] font-medium c-primary">المخزون المنخفض فقط</span>
          </label>
        </FilterBar>
        <Phase phase={phase} cols={10} rows={7} error={error} onRetry={onRetry}
          empty={<EmptyState icon="box" title="لا توجد أرصدة مسجّلة بعد" sub="سجّل الكميات الافتتاحية عن طريق تسوية زيادة مع سبب واضح مثل «رصيد افتتاحي»."
            action={canAdjust && <button className="btn btn-dark btn-sm" onClick={() => go('adjust')}><Icon n="plus" s={13} /> إدخال رصيد افتتاحي</button>} />}>
          {rows.length === 0 ? <EmptyState icon="filter" title="لا توجد أرصدة تطابق الفلاتر" sub="غيّر الفلاتر أو امسحها لعرض كل الأرصدة." /> : (
            <div className="overflow-x-auto -mx-1 px-1">
              <table className="vt">
                <thead><tr>
                  <th>المنتج</th><th>SKU</th><th>الفرع</th><th>الموقع</th>
                  <th className="t-num">الموجود</th><th className="t-num">المحجوز</th><th className="t-num">التالف</th><th className="t-num">قيد النقل</th><th className="t-num">المتاح</th><th className="t-num">حد الطلب</th><th>آخر تحديث</th><th></th>
                </tr></thead>
                <tbody>
                  {rows.map(s => {
                    const low = !!s.is_low_stock;
                    const pct = Math.min(100, Number(s.reorder_level) ? Number(s.available) / (Number(s.reorder_level) * 2) * 100 : 100);
                    const v = s.variant || variantCache.get(Number(s.product_variant_id)) || {};
                    return (
                      <tr key={s.id}>
                        <td><div className="min-w-0"><div className="font-medium c-primary truncate">{pickName({ product_name_ar: v.product_name_ar, product_name_en: v.product_name_en, sku: v.sku || s.product_variant_id })}</div></div></td>
                        <td><Sku>{v.sku || `#${s.product_variant_id}`}</Sku></td>
                        <td className="c-secondary">{s.branch?.name || '—'}</td>
                        <td>{s.location ? <span className="text-[11.5px] c-secondary">{s.location.name}</span> : <span className="text-[11.5px] c-muted">بدون موقع</span>}</td>
                        <td className="t-num num font-semibold c-primary">{formatQty(s.on_hand)}</td>
                        <td className="t-num num c-secondary">{formatQty(s.reserved)}</td>
                        <td className="t-num num" style={{ color: Number(s.damaged) ? 'var(--badge-red-text)' : 'var(--text-muted)' }}>{formatQty(s.damaged)}</td>
                        <td className="t-num num" style={{ color: Number(s.in_transit) ? 'var(--badge-blue-text)' : 'var(--text-muted)' }}>{formatQty(s.in_transit)}</td>
                        <td className="t-num">
                          <div className="flex flex-col items-end gap-1">
                            <span className="flex items-center gap-1.5"><span className={`num font-bold ${low ? '' : 'c-primary'}`} style={low ? { color: 'var(--accent-orange)' } : {}}>{formatQty(s.available)}</span>{low && <Badge cls="b-amber" dot>منخفض</Badge>}</span>
                            <span className="w-16 h-1 rounded-full hatched overflow-hidden flex justify-end" style={{ direction: 'ltr' }}>
                              <span className="block h-full rounded-full" style={{ width: pct + '%', background: low ? 'var(--accent-orange)' : 'var(--text-primary)' }} />
                            </span>
                          </div>
                        </td>
                        <td className="t-num num c-muted">{formatQty(s.reorder_level)}</td>
                        <td className="num text-[11.5px] c-muted">{formatDate(s.updated_at)}</td>
                        <td>
                          <div className="flex items-center gap-0.5 justify-end">
                            <button className="icon-btn" title="حركات الصنف" onClick={() => go('moves', { variantId: s.product_variant_id, branchId: s.branch?.id, locationId: s.location?.id })}><Icon n="history" s={15} /></button>
                            {canAdjust && <button className="icon-btn" title="تسوية" onClick={() => go('adjust', { branchId: s.branch?.id, locId: s.location?.id, variantId: s.product_variant_id })}><Icon n="sliders" s={15} /></button>}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Phase>
        {meta && (meta.last_page > 1) && (
          <div className="flex items-center justify-between mt-4">
            <button className="btn btn-soft btn-sm" disabled={page <= 1} onClick={() => setPage(p => Math.max(1, p - 1))}>السابق</button>
            <span className="text-[12px] c-muted num">صفحة {page} من {meta.last_page}</span>
            <button className="btn btn-soft btn-sm" disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}>التالي</button>
          </div>
        )}
      </Card>
    </section>
  );
}
// NOTE: "المتاح" KPI is page-scoped on purpose — GET inventory returns one page
// and there is no summary endpoint, so a global total cannot be shown honestly.

// ---------------- 4. Adjustment ----------------
const ADJ_MAX = 100;
let __adjLineId = 1;
const newAdjLine = (p = {}) => ({ key: __adjLineId++, variantId: null, type: 'increase', qty: '', reason: '', ...p });

const ADJ_API_TYPES = {
  increase:         { label: 'زيادة',              cls: 'b-green' },
  decrease:         { label: 'نقصان',              cls: 'b-red'   },
  mark_damaged:     { label: 'تسجيل تالف',         cls: 'b-amber' },
  write_off_damaged:{ label: 'إعدام تالف',         cls: 'b-red'   },
  restore_damaged:  { label: 'استرجاع من التالف',  cls: 'b-blue'  },
};

function AdjustScreen({ go, params }) {
  const { toast, can, branchesQuery, variantCache } = useInv();
  const { keyFor, reset } = useIdempotencyKey();
  const [branchId, setBranchId] = useState(params.branchId ? Number(params.branchId) : null);
  const [locId, setLocId] = useState(params.locId ? Number(params.locId) : (params.locationId ? Number(params.locationId) : null));
  const [lines, setLines] = useState(() => [newAdjLine(params.variantId ? { variantId: Number(params.variantId) } : {})]);
  const [tried, setTried] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [srvErr, setSrvErr] = useState(null);
  const [rowErrs, setRowErrs] = useState({});
  const [opening, setOpening] = useState(false);
  const allowed = can('inventory.adjust');
  const restriction = useRestrictedBranch();
  const effBranch = restriction.locked ? restriction.branchId : branchId;

  const locsQuery = useBranchesWithLocations(effBranch);

  if (!allowed) return <NoPerm title="تسوية المخزون" perm="inventory.adjust" go={go} />;

  const upd = (key, p) => { setLines(ls => ls.map(l => l.key === key ? { ...l, ...p } : l)); setRowErrs({}); };
  const lineErr = l => {
    const e = {};
    if (!l.variantId) e.variantId = true;
    const qv = toLatinDigits(l.qty);
    if (!qv || !QTY_RE.test(qv) || !(Number(qv) > 0) || Number(qv) > QTY_MAX) e.qty = 'كمية موجبة حتى 4 منازل عشرية';
    if (!l.reason.trim()) e.reason = true;
    else if (l.reason.trim().length > 1000) e.reason = true;
    return e;
  };
  const clientErrs = lines.map(lineErr);
  const valid = effBranch && clientErrs.every(e => !Object.keys(e).length);
  const dupes = lines.filter((l, i) => l.variantId && lines.findIndex(x => x.variantId === l.variantId && x.type === l.type) !== i).length;

  const applyOpening = on => {
    setOpening(on);
    setLines(ls => ls.map(l => on ? { ...l, type: 'increase', reason: l.reason || 'رصيد افتتاحي' } : l));
  };

  const execute = async () => {
    if (busy) return; // double-click guard: one movement per key
    setBusy(true); setSrvErr(null); setRowErrs({});
    const payload = {
      branch_id: effBranch,
      ...(locId ? { location_id: locId } : {}),
      items: lines.map(l => ({ product_variant_id: l.variantId, adjustment_type: l.type, quantity: Number(toLatinDigits(l.qty)), reason: l.reason.trim() || null })),
    };
    try {
      const res = await adjustmentsApi.create(payload, keyFor(payload));
      reset(); // new key only after success
      setConfirm(false); setLines([newAdjLine()]); setTried(false);
      toast(res.message || `تم تنفيذ التسوية · ${lines.length} بند`);
      go('moves', { branchId: effBranch });
    } catch (e) {
      const ne = normalizeError(e);
      setRowErrs({ ...ne.fields, ...groupItemsErrors(ne.fields) });
      // Server messages are Arabic — shown verbatim. 409 busy-lock keeps the
      // dialog open so retry reuses the SAME key (no duplicate movement).
      setSrvErr(ne.message);
      setBusy(false);
    }
  };

  const totals = Object.keys(ADJ_API_TYPES).map(k => ({ k, n: lines.filter(l => l.type === k && l.variantId).length })).filter(x => x.n);
  const shared = branchesQuery.data || [];
  const branchOptions = (restriction.locked ? shared.filter(b => b.id === restriction.branchId) : shared.filter(b => b.is_active))
    .map(b => ({ value: b.id, label: `${b.name} · ${b.type === 'warehouse' ? 'مخزن' : 'فرع'}` }));
  const locOptions = (locsQuery.data || []).filter(l => l.is_active).map(l => ({ value: l.id, label: `${l.name} (${l.code})` }));
  const brName = shared.find(b => b.id === effBranch)?.name || '';
  const locCode = (locsQuery.data || []).find(l => l.id === locId)?.code || '';

  return (
    <section className="space-y-5">
      <PageHead title="تسوية المخزون" back={{ label: 'أرصدة المخزون', onClick: () => go('stock') }}
        sub="زيادة أو خصم كميات، أو نقلها من وإلى التالف. التسوية تُنفَّذ كعملية واحدة وتنعكس فورًا على الأرصدة والحركات." />

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
        <div className="lg:col-span-9 space-y-5">
          <Card stagger={2}>
            <CardHead title="بيانات العملية" />
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <Field label="الفرع / المخزن" req error={tried && !effBranch ? 'اختر الفرع' : rowErrs.branch_id}>
                <Sel value={effBranch} onChange={v => { if (!restriction.locked) { setBranchId(v || null); setLocId(null); } }} placeholder="اختر…" disabled={restriction.locked} options={branchOptions} className={tried && !effBranch ? 'err' : ''} />
              </Field>
              <Field label="موقع التخزين" hint="اختياري — يطبَّق على كل البنود" error={rowErrs.location_id}>
                <Sel value={locId} onChange={v => setLocId(v || null)} placeholder={effBranch ? 'بدون موقع محدد' : 'اختر الفرع أولًا'} disabled={!effBranch} options={locOptions} />
              </Field>
              <div>
                <span className="field-label">إدخال رصيد افتتاحي</span>
                <label className="v-subcard px-3.5 py-2 flex items-center justify-between cursor-pointer" style={{ borderRadius: 12 }}>
                  <span className="text-[12px] c-secondary">كل البنود = زيادة</span>
                  <Toggle on={opening} onChange={applyOpening} />
                </label>
              </div>
            </div>
            {opening && <div className="mt-4"><InlineAlert kind="blue" icon="info" title="رصيد افتتاحي">تُسجَّل الكميات الافتتاحية حاليًا كتسوية زيادة. اكتب سببًا واضحًا لكل بند حتى يسهل تتبّعها في سجل الحركات.</InlineAlert></div>}
          </Card>

          <Card stagger={3}>
            <CardHead title="البنود" meta={<span className="num">{lines.length} / {ADJ_MAX}</span>}
              right={<button className="btn btn-soft btn-sm" disabled={lines.length >= ADJ_MAX} onClick={() => setLines(ls => [...ls, newAdjLine(opening ? { reason: 'رصيد افتتاحي' } : {})])}><Icon n="plus" s={13} /> إضافة بند</button>} />
            {dupes > 0 && <div className="mb-3"><InlineAlert kind="amber" title="بنود مكررة">يوجد {dupes} بند مكرر بنفس SKU ونفس نوع التسوية — سيتم دمجها عند التنفيذ.</InlineAlert></div>}
            <div className="overflow-x-auto">
              <table className="vt">
                <thead><tr><th className="w-8">#</th><th style={{ minWidth: 280 }}>المنتج / المتغير</th><th style={{ minWidth: 170 }}>نوع التسوية</th><th style={{ minWidth: 120 }}>الكمية</th><th style={{ minWidth: 200 }}>السبب</th><th></th></tr></thead>
                <tbody>
                  {lines.map((l, i) => {
                    const e = { ...(tried ? clientErrs[i] : {}), ...(rowErrs[i] || {}) };
                    return (
                      <tr key={l.key} className="align-top">
                        <td className="num c-muted pt-4">{i + 1}</td>
                        <td>
                          <div className={e.variantId ? 'rounded-[12px] ring-1 ring-[var(--badge-red-text)]' : ''}><VariantPicker small value={l.variantId} onChange={v => upd(l.key, { variantId: v })} /></div>
                          {e.product_variant_id && <div className="text-[10.5px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{e.product_variant_id}</div>}
                        </td>
                        <td><Sel small value={l.type} onChange={v => upd(l.key, { type: v || 'increase' })} options={Object.entries(ADJ_API_TYPES).map(([k, t]) => ({ value: k, label: t.label }))} /></td>
                        <td>
                          <NumInput small value={l.qty} onChange={v => upd(l.key, { qty: v })} err={e.qty || e.quantity} placeholder="0.0000" />
                          {(e.qty || e.quantity) && <div className="text-[10.5px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{e.qty || e.quantity}</div>}
                        </td>
                        <td><input maxLength={1000} className={`inp inp-sm ${e.reason ? 'err' : ''}`} value={l.reason} onChange={ev => upd(l.key, { reason: ev.target.value })} placeholder="مثال: كسر أثناء التفريغ" />
                          {e.reason && typeof e.reason === 'string' && <div className="text-[10.5px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{e.reason}</div>}</td>
                        <td className="pt-3"><button className="icon-btn" disabled={lines.length === 1} onClick={() => setLines(ls => ls.filter(x => x.key !== l.key))}><Icon n="trash" s={14} /></button></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <div className="mt-3 text-[11px] c-muted flex items-center gap-1.5"><Icon n="info" s={12} /> الكميات موجبة دائمًا وتقبل حتى 4 منازل عشرية. اتجاه الحركة يحدده نوع التسوية.</div>
          </Card>
        </div>

        <div className="lg:col-span-3 lg:sticky lg:top-5">
          <Card stagger={4}>
            <h3 className="font-display text-[15px] font-semibold c-primary mb-3">ملخص</h3>
            <div className="space-y-2 text-[12.5px]">
              <div className="flex justify-between"><span className="c-secondary">الفرع</span><span className="font-medium c-primary">{brName || '—'}</span></div>
              <div className="flex justify-between"><span className="c-secondary">الموقع</span><span className="mono c-primary" dir="ltr">{locCode || '—'}</span></div>
              <div className="flex justify-between"><span className="c-secondary">عدد البنود</span><span className="num font-semibold c-primary">{lines.length}</span></div>
            </div>
            <div className="h-px my-4" style={{ background: 'var(--divider-color)' }} />
            <div className="space-y-1.5">
              {totals.length === 0 && <div className="text-[11.5px] c-muted">لم يتم اختيار أصناف بعد</div>}
              {totals.map(t => <div key={t.k} className="flex items-center justify-between"><Badge cls={ADJ_API_TYPES[t.k].cls}>{ADJ_API_TYPES[t.k].label}</Badge><span className="num text-[12px] c-primary font-semibold">{t.n}</span></div>)}
            </div>
            <div className="mt-5 space-y-2">
              <button className="btn btn-orange w-full" onClick={() => { setTried(true); if (valid) { setSrvErr(null); setRowErrs({}); setConfirm(true); } }}>
                <Icon n="check" s={14} w={2.2} /> تنفيذ التسوية
              </button>
              <button className="btn btn-soft w-full" onClick={() => go('stock')}>إلغاء</button>
            </div>
            {tried && !valid && <div className="text-[11px] mt-3" style={{ color: 'var(--badge-red-text)' }}>أكمل الحقول المطلوبة المظللة بالأحمر.</div>}
          </Card>
        </div>
      </div>

      <ConfirmDialog open={confirm} onClose={() => setConfirm(false)} onConfirm={execute} busy={busy} error={srvErr}
        title="تأكيد تنفيذ التسوية" confirmLabel="تنفيذ الآن"
        body={<div className="space-y-3">
          <p>سيتم تنفيذ <b className="c-primary num">{lines.length}</b> بند على <b className="c-primary">{brName}</b>{locCode && <> — موقع <span className="mono" dir="ltr">{locCode}</span></>}. لا يمكن تعديل أو حذف الحركات بعد التنفيذ؛ للتصحيح أنشئ تسوية عكسية.</p>
          <div className="v-subcard p-3 space-y-1.5 max-h-40 overflow-y-auto">
            {lines.filter(l => l.variantId).map((l, fi) => (
              <div key={l.key}>
                <div className="flex items-center justify-between text-[12px]">
                  <span className="flex items-center gap-2"><Sku>{variantCache.get(Number(l.variantId))?.sku || `#${l.variantId}`}</Sku><Badge cls={ADJ_API_TYPES[l.type].cls}>{ADJ_API_TYPES[l.type].label}</Badge></span>
                  <span className="num font-semibold c-primary">{formatQty(toLatinDigits(l.qty))}</span>
                </div>
                {(rowErrs[fi]?.quantity || rowErrs[fi]?.product_variant_id) && <div className="text-[11px] mt-1 text-right" style={{ color: 'var(--badge-red-text)' }}>{rowErrs[fi]?.quantity || rowErrs[fi]?.product_variant_id}</div>}
              </div>
            ))}
          </div>
        </div>} />
    </section>
  );
}

// ---------------- 5. Movements ----------------
function MovesScreen({ go, params }) {
  const { can, branchesQuery, variantCache } = useInv();
  const [f, setF] = useState({ branchId: params.branchId ? Number(params.branchId) : null, locId: params.locationId ? Number(params.locationId) : null, variantId: params.variantId ? Number(params.variantId) : null, type: null, from: '', to: '' });
  const [cursor, setCursor] = useState(null);
  const [prevCursors, setPrevCursors] = useState([]);
  const allowed = canAny(can, 'inventory.view');
  const set = p => { setF(x => {
    const next = { ...x, ...p };
    if (next.from && next.to && next.to < next.from) next.to = next.from;
    return next;
  }); setCursor(null); setPrevCursors([]); };
  const showCost = can('inventory.view_cost');
  const restriction = useRestrictedBranch();
  const effBranch = restriction.locked ? restriction.branchId : f.branchId;

  const apiParams = {
    branch_id: effBranch || undefined, location_id: f.locId || undefined,
    product_variant_id: f.variantId || undefined,
    type: f.type || undefined, from_date: f.from || undefined, to_date: f.to || undefined,
    per_page: 15, ...(cursor ? { cursor } : {}),
  };
  const query = useApi(signal => movementsApi.list(apiParams, signal), [JSON.stringify(apiParams)]);
  const locsQuery = useBranchesWithLocations(effBranch);

  if (!allowed) return <NoPerm title="حركات المخزون" perm="inventory.view" go={go} />;

  const rows = query.data?.data || [];
  const { phase, error } = apiPhase(query, rows.length === 0);
  const onRetry = () => query.reload();
  const shared = branchesQuery.data || [];
  const branchOptions = (restriction.locked ? shared.filter(b => b.id === restriction.branchId) : shared.filter(b => b.is_active))
    .map(b => ({ value: b.id, label: `${b.name} · ${b.type === 'warehouse' ? 'مخزن' : 'فرع'}` }));
  const locOptions = (locsQuery.data || []).filter(l => l.is_active).map(l => ({ value: l.id, label: `${l.name} (${l.code})` }));
  const active = (effBranch ? 1 : 0) + (f.locId ? 1 : 0) + (f.variantId ? 1 : 0) + (f.type ? 1 : 0) + (f.from ? 1 : 0) + (f.to ? 1 : 0);
  const nextCursor = query.data?.meta?.next_cursor || null;
  const prevCursor = query.data?.meta?.prev_cursor || null;
  const goNext = () => { if (nextCursor) { setPrevCursors(p => [...p, cursor]); setCursor(nextCursor); } };
  const goPrev = () => { const p = [...prevCursors]; const c = p.pop(); setPrevCursors(p); setCursor(prevCursor || c || null); };

  return (
    <section className="space-y-5">
      <PageHead title="حركات المخزون" sub="سجل غير قابل للتعديل لكل عملية أثّرت على الكميات." />
      <Card stagger={2}>
        <CardHead title="السجل" meta={<span className="flex items-center gap-1.5"><Icon n="lock" s={11} /> عرض فقط</span>}
          right={!showCost && <span className="text-[11px] c-muted flex items-center gap-1"><Icon n="lock" s={11} /> تكلفة الوحدة مخفية حسب صلاحيتك</span>} />
        <FilterBar active={active} onReset={() => { setF({ branchId: null, locId: null, variantId: null, type: null, from: '', to: '' }); setCursor(null); setPrevCursors([]); }}>
          <FItem label="الفرع"><Sel small value={effBranch} onChange={v => set({ branchId: v || null, locId: null })} placeholder="كل الفروع" disabled={restriction.locked} options={branchOptions} className="!bg-[var(--bg-card)]" /></FItem>
          <FItem label="الموقع" w="w-40"><Sel small value={f.locId} onChange={v => set({ locId: v || null })} placeholder={effBranch ? 'كل المواقع' : 'اختر فرعًا أولًا'} disabled={!effBranch} options={locOptions} className="!bg-[var(--bg-card)]" /></FItem>
          <FItem label="الصنف / SKU" w="w-64"><VariantPicker small value={f.variantId} onChange={v => set({ variantId: v || null })} placeholder="كل الأصناف" allowClear /></FItem>
          <FItem label="نوع الحركة" w="w-40"><Sel small value={f.type} onChange={v => set({ type: v || null })} placeholder="كل الأنواع" options={Object.entries(MOVE_TYPE_LABELS).map(([value, t]) => ({ value, label: t.ar }))} className="!bg-[var(--bg-card)]" /></FItem>
          <FItem label="من تاريخ" w="w-36"><input type="date" value={f.from} onChange={e => set({ from: e.target.value })} className="inp inp-sm num !bg-[var(--bg-card)]" /></FItem>
          <FItem label="إلى تاريخ" w="w-36"><input type="date" value={f.to} onChange={e => set({ to: e.target.value })} min={f.from || undefined} className="inp inp-sm num !bg-[var(--bg-card)]" /></FItem>
        </FilterBar>

        <Phase phase={phase} cols={9} rows={8} error={error} onRetry={onRetry}
          empty={<EmptyState icon="history" title="لا توجد حركات بعد" sub="ستظهر هنا كل عمليات التسوية والتحويل والجرد واستلام الإنتاج بمجرد تنفيذها." />}>
          {rows.length === 0 ? <EmptyState icon="filter" title="لا توجد حركات تطابق الفلاتر" sub="وسّع نطاق التاريخ أو امسح بعض الفلاتر." /> : (
            <>
              <div className="overflow-x-auto">
                <table className="vt">
                  <thead><tr>
                    <th>التاريخ</th><th>المرجع</th><th>SKU</th><th>الفرع</th><th>الموقع</th><th>نوع الحركة</th>
                    <th className="t-num">الكمية</th><th className="t-num">الرصيد قبل ← بعد</th>{showCost && <th className="t-num">تكلفة الوحدة</th>}<th>المنفّذ</th><th>ملاحظات</th>
                  </tr></thead>
                  <tbody>
                    {rows.map(m => {
                      const t = MOVE_TYPE_LABELS[m.type] || { ar: m.type, cls: 'b-gray' };
                      const qty = Number(m.quantity);
                      return (
                        <tr key={m.id}>
                          <td className="num text-[11.5px] c-secondary">{formatDate(m.created_at, { includeTime: true })}</td>
                          <td><span className="mono text-[11.5px] font-semibold c-orange" dir="ltr">{m.reference_number}</span></td>
                          <td><div className="flex flex-col gap-0.5"><Sku>{m.variant?.sku || `#${m.product_variant_id}`}</Sku><span className="text-[10.5px] c-muted">{variantDisplayName(variantCache, m.product_variant_id, null)}</span></div></td>
                          <td className="c-secondary">{m.branch?.name || '—'}</td>
                          <td className="mono text-[11.5px] c-secondary" dir="ltr">{m.location?.name || '—'}</td>
                          <td><Badge cls={t.cls}>{t.ar}</Badge></td>
                          <td className="t-num num font-bold" style={{ color: qty > 0 ? 'var(--badge-green-text)' : qty < 0 ? 'var(--badge-red-text)' : 'var(--text-muted)' }}>{qty > 0 ? '+' : qty < 0 ? '−' : ''}{formatQty(Math.abs(qty))}</td>
                          <td className="t-num num text-[12px]"><span className="c-muted">{formatQty(m.balance_before)}</span> <span className="c-muted mx-1">→</span> <span className="font-semibold c-primary">{formatQty(m.balance_after)}</span></td>
                          {showCost && <td className="t-num num c-secondary">{m.unit_cost != null ? formatCurrency(m.unit_cost) : '—'}</td>}
                          <td className="c-secondary">{m.creator_name || '—'}</td>
                          <td className="text-[11.5px] c-muted max-w-[160px] truncate">{m.notes || '—'}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <div className="flex items-center justify-between mt-4 pt-4" style={{ borderTop: '1px solid var(--divider-color)' }}>
                <span className="text-[11.5px] c-muted">عرض <span className="num">{rows.length}</span> حركة · التنقل بالمؤشر</span>
                <div className="flex items-center gap-2">
                  <button className="btn btn-soft btn-sm" disabled={!prevCursor && prevCursors.length === 0 && !cursor} onClick={goPrev}><Icon n="chevR" s={13} /> السابق</button>
                  <button className="btn btn-dark btn-sm" disabled={!nextCursor} onClick={goNext}>التالي <Icon n="chevL" s={13} /></button>
                </div>
              </div>
            </>
          )}
        </Phase>
      </Card>
    </section>
  );
}

function NoPerm({ title, perm, go, message }) {
  return (
    <section className="space-y-5">
      {title && <PageHead title={title} />}
      <Card><EmptyState icon="lock" title="لا تملك صلاحية الوصول لهذه الشاشة" sub={message || `تتطلب هذه العملية صلاحية «${perm}». تواصل مع مدير النظام لمنحك الصلاحية.`}
        action={go && <button className="btn btn-soft btn-sm" onClick={() => go('stock')}>العودة لأرصدة المخزون</button>} /></Card>
    </section>
  );
}

// ============ 6. Stock transfers ============

const TR_FLOW = ['draft', 'approved', 'shipped', 'received'];
let __trKey = 1;
const newTrLine = (p = {}) => ({ key: __trKey++, variantId: null, fromLoc: null, toLoc: null, req: '', ...p });


function TrStatus({ s }) { const t = TRANSFER_STATUS[s]; return <Badge cls={({ draft: 'b-gray', approved: 'b-blue', shipped: 'b-amber', received: 'b-green', cancelled: 'b-red' })[s] || 'b-gray'} dot>{t || s}</Badge>; }

function TransfersScreen({ go }) {
  const { can, branchesQuery } = useInv();
  const [st, setSt] = useState('all');
  const [q, setQ] = useState('');
  const [fromF, setFromF] = useState(null);
  const [toF, setToF] = useState(null);
  const allowed = canAny(can, 'transfers.view', 'transfers.manage');

  // Small cardinality like branches: fetch all pages once (per_page=100) so the
  // KPIs are exact and status/branch/search filters run client-side.
  const query = useApi(signal => (async () => {
    const all = []; let page = 1; let last = 1;
    do {
      const res = await transfersApi.list({ per_page: 100, page, from_branch_id: fromF || undefined, to_branch_id: toF || undefined, status: st === 'all' ? undefined : st }, signal);
      all.push(...(res.data || [])); last = res.meta?.last_page || 1; page += 1;
    } while (page <= last);
    return all;
  })(), [fromF, toF, st]);

  if (!allowed) return <NoPerm title="التحويلات المخزنية" perm="transfers.view" go={go} />;

  const list = query.data || [];
  const { phase, error } = apiPhase(query, list.length === 0);
  const onRetry = () => query.reload();
  const ql = q.trim().toLowerCase();
  const rows = list.filter(t => (st === 'all' || t.status === st)
    && (!fromF || t.from_branch?.id === fromF) && (!toF || t.to_branch?.id === toF)
    && (!ql || (t.transfer_number || '').toLowerCase().includes(ql)));
  const cnt = k => list.filter(t => t.status === k).length;
  const active = (fromF ? 1 : 0) + (toF ? 1 : 0) + (ql ? 1 : 0) + (st !== 'all' ? 1 : 0);
  const shared = branchesQuery.data || [];
  const branchOptions = shared.filter(b => b.is_active).map(b => ({ value: b.id, label: `${b.name} · ${b.type === 'warehouse' ? 'مخزن' : 'فرع'}` }));
  const canCreate = canAny(can, 'transfers.create', 'transfers.manage');

  return (
    <section className="space-y-5">
      <PageHead title="التحويلات المخزنية" sub="نقل البضاعة بين فرعين أو مخزنين عبر مراحل: مسودة ← اعتماد ← شحن ← استلام."
        actions={canCreate && <button className="btn btn-dark" onClick={() => go('transfer-edit', {})}><Icon n="plus" s={14} w={2.2} /> تحويل جديد</button>} />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5 stagger-item stagger-2">
        <KPI label="مسودات" icon="edit" value={phase === 'ready' ? cnt('draft') : '—'} sub="بانتظار الاعتماد" />
        <KPI label="معتمدة" icon="check" value={phase === 'ready' ? cnt('approved') : '—'} sub="جاهزة للشحن" />
        <KPI hot label="في الطريق" icon="truck" value={phase === 'ready' ? cnt('shipped') : '—'} sub="بانتظار الاستلام" />
        <KPI label="مستلمة" icon="inbox" value={phase === 'ready' ? cnt('received') : '—'} sub="تحويلات مستلمة" />
      </div>

      <Card stagger={3}>
        <CardHead title="القائمة" right={<>
          <div className="seg">
            <button className={st === 'all' ? 'on' : ''} onClick={() => setSt('all')}>الكل</button>
            {Object.entries(TRANSFER_STATUS).map(([k, t]) => <button key={k} className={st === k ? 'on' : ''} onClick={() => setSt(k)}>{t}</button>)}
          </div>
          <SearchPill value={q} onChange={setQ} placeholder="رقم التحويل" w="w-32" />
        </>} />
        <FilterBar active={active} onReset={() => { setFromF(null); setToF(null); setQ(''); setSt('all'); }}>
          <FItem label="من فرع"><Sel small value={fromF} onChange={v => setFromF(v || null)} placeholder="كل الفروع" options={branchOptions} className="!bg-[var(--bg-card)]" /></FItem>
          <FItem label="إلى فرع"><Sel small value={toF} onChange={v => setToF(v || null)} placeholder="كل الفروع" options={branchOptions} className="!bg-[var(--bg-card)]" /></FItem>
        </FilterBar>
        <Phase phase={phase} cols={6} error={error} onRetry={onRetry}
          empty={<EmptyState icon="swap" title="لا توجد تحويلات" sub="أنشئ أول تحويل لنقل الكميات من المخزن الرئيسي إلى الفروع."
            action={canCreate && <button className="btn btn-dark btn-sm" onClick={() => go('transfer-edit', {})}><Icon n="plus" s={13} /> تحويل جديد</button>} />}>
          {rows.length === 0 ? <EmptyState icon="filter" title="لا توجد تحويلات بهذه الحالة" /> : (
            <table className="vt">
              <thead><tr><th>رقم التحويل</th><th>المصدر ← الوجهة</th><th>التقدّم</th><th>الحالة</th><th>التاريخ</th><th></th></tr></thead>
              <tbody>
                {rows.map(t => {
                  const idx = TR_FLOW.indexOf(t.status);
                  return (
                    <tr key={t.id} className="cursor-pointer" onClick={() => go('transfer', { id: t.id })}>
                      <td><span className="mono text-[12px] font-semibold c-primary" dir="ltr">{t.transfer_number}</span></td>
                      <td>
                        <div className="flex items-center gap-2">
                          <span className="font-medium c-primary">{t.from_branch?.name || '—'}</span>
                          <Icon n="arrowL" s={14} className="c-orange" />
                          <span className="font-medium c-primary">{t.to_branch?.name || '—'}</span>
                        </div>
                      </td>
                      <td>
                        <div className="flex items-center gap-1 w-28">
                          {TR_FLOW.slice(1).map((s, i) => <span key={s} className="h-1.5 flex-1 rounded-full" style={{ background: t.status === 'cancelled' ? 'var(--badge-red-bg)' : i < idx ? 'var(--accent-orange)' : 'var(--badge-gray-bg)' }} />)}
                        </div>
                      </td>
                      <td><TrStatus s={t.status} /></td>
                      <td className="num text-[11.5px] c-secondary">{formatDate(t.created_at)}</td>
                      <td><Icon n="chevL" s={15} className="c-muted" /></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </Phase>
      </Card>
    </section>
  );
}
// NOTE: the transfer list resource omits line items (TransferService::paginate
// loads fromBranch/toBranch/creator only), so the old "items count" column was
// dropped — the contract is the source of truth, not the mock.

function TransferEdit({ go, params }) {
  const { toast, can, branchesQuery, variantCache } = useInv();
  const restriction = useRestrictedBranch();
  const { keyFor, reset } = useIdempotencyKey();
  const editId = params.id ? Number(params.id) : null;
  const [from, setFrom] = useState(restriction.locked ? restriction.branchId : null);
  const [to, setTo] = useState(null);
  const [note, setNote] = useState('');
  const [lines, setLines] = useState(() => [newTrLine()]);
  const [tried, setTried] = useState(false);
  const [busy, setBusy] = useState(false);
  const [srvErr, setSrvErr] = useState(null);
  const [rowErrs, setRowErrs] = useState({});
  const [loaded, setLoaded] = useState(!editId);
  const allowed = editId ? canAny(can, 'transfers.update', 'transfers.manage') : canAny(can, 'transfers.create', 'transfers.manage');

  const showQuery = useApi(signal => (editId ? transfersApi.show(editId, signal) : Promise.resolve(null)), [editId || 0]);
  useEffect(() => {
    const t = showQuery.data;
    if (editId && t && !loaded) {
      setFrom(t.from_branch?.id ?? null); setTo(t.to_branch?.id ?? null); setNote(t.notes || '');
      setLines((t.items || []).map(it => newTrLine({ variantId: it.product_variant_id, fromLoc: it.from_location?.id ?? null, toLoc: it.to_location?.id ?? null, req: it.requested_qty != null ? String(it.requested_qty) : '' })));
      cacheVariantNames(variantCache, (t.items || []).map(it => ({ id: it.product_variant_id, sku: it.sku })));
      setLoaded(true);
    }
  }, [showQuery.data]);
  const fromLocsQuery = useBranchesWithLocations(from);
  const toLocsQuery = useBranchesWithLocations(to);

  if (!allowed) return <NoPerm title={editId ? 'تعديل التحويل' : 'تحويل جديد'} perm={editId ? 'transfers.update' : 'transfers.create'} go={go} />;

  const t = showQuery.data;
  const same = from && to && from === to;
  const upd = (key, p) => { setLines(ls => ls.map(l => l.key === key ? { ...l, ...p } : l)); setRowErrs({}); };
  const lineBad = l => {
    if (!l.variantId) return true;
    const qv = toLatinDigits(l.req);
    return !QTY_RE.test(qv) || !(Number(qv) > 0) || Number(qv) > QTY_MAX;
  };
  const valid = from && to && !same && lines.length && lines.length <= 100 && lines.every(l => !lineBad(l));

  const save = async () => {
    setTried(true); if (!valid || busy || (editId && (!t || t.status !== 'draft'))) return;
    setBusy(true); setSrvErr(null); setRowErrs({});
    const payload = {
      from_branch_id: from, to_branch_id: to, notes: note.trim() || null,
      items: lines.map(l => ({
        product_variant_id: l.variantId,
        ...(l.fromLoc ? { from_location_id: l.fromLoc } : {}),
        ...(l.toLoc ? { to_location_id: l.toLoc } : {}),
        requested_qty: Number(toLatinDigits(l.req)),
      })),
    };
    try {
      if (editId) {
        // PUT replaces items wholesale (TransferService::update) and is allowed in draft only.
        await transfersApi.update(editId, payload);
        toast('تم حفظ التعديلات'); go('transfer', { id: editId });
      } else {
        const res = await transfersApi.create(payload, keyFor(payload));
        reset(); // new key only after success
        toast(res.message || 'تم إنشاء التحويل بنجاح'); go('transfer', { id: res.data.id });
      }
    } catch (e) {
      const ne = normalizeError(e);
      setRowErrs({ ...ne.fields, ...groupItemsErrors(ne.fields) });
      setSrvErr(ne.message);
      setBusy(false);
    }
  };

  const shared = branchesQuery.data || [];
  const branchOptions = shared.filter(b => b.is_active).map(b => ({ value: b.id, label: `${b.name} · ${b.type === 'warehouse' ? 'مخزن' : 'فرع'}` }));
  const fromLocOpts = (fromLocsQuery.data || []).filter(l => l.is_active).map(l => ({ value: l.id, label: `${l.name} (${l.code})` }));
  const toLocOpts = (toLocsQuery.data || []).filter(l => l.is_active).map(l => ({ value: l.id, label: `${l.name} (${l.code})` }));
  const { phase, error } = editId ? apiPhase(showQuery, !t) : { phase: 'ready', error: undefined };

  return (
    <section className="space-y-5">
      <PageHead title={editId ? `تعديل ${t?.transfer_number || ''}` : 'تحويل جديد'} back={{ label: 'التحويلات المخزنية', onClick: () => go(editId ? 'transfer' : 'transfers', editId ? { id: editId } : {}) }}
        actions={<><button className="btn btn-soft" onClick={() => go('transfers')}>إلغاء</button><button className="btn btn-dark" onClick={save} disabled={busy}>{busy && <span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" />} حفظ كمسودة</button></>} />

      <Phase phase={phase} cols={4} error={error} onRetry={() => showQuery.reload()} onBack={() => go('transfers')}>
        {editId && t && t.status !== 'draft' ? (
          <Card><EmptyState icon="lock" title="التعديل مسموح في المسودة فقط" sub={`هذا التحويل بحالة «${TRANSFER_STATUS[t.status] || t.status}» ولا يقبل التعديل.`}
            action={<button className="btn btn-dark btn-sm" onClick={() => go('transfer', { id: editId })}>فتح التحويل</button>} /></Card>
        ) : (<>
          <Card stagger={2}>
            <div className="grid grid-cols-1 md:grid-cols-[1fr_auto_1fr] gap-4 items-end">
              <Field label="فرع المصدر" req error={tried && !from ? 'مطلوب' : rowErrs.from_branch_id}>
                <Sel value={from} disabled={restriction.locked} onChange={v => { setFrom(v || null); setLines(ls => ls.map(l => ({ ...l, fromLoc: null }))); }} placeholder="من…" options={branchOptions.filter(b => !restriction.locked || b.value === restriction.branchId)} className={(tried && !from) || same ? 'err' : ''} />
              </Field>
              <button type="button" disabled={restriction.locked} onClick={() => { setFrom(to); setTo(from); setLines(ls => ls.map(l => ({ ...l, fromLoc: l.toLoc, toLoc: l.fromLoc }))); }} className="w-10 h-10 rounded-full v-subcard flex items-center justify-center c-orange mx-auto mb-0.5" title="تبديل">
                <Icon n="swap" s={16} />
              </button>
              <Field label="فرع الوجهة" req error={tried && !to ? 'مطلوب' : rowErrs.to_branch_id}>
                <Sel value={to} onChange={v => { setTo(v || null); setLines(ls => ls.map(l => ({ ...l, toLoc: null }))); }} placeholder="إلى…" options={branchOptions} className={(tried && !to) || same ? 'err' : ''} />
              </Field>
            </div>
            {same && <div className="mt-3"><InlineAlert kind="red" title="المصدر والوجهة متطابقان">العملية الحالية تشترط اختلاف فرع المصدر عن فرع الوجهة. للنقل بين مواقع داخل نفس الفرع استخدم التسوية.</InlineAlert></div>}
            <Field label="ملاحظات" className="mt-4"><textarea maxLength={2000} className="inp" rows={2} value={note} onChange={e => setNote(e.target.value)} placeholder="سبب التحويل أو تعليمات للشحن…" /></Field>
          </Card>

          <Card stagger={3}>
            <CardHead title="البنود" meta={<span className="num">{lines.length} / 100</span>} right={<button className="btn btn-soft btn-sm" disabled={lines.length >= 100} onClick={() => setLines(ls => [...ls, newTrLine()])}><Icon n="plus" s={13} /> إضافة بند</button>} />
            {srvErr && <div className="mb-3"><InlineAlert kind="red">{srvErr}</InlineAlert></div>}
            <div className="overflow-x-auto">
              <table className="vt">
                <thead><tr><th className="w-8">#</th><th style={{ minWidth: 280 }}>المتغير / SKU</th><th style={{ minWidth: 170 }}>موقع المصدر</th><th style={{ minWidth: 170 }}>موقع الوجهة</th><th style={{ minWidth: 130 }}>الكمية المطلوبة</th><th></th></tr></thead>
                <tbody>
                  {lines.map((l, i) => {
                    const re = rowErrs[i] || {};
                    return (
                      <tr key={l.key} className="align-top">
                        <td className="num c-muted pt-4">{i + 1}</td>
                        <td>
                          <div className={(tried && !l.variantId) || re.product_variant_id ? 'rounded-[12px] ring-1 ring-[var(--badge-red-text)]' : ''}><VariantPicker small value={l.variantId} onChange={v => upd(l.key, { variantId: v })} /></div>
                          {re.product_variant_id && <div className="text-[10.5px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{re.product_variant_id}</div>}
                        </td>
                        <td><Sel small value={l.fromLoc} onChange={v => upd(l.key, { fromLoc: v || null })} placeholder={from ? 'أي موقع' : '—'} disabled={!from} options={fromLocOpts} /></td>
                        <td><Sel small value={l.toLoc} onChange={v => upd(l.key, { toLoc: v || null })} placeholder={to ? 'موقع الاستلام الافتراضي' : '—'} disabled={!to} options={toLocOpts} /></td>
                        <td>
                          <NumInput small value={l.req} onChange={v => upd(l.key, { req: v })} err={(tried && lineBad(l)) || re.requested_qty} placeholder="0.0000" />
                          {re.requested_qty && <div className="text-[10.5px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{re.requested_qty}</div>}
                        </td>
                        <td className="pt-3"><button className="icon-btn" disabled={lines.length === 1} onClick={() => setLines(ls => ls.filter(x => x.key !== l.key))}><Icon n="trash" s={14} /></button></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Card>
        </>)}
      </Phase>
    </section>
  );
}

function TransferDetail({ go, params }) {
  const { toast, can, variantCache } = useInv();
  const restriction = useRestrictedBranch();
  const id = Number(params.id);
  const [stage, setStage] = useState(null); // 'approve' | 'ship' | 'receive'
  const [qtys, setQtys] = useState({});
  const [confirm, setConfirm] = useState(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const [conflict, setConflict] = useState(null);
  const [stageErrors, setStageErrors] = useState({});
  const [delBusy, setDelBusy] = useState(false);
  const canView = canAny(can, 'transfers.view', 'transfers.manage');

  const query = useApi(signal => transfersApi.show(id, signal).then(t => {
    cacheVariantNames(variantCache, (t.items || []).map(it => ({ id: it.product_variant_id, sku: it.sku })));
    return t;
  }), [id]);

  if (!canView) return <NoPerm title="تفاصيل التحويل" perm="transfers.view" go={go} />;

  const t = query.data;
  const { phase, error } = apiPhase(query, !t);
  const onRetry = () => { setConflict(null); query.reload(); };

  const stageMeta = {
    approve: { col: 'approved_qty', srcCol: 'requested_qty', label: 'اعتماد', perm: 'transfers.approve', hint: 'حدّد الكمية المعتمدة لكل بند. البند الغائب يأخذ الكمية المطلوبة تلقائيًا.' },
    ship:    { col: 'shipped_qty', srcCol: 'approved_qty', label: 'شحن', perm: 'transfers.ship', hint: 'أدخل الكمية المشحونة فعليًا. سيتم خصمها من المصدر وتظهر «قيد النقل».' },
    receive: { col: 'received_qty', srcCol: 'shipped_qty', label: 'استلام', perm: 'transfers.receive', hint: 'أدخل الكمية المستلمة فعليًا في الوجهة. أي فرق يُسجَّل كعجز تحويل.' },
  };
  const startStage = s => { const m = stageMeta[s]; setStageErrors({}); setErr(null); setStage(s); setQtys(Object.fromEntries((t.items || []).map((l, i) => [i, String(l[m.srcCol] ?? '')]))); };
  const idx = t ? TR_FLOW.indexOf(t.status) : -1;

  const stagePayload = () => {
    const m = stageMeta[stage];
    return {
      expected_version: t.version,
      items: (t.items || []).map((l, i) => ({ id: l.id, [m.col]: Number(toLatinDigits(qtys[i])) })),
    };
  };
  const commit = async () => {
    if (busy) return;
    setBusy(true); setErr(null);
    try {
      const res = await transfersApi[stage](id, stagePayload());
      setConfirm(null); setStage(null);
      toast(res.message || `تم ${stageMeta[stage].label} التحويل`);
      query.reload(); // the resource carries the new version
    } catch (e) {
      const ne = normalizeError(e);
      setStageErrors(groupItemsErrors(ne.fields));
      if (ne.status === 409) {
        // Version conflict: reload first, never auto-resend.
        setConfirm(null); setStage(null); setConflict(ne.message); setBusy(false); return;
      }
      setErr(ne.message);
    }
    setBusy(false);
  };
  const cancel = async () => {
    if (busy) return;
    setBusy(true); setErr(null);
    try {
      const res = await transfersApi.cancel(id);
      setConfirm(null); toast(res.message || 'تم إلغاء التحويل'); query.reload();
    } catch (e) { setErr(normalizeError(e).message); }
    setBusy(false);
  };
  const doDelete = async () => {
    if (delBusy) return;
    setDelBusy(true); setErr(null);
    try {
      await transfersApi.remove(id);
      toast('تم حذف التحويل'); go('transfers');
    } catch (e) { setErr(normalizeError(e).message); setDelBusy(false); }
  };

  const actions = [];
  const canSend = !restriction.locked || t?.from_branch?.id === restriction.branchId;
  const canReceive = !restriction.locked || t?.to_branch?.id === restriction.branchId;
  if (t && !stage) {
    if (t.status === 'draft' && canSend) {
      if (canAny(can, 'transfers.update', 'transfers.manage')) actions.push(<button key="e" className="btn btn-soft" onClick={() => go('transfer-edit', { id: t.id })}><Icon n="edit" s={14} /> تعديل</button>);
      if (can('transfers.approve')) actions.push(<button key="a" className="btn btn-dark" onClick={() => startStage('approve')}><Icon n="check" s={14} /> اعتماد</button>);
    }
    if (t.status === 'approved' && canSend && can('transfers.ship')) actions.push(<button key="s" className="btn btn-dark" onClick={() => startStage('ship')}><Icon n="truck" s={14} /> شحن</button>);
    if (t.status === 'shipped' && canReceive && can('transfers.receive')) actions.push(<button key="r" className="btn btn-orange" onClick={() => startStage('receive')}><Icon n="inbox" s={14} /> استلام</button>);
    if (canSend && ['draft', 'approved'].includes(t.status) && canAny(can, 'transfers.update', 'transfers.manage')) actions.unshift(<button key="c" className="btn btn-danger" onClick={() => { setErr(null); setConfirm('cancel'); }}>إلغاء التحويل</button>);
    if (canSend && t.status === 'draft' && canAny(can, 'transfers.delete', 'transfers.manage')) actions.push(<button key="d" className="btn btn-soft hover:!text-[var(--badge-red-text)]" disabled={delBusy} onClick={() => { setErr(null); setConfirm('delete'); }}><Icon n="trash" s={14} /> حذف</button>);
  }
  const nextNeedsPerm = t && !stage && actions.length === 0 && !['received', 'cancelled'].includes(t.status);

  const steps = [['draft', 'مسودة', 'edit'], ['approved', 'معتمد', 'check'], ['shipped', 'مشحون', 'truck'], ['received', 'مستلم', 'inbox']];
  const shortage = stage === 'receive' && t ? (t.items || []).reduce((a, l, i) => a + (Number(l.shipped_qty || 0) - (Number(toLatinDigits(qtys[i])) || 0)), 0) : 0;

  return (
    <section className="space-y-5">
      <PageHead title={t ? <span className="mono" dir="ltr">{t.transfer_number}</span> : 'تفاصيل التحويل'} back={{ label: 'التحويلات المخزنية', onClick: () => go('transfers') }}
        sub={t && <span className="flex items-center gap-2 flex-wrap">{t.from_branch?.name || '—'} <Icon n="arrowL" s={14} className="c-orange" /> {t.to_branch?.name || '—'} <span className="c-muted">·</span> <TrStatus s={t.status} /></span>}
        actions={actions.length ? actions : nextNeedsPerm ? <span className="inline-flex items-center gap-1.5 text-[12px] c-muted px-3.5 py-2 rounded-full v-subcard"><Icon n="lock" s={12} /> الخطوة التالية تتطلب صلاحية أخرى</span> : null} />

      {conflict && <InlineAlert kind="amber" title="تعارض — تم تعديل التحويل" onClose={() => setConflict(null)}>{conflict}<div className="mt-2"><button className="btn btn-dark btn-sm" onClick={onRetry}><Icon n="refresh" s={13} /> إعادة تحميل وراجعة الكميات</button></div></InlineAlert>}

      {err && !confirm && <InlineAlert kind="red">{err}</InlineAlert>}
      <Phase phase={phase} cols={6} rows={3} error={error} onRetry={onRetry} onBack={() => go('transfers')} empty={<Card><EmptyState icon="swap" title="لا توجد بنود" /></Card>}>
        {t && (<div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
          <div className="lg:col-span-8 space-y-5">
            <Card stagger={2} pad="p-6">
              {t.status === 'cancelled' ? (
                <InlineAlert kind="red" icon="x" title="تم إلغاء هذا التحويل">{t.notes || 'لا يمكن تنفيذ أي إجراء على تحويل ملغي.'}</InlineAlert>
              ) : (
                <div className="flex items-center gap-2">
                  {steps.map(([k, l, ic], i) => (
                    <Fragment key={k}>
                      <div className="flex flex-col items-center gap-1.5 shrink-0">
                        <span className={`w-10 h-10 rounded-full flex items-center justify-center ${i <= idx ? 'text-white' : 'c-muted'}`} style={{ background: i < idx ? 'var(--pill-active-bg)' : i === idx ? 'var(--accent-orange)' : 'var(--bg-subcard)', color: i < idx ? 'var(--pill-active-text)' : undefined }}>
                          <Icon n={i < idx ? 'check' : ic} s={16} w={2} />
                        </span>
                        <span className={`text-[11.5px] ${i === idx ? 'font-semibold c-primary' : 'c-secondary'}`}>{l}</span>
                      </div>
                      {i < steps.length - 1 && <div className={`step-line mb-5 ${i < idx ? 'done' : ''}`} />}
                    </Fragment>
                  ))}
                </div>
              )}
            </Card>

            <Card stagger={3}>
              <CardHead title="البنود" meta={stage ? `وضع ${stageMeta[stage].label}` : `${(t.items || []).length} بند`} />
              {stage && <div className="mb-3"><InlineAlert kind="blue" icon="info" title={`${stageMeta[stage].label} التحويل`}>{stageMeta[stage].hint}</InlineAlert></div>}
              <div className="overflow-x-auto">
                <table className="vt">
                  <thead><tr><th>المتغير</th><th>من ← إلى</th><th className="t-num">المطلوبة</th><th className="t-num">المعتمدة</th><th className="t-num">المشحونة</th><th className="t-num">المستلمة</th></tr></thead>
                  <tbody>
                    {(t.items || []).map((l, i) => {
                      const cell = (col) => {
                        if (stage && stageMeta[stage].col === col) {
                          const max = Number(l[stageMeta[stage].srcCol]);
                          const over = !QTY_RE.test(toLatinDigits(qtys[i])) || Number(toLatinDigits(qtys[i])) > Math.min(max, QTY_MAX);
                          const fieldError = stageErrors[i]?.[col];
                          return <td className="t-num" style={{ width: 110 }}><NumInput small value={qtys[i]} onChange={v => { setStageErrors({}); setQtys(q => ({ ...q, [i]: v })); }} err={over || fieldError} className="!bg-[var(--accent-orange-light)]" />{(over || fieldError) && <div className="text-[10px] mt-0.5" style={{ color: 'var(--badge-red-text)' }}>{fieldError || `الكمية من صفر إلى ${formatQty(max)} وبحد أقصى أربع خانات عشرية`}</div>}</td>;
                        }
                        const v = l[col]; const diff = col === 'received_qty' && v != null && l.shipped_qty != null && Number(v) !== Number(l.shipped_qty);
                        return <td className="t-num num" style={{ color: v == null ? 'var(--text-muted)' : diff ? 'var(--badge-red-text)' : 'var(--text-primary)', fontWeight: v == null ? 400 : 600 }}>{v == null ? '—' : formatQty(v)}{diff && <span className="text-[10px] block font-normal">عجز {formatQty(Number(l.shipped_qty) - Number(v))}</span>}</td>;
                      };
                      return (
                        <tr key={l.id}>
                          <td><div className="flex items-center gap-2.5"><Sku>{l.sku || `#${l.product_variant_id}`}</Sku><div className="min-w-0"><div className="font-medium c-primary truncate">{variantDisplayName(variantCache, l.product_variant_id, l.sku)}</div></div></div></td>
                          <td className="mono text-[11px] c-secondary" dir="ltr">{l.from_location ? l.from_location.name : '—'} → {l.to_location ? l.to_location.name : '—'}</td>
                          <td className="t-num num font-semibold c-primary">{formatQty(l.requested_qty)}</td>
                          {cell('approved_qty')}{cell('shipped_qty')}{cell('received_qty')}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              {stage && (
                <div className="flex items-center gap-2.5 mt-4 pt-4" style={{ borderTop: '1px solid var(--divider-color)' }}>
                  <button className="btn btn-orange" onClick={() => {
                    const m = stageMeta[stage];
                    if ((t.items || []).some((l, i) => !QTY_RE.test(toLatinDigits(qtys[i])) || Number(toLatinDigits(qtys[i])) > Math.min(QTY_MAX, Number(l[m.srcCol])))) {
                      setErr('راجع الكميات: مطلوب صفر أو أكثر، حتى كمية المرحلة السابقة وبحد أقصى أربع خانات عشرية.'); return;
                    }
                    setErr(null); setConfirm('stage');
                  }}><Icon n="check" s={14} w={2.2} /> تأكيد ال{stageMeta[stage].label}</button>
                  <button className="btn btn-soft" onClick={() => setStage(null)}>تراجع</button>
                </div>
              )}
            </Card>
          </div>

          <Card className="lg:col-span-4" stagger={4}>
            <h3 className="font-display text-[15px] font-semibold c-primary mb-3">التفاصيل</h3>
            <div className="space-y-3 text-[12.5px]">
              {[['المصدر', t.from_branch?.name || '—'], ['الوجهة', t.to_branch?.name || '—'], ['التاريخ', <span className="num">{formatDate(t.created_at)}</span>], ['أنشأه', t.creator_name || '—'], ['إصدار التحويل', <span className="num">v{t.version}</span>]].map(([k, v]) => (
                <div key={k} className="flex justify-between"><span className="c-secondary">{k}</span><span className="font-medium c-primary">{v}</span></div>
              ))}
            </div>
            <div className="v-subcard p-3.5 mt-4 text-[12px] c-secondary">{t.notes || <span className="c-muted">لا توجد ملاحظات</span>}</div>
            <div className="mt-4 space-y-2">
              {[['requested_qty', 'المطلوب'], ['approved_qty', 'المعتمد'], ['shipped_qty', 'المشحون'], ['received_qty', 'المستلم']].map(([k, l]) => {
                const total = (t.items || []).reduce((a, x) => a + Number(x[k] || 0), 0); const req = (t.items || []).reduce((a, x) => a + Number(x.requested_qty || 0), 0);
                return (
                  <div key={k}>
                    <div className="flex justify-between text-[11.5px] mb-1"><span className="c-secondary">{l}</span><span className="num font-semibold c-primary">{formatQty(total)}</span></div>
                    <div className="h-1.5 rounded-full hatched overflow-hidden"><div className="h-full rounded-full bar-hatched-orange" style={{ width: (req ? total / req * 100 : 0) + '%' }} /></div>
                  </div>
                );
              })}
            </div>
          </Card>
        </div>)}
      </Phase>

      <ConfirmDialog open={confirm === 'stage'} onClose={() => setConfirm(null)} onConfirm={commit} busy={busy} error={err}
        title={stage ? `تأكيد ${stageMeta[stage].label} ${t?.transfer_number || ''}` : ''} confirmLabel="تأكيد"
        body={<div className="space-y-2">
          <p>{stage === 'ship' ? 'سيتم خصم الكميات المشحونة من رصيد المصدر وإضافتها كـ«قيد النقل» حتى يتم الاستلام.' : stage === 'receive' ? 'سيتم إضافة الكميات المستلمة إلى رصيد الوجهة وإغلاق التحويل.' : 'بعد الاعتماد لا يمكن تعديل بنود التحويل.'}</p>
          {shortage > 0 && <InlineAlert kind="amber" title="يوجد عجز في الاستلام">الفرق بين المشحون والمستلم {formatQty(shortage)} — سيُسجَّل كعجز تحويل.</InlineAlert>}
        </div>} />
      <ConfirmDialog open={confirm === 'cancel'} onClose={() => setConfirm(null)} onConfirm={cancel} busy={busy} error={err} danger title="إلغاء التحويل؟" confirmLabel="إلغاء التحويل"
        body={<>سيتم إلغاء <span className="mono c-primary" dir="ltr">{t?.transfer_number}</span> نهائيًا ولن يمكن إعادة تفعيله.</>} />
      <ConfirmDialog open={confirm === 'delete'} onClose={() => setConfirm(null)} onConfirm={doDelete} busy={delBusy} error={err} danger title="حذف مسودة التحويل؟" confirmLabel="حذف نهائي"
        body={<>سيتم حذف <span className="mono c-primary" dir="ltr">{t?.transfer_number}</span> نهائيًا. الحذف مسموح للمسودة فقط.</>} />
    </section>
  );
}

// ============ Shared Vortix UI primitives (RTL) ============


const InvCtx = createContext(null);
const useInv = () => useContext(InvCtx);

// Dropdowns share successful location reads for this authenticated inventory
// session. Branch/location writes invalidate the context cache before reloading.
export function useBranchesWithLocations(branchId) {
  const { branchesQuery, locationCache } = useInv();
  const query = useApi(async signal => {
    if (!branchId) return [];
    if (locationCache.has(Number(branchId))) return locationCache.get(Number(branchId));
    const rows = await fetchAllLocations(Number(branchId), signal);
    if (!signal.aborted) locationCache.set(Number(branchId), rows);
    return rows;
  }, [branchId || 0, branchesQuery.data]);
  return { ...query, branches: (branchesQuery.data || []).filter(b => b.is_active) };
}


// ---------- Inventory API layer (Phases 1-2) ----------
// All inventory screens are API-backed. Screens
// use catalog/useApi + @alfarid/api-client with the shared context cache below.

// Branch-scoped users (branch_id set, not super admin, no inventory.all_branches)
// are locked to their branch: the backend rejects anything else, and the UI
// hides other branches from dropdowns and disables the branch switcher.
function useRestrictedBranch() {
  const user = useAuthStore(s => s.user);
  const perms = user?.permissions;
  const elevated = hasPermission(perms, '*') || hasPermission(perms, 'inventory.all_branches')
    || (user?.roles || []).some(r => /super[\s_-]?admin/i.test(String(r)));
  const branchId = user?.branch_id ?? null;
  if (branchId == null || elevated) return { branchId: null, locked: false };
  return { branchId: Number(branchId), locked: true };
}

// Variant name cache: list endpoints (movements/transfers/stocktakes) return SKU
// only, so every variant seen from any source (options, StockLevel.variant) is
// cached here and reused for display. Never one request per row.
function cacheVariantNames(cache, list) {
  (list || []).forEach(v => {
    if (v && v.id != null) {
      const old = cache.get(Number(v.id)) || {};
      cache.set(Number(v.id), { ...old, name_ar: v.product_name_ar ?? v.name_ar ?? old.name_ar, name_en: v.product_name_en ?? v.name_en ?? old.name_en, sku: v.sku ?? old.sku });
    }
  });
}
function cacheVariantOptions(cache, options) {
  options.forEach(v => {
    const old = cache.get(Number(v.id)) || {};
    const parts = (v.label || '').split(' / ');
    const sku = parts.pop();
    cache.set(Number(v.id), { ...old, label: v.label, name_ar: old.name_ar || parts.join(' / '), sku: old.sku || sku });
  });
}
function variantDisplayName(cache, id, sku) {
  const hit = cache.get(Number(id));
  if (hit) return hit.name_ar || hit.name_en || hit.sku || sku || '—';
  return sku || '—';
}

// ---------- Icons (stroke, 1.9 like Vortix) ----------
const IP = {
  plus: 'M12 5v14M5 12h14',
  edit: 'M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
  search: 'M11 4a7 7 0 100 14 7 7 0 000-14zM20 20l-3.5-3.5',
  filter: 'M4 6h16M7 12h10M10 18h4',
  x: 'M6 6l12 12M18 6L6 18',
  check: 'M5 12l5 5L20 7',
  chevL: 'M15 18l-6-6 6-6',
  chevR: 'M9 6l6 6-6 6',
  chevD: 'M19 9l-7 7-7-7',
  pin: 'M12 21s-7-6.2-7-11.5A7 7 0 0119 9.5C19 14.8 12 21 12 21zM12 7a2.5 2.5 0 100 5 2.5 2.5 0 000-5z',
  store: 'M4 10h16v10H4zM3 10l2-6h14l2 6M9 20v-5h6v5',
  warehouse: 'M3 21V9l9-5 9 5v12M7 21v-8h10v8M7 17h10',
  box: 'M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3zM12 12l8-4.5M12 12v9M12 12L4 7.5',
  layers: 'M12 3l9 5-9 5-9-5 9-5zM3 13l9 5 9-5M3 17.5l9 5 9-5',
  sliders: 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0M14 4v4M8 10v4M16 16v4',
  history: 'M3 12a9 9 0 109-9 9 9 0 00-6.4 2.6L3 8M3 3v5h5M12 7v5l3 2',
  swap: 'M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7',
  clipboard: 'M9 4h6v3H9zM7 5H5v16h14V5h-2M9 12l2 2 4-4',
  factory: 'M3 21V10l6 4V10l6 4V6h6v15H3zM7 17h2M12 17h2M17 17h2',
  alert: 'M12 9v4M12 17h.01M10.3 3.9L2 18a2 2 0 001.7 3h16.6a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z',
  refresh: 'M4 4v6h6M20 20v-6h-6M5.6 15a7 7 0 0011.8 2.4L20 14M4 10l2.6-3.4A7 7 0 0118.4 9',
  lock: 'M6 11h12v10H6zM8 11V7a4 4 0 018 0v4',
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 9a3 3 0 100 6 3 3 0 000-6z',
  more: 'M12 6h.01M12 12h.01M12 18h.01',
  truck: 'M3 6h11v10H3zM14 10h4l3 3v3h-7M7 19a2 2 0 100-4 2 2 0 000 4zM17 19a2 2 0 100-4 2 2 0 000 4z',
  inbox: 'M3 13l3-8h12l3 8v6H3v-6zM3 13h5l1 3h6l1-3h5',
  barcode: 'M4 6v12M7 6v12M10 6v12M14 6v12M17 6v12M20 6v12',
  power: 'M12 3v8M6.3 7a8 8 0 1011.4 0',
  arrowL: 'M19 12H5M11 6l-6 6 6 6',
  sun: 'M12 8a4 4 0 100 8 4 4 0 000-8zM12 3v2m0 14v2M3 12h2m14 0h2m-2.6-6.4l-1.4 1.4M7 17l-1.4 1.4m0-12.8L7 7m10 10l1.4 1.4',
  moon: 'M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z',
  bell: 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 10-2 0v1.1A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
  help: 'M12 3a9 9 0 100 18 9 9 0 000-18zM9.5 9a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3.5m0 3h.01',
  logout: 'M15 21h4a2 2 0 002-2V5a2 2 0 00-2-2h-4M8 17l-5-5 5-5M3 12h12',
  info: 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 8h.01M12 11v5',
};
function Icon({ n, s = 16, w = 1.9, className = '', style }) {
  return (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={w} strokeLinecap="round" strokeLinejoin="round" className={className} style={style}>
      <path d={IP[n]} />
    </svg>
  );
}

// ---------- Layout ----------
function PageHead({ title, sub, actions, back }) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-4 pt-1 stagger-item stagger-1">
      <div>
        {back && (
          <button onClick={back.onClick} className="flex items-center gap-1.5 text-[12px] font-medium c-secondary hover:c-primary mb-2">
            <Icon n="chevR" s={14} /> {back.label}
          </button>
        )}
        <h1 className="font-display text-[34px] sm:text-[38px] font-medium tracking-[-0.02em] leading-tight c-primary">{title}</h1>
        {sub && <p className="text-[13.5px] mt-1 c-secondary">{sub}</p>}
      </div>
      {actions && <div className="flex items-center gap-2.5 flex-wrap">{actions}</div>}
    </div>
  );
}

function Card({ children, className = '', pad = 'p-5', stagger = 2 }) {
  return <div className={`v-card ${pad} stagger-item stagger-${stagger} ${className}`}>{children}</div>;
}

function CardHead({ title, meta, right }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div className="flex items-center gap-2.5">
        <h3 className="font-display text-[17px] font-semibold c-primary">{title}</h3>
        {meta && <span className="text-[11.5px] c-muted">| &nbsp;{meta}</span>}
      </div>
      {right && <div className="flex items-center gap-2.5 flex-wrap">{right}</div>}
    </div>
  );
}

function Badge({ cls = 'b-gray', dot, children }) {
  return <span className={`badge ${cls}`}>{dot && <span className="dot" />}{children}</span>;
}
const ActiveBadge = ({ on }) => <Badge cls={on ? 'b-green' : 'b-gray'} dot>{on ? 'نشط' : 'غير نشط'}</Badge>;

function SearchPill({ value, onChange, placeholder = 'بحث', w = 'w-40' }) {
  return (
    <div className="v-subcard px-3 py-1.5 rounded-full flex items-center gap-2">
      <Icon n="search" s={14} className="c-muted" />
      <input value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} className={`bg-transparent text-[12px] outline-none ${w} c-primary`} />
    </div>
  );
}

function Field({ label, req, hint, error, children, className = '' }) {
  return (
    <label className={`block ${className}`}>
      <span className="field-label">{label}{req && <span className="c-orange">*</span>}</span>
      {children}
      {error ? <span className="block text-[11px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{error}</span>
        : hint ? <span className="block text-[11px] mt-1 c-muted">{hint}</span> : null}
    </label>
  );
}

function Sel({ value, onChange, options, placeholder, className = '', small, disabled }) {
  return (
    <select disabled={disabled} value={value ?? ''} onChange={e => onChange(options.find(o => String(o.value) === e.target.value)?.value ?? null)} className={`inp ${small ? 'inp-sm' : ''} ${className}`}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
  );
}

function Toggle({ on, onChange, disabled }) {
  return <button type="button" disabled={disabled} onClick={() => onChange(!on)} className={`vtoggle ${on ? 'on' : ''} ${disabled ? 'opacity-40' : ''}`} />;
}


// ---------- States ----------
function SkeletonRows({ cols = 6, rows = 6 }) {
  return (
    <div className="space-y-0">
      <div className="v-subcard h-9 rounded-xl mb-1" />
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 px-3 py-3.5 v-border-b">
          {Array.from({ length: cols }).map((_, j) => (
            <div key={j} className="sk h-3" style={{ flex: j === 0 ? 2 : 1, opacity: 1 - i * 0.1 }} />
          ))}
        </div>
      ))}
    </div>
  );
}

function EmptyState({ icon = 'inbox', title, sub, action }) {
  return (
    <div className="v-subcard py-14 px-6 flex flex-col items-center text-center fade-in">
      <div className="w-14 h-14 rounded-full flex items-center justify-center mb-4 relative" style={{ background: 'var(--bg-card)' }}>
        <div className="absolute inset-0 rounded-full hatched opacity-60" />
        <Icon n={icon} s={22} className="c-secondary relative" />
      </div>
      <div className="font-display text-[15px] font-semibold c-primary">{title}</div>
      {sub && <div className="text-[12.5px] c-secondary mt-1 max-w-sm" style={{ textWrap: 'pretty' }}>{sub}</div>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}

function ErrorState({ msg = 'تعذّر الاتصال بالخادم. تحقّق من الاتصال وحاول مرة أخرى.', code = 'HTTP 503', onRetry }) {
  const { reload } = useInv();
  const retry = onRetry || reload;
  return (
    <div className="py-12 px-6 flex flex-col items-center text-center rounded-2xl fade-in" style={{ background: 'var(--badge-red-bg)' }}>
      <div className="w-12 h-12 rounded-full flex items-center justify-center mb-3" style={{ background: 'var(--bg-card)', color: 'var(--badge-red-text)' }}>
        <Icon n="alert" s={20} />
      </div>
      <div className="font-display text-[15px] font-semibold" style={{ color: 'var(--badge-red-text)' }}>حدث خطأ أثناء تحميل البيانات</div>
      <div className="text-[12.5px] c-secondary mt-1 max-w-md">{msg}</div>
      <div className="mono text-[10.5px] c-muted mt-2">{code}</div>
      <button onClick={retry} className="btn btn-dark btn-sm mt-4"><Icon n="refresh" s={13} /> إعادة المحاولة</button>
    </div>
  );
}

// Wrap list content with phase handling
// List phases: loading | ready | empty | denied (403) |
// notfound (404 + back to list) | conflict (409 banner + reload). Server
// messages are Arabic — rendered verbatim, never translated.
function Phase({ phase, cols, rows, empty, children, error, onRetry, onBack }) {
  const { go } = useInv();
  const back = onBack || (() => go('stock'));
  if (phase === 'loading') return <SkeletonRows cols={cols} rows={rows} />;
  if (phase === 'denied') return <NoPerm message={error?.message} go={back} />;
  if (phase === 'notfound') return <Card><EmptyState icon="search" title="العنصر غير موجود" sub={error?.message || 'ربما تم حذفه.'} action={<button className="btn btn-dark btn-sm" onClick={back}>رجوع للقائمة</button>} /></Card>;
  if (phase === 'conflict') return (<div className="space-y-4"><InlineAlert kind="amber" title="تعارض مع الخادم (409)"><p>{error?.message || 'تغيّرت البيانات على الخادم.'}</p><div className="mt-2"><button className="btn btn-dark btn-sm" onClick={onRetry}><Icon n="refresh" s={13} /> إعادة تحميل</button></div></InlineAlert>{children}</div>);
  if (phase === 'error') return <ErrorState msg={error?.message} code={error?.status ? `HTTP ${error.status}` : undefined} onRetry={onRetry} />;
  if (phase === 'empty') return empty;
  return children;
}

function InlineAlert({ kind = 'red', icon = 'alert', title, children, onClose }) {
  const map = { red: ['--badge-red-bg', '--badge-red-text'], amber: ['--badge-amber-bg', '--badge-amber-text'], blue: ['--badge-blue-bg', '--badge-blue-text'], green: ['--badge-green-bg', '--badge-green-text'] }[kind];
  return (
    <div className="rounded-2xl px-4 py-3 flex items-start gap-3 fade-in" style={{ background: `var(${map[0]})` }}>
      <span style={{ color: `var(${map[1]})` }} className="mt-0.5"><Icon n={icon} s={16} /></span>
      <div className="flex-1 text-[12.5px]">
        {title && <div className="font-semibold" style={{ color: `var(${map[1]})` }}>{title}</div>}
        <div className="c-primary opacity-80 mt-0.5">{children}</div>
      </div>
      {onClose && <button onClick={onClose} className="c-secondary"><Icon n="x" s={14} /></button>}
    </div>
  );
}

// ---------- Modal ----------
function Modal({ open, onClose, title, sub, children, footer, width = 'max-w-lg' }) {
  useEffect(() => {
    if (!open) return;
    const h = e => e.key === 'Escape' && onClose && onClose();
    window.addEventListener('keydown', h); return () => window.removeEventListener('keydown', h);
  }, [open]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 fade-in" style={{ background: 'rgba(0,0,0,.4)', backdropFilter: 'blur(4px)' }} onMouseDown={e => e.target === e.currentTarget && onClose && onClose()}>
      <div className={`v-card ${width} w-full shadow-2xl modal-in flex flex-col max-h-[92vh]`}>
        <div className="flex items-start justify-between gap-4 p-6 pb-4">
          <div>
            <h3 className="font-display text-[18px] font-semibold c-primary">{title}</h3>
            {sub && <p className="text-[12px] c-secondary mt-0.5">{sub}</p>}
          </div>
          <button onClick={onClose} className="w-8 h-8 rounded-full v-subcard flex items-center justify-center c-secondary shrink-0"><Icon n="x" s={14} /></button>
        </div>
        <div className="px-6 pb-2 overflow-y-auto flex-1">{children}</div>
        {footer && <div className="p-6 pt-4 flex items-center justify-start gap-2.5 flex-row-reverse">{footer}</div>}
      </div>
    </div>
  );
}

function ConfirmDialog({ open, onClose, onConfirm, title, body, confirmLabel = 'تأكيد', danger, busy, error }) {
  return (
    <Modal open={open} onClose={onClose} title={title} width="max-w-md"
      footer={<>
        <button className={`btn ${danger ? 'btn-orange' : 'btn-dark'}`} onClick={onConfirm} disabled={busy}>
          {busy ? <span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" /> : null}
          {confirmLabel}
        </button>
        <button className="btn btn-soft" onClick={onClose}>تراجع</button>
      </>}>
      <div className="text-[13px] c-secondary leading-relaxed" style={{ textWrap: 'pretty' }}>{body}</div>
      {error && <div className="mt-4"><InlineAlert kind="red" title="رفض الخادم العملية">{error}</InlineAlert></div>}
    </Modal>
  );
}

// ---------- Toast ----------
function Toast({ toast }) {
  return (
    <div className={`fixed bottom-6 left-6 z-[60] pointer-events-none transition-all duration-300 ${toast ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'}`}>
      <div className="px-4 py-2.5 rounded-full shadow-lg text-xs font-medium flex items-center gap-2" style={{ background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }}>
        <span className="w-2 h-2 rounded-full bg-[#e0491f]" />
        <span>{toast?.text}</span>
      </div>
    </div>
  );
}

// ---------- Variant picker: product → variant/SKU (never stops at product) ----------
// API-backed (Phase 3): GET production-receipts/options?search=, 300ms debounce,
// max 50 active variants. Empty query shows "اكتب للبحث", never a fake full list.
// Disabled variants are rejected by the server on input, so their absence here
// is correct; to decrease a disabled variant, open stock → adjust with variantId.
function VariantPicker({ value, onChange, placeholder = 'ابحث بالاسم أو SKU أو الباركود', small, exclude = [], allowClear }) {
  const { variantCache } = useInv();
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [dq, setDq] = useState('');
  const ref = useRef();
  useEffect(() => {
    // composedPath: the inventory shell renders inside a shadow root, where a document-level listener sees a retargeted target (the host) — contains() would always close. composedPath() crosses the boundary correctly.
    const h = e => { const path = typeof e.composedPath === 'function' ? e.composedPath() : []; const inside = path.length ? path.includes(ref.current) : (ref.current && ref.current.contains(e.target)); if (!inside) setOpen(false); };
    document.addEventListener('mousedown', h); return () => document.removeEventListener('mousedown', h);
  }, []);
  useEffect(() => { const t = setTimeout(() => setDq(q), 300); return () => clearTimeout(t); }, [q]);
  const term = dq.trim();
  const optsQuery = useApi(signal => term ? productionApi.options(term, signal) : Promise.resolve({ branches: [], variants: [] }), [term]);
  const variants = useMemo(() => {
    const list = (optsQuery.data?.variants || []).filter(v => !exclude.includes(v.id));
    list.forEach(v => {
      cacheVariantOptions(variantCache, [v]);
    });
    return list;
  }, [optsQuery.data, exclude.join(',')]);
  const cached = value ? variantCache.get(Number(value)) : null;
  const label = cached?.name_ar || cached?.name_en || cached?.label || null;
  const sku = cached?.sku || null;

  return (
    <div ref={ref} className="relative">
      <button type="button" onClick={() => setOpen(o => !o)} className={`inp ${small ? 'inp-sm' : ''} flex items-center justify-between gap-2 text-right`}>
        {value ? (
          <span className="flex items-center gap-2 min-w-0">
            <span className="truncate c-primary">{label || `صنف #${value}`}</span>
            {sku && <span className="mono text-[10.5px] px-1.5 py-0.5 rounded-md shrink-0" style={{ background: 'var(--icon-well-bg)' }} dir="ltr">{sku}</span>}
          </span>
        ) : <span className="c-muted truncate">{placeholder}</span>}
        <span className="flex items-center gap-1 shrink-0">
          {allowClear && value && <span onClick={e => { e.stopPropagation(); onChange(null); }} className="c-muted hover:c-primary"><Icon n="x" s={12} /></span>}
          <Icon n="chevD" s={13} className="c-muted" />
        </span>
      </button>
      {open && (
        <div className="absolute z-40 mt-1.5 w-full min-w-[320px] v-card shadow-2xl p-2 modal-in" style={{ borderRadius: 16 }}>
          <div className="v-subcard px-3 py-2 rounded-xl flex items-center gap-2 mb-1.5">
            <Icon n="search" s={14} className="c-muted" />
            <input autoFocus value={q} onChange={e => setQ(e.target.value)} placeholder="اسم المنتج، SKU، باركود…" className="bg-transparent text-[12.5px] outline-none flex-1 c-primary" />
            <Icon n="barcode" s={14} className="c-muted" />
          </div>
          <div className="max-h-[260px] overflow-y-auto">
            {!term && <div className="text-center text-[12px] c-muted py-6">اكتب للبحث عن صنف — لا تُعرض القائمة كاملة</div>}
            {term && optsQuery.loading && <div className="text-center text-[12px] c-muted py-6">جارٍ البحث…</div>}
            {term && optsQuery.error && <div className="text-center text-[12px] py-6" style={{ color: 'var(--badge-red-text)' }}>{optsQuery.error.message} <button className="underline" onClick={() => optsQuery.reload()}>إعادة المحاولة</button></div>}
            {term && !optsQuery.loading && !optsQuery.error && variants.length === 0 && <div className="text-center text-[12px] c-muted py-6">لا توجد نتائج مطابقة</div>}
            {term && !optsQuery.loading && !optsQuery.error && variants.map(v => {
              const [nm, sk] = (v.label || '').split('/').map(s => s.trim());
              return (
                <button type="button" key={v.id} onClick={() => { onChange(v.id); setOpen(false); setQ(''); }}
                  className={`w-full flex items-center justify-between gap-3 px-2.5 py-2 rounded-xl text-right hover:bg-[var(--bg-subcard)] ${value === v.id ? 'bg-[var(--bg-subcard)]' : ''}`}>
                  <span className="text-[12.5px] c-primary pr-4">{nm || v.label}</span>
                  <span className="flex items-center gap-2">
                    <span className="mono text-[11px] font-semibold c-primary" dir="ltr">{sk || ''}</span>
                    {value === v.id && <Icon n="check" s={13} className="c-orange" />}
                  </span>
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

const Sku = ({ children }) => <span dir="ltr" className="mono text-[11.5px] font-semibold px-2 py-0.5 rounded-md c-primary" style={{ background: 'var(--icon-well-bg)' }}>{children}</span>;

function NumInput({ value, onChange, decimals = 4, integer, small, placeholder = '0', className = '', err }) {
  return (
    <input dir="ltr" inputMode={integer ? 'numeric' : 'decimal'} value={value ?? ''} placeholder={placeholder}
      onChange={e => {
        // Normalize the keyboard without silently changing the quantity:
        // 5.5 must not become 55 in an integer production field.
        onChange(toLatinDigits(e.target.value));
      }}
      className={`inp ${small ? 'inp-sm' : ''} num text-left ${err ? 'err' : ''} ${className}`} />
  );
}

function KPI({ label, value, icon, hot, sub }) {
  return (
    <div className={`${hot ? 'v-orange-card' : 'v-subcard'} rounded-[18px] p-4 flex flex-col justify-between min-h-[112px]`}>
      <div className="flex items-center justify-between">
        <span className={`text-[12.5px] font-medium ${hot ? 'text-white/90' : 'c-secondary'}`}>{label}</span>
        <span className={`w-7 h-7 rounded-full flex items-center justify-center ${hot ? 'bg-white/15 text-white' : 'v-icon-well'}`}><Icon n={icon} s={14} w={2} /></span>
      </div>
      <div className={`font-display text-[28px] font-bold tracking-tight mt-2 num text-right ${hot ? '' : 'c-primary'}`}>{value}</div>
      {sub && <div className={`text-[11px] ${hot ? 'text-white/80' : 'c-muted'}`}>{sub}</div>}
    </div>
  );
}


const SCREENS = { branches:BranchesScreen,locations:LocationsScreen,stock:StockScreen,adjust:AdjustScreen,moves:MovesScreen,transfers:TransfersScreen,transfer:TransferDetail,'transfer-edit':TransferEdit,counts:CountsScreen,count:CountDetail,production:ProductionScreen };
function readInventoryRoute() {
 const [path,query='']=location.hash.slice(1).split('?');
 const route=path?.split('/')[2] || 'stock';
 return { route:SCREENS[route]?route:'stock', params:Object.fromEntries(new URLSearchParams(query)) };
}
export function InventoryReference({can,isDark}) {
 const [nav,setNav]=useState(readInventoryRoute);
 const [toastMsg,setToastMsg]=useState(null), [reloadKey,setReloadKey]=useState(0);
 const variantCache = useRef(new Map());
 const locationCache = useRef(new Map());
 const dirtyNavigation = useRef(false);
 const lastHash = useRef(location.hash);
 const [branchesTick, setBranchesTick] = useState(0);
 // Context-level cache of all branches with their counts; both branch screens and every dropdown share it (Phase 2.4).
 const branchesQuery = useApi(signal => fetchAllBranches(signal), [branchesTick]);
 const reloadBranches = () => { locationCache.current.clear(); setBranchesTick(t => t + 1); };
 const timer=useRef();
 useEffect(()=>{
   const markDirty = e => { dirtyNavigation.current = Boolean(e.detail); };
   const changed=()=>{
     if (location.hash === lastHash.current) return;
     if (dirtyNavigation.current && !window.confirm('تغييرات غير محفوظة. هل تريد مغادرة الجرد وفقدها؟')) {
       history.replaceState(null, '', lastHash.current); return;
     }
     dirtyNavigation.current = false; lastHash.current = location.hash; setNav(readInventoryRoute());
   };
   window.addEventListener('inventory:dirty',markDirty); window.addEventListener('hashchange',changed);
   return()=>{window.removeEventListener('inventory:dirty',markDirty);window.removeEventListener('hashchange',changed);clearTimeout(timer.current);};
 },[]);
 const go=(route,params={})=>{const qs=new URLSearchParams(Object.entries(params).filter(([,value])=>value!=null)).toString();location.hash='/inventory/'+route+(qs?'?'+qs:'');};
 const toast=text=>{setToastMsg({text});clearTimeout(timer.current);timer.current=setTimeout(()=>setToastMsg(null),2400);};
 const Screen=SCREENS[nav.route];
 return <InvCtx.Provider value={{toast,can,go,reloadKey,reload:()=>setReloadKey(k=>k+1),variantCache:variantCache.current,locationCache:locationCache.current,branchesQuery,reloadBranches}}>
  <div className={'inventory-surface '+(isDark?'theme-dark':'theme-light')} dir="rtl" lang="ar">
   <nav aria-label="شاشات المخزون" className="flex gap-2 overflow-x-auto pb-2 mb-5">
    {INV_NAV.map(item=><button key={item.id} type="button" onClick={()=>go(item.id)} className={'btn shrink-0 '+(item.match.includes(nav.route)?'btn-dark':'btn-soft')}><Icon n={item.icon} s={15}/>{item.label}</button>)}
   </nav>
   <main data-inventory-screen={nav.route}><Screen key={nav.route+JSON.stringify(nav.params)} go={go} params={nav.params}/></main>
   <Toast toast={toastMsg}/>
  </div>
 </InvCtx.Provider>;
}





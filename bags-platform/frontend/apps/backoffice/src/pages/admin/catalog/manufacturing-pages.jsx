import React, { useRef, useState } from 'react';
import { defaultApiClient as api } from '@alfarid/api-client';
import { useApi } from './useApi';
import { normalizeError } from './errors';
import { useApp, useQueryState, PageHeader, DataTable, SearchBox, FilterMenu, EmptyState, Drawer, Field, Input, SectionCard, Banner, SearchSelect, ErrorState, Spinner, Badge, vtoast, ConfirmDialog } from '../catalog-mock/catalog-reference';

const base = '/api/v1/admin';
const get = (path, params, signal) => api.get(base + path, { params, signal }).then(r => r.data);
const statuses = { pending: 'قيد المراجعة', approved: 'معتمد', rejected: 'مرفوض', suspended: 'موقوف' };
const types = { wholesale: 'تاجر جملة', distributor: 'موزع' };

export function DealersPage() {
  const { user: { can } } = useApp();
  const [q, setQ] = useQueryState({ search: '', type: '', status: '', page: 1, per_page: 15 });
  const query = useApi(signal => get('/customers', { ...q, business_only: 1 }, signal), [JSON.stringify(q)]);
  const [draft, setDraft] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [action, setAction] = useState(null);
  const [reason, setReason] = useState('');
  const detail = useApi(signal => draft?.id ? get('/customers/' + draft.id, {}, signal) : Promise.resolve(null), [draft?.id]);
  const set = (key, value) => setDraft(d => ({ ...d, [key]: value }));
  const open = row => { setError(null); setDraft(row ? { ...row, password: '' } : { name: '', phone: '', email: '', password: '', type: 'wholesale', company_name: '', tax_number: '', commercial_register: '', credit_limit: '0' }); };
  const save = async () => {
    if (busy) return;
    setBusy(true); setError(null);
    try {
      const payload = { name: draft.name };
      if (!draft.id || can('customers.approve')) {
        for (const key of ['phone', 'company_name', 'tax_number', 'commercial_register']) payload[key] = draft[key];
        payload.email = draft.email || null;
        if (draft.password) payload.password = draft.password;
        if (can('customers.approve')) payload.credit_limit = draft.credit_limit;
      }
      if (draft.id) await api.put(base + '/customers/' + draft.id, payload);
      else await api.post(base + '/customers', { ...payload, type: draft.type, status: 'pending' });
      setDraft(null); query.reload(); vtoast('تم حفظ حساب التاجر');
    } catch (e) { setError(normalizeError(e)); } finally { setBusy(false); }
  };
  const field = (key, label, options = {}) => <Field key={key} label={label} error={error?.fields?.[key]?.[0]} required={options.required}><Input aria-label={label} value={draft[key] ?? ''} onChange={e => set(key, e.target.value)} {...options} /></Field>;
  return <div className="space-y-5">
    <PageHeader title="التجار والموزعون" subtitle="عملاء بيشتروا منتجات تصنيعنا بالجملة، مع متابعة بياناتهم واعتماد حساباتهم." crumbs={[{ label: 'المبيعات' }, { label: 'التجار والموزعون' }]} actions={can('customers.create') && <button className="btn btn-ink" onClick={() => open(null)}>إضافة تاجر</button>} />
    <DataTable rows={query.data?.data || []} meta={query.data?.meta} state={query.loading ? 'loading' : query.error ? 'error' : 'ready'} error={query.error} onRetry={query.reload} onPage={page => setQ({ page })} onPerPage={per_page => setQ({ per_page, page: 1 })}
      toolbar={<div className="flex flex-wrap gap-2"><SearchBox value={q.search} onChange={search => setQ({ search, page: 1 })} placeholder="اسم التاجر، الشركة أو التليفون…" /><FilterMenu label="نوع الحساب" value={q.type} onChange={type => setQ({ type, page: 1 })} options={Object.entries(types).map(([value, label]) => ({ value, label }))} /><FilterMenu label="الحالة" value={q.status} onChange={status => setQ({ status, page: 1 })} options={Object.entries(statuses).map(([value, label]) => ({ value, label }))} /></div>}
      empty={<EmptyState title="لا توجد حسابات مطابقة" body="أضف تجار الجملة والموزعين اللي بتبيعوا لهم منتجاتكم." />}
      columns={[
        { key: 'name', label: 'التاجر / الشركة', render: r => <div><div className="font-semibold t-1">{r.name}</div><div className="t-2">{r.company_name || '—'}</div></div> },
        { key: 'phone', label: 'التليفون', render: r => <span dir="ltr">{r.phone}</span> },
        { key: 'type', label: 'النوع', render: r => types[r.type] },
        { key: 'status', label: 'الحالة', render: r => <Badge tone={r.status === 'approved' ? 'green' : 'amber'}>{statuses[r.status]}</Badge> },
        { key: 'credit', label: 'حد البيع الآجل', render: r => Number(r.credit_limit).toLocaleString('ar-EG') + ' ج.م' },
        { key: 'actions', label: '', render: r => <button className="btn btn-soft" onClick={() => open(r)}>فتح الحساب</button> },
      ]} />
    <Drawer open={!!draft} onClose={() => { if (!busy) setDraft(null); }} title={draft?.id ? 'حساب التاجر' : 'إضافة تاجر'} width={620} footer={<><button className="btn btn-soft" disabled={busy} onClick={() => setDraft(null)}>إغلاق</button>{(!draft?.id || can('customers.update')) && <button className="btn btn-ink" disabled={busy} onClick={save}>{busy ? 'جارٍ الحفظ…' : 'حفظ البيانات'}</button>}</>}>
      {draft && <div className="space-y-4">
        {error && <div role="alert"><Banner tone="red">{error.message}</Banner></div>}
        <fieldset disabled={busy || (!!draft.id && !can('customers.update'))} className="space-y-4">
          {field('name', 'اسم التاجر', { required: true })}
          {!draft.id && <Field label="نوع الحساب"><SearchSelect value={draft.type} onChange={value => set('type', value || 'wholesale')} options={Object.entries(types).filter(([type]) => type !== 'distributor' || can('customers.approve')).map(([value, label]) => ({ value, label }))} /></Field>}
          <fieldset disabled={!!draft.id && !can('customers.approve')} className="space-y-4">
            {field('phone', 'رقم التليفون', { required: true, ltr: true })}
            {field('email', 'البريد الإلكتروني', { type: 'email', ltr: true })}
            {field('password', draft.id ? 'كلمة مرور جديدة (اختياري)' : 'كلمة المرور', { type: 'password', autoComplete: 'new-password', required: !draft.id })}
            {field('company_name', 'اسم الشركة أو المحل')}
            {field('tax_number', 'الرقم الضريبي')}
            {field('commercial_register', 'السجل التجاري')}
            {can('customers.approve') && field('credit_limit', 'حد البيع الآجل بالجنيه', { type: 'number', min: '0', step: '0.01' })}
          </fieldset>
        </fieldset>
        {!draft.id && <Banner tone="amber">الحساب بيتسجل قيد المراجعة. الاعتماد بيتم بعد استكمال بيانات الشركة ومراجعة المستندات.</Banner>}
        {draft.id && detail.loading && <Spinner />}
        {draft.id && detail.error && <ErrorState title={detail.error.message} onRetry={detail.reload} />}
        {detail.data?.data && <>
          <div className="text-sm t-2">الحالة: {statuses[detail.data.data.status]}{detail.data.data.rejection_reason && ' — ' + detail.data.data.rejection_reason}{detail.data.data.suspension_reason && ' — ' + detail.data.data.suspension_reason}</div>
          {can('customers.documents.view') && <SectionCard title="مستندات التاجر">
            {can('customers.update') && <label className="btn btn-outline cursor-pointer">رفع مستند<input type="file" className="sr-only" accept="application/pdf,image/jpeg,image/png" disabled={busy || (detail.data.data.documents || []).length >= 5} onChange={async e => { const file = e.target.files?.[0]; e.target.value = ''; if (!file) return; setBusy(true); setError(null); try { const data = new FormData(); data.append('document', file); await api.post(base + `/customers/${draft.id}/documents`, data, { headers: { 'Content-Type': 'multipart/form-data' } }); detail.reload(); } catch (e) { setError(normalizeError(e)); } finally { setBusy(false); } }} /></label>}
            {(detail.data.data.documents || []).length === 0 ? <p className="t-2 text-sm">ارفع سجل التاجر أو بطاقته الضريبية لمراجعتهما. PDF أو صورة، حتى 5 ميجابايت للمستند.</p> : detail.data.data.documents.map(doc => <div key={doc.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span className="t-1 text-sm">{doc.original_name} — {doc.status === 'verified' ? 'موثّق' : 'بانتظار المراجعة'}</span>
              <button className="btn btn-soft" disabled={busy} onClick={async () => { setBusy(true); try { const r = await api.get(base + `/customers/${draft.id}/documents/${doc.id}/download`, { responseType: 'blob' }); const url = URL.createObjectURL(r.data); const link = document.createElement('a'); link.href = url; link.download = doc.original_name; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); } catch (e) { setError(normalizeError(e)); } finally { setBusy(false); } }}>تنزيل</button>
              {can('customers.approve') && doc.status !== 'verified' && <button className="btn btn-outline" disabled={busy} onClick={async () => { setBusy(true); try { await api.patch(base + `/customers/${draft.id}/documents/${doc.id}`, { status: 'verified' }); detail.reload(); } catch (e) { setError(normalizeError(e)); } finally { setBusy(false); } }}>توثيق المستند</button>}
            </div>)}
          </SectionCard>}
          {can('customers.approve') && <div className="flex flex-wrap gap-2">{Object.entries({ approve: 'اعتماد الحساب', reject: 'رفض الحساب', suspend: 'إيقاف الحساب' }).filter(([kind]) => kind !== 'approve' || draft.status !== 'approved').map(([kind, label]) => <button key={kind} className="btn btn-outline" disabled={busy} onClick={() => { setAction({ kind, label }); setReason(''); }}>{label}</button>)}</div>}
        </>}
      </div>}
    </Drawer>
    <ConfirmDialog open={!!action} onClose={() => setAction(null)} title={action?.label || ''} message={action?.kind === 'approve' ? 'تأكد من حفظ بيانات الشركة وتوثيق المستندات قبل الاعتماد.' : <Field label="السبب" required><Input aria-label="سبب تغيير حالة الحساب" value={reason} onChange={e => setReason(e.target.value)} /></Field>} confirmLabel="تأكيد" onConfirm={async () => { if (action.kind !== 'approve' && !reason.trim()) throw new Error('اكتب السبب أولًا'); await api.post(base + `/customers/${draft.id}/${action.kind}`, action.kind === 'approve' ? {} : { reason }); setDraft(null); query.reload(); vtoast('تم تحديث حالة الحساب'); }} />
  </div>;
}

export function ProductionPage() {
  const { user: { can } } = useApp();
  const [q, setQ] = useQueryState({ page: 1, per_page: 15 });
  const history = useApi(signal => get('/production-receipts', q, signal), [JSON.stringify(q)]);
  const [search, setSearch] = useState('');
  const options = useApi(signal => get('/production-receipts/options', { search }, signal), [search]);
  const [form, setForm] = useState({ batch_number: '', branch_id: null, location_id: null, notes: '' });
  const [items, setItems] = useState([]);
  const [variantId, setVariantId] = useState(null);
  const [selectedReceipt, setSelectedReceipt] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const request = useRef(null);
  const branches = options.data?.data?.branches || [];
  const variants = options.data?.data?.variants || [];
  const branch = branches.find(b => b.id === form.branch_id);
  const updateItem = (id, key, value) => setItems(rows => rows.map(row => row.product_variant_id === id ? { ...row, [key]: value } : row));
  const receive = async e => {
    e.preventDefault(); if (busy) return;
    setBusy(true); setError(null);
    try {
      if (!items.length) throw new Error('أضف صنفًا واحدًا على الأقل.');
      const payload = { ...form, items: items.map(({ product_variant_id, quantity, unit_cost }) => ({ product_variant_id, quantity: Number(quantity), unit_cost })) };
      const hash = JSON.stringify(payload);
      if (!request.current || request.current.hash !== hash) request.current = { hash, key: crypto.randomUUID() };
      await api.post(base + '/production-receipts', payload, { headers: { 'Idempotency-Key': request.current.key } });
      setForm(f => ({ ...f, batch_number: '', notes: '' })); setItems([]); request.current = null; history.reload(); vtoast('تم استلام الإنتاج وإضافته للمخزون');
    } catch (e) { setError(normalizeError(e)); } finally { setBusy(false); }
  };
  return <div className="space-y-5">
    <PageHeader title="استلام الإنتاج" subtitle="إدخال المنتجات التامة من مصنعنا للمخزن، مع تسجيل تكلفة تصنيع الوحدة." crumbs={[{ label: 'المخزون' }, { label: 'استلام الإنتاج' }]} />
    {can('inventory.adjust') && can('products.view_cost') && <SectionCard title="دفعة إنتاج جديدة" subtitle="كل دفعة لها رقم فريد. الاستلام يضيف المخزون ويحدّث متوسط تكلفة المنتج.">
      <form onSubmit={receive} className="space-y-4">
        {error && <div role="alert"><Banner tone="red">{error.message}{Object.entries(error.fields || {}).map(([key, messages]) => <div key={key}>{messages.join('، ')}</div>)}</Banner></div>}
        <fieldset disabled={busy} className="space-y-4">
          <div className="grid sm:grid-cols-3 gap-4">
            <Field label="رقم دفعة الإنتاج" required><Input aria-label="رقم دفعة الإنتاج" required maxLength={100} value={form.batch_number} onChange={e => setForm({ ...form, batch_number: e.target.value })} /></Field>
            <Field label="مخزن / فرع الاستلام" required><SearchSelect value={form.branch_id} onChange={id => setForm({ ...form, branch_id: id, location_id: null })} options={branches.map(b => ({ value: b.id, label: b.name }))} placeholder="اختر الفرع" /></Field>
            <Field label="الموقع داخل المخزن"><SearchSelect value={form.location_id} onChange={id => setForm({ ...form, location_id: id })} options={(branch?.locations || []).map(l => ({ value: l.id, label: l.name }))} placeholder="بدون موقع محدد" /></Field>
          </div>
          {options.error ? <ErrorState title={options.error.message} onRetry={options.reload} /> : <div className="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] gap-3 items-end">
            <Field label="ابحث عن منتج أو SKU"><Input aria-label="ابحث عن منتج أو SKU" value={search} onChange={e => { setSearch(e.target.value); setVariantId(null); }} maxLength={100} /></Field>
            <div className="min-w-0 flex-1"><Field label="الصنف"><SearchSelect value={variantId} onChange={setVariantId} options={variants.filter(v => !items.some(i => i.product_variant_id === v.id)).map(v => ({ value: v.id, label: v.label }))} placeholder={options.loading ? 'جارٍ البحث…' : 'اختر الصنف (أول 50 نتيجة)'} /></Field></div>
            <button type="button" className="btn btn-outline" disabled={!variantId || options.loading || items.length >= 50} onClick={() => { const v = variants.find(v => v.id === variantId); if (!v || items.some(i => i.product_variant_id === v.id)) return; setItems([...items, { product_variant_id: v.id, label: v.label, quantity: '1', unit_cost: '' }]); setVariantId(null); }}>إضافة الصنف</button>
          </div>}
          {items.map(item => <div key={item.product_variant_id} className="grid sm:grid-cols-[1fr_130px_150px_auto] gap-3 items-end">
            <p className="t-1 text-sm py-3">{item.label}</p>
            <Field label="الكمية" required><Input aria-label="الكمية" type="number" required min="1" max="99999999" step="1" value={item.quantity} onChange={e => updateItem(item.product_variant_id, 'quantity', e.target.value)} /></Field>
            <Field label="تكلفة تصنيع الوحدة" required><Input aria-label="تكلفة تصنيع الوحدة" type="number" required min="0" max="99999999.9999" step="0.0001" value={item.unit_cost} onChange={e => updateItem(item.product_variant_id, 'unit_cost', e.target.value)} /></Field>
            <button type="button" className="btn btn-soft" aria-label={'إزالة ' + item.label} onClick={() => setItems(items.filter(i => i !== item))}>إزالة</button>
          </div>)}
          <Field label="ملاحظات الاستلام"><Input aria-label="ملاحظات الاستلام" maxLength={1000} value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} /></Field>
          <div className="flex flex-wrap gap-4 items-center justify-between"><span className="t-2 text-sm">إجمالي تكلفة الدفعة: {items.reduce((sum, item) => sum + (Number(item.quantity) || 0) * (Number(item.unit_cost) || 0), 0).toLocaleString('ar-EG', { maximumFractionDigits: 2 })} ج.م</span><button className="btn btn-ink" type="submit" disabled={!form.branch_id || !items.length || busy}>{busy ? 'جارٍ الاستلام…' : 'تأكيد استلام الإنتاج'}</button></div>
        </fieldset>
      </form>
    </SectionCard>}
    <DataTable rows={history.data?.data || []} meta={history.data ? { ...history.data, data: undefined } : undefined} state={history.loading ? 'loading' : history.error ? 'error' : 'ready'} error={history.error} onRetry={history.reload} onPage={page => setQ({ page })} onPerPage={per_page => setQ({ per_page, page: 1 })}
      empty={<EmptyState title="لم تُستلم دفعات إنتاج بعد" body="سجّل أول دفعة بعد خروج المنتجات التامة من المصنع." />}
      columns={[
        { key: 'batch', label: 'دفعة الإنتاج', render: r => r.batch_number },
        { key: 'branch', label: 'الفرع', render: r => branches.find(b => b.id === r.branch_id)?.name || r.branch_id },
        { key: 'quantity', label: 'إجمالي القطع', render: r => r.items.reduce((sum, i) => sum + Number(i.quantity), 0) },
        ...(can('products.view_cost') ? [{ key: 'cost', label: 'تكلفة التصنيع', render: r => r.items.reduce((sum, i) => sum + Number(i.quantity) * Number(i.unit_cost), 0).toLocaleString('ar-EG', { maximumFractionDigits: 2 }) + ' ج.م' }] : []),
        { key: 'date', label: 'تاريخ الاستلام', render: r => new Date(r.created_at).toLocaleString('ar-EG', { timeZone: 'Africa/Cairo' }) },
        { key: 'notes', label: 'ملاحظات', render: r => r.notes || '—' },
        { key: 'details', label: '', render: r => <button className="btn btn-soft" onClick={() => setSelectedReceipt(r)}>تفاصيل الدفعة</button> },
      ]} />
    <Drawer open={!!selectedReceipt} onClose={() => setSelectedReceipt(null)} title={'دفعة الإنتاج ' + (selectedReceipt?.batch_number || '')} width={620}>
      <div className="space-y-4">{selectedReceipt?.items.map(item => <div key={item.product_variant_id} className="v-border-b py-3 space-y-2">
        <div className="font-semibold t-1">{item.label || 'صنف رقم ' + item.product_variant_id}</div>
        <div className="t-2 text-sm">الكمية: {item.quantity} قطعة</div>
        {can('products.view_cost') && <div className="t-2 text-sm">تكلفة تصنيع الوحدة: {item.unit_cost} ج.م</div>}
      </div>)}<p className="t-2 text-sm">{selectedReceipt?.notes}</p></div>
    </Drawer>
  </div>;
}

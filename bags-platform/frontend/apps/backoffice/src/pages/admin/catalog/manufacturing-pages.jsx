import React, { useEffect, useRef, useState } from 'react';
import { defaultApiClient as api } from '@alfarid/api-client';
import { formatCurrency, formatDate, CustomerStatusLabelsAr as statuses, CustomerTypeLabelsAr as types } from '@alfarid/utils';
import { useApi } from './useApi';
import { normalizeError } from './errors';
import { useApp, useQueryState, PageHeader, DataTable, SearchBox, FilterMenu, EmptyState, Drawer, Field, Input, SectionCard, Banner, SearchSelect, ErrorState, Spinner, Badge, vtoast, ConfirmDialog } from '../catalog-mock/catalog-reference';

const base = '/api/v1/admin';
const get = (path, params, signal) => api.get(base + path, { params, signal }).then(r => r.data);

export function DealersPage() {
  const { user: { can } } = useApp();
  const [q, setQ] = useQueryState({ search: '', type: '', status: '', page: 1, per_page: 15 });
  // Deep link from assistant (#/dealers?customer={id}) opens the drawer without appending to API filter parameters.
  const { customer: deepLinkId, tab: deepLinkTab, ...listQuery } = q;
  const query = useApi(signal => get('/customers', { ...listQuery, business_only: 1 }, signal), [JSON.stringify(listQuery)]);
  const [draft, setDraft] = useState(null);
  const [tab, setTab] = useState('details');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [action, setAction] = useState(null);
  const [reason, setReason] = useState('');
  const detail = useApi(signal => draft?.id ? get('/customers/' + draft.id, {}, signal) : Promise.resolve(null), [draft?.id]);
  const set = (key, value) => setDraft(d => ({ ...d, [key]: value }));
  useEffect(() => {
    const id = Number(deepLinkId);
    if (!Number.isInteger(id) || id <= 0) return;
    let live = true;
    get('/customers/' + id).then(res => { if (live && res?.data) { setError(null); setTab(deepLinkTab === 'wallet' ? 'wallet' : 'details'); setDraft({ ...res.data, password: '' }); } }).catch(() => { if (live) vtoast('تعذّر فتح حساب التاجر'); }).finally(() => { if (live) setQ({ customer: '', tab: '' }); });
    return () => { live = false; };
  }, [deepLinkId, deepLinkTab]);
  const open = row => { setError(null); setTab('details'); setDraft(row ? { ...row, password: '' } : { name: '', phone: '', email: '', password: '', type: 'wholesale', company_name: '', tax_number: '', commercial_register: '', credit_limit: '0' }); };
  const canUseWallet = draft?.id && (can('customers.wallet.view') || can('customers.wallet.adjust'));
  const walletOpen = canUseWallet && tab === 'wallet';
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
        { key: 'credit', label: 'حد البيع الآجل', render: r => formatCurrency(r.credit_limit) },
        { key: 'actions', label: '', render: r => <button className="btn btn-soft" onClick={() => open(r)}>فتح الحساب</button> },
      ]} />
    <Drawer open={!!draft} onClose={() => { if (!busy) setDraft(null); }} title={draft?.id ? 'حساب التاجر' : 'إضافة تاجر'} width={620} footer={<><button className="btn btn-soft" disabled={busy} onClick={() => setDraft(null)}>إغلاق</button>{!walletOpen && (!draft?.id || can('customers.update')) && <button className="btn btn-ink" disabled={busy} onClick={save}>{busy ? 'جارٍ الحفظ…' : 'حفظ البيانات'}</button>}</>}>
      {canUseWallet && <div className="flex gap-2 mb-4">
        <button type="button" className="btn btn-soft" aria-pressed={!walletOpen} disabled={busy} onClick={() => setTab('details')}>بيانات الحساب</button>
        <button type="button" className="btn btn-soft" aria-pressed={!!walletOpen} disabled={busy} onClick={() => setTab('wallet')}>المحفظة</button>
      </div>}
      {walletOpen && <DealerWallet key={draft.id} customerId={draft.id} canView={can('customers.wallet.view')} canAdjust={can('customers.wallet.adjust')} busy={busy} setBusy={setBusy} />}
      {draft && !walletOpen && <div className="space-y-4">
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
        {draft.id && can('ai_agent.use') && <div><button type="button" className="btn btn-outline" onClick={() => { const name = draft.name || draft.company_name || ''; const params = new URLSearchParams({ seed: 'راجع طلب $' + name + ' وقولي جاهز للاعتماد ولا لأ', mention_entity: 'customer', mention_id: String(draft.id), mention_label: name }); window.location.hash = '/assistant?' + params.toString(); }}>اسأل المساعد</button></div>}
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

function DealerWallet({ customerId, canView, canAdjust, busy, setBusy }) {
  const [page, setPage] = useState(1);
  const [form, setForm] = useState({ direction: 'credit', amount: '', reason: '' });
  const [error, setError] = useState(null);
  const pending = useRef(false);
  const request = useRef(null);
  const path = `/customers/${customerId}/wallet`;
  const wallet = useApi(signal => canView ? get(path, { page }, signal) : Promise.resolve(null), [path, page, canView]);
  const ledger = wallet.data?.transactions;

  const adjust = async event => {
    event.preventDefault();
    if (pending.current || !form.reason.trim()) return;
    pending.current = true;
    setBusy(true);
    setError(null);
    const payload = { ...form, kind: 'adjustment', reason: form.reason.trim() };
    const hash = JSON.stringify(payload);
    try {
      if (request.current?.hash !== hash) request.current = { hash, key: crypto.randomUUID() };
      await api.post(base + path + '/adjustments', payload, { headers: { 'Idempotency-Key': request.current.key } });
      request.current = null;
      setForm({ direction: 'credit', amount: '', reason: '' });
      if (page === 1) wallet.reload();
      else setPage(1);
      vtoast('تم تسجيل تعديل المحفظة');
    } catch (e) {
      setError(normalizeError(e));
    } finally {
      pending.current = false;
      setBusy(false);
    }
  };

  return <div className="space-y-4">
    {canView && <SectionCard title="دفتر المحفظة" subtitle={wallet.data ? `الرصيد ${formatCurrency(wallet.data.balance)}` : undefined}>
      {wallet.loading ? <Spinner /> : wallet.error ? <ErrorState title={wallet.error.message} onRetry={wallet.reload} /> : <>
        {ledger?.data.length ? <ul className="divide-y">
          {ledger.data.map(entry => <li key={entry.id} className="py-2 flex justify-between gap-3">
            <div><div>{entry.type_label}</div><div className="t-2 text-sm">{formatDate(entry.created_at)}{entry.reference_label && ` · ${entry.reference_label}`}</div></div>
            <div dir="ltr" className={Number(entry.amount) < 0 ? 'text-red-700' : 'text-emerald-700'}>{formatCurrency(entry.amount)}</div>
          </li>)}
        </ul> : <p className="t-2">لا توجد حركات في المحفظة.</p>}
        {ledger?.last_page > 1 && <div className="flex items-center gap-2 mt-3">
          <button type="button" className="btn btn-soft" disabled={page <= 1} onClick={() => setPage(page - 1)}>السابق</button>
          <span>{page} / {ledger.last_page}</span>
          <button type="button" className="btn btn-soft" disabled={page >= ledger.last_page} onClick={() => setPage(page + 1)}>التالي</button>
        </div>}
      </>}
    </SectionCard>}
    {canAdjust && <SectionCard title="تعديل الرصيد">
      <form onSubmit={adjust} className="space-y-3">
        {error && <div role="alert"><Banner tone="red">{error.message}</Banner></div>}
        <fieldset disabled={busy} className="space-y-3">
          <Field label="اتجاه التعديل"><select className="inp" aria-label="اتجاه التعديل" value={form.direction} onChange={e => setForm({ ...form, direction: e.target.value })}>
            <option value="credit">إضافة</option><option value="debit">خصم</option>
          </select></Field>
          <Field label="المبلغ بالجنيه" required><Input aria-label="المبلغ بالجنيه" type="number" min="0.01" max="99999999" step="0.01" required value={form.amount} onChange={e => setForm({ ...form, amount: e.target.value })} /></Field>
          <Field label="سبب التعديل" required><Input aria-label="سبب التعديل" required maxLength={500} value={form.reason} onChange={e => setForm({ ...form, reason: e.target.value })} /></Field>
          <button type="submit" className="btn btn-ink" disabled={!form.reason.trim()}>{busy ? 'جارٍ التسجيل…' : 'تسجيل التعديل'}</button>
        </fieldset>
      </form>
    </SectionCard>}
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
          <div className="flex flex-wrap gap-4 items-center justify-between"><span className="t-2 text-sm">إجمالي تكلفة الدفعة: {formatCurrency(items.reduce((sum, item) => sum + (Number(item.quantity) || 0) * (Number(item.unit_cost) || 0), 0))}</span><button className="btn btn-ink" type="submit" disabled={!form.branch_id || !items.length || busy}>{busy ? 'جارٍ الاستلام…' : 'تأكيد استلام الإنتاج'}</button></div>
        </fieldset>
      </form>
    </SectionCard>}
    <DataTable rows={history.data?.data || []} meta={history.data ? { ...history.data, data: undefined } : undefined} state={history.loading ? 'loading' : history.error ? 'error' : 'ready'} error={history.error} onRetry={history.reload} onPage={page => setQ({ page })} onPerPage={per_page => setQ({ per_page, page: 1 })}
      empty={<EmptyState title="لم تُستلم دفعات إنتاج بعد" body="سجّل أول دفعة بعد خروج المنتجات التامة من المصنع." />}
      columns={[
        { key: 'batch', label: 'دفعة الإنتاج', render: r => r.batch_number },
        { key: 'branch', label: 'الفرع', render: r => branches.find(b => b.id === r.branch_id)?.name || r.branch_id },
        { key: 'quantity', label: 'إجمالي القطع', render: r => r.items.reduce((sum, i) => sum + Number(i.quantity), 0) },
        ...(can('products.view_cost') ? [{ key: 'cost', label: 'تكلفة التصنيع', render: r => formatCurrency(r.items.reduce((sum, i) => sum + Number(i.quantity) * Number(i.unit_cost), 0)) }] : []),
        { key: 'date', label: 'تاريخ الاستلام', render: r => formatDate(r.created_at, { includeTime: true }) },
        { key: 'notes', label: 'ملاحظات', render: r => r.notes || '—' },
        { key: 'details', label: '', render: r => <button className="btn btn-soft" onClick={() => setSelectedReceipt(r)}>تفاصيل الدفعة</button> },
      ]} />
    <Drawer open={!!selectedReceipt} onClose={() => setSelectedReceipt(null)} title={'دفعة الإنتاج ' + (selectedReceipt?.batch_number || '')} width={620}>
      <div className="space-y-4">{selectedReceipt?.items.map(item => <div key={item.product_variant_id} className="v-border-b py-3 space-y-2">
        <div className="font-semibold t-1">{item.label || 'صنف رقم ' + item.product_variant_id}</div>
        <div className="t-2 text-sm">الكمية: {item.quantity} قطعة</div>
        {can('products.view_cost') && <div className="t-2 text-sm">تكلفة تصنيع الوحدة: {formatCurrency(item.unit_cost)}</div>}
      </div>)}<p className="t-2 text-sm">{selectedReceipt?.notes}</p></div>
    </Drawer>
  </div>;
}

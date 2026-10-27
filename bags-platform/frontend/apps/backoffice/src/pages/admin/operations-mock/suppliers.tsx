import React, { useState, useEffect, useMemo, Fragment } from 'react';
import { suppliersApi, purchaseRequestsApi, purchaseOrdersApi, supplierInvoicesApi, supplierPaymentsApi, branchesApi, commerceLookupsApi, newIdempotencyKey, type Supplier as SupplierApi, type PurchaseRequest as PRApi, type PurchaseOrder as POApi, type SupplierInvoice as InvoiceApi, type SupplierPaymentApi } from '@alfarid/api-client';
import { useCan } from '../../../hooks/useCan';
import { RemoteFeedback } from './api/RemoteFeedback';
import { useRemote, useDebounced, useAction } from './api/useRemote';
import { useFormState } from './api/useFormState';
import { mapErrors, num, toDate } from './api/mappers';
import {
  SP_METHODS,
  SUPPLIERS, PRS, POS, INVOICES, SPAYMENTS, VARIANTS,
  PR_STATUS,
  PO_STATUS,
  INV_STATUS,
  SupplierRow,
  PurchaseRequest,
  PurchaseOrder,
  SupplierInvoice,
  SupplierPayment,
} from './data';
import {
  Icon,
  useToast,
  Badge,
  StatusBadge,
  ActiveDot,
  Avatar,
  PageHead,
  Card,
  CardHead,
  KPI,
  SearchPill,
  FSelect,
  TabsBar,
  Pager,
  DataTable,
  RowActions,
  Modal,
  ConfirmModal,
  Field,
  TextArea,
  ToggleRow,
  Note,
  Dropzone,
  KV,
  Num,
  fmtNum,
  fmtMoney,
  HatchBar,
  Seg,
} from './ui';

const mapSupplier = (x: SupplierApi) => ({ demo: !!(x as any).__demo, id: x.id, code: x.code, name: x.name, company: x.company_name, phone: x.phone ?? '', email: x.email, terms: x.payment_terms_days, balance: num(x.current_balance), active: x.is_active, raw: x });
const mapPR = (x: PRApi) => ({ demo: !!(x as any).__demo, id: x.id, no: x.request_number, branch: x.branch?.name ?? '', branchId: x.branch_id, status: x.status, by: x.requested_by ? `#${x.requested_by}` : '—', date: toDate(x.created_at), items: x.items?.length ?? 0, raw: x });
const poStatus: Record<string, string> = { sent_to_supplier: 'sent', supplier_accepted: 'accepted', change_requested: 'revision', partially_received: 'partial' };
const toPOStatus = (v: string) => Object.entries(poStatus).find(([, ui]) => ui === v)?.[0] ?? v;
const mapPO = (x: POApi) => ({ demo: !!(x as any).__demo, id: x.id, no: x.po_number, sup: x.supplier_id, branch: x.branch?.name ?? '', branchId: x.branch_id, status: poStatus[x.status] ?? x.status, eta: x.expected_delivery_date, total: num(x.grand_total), raw: x });
const mapInvoice = (x: InvoiceApi) => ({ demo: !!(x as any).__demo, id: x.id, sys: x.invoice_number, supNo: x.supplier_invoice_no ?? '', sup: x.supplier_id, po: x.purchase_order?.po_number ?? null, poId: x.purchase_order_id, issue: x.issue_date ?? '', due: x.due_date, subtotal: num(x.subtotal), tax: num(x.tax_amount), paid: num(x.paid_amount), remaining: num(x.remaining_amount), status: x.status === 'partially_paid' ? 'partial' : x.status, file: !!x.attachment_url, raw: x });
const paymentMethod = (m: string): 'cash' | 'bank_transfer' | 'check' => m === 'bank' ? 'bank_transfer' : m === 'cheque' ? 'check' : 'cash';
const mapPayment = (x: SupplierPaymentApi) => ({ demo: !!(x as any).__demo, id: x.id, no: x.payment_number, sup: x.supplier_id, inv: x.invoice?.invoice_number ?? null, invId: x.supplier_invoice_id, amount: num(x.amount), method: x.payment_method === 'bank_transfer' ? 'bank' : x.payment_method === 'check' ? 'cheque' : 'cash', ref: x.reference_no, date: x.payment_date, notes: x.notes, void: x.voided_at ? { reason: x.void_reason ?? '', by: x.voided_by ? `#${x.voided_by}` : '—', at: x.voided_at } : null });
async function allPages<T>(loadPage: (page: number) => Promise<{ data: T[]; meta: { last_page: number } }>): Promise<T[]> {
  const first = await loadPage(1), rows = [...first.data];
  for (let page = 2; page <= first.meta.last_page; page++) rows.push(...(await loadPage(page)).data);
  return rows;
}
const mockSuppliers = SUPPLIERS.map(s => ({ id: s.id, code: s.code, name: s.name, company_name: s.company, email: s.email, phone: s.phone, payment_terms_days: s.terms, opening_balance: '0', current_balance: String(s.balance), is_active: s.active, tax_number: null, commercial_register: null, address: null, users: [] } as SupplierApi)).map(row => Object.assign(row, { __demo: true }) as SupplierApi);
const mockPRs = PRS.map(p => ({ id: p.id, request_number: p.no, branch_id: 0, branch: { id: 0, name: p.branch }, requested_by: null, status: p.status, notes: null, items: [], created_at: p.date } as PRApi)).map(row => Object.assign(row, { __demo: true }) as PRApi);
const mockPOs = POS.map(p => ({ id: p.id, po_number: p.no, supplier_id: p.sup, branch_id: 0, branch: { id: 0, name: p.branch }, purchase_request_id: null, status: toPOStatus(p.status), expected_delivery_date: p.eta, grand_total: String(p.total), approved_by: null, supplier_change_notes: null, subtotal: String(p.total), tax_amount: '0', discount_amount: '0', items: [], deliveries: [] } as POApi)).map(row => Object.assign(row, { __demo: true }) as POApi);
const mockInvoices = INVOICES.map(i => ({ id: i.id, invoice_number: i.sys, supplier_invoice_no: i.supNo, supplier_id: i.sup, purchase_order_id: null, issue_date: i.issue, due_date: i.due, subtotal: String(i.subtotal), tax_amount: String(i.tax), total_amount: String(i.subtotal + i.tax), paid_amount: String(i.paid), remaining_amount: String(i.subtotal + i.tax - i.paid), status: i.status === 'partial' ? 'partially_paid' : i.status, attachment_url: null } as InvoiceApi)).map(row => Object.assign(row, { __demo: true }) as InvoiceApi);
const mockPayments = SPAYMENTS.map(p => ({ id: p.id, payment_number: p.no, supplier_id: p.sup, supplier_invoice_id: null, invoice: p.inv ? { id: 0, invoice_number: p.inv, supplier_invoice_no: null } : null, amount: String(p.amount), payment_method: paymentMethod(p.method), reference_no: p.ref, payment_date: p.date, notes: p.notes, voided_at: p.void?.at ?? null, void_reason: p.void?.reason ?? null, voided_by: null } as SupplierPaymentApi)).map(row => Object.assign(row, { __demo: true }) as SupplierPaymentApi);
const pageOf = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length, from: data.length ? 1 : null, to: data.length || null } });
function useSupplierLookup() {
  return useRemote((signal) => allPages((page) => suppliersApi.list({ page }, signal)).then(r => r.map(mapSupplier)), [], { mock: () => mockSuppliers.map(mapSupplier) });
}
function useVariants() {
  return useRemote(signal => commerceLookupsApi.variants(signal).then(rows => rows.map(v => ({ id: v.id, name: v.label, sku: '' }))), [], { mock: () => VARIANTS.map((v, id) => ({ id: id + 1, name: v.name, sku: v.sku })) });
}
const blankItem = () => ({ product_variant_id: '', qty: 1, cost: 0, tax: 0, notes: '' });
export const VSel = ({ value, onChange, variants }: { value?: string | number; onChange: (v: string) => void; variants: any[] }) => (
  <select className="inp inp-sm !bg-[var(--bg-card)]" value={value ?? ''} onChange={e => onChange(e.target.value)}>
    <option value="">اختر المتغير</option>
    {variants.map((v) => (
      <option key={v.id} value={v.id}>
        {v.name} · {v.sku}
      </option>
    ))}
  </select>
);

export const NInp = ({ value, onChange, step = '1', min = '1', ph }: { value?: any; onChange: (v: string) => void; step?: string; min?: string; ph?: string }) => (
  <input
    type="number"
    step={step}
    min={min}
    className="inp inp-sm num !bg-[var(--bg-card)]"
    value={value ?? ''} onChange={e => onChange(e.target.value)}
    placeholder={ph}
  />
);

export function ItemsEditor({
  cols,
  max = 50,
  init = 1,
  extra,
  items, onItemsChange,
}: {
  cols: { h: string; fr: string; el: (ri: number) => React.ReactNode }[];
  max?: number;
  init?: number;
  extra?: React.ReactNode;
  items?: any[]; onItemsChange?: (items: any[]) => void;
}) {
  const [localRows, setRows] = useState(Array.from({ length: init }).map((_, i) => ({ k: i })));
  const rows = items ? items.map((_, k) => ({ k })) : localRows;
  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <span className="lbl">
          البنود <span className="accent">*</span>
        </span>
        <span className="text-[11px] tx3">
          <Num>{rows.length}</Num> / <Num>{max}</Num>
        </span>
      </div>
      <div className="v-sub p-2 space-y-2">
        <div
          className="grid gap-2 px-1 text-[11px] tx3"
          style={{ gridTemplateColumns: cols.map((c) => c.fr).join(' ') + ' 32px' }}
        >
          {cols.map((c, i) => (
            <span key={i}>{c.h}</span>
          ))}
          <span></span>
        </div>
        {rows.map((r, ri) => (
          <div
            key={r.k}
            className="grid gap-2 items-center"
            style={{ gridTemplateColumns: cols.map((c) => c.fr).join(' ') + ' 32px' }}
          >
            {cols.map((c, i) => (
              <Fragment key={i}>{c.el(ri)}</Fragment>
            ))}
            <button
              className="icon-btn !w-7 !h-7"
              disabled={rows.length === 1}
              onClick={() => items && onItemsChange ? onItemsChange(items.filter((_, i) => i !== ri)) : setRows(rows.filter((x) => x.k !== r.k))}
            >
              <Icon name="trash" size={12} />
            </button>
          </div>
        ))}
        <button
          className="btn btn-ghost btn-sm w-full"
          disabled={rows.length >= max}
          onClick={() => items && onItemsChange ? onItemsChange([...items, blankItem()]) : setRows([...rows, { k: Date.now() }])}
        >
          <Icon name="plus" size={13} />
          إضافة بند
        </button>
      </div>
      {extra}
    </div>
  );
}

/* ---------------- Suppliers list ---------------- */
export function SuppliersList({ go }: { go: (r: { m: string; id?: any; sub?: any }) => void }) {
  const toast = useToast();
  const [q, setQ] = useState('');
  const [form, setForm] = useState<SupplierRow | { id?: any } | null>(null);
  const dq = useDebounced(q);
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [dq]);
  const list = useRemote((signal) => suppliersApi.list({ page, search: dq || undefined }, signal), [page, dq], { mock: () => pageOf(mockSuppliers.filter(s => !dq || (s.name + s.code + s.company_name).includes(dq))) });
  const rows = (list.data?.data ?? []).map(mapSupplier);
  const invoices = useRemote(signal => supplierInvoicesApi.list({}, signal), [], { mock: () => pageOf(mockInvoices) });
  const orders = useRemote(signal => purchaseOrdersApi.list({}, signal), [], { mock: () => pageOf(mockPOs) });
  const totalDue = rows.reduce((a, s) => a + Math.max(0, s.balance), 0);

  return (
    <div className="space-y-5">
      <PageHead
        title="الموردون"
        sub="بيانات الموردين وأرصدتهم وحسابات موظفيهم على بوابة الموردين."
        actions={
          <button className="btn btn-dark" onClick={() => setForm({})}>
            <Icon name="plus" size={14} />
            مورد جديد
          </button>
        }
      />
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stg stg-2">
        <KPI
          hero
          label="المستحق في الصفحة الحالية"
          icon="wallet"
          value={<span className="ltr">{fmtNum(totalDue, 0)}</span>}
          small
          foot="EGP · الأرصدة الدائنة"
        />
        <KPI
          label="المفعّلون في الصفحة الحالية"
          icon="factory"
          value={rows.filter((s) => s.active).length}
          foot={<>من أصل <Num>{list.data?.meta.total ?? 0}</Num></>}
        />
        <KPI
          label="فواتير غير مسددة"
          icon="receipt"
          value={invoices.data?.data.filter((i) => ['posted', 'partially_paid'].includes(i.status)).length ?? 0}
          foot="في الصفحة المحمّلة"
        />
        <KPI
          label="أوامر شراء مفتوحة"
          icon="cart"
          value={orders.data?.data.filter((p) => ['sent_to_supplier', 'supplier_accepted', 'partially_received', 'change_requested'].includes(p.status)).length ?? 0}
          foot="في الصفحة المحمّلة"
        />
      </div>
      <Card stg={3}>
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <SearchPill value={q} onChange={setQ} placeholder="اسم المورد، الشركة، أو الكود" />
          <span className="text-[12px] tx3">
            <Num>{rows.length}</Num> مورد
          </span>
        </div>
        <RemoteFeedback state={list} />
        <DataTable
          rows={rows}
          onRow={(s: SupplierRow) => go({ m: 'suppliers', id: s.id })}
          cols={[
            {
              h: 'المورد',
              r: (s: SupplierRow) => (
                <div className="flex items-center gap-2.5">
                  <Avatar name={s.company || s.name} tone={s.id} size={32} />
                  <div>
                    <div className="font-semibold tx1">{s.company || s.name}</div>
                    {s.company && <div className="text-[11.5px] tx3">{s.name}</div>}
                  </div>
                </div>
              ),
            },
            {
              h: 'الكود',
              r: (s: SupplierRow) => (
                <span className="num text-[11.5px] font-semibold v-sub px-2 py-1 rounded-md tx2">{s.code}</span>
              ),
            },
            {
              h: 'الهاتف',
              r: (s: SupplierRow) => (
                <span className="num text-[12px] tx2 ltr" dir="ltr">
                  {s.phone}
                </span>
              ),
            },
            {
              h: 'مدة السداد',
              align: 'center',
              r: (s: SupplierRow) => (
                <span className="text-[12px] tx2">
                  <Num>{s.terms}</Num> يوم
                </span>
              ),
            },
            {
              h: 'الرصيد',
              r: (s: SupplierRow) => (
                <div className="leading-tight">
                  <div className={'font-semibold ' + (s.balance > 0 ? 'c-red' : s.balance < 0 ? 'c-green' : 'tx3')}>
                    {fmtMoney(Math.abs(s.balance))}
                  </div>
                  <div className="text-[10.5px] tx3">
                    {s.balance > 0 ? 'له علينا' : s.balance < 0 ? 'لنا عنده' : 'خالص'}
                  </div>
                </div>
              ),
            },
            { h: 'التفعيل', r: (s: SupplierRow) => <ActiveDot on={s.active} /> },
            {
              h: '',
              r: (s: SupplierRow) => (
                <RowActions>
                  <button className="icon-btn !w-7 !h-7" onClick={() => setForm(s)}>
                    <Icon name="edit" size={13} />
                  </button>
                  <button
                    className="icon-btn !w-7 !h-7"
                    title="كشف حساب"
                    onClick={() => go({ m: 'suppliers', id: s.id })}
                  >
                    <Icon name="file" size={13} />
                  </button>
                </RowActions>
              ),
            },
          ]}
        />
      </Card>
      <Pager total={list.data?.meta.total ?? 0} per={20} page={page} onPage={setPage} />
      {form && <SupplierForm blocked={list.usingMock} row={form.id ? (form as SupplierRow) : null} onClose={() => setForm(null)} onSaved={() => list.reload()} />}
    </div>
  );
}

export function SupplierForm({ row, onClose, onSaved, blocked }: { row: SupplierRow | null; onClose: () => void; onSaved?: () => void; blocked?: boolean }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({
    name: row?.name ?? '', company: row?.company ?? '', phone: row?.phone ?? '', email: (row as any)?.email ?? '',
    tax: (row as any)?.raw?.tax_number ?? '', cr: (row as any)?.raw?.commercial_register ?? '', terms: row?.terms ?? 30, opening: 0, address: (row as any)?.raw?.address ?? '', active: row?.active ?? true, code: (row as any)?.code ?? '',
  });
  const save = async () => {
    if (blocked || (row as any)?.demo) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red');
    if (!f.v.name.trim() || !f.v.code.trim()) return toast('اسم المورد والكود مطلوبان', 'red');
    const body: any = {
      code: f.v.code,
      name: f.v.name, company_name: (f.v.company as string) || null, email: (f.v.email as string) || null,
      phone: (f.v.phone as string) || null, tax_number: (f.v.tax as string) || null, commercial_register: (f.v.cr as string) || null,
      address: (f.v.address as string) || null, payment_terms_days: Number(f.v.terms || 0),
      opening_balance: row ? undefined : Number(f.v.opening || 0), is_active: !!f.v.active,
    };
    const r = await run(() => (row ? suppliersApi.update((row as any).id, body) : suppliersApi.create(body)),
      { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { company_name: 'company', payment_terms_days: 'terms', opening_balance: 'opening', tax_number: 'tax', commercial_register: 'cr', is_active: 'active' })) });
    if (r) { onSaved?.(); onClose(); }
  };
  return (
    <Modal
      open
      onClose={onClose}
      title={row ? 'تعديل مورد' : 'مورد جديد'}
      icon="factory"
      width={640}
      footer={
        <>
          <button className="btn btn-soft" onClick={onClose}>
            إلغاء
          </button>
          <button
            className="btn btn-dark"
            disabled={busy}
            onClick={save}
          >
            حفظ
          </button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="الكود" req hint="فريد" err={f.errors.code}>
            <input className="inp num" dir="ltr" value={f.v.code} onChange={f.on('code' as any)} placeholder="SUP-0001" />
          </Field>
          <Field label="اسم جهة الاتصال" req err={f.errors.name}>
            <input className="inp" value={f.v.name} onChange={f.on('name')} />
          </Field>
          <Field label="اسم الشركة / المؤسسة" err={f.errors.company}>
            <input className="inp" value={f.v.company} onChange={f.on('company' as any)} />
          </Field>
          <Field label="الهاتف" req err={f.errors.phone}>
            <input className="inp num" dir="ltr" value={f.v.phone} onChange={f.on('phone' as any)} />
          </Field>
          <Field label="البريد الإلكتروني" err={f.errors.email}>
            <input className="inp num" dir="ltr" type="email" value={f.v.email} onChange={f.on('email' as any)} />
          </Field>
          <Field label="الرقم الضريبي" err={f.errors.tax}>
            <input className="inp num" dir="ltr" value={f.v.tax} onChange={f.on('tax' as any)} />
          </Field>
          <Field label="السجل التجاري" err={f.errors.cr}>
            <input className="inp num" dir="ltr" value={f.v.cr} onChange={f.on('cr' as any)} />
          </Field>
          <Field label="مدة السداد الافتراضية (أيام)" req err={f.errors.terms}>
            <input type="number" min="0" className="inp num" value={f.v.terms} onChange={f.on('terms' as any)} />
          </Field>
          {!row && (
            <Field label="الرصيد الافتتاحي" err={f.errors.opening}>
              <input type="number" step="0.01" className="inp num" value={f.v.opening} onChange={f.on('opening' as any)} />
            </Field>
          )}
        </div>
        <Field label="العنوان" err={f.errors.address}>
          <input className="inp" value={f.v.address} onChange={f.on('address' as any)} />
        </Field>
        <ToggleRow label="مفعّل" on={f.v.active} onChange={(v) => f.set('active', v)} />
      </div>
    </Modal>
  );
}

export function SupplierDetail({ id, go }: { id: number; go: (r: { m: string; id?: any; sub?: any }) => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const sup = useRemote(signal => suppliersApi.show(id, signal).then(mapSupplier), [id], { mock: () => mapSupplier(mockSuppliers.find(s => s.id === id) ?? mockSuppliers[0]) });
  const usersRemote = useRemote(signal => suppliersApi.users(id, signal), [id], { mock: () => [] });
  const posRemote = useRemote(signal => allPages(page => purchaseOrdersApi.list({ page, supplier_id: id }, signal)).then(p => p.map(mapPO)), [id], { mock: () => mockPOs.filter(p => p.supplier_id === id).map(mapPO) });
  const invRemote = useRemote(signal => allPages(page => supplierInvoicesApi.list({ page, supplier_id: id }, signal)).then(p => p.map(mapInvoice)), [id], { mock: () => mockInvoices.filter(p => p.supplier_id === id).map(mapInvoice) });
  const payRemote = useRemote(signal => allPages(page => supplierPaymentsApi.list({ page, supplier_id: id }, signal)).then(p => p.map(mapPayment)), [id], { mock: () => mockPayments.filter(p => p.supplier_id === id).map(mapPayment) });
  const [tab, setTab] = useState<'info' | 'users' | 'pos' | 'invs' | 'pays'>('info');
  const [uform, setUform] = useState<any>(null);
  const [udel, setUdel] = useState<any>(null);
  const [edit, setEdit] = useState(false);
  const [deleteSupplier, setDeleteSupplier] = useState(false);
  const [userErrors, setUserErrors] = useState<Record<string, string>>({});
  const users = (usersRemote.data ?? []).map(u => ({ ...u, active: u.is_active }));
  const pos = posRemote.data ?? [], invs = invRemote.data ?? [], pays = payRemote.data ?? [];
  const saveUser = async () => {
    if (sup.usingMock || usersRemote.usingMock) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red');
    const body = { name: uform.name ?? '', email: uform.email ?? '', phone: uform.phone || null, is_active: uform.active ?? true, ...(uform.password ? { password: uform.password } : {}) };
    const result = await run(() => uform.id ? suppliersApi.updateUser(id, uform.id, body) : suppliersApi.createUser(id, body), { toast, success: uform.id ? 'تم الحفظ؛ تغيير البريد أو كلمة المرور أو التعطيل يسجّل خروج المستخدم من كل الأجهزة' : 'تم إنشاء الحساب', onFieldErrors: setUserErrors });
    if (result) { setUform(null); usersRemote.reload(); }
  };
  const s = sup.data;
  if (!s) return <Card><RemoteFeedback state={sup} /></Card>;
  return (
    <div className="space-y-5">
      <PageHead
        back={{ label: 'الموردون', onClick: () => go({ m: 'suppliers' }) }}
        title={
          <span className="flex items-center gap-3">
            {s.company || s.name}
            <ActiveDot on={s.active} />
          </span>
        }
        sub={
          <span>
            <span className="num">{s.code}</span> · {s.name}
          </span>
        }
        actions={
          <><button className="btn btn-soft" onClick={() => setEdit(true)}>
            <Icon name="edit" size={14} />
            تعديل
          </button><button className="btn btn-danger" disabled={busy || sup.usingMock} onClick={() => setDeleteSupplier(true)}><Icon name="trash" size={13} />حذف</button></>
        }
      />
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <div className="lg:col-span-4 v-card p-4 stg stg-2 self-start">
          <div className="v-orange rounded-[18px] p-4">
            <div className="text-[12.5px] text-white/90">الرصيد الحالي</div>
            <div className="font-display text-[28px] font-bold num my-1.5 ltr">
              {fmtNum(s.balance)} <span className="text-[13px] opacity-80">EGP</span>
            </div>
            <div className="text-[11px] text-white/80">
              {s.balance > 0 ? 'مستحق للمورد' : s.balance < 0 ? 'رصيد مدين لصالحنا' : 'لا يوجد رصيد'}
            </div>
          </div>
          <div className="grid grid-cols-3 gap-2 mt-3">
            <div className="v-sub p-3 text-center">
              <div className="text-[10.5px] tx3">أوامر</div>
              <Num className="font-display text-[18px] font-bold">{pos.length}</Num>
            </div>
            <div className="v-sub p-3 text-center">
              <div className="text-[10.5px] tx3">فواتير</div>
              <Num className="font-display text-[18px] font-bold">{invs.length}</Num>
            </div>
            <div className="v-sub p-3 text-center">
              <div className="text-[10.5px] tx3">مدفوعات</div>
              <Num className="font-display text-[18px] font-bold">{pays.length}</Num>
            </div>
          </div>
        </div>
        <div className="lg:col-span-8 space-y-4">
          <TabsBar
            value={tab}
            onChange={(v: any) => setTab(v)}
            tabs={[
              { value: 'info', label: 'البيانات الأساسية' },
              { value: 'users', label: 'حسابات الموظفين', count: users.length },
              { value: 'pos', label: 'أوامر الشراء', count: pos.length },
              { value: 'invs', label: 'الفواتير', count: invs.length },
              { value: 'pays', label: 'المدفوعات', count: pays.length },
            ]}
          />
          {tab === 'info' && (
            <Card stg={3}>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-10 gap-y-1">
                <KV
                  items={[
                    ['الكود', <Num>{s.code}</Num>],
                    ['الاسم', s.name],
                    ['اسم الشركة', s.company || '—'],
                    ['الهاتف', <Num>{s.phone}</Num>],
                    ['البريد', s.email ? <Num>{s.email}</Num> : null],
                  ]}
                />
                <KV
                  items={[
                    ['الرقم الضريبي', <Num>{s.raw.tax_number ?? '—'}</Num>],
                    ['السجل التجاري', <Num>{s.raw.commercial_register ?? '—'}</Num>],
                    [
                      'مدة السداد',
                      <>
                        <Num>{s.terms}</Num> يوم
                      </>,
                    ],
                    ['الرصيد الافتتاحي', fmtMoney(num(s.raw.opening_balance))],
                    ['العنوان', s.raw.address],
                  ]}
                />
              </div>
            </Card>
          )}
          {tab === 'users' && (
            <Card stg={3}>
              <RemoteFeedback state={usersRemote} />
              <CardHead
                title="حسابات موظفي المورد"
                sub="للدخول إلى بوابة الموردين"
                right={
                  <button className="btn btn-dark btn-sm" onClick={() => { setUserErrors({}); setUform({}); }}>
                    <Icon name="plus" size={13} />
                    حساب جديد
                  </button>
                }
              />
              <DataTable
                rows={users}
                cols={[
                  {
                    h: 'الموظف',
                    r: (u: any) => (
                      <div className="flex items-center gap-2.5">
                        <Avatar name={u.name} tone={u.id + 2} size={28} />
                        <span className="font-semibold tx1">{u.name}</span>
                      </div>
                    ),
                  },
                  { h: 'البريد', r: (u: any) => <Num className="tx2 text-[12px]">{u.email}</Num> },
                  {
                    h: 'الهاتف',
                    r: (u: any) => (u.phone ? <Num className="tx2 text-[12px]">{u.phone}</Num> : <span className="tx3">—</span>),
                  },
                  { h: 'التفعيل', r: (u: any) => <ActiveDot on={u.active} /> },
                  {
                    h: '',
                    r: (u: any) => (
                      <RowActions>
                        <button className="icon-btn !w-7 !h-7" onClick={() => { setUserErrors({}); setUform(u); }}>
                          <Icon name="edit" size={13} />
                        </button>
                        <button className="icon-btn !w-7 !h-7" onClick={() => setUdel(u)}>
                          <Icon name="trash" size={13} />
                        </button>
                      </RowActions>
                    ),
                  },
                ]}
              />
            </Card>
          )}
          {tab === 'pos' && (
            <Card stg={3}>
              <RemoteFeedback state={posRemote} />
              <DataTable
                rows={pos}
                onRow={() => go({ m: 'purchasing', sub: 'po' })}
                cols={[
                  { h: 'رقم الأمر', r: (p: any) => <span className="num font-semibold">{p.no}</span> },
                  { h: 'الفرع', r: (p: any) => <span className="tx2">{p.branch}</span> },
                  { h: 'الحالة', r: (p: any) => <StatusBadge map={PO_STATUS} value={p.status} /> },
                  { h: 'الإجمالي', r: (p: any) => fmtMoney(p.total) },
                ]}
                empty="لا توجد أوامر شراء لهذا المورد"
              />
            </Card>
          )}
          {tab === 'invs' && (
            <Card stg={3}>
              <RemoteFeedback state={invRemote} />
              <DataTable
                rows={invs}
                cols={[
                  { h: 'رقم النظام', r: (i: any) => <span className="num font-semibold">{i.sys}</span> },
                  { h: 'رقم المورد', r: (i: any) => <Num className="tx2">{i.supNo}</Num> },
                  { h: 'الإجمالي', r: (i: any) => fmtMoney(i.subtotal + i.tax) },
                  {
                    h: 'المتبقي',
                    r: (i: any) => fmtMoney(i.subtotal + i.tax - i.paid),
                  },
                  { h: 'الحالة', r: (i: any) => <StatusBadge map={INV_STATUS} value={i.status} /> },
                ]}
                empty="لا توجد فواتير"
              />
            </Card>
          )}
          {tab === 'pays' && (
            <Card stg={3}>
              <RemoteFeedback state={payRemote} />
              <DataTable
                rows={pays}
                cols={[
                  { h: 'رقم الدفعة', r: (p: any) => <span className="num font-semibold">{p.no}</span> },
                  { h: 'الوسيلة', r: (p: any) => SP_METHODS[p.method as keyof typeof SP_METHODS] },
                  { h: 'المبلغ', r: (p: any) => fmtMoney(p.amount) },
                  { h: 'التاريخ', r: (p: any) => <Num className="tx2">{p.date}</Num> },
                ]}
                empty="لا توجد مدفوعات"
              />
            </Card>
          )}
        </div>
      </div>
      <ConfirmModal open={deleteSupplier} onClose={() => setDeleteSupplier(false)} danger title="حذف المورد" text="لا يمكن حذف مورد مرتبط بأوامر أو فواتير أو دفعات أو مستخدمين." cta="حذف" onConfirm={async () => { if (sup.usingMock) return; const result = await run(() => suppliersApi.remove(id), { toast, success: 'تم حذف المورد' }); if (result) { go({ m: 'suppliers' }); return true; } return false; }} />
      <RemoteFeedback state={sup} />
      {edit && <SupplierForm blocked={sup.usingMock} row={s} onClose={() => setEdit(false)} onSaved={sup.reload} />}
      {uform && (
        <Modal
          open
          onClose={() => setUform(null)}
          title={uform.id ? 'تعديل حساب موظف' : 'حساب موظف جديد'}
          icon="user"
          width={480}
          footer={
            <>
              <button className="btn btn-soft" onClick={() => setUform(null)}>
                إلغاء
              </button>
              <button
                className="btn btn-dark"
                disabled={busy} onClick={saveUser}
              >
                حفظ
              </button>
            </>
          }
        >
          <div className="space-y-4">
            <Field label="الاسم" req err={userErrors.name}>
              <input className="inp" value={uform.name ?? ''} onChange={e => setUform({ ...uform, name: e.target.value })} />
            </Field>
            <Field label="البريد الإلكتروني" req err={userErrors.email}>
              <input className="inp num" dir="ltr" value={uform.email ?? ''} onChange={e => setUform({ ...uform, email: e.target.value })} />
            </Field>
            <Field label="الهاتف">
              <input className="inp num" dir="ltr" value={uform.phone ?? ''} onChange={e => setUform({ ...uform, phone: e.target.value })} />
            </Field>
            <Field label="كلمة المرور" req={!uform.id} err={userErrors.password}><input type="password" className="inp" value={uform.password ?? ''} onChange={e => setUform({ ...uform, password: e.target.value })} autoComplete="new-password" /></Field>
            <ToggleRow label="مفعّل" on={uform.active ?? true} onChange={active => setUform({ ...uform, active })} />
          </div>
        </Modal>
      )}
      <ConfirmModal
        open={!!udel}
        onClose={() => setUdel(null)}
        danger
        title={'حذف حساب «' + (udel?.name || '') + '»'}
        cta="حذف الحساب"
        text="لن يتمكن الموظف من الدخول إلى بوابة الموردين بعد الحذف."
        onConfirm={async () => { if (sup.usingMock || usersRemote.usingMock) return; const result = await run(() => suppliersApi.removeUser(id, udel.id), { toast, success: 'تم حذف الحساب وتسجيل الخروج من الأجهزة' }); if (result) { setUdel(null); usersRemote.reload(); return true; } return false; }}
      />
    </div>
  );
}

/* ---------------- PURCHASING PAGE ---------------- */
export function PurchasingPage({ sub = 'pr' }: { sub?: string }) {
  const [tab, setTab] = useState(sub);
  const [paymentInvoice, setPaymentInvoice] = useState<any>(null);
  useEffect(() => setTab(sub), [sub]);

  return (
    <div className="space-y-5">
      <PageHead title="المشتريات" sub="أربع وحدات مترابطة: من طلب الشراء حتى سداد فاتورة المورد." />
      <div className="flex items-center gap-2 flex-wrap stg stg-2">
        {[
          ['pr', 'طلبات الشراء', 'file'],
          ['po', 'أوامر الشراء', 'cart'],
          ['inv', 'فواتير الموردين', 'receipt'],
          ['pay', 'مدفوعات الموردين', 'cash'],
        ].map(([k, l, ic], i) => (
          <Fragment key={k}>
            {i > 0 && <Icon name="chevL" size={14} className="tx3" />}
            <button
              onClick={() => setTab(k)}
              className={'v-pill px-4 py-2 flex items-center gap-2 text-[13px] font-medium transition'}
              style={
                tab === k
                  ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)', borderColor: 'transparent' }
                  : { color: 'var(--text-secondary)' }
              }
            >
              <span className={'w-6 h-6 rounded-full flex items-center justify-center ' + (tab === k ? 'bg-white/15' : 'v-well')}>
                <Icon name={ic} size={12} />
              </span>
              {l}
            </button>
          </Fragment>
        ))}
      </div>
      {tab === 'pr' && <PRModule />}
      {tab === 'po' && <POModule />}
      {tab === 'inv' && <InvModule onPayment={invoice => { setPaymentInvoice(invoice); setTab('pay'); }} />}
      {tab === 'pay' && <PayModule initialInvoice={paymentInvoice} />}
    </div>
  );
}

export function PRModule() {
  const canApprove = useCan('purchase_requests.approve');
  const toast = useToast();
  const { busy, run } = useAction();
  const [st, setSt] = useState('');
  const [br, setBr] = useState('');
  const [form, setForm] = useState<any>(null);
  const [view, setView] = useState<any>(null);

  const [page, setPage] = useState(1);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => setPage(1), [st, br]);
  const list = useRemote(signal => purchaseRequestsApi.list({ page, status: st ? st as any : undefined, branch_id: br ? Number(br) : undefined }, signal), [page, st, br], { mock: () => pageOf(mockPRs.filter(p => !st || p.status === st)) });
  const branches = useRemote((signal) => branchesApi.list({}, signal).then(r => r.data), []);
  const variants = useVariants();
  const rows = (list.data?.data ?? []).map(mapPR);
  const detail = useRemote(signal => purchaseRequestsApi.show(view.id, signal), [view?.id], { enabled: !!view, mock: () => mockPRs.find(p => p.id === view.id)! });
  const openForm = (pr?: any) => { setErrors({}); setForm({ demo: list.usingMock || pr?.demo, id: pr?.id, no: pr?.no, branchId: pr?.raw?.branch_id ?? '', notes: pr?.raw?.notes ?? '', items: pr?.raw?.items?.map((it: any) => ({ product_variant_id: it.product_variant_id, qty: it.requested_qty, notes: it.notes ?? '' })) ?? [blankItem()] }); };
  const setItem = (index: number, key: string, value: string) => setForm({ ...form, items: form.items.map((it: any, i: number) => i === index ? { ...it, [key]: value } : it) });
  const doAction = async (id: number, fn: (id: number) => Promise<unknown>, msg: string) => {
    if ((fn === purchaseRequestsApi.approve || fn === purchaseRequestsApi.reject) && !canApprove) return toast('ليست لديك صلاحية اعتماد طلبات الشراء', 'red');
    if (list.usingMock || view?.demo) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red');
    const r = await run(() => fn(id), { toast, success: msg });
    if (r) { list.reload(); setView(null); }
  };
  const save = async (submit = false) => {
    if (form.demo || list.usingMock || variants.usingMock || branches.usingMock) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red');
    if (!form.branchId || !form.items.length || form.items.some((it: any) => !it.product_variant_id || !Number.isInteger(Number(it.qty)) || Number(it.qty) <= 0)) return toast('اختر الفرع وأدخل متغيرات وكميات صحيحة', 'red');
    const body = { branch_id: Number(form.branchId), notes: form.notes || null, items: form.items.map((it: any) => ({ product_variant_id: Number(it.product_variant_id), requested_qty: Number(it.qty), notes: it.notes || null })) };
    const r = await run(() => form.id ? purchaseRequestsApi.update(form.id, body) : purchaseRequestsApi.create(body), { toast, onFieldErrors: setErrors, success: 'تم حفظ المسودة' });
    if (!r) return;
    if (submit) { const sent = await run(() => purchaseRequestsApi.submit(r.id), { toast, success: 'أُرسل للاعتماد' }); if (!sent) { setForm({ ...form, id: r.id }); list.reload(); return; } }
    setForm(null); list.reload();
  };

  return (
    <>
      <Card stg={3}>
        <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
          <div className="flex flex-wrap gap-2">
            <FSelect
              value={st}
              onChange={setSt}
              placeholder="كل الحالات"
              options={Object.entries(PR_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
            />
            <FSelect value={br} onChange={setBr} placeholder="كل الفروع" options={(branches.data ?? []).map((b: { id: number; name: string }) => ({ value: String(b.id), label: b.name }))} />
          </div>
          <button className="btn btn-dark btn-sm" onClick={() => openForm()}>
            <Icon name="plus" size={13} />
            طلب شراء
          </button>
        </div>
        <RemoteFeedback state={list} />
        <DataTable
          rows={rows}
          onRow={setView}
          cols={[
            { h: 'رقم الطلب', r: (p: PurchaseRequest) => <span className="num font-semibold tx1">{p.no}</span> },
            { h: 'الفرع', r: (p: PurchaseRequest) => <span className="tx2">{p.branch}</span> },
            { h: 'الحالة', r: (p: PurchaseRequest) => <StatusBadge map={PR_STATUS} value={p.status} /> },
            {
              h: 'مقدم الطلب',
              r: (p: PurchaseRequest) => (
                <div className="flex items-center gap-2">
                  <Avatar name={p.by} tone={p.id} size={24} />
                  <span className="text-[12px]">{p.by}</span>
                </div>
              ),
            },
            {
              h: 'البنود',
              align: 'center',
              r: (p: PurchaseRequest) => <Num>{p.items}</Num>,
            },
            { h: 'التاريخ', r: (p: PurchaseRequest) => <Num className="tx2">{p.date}</Num> },
            {
              h: '',
              r: (p: PurchaseRequest) => (
                <RowActions>
                  {p.status === 'draft' && (
                    <>
                      <button className="btn btn-soft btn-xs" onClick={() => openForm(p)}>
                        تعديل
                      </button>
                      <button className="btn btn-dark btn-xs" disabled={busy} onClick={() => doAction(p.id, purchaseRequestsApi.submit, 'أُرسل للاعتماد')}>
                        إرسال للاعتماد
                      </button>
                      <button className="icon-btn !w-7 !h-7" disabled={busy} onClick={() => doAction(p.id, purchaseRequestsApi.remove, 'تم حذف المسودة')}>
                        <Icon name="trash" size={12} />
                      </button>
                    </>
                  )}
                  {p.status === 'pending' && canApprove && (
                    <>
                      <button className="btn btn-danger btn-xs" disabled={busy} onClick={() => doAction(p.id, purchaseRequestsApi.reject, 'تم الرفض')}>
                        رفض
                      </button>
                      <button className="btn btn-success btn-xs" disabled={busy} onClick={() => doAction(p.id, purchaseRequestsApi.approve, 'تم الاعتماد')}>
                        اعتماد
                      </button>
                    </>
                  )}
                </RowActions>
              ),
            },
          ]}
        />
      </Card>
      <Pager total={list.data?.meta.total ?? 0} per={20} page={page} onPage={setPage} />
      {form && (
        <Modal
          open
          onClose={() => setForm(null)}
          title={form.id ? 'تعديل طلب الشراء ' + form.no : 'طلب شراء جديد'}
          icon="file"
          width={680}
          footer={
            <>
              <button className="btn btn-soft" onClick={() => setForm(null)}>
                إلغاء
              </button>
              <button
                className="btn btn-soft"
                disabled={busy} onClick={() => save(false)}
              >
                حفظ كمسودة
              </button>
              <button
                className="btn btn-dark"
                disabled={busy} onClick={() => save(true)}
              >
                حفظ وإرسال للاعتماد
              </button>
            </>
          }
        >
          <div className="space-y-4">
            <Field label="الفرع" req>
              <select className="inp" value={form.branchId} onChange={e => setForm({ ...form, branchId: e.target.value })}>
                <option value="">اختر الفرع</option>
                {(branches.data ?? []).map((b) => (
                  <option key={b.id} value={b.id}>{b.name}</option>
                ))}
              </select>
            </Field>
            <RemoteFeedback state={variants} />
            <ItemsEditor
              items={form.items} onItemsChange={items => setForm({ ...form, items })}
              cols={[
                { h: 'متغير المنتج', fr: '2.2fr', el: (i) => <VSel variants={variants.data ?? []} value={form.items[i].product_variant_id} onChange={v => setItem(i, 'product_variant_id', v)} /> },
                { h: 'الكمية', fr: '.8fr', el: (i) => <NInp value={form.items[i].qty} onChange={v => setItem(i, 'qty', v)} /> },
                {
                  h: 'ملاحظات البند (255)',
                  fr: '1.6fr',
                  el: (i) => <input value={form.items[i].notes} onChange={e => setItem(i, 'notes', e.target.value)} maxLength={255} className="inp inp-sm !bg-[var(--bg-card)]" placeholder="اختياري" />,
                },
              ]}
            />
            <TextArea label="ملاحظات" max={2000} value={form.notes} onChange={notes => setForm({ ...form, notes })} />
            {Object.keys(errors).length > 0 && <Note tone="red">{Object.values(errors).join(' · ')}</Note>}
          </div>
        </Modal>
      )}
      {view && (
        <Modal
          open
          onClose={() => setView(null)}
          title={
            <span className="flex items-center gap-2">
              <span className="num">{view.no}</span>
              <StatusBadge map={PR_STATUS} value={view.status} />
            </span>
          }
          icon="file"
          width={600}
          footer={
            view.status === 'pending' && canApprove ? (
              <>
                <button
                  className="btn btn-danger"
                  disabled={busy} onClick={() => doAction(view.id, purchaseRequestsApi.reject, 'تم الرفض')}
                >
                  رفض
                </button>
                <button
                  className="btn btn-dark"
                  disabled={busy} onClick={() => doAction(view.id, purchaseRequestsApi.approve, 'تم الاعتماد')}
                >
                  اعتماد
                </button>
              </>
            ) : view.status === 'draft' ? (
              <>
                <button className="btn btn-soft" disabled={busy} onClick={() => doAction(view.id, purchaseRequestsApi.remove, 'تم حذف المسودة')}>
                  حذف
                </button>
                <button
                  className="btn btn-dark"
                  disabled={busy} onClick={() => doAction(view.id, purchaseRequestsApi.submit, 'أُرسل للاعتماد')}
                >
                  إرسال للاعتماد
                </button>
              </>
            ) : null
          }
        >
          <div className="space-y-4 pb-2">
            <RemoteFeedback state={detail} />
            <KV
              items={[
                ['الفرع', view.branch],
                ['مقدم الطلب', view.by],
                ['التاريخ', <Num>{view.date}</Num>],
              ]}
            />
            <DataTable
              compact
              rows={(detail.data?.items ?? []).map(it => ({ v: { name: it.variant?.sku ?? `#${it.product_variant_id}`, sku: it.variant?.sku ?? '' }, q: it.requested_qty, n: it.notes }))}
              cols={[
                {
                  h: 'المتغير',
                  r: (r: any) => (
                    <div className="leading-tight">
                      <div className="font-medium tx1">{r.v.name}</div>
                      <div className="text-[11px] tx3 num">{r.v.sku}</div>
                    </div>
                  ),
                },
                {
                  h: 'الكمية',
                  r: (r: any) => <Num className="font-semibold">{r.q}</Num>,
                },
                {
                  h: 'ملاحظات',
                  r: (r: any) => r.n || <span className="tx3">—</span>,
                },
              ]}
            />
          </div>
        </Modal>
      )}
    </>
  );
}

export function ReceivePOModal({ open, po, lines, onClose, onSaved, blocked }: { open: boolean; po: any; lines: any[]; onClose: () => void; onSaved: () => void; blocked?: boolean }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [qtys, setQtys] = useState<Record<number, number>>({});
  const [idemKey] = useState(newIdempotencyKey);
  const [deliveryId, setDeliveryId] = useState('');
  if (!open) return null;
  const save = async () => {
    if (!po || po.demo || blocked) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red');
    if (lines.some((l: any, i: number) => !Number.isInteger(Number(qtys[i] ?? 0)) || Number(qtys[i] ?? 0) < 0 || Number(qtys[i] ?? 0) > l.ord - l.rec)) return toast('الكمية المستلمة يجب ألا تتجاوز المتبقي', 'red');
    const items = lines.map((l: any, i: number) => ({ purchase_order_item_id: l.id, qty: Number(qtys[i] ?? 0) })).filter((x) => x.qty > 0);
    if (!items.length) return toast('أدخل كمية مستلمة واحدة على الأقل', 'red');
    const r = await run(() => purchaseOrdersApi.receive(po.id, { items, supplier_delivery_id: deliveryId ? Number(deliveryId) : null }, idemKey), { toast, success: 'تم تسجيل الاستلام' });
    if (r) onSaved();
  };
  return (
    <Modal open onClose={onClose} title={'استلام ' + (po?.no || '')} icon="box" width={620}
      footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-accent" disabled={busy} onClick={save}>تأكيد الاستلام</button></>}>
      <div className="space-y-4">
        <Field label="إشعار التوريد" hint="حدد الإشعار عند وجود توريد معلق"><select className="inp" value={deliveryId} onChange={e => setDeliveryId(e.target.value)}><option value="">بدون</option>{(po?.raw?.deliveries ?? []).filter((d: any) => d.status === 'pending_receipt').map((d: any) => <option key={d.id} value={d.id}>{d.delivery_number}</option>)}</select></Field>
        <div className="v-sub p-2">
          <div className="grid grid-cols-12 gap-2 px-2 py-1 text-[11px] tx3">
            <span className="col-span-6">البند</span>
            <span className="col-span-2 text-center">مطلوب</span>
            <span className="col-span-2 text-center">سابق</span>
            <span className="col-span-2 text-center">الآن</span>
          </div>
          {lines.map((l: any, i: number) => (
            <div key={i} className="grid grid-cols-12 gap-2 items-center px-2 py-1.5">
              <span className="col-span-6 text-[12.5px] font-medium clamp1">{l.v.name}</span>
              <Num className="col-span-2 text-center">{l.ord}</Num>
              <Num className="col-span-2 text-center tx3">{l.rec}</Num>
              <input type="number" min="0" max={l.ord - l.rec}
                value={qtys[i] ?? 0}
                onChange={(e) => setQtys({ ...qtys, [i]: Number(e.target.value) })}
                className="inp inp-sm col-span-2 num text-center !bg-[var(--bg-card)]" />
            </div>
          ))}
        </div>
      </div>
    </Modal>
  );
}

export function POModule() {
  const canApprove = useCan('purchases.approve');
  const canReceive = useCan('purchases.receive');
  const toast = useToast();
  const { busy, run } = useAction();
  const [q, setQ] = useState('');
  const [st, setSt] = useState('');
  const [sp, setSp] = useState('');
  const [br, setBr] = useState('');
  const [form, setForm] = useState<any>(null);
  const [view, setView] = useState<any>(null);
  const [recv, setRecv] = useState(false);
  const [closeConfirm, setCloseConfirm] = useState(false);
  const [fromPR, setFromPR] = useState('');
  const dq = useDebounced(q);

  const [page, setPage] = useState(1);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => setPage(1), [dq, st, sp, br]);
  const list = useRemote(signal => purchaseOrdersApi.list({ page, search: dq || undefined, status: st ? toPOStatus(st) as any : undefined, supplier_id: sp ? Number(sp) : undefined, branch_id: br ? Number(br) : undefined }, signal), [page, dq, st, sp, br], { mock: () => pageOf(mockPOs.filter(p => (!st || p.status === toPOStatus(st)) && (!sp || p.supplier_id === Number(sp)))) });
  const suppliers = useSupplierLookup();
  const branches = useRemote((signal) => branchesApi.list({}, signal).then(r => r.data), []);
  const approvedPRs = useRemote(signal => allPages(page => purchaseRequestsApi.list({ page, status: 'approved' }, signal)), []);
  const variants = useVariants();
  const rows = (list.data?.data ?? []).map(mapPO);
  const detail = useRemote(signal => purchaseOrdersApi.show(view.id, signal), [view?.id], { enabled: !!view, mock: () => mockPOs.find(p => p.id === view.id)! });
  const supName = (id: number) => suppliers.data?.find(x => x.id === id)?.company || suppliers.data?.find(x => x.id === id)?.name || `#${id}`;
  const openForm = (po?: any) => { const raw = po?.raw; setErrors({}); setFromPR(raw?.purchase_request_id ? String(raw.purchase_request_id) : ''); setForm({ demo: list.usingMock || po?.demo, id: po?.id, sup: raw?.supplier_id ?? '', branch: raw?.branch_id ?? '', eta: raw?.expected_delivery_date ?? '', discount: num(raw?.discount_amount), items: raw?.items?.map((it: any) => ({ product_variant_id: it.product_variant_id, qty: it.ordered_qty, cost: it.unit_cost, tax: it.tax_rate })) ?? [blankItem()] }); };
  const setItem = (index: number, key: string, value: string) => setForm({ ...form, items: form.items.map((it: any, i: number) => i === index ? { ...it, [key]: value } : it) });
  const act = async (id: number, fn: (id: number) => Promise<unknown>, msg: string) => { if (list.usingMock || view?.demo) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red'); const result = await run(() => fn(id), { toast, success: msg }); if (result) { setView(null); list.reload(); return true; } return false; };
  const actionsFor = (p: any): string[] => {
    if (['draft', 'revision'].includes(p.status)) return ['edit', ...(p.raw?.approved_by ? ['send'] : canApprove ? ['approve'] : []), ...(p.status === 'draft' ? ['delete'] : []), 'cancel'];
    return (({ accepted: ['receive', 'cancel'], sent: ['receive', 'cancel'], partial: ['receive', 'close'] } as Record<string, string[]>)[p.status] ?? []).filter(action => canReceive || !['receive', 'close'].includes(action));
  };
  const lines = (detail.data?.items ?? []).map(it => ({ id: it.id, v: { name: it.variant?.sku ?? `#${it.product_variant_id}`, sku: it.variant?.sku ?? '' }, ord: num(it.ordered_qty), rec: num(it.received_qty), cost: num(it.unit_cost), tax: num(it.tax_rate) }));
  const save = async () => {
    if (form.demo || list.usingMock || suppliers.usingMock || variants.usingMock) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red');
    if (!form.sup || !form.branch || form.items.some((it: any) => !it.product_variant_id || !Number.isInteger(Number(it.qty)) || Number(it.qty) <= 0)) return toast('أكمل المورد والفرع والبنود بكميات صحيحة', 'red');
    const body = { supplier_id: Number(form.sup), ...(form.id ? {} : { purchase_request_id: fromPR ? Number(fromPR) : null }), branch_id: Number(form.branch), items: form.items.map((it: any) => ({ product_variant_id: Number(it.product_variant_id), ordered_qty: Number(it.qty), unit_cost: Number(it.cost), tax_rate: Number(it.tax) })), expected_delivery_date: form.eta || null, discount_amount: Number(form.discount || 0) };
    const result = await run(() => form.id ? purchaseOrdersApi.update(form.id, body) : purchaseOrdersApi.create(body), { toast, success: 'تم حفظ الأمر كمسودة', onFieldErrors: setErrors });
    if (result) { setForm(null); list.reload(); }
  };

  return (
    <>
      <Card stg={3}>
        <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
          <div className="flex flex-wrap gap-2">
            <SearchPill value={q} onChange={setQ} placeholder="رقم الأمر" w="w-32" />
            <FSelect
              value={st}
              onChange={setSt}
              placeholder="الحالة"
              options={Object.entries(PO_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
            />
            <FSelect
              value={sp}
              onChange={setSp}
              placeholder="المورد"
              options={(suppliers.data ?? []).map((s: { id: number; name: string }) => ({ value: String(s.id), label: s.name }))}
            />
            <FSelect value={br} onChange={setBr} placeholder="الفرع" options={(branches.data ?? []).map((b: { id: number; name: string }) => ({ value: String(b.id), label: b.name }))} />
          </div>
          <button className="btn btn-dark btn-sm" onClick={() => openForm()}>
            <Icon name="plus" size={13} />
            أمر شراء
          </button>
        </div>
        <RemoteFeedback state={list} />
        <DataTable
          rows={rows}
          onRow={setView}
          cols={[
            { h: 'رقم الأمر', r: (p: PurchaseOrder) => <span className="num font-semibold tx1">{p.no}</span> },
            {
              h: 'المورد',
              r: (p: PurchaseOrder) => (
                <div className="flex items-center gap-2">
                  <Avatar name={supName(p.sup)} tone={p.sup} size={24} />
                  <span className="font-medium">{supName(p.sup)}</span>
                </div>
              ),
            },
            { h: 'الفرع', r: (p: PurchaseOrder) => <span className="tx2 text-[12px]">{p.branch}</span> },
            { h: 'الحالة', r: (p: PurchaseOrder) => <StatusBadge map={PO_STATUS} value={p.status} /> },
            {
              h: 'التوريد المتوقع',
              r: (p: PurchaseOrder) => (p.eta ? <Num className="tx2">{p.eta}</Num> : <span className="tx3">—</span>),
            },
            {
              h: 'الإجمالي',
              r: (p: PurchaseOrder) => <span className="font-semibold">{fmtMoney(p.total)}</span>,
            },
          ]}
        />
      </Card>
      <Pager total={list.data?.meta.total ?? 0} per={20} page={page} onPage={setPage} />
      {view && (
        <Modal
          open
          onClose={() => setView(null)}
          width={820}
          icon="cart"
          title={
            <span className="flex items-center gap-2">
              <span className="num">{view.no}</span>
              <StatusBadge map={PO_STATUS} value={view.status} />
            </span>
          }
          sub={
            <>
              {supName(view.sup)} · {view.branch}
            </>
          }
          footer={
            <>
              {actionsFor(view).includes('delete') && (
                <button
                  className="btn btn-ghost me-auto"
                  disabled={busy} onClick={() => act(view.id, purchaseOrdersApi.remove, 'تم الحذف')}
                >
                  <Icon name="trash" size={13} />
                  حذف
                </button>
              )}
              {actionsFor(view).includes('cancel') && (
                <button
                  className="btn btn-danger"
                  disabled={busy}
                  onClick={() => act(view.id, (id) => purchaseOrdersApi.cancel(id), 'تم إلغاء الأمر')}
                >
                  إلغاء الأمر
                </button>
              )}
              {actionsFor(view).includes('edit') && (
                <button
                  className="btn btn-soft"
                  disabled={busy || detail.loading || detail.usingMock || !detail.data}
                  onClick={() => {
                    setView(null);
                    openForm(detail.data ? mapPO(detail.data) : view);
                  }}
                >
                  تعديل
                </button>
              )}
              {actionsFor(view).includes('close') && (
                <button
                  className="btn btn-soft"
                  disabled={busy} onClick={() => setCloseConfirm(true)}
                >
                  إغلاق بالمستلم
                </button>
              )}
              {actionsFor(view).includes('approve') && (
                <button className="btn btn-dark" disabled={busy} onClick={() => act(view.id, (id) => purchaseOrdersApi.approve(id), 'تم الاعتماد')}>
                  اعتماد
                </button>
              )}
              {actionsFor(view).includes('send') && (
                <button
                  className="btn btn-dark"
                  disabled={busy}
                  onClick={() => act(view.id, (id) => purchaseOrdersApi.send(id), 'أُرسل للمورد')}
                >
                  <Icon name="send" size={13} />
                  إرسال للمورد
                </button>
              )}
              {actionsFor(view).includes('receive') && (
                <button className="btn btn-accent" disabled={busy || detail.loading || detail.usingMock || !detail.data} onClick={() => setRecv(true)}>
                  <Icon name="box" size={13} />
                  استلام
                </button>
              )}
            </>
          }
        >
          <div className="space-y-4 pb-2">
            <RemoteFeedback state={detail} />
            {view.status === 'revision' && (
              <Note tone="amber" icon="quote">
                <b>ملاحظة تعديل من المورد:</b> {detail.data?.supplier_change_notes ?? '—'}
              </Note>
            )}
            <div className="grid grid-cols-3 gap-2">
              <div className="v-sub p-3">
                <div className="text-[11px] tx3">التوريد المتوقع</div>
                <Num className="font-semibold">{view.eta || '—'}</Num>
              </div>
              <div className="v-sub p-3">
                <div className="text-[11px] tx3">طلب الشراء المرتبط</div>
                <Num className="font-semibold">{detail.data?.purchase_request_id ?? '—'}</Num>
              </div>
              <div className="v-sub p-3">
                <div className="text-[11px] tx3">الإجمالي</div>
                <div className="font-semibold">{fmtMoney(view.total)}</div>
              </div>
            </div>
            <DataTable
              compact
              rows={lines}
              cols={[
                {
                  h: 'المتغير',
                  r: (l: any) => (
                    <div className="leading-tight">
                      <div className="font-medium tx1">{l.v.name}</div>
                      <div className="text-[11px] tx3 num">{l.v.sku}</div>
                    </div>
                  ),
                },
                {
                  h: 'المطلوب',
                  align: 'center',
                  r: (l: any) => <Num>{l.ord}</Num>,
                },
                {
                  h: 'المستلم',
                  r: (l: any) => (
                    <div className="flex items-center gap-2 min-w-[110px]">
                      <div className="flex-1">
                        <HatchBar pct={(l.rec / l.ord) * 100} h={9} />
                      </div>
                      <Num className="text-[11.5px]">{l.rec}</Num>
                    </div>
                  ),
                },
                { h: 'تكلفة الوحدة', r: (l: any) => fmtMoney(l.cost) },
                { h: 'الضريبة', r: (l: any) => <Num>{l.tax}%</Num> },
              ]}
            />
            {view.status === 'partial' && (
              <div>
                <div className="lbl mb-2">التوريدات المرتبطة</div>
                <div className="v-sub p-3 flex items-center justify-between text-[12px]">
                  <span className="flex items-center gap-2">
                    <Icon name="truck" size={14} className="tx2" />
                    <span className="num font-semibold">{detail.data?.deliveries?.map(d => d.delivery_number).join('، ') || '—'}</span>
                  </span>
                  <Num className="tx3">{detail.data?.deliveries?.[0]?.delivery_date ?? '—'}</Num>
                </div>
              </div>
            )}
          </div>
        </Modal>
      )}
      <ConfirmModal open={closeConfirm} onClose={() => setCloseConfirm(false)} title="إغلاق بالمستلم" text="سيُغلق أمر الشراء بالكميات المستلمة فقط" cta="إغلاق" onConfirm={async () => { const ok = await act(view.id, purchaseOrdersApi.closeShort, 'أُغلق الأمر بالكميات المستلمة'); if (ok) setCloseConfirm(false); return ok ?? false; }} />
      {recv && <ReceivePOModal blocked={list.usingMock || detail.usingMock} key={view?.id} open={recv} po={detail.data ? mapPO(detail.data) : view} lines={lines} onClose={() => setRecv(false)} onSaved={() => { setRecv(false); setView(null); list.reload(); }} />}
      {form && (
        <Modal
          open
          onClose={() => setForm(false)}
          title="أمر شراء"
          icon="cart"
          width={780}
          footer={
            <>
              <button className="btn btn-soft" onClick={() => setForm(false)}>
                إلغاء
              </button>
              <button
                className="btn btn-dark"
                disabled={busy} onClick={save}
              >
                حفظ
              </button>
            </>
          }
        >
          <div className="space-y-4">
            {Object.keys(errors).length > 0 && <Note tone="red">{Object.values(errors).join(' · ')}</Note>}
            <div className="grid grid-cols-2 gap-3">
              <Field label="المورد" req>
                <select className="inp" value={form.sup} onChange={e => setForm({ ...form, sup: e.target.value })}>
                  <option value="">اختر المورد</option>
                  {(suppliers.data ?? []).filter((s) => s.active).map((s) => (
                    <option key={s.id} value={s.id}>{s.company || s.name}</option>
                  ))}
                </select>
              </Field>
              <Field label="ربط بطلب شراء" hint="اختياري — يملأ الفرع والبنود من الطلب">
                <select className="inp" value={fromPR} disabled={!!form.id} onChange={(e) => { const value = e.target.value; setFromPR(value); const pr = approvedPRs.data?.find(p => p.id === Number(value)); if (pr) setForm({ ...form, branch: pr.branch_id, items: (pr.items ?? []).map(it => ({ product_variant_id: it.product_variant_id, qty: it.requested_qty, cost: num(it.variant?.cost_price), tax: 0, notes: '' })) }); }}>
                  <option value="">إنشاء مباشر</option>{form.id && fromPR && <option value={fromPR}>طلب #{fromPR}</option>}
                  {(approvedPRs.data ?? []).map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.request_number} — {p.branch?.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="الفرع" req={!fromPR}>
                <select className="inp" disabled={!!fromPR} value={form.branch} onChange={e => setForm({ ...form, branch: e.target.value })}>
                  <option value="">{fromPR ? 'من طلب الشراء' : 'اختر الفرع'}</option>
                  {(branches.data ?? []).map((b) => (
                    <option key={b.id} value={b.id}>{b.name}</option>
                  ))}
                </select>
              </Field>
              <Field label="تاريخ التوريد المتوقع">
                <input type="date" className="inp" value={form.eta} onChange={e => setForm({ ...form, eta: e.target.value })} />
              </Field>
            </div>
            <RemoteFeedback state={variants} />
            <ItemsEditor
              items={form.items} onItemsChange={items => setForm({ ...form, items })}
              cols={[
                { h: 'المتغير', fr: '2.4fr', el: (i) => <VSel variants={variants.data ?? []} value={form.items[i].product_variant_id} onChange={v => setItem(i, 'product_variant_id', v)} /> },
                { h: 'الكمية', fr: '.8fr', el: (i) => <NInp value={form.items[i].qty} onChange={v => setItem(i, 'qty', v)} /> },
                { h: 'تكلفة الوحدة', fr: '1fr', el: (i) => <NInp value={form.items[i].cost} onChange={v => setItem(i, 'cost', v)} step="0.01" min="0" /> },
                { h: 'الضريبة %', fr: '.8fr', el: (i) => <NInp value={form.items[i].tax} onChange={v => setItem(i, 'tax', v)} step="0.01" min="0" /> },
              ]}
            />
            <div className="flex items-end gap-3">
              <Field label="خصم إجمالي" className="w-48">
                <input type="number" className="inp num" value={form.discount} onChange={e => setForm({ ...form, discount: e.target.value })} />
              </Field>
              <div className="flex-1 v-sub px-4 py-2.5 flex items-center justify-between">
                <span className="tx2 text-[12px]">الإجمالي التقديري</span>
                <span className="font-display font-bold">{fmtMoney(Math.max(0, form.items.reduce((sum: number, it: any) => sum + Number(it.qty) * Number(it.cost) * (1 + Number(it.tax) / 100), 0) - Number(form.discount || 0)))}</span>
              </div>
            </div>
          </div>
        </Modal>
      )}
    </>
  );
}

export function InvModule({ onPayment }: { onPayment?: (invoice: any) => void }) {
  const canPost = useCan('supplier_invoices.post');
  const canCancel = useCan('supplier_invoices.cancel');
  const toast = useToast();
  const { busy, run } = useAction();
  const [form, setForm] = useState<any>(null);
  const [view, setView] = useState<any>(null);
  const [page, setPage] = useState(1);
  const [postConfirm, setPostConfirm] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const suppliers = useSupplierLookup();
  const orders = useRemote(signal => allPages(page => purchaseOrdersApi.list({ page, supplier_id: form?.sup ? Number(form.sup) : undefined }, signal)).then(r => r.map(mapPO)), [form?.sup]);
  const list = useRemote(signal => supplierInvoicesApi.list({ page }, signal), [page], { mock: () => pageOf(mockInvoices) });
  const rows = (list.data?.data ?? []).map(mapInvoice);
  const supName = (id: number) => suppliers.data?.find(x => x.id === id)?.company || suppliers.data?.find(x => x.id === id)?.name || `#${id}`;
  const openForm = (invoice?: any) => { setErrors({}); setForm({ demo: list.usingMock || invoice?.demo, id: invoice?.id, sys: invoice?.sys, sup: invoice?.sup ?? '', supNo: invoice?.supNo ?? '', poId: invoice?.poId ?? '', issue: invoice?.issue || new Date().toISOString().slice(0, 10), due: invoice?.due ?? '', subtotal: invoice?.subtotal ?? 0, tax: invoice?.tax ?? 0, attachment: undefined }); };
  const action = async (fn: () => Promise<unknown>, success: string) => { if (list.usingMock || view?.demo) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red'); const r = await run(fn, { toast, success }); if (r) { setView(null); setPostConfirm(false); list.reload(); return true; } return false; };
  const save = async () => {
    if (form.demo || list.usingMock || suppliers.usingMock) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red');
    const body = { supplier_invoice_no: form.supNo ?? '', purchase_order_id: form.poId ? Number(form.poId) : null, issue_date: form.issue, due_date: form.due || null, subtotal: Number(form.subtotal), tax_amount: Number(form.tax || 0), ...(form.attachment ? { attachment: form.attachment } : {}) };
    const r = await run(() => form.id ? supplierInvoicesApi.update(form.id, body) : supplierInvoicesApi.create({ ...body, supplier_id: Number(form.sup) }), { toast, success: 'تم حفظ الفاتورة كمسودة', onFieldErrors: setErrors });
    if (r) { setForm(null); list.reload(); }
  };

  return (
    <>
      <Card stg={3}>
        <CardHead
          title="فواتير الموردين"
          right={
            <button className="btn btn-dark btn-sm" onClick={() => openForm()}>
              <Icon name="plus" size={13} />
              فاتورة
            </button>
          }
        />
        <RemoteFeedback state={list} />
        <DataTable
          rows={rows}
          onRow={setView}
          cols={[
            { h: 'رقم النظام', r: (i: SupplierInvoice) => <span className="num font-semibold tx1">{i.sys}</span> },
            { h: 'رقم فاتورة المورد', r: (i: SupplierInvoice) => <Num className="tx2">{i.supNo}</Num> },
            { h: 'المورد', r: (i: SupplierInvoice) => <span className="font-medium">{supName(i.sup)}</span> },
            {
              h: 'الإصدار / الاستحقاق',
              r: (i: SupplierInvoice) => (
                <div className="leading-tight">
                  <Num className="text-[12px]">{i.issue}</Num>
                  <div className="text-[11px] tx3 num">{i.due || '—'}</div>
                </div>
              ),
            },
            { h: 'الإجمالي', r: (i: SupplierInvoice) => fmtMoney(i.subtotal + i.tax) },
            {
              h: 'المتبقي',
              r: (i: SupplierInvoice) => (
                <span
                  className={
                    'font-semibold ' +
                    ((i as any).remaining > 0 && i.status !== 'cancelled' ? 'tx1' : 'tx3')
                  }
                >
                  {fmtMoney((i as any).remaining)}
                </span>
              ),
            },
            { h: 'الحالة', r: (i: SupplierInvoice) => <StatusBadge map={INV_STATUS} value={i.status} /> },
            { h: '', r: (i: SupplierInvoice) => (i.file ? <Icon name="file" size={15} className="tx3" /> : null) },
          ]}
        />
      </Card>
      <Pager total={list.data?.meta.total ?? 0} per={20} page={page} onPage={setPage} />
      <ConfirmModal open={postConfirm} onClose={() => setPostConfirm(false)} title="ترحيل الفاتورة" text="سيتم إثبات المديونية في رصيد المورد" cta="ترحيل" onConfirm={() => action(() => supplierInvoicesApi.post(view.id), 'تم ترحيل الفاتورة')} />
      {view && (
        <Modal
          open
          onClose={() => setView(null)}
          width={600}
          icon="receipt"
          title={
            <span className="flex items-center gap-2">
              <span className="num">{view.sys}</span>
              <StatusBadge map={INV_STATUS} value={view.status} />
            </span>
          }
          sub={supName(view.sup)}
          footer={
            <>
              {canCancel && ['draft', 'posted'].includes(view.status) && (
                <button
                  className="btn btn-danger me-auto"
                  disabled={busy} onClick={() => action(() => supplierInvoicesApi.cancel(view.id), 'تم إلغاء الفاتورة')}
                >
                  إلغاء
                </button>
              )}
              {view.status === 'draft' && (
                <>
                  <button
                    className="btn btn-soft"
                    onClick={() => {
                      openForm(view);
                      setView(null);
                    }}
                  >
                    تعديل
                  </button>
                  <button
                    className="btn btn-dark"
                    disabled={busy || !canPost} onClick={() => setPostConfirm(true)}
                  >
                    ترحيل
                  </button>
                </>
              )}
              {['posted', 'partial'].includes(view.status) && (
                <button
                  className="btn btn-accent"
                  onClick={() => { onPayment?.(view); setView(null); }}
                >
                  <Icon name="cash" size={13} />
                  تسجيل دفعة
                </button>
              )}
            </>
          }
        >
          <div className="space-y-4 pb-2">
            <div className="v-orange rounded-[18px] p-4 grid grid-cols-3 gap-3">
              {[
                ['الإجمالي', view.subtotal + view.tax],
                ['المدفوع', view.paid],
                ['المتبقي', view.remaining],
              ].map(([l, v]) => (
                <div key={l as string}>
                  <div className="text-[11.5px] text-white/85">{l}</div>
                  <div className="font-display font-bold text-[18px] num ltr">{fmtNum(v as number)}</div>
                </div>
              ))}
            </div>
            <div className="mt-1">
              <HatchBar pct={(view.paid / (view.subtotal + view.tax)) * 100} />
            </div>
            <KV
              items={[
                ['رقم فاتورة المورد', <Num>{view.supNo}</Num>],
                ['أمر الشراء', view.po ? <Num>{view.po}</Num> : null],
                ['تاريخ الإصدار', <Num>{view.issue}</Num>],
                ['تاريخ الاستحقاق', view.due ? <Num>{view.due}</Num> : null],
                ['المجموع الفرعي', fmtMoney(view.subtotal)],
                ['الضريبة', fmtMoney(view.tax)],
              ]}
            />
            {view.file && (
              <div className="v-sub p-3 flex items-center justify-between">
                <span className="flex items-center gap-2.5 text-[12.5px]">
                  <span className="w-9 h-9 rounded-lg bg-[var(--bg-card)] flex items-center justify-center accent">
                    <Icon name="file" size={16} />
                  </span>
                  <span>
                    <span className="num font-medium tx1">{view.supNo}.pdf</span>
                    <div className="text-[11px] tx3 num">مرفق الفاتورة</div>
                  </span>
                </span>
                <button className="btn btn-soft btn-xs" disabled={busy} onClick={() => run(() => supplierInvoicesApi.openAttachment(view.id), { toast })}>
                  <Icon name="download" size={12} />
                  تنزيل
                </button>
              </div>
            )}
          </div>
        </Modal>
      )}
      {form && (
        <Modal
          open
          onClose={() => setForm(null)}
          title={form.id ? 'تعديل فاتورة ' + form.sys : 'فاتورة مورد جديدة'}
          icon="receipt"
          width={600}
          footer={
            <>
              <button className="btn btn-soft" onClick={() => setForm(null)}>
                إلغاء
              </button>
              <button
                className="btn btn-dark"
                disabled={busy} onClick={save}
              >
                حفظ
              </button>
            </>
          }
        >
          <div className="space-y-4">
            {Object.keys(errors).length > 0 && <Note tone="red">{Object.values(errors).join(' · ')}</Note>}
            <div className="grid grid-cols-2 gap-3">
              <RemoteFeedback state={suppliers} /><RemoteFeedback state={orders} />
              <Field label="المورد" err={errors.supplier_id} req hint={form.id ? 'لا يمكن تغيير المورد بعد الإنشاء' : null}>
                <select className="inp" disabled={!!form.id} value={form.sup} onChange={e => setForm({ ...form, sup: e.target.value, poId: '' })}>
                  <option value="">اختر المورد</option>
                  {(suppliers.data ?? []).map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.company || s.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="رقم فاتورة المورد" err={errors.supplier_invoice_no} req>
                <input className="inp num" dir="ltr" value={form.supNo} onChange={e => setForm({ ...form, supNo: e.target.value })} />
              </Field>
              <Field label="أمر شراء مرتبط">
                <select className="inp" value={form.poId || ''} onChange={e => setForm({ ...form, poId: e.target.value })}>
                  <option value="">بدون</option>
                  {(orders.data ?? []).map((p) => (
                    <option key={p.id} value={p.id}>{p.no}</option>
                  ))}
                </select>
              </Field>
              <div></div>
              <Field label="تاريخ الإصدار" err={errors.issue_date} req>
                <input type="date" className="inp" value={form.issue} onChange={e => setForm({ ...form, issue: e.target.value })} />
              </Field>
              <Field label="تاريخ الاستحقاق" err={errors.due_date}>
                <input type="date" className="inp" value={form.due} onChange={e => setForm({ ...form, due: e.target.value })} />
              </Field>
              <Field label="المجموع الفرعي" err={errors.subtotal} req>
                <input type="number" step="0.01" className="inp num" value={form.subtotal} onChange={e => setForm({ ...form, subtotal: e.target.value })} />
              </Field>
              <Field label="الضريبة" err={errors.tax_amount}>
                <input type="number" step="0.01" className="inp num" value={form.tax} onChange={e => setForm({ ...form, tax: e.target.value })} />
              </Field>
            </div>
            <Field label="المرفق" err={errors.attachment}>
              <Dropzone accept=".pdf,.jpg,.jpeg,.png,.webp" max="10MB" onFile={attachment => setForm({ ...form, attachment })} />
            </Field>
            <Note icon="info">الفاتورة تعتمد على مبالغ إجمالية فقط — بدون جدول أصناف.</Note>
          </div>
        </Modal>
      )}
    </>
  );
}

export function PayModule({ initialInvoice }: { initialInvoice?: any }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [form, setForm] = useState<any>(null);
  const [view, setView] = useState<any>(null);
  const [voidM, setVoidM] = useState(false);
  const [method, setMethod] = useState<'bank' | 'cash' | 'cheque'>('bank');
  const [page, setPage] = useState(1);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [idemKey, setIdemKey] = useState(newIdempotencyKey);
  const [voidReason, setVoidReason] = useState('');
  const [allocateOpen, setAllocateOpen] = useState(false);
  const [allocateId, setAllocateId] = useState('');
  const suppliers = useSupplierLookup();
  const invoices = useRemote(signal => allPages(page => supplierInvoicesApi.list({ page, supplier_id: Number(form?.sup || view?.sup) || undefined }, signal)).then(r => r.filter(i => ['posted', 'partially_paid'].includes(i.status)).map(mapInvoice)), [form?.sup, view?.sup]);
  const list = useRemote(signal => supplierPaymentsApi.list({ page }, signal), [page], { mock: () => pageOf(mockPayments) });
  const rows = (list.data?.data ?? []).map(mapPayment);
  const supName = (id: number) => suppliers.data?.find(x => x.id === id)?.company || suppliers.data?.find(x => x.id === id)?.name || `#${id}`;
  const openForm = (payment?: any) => { setErrors({}); setIdemKey(newIdempotencyKey()); setMethod(payment?.method ?? 'bank'); setForm({ ...payment, demo: list.usingMock || payment?.demo, sup: payment?.sup ?? '', invId: payment?.invId ?? '', amount: payment?.amount ?? '', date: payment?.date ?? new Date().toISOString().slice(0, 10), ref: payment?.ref ?? '', notes: payment?.notes ?? '' }); };
  useEffect(() => { if (initialInvoice) openForm({ demo: initialInvoice.demo, sup: initialInvoice.sup, invId: initialInvoice.id, amount: initialInvoice.remaining }); }, [initialInvoice?.id]);
  const save = async () => {
    if (form.demo || list.usingMock || suppliers.usingMock) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red');
    const common = { payment_method: paymentMethod(method), reference_no: form.ref || null, notes: form.notes || null };
    const r = await run(() => form.id ? supplierPaymentsApi.update(form.id, common) : supplierPaymentsApi.create({ ...common, supplier_id: Number(form.sup), supplier_invoice_id: form.invId ? Number(form.invId) : null, amount: Number(form.amount), payment_date: form.date }, idemKey), { toast, success: 'تم حفظ الدفعة', onFieldErrors: setErrors });
    if (r) { setForm(null); list.reload(); invoices.reload(); suppliers.reload(); }
  };
  const voidPayment = async () => {
    if (list.usingMock || view?.demo) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red');
    if (voidReason.trim().length < 3) return toast('سبب الإلغاء مطلوب (3 أحرف على الأقل)', 'red');
    const r = await run(() => supplierPaymentsApi.void(view.id, voidReason.trim()), { toast, success: 'تم إلغاء الدفعة وعكس أثرها' });
    if (r) { setVoidM(false); setView(null); setVoidReason(''); list.reload(); invoices.reload(); suppliers.reload(); }
  };
  const allocate = async () => {
    if (list.usingMock || view?.demo || invoices.usingMock) return toast('البيانات المعروضة تجريبية؛ الحفظ غير متاح', 'red');
    if (!allocateId) return toast('اختر فاتورة', 'red');
    const r = await run(() => supplierPaymentsApi.allocate(view.id, Number(allocateId)), { toast, success: 'تم تخصيص الدفعة للفاتورة' });
    if (r) { setAllocateOpen(false); setView(mapPayment(r)); list.reload(); invoices.reload(); }
  };

  return (
    <>
      <Card stg={3}>
        <CardHead
          title="مدفوعات الموردين"
          right={
            <button
              className="btn btn-dark btn-sm"
              onClick={() => openForm()}
            >
              <Icon name="plus" size={13} />
              دفعة
            </button>
          }
        />
        <RemoteFeedback state={list} />
        <DataTable
          rows={rows}
          onRow={setView}
          cols={[
            {
              h: 'رقم الدفعة',
              r: (p: SupplierPayment) => (
                <span className={'num font-semibold ' + (p.void ? 'tx3 line-through' : 'tx1')}>{p.no}</span>
              ),
            },
            {
              h: 'المورد',
              r: (p: SupplierPayment) => (
                <div className="flex items-center gap-2">
                  <Avatar name={supName(p.sup)} tone={p.sup} size={24} />
                  <span className="font-medium">{supName(p.sup)}</span>
                </div>
              ),
            },
            {
              h: 'الفاتورة',
              r: (p: SupplierPayment) => (p.inv ? <Num className="tx2">{p.inv}</Num> : <span className="tx3">دفعة تحت الحساب</span>),
            },
            {
              h: 'الوسيلة',
              r: (p: SupplierPayment) => (
                <span className="flex items-center gap-1.5 text-[12px]">
                  <Icon
                    name={({ cash: 'cash', bank: 'bank', cheque: 'file' } as const)[p.method as 'cash'] || 'cash'}
                    size={13}
                    className="tx3"
                  />
                  {SP_METHODS[p.method as keyof typeof SP_METHODS]}
                </span>
              ),
            },
            {
              h: 'المرجع',
              r: (p: SupplierPayment) => (p.ref ? <Num className="text-[11.5px] tx2">{p.ref}</Num> : <span className="tx3">—</span>),
            },
            {
              h: 'المبلغ',
              r: (p: SupplierPayment) => (
                <span className={'font-semibold ' + (p.void ? 'tx3 line-through' : '')}>{fmtMoney(p.amount)}</span>
              ),
            },
            { h: 'التاريخ', r: (p: SupplierPayment) => <Num className="tx2">{p.date}</Num> },
            {
              h: 'الحالة',
              r: (p: SupplierPayment) => (p.void ? <Badge tone="red">ملغاة</Badge> : <Badge tone="green">سارية</Badge>),
            },
          ]}
        />
      </Card>
      <Pager total={list.data?.meta.total ?? 0} per={20} page={page} onPage={setPage} />
      <Modal open={allocateOpen} onClose={() => setAllocateOpen(false)} title="تخصيص دفعة لفاتورة" footer={<button className="btn btn-dark" disabled={busy} onClick={allocate}>تخصيص</button>}><RemoteFeedback state={invoices} /><Field label="الفاتورة"><select className="inp" value={allocateId} onChange={e => setAllocateId(e.target.value)}><option value="">اختر فاتورة</option>{(invoices.data ?? []).map(i => <option key={i.id} value={i.id}>{i.sys} · {fmtMoney(i.remaining)}</option>)}</select></Field></Modal>
      {view && (
        <Modal
          open
          onClose={() => setView(null)}
          width={540}
          icon="cash"
          title={<span className="num">{view.no}</span>}
          sub={supName(view.sup)}
          footer={
            !view.void && (
              <>
                <button className="btn btn-danger me-auto" onClick={() => { setVoidReason(''); setVoidM(true); }}>
                  إلغاء الدفعة
                </button>
                {!view.inv && (
                  <button className="btn btn-soft" onClick={() => { setAllocateId(''); setAllocateOpen(true); }}>
                    <Icon name="link" size={13} />
                    ربط بفاتورة
                  </button>
                )}
                <button
                  className="btn btn-dark"
                  onClick={() => {
                    openForm(view);
                    setView(null);
                  }}
                >
                  تعديل
                </button>
              </>
            )
          }
        >
          <div className="space-y-4 pb-2">
            {view.void && (
              <div className="v-sub p-4 t-red !bg-[var(--red-bg)] rounded-[14px]">
                <div className="font-semibold text-[13px] mb-1.5 flex items-center gap-2">
                  <Icon name="ban" size={14} />
                  دفعة ملغاة
                </div>
                <KV
                  items={[
                    ['السبب', view.void.reason],
                    ['بواسطة', view.void.by],
                    ['الوقت', <Num>{view.void.at}</Num>],
                  ]}
                />
              </div>
            )}
            <div className="v-sub p-4 flex items-center justify-between">
              <span className="tx2">المبلغ</span>
              <span className="font-display font-bold text-[20px]">{fmtMoney(view.amount)}</span>
            </div>
            <KV
              items={[
                ['الفاتورة', view.inv ? <Num>{view.inv}</Num> : null],
                ['الوسيلة', SP_METHODS[view.method as keyof typeof SP_METHODS]],
                ['المرجع', view.ref ? <Num>{view.ref}</Num> : null],
                ['تاريخ الدفع', <Num>{view.date}</Num>],
                ['ملاحظات', view.notes],
              ]}
            />
          </div>
        </Modal>
      )}
      <Modal
        open={voidM}
        onClose={() => setVoidM(false)}
        title="إلغاء الدفعة"
        icon="ban"
        tone="red"
        width={440}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setVoidM(false)}>
              تراجع
            </button>
            <button
              className="btn btn-danger"
              disabled={busy} onClick={voidPayment}
            >
              إلغاء الدفعة
            </button>
          </>
        }
      >
        <div className="pb-2">
          <TextArea label="سبب الإلغاء" req value={voidReason} onChange={setVoidReason} />
        </div>
      </Modal>
      {form && (
        <Modal
          open
          onClose={() => setForm(null)}
          title={form.id ? 'تعديل دفعة ' + form.no : 'دفعة جديدة لمورد'}
          icon="cash"
          width={560}
          footer={
            <>
              <button className="btn btn-soft" onClick={() => setForm(null)}>
                إلغاء
              </button>
              <button
                className="btn btn-dark"
                disabled={busy} onClick={save}
              >
                حفظ
              </button>
            </>
          }
        >
          <div className="space-y-4">
            <RemoteFeedback state={suppliers} /><RemoteFeedback state={invoices} />
            {Object.keys(errors).length > 0 && <Note tone="red">{Object.values(errors).join(' · ')}</Note>}
            {form.id && (
              <Note icon="lock">
                لا يمكن تعديل المبلغ أو المورد أو التاريخ — يمكن تعديل الوسيلة والمرجع والملاحظات فقط.
              </Note>
            )}
            <div className="grid grid-cols-2 gap-3">
              <Field label="المورد" err={errors.supplier_id} req>
                <select className="inp" disabled={!!form.id} value={form.sup} onChange={e => setForm({ ...form, sup: e.target.value, invId: '' })}>
                  <option value="">اختر المورد</option>
                  {(suppliers.data ?? []).map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.company || s.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="فاتورة مرتبطة" err={errors.supplier_invoice_id} hint="اختيارية">
                <select className="inp" value={form.invId || ''} disabled={!!form.id} onChange={e => setForm({ ...form, invId: e.target.value })}>
                  <option value="">بدون</option>
                  {(invoices.data ?? []).map((i) => (
                    <option key={i.id} value={i.id}>{i.sys}</option>
                  ))}
                </select>
              </Field>
              <Field label="المبلغ" err={errors.amount} req hint={form.id ? null : 'أكبر من صفر'}>
                <input
                  type="number"
                  min="0.01"
                  step="0.01"
                  className="inp num"
                  disabled={!!form.id}
                  value={form.amount} onChange={e => setForm({ ...form, amount: e.target.value })}
                />
              </Field>
              <Field label="تاريخ الدفع" err={errors.payment_date} req>
                <input
                  type="date"
                  className="inp"
                  disabled={!!form.id}
                  value={form.date} onChange={e => setForm({ ...form, date: e.target.value })}
                />
              </Field>
            </div>
            <Field label="الوسيلة" err={errors.payment_method} req>
              <Seg
                value={method}
                onChange={(v: any) => setMethod(v)}
                options={Object.entries(SP_METHODS).map(([v, l]) => ({ value: v, label: l }))}
              />
            </Field>
            <Field
              label="المرجع" err={errors.reference_no}
              req={method !== 'cash'}
              hint={
                method === 'cash' ? 'اختياري للدفع النقدي' : method === 'cheque' ? 'رقم الشيك' : 'رقم التحويل البنكي'
              }
            >
              <input className="inp num" dir="ltr" value={form.ref} onChange={e => setForm({ ...form, ref: e.target.value })} />
            </Field>
            <TextArea label="ملاحظات" value={form.notes} onChange={notes => setForm({ ...form, notes })} />
          </div>
        </Modal>
      )}
    </>
  );
}

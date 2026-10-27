import React, { useState, useEffect } from 'react';
import { treasuriesApi, expensesApi, treasuryTransfersApi, shiftsApi, quotationsApi, paymentNoticesApi, asPage, type TreasuryType, type Treasury, type Expense, type PosShift, type Quotation, type PaymentNotice, type Paginated } from '@alfarid/api-client';
import { useRemote, useAction } from './api/useRemote';
import { useFormState } from './api/useFormState';
import { mapErrors, num, toDate } from './api/mappers';
import { api as opsApi } from '@alfarid/api-client';
import { USE_MOCK } from './api/source';
import {
  TREASURIES,
  TREASURY_TYPES,
  EXPENSES,
  TRANSFERS,
  SHIFTS,
  QUOTES,
  NOTICES,
  NOTICE_METHODS,
  trName,
  TRANSFER_STATUS,
  SHIFT_STATUS,
  QUOTE_STATUS,
  NOTICE_STATUS,
  TreasuryRow,
  ExpenseRow,
  TreasuryTransfer,
  ShiftRow,
  QuoteRow,
  PaymentNoticeRow,
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
  KPI,
  FSelect,
  FDate,
  TabsBar,
  DataTable,
  RowActions,
  Modal,
  ConfirmModal,
  Field,
  TextArea,
  ToggleRow,
  Note,
  KV,
  Num,
  fmtNum,
  fmtMoney,
  Empty,
  Seg,
  Pager,
} from './ui';

const today = () => toDate(new Date().toISOString());
const mockPage = <T,>(data: T[], page = 1): Paginated<T> => ({ data: data.slice((page - 1) * 20, page * 20), meta: { current_page: page, last_page: Math.max(1, Math.ceil(data.length / 20)), per_page: 20, total: data.length, from: data.length ? (page - 1) * 20 + 1 : null, to: data.length ? Math.min(page * 20, data.length) : null } });
export const mapTreasury = (t: Treasury): TreasuryRow & { branchId: number | null } => ({ id: t.id, code: t.code, name: t.name, type: treasuryToUI(t.type), branch: t.branch?.name ?? null, branchId: t.branch_id, balance: num(t.current_balance), active: t.is_active });
export const mapExpense = (e: Expense): ExpenseRow => ({ id: e.id, no: e.expense_number, branch: e.branch?.name ?? '', tr: e.treasury_id, cat: e.category, amount: num(e.amount), date: e.expense_date?.slice(0, 10) ?? '', by: e.creator?.name ?? '', shift: e.shift_id ? `#${e.shift_id}` : null, notes: e.notes ?? '' });
export const mapShift = (s: PosShift): ShiftRow => ({ id: s.id, no: s.shift_number, cashier: s.cashier?.name ?? '', branch: s.branch?.name ?? '', tr: s.treasury_id, open: s.opened_at, close: s.closed_at, status: s.status === 'closed_pending_approval' ? 'closed' : s.status, exp: s.expected_closing_cash === null ? null : num(s.expected_closing_cash), act: s.actual_closing_cash === null ? null : num(s.actual_closing_cash) });
const quoteStatus = (s: string) => s === 'priced_by_admin' ? 'priced' : s === 'converted_to_order' ? 'converted' : s;
export const mapQuote = (q: Quotation): QuoteRow => ({ id: q.id, no: q.quotation_number, company: q.customer?.company_name || q.customer?.name || '—', cust: q.customer?.name ?? '', status: quoteStatus(q.status), total: q.status === 'draft' || q.status === 'requested' ? null : num(q.grand_total), valid: q.valid_until, date: q.created_at.slice(0, 10), order: q.converted_order_id ? `#${q.converted_order_id}` : undefined });
export const mapNotice = (n: PaymentNotice): PaymentNoticeRow => ({ id: n.id, no: n.notice_number, company: n.customer?.company_name || n.customer?.name || '—', cust: n.customer?.name ?? '', amount: num(n.amount), method: n.payment_method === 'bank_transfer' ? 'bank' : n.payment_method, status: n.status === 'pending_review' ? 'pending' : n.status, date: n.transfer_date ?? n.created_at.slice(0, 10), bank: n.bank_name ?? '', ref: n.reference_number ?? '', file: n.has_receipt, order: n.order_number ?? null, tr: n.treasury_id ?? undefined, review: n.admin_notes ?? undefined });

async function allPages<T>(request: (page: number) => Promise<Paginated<T>>): Promise<T[]> {
  const first = await request(1);
  const rows = [...first.data];
  for (let page = 2; page <= first.meta.last_page; page++) rows.push(...(await request(page)).data);
  return rows;
}

function RemoteNote({ remote }: { remote: { loading: boolean; error: { message: string } | null; usingMock: boolean; reload: () => void } }) {
  if (remote.usingMock && !USE_MOCK) return <Note tone="red">تعذّر الاتصال بالخادم؛ البيانات المعروضة تجريبية.</Note>;
  if (remote.error) return <><Empty icon="alert" title={remote.error.message} /><button className="btn btn-soft" onClick={remote.reload}>إعادة المحاولة</button></>;
  if (remote.loading) return <Note>جارٍ التحميل…</Note>;
  return null;
}

/* ---------------- FINANCE PAGE ---------------- */
export function FinancePage({ sub = 'tr' }: { sub?: string }) {
  const [tab, setTab] = useState(sub);
  useEffect(() => setTab(sub), [sub]);

  return (
    <div className="space-y-5">
      <PageHead title="المالية" sub="الخزائن والمصروفات وتحويلات النقدية بين الخزائن." />
      <TabsBar
        value={tab}
        onChange={(v: any) => setTab(v)}
        tabs={[
          { value: 'tr', label: 'الخزائن', icon: 'vault' },
          { value: 'exp', label: 'المصروفات', icon: 'receipt' },
          {
            value: 'trf',
            label: 'تحويلات الخزائن',
            icon: 'swap',
          },
        ]}
      />
      {tab === 'tr' && <TreasuriesTab />}
      {tab === 'exp' && <ExpensesTab />}
      {tab === 'trf' && <TransfersTab />}
    </div>
  );
}

export function ExpenseForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({ branchId: '', treasuryId: '', category: '', amount: '', date: today(), notes: '' });
  const treasures = useRemote((s) => allPages((page) => treasuriesApi.list({ page, per_page: 100, is_active: true }, s)), []);
  const branches = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/branches', { params: { page, per_page: 100 }, signal: s }).then((r) => asPage<{ id: number; name: string }>(r))), []);
  const selectedTreasury = treasures.data?.find((t) => t.id === Number(f.v.treasuryId));
  const categories = ['نظافة', 'صيانة', 'نقل وتحميل', 'إيجار', 'ضيافة', 'أدوات مكتبية', 'أخرى'];
  const save = async () => {
    if (!f.v.branchId || !f.v.treasuryId || !f.v.category || !f.v.amount) return toast('أكمل الحقول المطلوبة', 'red');
    const r = await run(() => expensesApi.create({
      branch_id: Number(f.v.branchId),
      treasury_id: Number(f.v.treasuryId), category: f.v.category, amount: Number(f.v.amount),
      expense_date: f.v.date, notes: f.v.notes || null,
    }), { toast, success: 'تم تسجيل سند الصرف', onFieldErrors: (e) => f.setErrors(e as any) });
    if (r) { onSaved(); onClose(); }
  };
  return (
    <Modal open onClose={onClose} title="سند صرف مصروفات" icon="receipt" width={560}
      footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={busy} onClick={save}>تسجيل الصرف</button></>}>
      <div className="space-y-4">
        <RemoteNote remote={treasures} />
        <RemoteNote remote={branches} />
        <div className="grid grid-cols-2 gap-3">
          <Field label="الفرع" req err={f.errors.branch_id}>
            <select className="inp" value={f.v.branchId} onChange={f.on('branchId')}>
              <option value="">اختر الفرع</option>
              {(branches.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </Field>
          <Field label="الخزينة المنصرف منها" req err={f.errors.treasury_id}>
            <select className="inp" value={f.v.treasuryId} onChange={f.on('treasuryId' as any)}>
              <option value="">اختر الخزينة</option>
              {(treasures.data ?? []).map((t: { id: number; name: string }) => (<option key={t.id} value={String(t.id)}>{t.name}</option>))}
            </select>
          </Field>
          <Field label="تصنيف المصروف" req err={f.errors.category}>
            <select className="inp" value={f.v.category} onChange={f.on('category' as any)}>
              <option value="">اختر التصنيف</option>
              {categories.map((c) => (<option key={c} value={c}>{c}</option>))}
            </select>
          </Field>
          <Field label="المبلغ (EGP)" req err={f.errors.amount} hint={selectedTreasury && Number(f.v.amount) > num(selectedTreasury.current_balance) ? 'المبلغ أكبر من رصيد الخزينة' : undefined}>
            <input type="number" step="0.01" min="0.01" className="inp num" value={f.v.amount} onChange={f.on('amount' as any)} />
          </Field>
        </div>
        <Field label="التاريخ" req err={f.errors.expense_date}>
          <input type="date" className="inp" value={f.v.date} onChange={f.on('date' as any)} />
        </Field>
        <TextArea label="البيان / الملاحظات" req value={f.v.notes} onChange={(v) => f.set('notes', v)} />
      </div>
    </Modal>
  );
}

const treasuryToUI = (t: string): TreasuryRow['type'] => t === 'main_safe' ? 'main' : t === 'pos_drawer' ? 'drawer' : t === 'bank_account' ? 'bank' : 'main';
const treasuryToBackend = (t: string): TreasuryType => (t === 'main' ? 'main_safe' : t === 'drawer' ? 'pos_drawer' : t === 'bank' ? 'bank_account' : t) as TreasuryType;

export function TreasuryForm({ row, onClose, onSaved }: { row: any; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({ name: row?.name ?? '', code: row?.code ?? '', type: row?.type ?? 'main', branchId: String(row?.branchId ?? ''), active: row?.active ?? true });
  const branches = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/branches', { params: { page, per_page: 100 }, signal: s }).then((r) => asPage<{ id: number; name: string }>(r))), []);
  const save = async () => {
    if (!f.v.name.trim()) return toast('اسم الخزينة مطلوب', 'red');
    const body: any = {
      name: f.v.name, code: f.v.code, type: treasuryToBackend(f.v.type),
      branch_id: (f.v.branchId as string) ? Number(f.v.branchId) : null,
      is_active: !!f.v.active,
    };
    const r = await run(() => (row ? treasuriesApi.update(row.id, body) : treasuriesApi.create(body)),
      { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { branch_id: 'branchId' })) });
    if (r) { onSaved(); onClose(); }
  };
  return (
    <Modal open onClose={onClose} title={row ? 'تعديل خزينة' : 'خزينة جديدة'} icon="vault" width={600}
      footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={busy} onClick={save}>حفظ</button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="اسم الخزينة" req err={f.errors.name}><input className="inp" value={f.v.name} onChange={f.on('name')} /></Field>
          <Field label="الكود" req err={f.errors.code}><input className="inp" value={f.v.code} onChange={f.on('code')} /></Field>
          <Field label="النوع" req err={f.errors.type}>
            <select className="inp" disabled={!!row} value={f.v.type} onChange={f.on('type' as any)}>
              {Object.entries(TREASURY_TYPES).map(([k, l]) => (<option key={k} value={k}>{l as string}</option>))}
            </select>
          </Field>
        </div>
        <Field label="الفرع" hint="فارغ للخزائن المركزية" err={f.errors.branchId}>
          <select className="inp" value={f.v.branchId} onChange={f.on('branchId' as any)}>
            <option value="">خزينة مركزية</option>
            {(branches.data ?? []).map((b: { id: number; name: string }) => (<option key={b.id} value={String(b.id)}>{b.name}</option>))}
          </select>
        </Field>
        {!row && (<Field label="الرصيد الافتتاحي" hint="يُسجَّل الرصيد عبر تحويل أو إيراد"><input type="number" step="0.01" className="inp num" value={0} readOnly /></Field>)}
        <ToggleRow label="مفعّلة" on={f.v.active} onChange={(v) => f.set('active', v)} />
      </div>
    </Modal>
  );
}

export function TreasuriesTab() {
  const toast = useToast();
  const { run } = useAction();
  const [br, setBr] = useState('');
  const [ty, setTy] = useState('');
  const [act, setAct] = useState('');
  const [form, setForm] = useState<any>(null);
  const [del, setDel] = useState<TreasuryRow | null>(null);
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [br, ty, act]);
  const list = useRemote((s) => treasuriesApi.list({ page, per_page: 20, branch_id: br || undefined, type: ty ? treasuryToBackend(ty) : undefined, is_active: act ? act === 'true' : undefined }, s).then((p) => ({ ...p, data: p.data.map(mapTreasury) })), [page, br, ty, act], { mock: () => mockPage(TREASURIES) });
  const branches = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/branches', { params: { page, per_page: 100 }, signal: s }).then((r) => asPage<{ id: number; name: string }>(r))), []);
  const rows = list.data?.data ?? [];
  const sum = (k: string) => rows.filter((t) => t.type === k).reduce((a, t) => a + t.balance, 0);

  return (
    <>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 stg stg-2">
        <KPI
          hero
          label="الحسابات البنكية"
          icon="bank"
          value={<span className="ltr">{fmtNum(sum('bank'), 0)}</span>}
          small
          foot="EGP"
        />
        <KPI
          label="الخزائن الرئيسية"
          icon="vault"
          value={<span className="ltr">{fmtNum(sum('main'), 0)}</span>}
          small
          foot="EGP"
        />
        <KPI
          label="أدراج الكاشير"
          icon="cash"
          value={<span className="ltr">{fmtNum(sum('drawer'), 0)}</span>}
          small
          foot="EGP"
        />
      </div>
      <Card stg={3}>
        <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
          <div className="flex flex-wrap gap-2">
            <FSelect value={br} onChange={setBr} placeholder="الفرع" options={(branches.data ?? []).map((b) => ({ value: String(b.id), label: b.name }))} />
            <FSelect
              value={ty}
              onChange={setTy}
              placeholder="النوع"
              options={Object.entries(TREASURY_TYPES).map(([v, l]) => ({ value: v, label: l }))}
            />
            <FSelect
              value={act}
              onChange={setAct}
              placeholder="التفعيل"
              options={[
                { value: 'true', label: 'مفعّلة' },
                { value: 'false', label: 'معطّلة' },
              ]}
            />
          </div>
          <button className="btn btn-dark btn-sm" onClick={() => setForm({})}>
            <Icon name="plus" size={13} />
            خزينة جديدة
          </button>
        </div>
        <RemoteNote remote={list} />
        <DataTable
          rows={rows}
          onRow={list.usingMock ? undefined : setForm}
          cols={[
            {
              h: 'الخزينة',
              r: (t: TreasuryRow) => (
                <div className="flex items-center gap-2.5">
                  <span className="w-8 h-8 rounded-[10px] v-sub flex items-center justify-center tx2">
                    <Icon
                      name={
                        ({
                          bank: 'bank',
                          main: 'vault',
                          drawer: 'cash',
                          branch: 'vault',
                        } as const)[t.type as 'bank'] || 'vault'
                      }
                      size={14}
                    />
                  </span>
                  <span className="font-semibold tx1">{t.name}</span>
                </div>
              ),
            },
            {
              h: 'الكود',
              r: (t: TreasuryRow) => (
                <span className="num text-[11.5px] font-semibold v-sub px-2 py-1 rounded-md tx2">{t.code}</span>
              ),
            },
            {
              h: 'النوع',
              r: (t: TreasuryRow) => (
                <Badge
                  dot={false}
                  tone={
                    ({
                      bank: 'blue',
                      main: 'violet',
                      drawer: 'orange',
                      branch: 'gray',
                    } as const)[t.type as 'bank'] || 'gray'
                  }
                >
                  {TREASURY_TYPES[t.type as keyof typeof TREASURY_TYPES] || t.type}
                </Badge>
              ),
            },
            {
              h: 'الفرع',
              r: (t: TreasuryRow) => (t.branch ? <span className="tx2">{t.branch}</span> : <span className="tx3">مركزي</span>),
            },
            {
              h: 'الرصيد الحالي',
              r: (t: TreasuryRow) => (
                <span className={'font-semibold ' + (t.balance < 0 ? 'c-red' : '')}>{fmtMoney(t.balance)}</span>
              ),
            },
            { h: 'التفعيل', r: (t: TreasuryRow) => <ActiveDot on={t.active} /> },
            {
              h: '',
              r: (t: TreasuryRow) => (
                <RowActions>
                  <button className="icon-btn !w-7 !h-7" disabled={list.usingMock} onClick={() => setForm(t)}>
                    <Icon name="edit" size={13} />
                  </button>
                  <button className="icon-btn !w-7 !h-7" disabled={list.usingMock} onClick={() => setDel(t)}>
                    <Icon name="trash" size={13} />
                  </button>
                </RowActions>
              ),
            },
          ]}
        />
        <Pager total={list.data?.meta.total ?? 0} page={page} per={20} onPage={setPage} />
      </Card>
      {form && (
        <TreasuryForm row={form.id ? form : null} onClose={() => setForm(null)} onSaved={() => list.reload()} />
      )}
      <ConfirmModal
        open={!!del}
        onClose={() => setDel(null)}
        danger
        title={'حذف «' + (del?.name || '') + '»'}
        cta="حذف الخزينة"
        text="لا يمكن حذف خزينة بها رصيد أو حركات سابقة."
        onConfirm={async () => {
          if (!del || list.usingMock) return;
          if (del.balance !== 0) return toast('لا يمكن حذف خزينة رصيدها لا يساوي صفر', 'red');
          const r = await run(() => treasuriesApi.remove(del.id), { toast, success: 'تم الحذف' });
          if (r) { setDel(null); list.reload(); }
        }}
      />
    </>
  );
}

export function ExpensesTab() {
  const toast = useToast();
  const { run } = useAction();
  const [br, setBr] = useState('');
  const [tr, setTr] = useState('');
  const [cat, setCat] = useState('');
  const [form, setForm] = useState(false);

  const categories = ['نظافة', 'صيانة', 'نقل وتحميل', 'إيجار', 'ضيافة', 'أدوات مكتبية', 'أخرى'];
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [br, tr, cat]);
  const list = useRemote((s) => expensesApi.list({ page, per_page: 20, branch_id: br || undefined, treasury_id: tr || undefined, category: cat || undefined }, s).then((p) => ({ ...p, data: p.data.map(mapExpense) })), [page, br, tr, cat], { mock: () => mockPage(EXPENSES) });
  const treasures = useRemote((s) => allPages((page) => treasuriesApi.list({ page, per_page: 100 }, s)), []);
  const branches = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/branches', { params: { page, per_page: 100 }, signal: s }).then((r) => asPage<{ id: number; name: string }>(r))), []);
  const rows = list.data?.data ?? [];
  const largest = [...rows].sort((a, b) => b.amount - a.amount)[0];
  const nameOf = (id: number) => treasures.data?.find((t) => t.id === id)?.name ?? (USE_MOCK ? trName(id) : `#${id}`);
  const total = rows.reduce((a, e) => a + e.amount, 0);

  return (
    <>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 stg stg-2">
        <KPI
          hero
          label="إجمالي المصروفات المعروضة"
          icon="receipt"
          value={<span className="ltr">{fmtNum(total, 0)}</span>}
          small
          foot="EGP"
        />
        <KPI label="عدد سندات الصرف" icon="file" value={rows.length} foot="سند مسجّل" />
        <KPI label="أعلى بند صرف" icon="tag" value={largest?.cat ?? '—'} foot={largest ? fmtMoney(largest.amount) : '—'} />
      </div>
      <Card stg={3}>
        <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
          <div className="flex flex-wrap gap-2">
            <FSelect value={br} onChange={setBr} placeholder="الفرع" options={(branches.data ?? []).map((b: { id: number; name: string }) => ({ value: String(b.id), label: b.name }))} />
            <FSelect
              value={tr}
              onChange={setTr}
              placeholder="الخزينة"
              options={(treasures.data ?? []).map((t: { id: number; name: string }) => ({ value: String(t.id), label: t.name }))}
            />
            <FSelect value={cat} onChange={setCat} placeholder="التصنيف" options={categories} />
          </div>
          <button className="btn btn-dark btn-sm" onClick={() => setForm(true)}>
            <Icon name="plus" size={13} />
            سند صرف
          </button>
        </div>
        <RemoteNote remote={list} />
        <DataTable
          rows={rows}
          cols={[
            { h: 'رقم السند', r: (e: ExpenseRow) => <span className="num font-semibold tx1">{e.no}</span> },
            { h: 'الفرع', r: (e: ExpenseRow) => <span className="tx2">{e.branch}</span> },
            { h: 'الخزينة', r: (e: ExpenseRow) => <span className="text-[12px]">{nameOf(e.tr)}</span> },
            {
              h: 'التصنيف',
              r: (e: ExpenseRow) => (
                <Badge dot={false} tone="gray">
                  {e.cat}
                </Badge>
              ),
            },
            {
              h: 'المبلغ',
              r: (e: ExpenseRow) => <span className="font-semibold c-red">{fmtMoney(e.amount)}</span>,
            },
            { h: 'التاريخ', r: (e: ExpenseRow) => <Num className="tx2">{e.date}</Num> },
            {
              h: 'بواسطة',
              r: (e: ExpenseRow) => (
                <div className="flex items-center gap-1.5 text-[12px]">
                  <Avatar name={e.by} tone={e.id} size={22} />
                  <span>{e.by}</span>
                </div>
              ),
            },
            {
              h: 'الوردية',
              r: (e: ExpenseRow) => (e.shift ? <Num className="tx3 text-[11px]">{e.shift}</Num> : <span className="tx3">—</span>),
            },
            {
              h: 'ملاحظات',
              r: (e: ExpenseRow) => <span className="text-[11.5px] tx2 clamp1">{e.notes || '—'}</span>,
            },
          ]}
        />
        <Pager total={list.data?.meta.total ?? 0} page={page} per={20} onPage={setPage} />
      </Card>
      {form && (
        <ExpenseForm onClose={() => setForm(false)} onSaved={() => { list.reload(); treasures.reload(); }} />
      )}
    </>
  );
}

export function TransfersTab() {
  const toast = useToast();
  const { busy, run } = useAction();
  const [st, setSt] = useState('');
  const [tr, setTr] = useState('');
  const [view, setView] = useState<any>(null);
  const [form, setForm] = useState(false);
  const [src, setSrc] = useState('');
  const [dst, setDst] = useState('');
  const [amount, setAmount] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [st, tr]);
  const list = useRemote(async (s) => {
    // The server supports separate source/destination filters, not an OR filter.
    const result = tr
      ? mockPage((await allPages((page) => treasuryTransfersApi.list({ page, per_page: 100, status: st || undefined }, s))).filter((x) => String(x.from_treasury_id) === tr || String(x.to_treasury_id) === tr), page)
      : await treasuryTransfersApi.list({ page, per_page: 20, status: st || undefined }, s);
    return { ...result, data: result.data.map((x): TreasuryTransfer => ({
      id: x.id, from: x.from_treasury_id, to: x.to_treasury_id, amount: num(x.amount),
      status: x.status, date: (x.created_at ?? '').slice(0, 10), by: x.creator?.name ?? '', appr: x.approver?.name ?? null, notes: x.notes,
    })) };
  }, [page, st, tr], { mock: () => mockPage(TRANSFERS.filter((x) => (!st || x.status === st) && (!tr || String(x.from) === tr || String(x.to) === tr)), page) });
  const treasures = useRemote((s) => allPages((page) => treasuriesApi.list({ page, per_page: 100 }, s)), []);
  const nameOf = (id: number) => (treasures.data ?? []).find((t) => t.id === id)?.name ?? (USE_MOCK ? trName(id) : `#${id}`);
  const rows = (list.data?.data ?? []).filter(
    (x) => (!st || x.status === st) && (!tr || String(x.from) === tr || String(x.to) === tr)
  );
  const approve = async (id: number) => {
    if (list.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => treasuryTransfersApi.approve(id), { toast, success: 'تم اعتماد التحويل' });
    if (r) { setView(null); list.reload(); treasures.reload(); }
  };
  const reject = async (id: number) => {
    if (list.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => treasuryTransfersApi.reject(id), { toast, success: 'تم رفض التحويل' });
    if (r) { setView(null); list.reload(); treasures.reload(); }
  };
  const create = async () => {
    if (!src || !dst || src === dst) return toast('اختر مصدرًا ووجهة مختلفين', 'red');
    if (!amount || Number(amount) <= 0) return toast('أدخل مبلغًا صحيحًا', 'red');
    const r = await run(() => treasuryTransfersApi.create({
      from_treasury_id: Number(src), to_treasury_id: Number(dst), amount: Number(amount), notes: notes || null,
    }), { toast, success: 'أُرسل طلب التحويل', onFieldErrors: setErrors });
    if (r) { setForm(false); setSrc(''); setDst(''); setAmount(''); setNotes(''); list.reload(); treasures.reload(); }
  };

  return (
    <>
      <Card stg={3}>
        <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
          <div className="flex flex-wrap gap-2">
            <FSelect
              value={st}
              onChange={setSt}
              placeholder="الحالة"
              options={Object.entries(TRANSFER_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
            />
            <FSelect
              value={tr}
              onChange={setTr}
              placeholder="الخزينة (مصدر أو وجهة)"
              options={(treasures.data ?? []).map((t: { id: number; name: string }) => ({ value: String(t.id), label: t.name }))}
            />
          </div>
          <button className="btn btn-dark btn-sm" onClick={() => setForm(true)}>
            <Icon name="plus" size={13} />
            طلب تحويل
          </button>
        </div>
        <RemoteNote remote={list} />
        <DataTable
          rows={rows}
          onRow={list.usingMock ? undefined : setView}
          cols={[
            {
              h: 'المصدر',
              r: (x: TreasuryTransfer) => <span className="font-semibold tx1">{nameOf(x.from)}</span>,
            },
            {
              h: 'الوجهة',
              r: (x: TreasuryTransfer) => <span className="font-semibold tx1">{nameOf(x.to)}</span>,
            },
            {
              h: 'المبلغ',
              r: (x: TreasuryTransfer) => <span className="font-display font-bold text-[14px]">{fmtMoney(x.amount)}</span>,
            },
            { h: 'الحالة', r: (x: TreasuryTransfer) => <StatusBadge map={TRANSFER_STATUS} value={x.status} /> },
            { h: 'التاريخ', r: (x: TreasuryTransfer) => <Num className="tx2">{x.date}</Num> },
            { h: 'المنشئ', r: (x: TreasuryTransfer) => <span className="text-[12px] tx2">{x.by}</span> },
            {
              h: 'المعتمد',
              r: (x: TreasuryTransfer) => (x.appr ? <span className="text-[12px] tx2">{x.appr}</span> : <span className="tx3">—</span>),
            },
            {
              h: '',
              r: (x: TreasuryTransfer) =>
                x.status === 'pending' && (
                  <RowActions>
                    <button className="btn btn-danger btn-xs" disabled={busy} onClick={() => reject(x.id)}>
                      رفض
                    </button>
                    <button className="btn btn-success btn-xs" disabled={busy} onClick={() => approve(x.id)}>
                      اعتماد
                    </button>
                  </RowActions>
                ),
            },
          ]}
        />
        <Pager total={list.data?.meta.total ?? 0} page={page} per={20} onPage={setPage} />
      </Card>
      {view && (
        <Modal
          open
          onClose={() => setView(null)}
          title="طلب تحويل"
          icon="swap"
          width={520}
          footer={
            view.status === 'pending' && (
              <>
                <button
                  className="btn btn-danger"
                  disabled={busy}
                  onClick={() => reject(view.id)}
                >
                  رفض
                </button>
                <button
                  className="btn btn-dark"
                  disabled={busy}
                  onClick={() => approve(view.id)}
                >
                  اعتماد
                </button>
              </>
            )
          }
        >
          <div className="space-y-4 pb-2">
            <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-3">
              <div className="v-sub p-3 text-center">
                <div className="text-[11px] tx3 mb-1">من</div>
                <div className="font-semibold text-[12.5px]">{nameOf(view.from)}</div>
              </div>
              <span className="w-9 h-9 rounded-full btn-accent flex items-center justify-center">
                <Icon name="arrowL" size={15} />
              </span>
              <div className="v-sub p-3 text-center">
                <div className="text-[11px] tx3 mb-1">إلى</div>
                <div className="font-semibold text-[12.5px]">{nameOf(view.to)}</div>
              </div>
            </div>
            <div className="text-center">
              <div className="font-display text-[28px] font-bold">{fmtMoney(view.amount)}</div>
              <div className="mt-1">
                <StatusBadge map={TRANSFER_STATUS} value={view.status} />
              </div>
            </div>
            <KV
              items={[
                ['المنشئ', view.by],
                ['المعتمد', view.appr],
                ['التاريخ', <Num>{view.date}</Num>],
                ['ملاحظات', view.notes],
              ]}
            />
          </div>
        </Modal>
      )}
      <Modal
        open={form}
        onClose={() => setForm(false)}
        title="طلب تحويل بين خزائن"
        icon="swap"
        width={520}
        sub="يُنشأ بحالة «بانتظار الاعتماد» ولا يمكن تعديله بعد ذلك."
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setForm(false)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={!src || !dst || src === dst || busy}
              onClick={create}
            >
              إنشاء الطلب
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3">
            <Field label="الخزينة المصدر" req err={errors.from_treasury_id}>
              <select className="inp" value={src} onChange={(e) => setSrc(e.target.value)}>
                <option value="">اختر</option>
                {(treasures.data ?? []).map((x: { id: number; name: string }) => (
                  <option key={x.id} value={x.id}>
                    {x.name}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="الخزينة الوجهة" req err={errors.to_treasury_id || (src && src === dst ? 'يجب أن تختلف عن المصدر' : null)}>
              <select
                className={'inp ' + (src && src === dst ? 'err' : '')}
                value={dst}
                onChange={(e) => setDst(e.target.value)}
              >
                <option value="">اختر</option>
                {(treasures.data ?? []).map((x: { id: number; name: string }) => (
                  <option key={x.id} value={x.id} disabled={String(x.id) === src}>
                    {x.name}
                  </option>
                ))}
              </select>
            </Field>
          </div>
          <Field label="المبلغ" req err={errors.amount}>
            <input type="number" step="0.01" min="0.01" className="inp num" value={amount} onChange={(e) => setAmount(e.target.value)} />
          </Field>
          <TextArea label="ملاحظات" value={notes} onChange={setNotes} />
        </div>
      </Modal>
    </>
  );
}

/* ---------------- SHIFTS PAGE ---------------- */
export function DiffCell({ s }: { s: ShiftRow }) {
  if (s.exp == null) return <span className="tx3">—</span>;
  const d = (s.act ?? 0) - s.exp;
  if (d === 0) return <Badge tone="green" dot={false}>مطابق</Badge>;
  return (
    <span className={'font-semibold ' + (d < 0 ? 'c-red' : 'c-amber')}>
      {d < 0 ? 'عجز ' : 'زيادة '}
      <span className="num ltr">{fmtNum(Math.abs(d))}</span>
    </span>
  );
}

export function ShiftsPage() {
  const toast = useToast();
  const { busy, run } = useAction();
  const [br, setBr] = useState('');
  const [ca, setCa] = useState('');
  const [st, setSt] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [view, setView] = useState<ShiftRow | null>(null);

  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [br, ca, st, from, to]);
  const list = useRemote((s) => shiftsApi.list({ page, per_page: 20, branch_id: br || undefined, user_id: ca || undefined, status: st === 'closed' ? 'closed_pending_approval' : st || undefined, from_date: from || undefined, to_date: to || undefined }, s).then((p) => ({ ...p, data: p.data.map(mapShift) })), [page, br, ca, st, from, to], { mock: () => mockPage(SHIFTS) });
  const branches = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/branches', { params: { page, per_page: 100 }, signal: s }).then((r) => asPage<{ id: number; name: string }>(r))), []);
  const cashiers = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/users', { params: { page, per_page: 100 }, signal: s }).then((r) => asPage<{id: number; name: string}>(r))), []);
  const treasures = useRemote((s) => allPages((page) => treasuriesApi.list({ page, per_page: 100 }, s)), []);
  const nameOf = (id: number) => treasures.data?.find((t) => t.id === id)?.name ?? (USE_MOCK ? trName(id) : `#${id}`);
  const rows = list.data?.data ?? [];
  const approveShift = async (id: number) => {
    if (list.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => shiftsApi.approve(id), { toast, success: 'تم اعتماد الوردية' });
    if (r) { setView(null); list.reload(); }
  };

  return (
    <div className="space-y-5">
      <PageHead title="مراجعة الورديات" sub="مطابقة النقدية المتوقعة مع الفعلية واعتماد الورديات المغلقة." />
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 stg stg-2">
        <KPI
          hero
          label="بانتظار الاعتماد"
          icon="clock"
          value={rows.filter((s) => s.status === 'closed').length}
          foot="ورديات مغلقة"
        />
        <KPI
          label="ورديات بها فروق"
          icon="alert"
          value={rows.filter((s) => s.exp != null && s.act !== s.exp).length}
          foot="عجز أو زيادة"
        />
        <KPI label="إجمالي الورديات المسجلة" icon="calendar" value={list.data?.meta.total ?? 0} foot="وردية" />
      </div>
      <Card stg={3}>
        <div className="flex flex-wrap gap-2 mb-4">
          <FSelect value={br} onChange={setBr} placeholder="الفرع" options={(branches.data ?? []).map((b) => ({ value: String(b.id), label: b.name }))} />
          <FSelect value={ca} onChange={setCa} placeholder="الكاشير" options={(cashiers.data ?? []).map((c) => ({ value: String(c.id), label: c.name }))} />
          <FSelect
            value={st}
            onChange={setSt}
            placeholder="الحالة"
            options={Object.entries(SHIFT_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
          />
          <FDate label="من" value={from} onChange={setFrom} />
          <FDate label="إلى" value={to} onChange={setTo} />
        </div>
        <RemoteNote remote={list} />
        <DataTable
          rows={rows}
          onRow={setView}
          cols={[
            { h: 'رقم الوردية', r: (s: ShiftRow) => <span className="num font-semibold tx1">{s.no}</span> },
            {
              h: 'الكاشير / الفرع',
              r: (s: ShiftRow) => (
                <div className="flex items-center gap-2">
                  <Avatar name={s.cashier} tone={s.id} size={28} />
                  <div className="leading-tight">
                    <div className="font-medium tx1">{s.cashier}</div>
                    <div className="text-[11px] tx3">{s.branch}</div>
                  </div>
                </div>
              ),
            },
            { h: 'الخزينة', r: (s: ShiftRow) => <span className="text-[12px] tx2">{nameOf(s.tr)}</span> },
            {
              h: 'الفتح / الإغلاق',
              r: (s: ShiftRow) => (
                <div className="leading-tight num text-[11.5px]">
                  <div className="tx1">{s.open}</div>
                  <div className="tx3">{s.close || '—'}</div>
                </div>
              ),
            },
            { h: 'الحالة', r: (s: ShiftRow) => <StatusBadge map={SHIFT_STATUS} value={s.status} /> },
            {
              h: 'المتوقعة',
              r: (s: ShiftRow) => (s.exp != null ? fmtMoney(s.exp) : <span className="tx3">—</span>),
            },
            {
              h: 'الفعلية',
              r: (s: ShiftRow) => (s.act != null ? fmtMoney(s.act) : <span className="tx3">—</span>),
            },
            { h: 'الفرق', r: (s: ShiftRow) => <DiffCell s={s} /> },
          ]}
        />
        <Pager total={list.data?.meta.total ?? 0} page={page} per={20} onPage={setPage} />
      </Card>
      {view && (
        <ShiftModal
          s={view}
          onClose={() => setView(null)}
          onApprove={() => approveShift(view.id)}
        />
      )}
    </div>
  );
}

export function ShiftModal({ s: initial, onClose, onApprove }: { s: ShiftRow; onClose: () => void; onApprove: () => void }) {
  const detail = useRemote((signal) => shiftsApi.show(initial.id, signal), [initial.id]);
  const s = detail.data ? mapShift(detail.data) : initial;
  const opening = num(detail.data?.opening_cash);
  const cashSales = num(detail.data?.cash_sales_total);
  const cardSales = num(detail.data?.card_sales_total);
  const cashRet = num(detail.data?.cash_returns_total);
  const cashExp = num(detail.data?.cash_expenses_total);
  const exp = s.exp ?? opening + cashSales - cashRet - cashExp;
  const d = s.act != null ? s.act - exp : null;

  return (
    <Modal
      open
      onClose={onClose}
      width={680}
      icon="clock"
      title={
        <span className="flex items-center gap-2">
          <span className="num">{s.no}</span>
          <StatusBadge map={SHIFT_STATUS} value={s.status} />
        </span>
      }
      sub={
        <>
          {s.cashier} · {s.branch} · {detail.data?.treasury?.name ?? `#${s.tr}`}
        </>
      }
      footer={
        detail.data?.status === 'closed_pending_approval' ? (
          <>
            <button className="btn btn-soft" onClick={onClose}>
              إغلاق
            </button>
            <button className="btn btn-dark" onClick={onApprove}>
              <Icon name="check" size={14} />
              اعتماد الوردية
            </button>
          </>
        ) : null
      }
    >
      <div className="space-y-4 pb-2">
        <RemoteNote remote={detail} />
        {s.status === 'open' && (
          <Note tone="blue" icon="info">
            الوردية ما زالت مفتوحة — الأرقام حتى اللحظة، والاعتماد متاح بعد إغلاقها من نقطة البيع.
          </Note>
        )}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div className="v-sub p-4 space-y-2.5 text-[12.5px]">
            {[
              ['عهدة بداية الوردية', opening, ''],
              ['+ المبيعات النقدية', cashSales, 'c-green'],
              ['− المرتجعات النقدية', -cashRet, 'c-red'],
              ['− المصروفات النقدية', -cashExp, 'c-red'],
            ].map(([l, v, c]) => (
              <div key={l as string} className="flex items-center justify-between">
                <span className="tx2">{l}</span>
                <span className={'font-semibold ' + (c as string)}>{fmtMoney(v as number)}</span>
              </div>
            ))}
            <div className="flex items-center justify-between v-bt pt-2.5">
              <span className="font-semibold tx1">النقدية المتوقعة</span>
              <span className="font-display font-bold">{fmtMoney(exp)}</span>
            </div>
            <div className="flex items-center justify-between text-[11.5px] tx3">
              <span>مبيعات البطاقات (خارج الدرج)</span>
              <span>{fmtMoney(cardSales)}</span>
            </div>
          </div>
          <div
            className={
              'rounded-[18px] p-4 flex flex-col justify-between ' +
              (d == null ? 'v-sub' : d === 0 ? 't-green' : d < 0 ? 't-red' : 't-amber')
            }
          >
            <div>
              <div className="text-[12px] font-medium opacity-90">النقدية الفعلية</div>
              <div className="font-display text-[26px] font-bold num ltr mt-1">
                {s.act != null ? fmtNum(s.act) : '—'}
              </div>
            </div>
            <div className="mt-4">
              <div className="text-[12px] font-medium opacity-90">الفرق</div>
              <div className="font-display text-[20px] font-bold mt-0.5">
                {d == null ? (
                  '—'
                ) : d === 0 ? (
                  'مطابق'
                ) : (
                  <>
                    {d < 0 ? 'عجز' : 'زيادة'} <span className="num ltr">{fmtNum(Math.abs(d))}</span>
                  </>
                )}
              </div>
            </div>
          </div>
        </div>
        <div>
          <div className="lbl mb-2">مصروفات الوردية</div>
          <DataTable
            compact
            rows={(detail.data?.expenses ?? []).map(mapExpense)}
            cols={[
              { h: 'رقم السند', r: (e: ExpenseRow) => <span className="num">{e.no}</span> },
              { h: 'التصنيف', r: (e: ExpenseRow) => e.cat },
              { h: 'المبلغ', r: (e: ExpenseRow) => fmtMoney(e.amount) },
              { h: 'البيان', r: (e: ExpenseRow) => e.notes || '—' },
            ]}
            empty="لا توجد مصروفات مسجلة على هذه الوردية"
          />
        </div>
      </div>
    </Modal>
  );
}

/* ---------------- WHOLESALE PAGE ---------------- */
export function WholesalePage({ sub = 'quotes' }: { sub?: string }) {
  const [tab, setTab] = useState(sub);
  useEffect(() => setTab(sub), [sub]);

  return (
    <div className="space-y-5">
      <PageHead title="الجملة" sub="تسعير عروض أسعار التجار ومراجعة إشعارات السداد الواردة من بوابة الجملة." />
      <TabsBar
        value={tab}
        onChange={(v: any) => setTab(v)}
        tabs={[
          {
            value: 'quotes',
            label: 'عروض الأسعار',
            icon: 'quote',
          },
          {
            value: 'notices',
            label: 'إشعارات السداد',
            icon: 'bank',
          },
        ]}
      />
      {tab === 'quotes' ? <QuotesTab /> : <NoticesTab />}
    </div>
  );
}

export function QuotesTab() {
  const toast = useToast();
  const { busy, run } = useAction();
  const [st, setSt] = useState('');
  const [cu, setCu] = useState('');
  const [page, setPage] = useState(1);
  const [view, setView] = useState<QuoteRow | null>(null);
  const [price, setPrice] = useState(false);
  const [expire, setExpire] = useState(false);
  const [prices, setPrices] = useState<Record<number, string>>({});
  const [validUntil, setValidUntil] = useState('');
  const [adminNotes, setAdminNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => setPage(1), [st, cu]);
  const status = st === 'priced' ? 'priced_by_admin' : st === 'converted' || st === 'ordered' ? 'converted_to_order' : st;
  const list = useRemote((s) => quotationsApi.list({ page, per_page: 20, status: status || undefined, customer_id: cu || undefined }, s).then((p) => ({ ...p, data: p.data.map(mapQuote) })), [page, st, cu], { mock: () => mockPage(QUOTES) });
  const customers = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/customers', { params: { page, type: 'wholesale', per_page: 100 }, signal: s }).then((r) => asPage<{ id: number; name: string; company_name?: string }>(r))), []);
  const detail = useRemote((s) => quotationsApi.show(view!.id, s), [view?.id], { enabled: !!view });
  useEffect(() => {
    if (!detail.data) return;
    setPrices(Object.fromEntries((detail.data.items ?? []).map((item) => [item.id, item.offered_unit_price ?? item.requested_target_price ?? ''])));
    setValidUntil(detail.data.valid_until ?? '');
    setAdminNotes(detail.data.admin_notes ?? '');
    setErrors({});
  }, [detail.data]);
  const rows = list.data?.data ?? [];
  const lines = (detail.data?.items ?? []).map((item) => ({ id: item.id, v: { name: item.variant?.product_name ?? '—', sku: item.variant?.sku ?? '', price: num(item.offered_unit_price) }, qty: item.qty, price: item.offered_unit_price === null ? null : num(item.offered_unit_price), note: null }));
  const sub = lines.reduce((total, line) => total + num(prices[line.id]) * line.qty, 0);
  const savePrice = async () => {
    if (!detail.data || list.usingMock) return;
    if (!validUntil || validUntil < today()) return toast('تاريخ الصلاحية يجب ألا يسبق اليوم', 'red');
    if (lines.some((line) => prices[line.id] == null || prices[line.id] === '' || !Number.isFinite(Number(prices[line.id])) || Number(prices[line.id]) < 0)) return toast('أدخل سعراً صحيحاً لكل بند', 'red');
    const result = await run(() => quotationsApi.price(detail.data!.id, { items: lines.map((line) => ({ id: line.id, offered_unit_price: Number(prices[line.id]) })), valid_until: validUntil, admin_notes: adminNotes || null }), { toast, success: 'أُرسل التسعير للعميل', onFieldErrors: setErrors });
    if (result) { detail.setData(result); setView(mapQuote(result)); setPrice(false); list.reload(); }
  };
  const expireQuote = async () => {
    if (!view || list.usingMock || !detail.data) return;
    const result = await run(() => quotationsApi.expire(view.id), { toast, success: 'تم إنهاء صلاحية العرض' });
    if (result) { setExpire(false); setView(mapQuote(result)); detail.setData(result); list.reload(); }
  };

  return (
    <>
      <Card stg={3}>
        <div className="flex flex-wrap gap-2 mb-4">
          <FSelect
            value={st}
            onChange={setSt}
            placeholder="الحالة"
            options={Object.entries(QUOTE_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
          />
          <FSelect value={cu} onChange={setCu} placeholder="العميل" options={(customers.data ?? []).map((c) => ({ value: String(c.id), label: c.company_name || c.name }))} />
        </div>
        <RemoteNote remote={list} />
        <DataTable
          rows={rows}
          onRow={setView}
          cols={[
            { h: 'رقم العرض', r: (q: QuoteRow) => <span className="num font-semibold tx1">{q.no}</span> },
            {
              h: 'العميل / الشركة',
              r: (q: QuoteRow) => (
                <div className="flex items-center gap-2">
                  <Avatar name={q.company} tone={q.id} size={28} />
                  <div className="leading-tight">
                    <div className="font-medium tx1">{q.company}</div>
                    <div className="text-[11px] tx3">{q.cust}</div>
                  </div>
                </div>
              ),
            },
            { h: 'الحالة', r: (q: QuoteRow) => <StatusBadge map={QUOTE_STATUS} value={q.status} /> },
            {
              h: 'الإجمالي',
              r: (q: QuoteRow) =>
                q.total ? (
                  <span className="font-semibold">{fmtMoney(q.total)}</span>
                ) : (
                  <span className="tx3 text-[11.5px]">لم يُسعّر</span>
                ),
            },
            {
              h: 'الصلاحية',
              r: (q: QuoteRow) => (q.valid ? <Num className="tx2">{q.valid}</Num> : <span className="tx3">—</span>),
            },
            { h: 'التاريخ', r: (q: QuoteRow) => <Num className="tx2">{q.date}</Num> },
            {
              h: '',
              r: (q: QuoteRow) =>
                ['requested', 'priced'].includes(q.status) && (
                  <RowActions>
                    <button
                      className="btn btn-accent btn-xs"
                      disabled={list.usingMock}
                      onClick={() => {
                        setView(q);
                        setPrice(true);
                      }}
                    >
                      تسعير
                    </button>
                  </RowActions>
                ),
            },
          ]}
        />
        <Pager total={list.data?.meta.total ?? 0} page={page} per={20} onPage={setPage} />
      </Card>
      {view && !price && (
        <Modal
          open
          onClose={() => setView(null)}
          width={760}
          icon="quote"
          title={
            <span className="flex items-center gap-2">
              <span className="num">{view.no}</span>
              <StatusBadge map={QUOTE_STATUS} value={view.status} />
            </span>
          }
          sub={
            <>
              {view.company} · {view.cust}
            </>
          }
          footer={
            <>
              {['priced', 'requested'].includes((detail.data ? quoteStatus(detail.data.status) : view.status)) && (
                <button className="btn btn-ghost me-auto" disabled={busy || detail.loading || !detail.data || list.usingMock} onClick={() => setExpire(true)}>
                  <Icon name="clock" size={13} />
                  إنهاء الصلاحية
                </button>
              )}
              {['requested', 'priced'].includes(view.status) && (
                <button className="btn btn-dark" disabled={detail.loading || !detail.data || list.usingMock} onClick={() => setPrice(true)}>
                  {view.status === 'priced' ? 'إعادة التسعير' : 'تسعير العرض'}
                </button>
              )}
            </>
          }
        >
          <div className="space-y-4 pb-2">
            <RemoteNote remote={detail} />
            {view.status === 'priced' && (
              <Note tone="blue" icon="info">
                بانتظار رد التاجر — القبول أو الرفض يتم من بوابة الجملة.
              </Note>
            )}
            {view.order && (
              <div className="v-sub p-3 flex items-center justify-between text-[12.5px]">
                <span className="flex items-center gap-2">
                  <Icon name="link" size={14} className="accent" />
                  تحول إلى طلب
                </span>
                <span className="num font-semibold accent underline underline-offset-4">{view.order}</span>
              </div>
            )}
            <div className="v-sub p-3.5">
              <div className="lbl mb-1">ملاحظات العميل</div>
              <div className="text-[12.5px] tx1">
                {detail.data?.customer_notes || '—'}
              </div>
            </div>
            <DataTable
              compact
              rows={lines}
              cols={[
                {
                  h: 'البند',
                  r: (l: any) => (
                    <div className="leading-tight">
                      <div className="font-medium tx1">{l.v.name}</div>
                      <div className="text-[11px] tx3 num">{l.v.sku}</div>
                      {l.note && <div className="text-[11px] tx2 mt-0.5">«{l.note}»</div>}
                    </div>
                  ),
                },
                {
                  h: 'الكمية',
                  align: 'center',
                  r: (l: any) => <Num className="font-semibold">{l.qty}</Num>,
                },
                {
                  h: 'السعر المعروض',
                  r: (l: any) => (l.price !== null ? fmtMoney(l.price) : <span className="tx3">—</span>),
                },
                {
                  h: 'الإجمالي',
                  r: (l: any) => (l.price !== null ? <span className="font-semibold">{fmtMoney(l.price * l.qty)}</span> : <span className="tx3">—</span>),
                },
              ]}
            />
            {view.total !== null && (
              <div className="v-sub p-4 flex items-center justify-between">
                <span className="tx2">إجمالي العرض المُقدَّم</span>
                <span className="font-display text-[22px] font-bold num">{fmtMoney(view.total)}</span>
              </div>
            )}
          </div>
        </Modal>
      )}
      {price && view && (
        <Modal
          open
          onClose={() => setPrice(false)}
          title={'تسعير ' + view.no}
          icon="quote"
          width={800}
          footer={
            <>
              <button className="btn btn-soft" onClick={() => setPrice(false)}>
                إلغاء
              </button>
              <button
                className="btn btn-dark"
                disabled={busy || detail.loading || !detail.data} onClick={savePrice}
              >
                إرسال التسعير للعميل
              </button>
            </>
          }
        >
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <Field label="صالح حتى تاريخ" req err={errors.valid_until}>
                <input type="date" className="inp" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
              </Field>
              <Field label="شروط الدفع">
                <select className="inp" disabled title="شروط الدفع تُدار من بوابة العميل">
                  <option>سداد نقدي عند الاستلام</option>
                  <option>تحويل بنكي مسبق</option>
                  <option>أجل 30 يومًا</option>
                </select>
              </Field>
            </div>
            <div className="v-sub p-2">
              <div className="grid grid-cols-12 gap-2 px-2 py-1 text-[11px] tx3">
                <span className="col-span-5">البند المطلوب</span>
                <span className="col-span-2 text-center">الكمية</span>
                <span className="col-span-2 text-end">سعر الوحدة</span>
                <span className="col-span-3 text-end">الإجمالي</span>
              </div>
              {lines.map((l, i) => (
                <div key={i} className="grid grid-cols-12 gap-2 items-center px-2 py-1.5 v-bb">
                  <div className="col-span-5 leading-tight">
                    <div className="text-[12.5px] font-medium clamp1">{l.v.name}</div>
                    <div className="text-[10.5px] tx3 num">{l.v.sku}</div>
                  </div>
                  <Num className="col-span-2 text-center font-semibold">{l.qty}</Num>
                  <input
                    type="number"
                    step="0.01"
                    className="inp inp-sm col-span-2 num text-end !bg-[var(--bg-card)]"
                    min="0" value={prices[l.id] ?? ''} onChange={(e) => setPrices((prev) => ({ ...prev, [l.id]: e.target.value }))}
                  />
                  <span className="col-span-3 text-end font-semibold num text-[12px]">
                    {fmtMoney(num(prices[l.id]) * l.qty)}
                  </span>
                </div>
              ))}
            </div>
            <div className="v-sub p-3.5 flex items-center justify-between">
              <span className="font-semibold tx1">المجموع التقديري</span>
              <span className="font-display text-[20px] font-bold num">{fmtMoney(sub)}</span>
            </div>
            <TextArea label="ملاحظات العرض للعميل" placeholder="أي شروط توريد أو خصومات إضافية" value={adminNotes} onChange={setAdminNotes} />
            {Object.values(errors).map((message, i) => <Note key={i} tone="red">{message}</Note>)}
          </div>
        </Modal>
      )}
      <ConfirmModal open={expire} onClose={() => setExpire(false)} title="إنهاء صلاحية العرض" text="سيُغلق عرض السعر ولن يتمكن العميل من قبوله." cta="إنهاء الصلاحية" onConfirm={expireQuote} />
    </>
  );
}

export function NoticesTab() {
  const toast = useToast();
  const { busy, run } = useAction();
  const [st, setSt] = useState('');
  const [cu, setCu] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<PaymentNoticeRow | null>(null);
  const [mode, setMode] = useState<'approve' | 'reject' | null>(null);
  const [treasuryId, setTreasuryId] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => { setPage(1); setSelected(null); }, [st, cu]);
  useEffect(() => { setNotes(''); setTreasuryId(''); setErrors({}); }, [mode, selected?.id]);
  const list = useRemote((s) => paymentNoticesApi.list({ page, per_page: 20, status: st === 'pending' ? 'pending_review' : st || undefined, customer_id: cu || undefined }, s).then((p) => ({ ...p, data: p.data.map(mapNotice) })), [page, st, cu], { mock: () => mockPage(NOTICES) });
  const detail = useRemote((s) => paymentNoticesApi.show(selected!.id, s), [selected?.id], { enabled: !!selected });
  const treasures = useRemote((s) => allPages((page) => treasuriesApi.list({ page, per_page: 100, is_active: true }, s)), []);
  const customers = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/customers', { params: { page, type: 'wholesale', per_page: 100 }, signal: s }).then((r) => asPage<{ id: number; name: string; company_name?: string }>(r))), []);
  const nameOf = (id: number) => treasures.data?.find((t) => t.id === id)?.name ?? (USE_MOCK ? trName(id) : '#' + id);
  const rows = list.data?.data ?? [];
  const sel = detail.data && detail.data.id === selected?.id ? mapNotice(detail.data) : selected;
  const setSel = setSelected;
  useEffect(() => { if (!selected && rows.length) setSelected(rows[0]); }, [list.data, selected]);
  const review = async (approve: boolean) => {
    if (!sel || list.usingMock || !detail.data) return;
    if (!approve && !notes.trim()) return toast('سبب الرفض مطلوب', 'red');
    const result = await run(() => approve ? paymentNoticesApi.approve(sel.id, { treasury_id: treasuryId ? Number(treasuryId) : null, admin_notes: notes || null }) : paymentNoticesApi.reject(sel.id, notes), { toast, success: approve ? 'تم اعتماد إشعار الدفع' : 'تم رفض الإشعار', onFieldErrors: setErrors });
    if (result) { detail.setData(result); setSelected(mapNotice(result)); setMode(null); list.reload(); treasures.reload(); }
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <Card stg={3} className="lg:col-span-5 space-y-3">
        <div className="flex flex-wrap gap-2">
          <FSelect
            value={st}
            onChange={setSt}
            placeholder="كل الحالات"
            options={Object.entries(NOTICE_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
          />
          <FSelect value={cu} onChange={setCu} placeholder="العميل" options={(customers.data ?? []).map((c) => ({ value: String(c.id), label: c.company_name || c.name }))} />
        </div>
        <RemoteNote remote={list} />
        <div className="space-y-2">
          {rows.length === 0 && <Empty icon="bank" title="لا توجد إشعارات" />}
          {(rows as PaymentNoticeRow[]).map((n: PaymentNoticeRow) => (
            <button
              key={n.id}
              disabled={list.usingMock} onClick={() => setSel(n)}
              className="w-full text-start v-sub p-3.5 transition flex items-start gap-3 rounded-[14px]"
              style={sel?.id === n.id ? { outline: '2px solid var(--accent)', background: 'var(--bg-card)' } : {}}
            >
              <Avatar name={n.company} tone={n.id} size={34} />
              <div className="flex-1 min-w-0">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-semibold text-[12.5px] tx1 clamp1">{n.company}</span>
                  <StatusBadge map={NOTICE_STATUS} value={n.status} />
                </div>
                <div className="flex items-center justify-between mt-1.5">
                  <span className="text-[11px] tx3">
                    <span className="num">{n.no}</span> · {NOTICE_METHODS[n.method as keyof typeof NOTICE_METHODS]}
                  </span>
                  <span className="font-semibold text-[12.5px]">{fmtMoney(n.amount, 0)}</span>
                </div>
              </div>
            </button>
          ))}
        </div>
        <Pager total={list.data?.meta.total ?? 0} page={page} per={20} onPage={setPage} />
      </Card>
      {sel && (
        <Card stg={4} className="lg:col-span-7">
          <RemoteNote remote={detail} />
          <div className="flex items-start justify-between gap-3 mb-4">
            <div>
              <div className="flex items-center gap-2">
                <span className="font-display text-[18px] font-semibold num">{sel.no}</span>
                <StatusBadge map={NOTICE_STATUS} value={sel.status} />
              </div>
              <div className="text-[12px] tx2 mt-1">
                {sel.company} · {sel.cust}
              </div>
            </div>
            {sel.status === 'pending' && (
              <div className="flex gap-2">
                <button className="btn btn-danger btn-sm" onClick={() => setMode('reject')}>
                  رفض
                </button>
                <button className="btn btn-dark btn-sm" onClick={() => setMode('approve')}>
                  <Icon name="check" size={13} />
                  موافقة
                </button>
              </div>
            )}
          </div>
          <div className="v-orange rounded-[18px] p-4 flex items-center justify-between mb-4">
            <div>
              <div className="text-[12px] text-white/85">المبلغ</div>
              <div className="font-display text-[26px] font-bold num ltr">
                {fmtNum(sel.amount)} <span className="text-[13px] opacity-80">EGP</span>
              </div>
            </div>
            <span className="px-3 py-1 rounded-full bg-white/20 text-[12px] font-semibold">
              {NOTICE_METHODS[sel.method as keyof typeof NOTICE_METHODS]}
            </span>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-8">
            <KV
              items={[
                ['البنك', sel.bank],
                ['المرجع', <Num>{sel.ref}</Num>],
                ['تاريخ التحويل', <Num>{sel.date}</Num>],
              ]}
            />
            <KV
              items={[
                ['الطلب المرتبط', sel.order ? <Num className="accent">{sel.order}</Num> : null],
                ['الخزينة', sel.tr ? nameOf(sel.tr) : null],
                ['ملاحظات المراجعة', sel.review],
              ]}
            />
          </div>
          <div className="mt-4">
            {sel.file ? (
              <div className="v-sub p-3 flex items-center gap-3">
                <div
                  className="w-16 h-20 rounded-[10px] bg-[var(--bg-card)] flex items-center justify-center tx3 border"
                  style={{ borderColor: 'var(--border-subtle)' }}
                >
                  <Icon name="image" size={20} />
                </div>
                <div className="flex-1">
                  <div className="text-[12.5px] font-medium tx1">إيصال التحويل</div>
                  <div className="text-[11px] tx3 num">{sel.no}</div>
                </div>
                <button className="btn btn-soft btn-xs" disabled={busy} onClick={() => run(() => paymentNoticesApi.openReceipt(sel.id), { toast })}>
                  <Icon name="eye" size={12} />
                  معاينة
                </button>
                <button className="btn btn-soft btn-xs" disabled={busy || list.usingMock} onClick={() => run(() => paymentNoticesApi.downloadReceipt(sel.id), { toast })}>
                  <Icon name="download" size={12} />
                  تنزيل
                </button>
              </div>
            ) : (
              <div className="v-sub p-3 text-[12px] tx3 text-center">لم يُرفق إيصال</div>
            )}
          </div>
        </Card>
      )}
      <Modal
        open={mode === 'approve'}
        onClose={() => setMode(null)}
        title="الموافقة على إشعار السداد"
        icon="check"
        tone="green"
        width={480}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setMode(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={busy || detail.loading || !detail.data} onClick={() => review(true)}
            >
              موافقة
            </button>
          </>
        }
      >
        <div className="space-y-4 pb-2">
          <Field label="الخزينة" hint="اختياري — الخزينة التي استقبلت المبلغ" err={errors.treasury_id}>
            <select className="inp" value={treasuryId} onChange={(e) => setTreasuryId(e.target.value)}>
              <option value="">بدون</option>
              {(treasures.data ?? []).map((t) => (
                <option key={t.id} value={t.id}>{t.name}</option>
              ))}
            </select>
          </Field>
          <TextArea label="ملاحظات الإدارة" value={notes} onChange={setNotes} />
          {errors.admin_notes && <Note tone="red">{errors.admin_notes}</Note>}
        </div>
      </Modal>
      <Modal
        open={mode === 'reject'}
        onClose={() => setMode(null)}
        title="رفض إشعار السداد"
        icon="ban"
        tone="red"
        width={460}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setMode(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-danger"
              disabled={busy || detail.loading || !detail.data} onClick={() => review(false)}
            >
              رفض
            </button>
          </>
        }
      >
        <div className="pb-2">
          <TextArea label="ملاحظات الإدارة" req placeholder="وضّح سبب الرفض للتاجر" value={notes} onChange={setNotes} />
          {errors.admin_notes && <Note tone="red">{errors.admin_notes}</Note>}
        </div>
      </Modal>
    </div>
  );
}

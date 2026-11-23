import { AdminLoading } from '../../../components/admin/AdminLoading';
import React, { useState, useEffect } from 'react';
import { parseApiError } from '@alfarid/api-client';
import { treasuriesApi, expensesApi, treasuryTransfersApi, shiftsApi, quotationsApi, paymentNoticesApi, asPage, newIdempotencyKey, type TreasuryType, type Treasury, type Expense, type PosShift, type Quotation, type PaymentNotice, type Paginated } from '@alfarid/api-client';
import { useRemote, useAction, allPages, useDebounced } from './api/useRemote';
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
export const mapExpense = (e: Expense): ExpenseRow => ({ id: e.id, no: e.expense_number, branch: e.branch?.name ?? '', tr: e.treasury_id, cat: e.category, amount: num(e.amount), date: e.expense_date?.slice(0, 10) ?? '', by: e.creator?.name ?? '', shift: e.shift_id ? `#${e.shift_id}` : null, notes: e.notes ?? '', voidedAt: e.voided_at ?? null, voidReason: e.void_reason ?? null, hasAttachment: Boolean(e.attachment_path) });
export const mapShift = (s: PosShift): ShiftRow => ({ id: s.id, no: s.shift_number, cashier: s.cashier?.name ?? '', branch: s.branch?.name ?? '', tr: s.treasury_id, open: s.opened_at, close: s.closed_at, status: s.status === 'closed_pending_approval' ? 'closed' : s.status, exp: s.expected_closing_cash === null ? null : num(s.expected_closing_cash), act: s.actual_closing_cash === null ? null : num(s.actual_closing_cash) });
const quoteStatus = (s: string) => s === 'priced_by_admin' ? 'priced' : s === 'converted_to_order' ? 'converted' : s;
export const mapQuote = (q: Quotation): QuoteRow => ({ id: q.id, no: q.quotation_number, company: q.customer?.company_name || q.customer?.name || '—', cust: q.customer?.name ?? '', status: quoteStatus(q.status), total: q.status === 'draft' || q.status === 'requested' ? null : num(q.grand_total), valid: q.valid_until, date: q.created_at.slice(0, 10), order: q.converted_order_id ? `#${q.converted_order_id}` : undefined });
export const mapNotice = (n: PaymentNotice): PaymentNoticeRow => ({ id: n.id, no: n.notice_number, company: n.customer?.company_name || n.customer?.name || '—', cust: n.customer?.name ?? '', amount: num(n.amount), method: n.payment_method === 'bank_transfer' ? 'bank' : n.payment_method, status: n.status === 'pending_review' ? 'pending' : n.status, date: n.transfer_date ?? n.created_at.slice(0, 10), bank: n.bank_name ?? '', ref: n.reference_number ?? '', file: n.has_receipt, order: n.order_number ?? null, tr: n.treasury_id ?? undefined, review: n.admin_notes ?? undefined });


function RemoteNote({ remote }: { remote: { loading: boolean; error: { message: string } | null; usingMock: boolean; reload: () => void } }) {
  if (remote.usingMock && !USE_MOCK) return <Note tone="red">تعذّر الاتصال بالخادم؛ البيانات المعروضة تجريبية.</Note>;
  if (remote.error) return <><Empty icon="alert" title={remote.error.message} /><button className="btn btn-soft" onClick={remote.reload}>إعادة المحاولة</button></>;
  if (remote.loading) return <AdminLoading />;
  return null;
}

/* ---------------- FINANCE PAGE ---------------- */
export function FinancePage({ sub = 'tr', id, can = () => false }: { sub?: string; id?: string | number; can?: (permission: string) => boolean }) {
  const [tab, setTab] = useState(sub);
  useEffect(() => setTab(sub), [sub]);
  const tabs = [
    { value: 'tr', label: 'الخزائن', icon: 'vault', permission: 'treasuries.view' },
    { value: 'exp', label: 'المصروفات', icon: 'receipt', permission: 'expenses.view' },
    { value: 'trf', label: 'تحويلات الخزائن', icon: 'swap', permission: 'treasury_transfers.view' },
  ].filter(t => can(t.permission));
  const activeTab = tabs.some(t => t.value === tab) ? tab : tabs[0]?.value;
  const numericId = Number(id);
  const recordId = activeTab === sub && Number.isSafeInteger(numericId) && numericId > 0 ? numericId : undefined;

  if (tabs.length === 0) {
    return (
      <div className="space-y-5">
        <PageHead title="المالية" sub="الخزائن والمصروفات وتحويلات النقدية بين الخزائن." />
        <Empty icon="lock" title="ليس لديك صلاحية عرض المالية." />
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <PageHead title="المالية" sub="الخزائن والمصروفات وتحويلات النقدية بين الخزائن." />
      <TabsBar
        value={activeTab}
        onChange={(v: any) => setTab(v)}
        tabs={tabs}
      />
      {recordId && <button type="button" className="btn btn-soft" onClick={() => { window.location.hash = `/finance?tab=${activeTab}`; }}>عرض كل السجلات</button>}
      {activeTab === 'tr' && <TreasuriesTab key={recordId} recordId={recordId} can={can} />}
      {activeTab === 'exp' && <ExpensesTab key={recordId} recordId={recordId} can={can} />}
      {activeTab === 'trf' && <TransfersTab key={recordId} recordId={recordId} can={can} />}
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
    if (!f.v.branchId || !f.v.treasuryId || !f.v.category || !f.v.amount || !f.v.notes.trim()) return toast('أكمل جميع الحقول المطلوبة بما فيها البيان', 'red');
    const r = await run(() => expensesApi.create({
      branch_id: Number(f.v.branchId),
      treasury_id: Number(f.v.treasuryId), category: f.v.category, amount: f.v.amount,
      expense_date: f.v.date, notes: f.v.notes || null,
    }), { toast, success: 'تم تسجيل سند الصرف', onFieldErrors: (e) => f.setErrors(e as any) });
    if (r) { onSaved(); onClose(); }
  };
  return (
    <Modal open onClose={onClose} title="سند صرف مصروفات" icon="receipt" width={560}
      footer={
        <div className="flex w-full sm:w-auto items-center justify-end gap-2">
          <button type="button" className="btn btn-soft flex-1 sm:flex-initial" onClick={onClose}>إلغاء</button>
          <button type="button" className="btn btn-dark flex-1 sm:flex-initial" disabled={busy} onClick={save}>تسجيل الصرف</button>
        </div>
      }>
      <div className="space-y-4">
        <RemoteNote remote={treasures} />
        <RemoteNote remote={branches} />
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
    if (!f.v.code.trim()) return toast('كود الخزينة مطلوب', 'red');
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
      footer={
        <div className="flex items-center gap-2 w-full sm:w-auto sm:justify-end">
          <button className="btn btn-soft flex-1 sm:flex-initial justify-center" onClick={onClose}>إلغاء</button>
          <button className="btn btn-dark flex-1 sm:flex-initial justify-center" disabled={busy} onClick={save}>حفظ</button>
        </div>
      }>
      <div className="space-y-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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

export function TreasuriesTab({ recordId, can = () => false }: { recordId?: number; can?: (permission: string) => boolean } = {}) {
  const toast = useToast();
  const { run } = useAction();
  const [br, setBr] = useState('');
  const [ty, setTy] = useState('');
  const [act, setAct] = useState('');
  const [form, setForm] = useState<any>(null);
  const [del, setDel] = useState<TreasuryRow | null>(null);
  const [page, setPage] = useState(1);
  const setFilter = <T,>(setter: (v: T) => void) => (v: T) => { setter(v); setPage(1); };
  const list = useRemote((s) => recordId
    ? treasuriesApi.show(recordId, s).then((record) => mockPage([mapTreasury(record)]))
    : treasuriesApi.list({ page, per_page: 20, branch_id: br || undefined, type: ty ? treasuryToBackend(ty) : undefined, is_active: act ? act === 'true' : undefined }, s).then((p) => ({ ...p, data: p.data.map(mapTreasury) })), [recordId, page, br, ty, act], { mock: () => mockPage(TREASURIES.filter((row) => !recordId || row.id === recordId)) });
  const branches = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/branches', { params: { page, per_page: 100 }, signal: s }).then((r) => asPage<{ id: number; name: string }>(r))), []);
  const rows = list.data?.data ?? [];
  const sum = (k: string) => rows.filter((t) => t.type === k).reduce((a, t) => a + t.balance, 0);

  const hasFilters = Boolean(br || ty || act);
  const clearFilters = () => { setBr(''); setTy(''); setAct(''); setPage(1); };
  const typeTone = (type: string) =>
    (({ bank: 'blue', main: 'violet', drawer: 'orange', branch: 'gray' } as const)[type as 'bank'] || 'gray');
  const typeIcon = (type: string) =>
    (({ bank: 'bank', main: 'vault', drawer: 'cash', branch: 'vault' } as const)[type as 'bank'] || 'vault');

  return (
    <>
      {/* Mobile Bento KPI Strip */}
      <div className="grid grid-cols-2 gap-2.5 sm:hidden stg stg-2">
        <div className="col-span-2 v-orange rounded-[18px] p-3.5 flex items-center justify-between gap-3 min-w-0">
          <div className="min-w-0">
            <div className="text-[12px] font-medium text-white/90 truncate">الحسابات البنكية</div>
            <div className="text-[10.5px] text-white/75 mt-0.5 truncate">EGP · الصفحة الحالية</div>
          </div>
          <div className="flex items-center gap-2.5 shrink-0">
            <span className="font-display font-bold text-[22px] tracking-tight num tabular-nums ltr">
              {fmtNum(sum('bank'), 0)}
            </span>
            <span className="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center shrink-0">
              <Icon name="bank" size={15} />
            </span>
          </div>
        </div>
        <div className="v-sub rounded-[18px] p-3.5 flex flex-col justify-between min-h-[96px] min-w-0 overflow-hidden">
          <div className="flex items-center justify-between gap-1.5">
            <span className="text-[11.5px] font-medium tx2 truncate">الخزائن الرئيسية</span>
            <span className="v-well w-6 h-6 rounded-full flex items-center justify-center shrink-0">
              <Icon name="vault" size={12} />
            </span>
          </div>
          <div className="font-display font-bold text-[18px] tracking-tight my-1.5 tx1 num tabular-nums ltr truncate">
            {fmtNum(sum('main'), 0)}
          </div>
          <div className="text-[10.5px] tx3 truncate">EGP · الصفحة الحالية</div>
        </div>
        <div className="v-sub rounded-[18px] p-3.5 flex flex-col justify-between min-h-[96px] min-w-0 overflow-hidden">
          <div className="flex items-center justify-between gap-1.5">
            <span className="text-[11.5px] font-medium tx2 truncate">أدراج الكاشير</span>
            <span className="v-well w-6 h-6 rounded-full flex items-center justify-center shrink-0">
              <Icon name="cash" size={12} />
            </span>
          </div>
          <div className="font-display font-bold text-[18px] tracking-tight my-1.5 tx1 num tabular-nums ltr truncate">
            {fmtNum(sum('drawer'), 0)}
          </div>
          <div className="text-[10.5px] tx3 truncate">EGP · الصفحة الحالية</div>
        </div>
      </div>

      {/* Desktop KPI Strip */}
      <div className="hidden sm:grid sm:grid-cols-3 gap-4 stg stg-2">
        <KPI
          hero
          label="الحسابات البنكية (الصفحة الحالية)"
          icon="bank"
          value={<span className="ltr">{fmtNum(sum('bank'), 0)}</span>}
          small
          foot="EGP · في الصفحة الحالية"
        />
        <KPI
          label="الخزائن الرئيسية (الصفحة الحالية)"
          icon="vault"
          value={<span className="ltr">{fmtNum(sum('main'), 0)}</span>}
          small
          foot="EGP · في الصفحة الحالية"
        />
        <KPI
          label="أدراج الكاشير (الصفحة الحالية)"
          icon="cash"
          value={<span className="ltr">{fmtNum(sum('drawer'), 0)}</span>}
          small
          foot="EGP · في الصفحة الحالية"
        />
      </div>

      <Card stg={3} pad="p-4 sm:p-5">
        <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-between gap-2.5 mb-4">
          {can('treasuries.create') && (
            <button
              className="btn btn-dark btn-sm sm:order-2 w-full sm:w-auto justify-center active:scale-[0.97] [touch-action:manipulation]"
              onClick={() => setForm({})}
            >
              <Icon name="plus" size={14} />
              خزينة جديدة
            </button>
          )}
          <div className="grid grid-cols-2 sm:flex sm:flex-wrap gap-2 w-full sm:w-auto sm:order-1">
            <div className="col-span-2 sm:w-auto min-w-0 [&>select]:w-full [&>select]:min-h-[42px] sm:[&>select]:w-auto sm:[&>select]:min-h-0">
              <FSelect value={br} onChange={setFilter(setBr)} placeholder="كل الفروع" options={(branches.data ?? []).map((b) => ({ value: String(b.id), label: b.name }))} />
            </div>
            <div className="min-w-0 [&>select]:w-full [&>select]:min-h-[42px] sm:[&>select]:w-auto sm:[&>select]:min-h-0">
              <FSelect
                value={ty}
                onChange={setFilter(setTy)}
                placeholder="كل الأنواع"
                options={Object.entries(TREASURY_TYPES).map(([v, l]) => ({ value: v, label: l }))}
              />
            </div>
            <div className="min-w-0 [&>select]:w-full [&>select]:min-h-[42px] sm:[&>select]:w-auto sm:[&>select]:min-h-0">
              <FSelect
                value={act}
                onChange={setFilter(setAct)}
                placeholder="التفعيل"
                options={[
                  { value: 'true', label: 'مفعّلة' },
                  { value: 'false', label: 'معطّلة' },
                ]}
              />
            </div>
            {hasFilters && (
              <button
                type="button"
                onClick={clearFilters}
                className="col-span-2 sm:col-span-1 pill-inp inline-flex items-center justify-center gap-1.5 min-h-[40px] sm:min-h-0 active:scale-[0.97] [touch-action:manipulation]"
              >
                <Icon name="x" size={12} />
                مسح الفلاتر
              </button>
            )}
          </div>
        </div>
        <RemoteNote remote={list} />

        {/* Desktop Table */}
        <div className="hidden md:block">
          <DataTable
            rows={rows}
            onRow={list.usingMock || !can('treasuries.update') ? undefined : setForm}
            cols={[
              {
                h: 'الخزينة',
                r: (t: TreasuryRow) => (
                  <div className="flex items-center gap-2.5">
                    <span className="w-8 h-8 rounded-[10px] v-sub flex items-center justify-center tx2">
                      <Icon name={typeIcon(t.type)} size={14} />
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
                  <Badge dot={false} tone={typeTone(t.type)}>
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
                    {can('treasuries.update') && (
                      <button className="icon-btn !w-7 !h-7" disabled={list.usingMock} onClick={() => setForm(t)}>
                        <Icon name="edit" size={13} />
                      </button>
                    )}
                    {can('treasuries.delete') && (
                      <button className="icon-btn !w-7 !h-7" disabled={list.usingMock} onClick={() => setDel(t)}>
                        <Icon name="trash" size={13} />
                      </button>
                    )}
                  </RowActions>
                ),
              },
            ]}
          />
        </div>

        {/* Mobile Treasury Cards */}
        <div className="md:hidden">
          {!list.loading && !list.error && rows.length === 0 ? (
            <div className="py-6">
              <Empty
                icon="vault"
                title="لا توجد خزائن مطابقة"
                text={hasFilters ? 'جرّب تعديل الفلاتر أو مسحها لعرض جميع الخزائن.' : undefined}
                action={
                  hasFilters ? (
                    <button type="button" className="btn btn-soft btn-sm" onClick={clearFilters}>
                      مسح الفلاتر
                    </button>
                  ) : undefined
                }
              />
            </div>
          ) : (
            <div className="op-order-cards">
              {rows.map((t) => {
                const canEdit = !list.usingMock && can('treasuries.update');
                return (
                  <div
                    key={t.id}
                    role={canEdit ? 'button' : undefined}
                    tabIndex={canEdit ? 0 : undefined}
                    onClick={canEdit ? () => setForm(t) : undefined}
                    onKeyDown={
                      canEdit
                        ? (e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                              e.preventDefault();
                              setForm(t);
                            }
                          }
                        : undefined
                    }
                    className={'op-order-card ' + (canEdit ? '[touch-action:manipulation]' : '!cursor-default active:!transform-none')}
                  >
                    <div className="op-order-card-head">
                      <div className="flex items-center gap-2.5 min-w-0 flex-1">
                        <span className="w-10 h-10 rounded-[12px] v-card border border-[var(--border-subtle)] flex items-center justify-center tx2 shrink-0">
                          <Icon name={typeIcon(t.type)} size={16} />
                        </span>
                        <div className="min-w-0 flex-1">
                          <div className="font-semibold tx1 text-[14px] leading-snug truncate">{t.name}</div>
                          <div className="flex items-center gap-1.5 mt-0.5">
                            <span className="num text-[11px] font-semibold tx3 truncate">{t.code}</span>
                          </div>
                        </div>
                      </div>
                      <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium v-card border border-[var(--border-subtle)] shrink-0">
                        <ActiveDot on={t.active} />
                        <span className={t.active ? 'tx1' : 'tx3'}>{t.active ? 'مفعّلة' : 'معطّلة'}</span>
                      </span>
                    </div>

                    <div className="op-order-card-meta">
                      <div className="col-span-2 flex items-center justify-between gap-2 pb-2 mb-0.5 border-b border-[var(--border-subtle)]">
                        <span className="text-[11px] tx3">الرصيد الحالي</span>
                        <span className={'font-display font-bold text-[17px] num tabular-nums ' + (t.balance < 0 ? 'c-red' : 'tx1')}>
                          {fmtMoney(t.balance)}
                        </span>
                      </div>
                      <div className="min-w-0">
                        <div className="text-[10.5px] tx3 mb-1">النوع</div>
                        <Badge dot={false} tone={typeTone(t.type)}>
                          {TREASURY_TYPES[t.type as keyof typeof TREASURY_TYPES] || t.type}
                        </Badge>
                      </div>
                      <div className="min-w-0">
                        <div className="text-[10.5px] tx3 mb-1">الفرع</div>
                        {t.branch ? (
                          <div className="text-[12.5px] font-medium tx1 truncate">{t.branch}</div>
                        ) : (
                          <div className="text-[12.5px] tx3">مركزي</div>
                        )}
                      </div>
                    </div>

                    {(can('treasuries.update') || can('treasuries.delete')) && (
                      <div className="flex items-center gap-2 pt-0.5" onClick={(e) => e.stopPropagation()}>
                        {can('treasuries.update') && (
                          <button
                            type="button"
                            className="btn btn-soft btn-sm flex-1 justify-center min-h-[42px] active:scale-[0.97] [touch-action:manipulation]"
                            disabled={list.usingMock}
                            onClick={() => setForm(t)}
                          >
                            <Icon name="edit" size={14} />
                            تعديل الخزينة
                          </button>
                        )}
                        {can('treasuries.delete') && (
                          <button
                            type="button"
                            className="icon-btn !w-[42px] !h-[42px] shrink-0 active:scale-[0.95] [touch-action:manipulation]"
                            style={{ color: 'var(--badge-red-text)' }}
                            disabled={list.usingMock}
                            onClick={() => setDel(t)}
                            aria-label={'حذف ' + t.name}
                          >
                            <Icon name="trash" size={15} />
                          </button>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>

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

export function ExpensesTab({ recordId, can = () => false }: { recordId?: number; can?: (permission: string) => boolean } = {}) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [br, setBr] = useState('');
  const [tr, setTr] = useState('');
  const [cat, setCat] = useState('');
  const [showFilters, setShowFilters] = useState(false);
  const [form, setForm] = useState(false);
  const [viewing, setViewing] = useState<ExpenseRow | null>(null);
  const [voiding, setVoiding] = useState<ExpenseRow | null>(null);
  const [voidReason, setVoidReason] = useState('');

  const categories = ['نظافة', 'صيانة', 'نقل وتحميل', 'إيجار', 'ضيافة', 'أدوات مكتبية', 'أخرى'];
  const [page, setPage] = useState(1);
  const setFilter = <T,>(setter: (v: T) => void) => (v: T) => { setter(v); setPage(1); };
  const activeFilterCount = [br, tr, cat].filter(Boolean).length;
  const clearFilters = () => { setBr(''); setTr(''); setCat(''); setPage(1); };
  const list = useRemote((s) => recordId
    ? expensesApi.show(recordId, s).then((record) => mockPage([mapExpense(record)]))
    : expensesApi.list({ page, per_page: 20, branch_id: br || undefined, treasury_id: tr || undefined, category: cat || undefined }, s).then((p) => ({ ...p, data: p.data.map(mapExpense) })), [recordId, page, br, tr, cat], { mock: () => mockPage(EXPENSES.filter((row) => !recordId || row.id === recordId)) });
  const treasures = useRemote((s) => allPages((page) => treasuriesApi.list({ page, per_page: 100 }, s)), []);
  const branches = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/branches', { params: { page, per_page: 100 }, signal: s }).then((r) => asPage<{ id: number; name: string }>(r))), []);
  const rows = list.data?.data ?? [];
  const activeRows = rows.filter(e => !e.voidedAt);
  const largest = [...activeRows].sort((a, b) => b.amount - a.amount)[0];
  const nameOf = (id: number) => treasures.data?.find((t) => t.id === id)?.name ?? (USE_MOCK ? trName(id) : `#${id}`);
  const total = activeRows.reduce((a, e) => a + e.amount, 0);

  const openReceipt = (id: number) => {
    void expensesApi.openAttachment(id).catch((error) => toast(parseApiError(error).message, 'red'));
  };

  return (
    <>
      <div className="grid grid-cols-2 sm:grid-cols-3 gap-2.5 sm:gap-4 stg stg-2">
        <div className="col-span-2 sm:col-span-1">
          <KPI
            hero
            label="إجمالي المصروفات (الصفحة الحالية)"
            icon="receipt"
            value={<span className="ltr">{fmtNum(total, 0)}</span>}
            small
            foot="EGP · في الصفحة الحالية"
          />
        </div>
        <KPI label="عدد سندات الصرف" icon="file" value={rows.length} foot="سند في الصفحة الحالية" />
        <KPI label="أعلى بند صرف" icon="tag" value={largest?.cat ?? '—'} foot={largest ? fmtMoney(largest.amount) : '—'} />
      </div>
      <Card stg={3}>
        <div className="op-expenses-toolbar flex flex-col md:flex-row md:items-center md:justify-between gap-2.5 mb-4">
          <div className="flex items-center gap-2 md:order-2">
            {can('expenses.create') && (
              <button type="button" className="btn btn-dark flex-1 md:flex-initial justify-center shrink-0" onClick={() => setForm(true)}>
                <Icon name="plus" size={14} />
                سند صرف
              </button>
            )}
            <button
              type="button"
              onClick={() => setShowFilters((s) => !s)}
              className={`md:hidden pill-inp min-h-[44px] px-4 inline-flex items-center justify-center gap-1.5 shrink-0 ${(showFilters || activeFilterCount > 0) ? 'on' : ''}`}
              aria-expanded={showFilters || activeFilterCount > 0}
            >
              <Icon name="filter" size={14} />
              <span>تصفية</span>
              {activeFilterCount > 0 && <span className="num text-[11px] font-bold">({activeFilterCount})</span>}
            </button>
          </div>
          <div className={`op-expenses-filters md:order-1 md:flex md:flex-wrap md:items-center gap-2 ${(showFilters || activeFilterCount > 0) ? '' : 'hidden md:flex'}`}>
            <FSelect value={br} onChange={setFilter(setBr)} placeholder="الفرع" options={(branches.data ?? []).map((b: { id: number; name: string }) => ({ value: String(b.id), label: b.name }))} />
            <FSelect
              value={tr}
              onChange={setFilter(setTr)}
              placeholder="الخزينة"
              options={(treasures.data ?? []).map((t: { id: number; name: string }) => ({ value: String(t.id), label: t.name }))}
            />
            <FSelect value={cat} onChange={setFilter(setCat)} placeholder="التصنيف" options={categories} />
            {activeFilterCount > 0 && (
              <button type="button" className="btn btn-ghost btn-xs col-span-2 md:col-span-1 justify-center" onClick={clearFilters}>
                <Icon name="x" size={12} />
                مسح الفلاتر
              </button>
            )}
          </div>
        </div>
        <RemoteNote remote={list} />
        <div className="hidden md:block op-orders-table-wrap">
          <DataTable
            rows={rows}
            onRow={setViewing}
            cols={[
              { h: 'رقم السند', r: (e: ExpenseRow) => <span className="num font-semibold tx1">{e.no}</span> },
              { h: 'الحالة', r: (e: ExpenseRow) => <Badge tone={e.voidedAt ? 'red' : 'green'}>{e.voidedAt ? 'ملغى' : 'مسجّل'}</Badge> },
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
                r: (e: ExpenseRow) => <span className="text-[11.5px] tx2 clamp1">{e.voidReason || e.notes || '—'}</span>,
              },
              {
                h: 'الإيصال',
                r: (e: ExpenseRow) => e.hasAttachment ? (
                  <button
                    type="button"
                    className="btn btn-soft btn-sm inline-flex items-center gap-1.5"
                    onClick={(ev) => { ev.stopPropagation(); openReceipt(e.id); }}
                  >
                    <Icon name="file" size={13} />
                    فتح الإيصال
                  </button>
                ) : null,
              },
              {
                h: '',
                r: (e: ExpenseRow) => !e.voidedAt && !e.shift && can('expenses.delete') ? (
                  <button
                    type="button"
                    className="btn btn-soft btn-sm"
                    onClick={(ev) => { ev.stopPropagation(); setVoiding(e); setVoidReason(''); }}
                  >
                    إلغاء المصروف
                  </button>
                ) : null,
              },
            ]}
          />
        </div>
        <div className="md:hidden">
          {list.loading ? (
            <div className="py-8 flex justify-center"><AdminLoading compact /></div>
          ) : rows.length === 0 ? (
            <div className="py-10 text-center space-y-3">
              <div className="tx3 text-[13px]">لا توجد مصروفات مطابقة</div>
              {activeFilterCount > 0 && (
                <button type="button" className="btn btn-soft btn-sm" onClick={clearFilters}>
                  إعادة ضبط الفلاتر
                </button>
              )}
            </div>
          ) : (
            <div className="op-expense-cards">
              {(rows as ExpenseRow[]).map((e) => {
                const noteText = e.voidReason || e.notes;
                const canVoid = !e.voidedAt && !e.shift && can('expenses.delete');
                return (
                  <div
                    key={e.id}
                    role="button"
                    tabIndex={0}
                    onClick={() => setViewing(e)}
                    onKeyDown={(ev) => {
                      if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        setViewing(e);
                      }
                    }}
                    className={`op-expense-card ${e.voidedAt ? 'is-voided' : ''}`}
                  >
                    <div className="op-order-card-head">
                      <div className="flex items-center gap-2 min-w-0">
                        <span className="num font-bold tx1 text-[13.5px] truncate">{e.no}</span>
                        <span className="num text-[11px] tx3 shrink-0">{e.date}</span>
                      </div>
                      <div className="flex items-center gap-1.5 shrink-0">
                        <Badge dot={false} tone="gray">{e.cat}</Badge>
                        <Badge tone={e.voidedAt ? 'red' : 'green'}>{e.voidedAt ? 'ملغى' : 'مسجّل'}</Badge>
                      </div>
                    </div>

                    <div className="op-expense-amount-band">
                      <div className="flex items-center gap-2 min-w-0">
                        <Avatar name={e.by} tone={e.id} size={28} />
                        <div className="min-w-0 leading-tight">
                          <div className="text-[12.5px] font-medium tx1 truncate">{e.by || '—'}</div>
                          <div className="text-[11px] tx3 truncate mt-0.5">{e.branch || '—'}</div>
                        </div>
                      </div>
                      <div className={`font-display font-bold text-[17px] num whitespace-nowrap shrink-0 ${e.voidedAt ? 'tx3 line-through' : 'c-red'}`}>
                        {fmtMoney(e.amount)}
                      </div>
                    </div>

                    <div className="op-expense-meta">
                      <div className="min-w-0">
                        <div className="text-[10.5px] tx3 mb-0.5">الخزينة</div>
                        <div className="text-[12px] tx1 font-medium truncate">{nameOf(e.tr)}</div>
                      </div>
                      <div className="min-w-0">
                        <div className="text-[10.5px] tx3 mb-0.5">الوردية</div>
                        <div className="text-[12px] tx1 font-medium num truncate">{e.shift || 'صرف إداري'}</div>
                      </div>
                    </div>

                    {noteText && (
                      <div className={`op-expense-note line-clamp-2 ${e.voidReason ? 'is-void' : ''}`}>
                        {e.voidReason ? `سبب الإلغاء: ${e.voidReason}` : noteText}
                      </div>
                    )}

                    {(e.hasAttachment || canVoid) && (
                      <div className="op-order-card-foot" onClick={(ev) => ev.stopPropagation()}>
                        {e.hasAttachment ? (
                          <button
                            type="button"
                            className="btn btn-soft btn-sm flex-1 justify-center gap-1.5"
                            onClick={() => openReceipt(e.id)}
                          >
                            <Icon name="file" size={14} />
                            عرض الإيصال
                          </button>
                        ) : <span />}
                        {canVoid && (
                          <button
                            type="button"
                            className="btn btn-danger btn-sm flex-1 justify-center gap-1.5"
                            onClick={() => { setVoiding(e); setVoidReason(''); }}
                          >
                            <Icon name="ban" size={14} />
                            إلغاء المصروف
                          </button>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>
        <Pager total={list.data?.meta.total ?? 0} page={page} per={20} onPage={setPage} />
      </Card>
      {form && (
        <ExpenseForm onClose={() => setForm(false)} onSaved={() => { list.reload(); treasures.reload(); }} />
      )}
      {viewing && (
        <Modal
          open
          onClose={() => setViewing(null)}
          title={`سند صرف ${viewing.no}`}
          sub={viewing.date}
          icon="receipt"
          tone={viewing.voidedAt ? 'red' : 'orange'}
          footer={
            <div className="flex w-full sm:w-auto items-center justify-end gap-2">
              {viewing.hasAttachment && (
                <button type="button" className="btn btn-soft flex-1 sm:flex-initial" onClick={() => openReceipt(viewing.id)}>
                  <Icon name="file" size={14} />
                  الإيصال
                </button>
              )}
              {!viewing.voidedAt && !viewing.shift && can('expenses.delete') && (
                <button
                  type="button"
                  className="btn btn-danger flex-1 sm:flex-initial"
                  onClick={() => { const target = viewing; setViewing(null); setVoiding(target); setVoidReason(''); }}
                >
                  <Icon name="ban" size={14} />
                  إلغاء المصروف
                </button>
              )}
              <button type="button" className="btn btn-dark flex-1 sm:flex-initial" onClick={() => setViewing(null)}>إغلاق</button>
            </div>
          }
        >
          <div className="space-y-4">
            <div className="v-sub rounded-2xl p-4 flex items-center justify-between gap-3">
              <div>
                <div className="text-[11.5px] tx3">المبلغ المنصرف</div>
                <div className={`font-display text-[22px] font-bold num mt-0.5 ${viewing.voidedAt ? 'tx3 line-through' : 'c-red'}`}>
                  {fmtMoney(viewing.amount)}
                </div>
              </div>
              <div className="flex items-center gap-1.5">
                <Badge dot={false} tone="gray">{viewing.cat}</Badge>
                <Badge tone={viewing.voidedAt ? 'red' : 'green'}>{viewing.voidedAt ? 'ملغى' : 'مسجّل'}</Badge>
              </div>
            </div>
            <KV
              items={[
                ['الفرع', viewing.branch || '—'],
                ['الخزينة', nameOf(viewing.tr)],
                ['بواسطة', viewing.by || '—'],
                ['الوردية', viewing.shift ? <Num>{viewing.shift}</Num> : 'صرف إداري'],
                ['التاريخ', <Num>{viewing.date}</Num>],
              ]}
            />
            {viewing.notes && (
              <div className="op-expense-note">
                <div className="text-[11px] font-semibold tx3 mb-1">البيان / الملاحظات</div>
                <div className="tx1">{viewing.notes}</div>
              </div>
            )}
            {viewing.voidReason && (
              <div className="op-expense-note is-void">
                <div className="text-[11px] font-semibold mb-1">سبب الإلغاء</div>
                <div>{viewing.voidReason}</div>
              </div>
            )}
          </div>
        </Modal>
      )}
      {voiding && <Modal open title={`إلغاء المصروف ${voiding.no}`} onClose={() => { if (!busy) setVoiding(null); }}
        footer={
          <div className="flex w-full sm:w-auto items-center justify-end gap-2">
            <button type="button" className="btn btn-soft flex-1 sm:flex-initial" disabled={busy} onClick={() => setVoiding(null)}>تراجع</button>
            <button type="button" className="btn btn-dark flex-1 sm:flex-initial" disabled={busy || !voidReason.trim()} onClick={async () => {
              if (list.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
              const result = await run(() => expensesApi.void(voiding.id, voidReason.trim()), { toast, success: 'تم إلغاء المصروف وعكس أثره على الخزينة' });
              if (result) { setVoiding(null); list.reload(); treasures.reload(); }
            }}>تأكيد الإلغاء</button>
          </div>
        }>
        <div className="space-y-3">
          <Note>يُحفظ السند في سجل التدقيق ويُعاد المبلغ إلى الخزينة.</Note>
          <TextArea label="سبب الإلغاء" req value={voidReason} onChange={setVoidReason} />
        </div>
      </Modal>}
    </>
  );
}

export function TransfersTab({ recordId, can = () => false }: { recordId?: number; can?: (permission: string) => boolean } = {}) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [st, setSt] = useState('');
  const [tr, setTr] = useState('');
  const [view, setView] = useState<any>(null);
  const [rejecting, setRejecting] = useState<TreasuryTransfer | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [form, setForm] = useState(false);
  const [src, setSrc] = useState('');
  const [idemKey, setIdemKey] = useState(() => newIdempotencyKey());
  const [unresolved, setUnresolved] = useState(false);
  const [dst, setDst] = useState('');
  const [amount, setAmount] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [page, setPage] = useState(1);
  const setFilter = <T,>(setter: (v: T) => void) => (v: T) => { setter(v); setPage(1); };
  const list = useRemote(async (s) => {
    const result = recordId
      ? mockPage([await treasuryTransfersApi.show(recordId, s)])
      : await treasuryTransfersApi.list({ page, per_page: 20, status: st || undefined, treasury_id: tr ? Number(tr) : undefined }, s);
    return { ...result, data: result.data.map((x): TreasuryTransfer => ({
      id: x.id, from: x.from_treasury_id, to: x.to_treasury_id, amount: num(x.amount),
      status: x.status, date: (x.created_at ?? '').slice(0, 10), by: x.creator?.name ?? '', appr: x.approver?.name ?? null, notes: x.notes,
    })) };
  }, [recordId, page, st, tr], { mock: () => mockPage(TRANSFERS.filter((x) => recordId ? x.id === recordId : (!st || x.status === st) && (!tr || String(x.from) === tr || String(x.to) === tr)), page) });
  const treasures = useRemote((s) => allPages((page) => treasuriesApi.list({ page, per_page: 100 }, s)), []);
  const nameOf = (id: number) => (treasures.data ?? []).find((t) => t.id === id)?.name ?? (USE_MOCK ? trName(id) : `#${id}`);
  const srcTreasury = (treasures.data ?? []).find((t) => String(t.id) === src);
  const dstTreasury = (treasures.data ?? []).find((t) => String(t.id) === dst);
  const rows = (list.data?.data ?? []).filter(
    (x) => recordId || ((!st || x.status === st) && (!tr || String(x.from) === tr || String(x.to) === tr))
  );
  const hasFilters = Boolean(st || tr);
  const approve = async (id: number) => {
    if (list.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => treasuryTransfersApi.approve(id), { toast, success: 'تم اعتماد التحويل' });
    if (r) { setView(null); list.reload(); treasures.reload(); }
  };
  const reject = async () => {
    if (!rejecting) return;
    if (list.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => treasuryTransfersApi.reject(rejecting.id, rejectReason.trim()), { toast, success: 'تم رفض التحويل' });
    if (r) { setRejecting(null); setRejectReason(''); setView(null); list.reload(); treasures.reload(); }
  };
  const create = async () => {
    if (!src || !dst || src === dst) return toast('اختر مصدرًا ووجهة مختلفين', 'red');
    if (!amount || Number(amount) <= 0) return toast('أدخل مبلغًا صحيحًا', 'red');
    const r = await run(async () => {
      setUnresolved(true);
      try {
        return await treasuryTransfersApi.create({
          from_treasury_id: Number(src), to_treasury_id: Number(dst), amount, notes: notes || null,
        }, idemKey);
      } catch (error) {
        const { status } = parseApiError(error);
        if (status !== null && status >= 400 && status < 500 && ![408, 409, 425, 429].includes(status)) setUnresolved(false);
        throw error;
      }
    }, { toast, success: 'أُرسل طلب التحويل', onFieldErrors: setErrors });
    if (r) { setUnresolved(false); setIdemKey(newIdempotencyKey()); setForm(false); setSrc(''); setDst(''); setAmount(''); setNotes(''); list.reload(); treasures.reload(); }
  };
  const resetUnresolvedTransfer = () => {
    list.reload();
    setUnresolved(false);
    setIdemKey(newIdempotencyKey());
    setSrc('');
    setDst('');
    setAmount('');
    setNotes('');
    setErrors({});
  };
  const closeForm = () => {
    if (busy) return;
    setForm(false);
    if (!unresolved) { setIdemKey(newIdempotencyKey()); setErrors({}); }
  };

  return (
    <>
      <Card stg={3}>
        <div className="op-transfers-toolbar flex flex-wrap items-center justify-between gap-2 mb-4">
          <div className="op-transfers-filters flex flex-wrap items-center gap-2">
            <FSelect
              value={st}
              onChange={setFilter(setSt)}
              placeholder="كل الحالات"
              options={Object.entries(TRANSFER_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
            />
            <FSelect
              value={tr}
              onChange={setFilter(setTr)}
              placeholder="كل الخزائن"
              options={(treasures.data ?? []).map((t: { id: number; name: string }) => ({ value: String(t.id), label: t.name }))}
            />
            {hasFilters && (
              <button
                type="button"
                className="btn btn-ghost btn-xs col-span-2 sm:col-span-1 justify-center min-h-[38px] sm:min-h-[34px]"
                onClick={() => { setSt(''); setTr(''); setPage(1); }}
              >
                <Icon name="x" size={12} />
                مسح التصفية
              </button>
            )}
          </div>
          {can('treasury_transfers.create') && (
            <button className="btn btn-dark btn-sm w-full md:w-auto min-h-[44px] md:min-h-[38px]" onClick={() => setForm(true)}>
              <Icon name="plus" size={14} />
              طلب تحويل
            </button>
          )}
        </div>
        <RemoteNote remote={list} />
        <div className="hidden md:block">
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
                  x.status === 'pending' && can('treasury_transfers.approve') && (
                    <RowActions>
                      <button className="btn btn-danger btn-xs" disabled={busy} onClick={() => { setRejecting(x); setRejectReason(''); }}>
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
        </div>
        <div className="md:hidden">
          {rows.length === 0 ? (
            <Empty icon="swap" title="لا توجد تحويلات مطابقة" />
          ) : (
            <div className="op-transfer-cards">
              {rows.map((x: TreasuryTransfer) => {
                const interactive = !list.usingMock;
                const canAct = x.status === 'pending' && can('treasury_transfers.approve');
                return (
                  <div
                    key={x.id}
                    role={interactive ? 'button' : undefined}
                    tabIndex={interactive ? 0 : undefined}
                    onClick={interactive ? () => setView(x) : undefined}
                    onKeyDown={
                      interactive
                        ? (e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                              e.preventDefault();
                              setView(x);
                            }
                          }
                        : undefined
                    }
                    className={'op-transfer-card' + (interactive ? ' is-interactive' : '')}
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-display font-bold text-[18px] leading-none tx1">
                        {fmtMoney(x.amount)}
                      </span>
                      <StatusBadge map={TRANSFER_STATUS} value={x.status} />
                    </div>
                    <div className="op-transfer-route">
                      <div className="min-w-0">
                        <div className="text-[10.5px] tx3 mb-0.5">من</div>
                        <div className="font-semibold text-[13px] tx1 truncate">{nameOf(x.from)}</div>
                      </div>
                      <span className="w-7 h-7 rounded-full v-well flex items-center justify-center shrink-0">
                        <Icon name="arrowL" size={13} />
                      </span>
                      <div className="min-w-0 text-end">
                        <div className="text-[10.5px] tx3 mb-0.5">إلى</div>
                        <div className="font-semibold text-[13px] tx1 truncate">{nameOf(x.to)}</div>
                      </div>
                    </div>
                    <div className="op-transfer-meta text-[12px]">
                      <div className="min-w-0">
                        <span className="tx3">التاريخ: </span>
                        <Num className="tx2">{x.date}</Num>
                      </div>
                      <div className="min-w-0 truncate text-end">
                        <span className="tx3">المنشئ: </span>
                        <span className="tx2">{x.by || '—'}</span>
                      </div>
                      {x.appr && (
                        <div className="col-span-2 min-w-0 truncate">
                          <span className="tx3">المعتمد: </span>
                          <span className="tx2">{x.appr}</span>
                        </div>
                      )}
                      {x.notes && (
                        <div className="col-span-2 text-[11.5px] tx2 line-clamp-2">
                          {x.notes}
                        </div>
                      )}
                    </div>
                    {canAct && (
                      <div className="flex items-center gap-2 pt-1 v-bt" onClick={(e) => e.stopPropagation()}>
                        <button
                          type="button"
                          className="btn btn-danger flex-1 min-h-[44px]"
                          disabled={busy}
                          onClick={() => { setRejecting(x); setRejectReason(''); }}
                        >
                          رفض
                        </button>
                        <button
                          type="button"
                          className="btn btn-success flex-1 min-h-[44px]"
                          disabled={busy}
                          onClick={() => approve(x.id)}
                        >
                          اعتماد
                        </button>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>
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
            view.status === 'pending' && can('treasury_transfers.approve') && (
              <>
                <button
                  className="btn btn-danger flex-1 sm:flex-initial min-h-[44px] sm:min-h-[40px]"
                  disabled={busy}
                  onClick={() => { setRejecting(view); setRejectReason(''); }}
                >
                  رفض
                </button>
                <button
                  className="btn btn-dark flex-1 sm:flex-initial min-h-[44px] sm:min-h-[40px]"
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
            <div className="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2.5 sm:gap-3">
              <div className="v-sub p-3 text-center min-w-0">
                <div className="text-[11px] tx3 mb-1">من</div>
                <div className="font-semibold text-[12.5px] break-words">{nameOf(view.from)}</div>
              </div>
              <span className="w-9 h-9 rounded-full btn-accent flex items-center justify-center shrink-0">
                <Icon name="arrowL" size={15} />
              </span>
              <div className="v-sub p-3 text-center min-w-0">
                <div className="text-[11px] tx3 mb-1">إلى</div>
                <div className="font-semibold text-[12.5px] break-words">{nameOf(view.to)}</div>
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
        onClose={closeForm}
        title="طلب تحويل بين خزائن"
        icon="swap"
        width={520}
        sub="يُنشأ بحالة «بانتظار الاعتماد» ولا يمكن تعديله بعد ذلك."
        footer={
          <>
            <button className="btn btn-soft flex-1 sm:flex-initial min-h-[44px] sm:min-h-[40px]" disabled={busy} onClick={closeForm}>
              إلغاء
            </button>
            <button
              className="btn btn-dark flex-1 sm:flex-initial min-h-[44px] sm:min-h-[40px]"
              disabled={!src || !dst || src === dst || busy}
              onClick={create}
            >
              إنشاء الطلب
            </button>
          </>
        }
      >
        {unresolved && (
          <div className="mb-3 space-y-2">
            <Note tone="amber" icon="alert">
              نتيجة الطلب السابق لم تُحسم. أعد الإرسال بنفس البيانات للتحقق منه بأمان، أو تحقق من القائمة ثم ابدأ تحويلاً جديداً.
            </Note>
            <button type="button" className="btn btn-soft btn-xs" disabled={busy} onClick={resetUnresolvedTransfer}>
              تحققت من القائمة — بدء تحويل جديد بمفتاح جديد
            </button>
          </div>
        )}
        <fieldset disabled={busy || unresolved} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Field
              label="الخزينة المصدر"
              req
              err={errors.from_treasury_id}
              hint={srcTreasury ? `الرصيد المتاح: ${fmtNum(srcTreasury.current_balance)} EGP` : undefined}
            >
              <select className="inp min-h-[44px] sm:min-h-0" value={src} onChange={(e) => setSrc(e.target.value)}>
                <option value="">اختر</option>
                {(treasures.data ?? []).map((x: { id: number; name: string }) => (
                  <option key={x.id} value={x.id}>
                    {x.name}
                  </option>
                ))}
              </select>
            </Field>
            <Field
              label="الخزينة الوجهة"
              req
              err={errors.to_treasury_id || (src && src === dst ? 'يجب أن تختلف عن المصدر' : null)}
              hint={dstTreasury ? `الرصيد الحالي: ${fmtNum(dstTreasury.current_balance)} EGP` : undefined}
            >
              <select
                className={'inp min-h-[44px] sm:min-h-0 ' + (src && src === dst ? 'err' : '')}
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
          {(src || dst) && (
            <div className="flex justify-end -mt-1">
              <button
                type="button"
                className="btn btn-soft btn-xs min-h-[36px]"
                onClick={() => { const prevSrc = src; setSrc(dst); setDst(prevSrc); }}
              >
                <Icon name="swap" size={13} />
                تبديل المصدر والوجهة
              </button>
            </div>
          )}
          <Field label="المبلغ" req err={errors.amount}>
            <input
              type="number"
              inputMode="decimal"
              step="0.01"
              min="0.01"
              placeholder="0.00"
              className="inp num min-h-[44px] sm:min-h-0"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
            />
          </Field>
          <TextArea label="ملاحظات" value={notes} onChange={setNotes} />
        </fieldset>
      </Modal>
      {rejecting && (
        <Modal
          open
          onClose={() => { if (!busy) setRejecting(null); }}
          title="رفض طلب التحويل"
          icon="swap"
          width={460}
          footer={
            <>
              <button className="btn btn-soft flex-1 sm:flex-initial min-h-[44px] sm:min-h-[40px]" disabled={busy} onClick={() => setRejecting(null)}>
                إلغاء
              </button>
              <button className="btn btn-danger flex-1 sm:flex-initial min-h-[44px] sm:min-h-[40px]" disabled={busy || rejectReason.trim().length < 3} onClick={reject}>
                تأكيد الرفض
              </button>
            </>
          }
        >
          <div className="space-y-3">
            <Note>لن يتم تحريك أي أرصدة؛ يُحفظ سبب الرفض في سجل التدقيق.</Note>
            <TextArea label="سبب الرفض" req value={rejectReason} onChange={setRejectReason} />
          </div>
        </Modal>
      )}
    </>
  );
}

/* ---------------- SHIFTS PAGE ---------------- */
export function DiffCell({ s }: { s: ShiftRow }) {
  if (s.exp == null || s.act == null) return <span className="tx3">—</span>;
  const d = Math.round((s.act - s.exp) * 100) / 100;
  if (d === 0) return <Badge tone="green" dot={false}>مطابق</Badge>;
  return (
    <span className={'font-semibold ' + (d < 0 ? 'c-red' : 'c-amber')}>
      {d < 0 ? 'عجز ' : 'زيادة '}
      <span className="num ltr">{fmtNum(Math.abs(d))}</span>
    </span>
  );
}

export function ShiftsPage({ can = () => false }: { can?: (permission: string) => boolean } = {}) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [br, setBr] = useState('');
  const [ca, setCa] = useState('');
  const [st, setSt] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [view, setView] = useState<ShiftRow | null>(null);

  const [page, setPage] = useState(1);
  const setFilter = <T,>(setter: (v: T) => void) => (v: T) => { setter(v); setPage(1); };
  const list = useRemote((s) => shiftsApi.list({ page, per_page: 20, branch_id: br || undefined, user_id: ca || undefined, status: st === 'closed' ? 'closed_pending_approval' : st || undefined, from_date: from || undefined, to_date: to || undefined }, s).then((p) => ({ ...p, data: p.data.map(mapShift) })), [page, br, ca, st, from, to], { mock: () => mockPage(SHIFTS) });
  const branches = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/branches', { params: { page, per_page: 100 }, signal: s }).then((r) => asPage<{ id: number; name: string }>(r))), []);
  const cashiers = useRemote((s) => allPages((page) => opsApi.get('/api/v1/admin/users', { params: { page, per_page: 100 }, signal: s }).then((r) => asPage<{id: number; name: string}>(r))), []);
  const treasures = useRemote((s) => allPages((page) => treasuriesApi.list({ page, per_page: 100 }, s)), []);
  const nameOf = (id: number) => treasures.data?.find((t) => t.id === id)?.name ?? (USE_MOCK ? trName(id) : `#${id}`);
  const rows = list.data?.data ?? [];
  const pendingCount = rows.filter((s) => s.status === 'closed').length;
  const diffCount = rows.filter((s) => s.exp != null && s.act != null && Math.round((s.act - s.exp) * 100) !== 0).length;
  const activeFilterCount = [br, ca, from, to].filter(Boolean).length;
  const hasAnyFilter = Boolean(st || activeFilterCount > 0);
  const clearFilters = () => { setBr(''); setCa(''); setSt(''); setFrom(''); setTo(''); setPage(1); };

  const approveShift = async (id: number) => {
    if (list.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => shiftsApi.approve(id), { toast, success: 'تم اعتماد الوردية' });
    if (r) { setView(null); list.reload(); }
  };

  const statusChips = [
    { value: '', label: 'الكل' },
    { value: 'closed', label: 'بانتظار الاعتماد' },
    { value: 'open', label: 'مفتوحة' },
    { value: 'approved', label: 'معتمدة' },
  ];

  return (
    <div className="space-y-5">
      <PageHead title="مراجعة الورديات" sub="مطابقة النقدية المتوقعة مع الفعلية واعتماد الورديات المغلقة." />
      <div className="op-shifts-kpis stg stg-2">
        <button
          type="button"
          className="op-shift-kpi-trigger"
          onClick={() => setFilter(setSt)(st === 'closed' ? '' : 'closed')}
          aria-pressed={st === 'closed'}
          title="تصفية الورديات بانتظار الاعتماد"
        >
          <KPI
            hero
            label="بانتظار الاعتماد (الصفحة الحالية)"
            icon="clock"
            value={pendingCount}
            foot={st === 'closed' ? 'مفعّل كفلتر · اضغط للإلغاء' : 'اضغط للتصفية · ورديات مغلقة'}
          />
        </button>
        <KPI
          label="ورديات بها فروق (الصفحة الحالية)"
          icon="alert"
          value={diffCount}
          foot="عجز أو زيادة في الصفحة الحالية"
        />
        <KPI label="إجمالي الورديات المسجلة" icon="calendar" value={list.data?.meta.total ?? 0} foot="وردية" />
      </div>
      <Card stg={3}>
        <div className="op-shifts-toolbar space-y-2.5 mb-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="op-shifts-status-pills w-full md:w-auto" role="group" aria-label="تصفية حسب الحالة">
              {statusChips.map((chip) => {
                const active = st === chip.value;
                return (
                  <button
                    key={chip.value || 'all'}
                    type="button"
                    className={'pill-inp font-medium ' + (active ? 'on' : '')}
                    aria-pressed={active}
                    onClick={() => setFilter(setSt)(chip.value)}
                  >
                    {chip.label}
                  </button>
                );
              })}
            </div>
            <div className="flex items-center gap-2 w-full md:w-auto justify-between md:hidden">
              <button
                type="button"
                className={'pill-inp inline-flex items-center gap-1.5 font-medium min-h-[40px] ' + (filtersOpen || activeFilterCount > 0 ? 'on' : '')}
                onClick={() => setFiltersOpen((v) => !v)}
                aria-expanded={filtersOpen}
              >
                <Icon name="filter" size={14} />
                <span>تصفية متقدمة</span>
                {activeFilterCount > 0 && (
                  <span className="num text-[11px] font-bold px-1.5 py-0.5 rounded-full bg-white/20">
                    {activeFilterCount}
                  </span>
                )}
              </button>
              {hasAnyFilter && (
                <button type="button" className="btn btn-ghost btn-xs" onClick={clearFilters}>
                  إعادة ضبط
                </button>
              )}
            </div>
          </div>

          <div className={'op-shifts-filters flex-wrap items-center gap-2 ' + (filtersOpen ? 'flex' : 'hidden md:flex')}>
            <FSelect value={br} onChange={setFilter(setBr)} placeholder="الفرع" options={(branches.data ?? []).map((b) => ({ value: String(b.id), label: b.name }))} />
            <FSelect value={ca} onChange={setFilter(setCa)} placeholder="الكاشير" options={(cashiers.data ?? []).map((c) => ({ value: String(c.id), label: c.name }))} />
            <FDate label="من" value={from} onChange={setFilter(setFrom)} />
            <FDate label="إلى" value={to} onChange={setFilter(setTo)} />
            {hasAnyFilter && (
              <button type="button" className="hidden md:inline-flex btn btn-ghost btn-xs" onClick={clearFilters}>
                مسح الفلاتر
              </button>
            )}
          </div>
        </div>

        <RemoteNote remote={list} />

        <div className="hidden md:block">
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
        </div>

        <div className="md:hidden">
          {list.loading ? (
            <div className="py-8 flex justify-center"><AdminLoading compact /></div>
          ) : rows.length === 0 ? (
            <div className="py-10 text-center space-y-3">
              <div className="tx3 text-[13px]">لا توجد ورديات مطابقة</div>
              {hasAnyFilter && (
                <button type="button" className="btn btn-soft btn-sm" onClick={clearFilters}>
                  إعادة ضبط الفلاتر
                </button>
              )}
            </div>
          ) : (
            <div className="op-shift-cards">
              {rows.map((s) => {
                const isPending = s.status === 'closed';
                return (
                  <div
                    key={s.id}
                    role="button"
                    tabIndex={0}
                    onClick={() => setView(s)}
                    onKeyDown={(ev) => {
                      if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        setView(s);
                      }
                    }}
                    className={`op-shift-card ${isPending ? 'is-pending' : ''}`}
                  >
                    <div className="op-order-card-head">
                      <span className="num font-bold tx1 text-[14px]">{s.no}</span>
                      <StatusBadge map={SHIFT_STATUS} value={s.status} />
                    </div>

                    <div className="op-expense-amount-band">
                      <div className="flex items-center gap-2.5 min-w-0">
                        <Avatar name={s.cashier} tone={s.id} size={32} />
                        <div className="min-w-0 leading-tight">
                          <div className="text-[13px] font-semibold tx1 truncate">{s.cashier || '—'}</div>
                          <div className="text-[11px] tx3 truncate mt-0.5">
                            {s.branch} · {nameOf(s.tr)}
                          </div>
                        </div>
                      </div>
                      <div className="text-[13px] whitespace-nowrap shrink-0">
                        <DiffCell s={s} />
                      </div>
                    </div>

                    <div className="op-shift-amounts">
                      <div className="min-w-0">
                        <div className="text-[10.5px] tx3 mb-0.5">النقدية المتوقعة</div>
                        <div className="text-[13px] tx1 font-semibold truncate">
                          {s.exp != null ? fmtMoney(s.exp) : <span className="tx3">—</span>}
                        </div>
                      </div>
                      <div className="min-w-0">
                        <div className="text-[10.5px] tx3 mb-0.5">النقدية الفعلية</div>
                        <div className="text-[13px] tx1 font-semibold truncate">
                          {s.act != null ? fmtMoney(s.act) : <span className="tx3">—</span>}
                        </div>
                      </div>
                    </div>

                    <div className="op-shift-times">
                      <span>
                        <span className="tx3">الفتح: </span>
                        <span className="num">{s.open}</span>
                      </span>
                      <span>
                        <span className="tx3">الإغلاق: </span>
                        <span className="num">{s.close || 'مفتوحة'}</span>
                      </span>
                    </div>

                    {isPending && can('pos_shifts.approve') && (
                      <div className="op-order-card-foot">
                        <span className="btn btn-dark btn-sm w-full justify-center gap-1.5">
                          <Icon name="check" size={14} />
                          مراجعة واعتماد الوردية
                        </span>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>

        <Pager total={list.data?.meta.total ?? 0} page={page} per={20} onPage={setPage} />
      </Card>
      {view && (
        <ShiftModal
          s={view}
          busy={busy}
          canApprove={can('pos_shifts.approve')}
          onClose={() => setView(null)}
          onApprove={() => approveShift(view.id)}
        />
      )}
    </div>
  );
}

export function ShiftModal({ s: initial, busy = false, canApprove = false, onClose, onApprove }: { s: ShiftRow; busy?: boolean; canApprove?: boolean; onClose: () => void; onApprove: () => void }) {
  const detail = useRemote((signal) => shiftsApi.show(initial.id, signal), [initial.id]);
  const s = detail.data ? mapShift(detail.data) : initial;
  const opening = num(detail.data?.opening_cash);
  const cashSales = num(detail.data?.cash_sales_total);
  const cardSales = num(detail.data?.card_sales_total);
  const cashRet = num(detail.data?.cash_returns_total);
  const cashExp = num(detail.data?.cash_expenses_total);
  const exp = s.exp ?? (detail.data ? opening + cashSales - cashRet - cashExp : null);
  const d = s.act != null && exp != null ? Math.round((s.act - exp) * 100) / 100 : null;
  const shiftExpenses = (detail.data?.expenses ?? []).map(mapExpense);

  return (
    <Modal
      open
      onClose={onClose}
      width={680}
      icon="clock"
      title={
        <span className="flex flex-wrap items-center gap-2">
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
        detail.data?.status === 'closed_pending_approval' && canApprove ? (
          <div className="flex w-full sm:w-auto items-center justify-end gap-2">
            <button type="button" className="btn btn-soft flex-1 sm:flex-initial justify-center" disabled={busy} onClick={onClose}>
              إغلاق
            </button>
            <button type="button" className="btn btn-dark flex-1 sm:flex-initial justify-center" disabled={busy} onClick={onApprove}>
              <Icon name="check" size={14} />
              اعتماد الوردية
            </button>
          </div>
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
              <div key={l as string} className="flex items-center justify-between gap-2">
                <span className="tx2">{l}</span>
                <span className={'font-semibold shrink-0 ' + (c as string)}>{fmtMoney(v as number)}</span>
              </div>
            ))}
            <div className="flex items-center justify-between gap-2 v-bt pt-2.5">
              <span className="font-semibold tx1">النقدية المتوقعة</span>
              <span className="font-display font-bold shrink-0">{exp != null ? fmtMoney(exp) : '—'}</span>
            </div>
            <div className="flex items-center justify-between gap-2 text-[11.5px] tx3">
              <span>مبيعات البطاقات (خارج الدرج)</span>
              <span className="shrink-0">{fmtMoney(cardSales)}</span>
            </div>
          </div>
          <div
            className={
              'order-first sm:order-last rounded-[18px] p-4 flex flex-col justify-between ' +
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
          <div className="hidden sm:block">
            <DataTable
              compact
              rows={shiftExpenses}
              cols={[
                { h: 'رقم السند', r: (e: ExpenseRow) => <span className="num">{e.no}</span> },
                { h: 'التصنيف', r: (e: ExpenseRow) => e.cat },
                { h: 'المبلغ', r: (e: ExpenseRow) => fmtMoney(e.amount) },
                { h: 'البيان', r: (e: ExpenseRow) => e.notes || '—' },
              ]}
              empty="لا توجد مصروفات مسجلة على هذه الوردية"
            />
          </div>
          <div className="sm:hidden">
            {shiftExpenses.length === 0 ? (
              <div className="v-sub rounded-[14px] py-6 text-center text-[12.5px] tx3">
                لا توجد مصروفات مسجلة على هذه الوردية
              </div>
            ) : (
              <div className="op-shift-exp-list">
                {shiftExpenses.map((e) => (
                  <div key={e.id} className="op-shift-exp-item">
                    <div className="flex items-center justify-between gap-2">
                      <div className="flex items-center gap-1.5 min-w-0">
                        <span className="num text-[12.5px] font-semibold tx1">{e.no}</span>
                        <Badge dot={false} tone="gray">{e.cat}</Badge>
                      </div>
                      <span className="text-[13px] font-semibold c-red shrink-0">{fmtMoney(e.amount)}</span>
                    </div>
                    {e.notes && <div className="text-[11.5px] tx2 leading-relaxed">{e.notes}</div>}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </Modal>
  );
}

/* ---------------- WHOLESALE PAGE ---------------- */
export function WholesalePage({ sub = 'quotes', can = () => false }: { sub?: string; can?: (permission: string) => boolean }) {
  const [tab, setTab] = useState<'quotes' | 'notices'>(sub === 'notices' ? 'notices' : 'quotes');
  useEffect(() => { setTab(sub === 'notices' ? 'notices' : 'quotes'); }, [sub]);
  const tabs = [
    { value: 'quotes', label: 'عروض الأسعار', icon: 'quote', permission: 'quotations.view' },
    { value: 'notices', label: 'إشعارات السداد', icon: 'bank', permission: 'payment_notices.view' },
  ].filter((t) => can(t.permission));
  const activeTab = tabs.some((t) => t.value === tab) ? tab : (tabs[0]?.value as 'quotes' | 'notices' | undefined);

  if (!activeTab) {
    return (
      <div className="space-y-5">
        <PageHead title="الجملة" sub="تسعير عروض أسعار التجار ومراجعة إشعارات السداد الواردة من بوابة الجملة." />
        <Empty icon="lock" title="ليس لديك صلاحية عرض الجملة." />
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <PageHead title="الجملة" sub="تسعير عروض أسعار التجار ومراجعة إشعارات السداد الواردة من بوابة الجملة." />
      <TabsBar
        value={activeTab}
        onChange={(v: any) => setTab(v)}
        tabs={tabs}
      />
      {activeTab === 'quotes' ? <QuotesTab can={can} /> : <NoticesTab can={can} />}
    </div>
  );
}

export function QuotesTab({ can = () => false }: { can?: (permission: string) => boolean } = {}) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [st, setSt] = useState('');
  const [cu, setCu] = useState('');
  const [customerSearch, setCustomerSearch] = useState('');
  const debouncedCustomerSearch = useDebounced(customerSearch);
  const [page, setPage] = useState(1);
  const [view, setView] = useState<QuoteRow | null>(null);
  const [price, setPrice] = useState(false);
  const [expire, setExpire] = useState(false);
  const [prices, setPrices] = useState<Record<number, string>>({});
  const [validUntil, setValidUntil] = useState('');
  const [adminNotes, setAdminNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const setFilter = <T,>(setter: (v: T) => void) => (v: T) => { setter(v); setPage(1); };
  const status = st === 'priced' ? 'priced_by_admin' : st === 'converted' || st === 'ordered' ? 'converted_to_order' : st;
  const list = useRemote((s) => quotationsApi.list({ page, per_page: 20, status: status || undefined, customer_id: cu || undefined }, s).then((p) => ({ ...p, data: p.data.map(mapQuote) })), [page, st, cu], { mock: () => mockPage(QUOTES) });
  const customers = useRemote(
    (s) => opsApi.get('/api/v1/admin/customers', { params: { page: 1, type: 'wholesale', per_page: 50, search: debouncedCustomerSearch }, signal: s })
      .then((r) => asPage<{ id: number; name: string; company_name?: string }>(r).data),
    [debouncedCustomerSearch],
    { enabled: debouncedCustomerSearch.trim().length >= 3 },
  );
  const detail = useRemote((s) => quotationsApi.show(view!.id, s), [view?.id], { enabled: !!view });
  useEffect(() => {
    if (!detail.data) return;
    setPrices(Object.fromEntries((detail.data.items ?? []).map((item) => [item.id, item.offered_unit_price ?? item.requested_target_price ?? ''])));
    setValidUntil(detail.data.valid_until ?? '');
    setAdminNotes(detail.data.admin_notes ?? '');
    setErrors({});
  }, [detail.data]);
  const rows = list.data?.data ?? [];
  const lines = (detail.data?.items ?? []).map((item) => ({ id: item.id, v: { name: item.variant?.product_name ?? '—', sku: item.variant?.sku ?? '', price: num(item.offered_unit_price) }, qty: item.qty, price: item.offered_unit_price === null ? null : num(item.offered_unit_price), note: (item as any).notes ?? (item as any).note ?? null }));
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

  const totalCount = list.data?.meta.total ?? rows.length;
  const anyFilter = Boolean(st || cu || customerSearch);
  const resetFilters = () => {
    setSt('');
    setCu('');
    setCustomerSearch('');
    setPage(1);
  };

  return (
    <>
      <Card stg={3}>
        <div className="op-filter-shell">
          <div className="op-filter-primary">
            <div className="op-search-box">
              <Icon name="search" size={15} className="tx3 shrink-0" />
              <input
                type="search"
                value={customerSearch}
                onChange={(e) => setCustomerSearch(e.target.value)}
                placeholder="ابحث عن عميل جملة (3 أحرف على الأقل)..."
                className="op-search-input"
                aria-label="بحث عن عميل"
              />
              {customerSearch && (
                <button
                  type="button"
                  onClick={() => setCustomerSearch('')}
                  className="op-search-clear"
                  aria-label="مسح البحث"
                >
                  <Icon name="x" size={12} />
                </button>
              )}
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {anyFilter && (
                <button type="button" className="btn btn-ghost btn-xs" onClick={resetFilters}>
                  <Icon name="x" size={12} />
                  مسح الفلاتر
                </button>
              )}
              <span className="v-sub px-3.5 py-1.5 rounded-full text-[12px] font-medium tx2 inline-flex items-center gap-2">
                <span>إجمالي العروض</span>
                <strong className="tx1 num font-bold">{fmtNum(totalCount)}</strong>
              </span>
            </div>
          </div>
          <div className="op-filter-secondary !grid-cols-1 sm:!grid-cols-2">
            <FSelect
              value={st}
              onChange={setFilter(setSt)}
              placeholder="كل الحالات"
              options={Object.entries(QUOTE_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
            />
            <FSelect
              value={cu}
              onChange={setFilter(setCu)}
              placeholder={debouncedCustomerSearch.trim().length >= 3 ? `نتائج العملاء (${(customers.data ?? []).length})` : 'العميل (اكتب للبحث أعلاه)'}
              options={(customers.data ?? []).map((c) => ({ value: String(c.id), label: c.company_name || c.name }))}
            />
          </div>
        </div>
        <RemoteNote remote={list} />
        <div className="hidden md:block op-orders-table-wrap">
          <DataTable
            rows={rows}
            onRow={setView}
            cols={[
              { h: 'رقم العرض', r: (q: QuoteRow) => <span className="num font-semibold tx1 text-[13px]">{q.no}</span> },
              {
                h: 'العميل / الشركة',
                r: (q: QuoteRow) => (
                  <div className="flex items-center gap-2.5 min-w-0">
                    <Avatar name={q.company} tone={q.id} size={32} />
                    <div className="leading-tight min-w-0">
                      <div className="font-semibold tx1 truncate">{q.company}</div>
                      <div className="text-[11px] tx3 truncate mt-0.5">{q.cust}</div>
                    </div>
                  </div>
                ),
              },
              { h: 'الحالة', r: (q: QuoteRow) => <StatusBadge map={QUOTE_STATUS} value={q.status} /> },
              {
                h: 'الإجمالي',
                r: (q: QuoteRow) =>
                  q.total ? (
                    <span className="font-semibold tx1 num whitespace-nowrap">{fmtMoney(q.total)}</span>
                  ) : (
                    <span className="tx3 text-[11.5px]">لم يُسعّر</span>
                  ),
              },
              {
                h: 'الصلاحية',
                r: (q: QuoteRow) => (q.valid ? <Num className="tx2 whitespace-nowrap">{q.valid}</Num> : <span className="tx3">—</span>),
              },
              { h: 'التاريخ', r: (q: QuoteRow) => <Num className="tx2 whitespace-nowrap">{q.date}</Num> },
              {
                h: '',
                align: 'left',
                r: (q: QuoteRow) => (
                  <RowActions>
                    <div className="flex items-center justify-end gap-1.5">
                      {['requested', 'priced'].includes(q.status) && can('quotations.price') && (
                        <button
                          type="button"
                          className="btn btn-accent btn-xs"
                          disabled={list.usingMock}
                          onClick={() => {
                            setView(q);
                            setPrice(true);
                          }}
                        >
                          تسعير
                        </button>
                      )}
                      <button
                        type="button"
                        className="btn btn-soft btn-xs inline-flex items-center gap-1"
                        onClick={() => setView(q)}
                      >
                        <span>التفاصيل</span>
                        <Icon name="chevL" size={12} />
                      </button>
                    </div>
                  </RowActions>
                ),
              },
            ]}
          />
        </div>
        <div className="md:hidden">
          {list.loading ? (
            <div className="py-8 flex justify-center"><AdminLoading compact /></div>
          ) : rows.length === 0 ? (
            <div className="py-10 text-center tx3 text-[13px]">لا توجد عروض أسعار مطابقة</div>
          ) : (
            <div className="op-order-cards">
              {(rows as QuoteRow[]).map((q) => (
                <div
                  key={q.id}
                  role="button"
                  tabIndex={0}
                  onClick={() => setView(q)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      setView(q);
                    }
                  }}
                  className="op-order-card"
                >
                  <div className="op-order-card-head">
                    <span className="num font-bold tx1 text-[13.5px]">{q.no}</span>
                    <StatusBadge map={QUOTE_STATUS} value={q.status} />
                  </div>

                  <div className="op-order-card-customer">
                    <div className="flex items-center gap-2.5 min-w-0 flex-1">
                      <Avatar name={q.company} tone={q.id} size={34} />
                      <div className="min-w-0 flex-1 leading-tight">
                        <div className="font-semibold tx1 text-[13.5px] truncate">{q.company}</div>
                        <div className="text-[11.5px] tx3 truncate mt-0.5">{q.cust}</div>
                      </div>
                    </div>
                    <div className="text-end shrink-0">
                      {q.total ? (
                        <div className="font-display font-bold tx1 text-[15px] num whitespace-nowrap">
                          {fmtMoney(q.total)}
                        </div>
                      ) : (
                        <span className="text-[11.5px] tx3">لم يُسعّر</span>
                      )}
                    </div>
                  </div>

                  <div className="op-order-card-meta">
                    <div className="min-w-0">
                      <div className="text-[10.5px] tx3 mb-0.5">الصلاحية</div>
                      <div className="text-[12px] tx1 font-medium num truncate">{q.valid || '—'}</div>
                    </div>
                    <div className="min-w-0">
                      <div className="text-[10.5px] tx3 mb-0.5">تاريخ الطلب</div>
                      <div className="text-[12px] tx1 font-medium num truncate">{q.date}</div>
                    </div>
                  </div>

                  <div className="op-order-card-foot">
                    <div className="flex items-center gap-2">
                      {['requested', 'priced'].includes(q.status) && can('quotations.price') && (
                        <button
                          type="button"
                          className="btn btn-accent btn-xs min-h-[32px]"
                          disabled={list.usingMock}
                          onClick={(e) => {
                            e.stopPropagation();
                            setView(q);
                            setPrice(true);
                          }}
                        >
                          تسعير
                        </button>
                      )}
                    </div>
                    <span className="btn btn-soft btn-xs inline-flex items-center gap-1 shrink-0 ms-auto">
                      <span>التفاصيل</span>
                      <Icon name="chevL" size={12} />
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
        <Pager total={totalCount} page={page} per={20} onPage={setPage} />
      </Card>
      {view && !price && (
        <Modal
          open
          onClose={() => setView(null)}
          width={760}
          icon="quote"
          title={
            <span className="flex flex-wrap items-center gap-2">
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
            (() => {
              const effectiveStatus = detail.data ? quoteStatus(detail.data.status) : view.status;
              if (!can('quotations.price') || !['priced', 'requested'].includes(effectiveStatus)) return null;
              return (
                <div className="flex flex-wrap w-full items-center gap-2">
                  <button className="btn btn-ghost min-h-[42px] sm:min-h-0 sm:me-auto" disabled={busy || detail.loading || !detail.data || list.usingMock} onClick={() => setExpire(true)}>
                    <Icon name="clock" size={13} />
                    إنهاء الصلاحية
                  </button>
                  <button className="btn btn-dark flex-1 sm:flex-none min-h-[42px] sm:min-h-0 justify-center" disabled={detail.loading || !detail.data || list.usingMock} onClick={() => setPrice(true)}>
                    {effectiveStatus === 'priced' ? 'إعادة التسعير' : 'تسعير العرض'}
                  </button>
                </div>
              );
            })()
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
            <div className="hidden sm:block">
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
            </div>
            <div className="sm:hidden space-y-2">
              {lines.map((l: any) => (
                <div key={l.id} className="op-return-item-card">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1 leading-tight">
                      <div className="font-semibold tx1 text-[13px]">{l.v.name}</div>
                      {l.v.sku && <div className="text-[11px] tx3 num mt-0.5">{l.v.sku}</div>}
                      {l.note && <div className="text-[11px] tx2 mt-1">«{l.note}»</div>}
                    </div>
                    <span className="v-card px-2 py-0.5 rounded-full text-[11px] font-semibold num shrink-0">
                      × <Num>{l.qty}</Num>
                    </span>
                  </div>
                  <div className="grid grid-cols-2 gap-2 pt-2 border-t border-[var(--border-subtle)] text-[12px]">
                    <div>
                      <span className="tx3 block text-[10.5px]">السعر المعروض</span>
                      <span className="font-medium tx1 num">{l.price !== null ? fmtMoney(l.price) : '—'}</span>
                    </div>
                    <div className="text-end">
                      <span className="tx3 block text-[10.5px]">الإجمالي</span>
                      <span className="font-semibold tx1 num">{l.price !== null ? fmtMoney(l.price * l.qty) : '—'}</span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
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
            <div className="flex w-full sm:w-auto items-center gap-2 ms-auto">
              <button className="btn btn-soft flex-1 sm:flex-none min-h-[44px] sm:min-h-0 justify-center" onClick={() => setPrice(false)}>
                إلغاء
              </button>
              <button
                className="btn btn-dark flex-1 sm:flex-none min-h-[44px] sm:min-h-0 justify-center"
                disabled={busy || detail.loading || !detail.data} onClick={savePrice}
              >
                إرسال التسعير للعميل
              </button>
            </div>
          }
        >
          <div className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
            <div className="v-sub p-2.5 sm:p-2 space-y-2 sm:space-y-0">
              <div className="hidden sm:grid sm:grid-cols-12 gap-2 px-2 py-1 text-[11px] tx3">
                <span className="col-span-5">البند المطلوب</span>
                <span className="col-span-2 text-center">الكمية</span>
                <span className="col-span-2 text-end">سعر الوحدة</span>
                <span className="col-span-3 text-end">الإجمالي</span>
              </div>
              {lines.map((l, i) => (
                <div
                  key={i}
                  className="grid grid-cols-12 gap-2.5 sm:gap-2 items-center p-3 sm:px-2 sm:py-1.5 rounded-xl sm:rounded-none bg-[var(--bg-card)] sm:bg-transparent border sm:border-0 border-[var(--border-subtle)] sm:v-bb"
                >
                  <div className="col-span-12 sm:col-span-5 flex items-start justify-between gap-2 leading-tight">
                    <div className="min-w-0">
                      <div className="text-[13px] sm:text-[12.5px] font-medium clamp1">{l.v.name}</div>
                      <div className="text-[11px] sm:text-[10.5px] tx3 num">{l.v.sku}</div>
                    </div>
                    <span className="sm:hidden v-sub px-2 py-0.5 rounded-full text-[11px] font-semibold num shrink-0">
                      × <Num>{l.qty}</Num>
                    </span>
                  </div>
                  <Num className="hidden sm:block sm:col-span-2 text-center font-semibold">{l.qty}</Num>
                  <div className="col-span-7 sm:col-span-2">
                    <span className="sm:hidden block text-[11px] tx3 mb-1">سعر الوحدة</span>
                    <input
                      type="number"
                      step="0.01"
                      aria-label={`سعر الوحدة - ${l.v.name}`}
                      className="inp sm:inp-sm num text-end !bg-[var(--bg-subcard)] sm:!bg-[var(--bg-card)]"
                      min="0" value={prices[l.id] ?? ''} onChange={(e) => setPrices((prev) => ({ ...prev, [l.id]: e.target.value }))}
                    />
                  </div>
                  <div className="col-span-5 sm:col-span-3 text-end">
                    <span className="sm:hidden block text-[11px] tx3 mb-1">الإجمالي</span>
                    <span className="font-semibold num text-[13px] sm:text-[12px]">
                      {fmtMoney(num(prices[l.id]) * l.qty)}
                    </span>
                  </div>
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

export function NoticesTab({ can = () => false }: { can?: (permission: string) => boolean } = {}) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [st, setSt] = useState('');
  const [cu, setCu] = useState('');
  const [customerSearch, setCustomerSearch] = useState('');
  const debouncedCustomerSearch = useDebounced(customerSearch);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<PaymentNoticeRow | null>(null);
  const [mode, setMode] = useState<'approve' | 'reject' | null>(null);
  const [treasuryId, setTreasuryId] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const setFilter = <T,>(setter: (v: T) => void) => (v: T) => { setter(v); setPage(1); setSelected(null); };
  useEffect(() => { setNotes(''); setTreasuryId(''); setErrors({}); }, [mode, selected?.id]);
  const list = useRemote((s) => paymentNoticesApi.list({ page, per_page: 20, status: st === 'pending' ? 'pending_review' : st || undefined, customer_id: cu || undefined }, s).then((p) => ({ ...p, data: p.data.map(mapNotice) })), [page, st, cu], { mock: () => mockPage(NOTICES) });
  const detail = useRemote((s) => paymentNoticesApi.show(selected!.id, s), [selected?.id], { enabled: !!selected });
  const treasures = useRemote((s) => allPages((page) => treasuriesApi.list({ page, per_page: 100, is_active: true }, s)), []);
  const customers = useRemote(
    (s) => opsApi.get('/api/v1/admin/customers', { params: { page: 1, type: 'wholesale', per_page: 50, search: debouncedCustomerSearch }, signal: s })
      .then((r) => asPage<{ id: number; name: string; company_name?: string }>(r).data),
    [debouncedCustomerSearch],
    { enabled: debouncedCustomerSearch.trim().length >= 3 },
  );
  const nameOf = (id: number) => treasures.data?.find((t) => t.id === id)?.name ?? (USE_MOCK ? trName(id) : '#' + id);
  const rows = list.data?.data ?? [];
  const sel = detail.data && detail.data.id === selected?.id ? mapNotice(detail.data) : selected;
  const setSel = setSelected;
  useEffect(() => {
    if (rows.length && (!selected || !rows.some((r) => r.id === selected.id))) {
      setSelected(rows[0]);
    } else if (!rows.length && selected) {
      setSelected(null);
    }
  }, [list.data]);
  const review = async (approve: boolean) => {
    if (list.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    if (!sel || !detail.data) return;
    if (!approve && !notes.trim()) return toast('سبب الرفض مطلوب', 'red');
    const result = await run(() => approve ? paymentNoticesApi.approve(sel.id, { treasury_id: treasuryId ? Number(treasuryId) : null, admin_notes: notes || null }) : paymentNoticesApi.reject(sel.id, notes), { toast, success: approve ? 'تم اعتماد إشعار الدفع' : 'تم رفض الإشعار', onFieldErrors: setErrors });
    if (result) { detail.setData(result); setSelected(mapNotice(result)); setMode(null); list.reload(); treasures.reload(); }
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <Card stg={3} className="lg:col-span-5 space-y-3">
        <div className="space-y-2.5">
          <div className="op-search-box !max-w-none !w-full">
            <Icon name="search" size={15} className="tx3 shrink-0" />
            <input
              type="search"
              value={customerSearch}
              onChange={(e) => setCustomerSearch(e.target.value)}
              placeholder="ابحث عن عميل جملة (3 أحرف على الأقل)..."
              className="op-search-input"
              aria-label="بحث عن عميل"
            />
            {customerSearch && (
              <button
                type="button"
                onClick={() => setCustomerSearch('')}
                className="op-search-clear"
                aria-label="مسح البحث"
              >
                <Icon name="x" size={12} />
              </button>
            )}
          </div>
          <div className="op-filter-secondary !grid-cols-1 sm:!grid-cols-2">
            <FSelect
              value={st}
              onChange={setFilter(setSt)}
              placeholder="كل الحالات"
              options={Object.entries(NOTICE_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
            />
            <FSelect
              value={cu}
              onChange={setFilter(setCu)}
              placeholder={debouncedCustomerSearch.trim().length >= 3 ? `العملاء (${(customers.data ?? []).length})` : 'العميل'}
              options={(customers.data ?? []).map((c) => ({ value: String(c.id), label: c.company_name || c.name }))}
            />
          </div>
        </div>
        <RemoteNote remote={list} />
        <div className="space-y-2">
          {rows.length === 0 && <Empty icon="bank" title="لا توجد إشعارات" />}
          {(rows as PaymentNoticeRow[]).map((n: PaymentNoticeRow) => {
            const active = sel?.id === n.id;
            return (
              <button
                key={n.id}
                type="button"
                disabled={list.usingMock}
                onClick={() => setSel(n)}
                className={`w-full text-start p-3.5 transition flex items-start gap-3 rounded-[14px] border ${
                  active
                    ? 'bg-[var(--bg-card)] border-[var(--accent)] shadow-[0_0_0_2px_var(--accent-light)]'
                    : 'v-sub border-transparent hover:border-[var(--border-field)]'
                }`}
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
                    <span className="font-semibold text-[12.5px] num">{fmtMoney(n.amount, 0)}</span>
                  </div>
                </div>
              </button>
            );
          })}
        </div>
        <Pager total={list.data?.meta.total ?? 0} page={page} per={20} onPage={(p) => { setPage(p); setSelected(null); }} />
      </Card>
      {sel && (
        <Card stg={4} className="lg:col-span-7">
          <RemoteNote remote={detail} />
          <div className="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-display text-[18px] font-semibold num">{sel.no}</span>
                <StatusBadge map={NOTICE_STATUS} value={sel.status} />
              </div>
              <div className="text-[12px] tx2 mt-1">
                {sel.company} · {sel.cust}
              </div>
            </div>
            {sel.status === 'pending' && (can('payment_notices.approve') || can('payment_notices.reject')) && (
              <div className="flex items-center gap-2 w-full sm:w-auto">
                {can('payment_notices.reject') && (
                  <button className="btn btn-danger btn-sm flex-1 sm:flex-none min-h-[40px] sm:min-h-0 justify-center" disabled={list.usingMock} onClick={() => setMode('reject')}>
                    رفض
                  </button>
                )}
                {can('payment_notices.approve') && (
                  <button className="btn btn-dark btn-sm flex-1 sm:flex-none min-h-[40px] sm:min-h-0 justify-center" disabled={list.usingMock} onClick={() => setMode('approve')}>
                    <Icon name="check" size={13} />
                    موافقة
                  </button>
                )}
              </div>
            )}
          </div>
          <div className="v-orange rounded-[18px] p-4 flex flex-wrap items-center justify-between gap-3 mb-4">
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
              <div className="v-sub p-3 flex flex-wrap items-center gap-3">
                <div
                  className="w-14 h-16 sm:w-16 sm:h-20 rounded-[10px] bg-[var(--bg-card)] flex items-center justify-center tx3 border shrink-0"
                  style={{ borderColor: 'var(--border-subtle)' }}
                >
                  <Icon name="image" size={20} />
                </div>
                <div className="flex-1 min-w-[140px]">
                  <div className="text-[12.5px] font-medium tx1">إيصال التحويل</div>
                  <div className="text-[11px] tx3 num">{sel.no}</div>
                </div>
                <div className="flex items-center gap-2 w-full sm:w-auto">
                  <button className="btn btn-soft btn-xs flex-1 sm:flex-none min-h-[38px] sm:min-h-0 justify-center" disabled={busy || list.usingMock} onClick={() => run(() => paymentNoticesApi.openReceipt(sel.id), { toast })}>
                    <Icon name="eye" size={12} />
                    معاينة
                  </button>
                  <button className="btn btn-soft btn-xs flex-1 sm:flex-none min-h-[38px] sm:min-h-0 justify-center" disabled={busy || list.usingMock} onClick={() => run(() => paymentNoticesApi.downloadReceipt(sel.id), { toast })}>
                    <Icon name="download" size={12} />
                    تنزيل
                  </button>
                </div>
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

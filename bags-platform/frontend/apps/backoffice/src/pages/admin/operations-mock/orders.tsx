import { AdminLoading } from '../../../components/admin/AdminLoading';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ORDER_STATUS,
  ORDER_NEXT,
  PAY_STATUS,
  PAY_METHODS,
  RETURN_STATUS,
  REFUND_METHODS,
  BRANCHES,
  ORDERS,
  RETURNS,
  VARIANTS,
  SHIP_COMPANIES,
  type OrderRow,
  type ReturnRow,
} from './data';
import { adminOrdersApi, adminReturnsApi, branchesApi, shippingCompaniesApi, treasuriesApi, PAYMENT_TRANSITIONS, type AdminOrderListParams, type OrderStatus, type PaymentStatus, type ReturnStatus, type RefundMethod } from '@alfarid/api-client';
import { useRemote, useDebounced, useAction } from './api/useRemote';
import { useFormState } from './api/useFormState';
import { RemoteFeedback } from './api/RemoteFeedback';
import { mockOrder, mockReturn } from './api/mockData';
import { useCan } from '../../../hooks/useCan';
import { mapOrder, mapReturn, branchOptions, toDate, toTime, num } from './api/mappers';
import {
  fmtNum,
  fmtMoney,
  Num,
  Icon,
  Avatar,
  Badge,
  StatusBadge,
  PageHead,
  Card,
  CardHead,
  KPI,
  SearchPill,
  FSelect,
  FChip,
  FDate,
  DataTable,
  Pager,
  RowActions,
  Modal,
  Field,
  TextArea,
  Note,
  KV,
  Timeline,
  Seg,
  Empty,
  useToast,
  type ColumnDef,
} from './ui';

export function PayCell({ o }: { o: OrderRow }) {
  return (
    <div className="leading-tight">
      <div className="text-[12.5px] tx1 font-medium">{PAY_METHODS[o.pay] || o.pay || '—'}</div>
      <div className={'text-[11px] mt-0.5 font-semibold c-' + (PAY_STATUS[o.payStatus]?.tone || 'gray')}>
        {PAY_STATUS[o.payStatus]?.label || o.payStatus}
      </div>
    </div>
  );
}

export function OrderFlags({ o }: { o: OrderRow }) {
  return (
    <div className="flex flex-col gap-1 items-start">
      {o.followUp && (
        <span className="badge t-amber !py-[2px] !text-[10.5px]">
          <Icon name="flag" size={10} sw={2.4} />
          يحتاج متابعة
        </span>
      )}
      {o.needRefund && (
        <span className="badge t-red !py-[2px] !text-[10.5px]">
          <Icon name="returns" size={10} sw={2.4} />
          يتطلب استرداد مبلغ
        </span>
      )}
    </div>
  );
}

/* ---------- Orders list ---------- */
export function OrdersList({ go }: { go: (route: { m: string; id?: number | string }) => void }) {
  const [q, setQ] = useState('');
  const [st, setSt] = useState('');
  const [br, setBr] = useState('');
  const [pm, setPm] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [fu, setFu] = useState(false);
  const [rf, setRf] = useState(false);
  const toast = useToast();
  const [page, setPage] = useState(1);
  const dq = useDebounced(q);
  const setFilter = <T,>(setter: (v: T) => void) => (v: T) => { setter(v); setPage(1); };

  const params: AdminOrderListParams = {
    page,
    status: (st || undefined) as OrderStatus | undefined,
    search: dq || undefined,
    allocated_branch_id: br ? Number(br) : undefined,
    payment_method: (pm === 'bank' ? 'bank_transfer' : pm || undefined) as AdminOrderListParams['payment_method'],
    date_from: from || undefined,
    date_to: to || undefined,
    needs_attention: fu ? true : undefined,
    requires_refund: rf ? true : undefined,
  };

  const { data, loading, error, reload, usingMock } = useRemote(
    (signal) => adminOrdersApi.list(params, signal),
    [page, st, dq, br, pm, from, to, fu, rf],
    {
      enabled: q === dq,
      mock: () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: ORDERS.length, from: 1, to: ORDERS.length } } as any),
      onError: (e) => toast(e.message, 'red'),
    },
  );
  const rows = useMemo(
    () => (usingMock ? ORDERS : (data?.data ?? []).map(mapOrder)),
    [data, usingMock],
  );
  const branches = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
  const branchOpts = useMemo(() => branchOptions(branches.data ?? []), [branches.data]);
  const kpi = useRemote(async (signal) => {
    const s = await adminOrdersApi.stats(signal);
    return { pending: s.pending, processing: s.processing, follow: s.needs_attention, refund: s.requires_refund };
  }, []);

  const anyFilter = q || st || br || pm || from || to || fu || rf;
  const reset = () => {
    setQ('');
    setSt('');
    setBr('');
    setPm('');
    setFrom('');
    setTo('');
    setFu(false);
    setRf(false);
  };

  const cols: ColumnDef<OrderRow>[] = [
    {
      h: 'الطلب',
      r: (o) => (
        <div className="leading-tight">
          <div className="num font-semibold tx1 text-[13px]">{o.no}</div>
          <div className="text-[11px] tx3 mt-1 whitespace-nowrap">{o.channel}</div>
        </div>
      ),
    },
    {
      h: 'العميل',
      r: (o) => (
        <div className="flex items-center gap-2.5 min-w-0 max-w-[240px]">
          <Avatar name={o.customer} tone={o.id} size={32} />
          <div className="leading-tight min-w-0 flex-1">
            <div className="font-semibold tx1 flex items-center gap-1.5 truncate">
              <span className="truncate">{o.customer}</span>
              {o.guest && <span className="badge t-gray !py-0 !px-1.5 !text-[10px] shrink-0">زائر</span>}
            </div>
            <div className="text-[11px] tx3 num ltr mt-0.5">{o.phone}</div>
          </div>
        </div>
      ),
    },
    {
      h: 'الحالة',
      r: (o) => (
        <div className="flex flex-col items-start gap-1.5">
          <StatusBadge map={ORDER_STATUS} value={o.status} />
          {(o.followUp || o.needRefund) && <OrderFlags o={o} />}
        </div>
      ),
    },
    { h: 'الدفع', r: (o) => <PayCell o={o} /> },
    {
      h: 'الفرع',
      r: (o) =>
        o.branch ? (
          <span className="tx2 whitespace-nowrap text-[12.5px]">{o.branch}</span>
        ) : (
          <span className="tx3 text-[11.5px]">غير مخصص</span>
        ),
    },
    {
      h: 'الإجمالي',
      r: (o) => <span className="font-semibold tx1 whitespace-nowrap num text-[13px]">{fmtMoney(o.total)}</span>,
    },
    {
      h: 'التاريخ',
      r: (o) => (
        <div className="leading-tight tx2 whitespace-nowrap">
          <Num>{o.date}</Num>
          <div className="text-[11px] tx3 num mt-0.5">{o.time}</div>
        </div>
      ),
    },
    {
      h: '',
      align: 'left',
      r: (o) => (
        <RowActions>
          <button
            type="button"
            className="btn btn-soft btn-xs inline-flex items-center gap-1"
            onClick={() => go({ m: 'orders', id: o.id })}
          >
            <span>التفاصيل</span>
            <Icon name="chevL" size={12} />
          </button>
        </RowActions>
      ),
    },
  ];

  const totalCount = data?.meta.total ?? rows.length;

  return (
    <div className="space-y-5">
      <RemoteFeedback state={{ loading: false, error: null, usingMock, reload }} />
      <PageHead
        title="الطلبات"
        sub="متابعة طلبات كل القنوات، وتحديث حالتها ودفعها وتخصيص مخزونها."
        actions={
          <span className="v-pill px-3.5 py-1.5 text-[12px] font-medium tx2 inline-flex items-center gap-2">
            <span>إجمالي النتائج</span>
            <strong className="tx1 num font-bold">{fmtNum(totalCount)}</strong>
          </span>
        }
      />
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5 stg stg-2">
        <button
          type="button"
          onClick={() => setFilter(setSt)(st === 'pending' ? '' : 'pending')}
          className={`op-kpi-btn is-hero ${st === 'pending' ? 'is-active' : ''}`}
          aria-pressed={st === 'pending'}
        >
          <KPI
            hero
            label="طلبات قيد المراجعة"
            icon="clock"
            value={kpi.data?.pending ?? '—'}
            foot="تحتاج تأكيدًا أو إلغاء"
          />
        </button>
        <button
          type="button"
          onClick={() => setFilter(setFu)(!fu)}
          className={`op-kpi-btn ${fu ? 'is-active' : ''}`}
          aria-pressed={fu}
        >
          <div className="rounded-[18px] p-4 flex flex-col justify-between min-h-[118px] min-w-0 overflow-hidden">
            <div className="flex items-center justify-between gap-1">
              <span className="text-[12.5px] font-medium tx2 truncate">يحتاج متابعة</span>
              <span className="v-well w-7 h-7 rounded-full flex items-center justify-center shrink-0">
                <Icon name="flag" size={14} />
              </span>
            </div>
            <div className="font-display font-bold tracking-tight my-2.5 tx1 num tabular-nums truncate text-[24px] sm:text-[28px]">
              {kpi.data?.follow ?? '—'}
            </div>
            <div className="text-[11px] tx3 truncate">معلَّمة للمتابعة</div>
          </div>
        </button>
        <button
          type="button"
          onClick={() => setFilter(setRf)(!rf)}
          className={`op-kpi-btn ${rf ? 'is-active' : ''}`}
          aria-pressed={rf}
        >
          <div className="rounded-[18px] p-4 flex flex-col justify-between min-h-[118px] min-w-0 overflow-hidden">
            <div className="flex items-center justify-between gap-1">
              <span className="text-[12.5px] font-medium tx2 truncate">يتطلب استرداد مبلغ</span>
              <span className="v-well w-7 h-7 rounded-full flex items-center justify-center shrink-0">
                <Icon name="returns" size={14} />
              </span>
            </div>
            <div className="font-display font-bold tracking-tight my-2.5 tx1 num tabular-nums truncate text-[24px] sm:text-[28px]">
              {kpi.data?.refund ?? '—'}
            </div>
            <div className="text-[11px] tx3 truncate">ملغاة أو مرتجعة ومدفوعة</div>
          </div>
        </button>
        <button
          type="button"
          onClick={() => setFilter(setSt)(st === 'processing' ? '' : 'processing')}
          className={`op-kpi-btn ${st === 'processing' ? 'is-active' : ''}`}
          aria-pressed={st === 'processing'}
        >
          <div className="rounded-[18px] p-4 flex flex-col justify-between min-h-[118px] min-w-0 overflow-hidden">
            <div className="flex items-center justify-between gap-1">
              <span className="text-[12.5px] font-medium tx2 truncate">قيد التجهيز</span>
              <span className="v-well w-7 h-7 rounded-full flex items-center justify-center shrink-0">
                <Icon name="store" size={14} />
              </span>
            </div>
            <div className="font-display font-bold tracking-tight my-2.5 tx1 num tabular-nums truncate text-[24px] sm:text-[28px]">
              {kpi.data?.processing ?? '—'}
            </div>
            <div className="text-[11px] tx3 truncate">بانتظار تخصيص المخزون</div>
          </div>
        </button>
      </div>
      <Card stg={3}>
        <div className="op-filter-shell">
          <div className="op-filter-primary">
            <div className="op-search-box">
              <Icon name="search" size={15} className="tx3 shrink-0" />
              <input
                type="search"
                value={q}
                onChange={(e) => setFilter(setQ)(e.target.value)}
                placeholder="ابحث برقم الطلب، اسم العميل، أو الهاتف..."
                className="op-search-input"
                aria-label="بحث في الطلبات"
              />
              {q && (
                <button
                  type="button"
                  onClick={() => setFilter(setQ)('')}
                  className="op-search-clear"
                  aria-label="مسح البحث"
                >
                  <Icon name="x" size={12} />
                </button>
              )}
            </div>
            <div className="op-filter-chips">
              <FChip on={fu} onClick={() => setFilter(setFu)(!fu)} icon="flag">
                يحتاج متابعة
              </FChip>
              <FChip on={rf} onClick={() => setFilter(setRf)(!rf)} icon="returns">
                يتطلب استرداد
              </FChip>
              {anyFilter && (
                <button type="button" className="btn btn-ghost btn-xs col-span-2 sm:col-span-1 justify-center" onClick={reset}>
                  <Icon name="x" size={12} />
                  مسح الفلاتر
                </button>
              )}
            </div>
          </div>
          <div className="op-filter-secondary">
            <FSelect
              value={st}
              onChange={setFilter(setSt)}
              placeholder="كل الحالات"
              options={Object.entries(ORDER_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
            />
            <FSelect value={br} onChange={setFilter(setBr)} placeholder="كل الفروع" options={branchOpts.length ? branchOpts : []} />
            <FSelect
              value={pm}
              onChange={setFilter(setPm)}
              placeholder="طريقة الدفع"
              options={Object.entries(PAY_METHODS).map(([v, l]) => ({ value: v, label: l }))}
            />
            <FDate label="من" value={from} onChange={setFilter(setFrom)} />
            <FDate label="إلى" value={to} onChange={setFilter(setTo)} />
          </div>
        </div>
        <div className="hidden md:block op-orders-table-wrap">
          <DataTable
            rows={loading ? [] : rows}
            empty={loading ? <AdminLoading compact /> : error && !usingMock
              ? <Empty icon="alert" title="تعذّر تحميل الطلبات" text={error.message}
                  action={<button className="btn btn-soft btn-sm" onClick={reload}>إعادة المحاولة</button>} />
              : undefined}
            onRow={(o: OrderRow) => go({ m: 'orders', id: o.id })}
            cols={cols}
          />
        </div>
        <div className="md:hidden">
          {loading ? (
            <div className="py-8 flex justify-center"><AdminLoading compact /></div>
          ) : error && !usingMock ? (
            <div className="py-6">
              <Empty
                icon="alert"
                title="تعذّر تحميل الطلبات"
                text={error.message}
                action={<button className="btn btn-soft btn-sm" onClick={reload}>إعادة المحاولة</button>}
              />
            </div>
          ) : rows.length === 0 ? (
            <div className="py-10 text-center tx3 text-[13px]">لا توجد بيانات مطابقة</div>
          ) : (
            <div className="op-order-cards">
              {rows.map((o: OrderRow) => (
                <button
                  key={o.id}
                  type="button"
                  onClick={() => go({ m: 'orders', id: o.id })}
                  className="op-order-card"
                >
                  <div className="op-order-card-head">
                    <div className="flex items-center gap-2 min-w-0">
                      <span className="num font-bold tx1 text-[13.5px]">{o.no}</span>
                      <span className="v-card px-2 py-0.5 rounded-full text-[10.5px] tx2 truncate">{o.channel}</span>
                    </div>
                    <StatusBadge map={ORDER_STATUS} value={o.status} />
                  </div>

                  <div className="op-order-card-customer">
                    <div className="flex items-center gap-2.5 min-w-0 flex-1">
                      <Avatar name={o.customer} tone={o.id} size={34} />
                      <div className="min-w-0 flex-1 leading-tight">
                        <div className="font-semibold tx1 text-[13.5px] flex items-center gap-1.5">
                          <span className="truncate">{o.customer}</span>
                          {o.guest && <span className="badge t-gray !py-0 !px-1.5 !text-[10px] shrink-0">زائر</span>}
                        </div>
                        <div className="text-[11.5px] tx3 num ltr mt-0.5 text-right">{o.phone || '—'}</div>
                      </div>
                    </div>
                    <div className="text-end shrink-0">
                      <div className="font-display font-bold tx1 text-[15px] num whitespace-nowrap">{fmtMoney(o.total)}</div>
                    </div>
                  </div>

                  <div className="op-order-card-meta">
                    <div className="min-w-0">
                      <div className="text-[10.5px] tx3 mb-0.5">الدفع</div>
                      <PayCell o={o} />
                    </div>
                    <div className="min-w-0">
                      <div className="text-[10.5px] tx3 mb-0.5">الفرع والتاريخ</div>
                      <div className="text-[12px] tx1 font-medium truncate">{o.branch || 'غير مخصص'}</div>
                      <div className="text-[11px] tx3 num mt-0.5">{o.date} · {o.time}</div>
                    </div>
                  </div>

                  <div className="op-order-card-foot">
                    <div className="min-w-0">
                      {(o.followUp || o.needRefund) && <OrderFlags o={o} />}
                    </div>
                    <span className="btn btn-soft btn-xs inline-flex items-center gap-1 shrink-0">
                      <span>التفاصيل</span>
                      <Icon name="chevL" size={12} />
                    </span>
                  </div>
                </button>
              ))}
            </div>
          )}
        </div>
        <Pager total={totalCount} per={data?.meta.per_page ?? 20} page={page} onPage={setPage} />
      </Card>
    </div>
  );
}

/* ---------- Order detail ---------- */
export function OrderDetail({
  id,
  go,
}: {
  id: number | string;
  go: (route: { m: string; id?: number | string }) => void;
}) {
  const toast = useToast();
  const mayUpdate = useCan('orders.update');
  const mayPay = useCan('orders.payment');
  const mayAllocate = useCan('orders.allocate');
  const { busy, run } = useAction();
  const numId = Number(id);
  const validId = Number.isSafeInteger(numId) && numId > 0;
  const remote = useRemote(
    (s) => adminOrdersApi.show(numId, s), [numId], { enabled: validId, mock: () => mockOrder(numId), onError: (e) => toast(e.message, 'red') },
  );
  const { data: order, loading, setData: setOrder } = remote;
  const shipCos = useRemote((s) => shippingCompaniesApi.list({ is_active: true }, s).then((p) => p.data), []);
  const branchesList = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
  const [statusNotes, setStatusNotes] = useState('');
  const [payNotes, setPayNotes] = useState('');
  const [refundRef, setRefundRef] = useState('');
  const [modal, setModal] = useState<string | null>(null);
  const [newSt, setNewSt] = useState('');
  const [newPay, setNewPay] = useState('');
  const [allocBranch, setAllocBranch] = useState('');
  const [carrier, setCarrier] = useState('');
  const [awb, setAwb] = useState('');
  useEffect(() => { setNewPay(''); }, [order?.id, order?.payment_status]);

  if (!validId) return <Empty title="معرّف الطلب غير صالح" />;
  if (loading || !order) return <><RemoteFeedback state={remote} />{!loading && !remote.error && <Empty title="الطلب غير موجود" />}</>;
  const o = mapOrder(order);

  const saveStatus = async (status: OrderStatus, notes: string) => {
    if (remote.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => adminOrdersApi.updateStatus(order.id, { status: status as any, notes: notes || undefined }),
      { toast, success: 'تم تحديث حالة الطلب' });
    if (r) { setOrder(r); setModal(null); }
  };

  const savePayment = async (payment_status: PaymentStatus, notes: string) => {
    if (remote.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    if (!notes.trim()) return toast('الملاحظات إلزامية للتعديل اليدوي على الدفع', 'red');
    if (payment_status === 'refunded' && !refundRef.trim()) return toast('مرجع الاسترداد إلزامي عند التحويل إلى مسترد', 'red');
    const r = await run(() => adminOrdersApi.updatePayment(order.id, {
      payment_status,
      notes,
      ...(payment_status === 'refunded' ? { refund_reference: refundRef.trim() } : {}),
    }),
      { toast, success: 'تم تحديث حالة الدفع' });
    if (r) { setOrder(r); setModal(null); setRefundRef(''); }
  };

  const saveAlloc = async (branchId: string) => {
    if (remote.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => adminOrdersApi.allocate(order.id, branchId ? Number(branchId) : null),
      { toast, success: 'تم تخصيص الطلب' });
    if (r) { setOrder(r); setModal(null); }
  };
  const deAlloc = async () => {
    if (remote.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => adminOrdersApi.deallocate(order.id), { toast, success: 'تم إلغاء التخصيص' });
    if (r) setOrder(r);
  };
  const saveShipment = async (companyId: string, awbNum: string) => {
    if (remote.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => adminOrdersApi.createShipment(order.id, { shipping_company_id: Number(companyId), awb_number: awbNum || null }),
      { toast, success: 'تم إنشاء الشحنة' });
    if (r) { setModal(null); remote.reload(); }
  };

  const items = (order.items ?? []).map((it) => ({
    v: { name: it.product_name, sku: it.sku, price: num(it.unit_price) },
    qty: it.qty,
    disc: num(it.discount_amount),
    tax: num(it.tax_amount),
    branch: o.branch,
    line: num(it.line_total),
  }));
  const displayItems = items;
  const lineTotal = (it: any) => it.line;
  const subtotal = order.subtotal != null ? num(order.subtotal) : displayItems.reduce((s: number, it: any) => s + (it.v.price ?? 0) * it.qty, 0);
  const disc = order.discount_amount != null ? num(order.discount_amount) : displayItems.reduce((s: number, it: any) => s + (it.disc ?? 0), 0);
  const ship = order.shipping_cost != null ? num(order.shipping_cost) : 0;
  const codFee = num(order.cod_fee);
  const tax = order.tax_amount != null ? num(order.tax_amount) : 0;
  const total = order.grand_total != null ? num(order.grand_total) : subtotal - disc + ship + codFee + tax;
  const statusOpts = (ORDER_NEXT[o.status] || []).filter((s) => s !== 'shipped');
  const next = ORDER_NEXT[o.status] || [];
  const canShip = next.includes('shipped');
  const terminal = next.length === 0;
  const canAllocate = ['confirmed', 'processing'].includes(order.status);

  function orderHistoryActor(type: string | null, id: number | null) {
    const kind = type?.split(/[\\/]/).pop()?.toLowerCase();
    const label = kind === 'user' || kind === 'staff' || kind === 'admin' ? 'موظف'
      : kind === 'customer' ? 'عميل'
      : kind === 'supplieruser' || kind === 'supplier' ? 'مورد'
      : kind == null || kind === 'system' ? 'النظام' : 'مستخدم';
    return id == null ? label : `${label} #${id}`;
  }

  const timeline = (order.status_history ?? []).map((h) => ({
    title: (ORDER_STATUS as any)[h.to_status]?.label ?? h.to_status,
    date: `${toDate(h.at)} ${toTime(h.at)}`,
    note: h.notes ?? undefined,
    by: orderHistoryActor(h.changed_by_type, h.changed_by_id),
    from: h.from_status,
    to: h.to_status,
  }));
  const history = timeline.map(t => ({ from: t.from, to: t.to, note: t.note, by: t.by ?? '', at: t.date }));

  return (
    <div className="space-y-5">
      <RemoteFeedback state={remote} />
      <PageHead
        back={{ label: 'كل الطلبات', onClick: () => go({ m: 'orders' }) }}
        title={
          <span className="flex items-center gap-3 flex-wrap">
            <span className="num">{o.no}</span>
            <StatusBadge map={ORDER_STATUS} value={o.status} />
          </span>
        }
        sub={
          <span>
            أُنشئ <Num>{o.date} · {o.time}</Num> عبر {o.channel}
          </span>
        }
        actions={
          <>
            <button
              className="btn btn-soft"
              disabled={!mayUpdate || remote.usingMock || terminal}
              onClick={() => {
                setNewSt('');
                setModal('status');
              }}
            >
              <Icon name="refresh" size={14} />
              تغيير الحالة
            </button>
            <button className="btn btn-soft" disabled={!mayPay || remote.usingMock} onClick={() => { setNewPay(''); setModal('pay'); }}>
              <Icon name="card" size={14} />
              تحديث الدفع
            </button>
            {o.branch ? (
              <button
                className="btn btn-soft"
                disabled={!mayAllocate || remote.usingMock || busy || !canAllocate}
                onClick={deAlloc}
              >
                <Icon name="layers" size={14} />
                فك التخصيص
              </button>
            ) : (
              <button className="btn btn-soft" disabled={!mayAllocate || remote.usingMock || busy || !canAllocate} onClick={() => setModal('alloc')}>
                <Icon name="layers" size={14} />
                تخصيص المخزون
              </button>
            )}
            <button
              className="btn btn-dark"
              disabled={!mayUpdate || remote.usingMock || busy || !canShip}
              onClick={() => setModal('ship')}
            >
              <Icon name="truck" size={14} />
              إنشاء شحنة
            </button>
          </>
        }
      />

      {(o.followUp || o.needRefund) && (
        <div className="flex flex-wrap gap-2 stg stg-2">
          {o.followUp && (
            <Note tone="amber" icon="flag">
              هذا الطلب معلَّم <b>«يحتاج متابعة»</b>.
            </Note>
          )}
          {o.needRefund && (
            <Note tone="red" icon="returns">
              هذا الطلب <b>«يتطلب استرداد مبلغ»</b> — أنشئ مرتجعًا أو حدّث حالة الدفع إلى مسترد.
            </Note>
          )}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <div className="lg:col-span-8 space-y-5">
          <Card stg={2}>
            <CardHead
              title="المنتجات"
              sub="بيانات المنتج كما كانت وقت الطلب"
              right={
                <span className="text-[11.5px] tx3">
                  <Num>{items.length}</Num> بنود
                </span>
              }
            />
            <DataTable
              compact
              cols={[
                {
                  h: 'المنتج',
                  r: (it) => (
                    <div className="flex items-center gap-2.5 min-w-0 max-w-[260px]">
                      <span className="w-9 h-9 rounded-[10px] v-sub flex items-center justify-center tx3">
                        <Icon name="box" size={15} />
                      </span>
                      <div className="leading-tight min-w-0 flex-1">
                        <div className="font-semibold tx1">{it.v.name}</div>
                        <div className="text-[11px] tx3 num ltr mt-0.5">{it.v.sku}</div>
                      </div>
                    </div>
                  ),
                },
                { h: 'الكمية', align: 'center', r: (it) => <Num className="font-semibold">{it.qty}</Num> },
                { h: 'سعر الوحدة', r: (it) => fmtMoney(it.v.price) },
                {
                  h: 'الخصم',
                  r: (it) =>
                    it.disc ? <span className="c-red">{fmtMoney(-it.disc)}</span> : <span className="tx3">—</span>,
                },
                { h: 'الضريبة', r: (it) => <span className="tx2">{fmtMoney(it.tax)}</span> },
                { h: 'إجمالي البند', r: (it) => <span className="font-semibold">{fmtMoney(lineTotal(it))}</span> },
                {
                  h: 'فرع البند',
                  r: (it) =>
                    it.branch ? (
                      <span className="text-[11.5px] tx2">{it.branch}</span>
                    ) : (
                      <span className="text-[11.5px] tx3">غير مخصص</span>
                    ),
                },
              ]}
              rows={displayItems}
            />
          </Card>

          <Card stg={3}>
            <CardHead title="سجل التغييرات" sub="كل تغيير في حالة الطلب ومن قام به" />
            <Timeline
              items={history.map((h) => ({
                icon: h.to === 'pending' ? 'plus' : 'refresh',
                body: (
                  <div>
                    <div className="flex items-center gap-2 flex-wrap">
                      {h.from && (
                        <>
                          <StatusBadge map={ORDER_STATUS} value={h.from} />
                          <Icon name="arrowL" size={13} className="tx3" />
                        </>
                      )}
                      <StatusBadge map={ORDER_STATUS} value={h.to} />
                    </div>
                    {h.note && <div className="text-[12.5px] tx1 mt-2">{h.note}</div>}
                    <div className="text-[11px] tx3 mt-1 flex items-center gap-2">
                      <span>{h.by}</span>
                      <span>·</span>
                      <Num>{h.at}</Num>
                    </div>
                  </div>
                ),
              }))}
            />
          </Card>
        </div>

        <div className="lg:col-span-4 space-y-5">
          <div className="v-card p-4 stg stg-2">
            <div className="v-orange rounded-[18px] p-4">
              <div className="flex items-center justify-between text-[12.5px] text-white/90">
                <span>إجمالي الطلب</span>
                <span className="px-2 py-0.5 rounded-full text-[10.5px] font-semibold bg-white/20">
                  {PAY_METHODS[o.pay]}
                </span>
              </div>
              <div className="font-display text-[30px] font-bold num mt-2 mb-1 ltr">
                {fmtNum(total)} <span className="text-[14px] opacity-80">EGP</span>
              </div>
              <div className="text-[11.5px] text-white/85">
                حالة الدفع: <b>{PAY_STATUS[o.payStatus]?.label || o.payStatus}</b>
              </div>
            </div>
            <div className="px-1 pt-4">
              <KV
                items={[
                  ['المجموع الفرعي', fmtMoney(subtotal)],
                  ['الخصم', <span className="c-red">{fmtMoney(-disc)}</span>],
                  [
                    'كود الكوبون',
                    <span className="badge t-orange num !text-[11px]" style={{ letterSpacing: '.04em' }}>
                      <Icon name="ticket" size={11} />
                      {order.coupon_code ?? '—'}
                    </span>,
                  ],
                  ['الشحن', fmtMoney(ship)],
                  ['رسوم الدفع عند الاستلام', fmtMoney(codFee)],
                  ['الضريبة', fmtMoney(tax)],
                ]}
              />
              <div className="flex items-center justify-between v-bt mt-3 pt-3">
                <span className="font-semibold tx1">الإجمالي</span>
                <span className="font-display font-bold text-[16px]">{fmtMoney(total)}</span>
              </div>
            </div>
          </div>

          <Card stg={3}>
            <CardHead title="العميل" className="mb-3" />
            <div className="flex items-center gap-3 mb-4">
              <Avatar name={o.customer} tone={o.id} size={40} />
              <div>
                <div className="font-semibold tx1">{o.customer}</div>
                <div className="text-[11.5px] tx3 num ltr">{o.phone}</div>
              </div>
            </div>
            <KV
              items={[
                [
                  'نوع العميل',
                  <Badge tone={o.guest ? 'gray' : o.ctype === 'جملة' ? 'violet' : 'blue'} dot={false}>
                    {o.ctype}
                  </Badge>,
                ],
                o.guest && [
                  'بيانات الزائر',
                  <span className="text-[12px]">
                    بدون حساب · <span className="num">{order.guest_email ?? '—'}</span>
                  </span>,
                ],
              ]}
            />
          </Card>

          <Card stg={4}>
            <CardHead title="التوصيل" className="mb-3" />
            <KV
              items={[
                ['المدينة', o.city],
                [
                  'العنوان',
                  <span className="text-[12px] leading-relaxed">
                    {order.shipping_address_line ?? '—'}
                  </span>,
                ],
              ]}
            />
            <div className="v-sub px-3.5 py-2.5 mt-3 text-[12px] tx2 flex gap-2">
              <Icon name="info" size={14} style={{ marginTop: 2 }} />
              {order.shipping_notes ?? '—'}
            </div>
          </Card>

          <Card stg={5}>
            <CardHead title="التنفيذ" className="mb-3" />
            <KV
              items={[
                ['حالة الطلب', <StatusBadge map={ORDER_STATUS} value={o.status} />],
                ['الفرع المخصص', o.branch || <span className="tx3">غير مخصص</span>],
              ]}
            />
            {!terminal && (
              <div className="mt-4">
                <div className="lbl mb-2">الانتقالات المتاحة</div>
                <div className="flex flex-wrap gap-1.5">
                  {next.map((n) => (
                    <StatusBadge key={n} map={ORDER_STATUS} value={n} />
                  ))}
                </div>
              </div>
            )}
          </Card>
        </div>
      </div>

      {/* status modal */}
      <Modal
        open={modal === 'status'}
        onClose={() => setModal(null)}
        title="تغيير حالة الطلب"
        icon="refresh"
        sub="تظهر فقط الحالات المسموح الانتقال إليها من الحالة الحالية."
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={!newSt || busy}
              onClick={() => saveStatus(newSt as OrderStatus, statusNotes)}
            >
              حفظ الحالة
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="v-sub p-3 flex items-center gap-2 text-[12px]">
            <span className="tx2">الحالة الحالية</span>
            <StatusBadge map={ORDER_STATUS} value={o.status} />
          </div>
          <Field label="الحالة الجديدة" req>
            <div className="grid grid-cols-2 gap-2">
              {statusOpts.map((n) => (
                <button
                  key={n}
                  type="button"
                  onClick={() => setNewSt(n)}
                  className="v-sub p-3 flex items-center justify-between text-[12.5px] font-medium transition"
                  style={
                    newSt === n ? { outline: '2px solid var(--accent)', background: 'var(--bg-card)' } : {}
                  }
                >
                  <StatusBadge map={ORDER_STATUS} value={n} />
                  {newSt === n && <Icon name="check" size={15} className="accent" />}
                </button>
              ))}
            </div>
          </Field>
          {newSt === 'shipped' && (
            <Note tone="amber" icon="alert">
              قد ترفض العملية إن لم توجد شحنة أو لم يُخصص المخزون — يُفضّل استخدام «إنشاء شحنة».
            </Note>
          )}
          <TextArea label="ملاحظات" max={500} placeholder="اختياري" value={statusNotes} onChange={setStatusNotes} />
        </div>
      </Modal>

      {/* payment modal */}
      <Modal
        open={modal === 'pay'}
        onClose={() => setModal(null)}
        title="تحديث حالة الدفع"
        icon="card"
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={busy || !newPay || newPay === order.payment_status}
              onClick={() => savePayment(newPay as PaymentStatus, payNotes)}
            >
              حفظ
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="حالة الدفع" req>
            <div className="grid grid-cols-2 gap-2">
              {(PAYMENT_TRANSITIONS[order.payment_status as PaymentStatus] ?? Object.keys(PAY_STATUS)).map((k) => {
                const s = (PAY_STATUS as any)[k] ?? { label: k, tone: 'gray' };
                return (
                <button
                  key={k}
                  type="button"
                  onClick={() => setNewPay(k)}
                  className="v-sub p-3 flex items-center justify-between transition"
                  style={
                    newPay === k ? { outline: '2px solid var(--accent)', background: 'var(--bg-card)' } : {}
                  }
                >
                  <span className="flex items-center gap-2 text-[12.5px] font-medium tx1">
                    <span className={'w-2 h-2 rounded-full t-' + s.tone} style={{ background: 'currentColor' }} />
                    {s.label}
                    <span className="text-[10.5px] tx3 num">{k}</span>
                  </span>
                  {newPay === k && <Icon name="check" size={15} className="accent" />}
                </button>
                );
              })}
            </div>
          </Field>
          <Note icon="info">يُسجَّل تغيير الحالة فقط — لا يدعم النظام إدخال مبلغ دفعة جزئية.</Note>
          <TextArea label="ملاحظات" max={500} placeholder="إلزامية للتعديل اليدوي" value={payNotes} onChange={setPayNotes} />
          {newPay === 'refunded' && (
            <Field label="مرجع الاسترداد" req>
              <input className="inp" value={refundRef} onChange={(e) => setRefundRef(e.target.value)} placeholder="رقم مرجع الاسترداد الفعلي" maxLength={191} />
            </Field>
          )}
        </div>
      </Modal>

      {/* allocate modal */}
      <Modal
        open={modal === 'alloc'}
        onClose={() => setModal(null)}
        title="تخصيص المخزون"
        icon="layers"
        width={460}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={busy}
              onClick={() => saveAlloc(allocBranch)}
            >
              {allocBranch ? 'تخصيص' : 'تخصيص تلقائي'}
            </button>
          </>
        }
      >
        <div className="space-y-4 pb-2">
          <Field label="الفرع" hint="اترك الحقل فارغًا ليختار النظام الفرع المناسب تلقائيًا.">
            <select className="inp" value={allocBranch} onChange={(e) => setAllocBranch(e.target.value)}>
              <option value="">— تخصيص تلقائي —</option>
              {(branchesList.data ?? []).map((b: { id: number; name: string }) => (
                <option key={b.id} value={String(b.id)}>{b.name}</option>
              ))}
            </select>
          </Field>
        </div>
      </Modal>

      {/* shipping modal */}
      <Modal
        open={modal === 'ship'}
        onClose={() => setModal(null)}
        title="إنشاء شحنة"
        icon="truck"
        width={520}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={!carrier || busy}
              onClick={() => saveShipment(carrier, awb)}
            >
              إنشاء الشحنة
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="شركة الشحن" req>
            <select className="inp" value={carrier} onChange={(e) => setCarrier(e.target.value)}>
              <option value="">اختر الشركة</option>
              {(shipCos.data ?? []).map((c: { id: number; name: string }) => (
                <option key={c.id} value={String(c.id)}>
                  {c.name}
                </option>
              ))}
            </select>
          </Field>
          <Field
            label="رقم البوليصة (AWB)"
            count={`${awb.length} / 100`}
            hint="اختياري — يمكن تركه إن كانت الشركة تُصدره تلقائيًا."
          >
            <input
              className="inp num"
              dir="ltr"
              maxLength={100}
              value={awb}
              onChange={(e) => setAwb(e.target.value)}
              placeholder="e.g. 7731 4402 9918"
            />
          </Field>
          {!o.branch && (
            <Note tone="amber" icon="alert">
              لم يتم تخصيص مخزون لهذا الطلب بعد — قد يرفض النظام إنشاء الشحنة.
            </Note>
          )}
        </div>
      </Modal>
    </div>
  );
}

/* ================= RETURNS ================= */
export function ReturnsList({ go }: { go: (route: { m: string; id?: number | string }) => void }) {
  const [st, setSt] = useState('');
  const [q, setQ] = useState('');
  const [create, setCreate] = useState(false);
  const toast = useToast();
  const mayCreate = useCan('returns.create');
  const [page, setPage] = useState(1);
  const dq = useDebounced(q);
  const { data, loading, error, reload, usingMock } = useRemote(
    (signal) => adminReturnsApi.list({ page, search: dq || undefined, status: (st === '' ? undefined : st) as ReturnStatus | undefined }, signal),
    [page, dq, st],
    {
      mock: () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: RETURNS.length, from: 1, to: RETURNS.length } } as any),
      onError: (e) => toast(e.message, 'red'),
    },
  );
  const branches = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
  const branchMap = useMemo(() => new Map((branches.data ?? []).map((b: { id: number; name: string }) => [b.id, b.name] as const)), [branches.data]);
  const rows = useMemo(
    () => (usingMock ? RETURNS.filter((r) => (!st || r.status === st) && (!dq || (r.no + r.order + r.customer).includes(dq))) : (data?.data ?? []).map((r: any) => mapReturn(r, (id: number | null) => (id == null ? null : branchMap.get(id) ?? `#${id}`)))),
    [data, usingMock, st, dq, branchMap],
  );
  const counts = useRemote(async (signal) => {
    const s = await adminReturnsApi.stats(signal);
    return { total: s.total, byStatus: s as unknown as Record<string, number> };
  }, []);

  const totalCount = data?.meta.total ?? rows.length;

  return (
    <div className="space-y-5">
      <RemoteFeedback state={{ loading: false, error: null, usingMock, reload }} />
      <PageHead
        title="المرتجعات"
        sub="الموافقة والاستلام ورد المبلغ تتم كخطوات منفصلة لكل مرتجع."
        actions={
          <button type="button" className="btn btn-dark" disabled={!mayCreate || usingMock} onClick={() => setCreate(true)}>
            <Icon name="plus" size={14} />
            إنشاء مرتجع
          </button>
        }
      />
      <div className="op-kpi-strip stg stg-2">
        <button
          type="button"
          onClick={() => { setSt(''); setPage(1); }}
          aria-pressed={!st}
          className={`op-kpi-btn p-3.5 sm:p-4 flex flex-col justify-between min-h-[82px] sm:min-h-[96px] ${!st ? 'is-active' : ''}`}
        >
          <div className="text-[12px] font-medium tx2">الكل</div>
          <div className="font-display text-[21px] sm:text-[24px] font-bold num tabular-nums tx1 mt-1.5 sm:mt-2">
            {counts.data?.total ?? '—'}
          </div>
        </button>
        {Object.entries(RETURN_STATUS).map(([k, s]) => (
          <button
            key={k}
            type="button"
            onClick={() => { setSt(st === k ? '' : k); setPage(1); }}
            aria-pressed={st === k}
            className={`op-kpi-btn p-3.5 sm:p-4 flex flex-col justify-between min-h-[82px] sm:min-h-[96px] ${st === k ? 'is-active' : ''}`}
          >
            <div className={'text-[12px] font-semibold truncate c-' + s.tone}>{s.label}</div>
            <div className="font-display text-[21px] sm:text-[24px] font-bold num tabular-nums tx1 mt-1.5 sm:mt-2">
              {counts.data?.byStatus[k] ?? '—'}
            </div>
          </button>
        ))}
      </div>
      <Card stg={3}>
        <div className="op-filter-shell">
          <div className="op-filter-primary">
            <div className="op-search-box">
              <Icon name="search" size={15} className="tx3 shrink-0" />
              <input
                type="search"
                value={q}
                onChange={(e) => { setQ(e.target.value); setPage(1); }}
                placeholder="ابحث برقم المرتجع، رقم الطلب، أو اسم العميل..."
                className="op-search-input"
                aria-label="بحث في المرتجعات"
              />
              {q && (
                <button
                  type="button"
                  onClick={() => { setQ(''); setPage(1); }}
                  className="op-search-clear"
                  aria-label="مسح البحث"
                >
                  <Icon name="x" size={12} />
                </button>
              )}
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {(st || q) && (
                <button
                  type="button"
                  className="btn btn-ghost btn-xs"
                  onClick={() => { setSt(''); setQ(''); setPage(1); }}
                >
                  <Icon name="x" size={12} />
                  مسح الفلاتر
                </button>
              )}
              <span className="v-sub px-3.5 py-1.5 rounded-full text-[12px] font-medium tx2 inline-flex items-center gap-2">
                <span>إجمالي النتائج</span>
                <strong className="tx1 num font-bold">{fmtNum(totalCount)}</strong>
              </span>
            </div>
          </div>
        </div>
        <div className="hidden md:block op-orders-table-wrap">
          <DataTable
            onRow={(r: ReturnRow) => go({ m: 'returns', id: r.id })}
            rows={loading ? [] : rows}
            empty={loading ? <AdminLoading compact /> : error && !usingMock
              ? <Empty icon="alert" title="تعذّر تحميل المرتجعات" text={error.message}
                  action={<button className="btn btn-soft btn-sm" onClick={reload}>إعادة المحاولة</button>} />
              : undefined}
            cols={[
              { h: 'رقم المرتجع', r: (r) => <span className="num font-semibold tx1 text-[13px]">{r.no}</span> },
              {
                h: 'الطلب الأصلي',
                r: (r) => (
                  <span className="num tx2 underline decoration-dotted underline-offset-4">{r.order}</span>
                ),
              },
              {
                h: 'العميل',
                r: (r) => (
                  <div className="flex items-center gap-2.5 min-w-0">
                    <Avatar name={r.customer} tone={r.id} size={32} />
                    <span className="font-semibold tx1 truncate">{r.customer}</span>
                  </div>
                ),
              },
              {
                h: 'الفرع',
                r: (r) =>
                  r.branch ? <span className="tx2 whitespace-nowrap">{r.branch}</span> : <span className="tx3 text-[11.5px]">يحدد عند الموافقة</span>,
              },
              { h: 'الحالة', r: (r) => <StatusBadge map={RETURN_STATUS} value={r.status} /> },
              {
                h: 'طريقة الرد',
                r: (r) =>
                  r.method ? (
                    <span className="tx2 whitespace-nowrap">{REFUND_METHODS[r.method] || r.method}</span>
                  ) : (
                    <span className="tx3">—</span>
                  ),
              },
              { h: 'إجمالي الاسترداد', r: (r) => <span className="font-semibold tx1 num whitespace-nowrap">{fmtMoney(r.total)}</span> },
              { h: 'تاريخ الإنشاء', r: (r) => <Num className="tx2 whitespace-nowrap">{r.date}</Num> },
              {
                h: '',
                align: 'left',
                r: (r) => (
                  <RowActions>
                    <button
                      type="button"
                      className="btn btn-soft btn-xs inline-flex items-center gap-1"
                      onClick={() => go({ m: 'returns', id: r.id })}
                    >
                      <span>التفاصيل</span>
                      <Icon name="chevL" size={12} />
                    </button>
                  </RowActions>
                ),
              },
            ]}
          />
        </div>
        <div className="md:hidden">
          {loading ? (
            <div className="py-8 flex justify-center"><AdminLoading compact /></div>
          ) : error && !usingMock ? (
            <div className="py-6">
              <Empty
                icon="alert"
                title="تعذّر تحميل المرتجعات"
                text={error.message}
                action={<button className="btn btn-soft btn-sm" onClick={reload}>إعادة المحاولة</button>}
              />
            </div>
          ) : rows.length === 0 ? (
            <div className="py-10 text-center tx3 text-[13px]">لا توجد مرتجعات مطابقة</div>
          ) : (
            <div className="op-order-cards">
              {rows.map((r: ReturnRow) => (
                <button
                  key={r.id}
                  type="button"
                  onClick={() => go({ m: 'returns', id: r.id })}
                  className="op-order-card"
                >
                  <div className="op-order-card-head">
                    <div className="flex items-center gap-2 min-w-0">
                      <span className="num font-bold tx1 text-[13.5px]">{r.no}</span>
                      <span className="v-card px-2 py-0.5 rounded-full text-[10.5px] tx2 num truncate">
                        طلب {r.order}
                      </span>
                    </div>
                    <StatusBadge map={RETURN_STATUS} value={r.status} />
                  </div>

                  <div className="op-order-card-customer">
                    <div className="flex items-center gap-2.5 min-w-0 flex-1">
                      <Avatar name={r.customer} tone={r.id} size={34} />
                      <div className="min-w-0 flex-1 leading-tight">
                        <div className="font-semibold tx1 text-[13.5px] truncate">{r.customer}</div>
                        <div className="text-[11px] tx3 mt-0.5 truncate">
                          الطلب الأصلي: <span className="num">{r.order}</span>
                        </div>
                      </div>
                    </div>
                    <div className="text-end shrink-0">
                      <div className="font-display font-bold tx1 text-[15px] num whitespace-nowrap">
                        {fmtMoney(r.total)}
                      </div>
                    </div>
                  </div>

                  <div className="op-order-card-meta">
                    <div className="min-w-0">
                      <div className="text-[10.5px] tx3 mb-0.5">الفرع</div>
                      <div className="text-[12px] tx1 font-medium truncate">
                        {r.branch || 'يحدد عند الموافقة'}
                      </div>
                    </div>
                    <div className="min-w-0">
                      <div className="text-[10.5px] tx3 mb-0.5">طريقة الرد</div>
                      <div className="text-[12px] tx1 font-medium truncate">
                        {r.method ? (REFUND_METHODS[r.method] || r.method) : '—'}
                      </div>
                    </div>
                  </div>

                  <div className="op-order-card-foot">
                    <span className="text-[11.5px] tx3 num">{r.date}</span>
                    <span className="btn btn-soft btn-xs inline-flex items-center gap-1 shrink-0">
                      <span>التفاصيل</span>
                      <Icon name="chevL" size={12} />
                    </span>
                  </div>
                </button>
              ))}
            </div>
          )}
        </div>
        <Pager total={totalCount} per={data?.meta.per_page ?? 20} page={page} onPage={setPage} />
      </Card>
      <CreateReturnModal open={create} onClose={() => setCreate(false)} onSaved={() => { reload(); counts.reload(); }} />
    </div>
  );
}

export function CreateReturnModal({ open, onClose, onSaved }: { open: boolean; onClose: () => void; onSaved?: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({ reason: '', refund_method: '' });
  const [orderId, setOrderId] = useState('');
  const [orderQuery, setOrderQuery] = useState('');
  const dqOrder = useDebounced(orderQuery);
  const [lines, setLines] = useState([{ item: '', qty: 1, reason: '' }]);
  const set = (i: number, k: string, v: any) =>
    setLines(lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)));

  const deliveredOrders = useRemote(
    (signal) => adminOrdersApi.list({ search: dqOrder || undefined, status: 'delivered' as OrderStatus }, signal).then((r) => r.data),
    [dqOrder],
    { enabled: open, mock: () => ORDERS.filter((o) => o.status === 'delivered').map((o) => ({ id: o.id, order_number: o.no } as any)) },
  );
  const selectedOrder = useRemote(
    (signal) => (orderId ? adminOrdersApi.show(Number(orderId), signal) : Promise.resolve(null as any)),
    [orderId],
    { enabled: open && !!orderId, mock: () => mockOrder(Number(orderId)) },
  );
  const orderItems = (selectedOrder.data as any)?.items ?? [];
  useEffect(() => { setLines([{ item: '', qty: 1, reason: '' }]); }, [orderId]);

  const submit = async () => {
    if (selectedOrder.usingMock || deliveredOrders.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    if (!orderId) return toast('اختر الطلب الأصلي', 'red');
    if (!f.v.reason.trim()) { f.setErrors({ reason: 'سبب الإرجاع مطلوب' } as any); return toast('سبب الإرجاع مطلوب', 'red'); }
    const items = lines
      .filter((l) => l.item && Number(l.qty) > 0)
      .map((l) => ({ order_item_id: Number(l.item), requested_qty: Number(l.qty), item_reason: l.reason || undefined }));
    if (!items.length) return toast('أضف بندًا واحدًا على الأقل بكمية صحيحة', 'red');
    const r = await run(() => adminReturnsApi.create({
      order_id: Number(orderId),
      reason: f.v.reason,
      refund_method: (f.v.refund_method === 'bank' ? 'bank_transfer' : f.v.refund_method === 'original' ? 'original_payment' : f.v.refund_method || undefined) as RefundMethod | undefined,
      items,
    }), { toast, success: 'تم إنشاء طلب المرتجع', onFieldErrors: (e) => f.setErrors(e as any) });
    if (r) { onSaved?.(); onClose(); toast(`تم إنشاء المرتجع ${(r as any).return_number ?? ''}`, 'green'); }
  };

  const selectedOrderData = selectedOrder.data as any;
  const selectedOrderCustomer =
    selectedOrderData?.customer?.name ?? selectedOrderData?.guest_name ?? selectedOrderData?.customer_name ?? '';
  const validItemsCount = lines.filter((l) => l.item && Number(l.qty) > 0).length;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="إنشاء مرتجع"
      icon="returns"
      width={860}
      sub="يُنشأ بحالة «قيد المراجعة»؛ يُحسب مبلغ الاسترداد من النظام."
      footer={
        <div className="flex w-full items-center justify-between gap-3">
          <div className="hidden sm:flex items-center gap-2 text-[12px] tx2">
            {orderId ? (
              <>
                <span className="v-sub px-2.5 py-1 rounded-full num font-semibold tx1">
                  {selectedOrderData?.order_number ?? `#${orderId}`}
                </span>
                <span>·</span>
                <span>
                  بنود جاهزة: <strong className="tx1 num">{validItemsCount}</strong>
                </span>
              </>
            ) : (
              <span className="tx3">اختر الطلب الأصلي وأضف البنود المطلوب إرجاعها</span>
            )}
          </div>
          <div className="flex w-full sm:w-auto items-center gap-2 ms-auto">
            <button type="button" className="btn btn-soft flex-1 sm:flex-none min-h-[44px] sm:min-h-0 justify-center" onClick={onClose}>
              إلغاء
            </button>
            <button
              type="button"
              className="btn btn-dark flex-1 sm:flex-none min-h-[44px] sm:min-h-0 justify-center"
              disabled={!orderId || busy}
              onClick={submit}
            >
              إنشاء المرتجع
            </button>
          </div>
        </div>
      }
    >
      <div className="space-y-5">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
          <div className="lg:col-span-7 v-sub p-3 sm:p-4 rounded-2xl border border-[var(--border-subtle)] space-y-3">
            <Field label="الطلب الأصلي" req err={(f.errors as any).order_id}>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <div className="relative">
                  <input
                    className="inp pl-8 !bg-[var(--bg-card)] !border-[var(--border-field)]"
                    value={orderQuery}
                    onChange={(e) => setOrderQuery(e.target.value)}
                    placeholder="ابحث برقم الطلب..."
                  />
                  {orderQuery && (
                    <button
                      type="button"
                      onClick={() => setOrderQuery('')}
                      aria-label="مسح بحث الطلب"
                      className="op-search-clear absolute left-2 top-1/2 -translate-y-1/2"
                    >
                      <Icon name="x" size={12} />
                    </button>
                  )}
                </div>
                <select
                  className="inp !bg-[var(--bg-card)] !border-[var(--border-field)]"
                  value={orderId}
                  onChange={(e) => setOrderId(e.target.value)}
                >
                  <option value="">
                    {deliveredOrders.loading ? 'جارٍ البحث...' : `اختر من النتائج (${(deliveredOrders.data ?? []).length})`}
                  </option>
                  {(deliveredOrders.data ?? []).map((o: any) => (
                    <option key={o.id} value={String(o.id)}>
                      {o.order_number ?? o.no} — {(o.customer as any)?.name ?? o.customer ?? ''}
                    </option>
                  ))}
                </select>
              </div>
            </Field>
            {orderId && selectedOrderData && (
              <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 rounded-xl bg-[var(--bg-card)] border border-[var(--border-subtle)] text-[12px]">
                <div className="flex items-center gap-2 min-w-0">
                  <span className="num font-semibold tx1">{selectedOrderData.order_number ?? `#${orderId}`}</span>
                  {selectedOrderCustomer && (
                    <>
                      <span className="tx3">·</span>
                      <span className="tx2 truncate">{selectedOrderCustomer}</span>
                    </>
                  )}
                </div>
                <div className="flex items-center gap-3 shrink-0 tx2">
                  <span>
                    البنود: <strong className="tx1 num">{orderItems.length}</strong>
                  </span>
                  {selectedOrderData.grand_total != null && (
                    <span className="font-semibold tx1 num">{fmtMoney(Number(selectedOrderData.grand_total))}</span>
                  )}
                </div>
              </div>
            )}
          </div>

          <div className="lg:col-span-5 v-sub p-4 rounded-2xl border border-[var(--border-subtle)] flex flex-col justify-between">
            <Field label="طريقة الاسترداد" hint="اختيارية — يمكن تحديدها لاحقاً عند رد المبلغ." err={(f.errors as any).refund_method}>
              <select
                className="inp !bg-[var(--bg-card)] !border-[var(--border-field)]"
                value={f.v.refund_method}
                onChange={f.on('refund_method' as any)}
              >
                <option value="">لم تُحدد</option>
                {Object.entries(REFUND_METHODS).map(([k, l]) => (
                  <option key={k} value={k}>
                    {l}
                  </option>
                ))}
              </select>
            </Field>
          </div>
        </div>

        <Field label="سبب الإرجاع" req err={(f.errors as any).reason}>
          <TextArea
            label=""
            req
            max={2000}
            placeholder="اشرح سبب الإرجاع بوضوح..."
            value={f.v.reason}
            onChange={(v) => f.set('reason' as any, v)}
          />
        </Field>

        <div>
          <div className="flex items-center justify-between mb-2.5">
            <span className="lbl">
              بنود المرتجع <span className="accent">*</span>
            </span>
            <span className="v-sub px-2.5 py-0.5 rounded-full text-[11px] tx2 num">
              {lines.length} / 50 بند
            </span>
          </div>
          <div className="v-sub p-2.5 sm:p-3 rounded-2xl border border-[var(--border-subtle)] space-y-2.5">
            <div className="hidden sm:grid sm:grid-cols-12 gap-2.5 px-2 pt-0.5 text-[11.5px] font-medium tx3">
              <span className="col-span-5">بند الطلب</span>
              <span className="col-span-3 text-center">الكمية</span>
              <span className="col-span-3">سبب البند</span>
              <span className="col-span-1 text-center">حذف</span>
            </div>
            {lines.map((l, i) => {
              const matched = orderItems.find((it: any) => String(it.id) === String(l.item));
              const maxQty = matched?.qty != null ? Number(matched.qty) : undefined;
              const currentQty = Math.max(1, Number(l.qty) || 1);
              return (
                <div
                  key={i}
                  className="grid grid-cols-12 gap-2.5 items-center p-3 sm:p-2 rounded-2xl sm:rounded-xl bg-[var(--bg-card)] border border-[var(--border-subtle)]"
                >
                  <div className="col-span-12 sm:hidden flex items-center justify-between gap-2 pb-1.5 border-b border-[var(--border-subtle)]">
                    <div className="flex items-center gap-2 min-w-0">
                      <span className="text-[12px] font-semibold tx1">بند <Num>#{i + 1}</Num></span>
                      {maxQty != null && (
                        <span className="v-sub px-2 py-0.5 rounded-full text-[10.5px] tx2">
                          الحد الأقصى: <Num className="font-semibold tx1">{maxQty}</Num>
                        </span>
                      )}
                    </div>
                    <button
                      type="button"
                      aria-label="حذف البند"
                      className="icon-btn !w-9 !h-9"
                      disabled={lines.length === 1}
                      onClick={() => setLines(lines.filter((_, j) => j !== i))}
                    >
                      <Icon name="trash" size={14} />
                    </button>
                  </div>

                  <select
                    className="inp sm:inp-sm col-span-12 sm:col-span-5 !bg-[var(--bg-subcard)]"
                    disabled={!orderId}
                    value={l.item}
                    onChange={(e) => set(i, 'item', e.target.value)}
                  >
                    <option value="">{orderId ? 'اختر بندًا من الطلب' : 'اختر الطلب الأصلي أولًا'}</option>
                    {orderItems.map((it: any) => (
                      <option key={String(it.id)} value={String(it.id)}>
                        {it.product_name ?? it.name} · {it.sku} (الكمية: {it.qty})
                      </option>
                    ))}
                  </select>

                  <div className="col-span-5 sm:col-span-3">
                    <span className="sm:hidden block text-[11px] tx3 mb-1">الكمية</span>
                    <div className="op-qty-stepper">
                      <button
                        type="button"
                        aria-label="إنقاص الكمية"
                        className="op-qty-stepper-btn"
                        disabled={currentQty <= 1}
                        onClick={() => set(i, 'qty', Math.max(1, currentQty - 1))}
                      >
                        <Icon name="minus" size={13} />
                      </button>
                      <input
                        type="number"
                        min="1"
                        aria-label="الكمية"
                        placeholder="1"
                        max={maxQty}
                        className="op-qty-stepper-input num"
                        value={l.qty}
                        onChange={(e) => set(i, 'qty', e.target.value)}
                      />
                      <button
                        type="button"
                        aria-label="زيادة الكمية"
                        className="op-qty-stepper-btn"
                        disabled={maxQty != null && currentQty >= maxQty}
                        onClick={() => set(i, 'qty', maxQty != null ? Math.min(maxQty, currentQty + 1) : currentQty + 1)}
                      >
                        <Icon name="plus" size={13} />
                      </button>
                    </div>
                  </div>

                  <div className="col-span-7 sm:col-span-3">
                    <span className="sm:hidden block text-[11px] tx3 mb-1">سبب البند</span>
                    <input
                      aria-label="سبب البند"
                      className="inp sm:inp-sm !bg-[var(--bg-subcard)]"
                      placeholder="سبب خاص (اختياري)"
                      value={l.reason}
                      onChange={(e) => set(i, 'reason', e.target.value)}
                    />
                  </div>

                  <button
                    type="button"
                    aria-label="حذف البند"
                    className="hidden sm:inline-flex icon-btn !w-8 !h-8 sm:col-span-1 justify-self-center"
                    disabled={lines.length === 1}
                    onClick={() => setLines(lines.filter((_, j) => j !== i))}
                  >
                    <Icon name="trash" size={13} />
                  </button>

                  {(f.errors as any)[`items.${i}.requested_qty`] && (
                    <span className="col-span-12 text-[11px] c-red">{(f.errors as any)[`items.${i}.requested_qty`]}</span>
                  )}
                </div>
              );
            })}
            <button
              type="button"
              className="btn btn-soft btn-sm w-full min-h-[42px] sm:min-h-0 border border-dashed border-[var(--border-strong)] !bg-transparent hover:!bg-[var(--bg-card)]"
              disabled={lines.length >= 50}
              onClick={() => setLines([...lines, { item: '', qty: 1, reason: '' }])}
            >
              <Icon name="plus" size={13} />
              إضافة بند آخر
            </button>
          </div>
        </div>
      </div>
    </Modal>
  );
}

export function ReturnDetail({
  id,
  go,
}: {
  id: number | string;
  go: (route: { m: string; id?: number | string }) => void;
}) {
  const toast = useToast();
  const mayApprove = useCan('returns.approve');
  const mayReceive = useCan('returns.receive');
  const mayRefund = useCan('returns.refund');
  const mayUpdate = useCan('returns.update');
  const { busy, run } = useAction();
  const numId = Number(id);
  const validId = Number.isSafeInteger(numId) && numId > 0;
  const remote = useRemote((s) => adminReturnsApi.show(numId, s), [numId], { enabled: validId, mock: () => mockReturn(numId), onError: (e) => toast(e.message, 'red') });
  const { data: ret, setData: setRet, loading } = remote;
  const branches = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
  const branchMap = useMemo(() => new Map((branches.data ?? []).map((b: { id: number; name: string }) => [b.id, b.name] as const)), [branches.data]);
  const fb = useMemo(() => (ret ? mapReturn(ret, (bid) => (bid == null ? null : branchMap.get(bid) ?? `#${bid}`)) : null), [ret, branchMap]);
  const r = fb;
  const [modal, setModal] = useState<string | null>(null);
  const [approveBranch, setApproveBranch] = useState('');
  const [rejectMode, setRejectMode] = useState('rejected');
  const [approveQtys, setApproveQtys] = useState<Record<number, number>>({});
  const [goodQtys, setGoodQtys] = useState<Record<number, number | undefined>>({});
  const [damagedQtys, setDamagedQtys] = useState<Record<number, number | undefined>>({});
  const [refundMethod, setRefundMethod] = useState('');
  const [refundRef, setRefundRef] = useState('');
  const [refundTreasury, setRefundTreasury] = useState('');
  const needsRefundTreasury = (refundMethod || ret?.refund_method) === 'cash' && num(ret?.total_refund_amount) > 0;
  const treasuryBranch = modal === 'refund' && needsRefundTreasury ? ret?.branch_id : null;
  const refundTreasuries = useRemote((signal) => treasuryBranch
    ? treasuriesApi.list({ branch_id: treasuryBranch, is_active: true, type: 'main_safe', per_page: 100 }, signal).then((result) => result.data)
    : Promise.resolve([]), [treasuryBranch]);
  const [rejectNotes, setRejectNotes] = useState('');
  const [approveNotes, setApproveNotes] = useState('');
  useEffect(() => { if (ret) setApproveBranch(ret.branch_id ? String(ret.branch_id) : ''); }, [ret]);

  const approve = async () => {
    if (!ret || remote.usingMock) return;
    if (!approveBranch) return toast('اختر الفرع المستلم', 'red');
    const items = (ret.items ?? []).map((it) => ({ id: it.id, approved_qty: approveQtys[it.id] ?? it.requested_qty }));
    const res = await run(() => adminReturnsApi.approve(ret.id, {
      branch_id: Number(approveBranch),
      items,
      admin_notes: approveNotes || undefined,
    }), { toast, success: 'تمت الموافقة على المرتجع' });
    if (res) { setRet(res); setModal(null); }
  };

  const receive = async () => {
    if (!ret || remote.usingMock) return;
    const missing = (ret.items ?? []).filter((it) => goodQtys[it.id] === undefined || damagedQtys[it.id] === undefined);
    if (missing.length > 0) return toast('أدخل الكمية السليمة والتالفة لكل بند بعد الفحص الفعلي', 'red');
    for (const it of ret.items ?? []) {
      const g = Number(goodQtys[it.id] ?? 0);
      const d = Number(damagedQtys[it.id] ?? 0);
      const maxAllowed = Number(it.approved_qty ?? it.requested_qty);
      if (!Number.isFinite(g) || !Number.isFinite(d) || g < 0 || d < 0) {
        return toast('الكميات المستلمة لا يمكن أن تكون سالبة', 'red');
      }
      if (g + d > maxAllowed) {
        return toast(`مجموع السليم والتالف للبند (${g + d}) يتجاوز الكمية المعتمدة (${maxAllowed})`, 'red');
      }
    }
    const items = (ret.items ?? []).map((it) => ({ id: it.id, received_good_qty: goodQtys[it.id] ?? 0, received_damaged_qty: damagedQtys[it.id] ?? 0 }));
    const res = await run(() => adminReturnsApi.receive(ret.id, { items }), { toast, success: 'تم استلام المرتجع وفحصه' });
    if (res) { setRet(res); setModal(null); }
  };

  const refund = async () => {
    if (!ret || remote.usingMock) return;
    if (needsRefundTreasury && !refundTreasury) return toast('اختر خزينة رد النقدية', 'red');
    const res = await run(() => adminReturnsApi.refund(ret.id, {
      refund_method: (refundMethod === 'bank' ? 'bank_transfer' : refundMethod === 'original' ? 'original_payment' : refundMethod || ret.refund_method || undefined) as RefundMethod | undefined,
      refund_reference: refundRef || undefined,
      treasury_id: needsRefundTreasury ? Number(refundTreasury) : undefined,
    }), { toast, success: 'تم تنفيذ الاسترداد' });
    if (res) { setRet(res); setModal(null); }
  };

  const rejectOrCancel = async () => {
    if (!ret || remote.usingMock) return;
    if (rejectMode === 'rejected' && !mayApprove) return toast('ليست لديك صلاحية رفض المرتجع', 'red');
    if (rejectMode === 'cancelled' && !mayUpdate) return toast('ليست لديك صلاحية إلغاء المرتجع', 'red');
    const res = await run(() => adminReturnsApi.update(ret.id, { status: rejectMode as 'rejected' | 'cancelled', admin_notes: rejectNotes || undefined }), { toast, success: 'تم التحديث' });
    if (res) { setRet(res); setModal(null); setRejectMode('rejected'); setRejectNotes(''); }

  };
  const closeReject = () => { setModal(null); setRejectMode('rejected'); setRejectNotes(''); };

  if (!validId) return <Empty title="معرّف المرتجع غير صالح" />;
  if (loading || !ret || !r) return <><RemoteFeedback state={remote} />{!loading && !remote.error && <Empty title="المرتجع غير موجود" />}</>;

  const lines = (ret?.items ?? []).map((it) => ({
    v: { name: it.product_name ?? `#${it.order_item_id}`, sku: it.sku ?? '' },
    req: it.requested_qty,
    appr: it.approved_qty,
    good: it.received_good_qty,
    dmg: it.received_damaged_qty,
    price: num(it.unit_refund_price),
    reason: it.item_reason ?? '',
    files: 0,
    id: it.id,
  }));
  const displayLines = lines;
  const steps = [
    { k: 'pending', l: 'طلب الإرجاع' },
    { k: 'approved', l: 'الموافقة' },
    { k: 'received', l: 'الاستلام والفحص' },
    { k: 'refunded', l: 'رد المبلغ' },
  ];
  const order = ['pending', 'approved', 'received', 'refunded'];
  const idx = order.indexOf(r.status);
  const closed = ['rejected', 'cancelled'].includes(r.status);

  return (
    <div className="space-y-5">
      <RemoteFeedback state={remote} />
      <PageHead
        back={{ label: 'كل المرتجعات', onClick: () => go({ m: 'returns' }) }}
        title={
          <span className="flex flex-wrap items-center gap-2 sm:gap-3">
            <span className="num">{r.no}</span>
            <StatusBadge map={RETURN_STATUS} value={r.status} />
          </span>
        }
        sub={
          <span>
            عن الطلب <span className="num font-semibold tx1">{r.order}</span> · {r.customer}
          </span>
        }
        actions={
          <>
            {r.status === 'pending' && (
              <>
                <button className="btn btn-danger" disabled={(!mayUpdate && !mayApprove) || remote.usingMock || busy} onClick={() => { setRejectMode(mayApprove ? 'rejected' : 'cancelled'); setModal('reject'); }}>
                  رفض / إلغاء
                </button>
                <button className="btn btn-dark" disabled={!mayApprove || remote.usingMock || busy} onClick={() => setModal('approve')}>
                  <Icon name="check" size={14} />
                  الموافقة
                </button>
              </>
            )}
            {r.status === 'approved' && (
              <>
                <button
                  className="btn btn-soft"
                  disabled={!mayUpdate || remote.usingMock || busy}
                  onClick={() => {
                    setRejectMode('cancelled');
                    setModal('reject');
                  }}
                >
                  إلغاء
                </button>
                <button className="btn btn-dark" disabled={!mayReceive || remote.usingMock || busy} onClick={() => setModal('receive')}>
                  <Icon name="box" size={14} />
                  الاستلام والفحص
                </button>
              </>
            )}
            {r.status === 'received' && (
              <button className="btn btn-accent" disabled={!mayRefund || remote.usingMock || busy} onClick={() => setModal('refund')}>
                <Icon name="wallet" size={14} />
                رد المبلغ
              </button>
            )}
          </>
        }
      />

      <Card stg={2} pad="p-5">
        {closed ? (
          <Note tone={r.status === 'rejected' ? 'red' : 'gray'} icon="ban">
            هذا المرتجع {RETURN_STATUS[r.status]?.label || r.status} — لا توجد خطوات إضافية.
          </Note>
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-2">
            {steps.map((s, i) => {
              const done = i <= idx,
                cur = i === idx + 1;
              return (
                <div key={s.k} className="flex flex-col gap-2">
                  <div
                    className="h-2 rounded-full overflow-hidden"
                    style={{ background: done ? 'var(--accent)' : 'transparent' }}
                  >
                    {!done && <div className="h-full hatch-track" />}
                  </div>
                  <div className="flex items-center gap-2">
                    <span
                      className={
                        'w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold num ' +
                        (done ? 'btn-accent' : cur ? 'btn-dark' : 'v-well')
                      }
                    >
                      {done ? <Icon name="check" size={10} sw={3} /> : i + 1}
                    </span>
                    <span className={'text-[12.5px] font-medium ' + (done || cur ? 'tx1' : 'tx3')}>
                      {s.l}
                    </span>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <Card stg={3} className="lg:col-span-8">
          <CardHead title="بنود المرتجع" />
          <div className="hidden md:block">
            <DataTable
              compact
              rows={displayLines}
              cols={[
                {
                  h: 'المنتج',
                  r: (l) => (
                    <div className="leading-tight min-w-0 flex-1">
                      <div className="font-semibold tx1">{l.v.name}</div>
                      <div className="text-[11px] tx3 num ltr">{l.v.sku}</div>
                    </div>
                  ),
                },
                { h: 'مطلوب', align: 'center', r: (l) => <Num>{l.req}</Num> },
                {
                  h: 'موافق عليه',
                  align: 'center',
                  r: (l) => (l.appr == null ? <span className="tx3">—</span> : <Num className="font-semibold">{l.appr}</Num>),
                },
                {
                  h: 'سليم',
                  align: 'center',
                  r: (l) => (l.good == null ? <span className="tx3">—</span> : <Num className="c-green font-semibold">{l.good}</Num>),
                },
                {
                  h: 'تالف',
                  align: 'center',
                  r: (l) => (l.dmg == null ? <span className="tx3">—</span> : <Num className="c-red font-semibold">{l.dmg}</Num>),
                },
                { h: 'سعر الاسترداد', r: (l) => fmtMoney(l.price) },
                {
                  h: 'السبب والمرفقات',
                  r: (l) => (
                    <div className="leading-tight min-w-0 flex-1">
                      <div className="text-[12px] tx1">{l.reason}</div>
                      {l.files > 0 && (
                        <div className="flex gap-1 mt-1.5">
                          {Array.from({ length: l.files }).map((_, i) => (
                            <span
                              key={i}
                              className="w-7 h-7 rounded-lg v-sub flex items-center justify-center tx3"
                            >
                              <Icon name="image" size={12} />
                            </span>
                          ))}
                        </div>
                      )}
                    </div>
                  ),
                },
              ]}
            />
          </div>
          <div className="md:hidden space-y-2.5">
            {displayLines.map((l) => (
              <div key={l.id} className="op-return-item-card">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0 flex-1 leading-tight">
                    <div className="font-semibold tx1 text-[13.5px]">{l.v.name}</div>
                    {l.v.sku && <div className="text-[11px] tx3 num ltr text-right mt-0.5">{l.v.sku}</div>}
                  </div>
                  <div className="font-display font-bold tx1 text-[13.5px] num whitespace-nowrap shrink-0">
                    {fmtMoney(l.price)}
                  </div>
                </div>
                <div className="op-return-item-stats">
                  <div>
                    <div className="text-[10.5px] tx3 mb-0.5">مطلوب</div>
                    <div className="text-[13px] font-semibold tx1 num"><Num>{l.req}</Num></div>
                  </div>
                  <div>
                    <div className="text-[10.5px] tx3 mb-0.5">معتمد</div>
                    <div className="text-[13px] font-semibold tx1 num">
                      {l.appr == null ? <span className="tx3">—</span> : <Num>{l.appr}</Num>}
                    </div>
                  </div>
                  <div>
                    <div className="text-[10.5px] tx3 mb-0.5">سليم</div>
                    <div className="text-[13px] font-semibold num">
                      {l.good == null ? <span className="tx3">—</span> : <Num className="c-green">{l.good}</Num>}
                    </div>
                  </div>
                  <div>
                    <div className="text-[10.5px] tx3 mb-0.5">تالف</div>
                    <div className="text-[13px] font-semibold num">
                      {l.dmg == null ? <span className="tx3">—</span> : <Num className="c-red">{l.dmg}</Num>}
                    </div>
                  </div>
                </div>
                {(l.reason || l.files > 0) && (
                  <div className="text-[12px] tx2 leading-relaxed pt-0.5">
                    {l.reason}
                    {l.files > 0 && (
                      <div className="flex gap-1.5 mt-1.5">
                        {Array.from({ length: l.files }).map((_, i) => (
                          <span key={i} className="w-7 h-7 rounded-lg v-card flex items-center justify-center tx3">
                            <Icon name="image" size={12} />
                          </span>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>
            ))}
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-5">
            <div className="v-sub p-4">
              <div className="lbl mb-1.5">سبب الإرجاع</div>
              <div className="text-[12.5px] tx1 leading-relaxed">
                {ret.reason ?? '—'}
              </div>
            </div>
            <div className="v-sub p-4">
              <div className="lbl mb-1.5">ملاحظات الإدارة</div>
              <div className="text-[12.5px] tx1 leading-relaxed">
                {ret.admin_notes ?? '—'}
              </div>
            </div>
          </div>
        </Card>
        <div className="lg:col-span-4 space-y-5">
          <div className="v-card p-4 stg stg-3">
            <div className="v-orange rounded-[18px] p-4">
              <div className="text-[12.5px] text-white/90">إجمالي الاسترداد</div>
              <div className="font-display text-[28px] font-bold num my-1.5 ltr">
                {fmtNum(r.total)} <span className="text-[13px] opacity-80">EGP</span>
              </div>
              <div className="text-[11px] text-white/80">محسوب من النظام حسب الكميات المعتمدة</div>
            </div>
            <div className="p-2 pt-4">
              <KV
                items={[
                  ['الفرع', r.branch],
                  ['طريقة الاسترداد', r.method && (REFUND_METHODS[r.method] || r.method)],
                  ['مرجع الاسترداد', r.status === 'refunded' ? <span className="num">{ret.refund_reference ?? '—'}</span> : null],
                ]}
              />
            </div>
          </div>
          <Card stg={4}>
            <CardHead title="السجل" className="mb-4" />
            <Timeline
              items={[
                r.status === 'refunded' && {
                  icon: 'wallet',
                  body: (
                    <div>
                      <div className="text-[12.5px] font-medium tx1">تم رد المبلغ</div>
                      <div className="text-[11px] tx3">
                        <Num>{toDate(ret.refunded_at)} {toTime(ret.refunded_at)}</Num>
                      </div>
                    </div>
                  ),
                },
                idx >= 2 && {
                  icon: 'box',
                  body: (
                    <div>
                      <div className="text-[12.5px] font-medium tx1">تم الاستلام والفحص</div>
                      <div className="text-[11px] tx3">
                        <Num>{toDate(ret.received_at)} {toTime(ret.received_at)}</Num>
                      </div>
                    </div>
                  ),
                },
                idx >= 1 && {
                  icon: 'check',
                  body: (
                    <div>
                      <div className="text-[12.5px] font-medium tx1">تمت الموافقة</div>
                      <div className="text-[11px] tx3">
                        <Num>{toDate(ret.approved_at)} {toTime(ret.approved_at)}</Num>
                      </div>
                    </div>
                  ),
                },
                {
                  icon: 'plus',
                  body: (
                    <div>
                      <div className="text-[12.5px] font-medium tx1">تم إنشاء المرتجع</div>
                      <div className="text-[11px] tx3">
                        {r.customer} · <Num>{r.date} {toTime(ret.created_at)}</Num>
                      </div>
                    </div>
                  ),
                },
              ].filter(Boolean) as any}
            />
          </Card>
        </div>
      </div>

      {/* approve modal */}
      <Modal
        open={modal === 'approve'}
        onClose={() => setModal(null)}
        title="الموافقة على المرتجع"
        icon="check"
        width={620}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={remote.usingMock || !approveBranch || busy}
              onClick={approve}
            >
              موافقة
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="الفرع المستلم" req>
            <select
              className="inp"
              value={approveBranch}
              onChange={(e) => setApproveBranch(e.target.value)}
            >
              <option value="">اختر الفرع</option>
              {(branches.data ?? []).map((b: { id: number; name: string }) => (
                <option key={b.id} value={String(b.id)}>{b.name}</option>
              ))}
            </select>
          </Field>
          <div>
            <div className="lbl mb-2">الكمية الموافق عليها لكل بند</div>
            <div className="v-sub p-2 space-y-1.5">
              {displayLines.map((l, i) => (
                <div key={i} className="flex items-center gap-3 px-2 py-1.5">
                  <div className="flex-1 min-w-0">
                    <div className="text-[12.5px] font-medium tx1 clamp1">{l.v.name}</div>
                    <div className="text-[11px] tx3">
                      مطلوب <Num>{l.req}</Num>
                    </div>
                  </div>
                  <input
                    type="number"
                    min="0"
                    max={l.req}
                    value={approveQtys[(l as any).id] ?? l.req}
                    onChange={(e) => setApproveQtys({ ...approveQtys, [(l as any).id]: Number(e.target.value) })}
                    className="inp inp-sm w-20 num text-center !bg-[var(--bg-card)]"
                  />
                </div>
              ))}
            </div>
          </div>
          <TextArea label="ملاحظات الإدارة" placeholder="اختياري" value={approveNotes} onChange={setApproveNotes} />
        </div>
      </Modal>

      {/* receive modal */}
      <Modal
        open={modal === 'receive'}
        onClose={() => setModal(null)}
        title="الاستلام والفحص"
        icon="box"
        width={620}
        sub="أدخل الكمية السليمة والتالفة المستلمة لكل بند."
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={remote.usingMock || busy}
              onClick={receive}
            >
              تأكيد الاستلام
            </button>
          </>
        }
      >
        <div className="v-sub p-2.5 sm:p-2 space-y-2 sm:space-y-0">
          <div className="hidden sm:grid sm:grid-cols-12 gap-2 px-2 py-1.5 text-[11px] tx3">
            <span className="col-span-6">البند</span>
            <span className="col-span-2 text-center">معتمد</span>
            <span className="col-span-2 text-center">سليم</span>
            <span className="col-span-2 text-center">تالف</span>
          </div>
          {displayLines.map((l, i) => (
            <div
              key={i}
              className="grid grid-cols-12 gap-2 items-center p-2.5 sm:px-2 sm:py-1.5 rounded-xl sm:rounded-none bg-[var(--bg-card)] sm:bg-transparent border sm:border-0 border-[var(--border-subtle)]"
            >
              <div className="col-span-12 sm:col-span-6 flex items-center justify-between gap-2 min-w-0">
                <span className="text-[12.5px] font-medium tx1 clamp1">{l.v.name}</span>
                <span className="sm:hidden text-[11px] tx3 shrink-0">
                  معتمد: <Num className="font-semibold tx1">{l.appr ?? l.req}</Num>
                </span>
              </div>
              <Num className="hidden sm:block sm:col-span-2 text-center font-semibold">{l.appr ?? l.req}</Num>
              <div className="col-span-6 sm:col-span-2">
                <span className="sm:hidden block text-[11px] tx3 mb-1">سليم</span>
                <input
                  type="number"
                  min="0"
                  aria-label={`الكمية السليمة - ${l.v.name}`}
                  value={goodQtys[(l as any).id] ?? ''}
                  onChange={(e) => setGoodQtys({ ...goodQtys, [(l as any).id]: e.target.value === '' ? undefined : Number(e.target.value) })}
                  className="inp inp-sm w-full num text-center !bg-[var(--bg-card)]"
                />
              </div>
              <div className="col-span-6 sm:col-span-2">
                <span className="sm:hidden block text-[11px] tx3 mb-1">تالف</span>
                <input
                  type="number"
                  min="0"
                  aria-label={`الكمية التالفة - ${l.v.name}`}
                  value={damagedQtys[(l as any).id] ?? ''}
                  onChange={(e) => setDamagedQtys({ ...damagedQtys, [(l as any).id]: e.target.value === '' ? undefined : Number(e.target.value) })}
                  className="inp inp-sm w-full num text-center !bg-[var(--bg-card)]"
                />
              </div>
            </div>
          ))}
        </div>
      </Modal>

      {/* refund modal */}
      <Modal
        open={modal === 'refund'}
        onClose={() => setModal(null)}
        title="رد المبلغ"
        icon="wallet"
        width={460}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-accent"
              disabled={busy}
              onClick={refund}
            >
              تأكيد الرد
            </button>
          </>
        }
      >
        <div className="space-y-4 pb-2">
          <div className="v-sub p-4 flex items-center justify-between">
            <span className="tx2 text-[12.5px]">المبلغ المحسوب</span>
            <span className="font-display font-bold text-[18px]">{fmtMoney(ret ? num(ret.total_refund_amount) : r.total)}</span>
          </div>
          <Field label="طريقة الاسترداد" req>
            <select className="inp" value={refundMethod || (ret?.refund_method === 'original_payment' ? 'original' : ret?.refund_method === 'bank_transfer' ? 'bank' : ret?.refund_method ?? r.method ?? '')} onChange={(e) => setRefundMethod(e.target.value)}>
              {Object.entries(REFUND_METHODS).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </Field>
          {needsRefundTreasury && (
            <Field label="خزينة رد النقدية" req>
              <select className="inp" value={refundTreasury} onChange={(e) => setRefundTreasury(e.target.value)} disabled={refundTreasuries.loading}>
                <option value="">{refundTreasuries.loading ? 'جارٍ تحميل الخزائن' : 'اختر خزينة الفرع'}</option>
                {(refundTreasuries.data ?? []).map((treasury) => (
                  <option key={treasury.id} value={treasury.id}>{treasury.name}</option>
                ))}
              </select>
              {refundTreasuries.error && <p role="alert">{refundTreasuries.error.message}</p>}
            </Field>
          )}
          <Field label="المرجع" hint="رقم العملية أو التحويل عند الاسترداد خارج النظام.">
            <input className="inp num" dir="ltr" value={refundRef} onChange={(e) => setRefundRef(e.target.value)} />
          </Field>
        </div>
      </Modal>

      {/* reject modal */}
      <Modal
        open={modal === 'reject'}
        onClose={closeReject}
        title="رفض أو إلغاء المرتجع"
        icon="ban"
        tone="red"
        width={460}
        footer={
          <>
            <button className="btn btn-soft" onClick={closeReject}>
              تراجع
            </button>
            <button
              className="btn btn-danger"
              disabled={busy || (rejectMode === 'rejected' ? !mayApprove : !mayUpdate)}
              onClick={rejectOrCancel}
            >
              تأكيد
            </button>
          </>
        }
      >
        <div className="space-y-4 pb-2">
          <Field label="الحالة">
            <Seg
              value={rejectMode}
              onChange={setRejectMode}
              options={[
                ...(mayApprove ? [{ value: 'rejected', label: 'مرفوض' }] : []),
                ...(mayUpdate ? [{ value: 'cancelled', label: 'ملغي' }] : []),
              ]}
            />
          </Field>
          <TextArea label="ملاحظات الإدارة" placeholder="سبب الرفض أو الإلغاء" value={rejectNotes} onChange={setRejectNotes} />
        </div>
      </Modal>
    </div>
  );
}

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ReactDOM from 'react-dom';
import { API_ENDPOINTS, defaultApiClient } from '@alfarid/api-client';
import { hasPermission } from '@alfarid/utils';
import { pos } from '../../services/pos-data-source';
import { posCartStore } from '../../stores/pos-cart.store';
import { beepIncomingOrder, enableOrderSound } from '../../lib/sound';
import { normalizeError } from '../../lib/pos-error';
import { cx, egp, Empty, fmt, useEsc } from '../ui/SharedUI';
import { Icon } from '../ui/Icons';
import type {
  CashierUser,
  CompletedBranchOrderSummary,
  PastSale,
  PosPrepSession,
  PosShippingCompany,
} from '../../types/pos.types';

interface OrderSummary {
  id: number;
  number: string;
  channel: 'store' | 'wholesale' | 'pos';
  status: string;
  prepared_at?: string | null;
}

interface OrderPage {
  data: OrderSummary[];
  total: number;
  next_cursor: number | null;
  position: number;
  catching_up: boolean;
}

interface OrderEvents {
  data: { order_id: number; kind: 'arrival' | 'updated' | 'removed' }[];
  position: number;
  more: boolean;
  reset: boolean;
}

interface OrderDetailItem {
  id: number;
  product_variant_id?: number;
  name: string;
  sku: string;
  barcode?: string | null;
  qty: string;
  returnable_qty?: number;
}

interface OrderShipmentItem {
  id: number;
  awb_number: string;
  status: string;
  company_name: string | null;
  shipping_cost: string;
  cod_amount: string;
  tracking_url: string | null;
  created_at: string | null;
}

interface OrderReturnSummaryItem {
  id: number;
  return_number: string;
  status: string;
  total_refund_amount: string;
  created_at: string | null;
}

interface OrderDetails extends OrderSummary {
  items: OrderDetailItem[];
  shipments?: OrderShipmentItem[];
  returns?: OrderReturnSummaryItem[];
  next_status: 'confirmed' | 'processing' | 'delivered' | null;
  can_prepare?: boolean;
  can_ship?: boolean;
  can_cancel?: boolean;
  can_return?: boolean;
  prepared_at?: string | null;
  grand_total?: string | null;
  created_at: string | null;
  delivery: { name: string | null; phone: string | null; city: string | null; address: string | null; notes: string | null } | null;
  payment_method: string | null;
  payment_status: string | null;
}

type ChannelFilter = 'all' | 'store' | 'wholesale';
type CompletedChannelFilter = 'all' | 'pos' | 'store' | 'wholesale';

const CANCEL_REASON_PRESETS = [
  'طلب العميل إلغاء الطلب',
  'عدم توفر الصنف بالفرع',
  'تكرار الطلب بالخطأ',
  'تعذر التواصل مع العميل',
] as const;

export interface IncomingOrdersProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  cashier?: CashierUser | null;
  toast?: (msg: string, tone?: 'ok' | 'bad') => void;
  onStartCartPrep?: (session: PosPrepSession) => void;
  onOpenPosReturn?: (orderNumber: string) => void;
}

const endpoint = API_ENDPOINTS.POS.INCOMING_ORDERS;

const statusLabels: Record<string, string> = {
  pending: 'جديد',
  confirmed: 'مؤكد',
  processing: 'قيد التجهيز',
  shipped: 'تم الشحن',
  delivered: 'تم التسليم',
  cancelled: 'ملغي',
  returned: 'مرتجع',
};

const statusBadgeClasses: Record<string, string> = {
  pending: 'b-acc',
  confirmed: 'b-info',
  processing: 'b-warn',
  shipped: 'b-info',
  delivered: 'b-ok',
  cancelled: 'b-bad',
  returned: 'b-bad',
};

const paymentLabels: Record<string, string> = {
  cash: 'نقدي',
  card: 'بطاقة',
  mixed: 'نقدي وبطاقة',
  credit: 'آجل',
  cod: 'الدفع عند الاستلام',
  wallet: 'محفظة',
  bank_transfer: 'تحويل بنكي',
};

const paymentStatusLabels: Record<string, string> = {
  unpaid: 'غير مدفوع',
  paid: 'مدفوع',
  failed: 'فشل الدفع',
  refunded: 'مسترد',
  partially_refunded: 'مسترد جزئيًا',
  refund_pending: 'في انتظار الاسترداد',
};

const paymentStatusBadgeClasses: Record<string, string> = {
  paid: 'b-ok',
  unpaid: 'b-warn',
  refund_pending: 'b-warn',
  partially_refunded: 'b-info',
  failed: 'b-bad',
  refunded: 'b-bad',
};

const ORDER_STEPS = [
  { key: 'pending', label: 'جديد' },
  { key: 'confirmed', label: 'مؤكد' },
  { key: 'processing', label: 'قيد التجهيز' },
  { key: 'shipped', label: 'تم الشحن' },
] as const;

function getOrderStepIndex(status: string): number {
  if (status === 'pending') return 0;
  if (status === 'confirmed') return 1;
  if (status === 'processing') return 2;
  if (status === 'shipped' || status === 'delivered') return 3;
  return -1;
}

function OrderStatusBadge({ status }: { status: string }) {
  return (
    <span className={cx('badge', statusBadgeClasses[status] ?? 'b-neutral')}>
      <span className="dot" />
      {statusLabels[status] ?? status}
    </span>
  );
}

function ChannelBadge({ channel }: { channel: OrderSummary['channel'] }) {
  if (channel === 'pos') {
    return (
      <span className="io-channel-pill is-store">
        <Icon n="pos" s={12} />
        <span>كاشير</span>
      </span>
    );
  }
  const isWholesale = channel === 'wholesale';
  return (
    <span className={cx('io-channel-pill', isWholesale ? 'is-wholesale' : 'is-store')}>
      <Icon n={isWholesale ? 'dealer' : 'users'} s={12} />
      <span>{isWholesale ? 'تاجر' : 'فرد'}</span>
    </span>
  );
}

function OrderProgressStepper({ status }: { status: string }) {
  const stepIndex = getOrderStepIndex(status);
  if (stepIndex < 0) return null;
  const isCompleted = status === 'delivered';

  return (
    <div className="io-stepper" aria-label="مراحل تجهيز الطلب">
      {ORDER_STEPS.map((step, idx) => {
        const done = isCompleted || idx < stepIndex;
        const active = !isCompleted && idx === stepIndex;
        return (
          <React.Fragment key={step.key}>
            {idx > 0 && <div className={cx('io-step-line', (isCompleted || idx <= stepIndex) && 'is-done')} />}
            <div className={cx('io-step', done && 'is-done', active && 'is-active')}>
              <span className="io-step-dot">
                {done ? <Icon n="check" s={11} w={2.8} /> : fmt(idx + 1)}
              </span>
              <span className="io-step-label">{step.label}</span>
            </div>
          </React.Fragment>
        );
      })}
    </div>
  );
}

function QueueSkeleton() {
  return (
    <div className="io-skel-list" aria-hidden="true">
      {[0, 1, 2, 3].map((idx) => (
        <div key={idx} className="io-skel-card">
          <div className="row between gap-2">
            <span className="io-skel-bar" style={{ width: '44%' }} />
            <span className="io-skel-bar" style={{ width: '24%', height: 20, borderRadius: 99 }} />
          </div>
          <div className="row between gap-2">
            <span className="io-skel-bar" style={{ width: '28%' }} />
            <span className="io-skel-bar" style={{ width: 14, height: 14 }} />
          </div>
        </div>
      ))}
    </div>
  );
}

function DetailSkeleton() {
  return (
    <div className="io-detail-skel" aria-hidden="true">
      <div className="io-skel-bar" style={{ width: '48%', height: 22 }} />
      <div className="io-skel-bar" style={{ width: '32%', height: 14 }} />
      <div className="io-meta-grid">
        {[0, 1, 2, 3].map((idx) => (
          <div key={idx}>
            <span className="io-skel-bar" style={{ width: '50%', height: 10 }} />
            <span className="io-skel-bar" style={{ width: '75%', height: 14, marginTop: 6 }} />
          </div>
        ))}
      </div>
      <div className="io-skel-card" style={{ height: 96 }} />
      <div className="io-skel-card" style={{ height: 120 }} />
    </div>
  );
}

export function IncomingOrders({
  open,
  onOpenChange,
  cashier,
  toast,
  onStartCartPrep,
  onOpenPosReturn,
}: IncomingOrdersProps) {
  const [queueTab, setQueueTab] = useState<'open' | 'completed'>('open');
  const [page, setPage] = useState<OrderPage>();
  const [older, setOlder] = useState<OrderPage>();
  const [details, setDetails] = useState<OrderDetails>();
  const [selectedId, setSelectedId] = useState<number>();
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [channelFilter, setChannelFilter] = useState<ChannelFilter>('all');
  const [error, setError] = useState('');
  const [stale, setStale] = useState(false);
  const [sound, setSound] = useState(false);
  const [busy, setBusy] = useState(false);
  const [arrivals, setArrivals] = useState(0);

  // Completed orders state
  const [completedQuery, setCompletedQuery] = useState('');
  const [completedChannel, setCompletedChannel] = useState<CompletedChannelFilter>('all');
  const [completedList, setCompletedList] = useState<CompletedBranchOrderSummary[]>([]);
  const [completedNextCursor, setCompletedNextCursor] = useState<string | null>(null);
  const [loadingCompleted, setLoadingCompleted] = useState(false);
  const [selectedCompletedSummary, setSelectedCompletedSummary] = useState<CompletedBranchOrderSummary | null>(null);
  const [selectedCompletedPosSale, setSelectedCompletedPosSale] = useState<PastSale | null>(null);

  // Cancel, shipment & branch return sub-panels
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [shipPanelOpen, setShipPanelOpen] = useState(false);
  const [shippingCompanies, setShippingCompanies] = useState<PosShippingCompany[]>([]);
  const [loadingCompanies, setLoadingCompanies] = useState(false);
  const [selectedCompanyId, setSelectedCompanyId] = useState<string>('');
  const [awbInput, setAwbInput] = useState('');
  const [returnPanelOpen, setReturnPanelOpen] = useState(false);
  const [returnSelection, setReturnSelection] = useState<Record<number, { qty: number; to: 'on_hand' | 'damaged' }>>({});
  const [returnReasonType, setReturnReasonType] = useState<'change_of_mind' | 'defect_or_mismatch'>('change_of_mind');
  const [returnReason, setReturnReason] = useState('');

  const toastRef = useRef(toast);
  toastRef.current = toast;
  const request = useRef<AbortController | undefined>(undefined);
  const command = useRef<{ signature: string; key: string } | undefined>(undefined);
  const sheetRef = useRef<HTMLDivElement>(null);
  const dragRef = useRef({
    active: false,
    startY: 0,
    lastY: 0,
    lastTime: 0,
    velocityY: 0,
    offsetY: 0,
  });

  useEffect(() => () => onOpenChange(false), [onOpenChange]);

  useEffect(() => {
    let active = true;
    const unlock = () => { void enableOrderSound().then((enabled) => { if (active) setSound(enabled); }); };
    window.addEventListener('click', unlock, { once: true });
    return () => {
      active = false;
      window.removeEventListener('click', unlock);
    };
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    let position: number | undefined;
    let initialOrders = new Set<number>();
    let initializing = false;
    const newlyArrived = new Set<number>();
    let overflow = false;
    let failures = 0;
    let running = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    async function refresh() {
      if (running || controller.signal.aborted) return;
      running = true;
      clearTimeout(timer);
      let delay = 1500;
      try {
        const config = { signal: controller.signal, timeout: 8000 };
        const events = position === undefined ? null : (await defaultApiClient.get<OrderEvents>(
          `${endpoint}/events`, { ...config, params: { after: position } },
        )).data;
        if (controller.signal.aborted) return;
        if (!events || events.reset || events.data.length > 0) {
          if (events?.reset) {
            setDetails(undefined);
            setSelectedId(undefined);
          } else if (events) {
            const activePrepId = posCartStore.getState().prepSession?.orderId;
            if (activePrepId && events.data.some((ev) => ev.kind === 'removed' && ev.order_id === activePrepId)) {
              posCartStore.getState().clearPrepSession();
              toastRef.current?.('تم إلغاء أو سحب الطلب الجاري تجهيزه في السلة.', 'bad');
            }
            setDetails((current) => {
              const removed = current && events.data.some(
                (event) => event.kind === 'removed' && event.order_id === current.id,
              );
              if (removed) {
                setSelectedId(undefined);
                return undefined;
              }
              return current;
            });
          }
          const { data: latest } = await defaultApiClient.get<OrderPage>(endpoint, config);
          if (controller.signal.aborted) return;
          setPage(latest);
          if (!events || events.reset) {
            setOlder(undefined);
            position = latest.position;
            newlyArrived.clear();
            overflow = false;
            initialOrders = new Set(latest.data.map((order) => order.id));
            initializing = latest.catching_up;
          } else {
            for (const event of events.data) {
              if (!initializing && event.kind === 'arrival' && !initialOrders.has(event.order_id)) {
                if (newlyArrived.size < 1000) newlyArrived.add(event.order_id);
                else overflow = true;
              }
              if (event.kind === 'removed') {
                initialOrders.delete(event.order_id);
                newlyArrived.delete(event.order_id);
              }
            }
            position = events.position;
          }
        }
        if (events?.more) {
          delay = 500;
        } else if (events && !events.reset) {
          initializing = false;
        }
        if (!events?.more && (newlyArrived.size > 0 || overflow)) {
          const count = overflow ? 1000 : newlyArrived.size;
          newlyArrived.clear();
          overflow = false;
          beepIncomingOrder();
          setArrivals((current) => Math.min(1000, current + count));
        }
        setStale(false);
        failures = 0;
      } catch (failure) {
        if (controller.signal.aborted) return;
        const status = normalizeError(failure).status;
        if (status === 401 || status === 403) {
          setDetails(undefined);
          setSelectedId(undefined);
          setPage(undefined);
          setOlder(undefined);
          position = undefined;
        }
        setStale(true);
        failures = Math.min(failures + 1, 5);
        delay = Math.min(2000 * 2 ** failures, 60000);
      } finally {
        running = false;
      }
      timer = setTimeout(refresh, delay + Math.random() * 250);
    }

    window.addEventListener('online', refresh);
    void refresh();
    return () => {
      window.removeEventListener('online', refresh);
      controller.abort();
      request.current?.abort();
      clearTimeout(timer);
    };
  }, []);

  const fetchCompletedOrders = useCallback(async (opts: { q?: string; channel?: CompletedChannelFilter; cursor?: string | null; append?: boolean } = {}) => {
    setLoadingCompleted(true);
    try {
      const res = await pos.getCompletedBranchOrders({
        q: opts.q ?? completedQuery,
        channel: opts.channel ?? completedChannel,
        cursor: opts.cursor ?? null,
        limit: 25,
      });
      setCompletedList((prev) => (opts.append ? [...prev, ...res.data] : res.data));
      setCompletedNextCursor(res.nextCursor);
    } catch (failure) {
      setError(normalizeError(failure).message);
    } finally {
      setLoadingCompleted(false);
    }
  }, [completedQuery, completedChannel]);

  useEffect(() => {
    if (!open || queueTab !== 'completed') return;
    const timer = setTimeout(() => {
      void fetchCompletedOrders({ q: completedQuery, channel: completedChannel });
    }, completedQuery.trim() ? 240 : 0);
    return () => clearTimeout(timer);
  }, [open, queueTab, completedQuery, completedChannel, fetchCompletedOrders]);

  const closeModal = useCallback(() => {
    if (busy) return;
    setDetails(undefined);
    setSelectedId(undefined);
    setSelectedCompletedSummary(null);
    setSelectedCompletedPosSale(null);
    setCancelOpen(false);
    setCancelReason('');
    setShipPanelOpen(false);
    setAwbInput('');
    setReturnPanelOpen(false);
    setReturnSelection({});
    setLoadingDetail(false);
    setError('');
    onOpenChange(false);
  }, [busy, onOpenChange]);

  useEsc(closeModal, open);

  useEffect(() => {
    if (!open && sheetRef.current) {
      sheetRef.current.style.transform = '';
      sheetRef.current.style.transition = '';
    }
  }, [open]);

  const isMobileSheet = () =>
    typeof window !== 'undefined' && window.matchMedia('(max-width: 760px)').matches;

  const onHeaderPointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    if (busy || !isMobileSheet()) return;
    if ((e.target as HTMLElement).closest('button, a, input')) return;
    const el = sheetRef.current;
    if (!el) return;
    el.setPointerCapture?.(e.pointerId);
    el.style.transition = 'none';
    const now = performance.now();
    dragRef.current = {
      active: true,
      startY: e.clientY,
      lastY: e.clientY,
      lastTime: now,
      velocityY: 0,
      offsetY: 0,
    };
  };

  const onSheetPointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const d = dragRef.current;
    if (!d.active || !sheetRef.current) return;
    const now = performance.now();
    const dt = Math.max(1, now - d.lastTime);
    const dy = e.clientY - d.startY;
    d.velocityY = (e.clientY - d.lastY) / dt;
    d.lastY = e.clientY;
    d.lastTime = now;
    const offset = dy < 0 ? (dy * 120 * 0.35) / (120 + 0.35 * Math.abs(dy)) : dy;
    d.offsetY = offset;
    sheetRef.current.style.transform = `translate3d(0, ${offset}px, 0)`;
  };

  const onSheetPointerUp = (e: React.PointerEvent<HTMLDivElement>) => {
    const d = dragRef.current;
    if (!d.active || !sheetRef.current) return;
    d.active = false;
    const el = sheetRef.current;
    try {
      el.releasePointerCapture?.(e.pointerId);
    } catch {
      // ignore if not captured
    }
    const shouldClose = d.offsetY > 90 || (d.velocityY > 0.45 && d.offsetY > 24);
    el.style.transition = 'transform 260ms cubic-bezier(.2, 1, .3, 1)';
    if (shouldClose) {
      el.style.transform = 'translate3d(0, 100%, 0)';
      window.setTimeout(closeModal, 200);
    } else {
      el.style.transform = 'translate3d(0, 0, 0)';
      window.setTimeout(() => {
        if (sheetRef.current) {
          sheetRef.current.style.transform = '';
          sheetRef.current.style.transition = '';
        }
      }, 260);
    }
  };

  async function run(action: (signal: AbortSignal) => Promise<void>) {
    if (request.current) return;
    const controller = new AbortController();
    request.current = controller;
    setBusy(true);
    setError('');
    try {
      await action(controller.signal);
    } catch (failure) {
      if (!controller.signal.aborted) setError(normalizeError(failure).message);
    } finally {
      request.current = undefined;
      if (!controller.signal.aborted) setBusy(false);
    }
  }

  function loadOrder(id: number, completedSummary: CompletedBranchOrderSummary | null = null) {
    setSelectedId(id);
    setSelectedCompletedSummary(completedSummary);
    setSelectedCompletedPosSale(null);
    setCancelOpen(false);
    setCancelReason('');
    setShipPanelOpen(false);
    setAwbInput('');
    setReturnPanelOpen(false);
    setReturnSelection({});
    setLoadingDetail(true);
    return run(async (signal) => {
      setDetails(undefined);
      try {
        const response = await defaultApiClient.get<{ data: OrderDetails }>(`${endpoint}/${id}`, { signal });
        if (!signal.aborted) setDetails(response.data.data);
      } finally {
        if (!signal.aborted) setLoadingDetail(false);
      }
    });
  }

  function loadCompletedOrder(summary: CompletedBranchOrderSummary) {
    setSelectedId(summary.id);
    setSelectedCompletedSummary(summary);
    setCancelOpen(false);
    setShipPanelOpen(false);
    setReturnPanelOpen(false);
    setReturnSelection({});
    if (summary.channel === 'pos') {
      if (!summary.order_number?.trim()) {
        setError('تعذر تحديد رقم الفاتورة.');
        return;
      }
      setDetails(undefined);
      setSelectedCompletedPosSale(null);
      setLoadingDetail(true);
      return run(async () => {
        try {
          const sale = await pos.getPastSale(summary.order_number);
          setSelectedCompletedPosSale(sale);
        } finally {
          setLoadingDetail(false);
        }
      });
    }
    return loadOrder(summary.id, summary);
  }

  async function openShipmentPanel() {
    setCancelOpen(false);
    setReturnPanelOpen(false);
    setShipPanelOpen(true);
    if (shippingCompanies.length > 0) return;
    setLoadingCompanies(true);
    try {
      const companies = await pos.getShippingCompanies();
      setShippingCompanies(companies);
      if (companies.length > 0 && !selectedCompanyId) {
        setSelectedCompanyId(String(companies[0].id));
      }
    } catch (failure) {
      setError(normalizeError(failure).message);
    } finally {
      setLoadingCompanies(false);
    }
  }

  function submitShipment(order: OrderDetails) {
    const companyId = Number(selectedCompanyId);
    if (!companyId) {
      setError('اختر شركة الشحن أولًا.');
      return;
    }
    return run(async (signal) => {
      const created = await pos.createIncomingOrderShipment(order.id, {
        shipping_company_id: companyId,
        awb_number: awbInput.trim() || null,
      });
      setShipPanelOpen(false);
      setAwbInput('');
      const { data } = await defaultApiClient.get<{ data: OrderDetails }>(`${endpoint}/${order.id}`, { signal });
      if (!signal.aborted) setDetails(data.data);
      toastRef.current?.(`تم شحن الطلب #${order.number} برقم بوليصة ${created?.awb_number ?? ''}`);
    });
  }

  function advance(order: OrderDetails) {
    const signature = `${order.id}:${order.status}:${order.next_status}`;
    if (command.current?.signature !== signature) command.current = { signature, key: crypto.randomUUID() };
    const key = command.current.key;
    return run(async (signal) => {
      await defaultApiClient.post(`${endpoint}/${order.id}/status`, {
        expected_status: order.status,
        status: order.next_status,
      }, { signal, headers: { 'Idempotency-Key': key } });
      const { data } = await defaultApiClient.get<{ data: OrderDetails }>(`${endpoint}/${order.id}`, { signal });
      if (!signal.aborted) setDetails(data.data);
    });
  }

  function submitCancel(order: OrderDetails) {
    const trimmed = cancelReason.trim();
    if (trimmed.length < 3) {
      setError('يرجى إدخال سبب الإلغاء (٣ أحرف على الأقل).');
      return;
    }
    const expected = order.status as 'pending' | 'confirmed' | 'processing';
    return run(async () => {
      await pos.cancelIncomingOrder(order.id, expected, trimmed);
      if (posCartStore.getState().prepSession?.orderId === order.id) {
        posCartStore.getState().clearPrepSession();
      }
      setPage((prev) =>
        prev
          ? {
              ...prev,
              data: prev.data.filter((o) => o.id !== order.id),
              total: Math.max(0, prev.total - 1),
            }
          : prev,
      );
      setOlder((prev) => (prev ? { ...prev, data: prev.data.filter((o) => o.id !== order.id) } : prev));
      setDetails(undefined);
      setSelectedId(undefined);
      setCancelOpen(false);
      setCancelReason('');
      toastRef.current?.(`تم إلغاء الطلب #${order.number}`);
    });
  }

  function submitBranchReturn(order: OrderDetails) {
    const items = Object.entries(returnSelection)
      .filter(([, v]) => v.qty > 0)
      .map(([orderItemId, v]) => ({
        order_item_id: Number(orderItemId),
        qty: v.qty,
        return_to: v.to,
      }));
    if (items.length === 0) {
      setError('اختر صنفًا واحدًا على الأقل للاستلام كمرتجع.');
      return;
    }
    return run(async (signal) => {
      const res = await pos.receiveBranchOrderReturn(order.id, {
        reason: returnReason.trim() || undefined,
        reason_type: returnReasonType,
        items,
      });
      setReturnPanelOpen(false);
      setReturnSelection({});
      setReturnReason('');
      const response = await defaultApiClient.get<{ data: OrderDetails }>(`${endpoint}/${order.id}`, { signal });
      if (!signal.aborted) setDetails(response.data.data);
      void fetchCompletedOrders();
      toastRef.current?.(`تم استلام المرتجع #${res.return_number} وإضافته لمخزون الفرع`);
    });
  }

  const displayed = older ?? page;
  const filteredOrders = useMemo(() => {
    const list = displayed?.data ?? [];
    if (channelFilter === 'all') return list;
    return list.filter((order) => order.channel === channelFilter);
  }, [displayed?.data, channelFilter]);

  const totalItemsQty = useMemo(
    () => details?.items.reduce((sum, item) => sum + (Number(item.qty) || 0), 0) ?? 0,
    [details?.items],
  );

  const hasDetailPane = Boolean(details || selectedCompletedPosSale || loadingDetail);

  const backToQueue = () => {
    if (busy) return;
    setDetails(undefined);
    setSelectedCompletedSummary(null);
    setSelectedCompletedPosSale(null);
    setCancelOpen(false);
    setReturnPanelOpen(false);
    setSelectedId(undefined);
    setLoadingDetail(false);
    setError('');
  };

  return (
    <>
      <button
        type="button"
        className={cx('io-trigger', arrivals > 0 && 'has-new')}
        aria-label={`طلبات الفرع (${page ? fmt(page.total) : '…'})`}
        onClick={() => {
          onOpenChange(true);
          setArrivals(0);
        }}
      >
        <Icon n="orders" s={15} />
        <span className="io-trigger-label">الطلبات</span>
        <span className="io-trigger-count num">{page ? fmt(page.total) : '…'}</span>
        {arrivals > 0 && (
          <span role="status" className="io-arrivals-pill num">
            +{arrivals === 1000 ? '999+' : fmt(arrivals)} <span className="io-arrivals-word">جديد</span>
          </span>
        )}
      </button>

      {open && ReactDOM.createPortal(
        <div
          className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}
          dir="rtl"
        >
          <div className="pos-overlay" onClick={closeModal} />
          <div
            ref={sheetRef}
            className="pos-modal io-modal pos-sheet-mobile"
            role="dialog"
            aria-modal="true"
            aria-label="طلبات الفرع"
            onPointerMove={onSheetPointerMove}
            onPointerUp={onSheetPointerUp}
            onPointerCancel={onSheetPointerUp}
          >
            <div className={cx('pop io-pop', hasDetailPane && 'has-detail')}>
              <header className="io-header" onPointerDown={onHeaderPointerDown}>
                <span className="pos-sheet-handle io-grabber" aria-hidden="true" />
                <div className="io-header-row">
                  <div className="io-header-start">
                    {hasDetailPane && (
                      <button
                        type="button"
                        disabled={busy}
                        className="btn btn-secondary btn-sm io-mobile-back"
                        onClick={backToQueue}
                      >
                        <Icon n="chevRight" s={15} />
                        <span>رجوع للطلبات</span>
                      </button>
                    )}
                    <div className={cx('io-header-title', hasDetailPane && 'hide-on-mobile-detail')}>
                      <h3 className="fs-16">طلبات الفرع</h3>
                      <span className="badge b-neutral num">
                        {displayed ? fmt(displayed.total) : '…'}
                      </span>
                    </div>
                  </div>

                  <div className="io-header-end">
                    {!sound && (
                      <button
                        type="button"
                        className="io-sound-pill"
                        onClick={async () => {
                          const enabled = await enableOrderSound();
                          setSound(enabled);
                          if (enabled) beepIncomingOrder();
                        }}
                        title="تفعيل التنبيه الصوتي للطلبات الجديدة"
                      >
                        <Icon n="bell" s={14} />
                        <span>تفعيل الصوت</span>
                      </button>
                    )}
                    <button
                      type="button"
                      className="icon-btn sm io-close-btn"
                      onClick={closeModal}
                      disabled={busy}
                      aria-label="إغلاق"
                    >
                      <Icon n="x" s={16} />
                    </button>
                  </div>
                </div>
              </header>

              <div className={cx('io-shell', hasDetailPane && 'has-detail')}>
                {(stale || error) && (
                  <div className="io-alerts">
                    {stale && (
                      <div className="banner b-warn" role="status">
                        <Icon n="wifi" s={16} />
                        <span>الاتصال متوقف. جارٍ إعادة المحاولة تلقائيًا…</span>
                      </div>
                    )}
                    {error && (
                      <div className="banner b-bad" role="alert">
                        <Icon n="alert" s={16} />
                        <span>{error}</span>
                      </div>
                    )}
                  </div>
                )}

                <div className="io-grid">
                  <section className="io-queue" aria-label="قائمة الطلبات">
                    <div className="io-tab-bar" role="tablist" aria-label="تبويب الطلبات">
                      <button
                        type="button"
                        role="tab"
                        className="io-tab-btn"
                        aria-selected={queueTab === 'open'}
                        onClick={() => {
                          setQueueTab('open');
                          setSelectedCompletedSummary(null);
                          setSelectedCompletedPosSale(null);
                          setReturnPanelOpen(false);
                        }}
                      >
                        المفتوحة ({page ? fmt(page.total) : '0'})
                      </button>
                      <button
                        type="button"
                        role="tab"
                        className="io-tab-btn"
                        aria-selected={queueTab === 'completed'}
                        onClick={() => {
                          setQueueTab('completed');
                          setDetails(undefined);
                          setSelectedId(undefined);
                          setCancelOpen(false);
                        }}
                      >
                        المكتملة والسابقة
                      </button>
                    </div>

                    {queueTab === 'open' ? (
                      <>
                    <div className="io-queue-head">
                      <span className="fs-13 fw-6 t-2">الطلبات المفتوحة</span>
                      {(displayed?.data.length ?? 0) > 0 && (
                        <div className="seg io-filter-seg" role="group" aria-label="تصفية حسب القناة">
                          <button
                            type="button"
                            aria-pressed={channelFilter === 'all'}
                            onClick={() => setChannelFilter('all')}
                          >
                            الكل
                          </button>
                          <button
                            type="button"
                            aria-pressed={channelFilter === 'store'}
                            onClick={() => setChannelFilter('store')}
                          >
                            فرد
                          </button>
                          <button
                            type="button"
                            aria-pressed={channelFilter === 'wholesale'}
                            onClick={() => setChannelFilter('wholesale')}
                          >
                            تاجر
                          </button>
                        </div>
                      )}
                    </div>

                    <div className="io-list-scroll">
                      {!displayed && !error ? (
                        <QueueSkeleton />
                      ) : displayed?.data.length === 0 ? (
                        <Empty
                          icon="inbox"
                          title="لا توجد طلبات مفتوحة"
                          body="ستظهر طلبات المتجر الإلكتروني والتجار الموجهة لهذا الفرع هنا فور وصولها."
                        />
                      ) : filteredOrders.length === 0 ? (
                        <Empty
                          icon="filter"
                          title="لا توجد طلبات مطابقة"
                          body="جرّب تغيير فلتر القناة لعرض جميع الطلبات المفتوحة."
                          action={
                            <button
                              type="button"
                              className="btn btn-secondary btn-sm"
                              onClick={() => setChannelFilter('all')}
                            >
                              عرض الكل
                            </button>
                          }
                        />
                      ) : (
                        <ul className="io-list">
                          {filteredOrders.map((order) => {
                            const isSelected = selectedId === order.id;
                            const isPending = order.status === 'pending';
                            return (
                              <li key={order.id}>
                                <button
                                  type="button"
                                  disabled={busy}
                                  className={cx('io-order-card', isSelected && 'is-selected', isPending && 'is-pending')}
                                  aria-current={isSelected ? 'true' : undefined}
                                  onClick={() => void loadOrder(order.id)}
                                >
                                  <div className="io-order-top">
                                    <bdi className="ltr mono io-order-num">{order.number}</bdi>
                                    <OrderStatusBadge status={order.status} />
                                  </div>
                                  <div className="io-order-bottom">
                                    <div className="row gap-1">
                                      <ChannelBadge channel={order.channel} />
                                      {order.prepared_at && (
                                        <span className="badge b-ok fs-11">تم التجهيز ✓</span>
                                      )}
                                    </div>
                                    <span className="io-order-chev">
                                      <Icon n="chevLeft" s={15} />
                                    </span>
                                  </div>
                                </button>
                              </li>
                            );
                          })}
                        </ul>
                      )}
                    </div>

                    {(older || displayed?.next_cursor != null) && (
                      <div className="io-pager">
                        {older && (
                          <button
                            type="button"
                            disabled={busy}
                            className="btn btn-secondary btn-sm"
                            onClick={() => setOlder(undefined)}
                          >
                            أحدث الطلبات
                          </button>
                        )}
                        {displayed?.next_cursor != null && (
                          <button
                            type="button"
                            disabled={busy}
                            className="btn btn-secondary btn-sm ms-auto"
                            onClick={() => void run(async (signal) => {
                              const { data } = await defaultApiClient.get<OrderPage>(endpoint, {
                                signal,
                                params: { cursor: displayed.next_cursor },
                              });
                              if (!signal.aborted) setOlder(data);
                            })}
                          >
                            طلبات أقدم
                          </button>
                        )}
                      </div>
                    )}
                      </>
                    ) : (
                      <>
                        <div className="io-search-box">
                          <input
                            className="input"
                            placeholder="ابحث برقم الطلب، الهاتف، اسم العميل، أو SKU…"
                            value={completedQuery}
                            onChange={(e) => setCompletedQuery(e.target.value)}
                          />
                        </div>
                        <div className="io-queue-head">
                          <span className="fs-13 fw-6 t-2">طلبات الفرع السابقة</span>
                          <div className="seg io-filter-seg" role="group" aria-label="تصفية الطلبات السابقة حسب القناة">
                            {([
                              ['all', 'الكل'],
                              ['pos', 'كاشير'],
                              ['store', 'متجر'],
                              ['wholesale', 'جملة'],
                            ] as const).map(([val, lbl]) => (
                              <button
                                key={val}
                                type="button"
                                aria-pressed={completedChannel === val}
                                onClick={() => setCompletedChannel(val)}
                              >
                                {lbl}
                              </button>
                            ))}
                          </div>
                        </div>

                        <div className="io-list-scroll">
                          {loadingCompleted && completedList.length === 0 ? (
                            <QueueSkeleton />
                          ) : completedList.length === 0 ? (
                            <Empty
                              icon="orders"
                              title="لا توجد طلبات مكتملة مطابقة"
                              body="جرّب البحث برقم الهاتف أو اسم العميل أو غيّر فلتر القناة."
                            />
                          ) : (
                            <ul className="io-list">
                              {completedList.map((order) => {
                                const isSelected = selectedId === order.id && selectedCompletedSummary?.channel === order.channel;
                                return (
                                  <li key={`${order.channel}-${order.id}`}>
                                    <button
                                      type="button"
                                      disabled={busy}
                                      className={cx('io-order-card', isSelected && 'is-selected')}
                                      onClick={() => void loadCompletedOrder(order)}
                                    >
                                      <div className="io-order-top">
                                        <bdi className="ltr mono io-order-num">{order.order_number}</bdi>
                                        <OrderStatusBadge status={order.status} />
                                      </div>
                                      <div className="io-order-bottom">
                                        <div className="row gap-1">
                                          <ChannelBadge channel={order.channel} />
                                          {order.customer_name && (
                                            <span className="fs-12 t-2 truncate">{order.customer_name}</span>
                                          )}
                                        </div>
                                        <span className="num fs-12 fw-6">{egp(order.grand_total)} ج</span>
                                      </div>
                                    </button>
                                  </li>
                                );
                              })}
                            </ul>
                          )}
                        </div>

                        {completedNextCursor && (
                          <div className="io-pager">
                            <button
                              type="button"
                              disabled={loadingCompleted}
                              className="btn btn-secondary btn-sm ms-auto"
                              onClick={() => void fetchCompletedOrders({ cursor: completedNextCursor, append: true })}
                            >
                              {loadingCompleted ? 'جارٍ التحميل…' : 'تحميل المزيد'}
                            </button>
                          </div>
                        )}
                      </>
                    )}
                  </section>

                  <section className="io-detail" aria-label="تفاصيل الطلب">
                    {loadingDetail ? (
                      <div className="io-detail-scroll">
                        <DetailSkeleton />
                      </div>
                    ) : selectedCompletedPosSale ? (
                      <>
                        <div className="io-detail-scroll">
                          <div className="io-detail-head">
                            <div className="col gap-2">
                              <div className="row gap-2 wrap">
                                <bdi className="ltr mono io-detail-num">{selectedCompletedPosSale.number}</bdi>
                                <OrderStatusBadge status="delivered" />
                                <ChannelBadge channel="pos" />
                              </div>
                              <div className="io-detail-date">
                                <Icon n="calendar" s={13} />
                                <span>{selectedCompletedPosSale.date}</span>
                              </div>
                            </div>
                          </div>

                          <div className="io-meta-grid">
                            <div>
                              <span>العميل</span>
                              <b>{selectedCompletedPosSale.customer}</b>
                            </div>
                            <div>
                              <span>طريقة الدفع</span>
                              <b>{selectedCompletedPosSale.method}</b>
                            </div>
                            <div>
                              <span>الكاشير</span>
                              <b>{selectedCompletedPosSale.cashier || '—'}</b>
                            </div>
                            <div>
                              <span>الإجمالي</span>
                              <b className="num">
                                {egp(
                                  selectedCompletedPosSale.items.reduce(
                                    (s, i) => s + (i.line_total ?? i.price * i.qty),
                                    0,
                                  ),
                                )} ج.م
                              </b>
                            </div>
                          </div>

                          <section className="io-section">
                            <div className="io-section-head">
                              <Icon n="bag" s={15} />
                              <h3>بنود الفاتورة</h3>
                            </div>
                            <ul className="io-items-list">
                              {selectedCompletedPosSale.items.map((item) => {
                                const prod = pos.getProductById(item.id);
                                return (
                                  <li key={item.id} className="io-item-row">
                                    <span className="io-item-ic" aria-hidden="true">
                                      <Icon n="bag" s={16} />
                                    </span>
                                    <div className="io-item-info">
                                      <span className="io-item-name">{prod?.name ?? `صنف #${item.id}`}</span>
                                      <span className="fs-12 t-3">
                                        متاح للإرجاع: {fmt(item.returnable_qty ?? item.qty)} · {egp(item.price)} ج
                                      </span>
                                    </div>
                                    <span className="io-item-qty num">× {fmt(item.qty)}</span>
                                  </li>
                                );
                              })}
                            </ul>
                          </section>
                        </div>

                        {hasPermission(cashier?.perms, 'pos.sales.refund') &&
                          onOpenPosReturn &&
                          selectedCompletedPosSale.items.some((i) => (i.returnable_qty ?? i.qty) > 0) && (
                            <div className="io-detail-actions">
                              <button
                                type="button"
                                className="btn btn-accent btn-lg io-advance-btn"
                                onClick={() => onOpenPosReturn(selectedCompletedPosSale.number)}
                              >
                                <Icon n="ret" s={17} />
                                <span>مرتجع / استبدال الفاتورة</span>
                              </button>
                            </div>
                          )}
                      </>
                    ) : !details ? (
                      <div className="io-detail-empty">
                        <Empty
                          icon="orders"
                          title="اختر طلبًا للمعاينة"
                          body="اضغط على أي طلب من القائمة لعرض الأصناف وبيانات التسليم وتحديث حالته."
                        />
                      </div>
                    ) : (
                      <>
                        <div className="io-detail-scroll">
                          <div className="io-detail-head">
                            <div className="col gap-2">
                              <div className="row gap-2 wrap">
                                <bdi className="ltr mono io-detail-num">{details.number}</bdi>
                                <OrderStatusBadge status={details.status} />
                                <ChannelBadge channel={details.channel} />
                              </div>
                              {details.created_at && (
                                <div className="io-detail-date">
                                  <Icon n="calendar" s={13} />
                                  <span>تاريخ الطلب: {new Date(details.created_at).toLocaleString('ar-EG')}</span>
                                </div>
                              )}
                            </div>
                          </div>

                          <OrderProgressStepper status={details.status} />

                          <div className="io-meta-grid">
                            <div>
                              <span>القناة</span>
                              <b>{details.channel === 'wholesale' ? 'تاجر (جملة)' : 'فرد (متجر)'}</b>
                            </div>
                            <div>
                              <span>طريقة الدفع</span>
                              <b>{details.payment_method ? (paymentLabels[details.payment_method] ?? details.payment_method) : 'غير محددة'}</b>
                            </div>
                            <div>
                              <span>حالة الدفع</span>
                              <b>
                                {details.payment_status ? (
                                  <span className={cx('badge', paymentStatusBadgeClasses[details.payment_status] ?? 'b-neutral')}>
                                    <span className="dot" />
                                    {paymentStatusLabels[details.payment_status] ?? details.payment_status}
                                  </span>
                                ) : 'غير محددة'}
                              </b>
                            </div>
                            <div>
                              <span>إجمالي القطع</span>
                              <b className="num">{fmt(totalItemsQty)} قطعة ({fmt(details.items.length)} صنف)</b>
                            </div>
                          </div>

                          {details.delivery && (
                            <section className="io-section">
                              <div className="io-section-head">
                                <Icon n="truck" s={15} />
                                <h3>بيانات التسليم</h3>
                              </div>
                              <div className="io-delivery-grid">
                                <div className="io-delivery-field">
                                  <span>الاسم</span>
                                  <b>{details.delivery.name || 'غير متوفر'}</b>
                                </div>
                                <div className="io-delivery-field">
                                  <span>الهاتف</span>
                                  {details.delivery.phone ? (
                                    <a href={`tel:${details.delivery.phone}`} className="io-phone-link ltr num">
                                      <Icon n="phone" s={13} />
                                      <span>{details.delivery.phone}</span>
                                    </a>
                                  ) : (
                                    <b>غير متوفر</b>
                                  )}
                                </div>
                                <div className="io-delivery-field">
                                  <span>المدينة</span>
                                  <b>{details.delivery.city || 'غير متوفرة'}</b>
                                </div>
                                <div className="io-delivery-field is-full">
                                  <span>العنوان</span>
                                  <b>{details.delivery.address || 'غير متوفر'}</b>
                                </div>
                              </div>
                              {details.delivery.notes && (
                                <div className="io-notes">
                                  <Icon n="info" s={15} />
                                  <div>
                                    <span className="fw-6">تعليمات التسليم: </span>
                                    <span>{details.delivery.notes}</span>
                                  </div>
                                </div>
                              )}
                            </section>
                          )}

                          <section className="io-section">
                            <div className="io-section-head">
                              <Icon n="bag" s={15} />
                              <h3>الأصناف المطلوبة</h3>
                              <span className="badge b-neutral num ms-auto">{fmt(details.items.length)}</span>
                            </div>
                            <ul className="io-items-list">
                              {details.items.map((item) => {
                                const maxRet = Math.max(0, Number(item.returnable_qty ?? 0));
                                const sel = returnSelection[item.id];
                                return (
                                  <li key={item.id} className="io-item-row">
                                    <span className="io-item-ic" aria-hidden="true">
                                      <Icon n="bag" s={16} />
                                    </span>
                                    <div className="io-item-info">
                                      <span className="io-item-name">{item.name}</span>
                                      <bdi className="ltr mono io-item-sku">{item.sku}</bdi>
                                      {details.status === 'delivered' && (
                                        <span className="fs-11 t-3">متاح للإرجاع: {fmt(maxRet)}</span>
                                      )}
                                    </div>
                                    {returnPanelOpen && maxRet > 0 ? (
                                      <div className="row gap-2">
                                        <button
                                          type="button"
                                          className="icon-btn sm"
                                          onClick={() =>
                                            setReturnSelection((prev) => {
                                              const cur = prev[item.id]?.qty ?? 0;
                                              const nextQty = Math.max(0, cur - 1);
                                              if (nextQty === 0) {
                                                const copy = { ...prev };
                                                delete copy[item.id];
                                                return copy;
                                              }
                                              return { ...prev, [item.id]: { qty: nextQty, to: prev[item.id]?.to ?? 'on_hand' } };
                                            })
                                          }
                                        >
                                          <Icon n="minus" s={12} />
                                        </button>
                                        <b className="num fs-13">{fmt(sel?.qty ?? 0)}</b>
                                        <button
                                          type="button"
                                          className="icon-btn sm"
                                          onClick={() =>
                                            setReturnSelection((prev) => {
                                              const cur = prev[item.id]?.qty ?? 0;
                                              const nextQty = Math.min(maxRet, cur + 1);
                                              return { ...prev, [item.id]: { qty: nextQty, to: prev[item.id]?.to ?? 'on_hand' } };
                                            })
                                          }
                                        >
                                          <Icon n="plus" s={12} />
                                        </button>
                                        {(sel?.qty ?? 0) > 0 && (
                                          <select
                                            className="input"
                                            style={{ width: 92, minHeight: 30, fontSize: 11.5 }}
                                            value={sel?.to ?? 'on_hand'}
                                            onChange={(e) =>
                                              setReturnSelection((prev) => ({
                                                ...prev,
                                                [item.id]: { qty: prev[item.id]?.qty ?? 1, to: e.target.value as 'on_hand' | 'damaged' },
                                              }))
                                            }
                                          >
                                            <option value="on_hand">سليم للمخزون</option>
                                            <option value="damaged">تالف</option>
                                          </select>
                                        )}
                                      </div>
                                    ) : (
                                      <span className="io-item-qty num">× {fmt(Number(item.qty))}</span>
                                    )}
                                  </li>
                                );
                              })}
                            </ul>
                          </section>

                          {details.shipments && details.shipments.length > 0 && (
                            <section className="io-section">
                              <div className="io-section-head">
                                <Icon n="truck" s={15} />
                                <h3>الشحن والتتبع</h3>
                              </div>
                              <ul className="io-items-list">
                                {details.shipments.map((s) => (
                                  <li key={s.id} className="io-item-row">
                                    <span className="io-item-ic" aria-hidden="true">
                                      <Icon n="truck" s={16} />
                                    </span>
                                    <div className="io-item-info">
                                      <span className="io-item-name">
                                        {s.company_name || 'شركة شحن'} · <bdi className="ltr mono">{s.awb_number}</bdi>
                                      </span>
                                      <span className="fs-11 t-3">
                                        الحالة: {s.status} · تكلفة الشحن: {egp(Number(s.shipping_cost))} ج
                                      </span>
                                    </div>
                                    {s.tracking_url && (
                                      <a
                                        href={s.tracking_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="btn btn-secondary btn-sm"
                                      >
                                        تتبع الشحنة
                                      </a>
                                    )}
                                  </li>
                                ))}
                              </ul>
                            </section>
                          )}

                          {details.returns && details.returns.length > 0 && (
                            <section className="io-section">
                              <div className="io-section-head">
                                <Icon n="ret" s={15} />
                                <h3>طلبات المرتجع</h3>
                              </div>
                              <ul className="io-items-list">
                                {details.returns.map((r) => (
                                  <li key={r.id} className="io-item-row">
                                    <span className="io-item-ic" aria-hidden="true">
                                      <Icon n="ret" s={16} />
                                    </span>
                                    <div className="io-item-info">
                                      <bdi className="ltr mono io-item-name">{r.return_number}</bdi>
                                      <span className="fs-11 t-3">
                                        {r.status} · {egp(Number(r.total_refund_amount))} ج
                                      </span>
                                    </div>
                                  </li>
                                ))}
                              </ul>
                            </section>
                          )}

                          {shipPanelOpen && (
                            <div className="io-return-panel" role="region" aria-label="إنشاء بوليصة وشحن">
                              <div className="row between">
                                <span className="fw-6 fs-13">إصدار بوليصة شحن للطلب</span>
                                <button type="button" className="icon-btn sm" onClick={() => setShipPanelOpen(false)}>
                                  <Icon n="x" s={14} />
                                </button>
                              </div>
                              {loadingCompanies ? (
                                <span className="fs-12 t-3">جارٍ تحميل شركات الشحن…</span>
                              ) : shippingCompanies.length === 0 ? (
                                <span className="fs-12 t-bad">لا توجد شركات شحن نشطة مفعلة في النظام.</span>
                              ) : (
                                <>
                                  <div className="row gap-2 wrap">
                                    <select
                                      className="input"
                                      value={selectedCompanyId}
                                      onChange={(e) => setSelectedCompanyId(e.target.value)}
                                    >
                                      {shippingCompanies.map((c) => (
                                        <option key={c.id} value={String(c.id)}>
                                          {c.name} ({c.code})
                                        </option>
                                      ))}
                                    </select>
                                    <input
                                      className="input grow ltr"
                                      maxLength={100}
                                      placeholder="رقم البوليصة AWB (اختياري — يتولد تلقائيًا)"
                                      value={awbInput}
                                      onChange={(e) => setAwbInput(e.target.value)}
                                    />
                                  </div>
                                  <div className="row gap-2">
                                    <button
                                      type="button"
                                      disabled={busy || !selectedCompanyId}
                                      className="btn btn-primary btn-sm"
                                      onClick={() => void submitShipment(details)}
                                    >
                                      {busy ? 'جارٍ الإصدار…' : 'تأكيد الشحن وإصدار البوليصة'}
                                    </button>
                                    <button type="button" className="btn btn-secondary btn-sm" disabled={busy} onClick={() => setShipPanelOpen(false)}>
                                      تراجع
                                    </button>
                                  </div>
                                </>
                              )}
                            </div>
                          )}

                          {cancelOpen && (
                            <div className="io-cancel-panel" role="region" aria-label="إلغاء الطلب">
                              <div className="row between">
                                <span className="fw-6 fs-13 t-bad">تأكيد إلغاء الطلب وتحرير المخزون المحجوز</span>
                                <button type="button" className="icon-btn sm" onClick={() => setCancelOpen(false)}>
                                  <Icon n="x" s={14} />
                                </button>
                              </div>
                              <div className="pos-expense-chips">
                                {CANCEL_REASON_PRESETS.map((preset) => (
                                  <button
                                    key={preset}
                                    type="button"
                                    className="pos-expense-chip"
                                    aria-pressed={cancelReason === preset}
                                    onClick={() => setCancelReason(preset)}
                                  >
                                    {preset}
                                  </button>
                                ))}
                              </div>
                              <input
                                className="input"
                                maxLength={500}
                                placeholder="اكتب سبب إلغاء الطلب..."
                                value={cancelReason}
                                onChange={(e) => setCancelReason(e.target.value)}
                              />
                              <div className="row gap-2">
                                <button
                                  type="button"
                                  disabled={busy || cancelReason.trim().length < 3}
                                  className="btn btn-danger btn-sm"
                                  onClick={() => void submitCancel(details)}
                                >
                                  {busy ? 'جارٍ الإلغاء…' : 'تأكيد إلغاء الطلب'}
                                </button>
                                <button type="button" className="btn btn-secondary btn-sm" disabled={busy} onClick={() => setCancelOpen(false)}>
                                  تراجع
                                </button>
                              </div>
                            </div>
                          )}

                          {returnPanelOpen && (
                            <div className="io-return-panel" role="region" aria-label="استلام مرتجع بالفرع">
                              <div className="banner b-info">
                                <Icon n="info" s={15} />
                                <span className="fs-12">سيتم إضافة القطع لمخزون الفرع وتسجيل طلب المرتجع للمراجعة المالية دون خصم نقدية من الدرج.</span>
                              </div>
                              <div className="row gap-2 wrap">
                                <select
                                  className="input"
                                  value={returnReasonType}
                                  onChange={(e) => setReturnReasonType(e.target.value as 'change_of_mind' | 'defect_or_mismatch')}
                                >
                                  <option value="change_of_mind">تغيير رأي / مقاس غير مناسب</option>
                                  <option value="defect_or_mismatch">عيب أو عدم مطابقة</option>
                                </select>
                                <input
                                  className="input grow"
                                  maxLength={500}
                                  placeholder="ملاحظة إضافية للمرتجع (اختياري)"
                                  value={returnReason}
                                  onChange={(e) => setReturnReason(e.target.value)}
                                />
                              </div>
                              <div className="row gap-2">
                                <button
                                  type="button"
                                  disabled={busy || !Object.values(returnSelection).some((v) => v.qty > 0)}
                                  className="btn btn-primary btn-sm"
                                  onClick={() => void submitBranchReturn(details)}
                                >
                                  {busy ? 'جارٍ الاستلام…' : 'تأكيد استلام المرتجع بالمخزون'}
                                </button>
                                <button type="button" className="btn btn-secondary btn-sm" disabled={busy} onClick={() => setReturnPanelOpen(false)}>
                                  تراجع
                                </button>
                              </div>
                            </div>
                          )}
                        </div>

                        {(details.next_status || details.can_prepare || details.can_ship || (details.can_cancel && hasPermission(cashier?.perms, 'pos.orders.cancel')) || details.can_return || details.prepared_at || error) && (
                          <div className="io-detail-actions">
                            {details.prepared_at && (
                              <span className="badge b-ok">تم التجهيز في السلة ✓</span>
                            )}
                            {details.can_prepare && onStartCartPrep && (
                              <button
                                type="button"
                                disabled={busy || stale}
                                className="btn btn-primary btn-lg"
                                onClick={() =>
                                  onStartCartPrep({
                                    orderId: details.id,
                                    orderNumber: details.number,
                                    channel: details.channel,
                                    expectedStatus: details.status as 'pending' | 'confirmed' | 'processing',
                                    customerName: details.delivery?.name ?? null,
                                    startedAt: new Date().toISOString(),
                                    items: details.items
                                      .filter((item) => item.product_variant_id != null)
                                      .map((item) => ({
                                        orderItemId: item.id,
                                        productVariantId: item.product_variant_id!,
                                        sku: item.sku,
                                        barcode: item.barcode ?? '',
                                        name: item.name,
                                        expectedQty: Math.max(1, Math.round(Number(item.qty) || 0)),
                                        pickedQty: 0,
                                      })),
                                  })
                                }
                              >
                                <Icon n="box" s={17} />
                                <span>تجهيز في السلة</span>
                              </button>
                            )}
                            {details.can_ship && !shipPanelOpen && (
                              <button
                                type="button"
                                disabled={busy || stale}
                                className="btn btn-secondary btn-lg"
                                onClick={() => void openShipmentPanel()}
                              >
                                <Icon n="truck" s={17} />
                                <span>إنشاء بوليصة وشحن</span>
                              </button>
                            )}
                            {details.next_status && (
                              <button
                                type="button"
                                disabled={busy || stale}
                                className="btn btn-accent btn-lg io-advance-btn"
                                onClick={() => void advance(details)}
                              >
                                <Icon n={details.next_status === 'confirmed' || details.next_status === 'delivered' ? 'check' : 'box'} s={17} />
                                <span>
                                  {busy
                                    ? 'جارٍ التحديث…'
                                    : details.next_status === 'confirmed'
                                    ? 'تأكيد الطلب'
                                    : details.next_status === 'delivered'
                                    ? 'تأكيد التسليم للعميل'
                                    : 'بدء التجهيز'}
                                </span>
                              </button>
                            )}
                            {details.can_cancel && hasPermission(cashier?.perms, 'pos.orders.cancel') && !cancelOpen && (
                              <button
                                type="button"
                                disabled={busy || stale}
                                className="btn btn-danger btn-lg"
                                onClick={() => setCancelOpen(true)}
                              >
                                <Icon n="x" s={16} />
                                <span>إلغاء الطلب</span>
                              </button>
                            )}
                            {details.can_return && !returnPanelOpen && (
                              <button
                                type="button"
                                disabled={busy}
                                className="btn btn-accent btn-lg io-advance-btn"
                                onClick={() => {
                                  setReturnPanelOpen(true);
                                  const init: Record<number, { qty: number; to: 'on_hand' | 'damaged' }> = {};
                                  for (const item of details.items) {
                                    const maxRet = Math.max(0, Number(item.returnable_qty ?? 0));
                                    if (maxRet > 0) init[item.id] = { qty: 0, to: 'on_hand' };
                                  }
                                  setReturnSelection(init);
                                }}
                              >
                                <Icon n="ret" s={17} />
                                <span>استلام مرتجع بالفرع</span>
                              </button>
                            )}
                            {error && (
                              <button
                                type="button"
                                disabled={busy}
                                className="btn btn-secondary"
                                onClick={() => void loadOrder(details.id)}
                              >
                                <Icon n="refresh" s={15} />
                                تحديث حالة الطلب
                              </button>
                            )}
                          </div>
                        )}
                      </>
                    )}
                  </section>
                </div>
              </div>
            </div>
          </div>
        </div>,
        document.body,
      )}
    </>
  );
}

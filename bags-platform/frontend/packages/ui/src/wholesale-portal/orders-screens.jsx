import React, { useState, useEffect, useMemo, useRef } from 'react';
import { useApp } from './context';
import { wholesaleEndpoints, accountEndpoints, defaultApiClient } from '@alfarid/api-client';
import { useApi } from './data/useApi';
import { DataBoundary } from './data/DataBoundary';
import { apiError, pageMeta, saveBlob, today, addQuoteLine } from './data/helpers';
import { mapOrder, mapQuote, mapReturn } from './data/mappers';
import { validateReturnFiles } from './data/validation';
import { ORDER_STATUS, PAY_METHOD, PAY_STATUS, QUOTE_STATUS, RETURN_STATUS, REFUND_METHOD } from './data/constants';
import { fmt, Money } from './data/format';
import {
  Badge, PageHead, Crumb, Panel, SkeletonRows, Empty,
  Async, Pager, Field, Modal, Banner, Thumb, Qty
} from './ui-primitives';

/* Screens 13–17 + 22: orders, order detail, quotations, returns. */

const REORDER_SKIP_LABEL = { not_found_or_inactive: 'الصنف غير موجود أو غير متاح', insufficient_stock: 'الكمية غير متاحة' };

export const ReorderModal = ({ orderId, onClose }) => {
  const { go, applyCartResponse } = useApp();
  const [busy, setBusy] = useState(true);
  const [report, setReport] = useState(null);
  const [err, setErr] = useState('');
  const request = React.useRef(null);
  useEffect(() => {
    let active = true;
    setBusy(true);
    setErr('');
    setReport(null);
    // StrictMode replays effects; re-use the same mutation rather than adding twice.
    if (request.current?.id !== orderId) request.current = {
      id: orderId,
      promise: wholesaleEndpoints.reorder(orderId).then((r) => { applyCartResponse(r.cart); return r; }),
    };
    request.current.promise
      .then((r) => { if (active) { setReport(r); setBusy(false); } })
      .catch((e) => { if (active) { setErr(apiError(e).message); setBusy(false); } });
    return () => { active = false; };
  }, [orderId, applyCartResponse]);
  return (
    <Modal title="إعادة الطلب" onClose={onClose} icon={<div className="modal-icon"><i className="fa-solid fa-rotate" /></div>}
      foot={<><button className="btn-pill" onClick={onClose}>إغلاق</button><button className="btn-pill is-dark" disabled={busy || !report} onClick={() => go('cart')}><i className="fa-solid fa-bag-shopping" /> فتح السلة</button></>}>
      <Banner kind="info">تمت إضافة البنود المتاحة للسلة <b>بأسعار اليوم</b>، مش بأسعار الطلب القديم.</Banner>
      <div className="mt-16">
        {busy && <SkeletonRows rows={3} cols={3} />}
        {err && <p className="f-error">{err}</p>}
        {report && (
          <>
            {(report.added || []).map((a, i) => (
              <div key={`a-${i}`} className="sum-row"><span><b style={{ color: 'var(--ink)' }}>{a.sku}</b></span>
                <span className="badge success">أضيف <span className="num">×{a.qty}</span></span></div>))}
            {(report.skipped || []).map((s, i) => (
              <div key={`s-${i}`} className="sum-row"><span><b style={{ color: 'var(--ink)' }}>{s.sku}</b></span>
                <span className="badge danger">تم تخطيه · {REORDER_SKIP_LABEL[s.reason] || s.reason}</span></div>))}
            {!report.added?.length && !report.skipped?.length && <p className="muted">لا توجد بنود قابلة للإضافة.</p>}
          </>
        )}
      </div>
    </Modal>
  );
};

export const CancelModal = ({ order, onClose, onDone }) => {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const confirm = async () => {
    setBusy(true);
    setErr('');
    try {
      await accountEndpoints.cancelOrder(order.id);
      onDone();
    } catch (e) {
      setErr(apiError(e).message);
      setBusy(false);
    }
  };
  return (
    <Modal title={`إلغاء الطلب ${order.number}؟`} onClose={onClose} icon={<div className="modal-icon is-err"><i className="fa-solid fa-ban" /></div>}
      foot={<><button className="btn-pill" onClick={onClose}>تراجع</button><button className="btn-pill is-danger" disabled={busy} onClick={confirm}>{busy ? <span className="spin" /> : null} تأكيد الإلغاء</button></>}>
      {order.method === 'credit' && <p className="muted" style={{ lineHeight: 1.9 }}>هيتم إلغاء الطلب وتحرير مبلغ <b className="num" style={{ color: 'var(--ink)' }}>{fmt(order.total)} EGP</b> من الائتمان المستخدم. الإلغاء متاح طالما الطلب لم يُشحن.</p>}
      {order.method !== 'credit' && <p className="muted" style={{ lineHeight: 1.9 }}>هيتم إلغاء الطلب. الإلغاء متاح طالما الطلب لم يُشحن.</p>}
      {err && <p className="f-error" style={{ marginTop: 8 }}>{err}</p>}
    </Modal>
  );
};

/* ---------- 13. Orders ---------- */
const GROUPS = { all: null, active: 'active', delivered: 'delivered', cancelled: 'cancelled' };

const OrderMiniTrack = ({ track = [], status }) => {
  if (!track.length) return null;
  if (status === 'cancelled' || status === 'returned') {
    return (
      <div className="ord-mini-tl is-stopped">
        <span className={`badge ${ORDER_STATUS[status]?.[1] || 'default'}`}>
          {ORDER_STATUS[status]?.[0] || status}
        </span>
      </div>
    );
  }
  return (
    <ol className="ord-mini-tl" aria-label="تتبع مراحل الطلب">
      {track.map(([key, , state]) => (
        <li key={key} className={`is-${state}`} title={ORDER_STATUS[key]?.[0] || key}>
          <span className="ord-mini-dot" />
          <span className="ord-mini-lbl">{ORDER_STATUS[key]?.[0] || key}</span>
        </li>
      ))}
    </ol>
  );
};

const OrderInspector = ({ order, onClose, onOpenFull, onReorder, onCancel, onPayNotice, onReturn, isSheet = false }) => {
  const needsDetail = Boolean(order && (!order.items?.length || !order.recipient));
  const detailApi = useApi(
    (signal) => wholesaleEndpoints.order(order.id, signal).then(mapOrder),
    [order?.id],
    needsDetail,
  );
  const full = (!needsDetail ? order : detailApi.data) || order;
  const items = full?.items || [];

  if (!order) {
    return (
      <aside className="panel ord-inspector is-empty" aria-label="معاينة الطلب">
        <div className="ord-insp-head">
          <div className="ord-insp-eyebrow">ORDER PREVIEW</div>
          <button
            type="button"
            className="wp-icon-btn"
            onClick={onClose}
            title="إغلاق المعاينة"
            aria-label="إغلاق المعاينة"
          >
            <i className="fa-solid fa-xmark" />
          </button>
        </div>
        <div className="state" style={{ padding: '44px 20px' }}>
          <div className="state-icon"><i className="fa-solid fa-box-open" /></div>
          <p className="state-title" style={{ fontSize: 16 }}>اختر طلباً للمعاينة</p>
          <p className="state-desc">اضغط على أي صف من الجدول لعرض البنود، التتبع، وتفاصيل الشحن فوراً.</p>
        </div>
      </aside>
    );
  }

  return (
    <aside className={`panel ord-inspector${isSheet ? ' is-sheet' : ''}`} aria-label={`معاينة الطلب ${full.number}`}>
      <div className="ord-insp-head">
        <div>
          {!isSheet && <div className="ord-insp-eyebrow">ORDER PREVIEW</div>}
          <div className="ord-insp-num num">{full.number}</div>
          <div className="ord-insp-date">بتاريخ <span className="num">{full.date}</span></div>
        </div>
        <div className="ord-insp-head-actions">
          {!isSheet && (
            <button
              type="button"
              className="wp-icon-btn"
              onClick={() => onOpenFull(full.id)}
              title="فتح صفحة تفاصيل الطلب"
              aria-label="فتح صفحة تفاصيل الطلب"
            >
              <i className="fa-solid fa-arrow-up-right-from-square" />
            </button>
          )}
          <button
            type="button"
            className="wp-icon-btn"
            onClick={onClose}
            title="إغلاق المعاينة"
            aria-label="إغلاق المعاينة"
          >
            <i className="fa-solid fa-xmark" />
          </button>
        </div>
      </div>

      <div className="ord-insp-scroll">
        <div className="ord-insp-hero">
        <div className="ord-insp-hero-total">
          <span>إجمالي الطلب</span>
          <strong className="num">{fmt(full.total)} <small>EGP</small></strong>
        </div>
        <div className="ord-insp-hero-meta">
          {full.method && <span className="ord-method-pill">{PAY_METHOD[full.method] || full.method}</span>}
          {items.length > 0 && <span className="ord-method-pill num">{items.length} صنف</span>}
        </div>
      </div>

      <div className="ord-insp-section">
        <div className="ord-insp-sec-head">
          <span>مراحل التنفيذ</span>
          <span className="panel-en">TRACKING</span>
        </div>
        <OrderMiniTrack track={full.track} status={full.status} />
      </div>

      <div className="ord-insp-section">
        <div className="ord-insp-sec-head">
          <span>البنود {items.length ? <span className="num">({items.length})</span> : ''}</span>
          <span className="panel-en">ITEMS</span>
        </div>
        {needsDetail && detailApi.loading ? (
          <SkeletonRows rows={3} cols={2} />
        ) : items.length ? (
          <ul className="ord-insp-items">
            {items.slice(0, 5).map((it, idx) => (
              <li key={it.id || it.sku || idx} className="ord-insp-item">
                <Thumb sm />
                <div className="ord-insp-item-info">
                  <div className="ord-insp-item-name">{it.name}</div>
                  <div className="ord-insp-item-meta">
                    {it.sku && <span className="sku">{it.sku}</span>}
                    <span className="num">{it.qty} × {fmt(it.unit)}</span>
                  </div>
                </div>
                <strong className="num">{fmt(it.line_total)}</strong>
              </li>
            ))}
            {items.length > 5 && (
              <li className="ord-insp-more">
                <button type="button" className="btn-link" onClick={() => onOpenFull(full.id)}>
                  +{items.length - 5} بنود أخرى في صفحة التفاصيل
                </button>
              </li>
            )}
          </ul>
        ) : (
          <p className="muted small" style={{ margin: 0 }}>اضغط &quot;عرض التفاصيل الكاملة&quot; لعرض بنود الطلب.</p>
        )}
      </div>

      <div className="ord-insp-section">
        <div className="ord-insp-sec-head">
          <span>تفصيل المبالغ</span>
          <span className="panel-en">BREAKDOWN</span>
        </div>
        <div className="ord-insp-sums">
          <div className="ord-insp-sum-row"><span>المنتجات</span><span className="num">{fmt(full.subtotal)}</span></div>
          {Boolean(full.discount) && (
            <div className="ord-insp-sum-row is-neg"><span>الخصم</span><span className="num">− {fmt(full.discount)}</span></div>
          )}
          <div className="ord-insp-sum-row"><span>الشحن</span><span className="num">{fmt(full.shipping)}</span></div>
        </div>
      </div>

      {(full.recipient || full.city) && (
        <div className="ord-insp-section">
          <div className="ord-insp-sec-head">
            <span>التوصيل</span>
            <span className="panel-en">DELIVERY</span>
          </div>
          <div className="ord-insp-delivery">
            {full.recipient && <div><strong>{full.recipient}</strong></div>}
            <div className="muted small">
              {[full.city, full.address].filter(Boolean).join(' · ')}
            </div>
            {full.phone && <div className="num small muted">{full.phone}</div>}
          </div>
        </div>
      )}

      </div>

      <div className="ord-insp-foot">
        <button type="button" className="btn-pill is-dark ord-insp-primary" onClick={() => onOpenFull(full.id)}>
          <i className="fa-solid fa-eye" /> عرض التفاصيل الكاملة
        </button>
        <div className="ord-insp-secondary">
          <button type="button" className="btn-pill sm" onClick={() => onReorder(full.id)}>
            <i className="fa-solid fa-rotate" /> إعادة الطلب
          </button>
          {full.pay === 'unpaid' && full.method === 'credit' && (
            <button type="button" className="btn-pill sm" onClick={() => onPayNotice(full.id)}>
              <i className="fa-solid fa-money-check-dollar" /> إشعار سداد
            </button>
          )}
          {full.returnable && (
            <button type="button" className="btn-pill sm" onClick={() => onReturn(full.id)}>
              <i className="fa-solid fa-rotate-left" /> إرجاع
            </button>
          )}
          {full.cancellable && (
            <button type="button" className="btn-pill sm is-danger" onClick={() => onCancel(full)}>
              <i className="fa-solid fa-ban" /> إلغاء
            </button>
          )}
        </div>
        {!isSheet && (
          <div className="ord-kbd-hint" aria-hidden="true">
            <span><kbd>↑</kbd> <kbd>↓</kbd> للتنقل</span>
            <span><kbd>Enter</kbd> للتفاصيل</span>
            <span><kbd>/</kbd> للبحث</span>
          </div>
        )}
      </div>
    </aside>
  );
};

export const Orders = () => {
  const { go, toast, refreshAccount, account } = useApp();
  const [f, setF] = useState('all');
  const [payFilter, setPayFilter] = useState('all');
  const [sortBy, setSortBy] = useState('newest');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState(null);
  const [mobilePreviewId, setMobilePreviewId] = useState(null);
  const [inspectorOpen, setInspectorOpen] = useState(() => {
    if (typeof window === 'undefined') return true;
    try { return window.localStorage.getItem('varya_orders_inspector') !== '0'; } catch { return true; }
  });
  const [re, setRe] = useState(null);
  const [cancel, setCancel] = useState(null);
  const searchRef = useRef(null);

  const toggleInspector = (next) => {
    setInspectorOpen((prev) => {
      const val = typeof next === 'boolean' ? next : !prev;
      try { window.localStorage.setItem('varya_orders_inspector', val ? '1' : '0'); } catch {}
      return val;
    });
  };

  useEffect(() => {
    const onGlobalKey = (e) => {
      if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
      const tag = document.activeElement?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || document.activeElement?.isContentEditable) return;
      if (searchRef.current) {
        e.preventDefault();
        searchRef.current.focus();
      }
    };
    window.addEventListener('keydown', onGlobalKey);
    return () => window.removeEventListener('keydown', onGlobalKey);
  }, []);

  const api = useApi((signal) => wholesaleEndpoints
    .orders({ group: GROUPS[f] || undefined, page }, signal)
    .then((r) => ({ items: (r.data || []).map(mapOrder), meta: pageMeta(r.meta) })), [f, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 15 };

  const filtered = useMemo(() => {
    const query = q.trim().toLowerCase();
    let rows = list.filter((o) => {
      if (payFilter !== 'all' && o.pay !== payFilter) return false;
      if (!query) return true;
      const haystack = [
        o.number,
        o.recipient,
        o.city,
        o.date,
        ...(o.items || []).flatMap((it) => [it.name, it.sku]),
      ].filter(Boolean).join(' ').toLowerCase();
      return haystack.includes(query);
    });
    if (sortBy === 'oldest') {
      rows = [...rows].sort((a, b) => String(a.date || '').localeCompare(String(b.date || '')) || a.id - b.id);
    } else if (sortBy === 'highest') {
      rows = [...rows].sort((a, b) => (b.total ?? 0) - (a.total ?? 0));
    } else if (sortBy === 'lowest') {
      rows = [...rows].sort((a, b) => (a.total ?? 0) - (b.total ?? 0));
    }
    return rows;
  }, [list, q, payFilter, sortBy]);

  useEffect(() => {
    if (!filtered.length) {
      setSelectedId(null);
      return;
    }
    if (!filtered.some((o) => o.id === selectedId)) {
      setSelectedId(filtered[0].id);
    }
  }, [filtered, selectedId]);

  const selectedOrder = useMemo(
    () => filtered.find((o) => o.id === selectedId) || null,
    [filtered, selectedId],
  );

  const mobilePreviewOrder = useMemo(
    () => (mobilePreviewId == null ? null : filtered.find((o) => o.id === mobilePreviewId) || list.find((o) => o.id === mobilePreviewId) || null),
    [filtered, list, mobilePreviewId],
  );

  useEffect(() => {
    if (!mobilePreviewOrder) return;
    const onEsc = (e) => {
      if (e.key === 'Escape') setMobilePreviewId(null);
    };
    window.addEventListener('keydown', onEsc);
    return () => window.removeEventListener('keydown', onEsc);
  }, [mobilePreviewOrder]);

  const pageVolume = useMemo(
    () => filtered.reduce((sum, o) => sum + (o.total ?? 0), 0),
    [filtered],
  );
  const unpaidInView = useMemo(
    () => filtered.filter((o) => o.pay === 'unpaid' || o.pay === 'partial'),
    [filtered],
  );
  const unpaidTotal = useMemo(
    () => unpaidInView.reduce((sum, o) => sum + (o.total ?? 0), 0),
    [unpaidInView],
  );

  const hasLocalFilter = Boolean(q.trim() || payFilter !== 'all' || sortBy !== 'newest');
  const clearLocalFilters = () => {
    setQ('');
    setPayFilter('all');
    setSortBy('newest');
  };

  const onCancelled = () => {
    setCancel(null);
    refreshAccount();
    api.reload();
    toast('تم إلغاء الطلب');
  };

  const handleRowClick = (o) => {
    if (typeof window !== 'undefined' && window.innerWidth >= 1100) {
      if (inspectorOpen && selectedId === o.id) {
        toggleInspector(false);
      } else {
        setSelectedId(o.id);
        if (!inspectorOpen) toggleInspector(true);
      }
    } else {
      go('order', o.id);
    }
  };

  const handleRowKeyDown = (e, o, idx) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      go('order', o.id);
    } else if (e.key === 'Escape' && inspectorOpen) {
      e.preventDefault();
      toggleInspector(false);
    } else if (e.key === 'ArrowDown' && idx < filtered.length - 1) {
      e.preventDefault();
      setSelectedId(filtered[idx + 1].id);
    } else if (e.key === 'ArrowUp' && idx > 0) {
      e.preventDefault();
      setSelectedId(filtered[idx - 1].id);
    }
  };

  return (
    <>
      <PageHead en="My Orders" ar="كل طلبات حساب الجملة">
        <button type="button" className="btn-pill" onClick={() => go('quick')}>
          <i className="fa-solid fa-bolt" /> طلب سريع
        </button>
        <button type="button" className="btn-pill is-dark" onClick={() => go('catalog')}>
          <i className="fa-solid fa-plus" /> طلب جديد
        </button>
      </PageHead>

      <div className="ord-summary-bar" role="region" aria-label="ملخص الطلبات">
        <div className="ord-stat">
          <span className="ord-stat-lbl">إجمالي الطلبات</span>
          <strong className="ord-stat-val num">{meta.total}</strong>
        </div>
        <button
          type="button"
          className={`ord-stat is-btn${f === 'active' ? ' is-on' : ''}`}
          onClick={() => { setF(f === 'active' ? 'all' : 'active'); setPage(1); }}
        >
          <span className="ord-stat-dot is-info" />
          <span className="ord-stat-lbl">جاري تنفيذها</span>
          <strong className="ord-stat-val num">{account?.in_progress_orders_count ?? 0}</strong>
        </button>
        <div className="ord-stat">
          <span className="ord-stat-lbl">قيمة المعروض</span>
          <strong className="ord-stat-val num">{fmt(pageVolume)} <small>EGP</small></strong>
        </div>
        {(unpaidInView.length > 0 || payFilter === 'unpaid') && (
          <button
            type="button"
            className={`ord-stat is-btn${payFilter === 'unpaid' ? ' is-on' : ''}`}
            onClick={() => setPayFilter(payFilter === 'unpaid' ? 'all' : 'unpaid')}
          >
            <span className="ord-stat-dot is-warn" />
            <span className="ord-stat-lbl">غير مسدد بالصفحة</span>
            <strong className="ord-stat-val num">{fmt(unpaidTotal)} <small>EGP</small></strong>
            {unpaidInView.length > 0 && <span className="ord-stat-count num">{unpaidInView.length}</span>}
          </button>
        )}
      </div>

      <div className={`ord-workspace${inspectorOpen ? '' : ' is-collapsed'}`}>
        <section className="panel ord-list-panel">
          <div className="toolbar ord-toolbar">
            <div className="ord-toolbar-row">
              <div className="chips ord-chips">
                {[['all', 'الكل'], ['active', 'جاري التنفيذ'], ['delivered', 'تم التسليم'], ['cancelled', 'ملغي']].map(([k, l]) => (
                  <button
                    key={k}
                    type="button"
                    className={`chip${f === k ? ' is-on' : ''}`}
                    onClick={() => { setF(k); setPage(1); }}
                  >
                    {l}
                  </button>
                ))}
              </div>
              <div className="ord-toolbar-meta">
                {hasLocalFilter && (
                  <button type="button" className="btn-link muted small" onClick={clearLocalFilters}>
                    مسح الفلاتر ({filtered.length}/{list.length})
                  </button>
                )}
                <button
                  type="button"
                  className={`ord-insp-toggle${inspectorOpen ? ' is-on' : ''}`}
                  onClick={() => toggleInspector()}
                  title={inspectorOpen ? 'إخفاء المعاينة الجانبية' : 'إظهار المعاينة الجانبية'}
                >
                  <i className="fa-solid fa-table-columns" aria-hidden="true" />
                  <span>{inspectorOpen ? 'إخفاء المعاينة' : 'معاينة سريعة'}</span>
                </button>
              </div>
            </div>
            <div className="ord-controls">
              <div className="ord-search-box">
                <i className="fa-solid fa-magnifying-glass" aria-hidden="true" />
                <input
                  ref={searchRef}
                  type="search"
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                      if (q) setQ('');
                      else e.currentTarget.blur();
                    }
                  }}
                  placeholder="ابحث برقم الطلب، المستلم، المدينة، SKU..."
                  aria-label="بحث في الطلبات"
                />
                {q ? (
                  <button
                    type="button"
                    className="ord-search-clear"
                    onClick={() => setQ('')}
                    aria-label="مسح البحث"
                  >
                    <i className="fa-solid fa-xmark" />
                  </button>
                ) : (
                  <kbd className="ord-search-kbd" aria-hidden="true">/</kbd>
                )}
              </div>
              <select
                className="ord-select"
                value={payFilter}
                onChange={(e) => setPayFilter(e.target.value)}
                aria-label="تصفية حسب حالة السداد"
              >
                <option value="all">كل حالات السداد</option>
                <option value="paid">مدفوع</option>
                <option value="unpaid">غير مدفوع</option>
                <option value="partial">مدفوع جزئياً</option>
                <option value="pending">في انتظار التحصيل</option>
              </select>
              <select
                className="ord-select"
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value)}
                aria-label="ترتيب الطلبات"
              >
                <option value="newest">الأحدث أولاً</option>
                <option value="oldest">الأقدم أولاً</option>
                <option value="highest">الأعلى قيمة</option>
                <option value="lowest">الأقل قيمة</option>
              </select>
            </div>
          </div>

          <DataBoundary api={api}>
            <Async
              rows={6}
              cols={6}
              isEmpty={!list.length}
              empty={
                <Empty
                  icon="fa-box-open"
                  title="مفيش طلبات"
                  desc="لما تعمل أول طلب هيظهر هنا."
                  action={<button className="btn-pill is-dark" onClick={() => go('catalog')}>تصفح الكتالوج</button>}
                />
              }
            >
              {!filtered.length ? (
                <Empty
                  icon="fa-filter-circle-xmark"
                  title="لا توجد طلبات مطابقة للبحث"
                  desc="جرّب تغيير كلمات البحث أو إزالة الفلاتر لعرض طلبات هذه الصفحة."
                  action={<button type="button" className="btn-pill is-dark" onClick={clearLocalFilters}>مسح الفلاتر</button>}
                />
              ) : (
                <>
                  <div className="tbl-wrap ord-tbl-wrap">
                    <table className="tbl ord-tbl">
                      <thead>
                        <tr>
                          <th>الطلب</th>
                          <th>التاريخ</th>
                          <th>حالة التنفيذ</th>
                          <th>السداد</th>
                          <th className="t-end">الإجمالي</th>
                          <th aria-label="إجراءات"></th>
                        </tr>
                      </thead>
                      <tbody>
                        {filtered.map((o, idx) => {
                          const isSel = inspectorOpen && selectedOrder?.id === o.id;
                          const subMeta = [
                            o.items?.length ? `${o.items.length} صنف` : null,
                            o.city || o.recipient,
                          ].filter(Boolean).join(' · ');
                          return (
                            <tr
                              key={o.id}
                              tabIndex={0}
                              aria-selected={isSel}
                              className={`is-click${isSel ? ' is-selected' : ''}`}
                              onClick={() => handleRowClick(o)}
                              onDoubleClick={() => go('order', o.id)}
                              onKeyDown={(e) => handleRowKeyDown(e, o, idx)}
                            >
                              <td>
                                <div className="strong num">{o.number}</div>
                                {subMeta && <div className="ord-row-sub">{subMeta}</div>}
                              </td>
                              <td className="num ord-date-cell">{o.date}</td>
                              <td><Badge map={ORDER_STATUS} k={o.status} /></td>
                              <td>
                                <div className="ord-pay-cell">
                                  <Badge map={PAY_STATUS} k={o.pay} />
                                  <span className="ord-pay-method">{PAY_METHOD[o.method] || o.method}</span>
                                </div>
                              </td>
                              <td className="t-end strong num">{fmt(o.total)} <span className="ord-curr">EGP</span></td>
                              <td onClick={(e) => e.stopPropagation()}>
                                <div className="ord-row-icons">
                                  {!inspectorOpen && (
                                    <button
                                      type="button"
                                      className="ord-row-btn"
                                      onClick={() => { setSelectedId(o.id); toggleInspector(true); }}
                                      title="معاينة سريعة"
                                      aria-label={`معاينة الطلب ${o.number}`}
                                    >
                                      <i className="fa-solid fa-eye" />
                                    </button>
                                  )}
                                  <button
                                    type="button"
                                    className="ord-row-btn"
                                    onClick={() => setRe(o.id)}
                                    title="إعادة الطلب"
                                    aria-label={`إعادة الطلب ${o.number}`}
                                  >
                                    <i className="fa-solid fa-rotate" />
                                  </button>
                                  <button
                                    type="button"
                                    className="ord-row-btn"
                                    onClick={() => go('order', o.id)}
                                    title="عرض التفاصيل الكاملة"
                                    aria-label={`عرض تفاصيل ${o.number}`}
                                  >
                                    <i className="fa-solid fa-chevron-left" />
                                  </button>
                                </div>
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                  <div className="ord-mobile-list">
                    {filtered.map((o) => {
                      const subMeta = [
                        o.items?.length ? `${o.items.length} صنف` : null,
                        o.city || o.recipient,
                      ].filter(Boolean).join(' · ');
                      return (
                        <article
                          key={o.id}
                          className="ord-m-card"
                          onClick={() => setMobilePreviewId(o.id)}
                          onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setMobilePreviewId(o.id); } }}
                          tabIndex={0}
                          role="button"
                          aria-label={`معاينة الطلب ${o.number}`}
                        >
                          <div className="ord-m-head">
                            <div className="ord-m-id">
                              <span className="ord-m-num num">{o.number}</span>
                              <span className="ord-m-date num">
                                {o.date}{subMeta ? ` · ${subMeta}` : ''}
                              </span>
                            </div>
                            <div className="ord-m-head-end">
                              <Badge map={ORDER_STATUS} k={o.status} />
                              <i className="fa-solid fa-chevron-left ord-m-chev" aria-hidden="true" />
                            </div>
                          </div>
                          <div className="ord-m-mid">
                            <div className="ord-m-pay">
                              <span className="ord-m-method">{PAY_METHOD[o.method] || o.method}</span>
                              <Badge map={PAY_STATUS} k={o.pay} />
                            </div>
                            <div className="ord-m-total">
                              <span className="ord-m-total-lbl">الإجمالي</span>
                              <strong className="num">{fmt(o.total)} <small>EGP</small></strong>
                            </div>
                          </div>
                          <div className="ord-m-actions" onClick={(e) => e.stopPropagation()}>
                            <button type="button" className="btn-pill is-dark ord-m-btn" onClick={() => setRe(o.id)}>
                              <i className="fa-solid fa-rotate" aria-hidden="true" /> إعادة الطلب
                            </button>
                            {o.cancellable && (
                              <button type="button" className="btn-pill is-danger ord-m-btn ord-m-cancel" onClick={() => setCancel(o)}>
                                إلغاء الطلب
                              </button>
                            )}
                          </div>
                        </article>
                      );
                    })}
                  </div>
                </>
              )}
              <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
            </Async>
          </DataBoundary>
        </section>

        <div className="ord-inspector-slot" aria-hidden={!inspectorOpen}>
          <OrderInspector
            key={selectedOrder?.id || 'empty'}
            order={selectedOrder}
            onClose={() => toggleInspector(false)}
            onOpenFull={(id) => go('order', id)}
            onReorder={(id) => setRe(id)}
            onCancel={(o) => setCancel(o)}
            onPayNotice={(id) => go('noticeNew', id)}
            onReturn={(id) => go('returnNew', id)}
          />
        </div>
      </div>

      {mobilePreviewOrder && (
        <div
          className="ord-sheet-bg"
          onMouseDown={(e) => e.target === e.currentTarget && setMobilePreviewId(null)}
        >
          <div className="ord-sheet" role="dialog" aria-modal="true" aria-label={`معاينة الطلب ${mobilePreviewOrder.number}`}>
            <div className="ord-sheet-handle" aria-hidden="true" />
            <OrderInspector
              order={mobilePreviewOrder}
              isSheet
              onClose={() => setMobilePreviewId(null)}
              onOpenFull={(id) => { setMobilePreviewId(null); go('order', id); }}
              onReorder={(id) => { setMobilePreviewId(null); setRe(id); }}
              onCancel={(o) => { setMobilePreviewId(null); setCancel(o); }}
              onPayNotice={(id) => { setMobilePreviewId(null); go('noticeNew', id); }}
              onReturn={(id) => { setMobilePreviewId(null); go('returnNew', id); }}
            />
          </div>
        </div>
      )}

      {re && <ReorderModal orderId={re} onClose={() => setRe(null)} />}
      {cancel && <CancelModal order={cancel} onClose={() => setCancel(null)} onDone={onCancelled} />}
    </>
  );
};

/* ---------- 14. Order detail + tracking ---------- */
export const OrderDetail = ({ id }) => {
  const { go, refreshAccount, toast, account } = useApp();
  const [re, setRe] = useState(false);
  const [cancel, setCancel] = useState(false);

  const api = useApi((signal) => wholesaleEndpoints.order(id, signal).then(mapOrder), [id]);
  const o = api.data;

  const onCancelled = () => {
    setCancel(false);
    refreshAccount();
    api.reload();
  };

  const totalUnits = useMemo(
    () => (o?.items || []).reduce((sum, it) => sum + (it.qty ?? 0), 0),
    [o],
  );

  const copyOrderNumber = async () => {
    if (!o?.number) return;
    try {
      await navigator.clipboard.writeText(o.number);
      toast('تم نسخ رقم الطلب');
    } catch {
      toast(`رقم الطلب: ${o.number}`);
    }
  };

  const isStopped = o?.status === 'cancelled' || o?.status === 'returned';

  return (
    <div className="ord-detail-page">
      <PageHead
        en={o?.number || ''}
        crumb={<Crumb items={[{ label: 'طلباتي', go: () => go('orders') }, { label: o?.number || '', mono: true }]} />}
        ar={o ? (
          <span className="ord-detail-sub">
            <span>تاريخ الطلب <strong className="num">{o.date}</strong></span>
            {o.items?.length > 0 && (
              <>
                <span className="ord-detail-dot" aria-hidden="true" />
                <span><strong className="num">{o.items.length}</strong> صنف · <strong className="num">{totalUnits}</strong> قطعة</span>
              </>
            )}
          </span>
        ) : null}
      >
        {o && <Badge map={ORDER_STATUS} k={o.status} lg />}
        {o && (
          <button type="button" className="btn-pill" onClick={copyOrderNumber} title="نسخ رقم الطلب">
            <i className="fa-regular fa-copy" aria-hidden="true" /> نسخ الرقم
          </button>
        )}
        {o && (
          <button type="button" className="btn-pill" onClick={() => window.print()}>
            <i className="fa-solid fa-print" aria-hidden="true" /> طباعة
          </button>
        )}
        {o && <button type="button" className="btn-pill is-dark" onClick={() => setRe(true)}><i className="fa-solid fa-rotate" /> إعادة الطلب</button>}
        {o?.returnable && <button type="button" className="btn-pill" onClick={() => go('returnNew', o.id)}><i className="fa-solid fa-rotate-left" /> طلب إرجاع</button>}
        {o?.cancellable && <button type="button" className="btn-pill is-danger" onClick={() => setCancel(true)}>إلغاء الطلب</button>}
      </PageHead>

      <DataBoundary api={api}>
        <Async rows={5} cols={4} empty={<Empty icon="fa-box-open" title="الطلب غير موجود" />}>
          {o && (
            <>
              <div className="ord-detail-m-top">
                <section className="ord-detail-m-hero" aria-label="ملخص الطلب">
                  <div className="ord-detail-m-hero-head">
                    <div>
                      <div className="ord-detail-m-hero-lbl">إجمالي الطلب</div>
                      <div className="ord-detail-m-hero-val num">
                        {fmt(o.total)} <small>EGP</small>
                      </div>
                    </div>
                    <Badge map={ORDER_STATUS} k={o.status} />
                  </div>
                  <div className="ord-detail-m-hero-meta">
                    {o.method && <span className="ord-detail-m-pill">{PAY_METHOD[o.method] || o.method}</span>}
                    {o.pay && <span className="ord-detail-m-pill">{PAY_STATUS[o.pay]?.[0] || o.pay}</span>}
                    <span className="ord-detail-m-pill num">{o.items.length} صنف · {totalUnits} قطعة</span>
                  </div>
                </section>

                <div className="ord-detail-m-actions">
                  <button type="button" className="btn-pill is-dark ord-detail-m-act-main" onClick={() => setRe(true)}>
                    <i className="fa-solid fa-rotate" aria-hidden="true" /> إعادة الطلب
                  </button>
                  <button type="button" className="btn-pill ord-detail-m-act-ic" onClick={copyOrderNumber} title="نسخ رقم الطلب" aria-label="نسخ رقم الطلب">
                    <i className="fa-regular fa-copy" aria-hidden="true" />
                  </button>
                  <button type="button" className="btn-pill ord-detail-m-act-ic" onClick={() => window.print()} title="طباعة" aria-label="طباعة">
                    <i className="fa-solid fa-print" aria-hidden="true" />
                  </button>
                  {o.pay === 'unpaid' && o.method === 'credit' && (
                    <button type="button" className="btn-pill ord-detail-m-act-sec" onClick={() => go('noticeNew', o.id)}>
                      <i className="fa-solid fa-money-check-dollar" aria-hidden="true" /> إشعار سداد
                    </button>
                  )}
                  {o.returnable && (
                    <button type="button" className="btn-pill ord-detail-m-act-sec" onClick={() => go('returnNew', o.id)}>
                      <i className="fa-solid fa-rotate-left" aria-hidden="true" /> طلب إرجاع
                    </button>
                  )}
                  {o.cancellable && (
                    <button type="button" className="btn-pill is-danger ord-detail-m-act-sec" onClick={() => setCancel(true)}>
                      <i className="fa-solid fa-ban" aria-hidden="true" /> إلغاء الطلب
                    </button>
                  )}
                </div>
              </div>

              {Boolean(o.track?.length) && !isStopped && (
                <section className="panel ord-detail-stepper-panel" aria-label="مراحل تنفيذ الطلب">
                  <ol className="ord-detail-stepper">
                    {o.track.map(([s, t, st]) => (
                      <li key={s} className={`is-${st}`}>
                        <div className="ord-detail-step-node">
                          <span className="ord-detail-step-dot">
                            {st === 'done' && <i className="fa-solid fa-check" aria-hidden="true" />}
                          </span>
                        </div>
                        <div className="ord-detail-step-copy">
                          <span className="ord-detail-step-title">{ORDER_STATUS[s] ? ORDER_STATUS[s][0] : s}</span>
                          <span className="ord-detail-step-time num">{t || '—'}</span>
                        </div>
                      </li>
                    ))}
                  </ol>
                </section>
              )}

              <div className="ord-detail-grid">
                <div className="stack">
                  <Panel
                    title="البنود"
                    en="ITEMS"
                    body={false}
                    action={o.items.length > 0 ? (
                      <span className="ord-detail-count num">
                        {o.items.length} صنف · {totalUnits} قطعة
                      </span>
                    ) : null}
                  >
                    <div className="tbl-wrap ord-tbl-wrap">
                      <table className="tbl ord-detail-tbl">
                        <thead>
                          <tr>
                            <th>المنتج</th>
                            <th className="t-end">الكمية</th>
                            <th className="t-end">سعر الوحدة</th>
                            <th className="t-end">الخصم</th>
                            <th className="t-end">الإجمالي</th>
                          </tr>
                        </thead>
                        <tbody>
                          {o.items.map((it) => (
                            <tr key={it.id || it.sku}>
                              <td>
                                <div className="prod-cell">
                                  <Thumb sm />
                                  <div>
                                    <div className="prod-name">{it.name}</div>
                                    <div className="sku">{it.sku}</div>
                                  </div>
                                </div>
                              </td>
                              <td className="t-end num strong">{it.qty}</td>
                              <td className="t-end num">{fmt(it.unit)} <span className="ord-curr">EGP</span></td>
                              <td className="t-end num" style={{ color: it.discount ? 'var(--teal)' : 'var(--soft)' }}>
                                {it.discount ? `− ${fmt(it.discount)}` : '—'}
                              </td>
                              <td className="t-end strong num">{fmt(it.line_total)} <span className="ord-curr">EGP</span></td>
                            </tr>
                          ))}
                        </tbody>
                        <tfoot>
                          <tr>
                            <td>إجمالي البنود</td>
                            <td className="t-end num">{totalUnits}</td>
                            <td />
                            <td className="t-end num" style={{ color: o.discount ? 'var(--teal)' : 'var(--soft)' }}>
                              {o.discount ? `− ${fmt(o.discount)}` : '—'}
                            </td>
                            <td className="t-end num">{fmt(o.subtotal)} <span className="ord-curr">EGP</span></td>
                          </tr>
                        </tfoot>
                      </table>
                    </div>

                    <div className="ord-items-mobile">
                      {o.items.map((it) => (
                        <div key={it.id || it.sku} className="ord-item-m">
                          <div className="ord-item-m-top">
                            <Thumb sm />
                            <div className="ord-item-m-info">
                              <div className="prod-name">{it.name}</div>
                              {it.sku && <div className="sku">{it.sku}</div>}
                            </div>
                          </div>
                          <div className="ord-item-m-foot">
                            <span className="ord-item-m-calc">
                              <span className="num">{it.qty} × {fmt(it.unit)} <small>EGP</small></span>
                              {it.discount ? <span className="ord-item-m-disc num">خصم −{fmt(it.discount)}</span> : null}
                            </span>
                            <strong className="num">{fmt(it.line_total)} <small>EGP</small></strong>
                          </div>
                        </div>
                      ))}
                    </div>
                  </Panel>

                  <div className="grid-2e ord-detail-meta-grid">
                    <Panel title="المستلم والعنوان" en="DELIVERY">
                      <dl className="kv ord-detail-kv">
                        <dt><i className="fa-regular fa-user" aria-hidden="true" /> المستلم</dt>
                        <dd>{o.recipient || '—'}</dd>
                        <dt><i className="fa-solid fa-phone" aria-hidden="true" /> التليفون</dt>
                        <dd>{o.phone ? <a href={`tel:${o.phone}`} className="ord-detail-tel num">{o.phone}</a> : '—'}</dd>
                        <dt><i className="fa-solid fa-location-dot" aria-hidden="true" /> المدينة</dt>
                        <dd>{o.city || '—'}</dd>
                        <dt><i className="fa-regular fa-map" aria-hidden="true" /> العنوان</dt>
                        <dd>{o.address || '—'}</dd>
                      </dl>
                    </Panel>

                    <Panel title="الدفع" en="PAYMENT">
                      <dl className="kv ord-detail-kv">
                        <dt><i className="fa-regular fa-credit-card" aria-hidden="true" /> الطريقة</dt>
                        <dd>{PAY_METHOD[o.method] || o.method || '—'}</dd>
                        <dt><i className="fa-solid fa-circle-check" aria-hidden="true" /> حالة السداد</dt>
                        <dd><Badge map={PAY_STATUS} k={o.pay} /></dd>
                      </dl>
                      {o.pay === 'unpaid' && o.method === 'credit' && (
                        <button type="button" className="btn-pill is-dark sm ord-detail-pay-btn mt-16" onClick={() => go('noticeNew', o.id)}>
                          <i className="fa-solid fa-money-check-dollar" aria-hidden="true" /> تسجيل إشعار سداد لهذا الطلب
                        </button>
                      )}
                    </Panel>
                  </div>
                </div>

                <aside className="stack ord-detail-aside">
                  <Panel title="تفصيل المبالغ" en="TOTALS">
                    <div className="ord-detail-total-hero">
                      <div className="ord-detail-total-lbl">
                        <span>الإجمالي المستحق</span>
                        <Badge map={PAY_STATUS} k={o.pay} />
                      </div>
                      <div className="ord-detail-total-val num">
                        {fmt(o.total)} <small>EGP</small>
                      </div>
                    </div>

                    <div className="sum-row"><span>المنتجات</span><strong className="num">{fmt(o.subtotal)} <small className="ord-curr">EGP</small></strong></div>
                    <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{o.discount ? `− ${fmt(o.discount)} EGP` : '—'}</strong></div>
                    <div className="sum-row"><span>الشحن</span><strong className="num">{fmt(o.shipping)} <small className="ord-curr">EGP</small></strong></div>
                    <div className="sum-row"><span>رسوم التحصيل</span><strong className="num">{fmt(o.cod_fee)} <small className="ord-curr">EGP</small></strong></div>
                    <div className="sum-row"><span>الضريبة</span><strong className="num">{fmt(o.tax)} <small className="ord-curr">EGP</small></strong></div>
                    <div className="sum-total"><span>الإجمالي</span><span className="num">{fmt(o.total)} <small className="ord-curr">EGP</small></span></div>
                  </Panel>

                  <Panel title="سجل الحالة" en="TRACKING">
                    <ul className="tl">
                      {(o.track || []).map(([s, t, st], i) => (
                        <li key={i} className={st === 'now' ? 'is-now' : st === 'done' ? 'is-done' : 'is-future'}>
                          <div className="tl-title">{ORDER_STATUS[s] ? ORDER_STATUS[s][0] : s}</div>
                          {t && <div className="tl-meta num">{t}</div>}
                        </li>
                      ))}
                    </ul>
                    <p className="note"><i className="fa-solid fa-circle-info" />التتبع من خدمة الشحن برقم الطلب ورقم الهاتف.</p>
                  </Panel>
                </aside>
              </div>
            </>
          )}
        </Async>
      </DataBoundary>
      {re && o && <ReorderModal orderId={o.id} onClose={() => setRe(false)} />}
      {cancel && o && <CancelModal order={o} onClose={() => setCancel(null)} onDone={onCancelled} />}
      {o && (
        <article className="ord-print-sheet" aria-hidden="true">
          <header className="ops-head">
            <div className="ops-brand">
              <div className="ops-brand-row">
                <span className="ops-logo">VARYA</span>
                <span className="ops-tag">WHOLESALE ORDER</span>
              </div>
              <div className="ops-doc-title">بيان طلب جملة</div>
            </div>
            <div className="ops-order-id">
              <div className="ops-num num">{o.number}</div>
              <div className="ops-date">التاريخ: <span className="num">{o.date}</span></div>
              <div className="ops-badges">
                <span className="ops-badge">{ORDER_STATUS[o.status]?.[0] || o.status}</span>
                <span className="ops-badge">{PAY_STATUS[o.pay]?.[0] || o.pay}</span>
              </div>
            </div>
          </header>

          <section className="ops-meta">
            <div className="ops-meta-box">
              <div className="ops-meta-title">التاجر والدفع</div>
              <dl className="ops-kv">
                {(account?.company || account?.name) && (
                  <>
                    <dt>الحساب</dt>
                    <dd>{account.company || account.name}</dd>
                  </>
                )}
                {account?.tax_number && (
                  <>
                    <dt>الرقم الضريبي</dt>
                    <dd className="num">{account.tax_number}</dd>
                  </>
                )}
                <dt>طريقة الدفع</dt>
                <dd>{PAY_METHOD[o.method] || o.method || '—'}</dd>
                <dt>حالة السداد</dt>
                <dd>{PAY_STATUS[o.pay]?.[0] || o.pay || '—'}</dd>
              </dl>
            </div>
            <div className="ops-meta-box">
              <div className="ops-meta-title">الاستلام والشحن</div>
              <dl className="ops-kv">
                <dt>المستلم</dt>
                <dd>{o.recipient || '—'}</dd>
                <dt>الهاتف</dt>
                <dd className="num">{o.phone || '—'}</dd>
                <dt>المدينة</dt>
                <dd>{o.city || '—'}</dd>
                <dt>العنوان</dt>
                <dd>{o.address || '—'}</dd>
              </dl>
            </div>
          </section>

          <table className="ops-table">
            <thead>
              <tr>
                <th className="ops-col-idx">#</th>
                <th>الصنف</th>
                <th className="t-end">الكمية</th>
                <th className="t-end">سعر الوحدة</th>
                <th className="t-end">الخصم</th>
                <th className="t-end">الإجمالي</th>
              </tr>
            </thead>
            <tbody>
              {o.items.map((it, idx) => (
                <tr key={it.id || it.sku || idx}>
                  <td className="ops-col-idx num">{idx + 1}</td>
                  <td>
                    <div className="ops-item-name">{it.name}</div>
                    {it.sku && <div className="ops-item-sku">{it.sku}</div>}
                  </td>
                  <td className="t-end num">{it.qty}</td>
                  <td className="t-end num">{fmt(it.unit)}</td>
                  <td className="t-end num">{it.discount ? `− ${fmt(it.discount)}` : '—'}</td>
                  <td className="t-end num ops-strong">{fmt(it.line_total)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={2}>إجمالي البنود (<span className="num">{o.items.length}</span> صنف)</td>
                <td className="t-end num">{totalUnits}</td>
                <td />
                <td className="t-end num">{o.discount ? `− ${fmt(o.discount)}` : '—'}</td>
                <td className="t-end num">{fmt(o.subtotal)} EGP</td>
              </tr>
            </tfoot>
          </table>

          <section className="ops-summary-wrap">
            <div className="ops-summary">
              <div className="ops-sum-row"><span>المنتجات</span><span className="num">{fmt(o.subtotal)} EGP</span></div>
              {Boolean(o.discount) && (
                <div className="ops-sum-row"><span>الخصم</span><span className="num">− {fmt(o.discount)} EGP</span></div>
              )}
              <div className="ops-sum-row"><span>الشحن</span><span className="num">{fmt(o.shipping)} EGP</span></div>
              {Boolean(o.cod_fee) && (
                <div className="ops-sum-row"><span>رسوم التحصيل</span><span className="num">{fmt(o.cod_fee)} EGP</span></div>
              )}
              {Boolean(o.tax) && (
                <div className="ops-sum-row"><span>الضريبة</span><span className="num">{fmt(o.tax)} EGP</span></div>
              )}
              <div className="ops-sum-total">
                <span>الإجمالي النهائي</span>
                <span className="num">{fmt(o.total)} <small>EGP</small></span>
              </div>
            </div>
          </section>

          <footer className="ops-foot">
            <span>VARYA Wholesale</span>
            <span className="num">{o.number}</span>
          </footer>
        </article>
      )}
    </div>
  );
};

/* ---------- 15. Quotations list ---------- */
const QUOTE_FILTER_REV = { priced: 'priced_by_admin', converted: 'converted_to_order' };

const getQuoteDaysLeft = (validUntil) => {
  if (!validUntil) return null;
  const ms = new Date(validUntil).getTime() - Date.now();
  return Number.isFinite(ms) ? Math.ceil(ms / 864e5) : null;
};

const isQuoteExpired = (q) => Boolean(q && (q.status === 'expired' || (q.status === 'priced' && q.valid_until && q.valid_until < today())));

const QuoteInspector = ({ quote, isSheet = false, onClose, onOpenFull, onEditDraft, onAccept, onReject, onDeleteDraft, onOpenOrder }) => {
  const needsDetail = Boolean(quote && (!quote.items || quote.items.length === 0));
  const detailApi = useApi(
    (signal) => wholesaleEndpoints.quote(quote.id, signal).then(mapQuote),
    [quote?.id],
    needsDetail,
  );
  const full = (!needsDetail ? quote : detailApi.data) || quote;
  const items = full?.items || [];
  const daysLeft = full ? getQuoteDaysLeft(full.valid_until) : null;
  const expired = isQuoteExpired(full);
  const canRespond = Boolean(full && full.status === 'priced' && !expired);

  if (!quote) {
    return (
      <aside className="panel ord-inspector is-empty" aria-label="معاينة عرض السعر">
        <div className="ord-insp-head">
          <div className="ord-insp-eyebrow">QUOTATION PREVIEW</div>
          <button
            type="button"
            className="wp-icon-btn"
            onClick={onClose}
            title="إغلاق المعاينة"
            aria-label="إغلاق المعاينة"
          >
            <i className="fa-solid fa-xmark" />
          </button>
        </div>
        <div className="state" style={{ padding: '44px 20px' }}>
          <div className="state-icon"><i className="fa-solid fa-file-signature" /></div>
          <p className="state-title" style={{ fontSize: 16 }}>اختر عرض سعر للمعاينة</p>
          <p className="state-desc">اضغط على أي صف من الجدول لمراجعة البنود، مقارنة الأسعار المعروضة، والرد مباشرة.</p>
        </div>
      </aside>
    );
  }

  return (
    <aside className={`panel ord-inspector${isSheet ? ' is-sheet' : ''}`} aria-label={`معاينة عرض السعر ${full.number}`}>
      <div className="ord-insp-head">
        <div>
          <div className="ord-insp-eyebrow">QUOTATION PREVIEW</div>
          <div className="ord-insp-num num">{full.number}</div>
          <div className="ord-insp-date">
            أُنشئ في <span className="num">{full.created}</span>
            {full.valid_until ? <> · صالح حتى <span className="num">{full.valid_until}</span></> : null}
          </div>
        </div>
        <div className="ord-insp-head-actions">
          <button
            type="button"
            className="wp-icon-btn"
            onClick={() => onOpenFull(full)}
            title={full.status === 'draft' ? 'فتح محرر المسودة' : 'فتح صفحة عرض السعر'}
            aria-label={full.status === 'draft' ? 'فتح محرر المسودة' : 'فتح صفحة عرض السعر'}
          >
            <i className="fa-solid fa-arrow-up-right-from-square" />
          </button>
          <button
            type="button"
            className="wp-icon-btn"
            onClick={onClose}
            title="إغلاق المعاينة"
            aria-label="إغلاق المعاينة"
          >
            <i className="fa-solid fa-xmark" />
          </button>
        </div>
      </div>

      <div className="ord-insp-scroll">
        <div className="ord-insp-hero">
          <div className="ord-insp-hero-total">
            <span>{full.total != null ? 'الإجمالي المعروض' : 'حالة التسعير'}</span>
            {full.total != null
              ? <strong className="num">{fmt(full.total)} <small>EGP</small></strong>
              : <strong style={{ fontSize: 15, fontWeight: 600, color: 'var(--muted)' }}>بانتظار التسعير</strong>}
          </div>
          <div className="ord-insp-hero-meta">
            <Badge map={QUOTE_STATUS} k={full.status} />
            {items.length > 0 && <span className="ord-method-pill num">{items.length} صنف</span>}
            {full.revision != null && <span className="ord-method-pill num">نسخة #{full.revision}</span>}
            {canRespond && daysLeft != null && daysLeft >= 0 && (
              <span className={`badge ${daysLeft <= 2 ? 'danger' : 'warning'}`}>
                {daysLeft === 0 ? 'ينتهي اليوم' : <>باقي <span className="num">{daysLeft}</span> يوم</>}
              </span>
            )}
          </div>
        </div>

        {canRespond && (
          <div className="qt-insp-banner is-info">
            <i className="fa-solid fa-clock" />
            <span>الإدارة سعّرت العرض — راجع الأسعار وقرر قبل <b className="num">{full.valid_until}</b>.</span>
          </div>
        )}
        {full.status === 'priced' && expired && (
          <div className="qt-insp-banner is-err">
            <i className="fa-solid fa-calendar-xmark" />
            <span>انتهت صلاحية هذا العرض ولم يعد قابلًا للقبول.</span>
          </div>
        )}
        {full.status === 'converted' && full.converted_order_id && (
          <div className="qt-insp-banner is-ok">
            <i className="fa-solid fa-circle-check" />
            <span>تم تحويل العرض إلى الطلب <b className="num">{full.converted_order_number || `#${full.converted_order_id}`}</b></span>
          </div>
        )}

        <div className="ord-insp-section">
          <div className="ord-insp-sec-head">
            <span>البنود والأسعار {items.length ? <span className="num">({items.length})</span> : ''}</span>
            <span className="panel-en">LINE ITEMS</span>
          </div>
          {needsDetail && detailApi.loading ? (
            <SkeletonRows rows={3} cols={2} />
          ) : items.length ? (
            <ul className="ord-insp-items">
              {items.slice(0, 5).map((it, idx) => (
                <li key={it.id || it.sku || idx} className="ord-insp-item">
                  <Thumb sm />
                  <div className="ord-insp-item-info">
                    <div className="ord-insp-item-name">{it.name || it.sku || 'منتج'}</div>
                    <div className="ord-insp-item-meta">
                      {it.sku && <span className="sku">{it.sku}</span>}
                      <span className="num">×{it.qty}</span>
                      {it.target != null && (
                        <span className="qt-price-tag">مطلوب: <span className="num">{fmt(it.target)}</span></span>
                      )}
                      {it.offered != null && (
                        <span className="qt-price-tag is-offered">معروض: <span className="num">{fmt(it.offered)}</span></span>
                      )}
                    </div>
                  </div>
                  <strong className="num">{it.offered != null ? fmt(it.line_total) : '—'}</strong>
                </li>
              ))}
              {items.length > 5 && (
                <li className="ord-insp-more">
                  <button type="button" className="btn-link" onClick={() => onOpenFull(full)}>
                    +{items.length - 5} بنود أخرى في صفحة العرض
                  </button>
                </li>
              )}
            </ul>
          ) : (
            <p className="muted small" style={{ margin: 0 }}>لا توجد بنود مضافة بعد.</p>
          )}
        </div>

        {(full.subtotal != null || full.total != null) && (
          <div className="ord-insp-section">
            <div className="ord-insp-sec-head">
              <span>تفصيل الإجمالي</span>
              <span className="panel-en">TOTALS</span>
            </div>
            <div className="ord-insp-sums">
              <div className="ord-insp-sum-row"><span>الإجمالي قبل الخصم</span><span className="num">{full.subtotal != null ? fmt(full.subtotal) : '—'}</span></div>
              {Boolean(full.discount) && (
                <div className="ord-insp-sum-row is-neg"><span>الخصم</span><span className="num">− {fmt(full.discount)}</span></div>
              )}
              {full.tax != null && (
                <div className="ord-insp-sum-row"><span>الضريبة</span><span className="num">{fmt(full.tax)}</span></div>
              )}
              <div className="ord-insp-sum-total">
                <span>الإجمالي النهائي</span>
                <strong className="num">{full.total != null ? fmt(full.total) : '—'} {full.total != null && <small>EGP</small>}</strong>
              </div>
            </div>
          </div>
        )}

        {(full.trader_note || full.admin_note) && (
          <div className="ord-insp-section">
            <div className="ord-insp-sec-head">
              <span>الملاحظات</span>
              <span className="panel-en">NOTES</span>
            </div>
            <div className="qt-notes-stack">
              {full.trader_note && (
                <div className="qt-note-mini">
                  <span>ملاحظاتك</span>
                  <p>{full.trader_note}</p>
                </div>
              )}
              {full.admin_note && (
                <div className="qt-note-mini is-admin">
                  <span>رد الإدارة</span>
                  <p>{full.admin_note}</p>
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      <div className="ord-insp-foot">
        {full.status === 'draft' ? (
          <>
            <button type="button" className="btn-pill is-dark ord-insp-primary" onClick={() => onEditDraft(full.id)}>
              <i className="fa-solid fa-pen" /> متابعة تعديل المسودة
            </button>
            <div className="ord-insp-secondary">
              <button type="button" className="btn-pill sm is-danger" onClick={() => onDeleteDraft(full)}>
                <i className="fa-solid fa-trash" /> حذف المسودة
              </button>
            </div>
          </>
        ) : canRespond ? (
          <>
            <button type="button" className="btn-pill is-dark ord-insp-primary" onClick={() => onAccept(full)}>
              <i className="fa-solid fa-check" /> قبول العرض
            </button>
            <div className="ord-insp-secondary">
              <button type="button" className="btn-pill sm" onClick={() => onOpenFull(full)}>
                <i className="fa-solid fa-eye" /> التفاصيل الكاملة
              </button>
              <button type="button" className="btn-pill sm is-danger" onClick={() => onReject(full)}>
                <i className="fa-solid fa-xmark" /> رفض العرض
              </button>
            </div>
          </>
        ) : (
          <>
            <button type="button" className="btn-pill is-dark ord-insp-primary" onClick={() => onOpenFull(full)}>
              <i className="fa-solid fa-eye" /> عرض التفاصيل الكاملة
            </button>
            {full.status === 'converted' && full.converted_order_id && (
              <div className="ord-insp-secondary">
                <button type="button" className="btn-pill sm" onClick={() => onOpenOrder(full.converted_order_id)}>
                  <i className="fa-solid fa-box" /> فتح الطلب <span className="num">{full.converted_order_number || full.converted_order_id}</span>
                </button>
              </div>
            )}
          </>
        )}
        {!isSheet && (
          <div className="ord-kbd-hint" aria-hidden="true">
            <span><kbd>↑</kbd> <kbd>↓</kbd> للتنقل</span>
            <span><kbd>Enter</kbd> للتفاصيل</span>
            <span><kbd>/</kbd> للبحث</span>
          </div>
        )}
      </div>
    </aside>
  );
};

export const Quotes = () => {
  const { go, toast, refreshAccount } = useApp();
  const [f, setF] = useState('all');
  const [q, setQ] = useState('');
  const [sortBy, setSortBy] = useState('newest');
  const [expiringOnly, setExpiringOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState(null);
  const [inspectorOpen, setInspectorOpen] = useState(() => {
    if (typeof window === 'undefined') return true;
    try { return window.localStorage.getItem('varya_quotes_inspector') !== '0'; } catch { return true; }
  });
  const [modalAction, setModalAction] = useState(null);
  const [mobilePreviewId, setMobilePreviewId] = useState(null);
  const [busy, setBusy] = useState(false);
  const searchRef = useRef(null);

  const toggleInspector = (next) => {
    setInspectorOpen((prev) => {
      const val = typeof next === 'boolean' ? next : !prev;
      try { window.localStorage.setItem('varya_quotes_inspector', val ? '1' : '0'); } catch {}
      return val;
    });
  };

  useEffect(() => {
    const onGlobalKey = (e) => {
      if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
      const tag = document.activeElement?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || document.activeElement?.isContentEditable) return;
      if (searchRef.current) {
        e.preventDefault();
        searchRef.current.focus();
      }
    };
    window.addEventListener('keydown', onGlobalKey);
    return () => window.removeEventListener('keydown', onGlobalKey);
  }, []);

  const api = useApi((signal) => wholesaleEndpoints
    .quotes({ status: QUOTE_FILTER_REV[f] || (f === 'all' ? undefined : f), page }, signal)
    .then((r) => ({ items: (r.data || []).map(mapQuote), meta: pageMeta(r.meta) })), [f, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 15 };

  const pricedReadyInView = useMemo(
    () => list.filter((it) => it.status === 'priced' && !isQuoteExpired(it)),
    [list],
  );
  const expiringSoonInView = useMemo(
    () => pricedReadyInView.filter((it) => {
      const d = getQuoteDaysLeft(it.valid_until);
      return d != null && d >= 0 && d <= 3;
    }),
    [pricedReadyInView],
  );
  const requestedCountInView = useMemo(
    () => list.filter((it) => it.status === 'requested').length,
    [list],
  );

  const filtered = useMemo(() => {
    const query = q.trim().toLowerCase();
    let rows = list.filter((item) => {
      if (expiringOnly) {
        const d = getQuoteDaysLeft(item.valid_until);
        if (!(item.status === 'priced' && !isQuoteExpired(item) && d != null && d >= 0 && d <= 3)) return false;
      }
      if (!query) return true;
      const haystack = [
        item.number,
        item.created,
        item.valid_until,
        item.converted_order_number,
        ...(item.items || []).flatMap((it) => [it.name, it.sku]),
      ].filter(Boolean).join(' ').toLowerCase();
      return haystack.includes(query);
    });
    if (sortBy === 'oldest') {
      rows = [...rows].sort((a, b) => String(a.created || '').localeCompare(String(b.created || '')) || a.id - b.id);
    } else if (sortBy === 'highest') {
      rows = [...rows].sort((a, b) => (b.total ?? -1) - (a.total ?? -1));
    } else if (sortBy === 'expiring') {
      rows = [...rows].sort((a, b) => String(a.valid_until || '9999-12-31').localeCompare(String(b.valid_until || '9999-12-31')));
    }
    return rows;
  }, [list, q, sortBy, expiringOnly]);

  const pageVolume = useMemo(
    () => filtered.reduce((sum, item) => sum + (item.total ?? 0), 0),
    [filtered],
  );

  useEffect(() => {
    if (!filtered.length) {
      setSelectedId(null);
      return;
    }
    if (!filtered.some((it) => it.id === selectedId)) {
      setSelectedId(filtered[0].id);
    }
  }, [filtered, selectedId]);

  const selectedQuote = useMemo(
    () => filtered.find((it) => it.id === selectedId) || null,
    [filtered, selectedId],
  );
  const mobilePreviewQuote = useMemo(
    () => filtered.find((it) => it.id === mobilePreviewId) || null,
    [filtered, mobilePreviewId],
  );

  useEffect(() => {
    if (!mobilePreviewQuote) return undefined;
    const onEsc = (e) => { if (e.key === 'Escape') setMobilePreviewId(null); };
    window.addEventListener('keydown', onEsc);
    return () => window.removeEventListener('keydown', onEsc);
  }, [mobilePreviewQuote]);

  const hasLocalFilter = Boolean(q.trim() || sortBy !== 'newest' || expiringOnly);
  const clearLocalFilters = () => {
    setQ('');
    setSortBy('newest');
    setExpiringOnly(false);
  };

  const openQuotePage = (item) => go(item.status === 'draft' ? 'quoteEdit' : 'quote', item.id);

  const handleRowClick = (item) => {
    if (typeof window !== 'undefined' && window.innerWidth >= 1100) {
      if (inspectorOpen && selectedId === item.id) {
        toggleInspector(false);
      } else {
        setSelectedId(item.id);
        if (!inspectorOpen) toggleInspector(true);
      }
    } else {
      openQuotePage(item);
    }
  };

  const handleRowKeyDown = (e, item, idx) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      openQuotePage(item);
    } else if (e.key === 'Escape' && inspectorOpen) {
      e.preventDefault();
      toggleInspector(false);
    } else if (e.key === 'ArrowDown' && idx < filtered.length - 1) {
      e.preventDefault();
      setSelectedId(filtered[idx + 1].id);
    } else if (e.key === 'ArrowUp' && idx > 0) {
      e.preventDefault();
      setSelectedId(filtered[idx - 1].id);
    }
  };

  const runModalAction = async () => {
    if (!modalAction) return;
    const { kind, quote: target } = modalAction;
    setBusy(true);
    try {
      if (kind === 'accept') {
        await wholesaleEndpoints.acceptQuote(target.id, target.revision != null ? { expected_revision: target.revision } : undefined);
        toast('تم قبول عرض السعر');
      } else if (kind === 'reject') {
        await wholesaleEndpoints.rejectQuote(target.id);
        toast('تم رفض عرض السعر');
      } else if (kind === 'delete') {
        await wholesaleEndpoints.deleteQuote(target.id);
        toast('تم حذف المسودة');
      }
      setModalAction(null);
      await api.reload();
      refreshAccount();
    } catch (e) {
      toast(apiError(e).message);
      await api.reload();
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHead en="Quotations" ar="اطلب تسعير خاص للكميات الكبيرة وتابع رد الإدارة">
        <button type="button" className="btn-pill is-dark" onClick={() => go('quoteNew')}>
          <i className="fa-solid fa-plus" /> طلب عرض سعر
        </button>
      </PageHead>

      <div className="ord-summary-bar" role="region" aria-label="ملخص عروض الأسعار">
        <div className="ord-stat">
          <span className="ord-stat-lbl">إجمالي العروض</span>
          <strong className="ord-stat-val num">{meta.total}</strong>
        </div>
        <button
          type="button"
          className={`ord-stat is-btn${f === 'priced' ? ' is-on' : ''}`}
          onClick={() => { setF(f === 'priced' ? 'all' : 'priced'); setExpiringOnly(false); setPage(1); }}
        >
          <span className="ord-stat-dot is-info" />
          <span className="ord-stat-lbl">بانتظار قرارك</span>
          <strong className="ord-stat-val num">{pricedReadyInView.length}</strong>
        </button>
        <div className="ord-stat">
          <span className="ord-stat-lbl">قيمة المعروض</span>
          <strong className="ord-stat-val num">{fmt(pageVolume)} <small>EGP</small></strong>
        </div>
        {(expiringSoonInView.length > 0 || expiringOnly) ? (
          <button
            type="button"
            className={`ord-stat is-btn${expiringOnly ? ' is-on' : ''}`}
            onClick={() => setExpiringOnly((v) => !v)}
          >
            <span className="ord-stat-dot is-warn" />
            <span className="ord-stat-lbl">تنتهي خلال ٣ أيام</span>
            <strong className="ord-stat-val num">{expiringSoonInView.length}</strong>
          </button>
        ) : (
          <button
            type="button"
            className={`ord-stat is-btn${f === 'requested' ? ' is-on' : ''}`}
            onClick={() => { setF(f === 'requested' ? 'all' : 'requested'); setPage(1); }}
          >
            <span className="ord-stat-dot is-warn" />
            <span className="ord-stat-lbl">قيد التسعير</span>
            <strong className="ord-stat-val num">{requestedCountInView}</strong>
          </button>
        )}
      </div>

      <div className={`ord-workspace${inspectorOpen ? '' : ' is-collapsed'}`}>
        <section className="panel ord-list-panel">
          <div className="toolbar ord-toolbar">
            <div className="ord-toolbar-row">
              <div className="chips ord-chips">
                <button
                  type="button"
                  className={`chip${f === 'all' && !expiringOnly ? ' is-on' : ''}`}
                  onClick={() => { setF('all'); setExpiringOnly(false); setPage(1); }}
                >
                  الكل
                </button>
                {Object.entries(QUOTE_STATUS).map(([k, [l]]) => (
                  <button
                    key={k}
                    type="button"
                    className={`chip${f === k ? ' is-on' : ''}`}
                    onClick={() => { setF(k); setExpiringOnly(false); setPage(1); }}
                  >
                    {l}
                  </button>
                ))}
              </div>
              <div className="ord-toolbar-meta">
                {hasLocalFilter && (
                  <button type="button" className="btn-link muted small" onClick={clearLocalFilters}>
                    مسح الفلاتر ({filtered.length}/{list.length})
                  </button>
                )}
                <button
                  type="button"
                  className={`ord-insp-toggle${inspectorOpen ? ' is-on' : ''}`}
                  onClick={() => toggleInspector()}
                  aria-pressed={inspectorOpen}
                  title={inspectorOpen ? 'إخفاء لوحة المعاينة السريعة' : 'إظهار لوحة المعاينة السريعة'}
                >
                  <i className="fa-solid fa-table-columns" />
                  <span>{inspectorOpen ? 'إخفاء المعاينة' : 'معاينة سريعة'}</span>
                </button>
              </div>
            </div>

            <div className="ord-controls qt-controls">
              <div className="ord-search-box">
                <i className="fa-solid fa-magnifying-glass" aria-hidden="true" />
                <input
                  ref={searchRef}
                  type="search"
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder="ابحث برقم العرض، التاريخ، أو اسم/كود الصنف..."
                  aria-label="بحث في عروض الأسعار"
                />
                {q ? (
                  <button type="button" className="ord-search-clear" onClick={() => setQ('')} aria-label="مسح البحث">
                    <i className="fa-solid fa-xmark" />
                  </button>
                ) : (
                  <kbd className="ord-search-kbd" aria-hidden="true">/</kbd>
                )}
              </div>

              <select
                className="ord-select"
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value)}
                aria-label="ترتيب عروض الأسعار"
              >
                <option value="newest">الأحدث أولاً</option>
                <option value="oldest">الأقدم أولاً</option>
                <option value="highest">الأعلى قيمة</option>
                <option value="expiring">الأقرب انتهاءً</option>
              </select>
            </div>
          </div>

          <DataBoundary api={api}>
            <Async
              rows={6}
              cols={6}
              isEmpty={!filtered.length}
              empty={hasLocalFilter ? (
                <Empty
                  icon="fa-filter-circle-xmark"
                  title="لا توجد عروض مطابقة للبحث"
                  desc="جرّب تغيير كلمات البحث أو مسح الفلاتر."
                  action={<button type="button" className="btn-pill is-dark" onClick={clearLocalFilters}>مسح الفلاتر</button>}
                />
              ) : (
                <Empty
                  icon="fa-file-signature"
                  title="لا توجد عروض أسعار بهذه الحالة"
                  action={<button type="button" className="btn-pill is-dark" onClick={() => go('quoteNew')}>طلب عرض سعر</button>}
                />
              )}
            >
              <div className="tbl-wrap ord-tbl-wrap">
                <table className="tbl ord-tbl">
                  <thead>
                    <tr>
                      <th>رقم العرض</th>
                      <th>تاريخ الإنشاء</th>
                      <th>الحالة</th>
                      <th>صالح حتى</th>
                      <th className="t-end">الإجمالي</th>
                      <th className="t-end"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((item, idx) => {
                      const daysLeft = getQuoteDaysLeft(item.valid_until);
                      const expired = isQuoteExpired(item);
                      const canRespond = item.status === 'priced' && !expired;
                      const isSel = inspectorOpen && item.id === selectedId;
                      return (
                        <tr
                          key={item.id}
                          tabIndex={0}
                          className={`is-click${isSel ? ' is-selected' : ''}`}
                          onClick={() => handleRowClick(item)}
                          onDoubleClick={() => openQuotePage(item)}
                          onKeyDown={(e) => handleRowKeyDown(e, item, idx)}
                        >
                          <td>
                            <div className="strong num">{item.number}</div>
                            {item.items?.length > 0 && (
                              <div className="ord-row-sub num">{item.items.length} صنف</div>
                            )}
                            {!item.items?.length && item.converted_order_number && (
                              <div className="ord-row-sub num">طلب {item.converted_order_number}</div>
                            )}
                          </td>
                          <td className="ord-date-cell num">{item.created}</td>
                          <td><Badge map={QUOTE_STATUS} k={item.status} /></td>
                          <td className="num">
                            {item.valid_until || '—'}
                            {canRespond && daysLeft != null && daysLeft >= 0 && (
                              <span
                                className={`badge ${daysLeft <= 2 ? 'danger' : 'warning'}`}
                                style={{ marginInlineStart: 8 }}
                              >
                                {daysLeft === 0 ? 'ينتهي اليوم' : <>باقي <span className="num">{daysLeft}</span> يوم</>}
                              </span>
                            )}
                            {item.status === 'priced' && expired && (
                              <span className="badge danger" style={{ marginInlineStart: 8 }}>منتهي</span>
                            )}
                          </td>
                          <td className="t-end strong num">
                            {item.total != null ? <>{fmt(item.total)} <span className="ord-curr">EGP</span></> : '—'}
                          </td>
                          <td className="t-end" onClick={(e) => e.stopPropagation()}>
                            <div className="ord-row-icons">
                              {canRespond && (
                                <>
                                  <button
                                    type="button"
                                    className="ord-row-btn"
                                    title="قبول العرض"
                                    aria-label={`قبول العرض ${item.number}`}
                                    onClick={() => setModalAction({ kind: 'accept', quote: item })}
                                  >
                                    <i className="fa-solid fa-check" style={{ color: 'var(--teal)' }} />
                                  </button>
                                  <button
                                    type="button"
                                    className="ord-row-btn"
                                    title="رفض العرض"
                                    aria-label={`رفض العرض ${item.number}`}
                                    onClick={() => setModalAction({ kind: 'reject', quote: item })}
                                  >
                                    <i className="fa-solid fa-xmark" style={{ color: '#be123c' }} />
                                  </button>
                                </>
                              )}
                              <button
                                type="button"
                                className="ord-row-btn"
                                title={item.status === 'draft' ? 'تعديل المسودة' : 'فتح تفاصيل العرض'}
                                aria-label={item.status === 'draft' ? `تعديل ${item.number}` : `فتح ${item.number}`}
                                onClick={() => openQuotePage(item)}
                              >
                                <i className={`fa-solid ${item.status === 'draft' ? 'fa-pen' : 'fa-arrow-up-right-from-square'}`} />
                              </button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <div className="ord-mobile-list">
                {filtered.map((item) => {
                  const daysLeft = getQuoteDaysLeft(item.valid_until);
                  const expired = isQuoteExpired(item);
                  const canRespond = item.status === 'priced' && !expired;
                  const subMeta = [
                    item.items?.length ? `${item.items.length} صنف` : null,
                    !item.items?.length && item.converted_order_number ? `طلب ${item.converted_order_number}` : null,
                  ].filter(Boolean).join(' · ');

                  return (
                    <article
                      key={item.id}
                      className="ord-m-card qt-m-card"
                      onClick={() => setMobilePreviewId(item.id)}
                      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setMobilePreviewId(item.id); } }}
                      tabIndex={0}
                      role="button"
                      aria-label={`معاينة عرض السعر ${item.number}`}
                    >
                      <div className="ord-m-head">
                        <div className="ord-m-id">
                          <span className="ord-m-num num">{item.number}</span>
                          <span className="ord-m-date num">
                            {item.created}{subMeta ? ` · ${subMeta}` : ''}
                          </span>
                        </div>
                        <div className="ord-m-head-end">
                          <Badge map={QUOTE_STATUS} k={item.status} />
                          <i className="fa-solid fa-chevron-left ord-m-chev" aria-hidden="true" />
                        </div>
                      </div>

                      <div className="ord-m-mid">
                        <div className="qt-m-meta">
                          {item.valid_until ? (
                            <div className="qt-m-valid">
                              <span>صالح حتى <span className="num">{item.valid_until}</span></span>
                              {canRespond && daysLeft != null && daysLeft >= 0 && (
                                <span className={`badge ${daysLeft <= 2 ? 'danger' : 'warning'}`}>
                                  {daysLeft === 0 ? 'ينتهي اليوم' : <>باقي <span className="num">{daysLeft}</span> يوم</>}
                                </span>
                              )}
                              {item.status === 'priced' && expired && (
                                <span className="badge danger">منتهي</span>
                              )}
                            </div>
                          ) : item.status === 'draft' ? (
                            <span className="qt-m-valid is-soft">مسودة غير مرسلة</span>
                          ) : item.status === 'requested' ? (
                            <span className="qt-m-valid is-soft">في انتظار تسعير الإدارة</span>
                          ) : (
                            <span className="qt-m-valid is-soft">بدون تاريخ صلاحية</span>
                          )}
                        </div>
                        <div className="ord-m-total">
                          <span className="ord-m-total-lbl">الإجمالي</span>
                          {item.total != null ? (
                            <strong className="num">{fmt(item.total)} <small>EGP</small></strong>
                          ) : (
                            <span className="qt-m-pending">—</span>
                          )}
                        </div>
                      </div>

                      {(canRespond || item.status === 'draft' || (item.status === 'converted' && item.converted_order_id)) && (
                        <div className="ord-m-actions" onClick={(e) => e.stopPropagation()}>
                          {canRespond && (
                            <>
                              <button
                                type="button"
                                className="btn-pill is-dark ord-m-btn"
                                onClick={() => setModalAction({ kind: 'accept', quote: item })}
                              >
                                <i className="fa-solid fa-check" aria-hidden="true" /> قبول العرض
                              </button>
                              <button
                                type="button"
                                className="btn-pill is-danger ord-m-btn ord-m-cancel"
                                onClick={() => setModalAction({ kind: 'reject', quote: item })}
                              >
                                <i className="fa-solid fa-xmark" aria-hidden="true" /> رفض
                              </button>
                            </>
                          )}
                          {item.status === 'draft' && (
                            <>
                              <button
                                type="button"
                                className="btn-pill is-dark ord-m-btn"
                                onClick={() => go('quoteEdit', item.id)}
                              >
                                <i className="fa-solid fa-pen" aria-hidden="true" /> متابعة التعديل
                              </button>
                              <button
                                type="button"
                                className="btn-pill is-danger ord-m-btn ord-m-cancel"
                                onClick={() => setModalAction({ kind: 'delete', quote: item })}
                              >
                                <i className="fa-solid fa-trash" aria-hidden="true" /> حذف
                              </button>
                            </>
                          )}
                          {item.status === 'converted' && item.converted_order_id && (
                            <button
                              type="button"
                              className="btn-pill ord-m-btn"
                              onClick={() => go('order', item.converted_order_id)}
                            >
                              <i className="fa-solid fa-box" aria-hidden="true" /> عرض الطلب <span className="num">{item.converted_order_number || item.converted_order_id}</span>
                            </button>
                          )}
                        </div>
                      )}
                    </article>
                  );
                })}
              </div>
              <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
            </Async>
          </DataBoundary>
        </section>

        <div className="ord-inspector-slot" aria-hidden={!inspectorOpen}>
          <QuoteInspector
            quote={selectedQuote}
            onClose={() => toggleInspector(false)}
            onOpenFull={openQuotePage}
            onEditDraft={(qid) => go('quoteEdit', qid)}
            onAccept={(qt) => setModalAction({ kind: 'accept', quote: qt })}
            onReject={(qt) => setModalAction({ kind: 'reject', quote: qt })}
            onDeleteDraft={(qt) => setModalAction({ kind: 'delete', quote: qt })}
            onOpenOrder={(oid) => go('order', oid)}
          />
        </div>
      </div>

      {mobilePreviewQuote && (
        <div
          className="ord-sheet-bg"
          onMouseDown={(e) => e.target === e.currentTarget && setMobilePreviewId(null)}
        >
          <div className="ord-sheet" role="dialog" aria-modal="true" aria-label={`معاينة عرض السعر ${mobilePreviewQuote.number}`}>
            <div className="ord-sheet-handle" aria-hidden="true" />
            <QuoteInspector
              quote={mobilePreviewQuote}
              isSheet
              onClose={() => setMobilePreviewId(null)}
              onOpenFull={(qt) => { setMobilePreviewId(null); openQuotePage(qt); }}
              onEditDraft={(qid) => { setMobilePreviewId(null); go('quoteEdit', qid); }}
              onAccept={(qt) => { setMobilePreviewId(null); setModalAction({ kind: 'accept', quote: qt }); }}
              onReject={(qt) => { setMobilePreviewId(null); setModalAction({ kind: 'reject', quote: qt }); }}
              onDeleteDraft={(qt) => { setMobilePreviewId(null); setModalAction({ kind: 'delete', quote: qt }); }}
              onOpenOrder={(oid) => { setMobilePreviewId(null); go('order', oid); }}
            />
          </div>
        </div>
      )}

      {modalAction && (
        <Modal
          title={
            modalAction.kind === 'accept' ? `قبول العرض ${modalAction.quote.number}؟`
              : modalAction.kind === 'reject' ? `رفض العرض ${modalAction.quote.number}؟`
                : `حذف المسودة ${modalAction.quote.number}؟`
          }
          onClose={() => !busy && setModalAction(null)}
          icon={
            <div className={`modal-icon${modalAction.kind !== 'accept' ? ' is-err' : ''}`}>
              <i className={`fa-solid ${modalAction.kind === 'accept' ? 'fa-check' : modalAction.kind === 'reject' ? 'fa-xmark' : 'fa-trash'}`} />
            </div>
          }
          foot={
            <>
              <button type="button" className="btn-pill" disabled={busy} onClick={() => setModalAction(null)}>رجوع</button>
              <button
                type="button"
                className={`btn-pill ${modalAction.kind === 'accept' ? 'is-dark' : 'is-danger'}`}
                disabled={busy}
                onClick={runModalAction}
              >
                {busy ? <span className="spin" /> : modalAction.kind === 'accept' ? 'تأكيد القبول' : modalAction.kind === 'reject' ? 'تأكيد الرفض' : 'حذف'}
              </button>
            </>
          }
        >
          <p className="muted" style={{ lineHeight: 1.9 }}>
            {modalAction.kind === 'accept' ? (
              <>بقبولك العرض بإجمالي <b className="num" style={{ color: 'var(--ink)' }}>{fmt(modalAction.quote.total)} EGP</b> هيتم إنشاء الطلب وتأكيده فورًا وخصم المخزون والائتمان.</>
            ) : modalAction.kind === 'reject' ? (
              'الإجراء نهائي، ولن يكون العرض قابلًا للقبول بعد الرفض.'
            ) : (
              'سيتم حذف هذه المسودة نهائيًا ولن يمكن استرجاعها.'
            )}
          </p>
        </Modal>
      )}
    </>
  );
};

/* ---------- 16. Create / edit quote ---------- */
const QUOTE_NOTE_PRESETS = ['تغليف خاص', 'توريد على دفعات', 'توصيل مستعجل', 'شروط دفع آجلة'];

export const QuoteForm = ({ id }) => {
  const { go, toast, route } = useApp();
  const editingId = id ?? route.id;
  const [items, setItems] = useState([]);
  const [note, setNote] = useState('');
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [matches, setMatches] = useState([]);
  const [searching, setSearching] = useState(false);
  const [activeMatch, setActiveMatch] = useState(0);
  const [errs, setErrs] = useState({});
  const [confirm, setConfirm] = useState(null);
  const [busy, setBusy] = useState(false);
  const [draftId, setDraftId] = useState(editingId);
  const savedId = React.useRef(editingId);
  const searchInputRef = useRef(null);
  const quoteApi = useApi((signal) => wholesaleEndpoints.quote(editingId, signal).then(mapQuote), [editingId], !!editingId);

  useEffect(() => {
    savedId.current = editingId;
    setDraftId(editingId);
    if (!editingId) {
      setItems([]);
      setNote('');
      setErrs({});
    }
  }, [editingId]);

  useEffect(() => {
    if (quoteApi.state === 'ready' && quoteApi.data) {
      const m = quoteApi.data;
      setItems((m.items || []).map((it) => ({
        variant_id: it.variant_id,
        name: it.name,
        sku: it.sku,
        qty: it.qty,
        target: it.target != null ? String(it.target) : '',
        catalogPrice: null,
      })));
      setNote(m.trader_note || '');
    }
  }, [quoteApi.state, quoteApi.data]);

  useEffect(() => {
    const onGlobalKey = (e) => {
      if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
      const tag = document.activeElement?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || document.activeElement?.isContentEditable) return;
      if (searchInputRef.current) {
        e.preventDefault();
        searchInputRef.current.focus();
      }
    };
    window.addEventListener('keydown', onGlobalKey);
    return () => window.removeEventListener('keydown', onGlobalKey);
  }, []);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(t);
  }, [search]);

  useEffect(() => {
    if (!debounced.trim()) {
      setMatches([]);
      setSearching(false);
      setActiveMatch(0);
      return;
    }
    const ctrl = new AbortController();
    setSearching(true);
    wholesaleEndpoints.products({ search: debounced.trim(), per_page: 8 }, ctrl.signal)
      .then((r) => {
        const vars = (r.data || []).flatMap((p) => (p.variants || []).map((v) => ({
          variant_id: v.id,
          name: p.name,
          sku: v.sku,
          label: v.label && v.label !== v.sku ? v.label : null,
          price: v.your_price != null ? Number(v.your_price) : null,
          stock: v.available_qty != null ? Number(v.available_qty) : null,
        })));
        if (!ctrl.signal.aborted) {
          setMatches(vars);
          setActiveMatch(0);
          setSearching(false);
        }
      })
      .catch(() => {
        if (!ctrl.signal.aborted) setSearching(false);
      });
    return () => ctrl.abort();
  }, [debounced]);

  const addMatch = (v) => {
    setItems((prev) => addQuoteLine(prev, {
      variant_id: v.variant_id,
      name: v.name,
      sku: v.sku,
      qty: 1,
      target: '',
      catalogPrice: v.price,
    }));
    setSearch('');
    setMatches([]);
    setActiveMatch(0);
    searchInputRef.current?.focus();
  };

  const handleSearchKeyDown = (e) => {
    if (!matches.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActiveMatch((prev) => (prev + 1) % matches.length);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActiveMatch((prev) => (prev - 1 + matches.length) % matches.length);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const chosen = matches[activeMatch];
      if (chosen) addMatch(chosen);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      setMatches([]);
    }
  };

  const upd = (i, k, val) => setItems(items.map((it, j) => (j === i ? { ...it, [k]: val } : it)));

  const totalQty = useMemo(() => items.reduce((a, it) => a + Number(it.qty || 0), 0), [items]);
  const targetStats = useMemo(() => {
    let targetSum = 0;
    let catalogSum = 0;
    let pricedLines = 0;
    items.forEach((it) => {
      const qVal = Number(it.qty || 0);
      if (it.target !== '' && Number(it.target) > 0) {
        targetSum += qVal * Number(it.target);
        pricedLines += 1;
      }
      if (it.catalogPrice != null) {
        catalogSum += qVal * it.catalogPrice;
      }
    });
    return { targetSum, catalogSum, pricedLines };
  }, [items]);

  const appendNotePreset = (preset) => {
    setNote((prev) => {
      const trimmed = prev.trim();
      if (trimmed.includes(preset)) return prev;
      return trimmed ? `${trimmed} · ${preset}` : preset;
    });
  };

  const save = async () => {
    const e = {};
    if (!items.length) e.items = 'أضف بند واحد على الأقل.';
    items.forEach((it, i) => { if (!it.qty || Number(it.qty) <= 0) e[`items.${i}.qty`] = 'الكمية مطلوبة.'; });
    if (note.length > 2000) e.note = 'الحد الأقصى 2000 حرف.';
    setErrs(e);
    if (Object.keys(e).length) return null;
    const body = {
      customer_notes: note || null,
      items: items.map((it) => ({ product_variant_id: it.variant_id, qty: Number(it.qty), requested_target_price: it.target ? Number(it.target) : null })),
    };
    setBusy(true);
    try {
      const saved = savedId.current ? await wholesaleEndpoints.updateQuote(savedId.current, body) : await wholesaleEndpoints.createQuote(body);
      savedId.current = saved.id;
      setDraftId(saved.id);
      return saved;
    } catch (err) {
      const info = apiError(err);
      const mapped = {};
      Object.entries(info.fields || {}).forEach(([k, msgs]) => { mapped[k === 'customer_notes' ? 'note' : k] = Array.isArray(msgs) ? msgs[0] : String(msgs); });
      setErrs(mapped);
      if (!Object.keys(mapped).length) toast(info.message);
      return null;
    } finally {
      setBusy(false);
    }
  };

  const saveDraft = async () => {
    const saved = await save();
    if (saved) { toast('تم حفظ المسودة'); go('quotes'); }
  };

  const sendForPricing = async () => {
    const saved = await save();
    if (!saved) return;
    setBusy(true);
    try {
      const submitted = await wholesaleEndpoints.submitQuote(saved.id);
      go('quote', submitted.id);
    } catch (err) {
      toast(apiError(err).message);
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  const removeDraft = async () => {
    const targetId = savedId.current ?? draftId ?? editingId;
    if (!targetId) return;
    setBusy(true);
    try {
      await wholesaleEndpoints.deleteQuote(targetId);
      toast('تم حذف المسودة');
      go('quotes');
    } catch (err) {
      toast(apiError(err).message);
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  if (editingId && quoteApi.state !== 'ready') return (<><PageHead en="Quote" /><section className="panel"><DataBoundary api={quoteApi}><Async rows={5} cols={5} /></DataBoundary></section></>);

  return (
    <>
      <PageHead
        en={draftId ? 'تعديل مسودة' : 'New Quote'}
        crumb={<Crumb items={[{ label: 'عروض الأسعار', go: () => go('quotes') }, { label: draftId ? 'تعديل مسودة' : 'طلب جديد' }]} />}
        ar={draftId ? 'مسودة — يمكنك التعديل قبل الإرسال' : 'اختر المنتجات والكميات، واقترح السعر المستهدف لو حابب'}
      >
        <button type="button" className="btn-pill" onClick={() => go('quotes')}>
          <i className="fa-solid fa-arrow-right" /> عروض الأسعار
        </button>
        {draftId && (
          <button type="button" className="btn-pill is-danger" onClick={() => setConfirm('delete')}>
            <i className="fa-regular fa-trash-can" /> حذف المسودة
          </button>
        )}
      </PageHead>

      <div className="ord-summary-bar" role="region" aria-label="ملخص طلب التسعير">
        <div className="ord-stat">
          <span className="ord-stat-lbl">عدد البنود</span>
          <strong className="ord-stat-val num">{items.length} <small>/ 100</small></strong>
        </div>
        <div className="ord-stat">
          <span className="ord-stat-lbl">إجمالي القطع</span>
          <strong className="ord-stat-val num">{totalQty}</strong>
        </div>
        {targetStats.targetSum > 0 && (
          <div className="ord-stat">
            <span className="ord-stat-dot is-info" />
            <span className="ord-stat-lbl">الإجمالي المستهدف</span>
            <strong className="ord-stat-val num">{fmt(targetStats.targetSum)} <small>EGP</small></strong>
          </div>
        )}
        {targetStats.catalogSum > 0 && (
          <div className="ord-stat">
            <span className="ord-stat-lbl">بقيمة الكتالوج</span>
            <strong className="ord-stat-val num">{fmt(targetStats.catalogSum)} <small>EGP</small></strong>
          </div>
        )}
      </div>

      <div className={`grid-2 grid-summary qf-workspace${items.length > 0 ? ' has-float' : ''}`}>
        <section className="panel qf-items-panel">
          <div className="toolbar qf-search-toolbar" style={{ position: 'relative' }}>
            <div className="ord-search-box qf-search-box">
              <i className={`fa-solid ${searching ? 'fa-spinner fa-spin' : 'fa-magnifying-glass'}`} aria-hidden="true" />
              <input
                ref={searchInputRef}
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={handleSearchKeyDown}
                placeholder="أضف منتج — ابحث بالاسم أو SKU لإضافته لطلب التسعير..."
                aria-label="بحث لإضافة منتج"
              />
              {search ? (
                <button
                  type="button"
                  className="ord-search-clear"
                  onClick={() => { setSearch(''); setMatches([]); }}
                  aria-label="مسح البحث"
                >
                  <i className="fa-solid fa-xmark" aria-hidden="true" />
                </button>
              ) : (
                <kbd className="ord-search-kbd" aria-hidden="true">/</kbd>
              )}
            </div>
            <div className="qf-toolbar-meta">
              <span className="soft small qf-count">
                <span className="num">{items.length}</span> / <span className="num">100</span> بند
              </span>
              {items.length > 0 && (
                <button type="button" className="btn-link muted small" onClick={() => setItems([])}>
                  مسح الكل
                </button>
              )}
            </div>
            {matches.length > 0 && (
              <div className="qf-suggest" role="listbox" aria-label="نتائج البحث عن المنتجات">
                {matches.map((v, idx) => {
                  const alreadyIn = items.some((it) => it.variant_id === v.variant_id);
                  return (
                    <button
                      key={v.variant_id}
                      type="button"
                      role="option"
                      aria-selected={idx === activeMatch}
                      className={idx === activeMatch ? 'is-active' : ''}
                      onMouseEnter={() => setActiveMatch(idx)}
                      onClick={() => addMatch(v)}
                    >
                      <Thumb sm />
                      <span className="qf-suggest-info">
                        <b>{v.name}</b>
                        <span className="qf-suggest-sub">
                          <span className="sku">{v.sku}</span>
                          {v.label && <span className="qf-suggest-var">{v.label}</span>}
                          {v.stock != null && <span className="qf-suggest-stock num">متاح: {v.stock}</span>}
                          {alreadyIn && <span className="qf-suggest-in">مضاف</span>}
                        </span>
                      </span>
                      <span className="qf-suggest-price num">
                        {v.price != null ? <>{fmt(v.price)} <small>EGP</small></> : '—'}
                      </span>
                      <span className="qf-suggest-add">
                        <i className="fa-solid fa-plus" aria-hidden="true" /> إضافة
                      </span>
                    </button>
                  );
                })}
              </div>
            )}
          </div>
          {items.length === 0 ? (
            <Empty
              icon="fa-list"
              title="لم تُضف أي بنود بعد"
              desc="ابحث باسم المنتج أو كود SKU في شريط البحث بالأعلى لإضافة الكميات المطلوب تسعيرها."
              action={
                <button type="button" className="btn-pill is-dark" onClick={() => searchInputRef.current?.focus()}>
                  <i className="fa-solid fa-magnifying-glass" /> ابدأ بالبحث عن منتج
                </button>
              }
            />
          ) : (
            <>
              <div className="tbl-wrap ord-tbl-wrap"><table className="tbl ord-tbl qf-tbl">
                <thead>
                  <tr>
                    <th>المنتج / المتغير</th>
                    <th>الكمية</th>
                    <th>السعر المستهدف <span className="f-opt">(اختياري)</span></th>
                    <th className="t-end">الإجمالي التقديري</th>
                    <th className="t-end"></th>
                  </tr>
                </thead>
                <tbody>{items.map((it, i) => {
                  const targetNum = it.target !== '' ? Number(it.target) : null;
                  const estUnit = targetNum && targetNum > 0 ? targetNum : it.catalogPrice;
                  const estLine = estUnit != null ? Number(it.qty || 0) * estUnit : null;
                  const diffPct = targetNum && targetNum > 0 && it.catalogPrice && it.catalogPrice > 0
                    ? Math.round(((targetNum - it.catalogPrice) / it.catalogPrice) * 100)
                    : null;
                  return (
                    <tr key={it.variant_id || i} className={errs[`items.${i}.qty`] ? 'is-error' : ''}>
                      <td>
                        <div className="prod-cell">
                          <Thumb sm />
                          <div>
                            <div className="prod-name">{it.name}</div>
                            <div className="qf-row-meta">
                              <span className="sku">{it.sku}</span>
                              {it.catalogPrice != null && (
                                <span className="qf-cat-ref num">الكتالوج: {fmt(it.catalogPrice)} EGP</span>
                              )}
                            </div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <Qty value={it.qty} onChange={(n) => upd(i, 'qty', n)} />
                        {errs[`items.${i}.qty`] && <p className="f-error">{errs[`items.${i}.qty`]}</p>}
                      </td>
                      <td>
                        <div className="qf-target-wrap">
                          <div className="qf-price-input">
                            <input
                              className="box-input ltr"
                              placeholder="0.00"
                              inputMode="decimal"
                              value={it.target}
                              onChange={(e) => { const v = e.target.value; if (/^\d*(\.\d{0,2})?$/.test(v)) upd(i, 'target', v); }}
                            />
                            <span className="qf-price-curr">EGP</span>
                          </div>
                          {diffPct != null && diffPct !== 0 && (
                            <span className={`qf-diff-pill num${diffPct < 0 ? ' is-save' : ''}`}>
                              {diffPct > 0 ? `+${diffPct}%` : `${diffPct}%`}
                            </span>
                          )}
                        </div>
                        {errs[`items.${i}.requested_target_price`] && <p className="f-error">{errs[`items.${i}.requested_target_price`]}</p>}
                        {errs[`items.${i}.product_variant_id`] && <p className="f-error">{errs[`items.${i}.product_variant_id`]}</p>}
                      </td>
                      <td className="t-end num">
                        {estLine != null ? (
                          <span className={targetNum && targetNum > 0 ? 'strong' : 'muted'}>
                            {fmt(estLine)} <span className="ord-curr">EGP</span>
                          </span>
                        ) : '—'}
                      </td>
                      <td className="t-end">
                        <button
                          type="button"
                          className="ord-row-btn"
                          onClick={() => setItems(items.filter((_, j) => j !== i))}
                          title="حذف البند"
                          aria-label={`حذف ${it.name}`}
                        >
                          <i className="fa-solid fa-xmark" />
                        </button>
                      </td>
                    </tr>
                  );
                })}</tbody>
              </table></div>
              <div className="qf-mobile-list">
                {items.map((it, i) => (
                  <div key={i} className={`qf-m-card${errs[`items.${i}.qty`] ? ' is-error' : ''}`}>
                    <div className="qf-m-head">
                      <div className="prod-cell">
                        <Thumb sm />
                        <div>
                          <div className="prod-name">{it.name}</div>
                          <div className="soft small"><span className="sku">{it.sku}</span></div>
                        </div>
                      </div>
                      <button
                        type="button"
                        className="qf-m-del"
                        onClick={() => setItems(items.filter((_, j) => j !== i))}
                        aria-label={`حذف ${it.name}`}
                      >
                        <i className="fa-solid fa-xmark" aria-hidden="true" />
                      </button>
                    </div>
                    <div className="qf-m-fields">
                      <div className="qf-m-field">
                        <span className="qf-m-lbl">الكمية</span>
                        <Qty value={it.qty} onChange={(n) => upd(i, 'qty', n)} />
                        {errs[`items.${i}.qty`] && <p className="f-error">{errs[`items.${i}.qty`]}</p>}
                      </div>
                      <div className="qf-m-field">
                        <span className="qf-m-lbl">السعر المستهدف <span className="f-opt">(اختياري)</span></span>
                        <input
                          className="box-input ltr qf-m-price"
                          placeholder="0.00"
                          inputMode="decimal"
                          value={it.target}
                          onChange={(e) => { const v = e.target.value; if (/^\d*(\.\d{0,2})?$/.test(v)) upd(i, 'target', v); }}
                        />
                        {errs[`items.${i}.requested_target_price`] && <p className="f-error">{errs[`items.${i}.requested_target_price`]}</p>}
                        {errs[`items.${i}.product_variant_id`] && <p className="f-error">{errs[`items.${i}.product_variant_id`]}</p>}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </>
          )}
          {errs.items && <p className="f-error" style={{ padding: '0 24px 16px' }}>{errs.items}</p>}
        </section>
        <aside className="stack qf-aside">
          <section className="panel qf-summary-panel">
            <div className="ord-insp-head">
              <div>
                <div className="ord-insp-eyebrow">QUOTE SUMMARY</div>
                <h2 className="panel-title" style={{ marginTop: 2 }}>ملخص الطلب والملاحظات</h2>
              </div>
              <span className={`badge ${draftId ? 'warning' : 'default'}`}>
                {draftId ? 'مسودة' : 'جديد'}
              </span>
            </div>

            <div className="ord-insp-hero">
              <div className="ord-insp-hero-total">
                <span>{targetStats.targetSum > 0 ? 'الإجمالي المستهدف' : 'حالة التسعير'}</span>
                {targetStats.targetSum > 0 ? (
                  <strong className="num">{fmt(targetStats.targetSum)} <small>EGP</small></strong>
                ) : (
                  <strong style={{ fontSize: 14, fontWeight: 600, color: 'var(--muted)' }}>تسعير حسب الكمية</strong>
                )}
              </div>
              <div className="ord-insp-hero-meta">
                <span className="ord-method-pill num">{items.length} بند</span>
                <span className="ord-method-pill num">{totalQty} قطعة</span>
                {targetStats.pricedLines > 0 && (
                  <span className="ord-method-pill num">{targetStats.pricedLines} بسعر مستهدف</span>
                )}
              </div>
            </div>

            <div className="qf-notes-box">
              <Field label="ملاحظات التاجر" opt counter={<span className="f-counter" style={{ color: note.length > 2000 ? '#b91c1c' : null }}>{note.length}/2000</span>} error={errs.note}>
                <textarea className="line-input" rows="4" value={note} placeholder="مواعيد التسليم، تغليف خاص، شروط الدفع…" onChange={(e) => setNote(e.target.value)} />
              </Field>
              <div className="qf-note-presets" aria-label="ملاحظات جاهزة">
                {QUOTE_NOTE_PRESETS.map((preset) => (
                  <button key={preset} type="button" className="qf-preset-chip" onClick={() => appendNotePreset(preset)}>
                    + {preset}
                  </button>
                ))}
              </div>
            </div>

            <div className="ord-insp-foot">
              <button type="button" className="btn-pill is-dark ord-insp-primary" style={{ padding: '12px 18px', fontSize: 13.5 }} disabled={busy} onClick={() => setConfirm('submit')}>
                <i className="fa-solid fa-paper-plane" /> إرسال للإدارة للتسعير
              </button>
              <button type="button" className="btn-pill ord-insp-primary" disabled={busy} onClick={saveDraft}>
                <i className="fa-regular fa-floppy-disk" /> حفظ كمسودة
              </button>
              <div className="ord-kbd-hint" aria-hidden="true">
                <span><kbd>/</kbd> بحث</span>
                <span><kbd>↑</kbd> <kbd>↓</kbd> اختيار</span>
                <span><kbd>Enter</kbd> إضافة</span>
              </div>
            </div>
          </section>

          <div className="qt-insp-banner is-info">
            <i className="fa-solid fa-circle-info" />
            <span>بعد الإرسال مش هتقدر تعدّل الطلب. الإدارة هترد بالأسعار وتاريخ صلاحية العرض.</span>
          </div>
        </aside>
      </div>
      {items.length > 0 && (
        <div className="qf-float-bar" role="region" aria-label="إجراءات عرض السعر">
          <div className="qf-float-info">
            <strong className="num">{items.length} بند</strong>
            <small className="num">{totalQty} قطعة</small>
          </div>
          <div className="qf-float-actions">
            <button type="button" className="btn-pill qf-float-draft" disabled={busy} onClick={saveDraft} aria-label="حفظ كمسودة">
              <i className="fa-regular fa-floppy-disk" aria-hidden="true" /> <span>مسودة</span>
            </button>
            <button type="button" className="btn-pill is-dark" disabled={busy} onClick={() => setConfirm('submit')}>
              <i className="fa-solid fa-paper-plane" aria-hidden="true" /> <span>إرسال</span>
            </button>
          </div>
        </div>
      )}
      {confirm === 'submit' && <Modal title="إرسال طلب التسعير؟" onClose={() => setConfirm(null)} icon={<div className="modal-icon"><i className="fa-solid fa-paper-plane" /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className="btn-pill is-dark" disabled={busy} onClick={sendForPricing}>{busy ? <span className="spin" /> : 'إرسال'}</button></>}>
        <p className="muted">هيتم إرسال <span className="num">{items.length}</span> بند (<span className="num">{totalQty}</span> قطعة) للإدارة. الحالة هتتغير إلى «مطلوب تسعيره».</p></Modal>}
      {confirm === 'delete' && <Modal title="حذف المسودة؟" onClose={() => setConfirm(null)} icon={<div className="modal-icon is-err"><i className="fa-solid fa-trash-can" /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className="btn-pill is-danger" disabled={busy} onClick={removeDraft}>{busy ? <span className="spin" /> : 'حذف'}</button></>}>
        <p className="muted">الإجراء ده نهائي.</p></Modal>}
    </>
  );
};

/* ---------- 17. Quote detail ---------- */
export const QuoteDetail = ({ id }) => {
  const { go, toast, refreshAccount } = useApp();
  const [confirm, setConfirm] = useState(null);
  const [busy, setBusy] = useState(false);

  const api = useApi((signal) => wholesaleEndpoints.quote(id, signal).then(mapQuote), [id]);
  const q = api.data;

  const act = async (kind) => {
    setBusy(true);
    try {
      if (kind === 'accept') {
        await wholesaleEndpoints.acceptQuote(id, q?.revision != null ? { expected_revision: q.revision } : undefined);
        toast('تم قبول العرض');
      } else {
        await wholesaleEndpoints.rejectQuote(id);
        toast('تم رفض العرض');
      }
      await api.reload();
      refreshAccount();
    } catch (e) {
      toast(apiError(e).message);
      await api.reload();
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  return (
    <>
      <PageHead en={q?.number || ''} crumb={<Crumb items={[{ label: 'عروض الأسعار', go: () => go('quotes') }, { label: q?.number || '', mono: true }]} />} ar={q ? <>أُنشئ في <span className="num">{q.created}</span>{q.valid_until ? <> · صالح حتى <span className="num">{q.valid_until}</span></> : null}</> : null}>
        {q && <Badge map={QUOTE_STATUS} k={q.status} lg />}
      </PageHead>
      <DataBoundary api={api}>
        <Async rows={5} cols={4} empty={<Empty icon="fa-file-signature" title="العرض غير موجود" />}>
          {q && (
          <>
            {q.status === 'priced' && !(q.valid_until && q.valid_until < today()) && <div className="mb-24"><Banner kind="info" icon="fa-clock" action={<div className="row"><button className="btn-pill is-danger sm" onClick={() => setConfirm('reject')}>رفض</button><button className="btn-pill is-dark sm" onClick={() => setConfirm('accept')}>قبول العرض</button></div>}><b>الإدارة سعّرت العرض.</b> راجع الأسعار وقرر قبل <span className="num">{q.valid_until}</span> — بعدها العرض بينتهي تلقائيًا.</Banner></div>}
            {q.status === 'priced' && q.valid_until && q.valid_until < today() && <div className="mb-24"><Banner kind="error" icon="fa-calendar-xmark">انتهت صلاحية العرض ولم يعد قابلًا للقبول.</Banner></div>}
            {q.status === 'requested' && <div className="mb-24"><Banner kind="warn" icon="fa-hourglass-half">الطلب عند الإدارة للتسعير. هيوصلك إشعار لما يتم الرد.</Banner></div>}
            {q.status === 'expired' && <div className="mb-24"><Banner kind="error" icon="fa-calendar-xmark">انتهت صلاحية العرض ولم يعد قابلًا للقبول. تقدر تطلب عرض جديد.</Banner></div>}
            {q.status === 'converted' && <div className="mb-24"><Banner kind="ok" action={q.converted_order_id && <button className="btn-pill sm" onClick={() => go('order', q.converted_order_id)}>فتح الطلب <span className="num">{q.converted_order_number || q.converted_order_id}</span></button>}>تم تحويل العرض لطلب.</Banner></div>}
            {q.status === 'accepted' && <div className="mb-24"><Banner kind="ok">تم قبول العرض. الإدارة هتحوله لطلب وهيظهر في طلباتك.</Banner></div>}
            <div className="grid-2">
              <Panel title="البنود" en="LINE ITEMS" body={false}>
                <div className="tbl-wrap ord-tbl-wrap"><table className="tbl">
                  <thead><tr><th>المنتج</th><th>الكمية</th><th>السعر المطلوب</th><th>السعر المعروض</th><th className="t-end">إجمالي البند</th></tr></thead>
                  <tbody>{q.items.map((it) => (
                    <tr key={it.id || it.sku}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td>
                      <td className="num">{it.qty}</td><td className="num muted">{it.target != null ? fmt(it.target) : '—'}</td>
                      <td className="num strong">{it.offered != null ? fmt(it.offered) : <span className="soft">في الانتظار</span>}</td>
                      <td className="t-end strong num">{it.offered != null ? fmt(it.line_total) : '—'}</td></tr>))}</tbody>
                </table></div>
                <div className="ord-items-mobile">
                  {q.items.map((it) => (
                    <div key={it.id || it.sku} className="ord-item-m">
                      <div className="ord-item-m-top">
                        <Thumb sm />
                        <div className="ord-item-m-info">
                          <div className="prod-name">{it.name}</div>
                          <div className="sku">{it.sku}</div>
                        </div>
                      </div>
                      <div className="ord-item-m-foot">
                        <span>
                          الكمية: <strong className="num">{it.qty}</strong>
                          {it.target != null && <> · المطلوب: <span className="num">{fmt(it.target)}</span></>}
                        </span>
                        <span className="num">
                          {it.offered != null ? (
                            <>المعروض: <strong>{fmt(it.offered)}</strong> · الإجمالي <strong>{fmt(it.line_total)}</strong></>
                          ) : (
                            <span className="soft">في الانتظار</span>
                          )}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </Panel>
              <div className="stack">
                <Panel title="الإجماليات" en="TOTALS">
                  <div className="sum-row"><span>الإجمالي قبل الخصم</span><strong className="num">{q.subtotal != null ? fmt(q.subtotal) : '—'}</strong></div>
                  <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{q.discount ? '− ' + fmt(q.discount) : '—'}</strong></div>
                  <div className="sum-row"><span>الضريبة</span><strong className="num">{q.tax != null ? fmt(q.tax) : '—'}</strong></div>
                  <div className="sum-total"><span>الإجمالي النهائي</span><strong className="num">{q.total != null ? fmt(q.total) : '—'}</strong></div>
                </Panel>
                <Panel title="الملاحظات" en="NOTES">
                  <div className="note-bubble"><span>ملاحظات التاجر</span><p>{q.trader_note || <span className="soft">—</span>}</p></div>
                  <div className="note-bubble is-admin"><span>رد الإدارة</span><p>{q.admin_note || <span className="soft">لم يتم الرد بعد</span>}</p></div>
                </Panel>
              </div>
            </div>
          </>
          )}
        </Async>
      </DataBoundary>
      {confirm && <Modal title={confirm === 'accept' ? 'قبول عرض السعر؟' : 'رفض عرض السعر؟'} onClose={() => setConfirm(null)} icon={<div className={`modal-icon${confirm === 'reject' ? ' is-err' : ''}`}><i className={`fa-solid ${confirm === 'accept' ? 'fa-check' : 'fa-xmark'}`} /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className={`btn-pill ${confirm === 'accept' ? 'is-dark' : 'is-danger'}`} disabled={busy} onClick={() => act(confirm)}>{busy ? <span className="spin" /> : confirm === 'accept' ? 'قبول' : 'رفض'}</button></>}>
        <p className="muted" style={{ lineHeight: 1.9 }}>{confirm === 'accept' && q ? <>بقبولك العرض بإجمالي <b className="num" style={{ color: 'var(--ink)' }}>{fmt(q.total)} EGP</b> هيتم إنشاء الطلب وتأكيده فورًا وخصم المخزون والائتمان.</> : 'الإجراء نهائي، ولن يكون العرض قابلًا للقبول بعد الرفض.'}</p></Modal>}
    </>
  );
};

/* ---------- 22. Returns ---------- */
const RETURN_STEPS = ['requested', 'approved', 'received', 'refunded'];

const ReturnMiniTrack = ({ ret }) => {
  if (!ret) return null;
  if (ret.status === 'rejected' || ret.status === 'cancelled') {
    return (
      <div className="ord-mini-tl is-stopped">
        <span className={`badge ${RETURN_STATUS[ret.status]?.[1] || 'default'}`}>
          {RETURN_STATUS[ret.status]?.[0] || ret.status}
        </span>
      </div>
    );
  }
  const dates = {
    requested: ret.date,
    approved: ret.approved_at,
    received: ret.received_at,
    refunded: ret.refunded_at,
  };
  const currentIdx = RETURN_STEPS.indexOf(ret.status);
  return (
    <ol className="ord-mini-tl ret-mini-tl" aria-label="مراحل المرتجع">
      {RETURN_STEPS.map((key, idx) => {
        const hasDate = Boolean(dates[key]);
        const state = idx < currentIdx || (idx === currentIdx && key === 'refunded') || (hasDate && idx < currentIdx)
          ? 'done'
          : idx === currentIdx
            ? 'now'
            : 'future';
        return (
          <li key={key} className={`is-${state}`} title={RETURN_STATUS[key]?.[0] || key}>
            <span className="ord-mini-dot" />
            <span className="ord-mini-lbl">{RETURN_STATUS[key]?.[0] || key}</span>
          </li>
        );
      })}
    </ol>
  );
};

const ReturnInspector = ({ ret, isSheet = false, onClose, onOpenFull, onOpenOrder }) => {
  const needsDetail = Boolean(ret && (!ret.items || ret.items.length === 0));
  const detailApi = useApi(
    () => accountEndpoints.returnDetail(ret.id).then(mapReturn),
    [ret?.id],
    needsDetail,
  );
  const full = (!needsDetail ? ret : detailApi.data) || ret;
  const items = full?.items || [];

  if (!ret) {
    return (
      <aside className="panel ord-inspector is-empty" aria-label="معاينة المرتجع">
        <div className="ord-insp-head">
          <div className="ord-insp-eyebrow">RETURN PREVIEW</div>
          <button
            type="button"
            className="wp-icon-btn"
            onClick={onClose}
            title="إغلاق المعاينة"
            aria-label="إغلاق المعاينة"
          >
            <i className="fa-solid fa-xmark" />
          </button>
        </div>
        <div className="state" style={{ padding: '44px 20px' }}>
          <div className="state-icon"><i className="fa-solid fa-rotate-left" /></div>
          <p className="state-title" style={{ fontSize: 16 }}>اختر مرتجعاً للمعاينة</p>
          <p className="state-desc">اضغط على أي صف من الجدول لعرض البنود، مراحل الاسترداد، والسبب فوراً.</p>
        </div>
      </aside>
    );
  }

  return (
    <aside className={`panel ord-inspector${isSheet ? ' is-sheet' : ''}`} aria-label={`معاينة المرتجع ${full.number}`}>
      <div className="ord-insp-head">
        <div>
          <div className="ord-insp-eyebrow">RETURN PREVIEW</div>
          <div className="ord-insp-num num">{full.number}</div>
          <div className="ord-insp-date">
            بتاريخ <span className="num">{full.date}</span>
            {full.order ? <> · طلب <span className="num">{full.order}</span></> : null}
          </div>
        </div>
        <div className="ord-insp-head-actions">
          <button
            type="button"
            className="wp-icon-btn"
            onClick={() => onOpenFull(full.id)}
            title="فتح صفحة تفاصيل المرتجع"
            aria-label="فتح صفحة تفاصيل المرتجع"
          >
            <i className="fa-solid fa-arrow-up-right-from-square" />
          </button>
          <button
            type="button"
            className="wp-icon-btn"
            onClick={onClose}
            title="إغلاق المعاينة"
            aria-label="إغلاق المعاينة"
          >
            <i className="fa-solid fa-xmark" />
          </button>
        </div>
      </div>

      <div className="ord-insp-scroll">
        <div className="ord-insp-hero">
          <div className="ord-insp-hero-total">
            <span>مبلغ الاسترداد</span>
            <strong className="num">{fmt(full.refund)} <small>EGP</small></strong>
          </div>
          <div className="ord-insp-hero-meta">
            <Badge map={RETURN_STATUS} k={full.status} />
            {full.method && <span className="ord-method-pill">{REFUND_METHOD[full.method] || full.method}</span>}
            {items.length > 0 && <span className="ord-method-pill num">{items.length} صنف</span>}
          </div>
        </div>

        <div className="ord-insp-section">
          <div className="ord-insp-sec-head">
            <span>مراحل المرتجع</span>
            <span className="panel-en">TIMELINE</span>
          </div>
          <ReturnMiniTrack ret={full} />
        </div>

        <div className="ord-insp-section">
          <div className="ord-insp-sec-head">
            <span>البنود المرتجعة {items.length ? <span className="num">({items.length})</span> : ''}</span>
            <span className="panel-en">ITEMS</span>
          </div>
          {needsDetail && detailApi.loading ? (
            <SkeletonRows rows={3} cols={2} />
          ) : items.length ? (
            <ul className="ord-insp-items">
              {items.slice(0, 5).map((it, idx) => (
                <li key={it.sku || idx} className="ord-insp-item">
                  <Thumb sm />
                  <div className="ord-insp-item-info">
                    <div className="ord-insp-item-name">{it.name || it.sku || 'منتج'}</div>
                    <div className="ord-insp-item-meta">
                      {it.sku && <span className="sku">{it.sku}</span>}
                      <span className="num">×{it.qty}</span>
                      {it.reason && <span>{it.reason}</span>}
                    </div>
                  </div>
                  {it.photos > 0 && (
                    <span className="ord-method-pill num" title="صور مرفقة">
                      <i className="fa-regular fa-image" /> {it.photos}
                    </span>
                  )}
                </li>
              ))}
              {items.length > 5 && (
                <li className="ord-insp-more">
                  <button type="button" className="btn-link" onClick={() => onOpenFull(full.id)}>
                    +{items.length - 5} بنود أخرى في صفحة التفاصيل
                  </button>
                </li>
              )}
            </ul>
          ) : (
            <p className="muted small" style={{ margin: 0 }}>اضغط &quot;عرض التفاصيل الكاملة&quot; لعرض بنود المرتجع.</p>
          )}
        </div>

        {(full.reason || full.ref) && (
          <div className="ord-insp-section">
            <div className="ord-insp-sec-head">
              <span>تفاصيل إضافية</span>
              <span className="panel-en">DETAILS</span>
            </div>
            <div className="qt-notes-stack">
              {full.reason && (
                <div className="qt-note-mini">
                  <span>السبب العام</span>
                  <p>{full.reason}</p>
                </div>
              )}
              {full.ref && (
                <div className="qt-note-mini is-admin">
                  <span>مرجع الاسترداد</span>
                  <p className="num">{full.ref}</p>
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      <div className="ord-insp-foot">
        <button type="button" className="btn-pill is-dark ord-insp-primary" onClick={() => onOpenFull(full.id)}>
          <i className="fa-solid fa-eye" /> عرض التفاصيل الكاملة
        </button>
        {full.order_id && (
          <div className="ord-insp-secondary">
            <button type="button" className="btn-pill sm" onClick={() => onOpenOrder(full.order_id)}>
              <i className="fa-solid fa-box" /> فتح الطلب <span className="num">{full.order || full.order_id}</span>
            </button>
          </div>
        )}
        {!isSheet && (
          <div className="ord-kbd-hint" aria-hidden="true">
            <span><kbd>↑</kbd> <kbd>↓</kbd> للتنقل</span>
            <span><kbd>Enter</kbd> للتفاصيل</span>
            <span><kbd>/</kbd> للبحث</span>
          </div>
        )}
      </div>
    </aside>
  );
};

export const Returns = () => {
  const { go } = useApp();
  const [f, setF] = useState('all');
  const [q, setQ] = useState('');
  const [sortBy, setSortBy] = useState('newest');
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState(null);
  const [mobilePreviewId, setMobilePreviewId] = useState(null);
  const [inspectorOpen, setInspectorOpen] = useState(() => {
    if (typeof window === 'undefined') return true;
    try { return window.localStorage.getItem('varya_returns_inspector') !== '0'; } catch { return true; }
  });
  const searchRef = useRef(null);

  const toggleInspector = (next) => {
    setInspectorOpen((prev) => {
      const val = typeof next === 'boolean' ? next : !prev;
      try { window.localStorage.setItem('varya_returns_inspector', val ? '1' : '0'); } catch {}
      return val;
    });
  };

  useEffect(() => {
    const onGlobalKey = (e) => {
      if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
      const tag = document.activeElement?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || document.activeElement?.isContentEditable) return;
      if (searchRef.current) {
        e.preventDefault();
        searchRef.current.focus();
      }
    };
    window.addEventListener('keydown', onGlobalKey);
    return () => window.removeEventListener('keydown', onGlobalKey);
  }, []);

  const api = useApi(() => accountEndpoints.returns(page)
    .then((r) => ({ items: (r.data || []).map(mapReturn), meta: pageMeta(r.meta) })), [page]);
  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 20 };

  const activeCountInView = useMemo(
    () => list.filter((r) => ['requested', 'approved', 'received'].includes(r.status)).length,
    [list],
  );
  const refundedCountInView = useMemo(
    () => list.filter((r) => r.status === 'refunded').length,
    [list],
  );

  const filtered = useMemo(() => {
    const query = q.trim().toLowerCase();
    let rows = list.filter((r) => {
      if (f === 'active_group') {
        if (!['requested', 'approved', 'received'].includes(r.status)) return false;
      } else if (f !== 'all' && r.status !== f) {
        return false;
      }
      if (!query) return true;
      const haystack = [
        r.number,
        r.order,
        r.date,
        r.reason,
        r.ref,
        ...(r.items || []).flatMap((it) => [it.name, it.sku]),
      ].filter(Boolean).join(' ').toLowerCase();
      return haystack.includes(query);
    });
    if (sortBy === 'oldest') {
      rows = [...rows].sort((a, b) => String(a.date || '').localeCompare(String(b.date || '')) || a.id - b.id);
    } else if (sortBy === 'highest') {
      rows = [...rows].sort((a, b) => (b.refund ?? 0) - (a.refund ?? 0));
    }
    return rows;
  }, [list, f, q, sortBy]);

  const pageRefundTotal = useMemo(
    () => filtered.reduce((sum, r) => sum + (r.refund ?? 0), 0),
    [filtered],
  );

  useEffect(() => {
    if (!filtered.length) {
      setSelectedId(null);
      return;
    }
    if (!filtered.some((r) => r.id === selectedId)) {
      setSelectedId(filtered[0].id);
    }
  }, [filtered, selectedId]);

  const selectedReturn = useMemo(
    () => filtered.find((r) => r.id === selectedId) || null,
    [filtered, selectedId],
  );
  const mobilePreviewReturn = useMemo(
    () => filtered.find((r) => r.id === mobilePreviewId) || null,
    [filtered, mobilePreviewId],
  );

  useEffect(() => {
    if (!mobilePreviewReturn) return undefined;
    const onEsc = (e) => { if (e.key === 'Escape') setMobilePreviewId(null); };
    window.addEventListener('keydown', onEsc);
    return () => window.removeEventListener('keydown', onEsc);
  }, [mobilePreviewReturn]);

  const hasLocalFilter = Boolean(f !== 'all' || q.trim() || sortBy !== 'newest');
  const clearLocalFilters = () => {
    setF('all');
    setQ('');
    setSortBy('newest');
  };

  const handleRowClick = (r) => {
    if (typeof window !== 'undefined' && window.innerWidth >= 1100) {
      if (inspectorOpen && selectedId === r.id) {
        toggleInspector(false);
      } else {
        setSelectedId(r.id);
        if (!inspectorOpen) toggleInspector(true);
      }
    } else {
      go('return', r.id);
    }
  };

  const handleRowKeyDown = (e, r, idx) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      go('return', r.id);
    } else if (e.key === 'Escape' && inspectorOpen) {
      e.preventDefault();
      toggleInspector(false);
    } else if (e.key === 'ArrowDown' && idx < filtered.length - 1) {
      e.preventDefault();
      setSelectedId(filtered[idx + 1].id);
    } else if (e.key === 'ArrowUp' && idx > 0) {
      e.preventDefault();
      setSelectedId(filtered[idx - 1].id);
    }
  };

  return (
    <>
      <PageHead en="Returns" ar="طلبات الإرجاع والاسترداد">
        <button type="button" className="btn-pill is-dark" onClick={() => go('returnNew')}>
          <i className="fa-solid fa-plus" /> طلب إرجاع
        </button>
      </PageHead>

      <div className="ord-summary-bar" role="region" aria-label="ملخص المرتجعات">
        <div className="ord-stat">
          <span className="ord-stat-lbl">إجمالي المرتجعات</span>
          <strong className="ord-stat-val num">{meta.total}</strong>
        </div>
        <button
          type="button"
          className={`ord-stat is-btn${f === 'active_group' ? ' is-on' : ''}`}
          onClick={() => setF(f === 'active_group' ? 'all' : 'active_group')}
        >
          <span className="ord-stat-dot is-warn" />
          <span className="ord-stat-lbl">قيد المتابعة</span>
          <strong className="ord-stat-val num">{activeCountInView}</strong>
        </button>
        <div className="ord-stat">
          <span className="ord-stat-lbl">استرداد المعروض</span>
          <strong className="ord-stat-val num">{fmt(pageRefundTotal)} <small>EGP</small></strong>
        </div>
        <button
          type="button"
          className={`ord-stat is-btn${f === 'refunded' ? ' is-on' : ''}`}
          onClick={() => setF(f === 'refunded' ? 'all' : 'refunded')}
        >
          <span className="ord-stat-dot is-info" />
          <span className="ord-stat-lbl">تم رد المبلغ</span>
          <strong className="ord-stat-val num">{refundedCountInView}</strong>
        </button>
      </div>

      <div className={`ord-workspace${inspectorOpen ? '' : ' is-collapsed'}`}>
        <section className="panel ord-list-panel">
          <div className="toolbar ord-toolbar">
            <div className="ord-toolbar-row">
              <div className="chips ord-chips">
                <button
                  type="button"
                  className={`chip${f === 'all' ? ' is-on' : ''}`}
                  onClick={() => setF('all')}
                >
                  الكل
                </button>
                {Object.entries(RETURN_STATUS).map(([k, [l]]) => (
                  <button
                    key={k}
                    type="button"
                    className={`chip${f === k ? ' is-on' : ''}`}
                    onClick={() => setF(k)}
                  >
                    {l}
                  </button>
                ))}
              </div>
              <div className="ord-toolbar-meta">
                {hasLocalFilter && (
                  <button type="button" className="btn-link muted small" onClick={clearLocalFilters}>
                    مسح الفلاتر ({filtered.length}/{list.length})
                  </button>
                )}
                <button
                  type="button"
                  className={`ord-insp-toggle${inspectorOpen ? ' is-on' : ''}`}
                  onClick={() => toggleInspector()}
                  aria-pressed={inspectorOpen}
                  title={inspectorOpen ? 'إخفاء لوحة المعاينة السريعة' : 'إظهار لوحة المعاينة السريعة'}
                >
                  <i className="fa-solid fa-table-columns" />
                  <span>{inspectorOpen ? 'إخفاء المعاينة' : 'معاينة سريعة'}</span>
                </button>
              </div>
            </div>

            <div className="ord-controls qt-controls">
              <div className="ord-search-box">
                <i className="fa-solid fa-magnifying-glass" aria-hidden="true" />
                <input
                  ref={searchRef}
                  type="search"
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder="ابحث برقم المرتجع، رقم الطلب، السبب، أو الصنف..."
                  aria-label="بحث في المرتجعات"
                />
                {q ? (
                  <button type="button" className="ord-search-clear" onClick={() => setQ('')} aria-label="مسح البحث">
                    <i className="fa-solid fa-xmark" />
                  </button>
                ) : (
                  <kbd className="ord-search-kbd" aria-hidden="true">/</kbd>
                )}
              </div>

              <select
                className="ord-select"
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value)}
                aria-label="ترتيب المرتجعات"
              >
                <option value="newest">الأحدث أولاً</option>
                <option value="oldest">الأقدم أولاً</option>
                <option value="highest">الأعلى استرداداً</option>
              </select>
            </div>
          </div>

          <DataBoundary api={api}>
            <Async
              rows={4}
              cols={6}
              isEmpty={!filtered.length}
              empty={hasLocalFilter ? (
                <Empty
                  icon="fa-filter-circle-xmark"
                  title="لا توجد مرتجعات مطابقة للبحث"
                  desc="جرّب تغيير كلمات البحث أو مسح الفلاتر."
                  action={<button type="button" className="btn-pill is-dark" onClick={clearLocalFilters}>مسح الفلاتر</button>}
                />
              ) : (
                <Empty
                  icon="fa-rotate-left"
                  title="مفيش مرتجعات"
                  desc="تقدر تطلب إرجاع لأي طلب مؤهل بعد التسليم."
                  action={<button type="button" className="btn-pill is-dark" onClick={() => go('returnNew')}>طلب إرجاع</button>}
                />
              )}
            >
              <div className="tbl-wrap ord-tbl-wrap">
                <table className="tbl ord-tbl">
                  <thead>
                    <tr>
                      <th>رقم المرتجع</th>
                      <th>الطلب</th>
                      <th>الحالة</th>
                      <th>التاريخ</th>
                      <th className="t-end">مبلغ الاسترداد</th>
                      <th className="t-end"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((r, idx) => {
                      const isSel = inspectorOpen && r.id === selectedId;
                      return (
                        <tr
                          key={r.id}
                          tabIndex={0}
                          className={`is-click${isSel ? ' is-selected' : ''}`}
                          onClick={() => handleRowClick(r)}
                          onDoubleClick={() => go('return', r.id)}
                          onKeyDown={(e) => handleRowKeyDown(e, r, idx)}
                        >
                          <td>
                            <div className="strong num">{r.number}</div>
                            {(r.items?.length > 0 || r.method) && (
                              <div className="ord-row-sub">
                                {[
                                  r.items?.length ? `${r.items.length} صنف` : null,
                                  r.method ? (REFUND_METHOD[r.method] || r.method) : null,
                                ].filter(Boolean).join(' · ')}
                              </div>
                            )}
                          </td>
                          <td className="num">{r.order}</td>
                          <td><Badge map={RETURN_STATUS} k={r.status} /></td>
                          <td className="ord-date-cell num">{r.date}</td>
                          <td className="t-end strong num">
                            {fmt(r.refund)} <span className="ord-curr">EGP</span>
                          </td>
                          <td className="t-end" onClick={(e) => e.stopPropagation()}>
                            <div className="ord-row-icons">
                              {r.order_id && (
                                <button
                                  type="button"
                                  className="ord-row-btn"
                                  title="فتح الطلب"
                                  aria-label={`فتح الطلب ${r.order}`}
                                  onClick={() => go('order', r.order_id)}
                                >
                                  <i className="fa-solid fa-box" />
                                </button>
                              )}
                              <button
                                type="button"
                                className="ord-row-btn"
                                title="فتح تفاصيل المرتجع"
                                aria-label={`فتح المرتجع ${r.number}`}
                                onClick={() => go('return', r.id)}
                              >
                                <i className="fa-solid fa-arrow-up-right-from-square" />
                              </button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <div className="ord-mobile-list">
                {filtered.map((r) => {
                  const subMeta = [
                    r.order ? `طلب ${r.order}` : null,
                    r.items?.length ? `${r.items.length} صنف` : null,
                  ].filter(Boolean).join(' · ');
                  return (
                    <article
                      key={r.id}
                      className="ord-m-card"
                      onClick={() => setMobilePreviewId(r.id)}
                      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setMobilePreviewId(r.id); } }}
                      tabIndex={0}
                      role="button"
                      aria-label={`معاينة المرتجع ${r.number}`}
                    >
                      <div className="ord-m-head">
                        <div className="ord-m-id">
                          <span className="ord-m-num num">{r.number}</span>
                          <span className="ord-m-date num">
                            {r.date}{subMeta ? ` · ${subMeta}` : ''}
                          </span>
                        </div>
                        <div className="ord-m-head-end">
                          <Badge map={RETURN_STATUS} k={r.status} />
                          <i className="fa-solid fa-chevron-left ord-m-chev" aria-hidden="true" />
                        </div>
                      </div>
                      <div className="ord-m-mid">
                        <div className="ord-m-pay">
                          <span className="ord-m-method">{REFUND_METHOD[r.method] || r.method || 'استرداد'}</span>
                        </div>
                        <div className="ord-m-total">
                          <span className="ord-m-total-lbl">مبلغ الاسترداد</span>
                          <strong className="num">{fmt(r.refund)} <small>EGP</small></strong>
                        </div>
                      </div>
                      <div className="ord-m-actions" onClick={(e) => e.stopPropagation()}>
                        <button type="button" className="btn-pill is-dark ord-m-btn" onClick={() => go('return', r.id)}>
                          <i className="fa-solid fa-eye" aria-hidden="true" /> التفاصيل الكاملة
                        </button>
                        {r.order_id && (
                          <button type="button" className="btn-pill ord-m-btn ord-m-cancel" onClick={() => go('order', r.order_id)}>
                            <i className="fa-solid fa-box" aria-hidden="true" /> الطلب
                          </button>
                        )}
                      </div>
                    </article>
                  );
                })}
              </div>
              <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
            </Async>
          </DataBoundary>
        </section>

        <div className="ord-inspector-slot" aria-hidden={!inspectorOpen}>
          <ReturnInspector
            ret={selectedReturn}
            onClose={() => toggleInspector(false)}
            onOpenFull={(rid) => go('return', rid)}
            onOpenOrder={(oid) => go('order', oid)}
          />
        </div>
      </div>

      {mobilePreviewReturn && (
        <div
          className="ord-sheet-bg"
          onMouseDown={(e) => e.target === e.currentTarget && setMobilePreviewId(null)}
        >
          <div className="ord-sheet" role="dialog" aria-modal="true" aria-label={`معاينة المرتجع ${mobilePreviewReturn.number}`}>
            <div className="ord-sheet-handle" aria-hidden="true" />
            <ReturnInspector
              ret={mobilePreviewReturn}
              isSheet
              onClose={() => setMobilePreviewId(null)}
              onOpenFull={(rid) => { setMobilePreviewId(null); go('return', rid); }}
              onOpenOrder={(oid) => { setMobilePreviewId(null); go('order', oid); }}
            />
          </div>
        </div>
      )}
    </>
  );
};

const RETURN_REASON_PRESETS = ['عيب تصنيع', 'تلف أثناء الشحن', 'منتج غير مطابق', 'زيادة عن الحاجة'];

export const ReturnForm = ({ orderId }) => {
  const { go, toast, route } = useApp();
  const preselect = orderId ?? route.id;
  const [oid, setOid] = useState(preselect || null);
  const [orderQuery, setOrderQuery] = useState('');
  const [sel, setSel] = useState({});
  const [reason, setReason] = useState('');
  const [method, setMethod] = useState('');
  const [errs, setErrs] = useState({});
  const [busy, setBusy] = useState(false);

  const eligibleApi = useApi((signal) => wholesaleEndpoints.orders({ group: 'delivered', returnable: 1, per_page: 100 }, signal)
    .then((r) => (r.data || []).map(mapOrder).filter((o) => o.returnable)), []);
  const eligible = eligibleApi.data || [];
  useEffect(() => { if (!oid && eligible.length) setOid(eligible[0].id); }, [oid, eligible]);

  const filteredEligible = useMemo(() => {
    const q = orderQuery.trim().toLowerCase();
    if (!q) return eligible;
    return eligible.filter((x) => [x.number, x.date, x.city, x.recipient].filter(Boolean).join(' ').toLowerCase().includes(q));
  }, [eligible, orderQuery]);

  const orderApi = useApi((signal) => wholesaleEndpoints.order(oid, signal).then(mapOrder), [oid], !!oid);
  const order = orderApi.state === 'ready' && orderApi.data?.id === oid ? orderApi.data : null;
  useEffect(() => { setSel({}); }, [oid]);

  const toggle = (id) => setSel((s) => ({ ...s, [id]: s[id] ? undefined : { qty: 1, reason: '', files: [] } }));
  const set = (id, k, v) => setSel((s) => ({ ...s, [id]: { ...s[id], [k]: v } }));
  const chosen = (order?.items || []).map((it) => [String(it.id), sel[it.id]]).filter(([, v]) => v);
  const totalFiles = chosen.reduce((a, [, v]) => a + v.files.length, 0);
  const totalReturnedQty = chosen.reduce((a, [, v]) => a + Number(v.qty || 0), 0);
  const estimatedRefund = useMemo(() => {
    if (!order?.items?.length) return 0;
    return order.items.reduce((sum, it) => {
      const s = sel[it.id];
      if (!s) return sum;
      return sum + Number(it.unit || 0) * Number(s.qty || 0);
    }, 0);
  }, [order, sel]);

  const allSelected = Boolean(order?.items?.length && chosen.length === order.items.length);
  const toggleAllItems = () => {
    if (!order?.items?.length) return;
    if (allSelected) {
      setSel({});
    } else {
      const next = {};
      order.items.forEach((it) => {
        next[it.id] = sel[it.id] || { qty: 1, reason: '', files: [] };
      });
      setSel(next);
    }
  };

  const appendReasonPreset = (preset) => {
    setReason((prev) => {
      const trimmed = prev.trim();
      if (trimmed.includes(preset)) return prev;
      return trimmed ? `${trimmed} · ${preset}` : preset;
    });
  };

  const addFiles = (id, list) => {
    const cur = sel[id];
    if (!cur) return;
    const next = [...cur.files, ...list];
    const nextSel = { ...sel, [id]: { ...cur, files: next } };
    const nextFiles = Object.values(nextSel).filter(Boolean).map((v) => v.files);
    const error = validateReturnFiles(nextFiles);
    setErrs((previous) => ({ ...previous, files: error }));
    if (error) {
      toast(error);
      return;
    }
    setSel(nextSel);
  };

  const submit = async () => {
    const e = {};
    if (!oid || !order || !order.returnable) e.order = 'اختر طلبًا مؤهلًا للإرجاع.';
    if (!chosen.length) e.items = 'اختر بند واحد على الأقل.';
    if (!reason.trim()) e.reason = 'السبب العام مطلوب.';
    chosen.forEach(([itemId, v]) => {
      if (v.reason.length > 255) e[itemId] = 'الحد الأقصى 255 حرف.';
      if (!Number.isInteger(Number(v.qty)) || Number(v.qty) < 1) e[`${itemId}-qty`] = 'كمية صحيحة مطلوبة.';
    });
    const fileError = validateReturnFiles(chosen.map(([, v]) => v.files));
    if (fileError) e.files = fileError;
    if (method && !REFUND_METHOD[method]) e.method = 'طريقة استرداد غير صالحة.';
    setErrs(e);
    if (Object.keys(e).length) {
      if (!e.order && !e.items && e.reason && typeof document !== 'undefined') {
        const el = document.getElementById('ret-reason-field');
        el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el?.querySelector('textarea')?.focus({ preventScroll: true });
      }
      return;
    }
    const fd = new FormData();
    fd.append('order_id', String(oid));
    fd.append('reason', reason);
    if (method) fd.append('refund_method', method);
    chosen.forEach(([itemId, v], i) => {
      fd.append(`items[${i}][order_item_id]`, String(itemId));
      fd.append(`items[${i}][requested_qty]`, String(Math.trunc(Number(v.qty))));
      if (v.reason) fd.append(`items[${i}][item_reason]`, v.reason);
      v.files.forEach((fl) => fd.append(`items[${i}][attachments][]`, fl));
    });
    setBusy(true);
    try {
      const r = await accountEndpoints.createReturn(fd);
      go('return', r.id);
    } catch (err) {
      const info = apiError(err);
      const mapped = {};
      Object.entries(info.fields || {}).forEach(([k, msgs]) => {
        const parts = k.split('.');
        const itemId = parts[0] === 'items' ? chosen[Number(parts[1])]?.[0] : null;
        const key = k === 'order_id' ? 'order' : k === 'refund_method' ? 'method'
          : itemId ? (parts[2] === 'requested_qty' ? `${itemId}-qty` : parts[2] === 'item_reason' ? itemId : parts[2] === 'attachments' ? 'files' : 'items') : k;
        mapped[key] = Array.isArray(msgs) ? msgs[0] : String(msgs);
      });
      setErrs(mapped);
      if (!Object.keys(mapped).length) toast(info.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHead
        en="New Return"
        crumb={<Crumb items={[{ label: 'المرتجعات', go: () => go('returns') }, { label: 'طلب جديد' }]} />}
        ar="اختر الطلب والبنود، ووضح السبب"
      >
        <button type="button" className="btn-pill" onClick={() => go('returns')}>
          <i className="fa-solid fa-arrow-right" /> المرتجعات
        </button>
      </PageHead>

      <div className="ord-summary-bar" role="region" aria-label="ملخص طلب الإرجاع">
        <div className="ord-stat">
          <span className="ord-stat-lbl">الطلب المختار</span>
          <strong className="ord-stat-val num">{order?.number || '—'}</strong>
        </div>
        <div className="ord-stat">
          <span className="ord-stat-lbl">الأصناف المختارة</span>
          <strong className="ord-stat-val num">{chosen.length}{order?.items?.length ? <small> / {order.items.length}</small> : null}</strong>
        </div>
        <div className="ord-stat">
          <span className="ord-stat-lbl">القطع المرتجعة</span>
          <strong className="ord-stat-val num">{totalReturnedQty}</strong>
        </div>
        <div className="ord-stat">
          <span className="ord-stat-dot is-info" />
          <span className="ord-stat-lbl">الاسترداد التقديري</span>
          <strong className="ord-stat-val num">{fmt(estimatedRefund)} <small>EGP</small></strong>
        </div>
        <div className="ord-stat">
          <span className="ord-stat-lbl">الصور المرفقة</span>
          <strong className="ord-stat-val num">{totalFiles} <small>/ 10</small></strong>
        </div>
      </div>

      <div className={`grid-2 grid-summary qf-workspace ret-workspace${chosen.length > 0 ? ' has-float' : ''}`}>
        <div className="stack">
          <Panel title="الطلب المؤهل" en="ORDER">
            {errs.order && <p className="f-error">{errs.order}</p>}
            <DataBoundary api={eligibleApi}>
              <Async rows={2} cols={2} isEmpty={!eligible.length} empty={<Empty icon="fa-rotate-left" title="لا توجد طلبات مؤهلة للإرجاع حاليًا." />}>
                {eligible.length > 4 && (
                  <div className="ord-search-box ret-order-search">
                    <i className="fa-solid fa-magnifying-glass" aria-hidden="true" />
                    <input
                      type="search"
                      value={orderQuery}
                      onChange={(e) => setOrderQuery(e.target.value)}
                      placeholder="ابحث برقم الطلب أو التاريخ..."
                      aria-label="بحث في الطلبات المؤهلة"
                    />
                    {orderQuery && (
                      <button type="button" className="ord-search-clear" onClick={() => setOrderQuery('')} aria-label="مسح البحث">
                        <i className="fa-solid fa-xmark" />
                      </button>
                    )}
                  </div>
                )}
                <div className="addr-picks ret-order-picks">
                  {filteredEligible.map((x) => (
                    <button key={x.id} type="button" className={`addr-pick${oid === x.id ? ' is-on' : ''}`} onClick={() => setOid(x.id)}>
                      <b className="num">{x.number}</b>
                      <span>تم التسليم · <span className="num">{x.date}</span> · <span className="num">{fmt(x.total)}</span> EGP</span>
                    </button>
                  ))}
                  {!filteredEligible.length && eligible.length > 0 && (
                    <p className="muted small">لا توجد طلبات مطابقة للبحث.</p>
                  )}
                </div>
              </Async>
            </DataBoundary>
            <p className="note mt-16"><i className="fa-solid fa-circle-info" />الطلبات المؤهلة بس هي اللي بتظهر هنا (تم تسليمها وضمن مدة الإرجاع).</p>
          </Panel>

          <Panel
            title="البنود المرتجعة"
            en="ITEMS"
            action={order?.items?.length > 1 && (
              <button type="button" className="btn-link muted small" onClick={toggleAllItems}>
                {allSelected ? 'إلغاء تحديد الكل' : 'تحديد كل البنود'}
              </button>
            )}
          >
            {errs.items && <p className="f-error" style={{ marginTop: 0 }}>{errs.items}</p>}
            {oid && (
              <DataBoundary api={orderApi}>
                <Async rows={3} cols={3}>
                  {(order?.items || []).map((it) => {
                    const s = sel[it.id];
                    const itemRefund = s ? Number(it.unit || 0) * Number(s.qty || 0) : null;
                    return (
                      <div key={it.id} className={`ret-item${s ? ' is-on' : ''}`}>
                        <label className="row ret-item-check">
                          <input type="checkbox" checked={!!s} onChange={() => toggle(it.id)} />
                          <Thumb sm />
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <div className="prod-name">{it.name}</div>
                            <div className="soft small">
                              <span className="sku">{it.sku}</span> · تم شراء <span className="num">{it.qty}</span> · <span className="num">{fmt(it.unit)}</span> EGP
                            </div>
                          </div>
                          {itemRefund != null && (
                            <span className="ret-item-price num">{fmt(itemRefund)} <small>EGP</small></span>
                          )}
                        </label>
                        {s && (
                          <div className="fields mt-16 ret-item-fields">
                            <Field label="الكمية المرتجعة" req hint={`حتى ${it.qty}`} error={errs[`${it.id}-qty`]}>
                              <div className="ret-qty-row">
                                <Qty value={s.qty} min={1} onChange={(n) => set(it.id, 'qty', Math.min(n, it.qty))} />
                                {it.qty > 1 && s.qty < it.qty && (
                                  <button type="button" className="qf-preset-chip" onClick={() => set(it.id, 'qty', it.qty)}>
                                    الكل ({it.qty})
                                  </button>
                                )}
                              </div>
                            </Field>
                            <Field label="سبب البند" opt error={errs[it.id]}>
                              <input
                                className={`line-input${errs[it.id] ? ' is-invalid' : ''}`}
                                value={s.reason}
                                placeholder="مثال: عيب في السحّاب"
                                onChange={(e) => set(it.id, 'reason', e.target.value)}
                              />
                            </Field>
                            <Field label="صور" opt span hint="حتى 5 صور للبند · 5 ميجابايت للصورة · JPG / PNG / WebP" error={errs.files}>
                              <div className="row wrap">
                                {s.files.map((fl, k) => (
                                  <button
                                    key={k}
                                    type="button"
                                    className="photo-chip"
                                    title={`حذف ${fl.name}`}
                                    aria-label={`حذف ${fl.name}`}
                                    onClick={() => { set(it.id, 'files', s.files.filter((_, index) => index !== k)); setErrs((previous) => ({ ...previous, files: null })); }}
                                  >
                                    <i className="fa-regular fa-image" aria-hidden="true" />
                                    <span className="ret-photo-del" aria-hidden="true"><i className="fa-solid fa-xmark" /></span>
                                  </button>
                                ))}
                                {s.files.length < 5 && totalFiles < 10 && (
                                  <label className="drop sm" style={{ minHeight: 52, width: 52, padding: 0, justifyContent: 'center', cursor: 'pointer' }} aria-label="إضافة صورة">
                                    <input
                                      type="file"
                                      accept=".jpg,.jpeg,.png,.webp"
                                      multiple
                                      style={{ display: 'none' }}
                                      onChange={(e) => { addFiles(it.id, Array.from(e.target.files || [])); e.target.value = ''; }}
                                    />
                                    <i className="fa-solid fa-plus" aria-hidden="true" />
                                  </label>
                                )}
                              </div>
                            </Field>
                          </div>
                        )}
                      </div>
                    );
                  })}
                </Async>
              </DataBoundary>
            )}
            {errs.files && <p className="f-error" role="alert">{errs.files}</p>}
          </Panel>
        </div>

        <aside className="stack qf-aside">
          <section className="panel qf-summary-panel">
            <div className="ord-insp-head">
              <div>
                <div className="ord-insp-eyebrow">RETURN SUMMARY</div>
                <h2 className="panel-title" style={{ marginTop: 2 }}>ملخص الإرجاع والاسترداد</h2>
              </div>
              {order && <span className="ord-method-pill num">{order.number}</span>}
            </div>

            <div className="ord-insp-hero">
              <div className="ord-insp-hero-total">
                <span>الاسترداد التقديري</span>
                <strong className="num">{fmt(estimatedRefund)} <small>EGP</small></strong>
              </div>
              <div className="ord-insp-hero-meta">
                <span className="ord-method-pill num">{chosen.length} صنف</span>
                <span className="ord-method-pill num">{totalReturnedQty} قطعة</span>
                <span className="ord-method-pill num">{totalFiles}/10 صور</span>
              </div>
            </div>

            <div className="qf-notes-box">
              <div id="ret-reason-field">
                <Field label="السبب العام" req error={errs.reason}>
                  <textarea
                    className={`line-input${errs.reason ? ' is-invalid' : ''}`}
                    rows="3"
                    value={reason}
                    placeholder="اشرح سبب طلب الإرجاع باختصار…"
                    onChange={(e) => setReason(e.target.value)}
                  />
                </Field>
              </div>
              <div className="qf-note-presets" aria-label="أسباب شائعة">
                {RETURN_REASON_PRESETS.map((preset) => (
                  <button key={preset} type="button" className="qf-preset-chip" onClick={() => appendReasonPreset(preset)}>
                    + {preset}
                  </button>
                ))}
              </div>
            </div>

            <div>
              <div className="f-label">طريقة الاسترداد</div>
              <div className="radio-list">
                {Object.entries(REFUND_METHOD).map(([k, l]) => (
                  <label key={k} className="radio-row">
                    <span className="radio-left">
                      <input type="radio" checked={method === k} onChange={() => setMethod(k)} />
                      <span className="radio-title">{l}</span>
                    </span>
                  </label>
                ))}
              </div>
              {errs.method && <p className="f-error">{errs.method}</p>}
            </div>

            <div className="ord-insp-foot">
              <button
                type="button"
                className="btn-pill is-dark ord-insp-primary"
                style={{ padding: '12px 18px', fontSize: 13.5 }}
                disabled={busy || !order || !order.returnable}
                onClick={submit}
              >
                {busy ? <><span className="spin" /> جارٍ التقديم…</> : <><i className="fa-solid fa-rotate-left" /> تقديم طلب الإرجاع</>}
              </button>
            </div>
          </section>

          <div className="qt-insp-banner is-info">
            <i className="fa-solid fa-circle-info" />
            <span>إجمالي حجم الصور حتى 25 ميجابايت. مبلغ الاسترداد النهائي بيتحدد بعد فحص المرتجع.</span>
          </div>
        </aside>
      </div>
      {chosen.length > 0 && order && order.returnable && (
        <div className="qf-float-bar" role="region" aria-label="ملخص وتقديم طلب الإرجاع">
          <div className="qf-float-info">
            <strong className="num">{chosen.length} بند محدد</strong>
            <small className="num">{totalFiles} / 10 صور</small>
          </div>
          <div className="qf-float-actions">
            <button type="button" className="btn-pill is-dark" disabled={busy} onClick={submit}>
              {busy ? <span className="spin" /> : <><i className="fa-solid fa-paper-plane" aria-hidden="true" /> <span>تقديم الطلب</span></>}
            </button>
          </div>
        </div>
      )}
    </>
  );
};

export const ReturnDetail = ({ id }) => {
  const { go } = useApp();
  const api = useApi(() => accountEndpoints.returnDetail(id).then(mapReturn), [id]);
  const r = api.data;
  const steps = r ? [['requested', r.date], ['approved', r.approved_at], ['received', r.received_at], ['refunded', r.refunded_at]] : [];

  const { toast } = useApp();
  const dlPhoto = async (url) => {
    try {
      await saveBlob(defaultApiClient.get(url, { responseType: 'blob', params: { signed: 1 } }), 'photo.jpg');
    } catch (e) {
      toast(apiError(e).message);
    }
  };

  return (
    <>
      <PageHead en={r?.number || ''} crumb={<Crumb items={[{ label: 'المرتجعات', go: () => go('returns') }, { label: r?.number || '', mono: true }]} />} ar={r ? <>على الطلب <button className="btn-link" onClick={() => go('order', r.order_id)}><span className="num">{r.order}</span></button></> : null}>
        {r && <Badge map={RETURN_STATUS} k={r.status} lg />}
      </PageHead>
      <DataBoundary api={api}>
        <Async rows={4} cols={4} empty={<Empty icon="fa-rotate-left" title="المرتجع غير موجود" />}>
          {r && (
          <div className="grid-2">
            <div className="stack">
              <Panel title="البنود" en="ITEMS" body={false}>
                <div className="tbl-wrap ord-tbl-wrap"><table className="tbl"><thead><tr><th>المنتج</th><th>الكمية</th><th>السبب</th><th>صور</th></tr></thead>
                  <tbody>{r.items.map((it, i) => <tr key={i}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td><td className="num">{it.qty}</td><td>{it.reason}</td><td><div className="row" style={{ gap: 6 }}>{(it.attachments || []).map((u, k) => <button key={k} className="photo-chip" style={{ width: 36, height: 36 }} title="تنزيل الصورة" onClick={() => dlPhoto(u)}><i className="fa-regular fa-image" /></button>)}{it.photos > 0 && !it.attachments?.length && <span className="soft small num">{it.photos}</span>}</div></td></tr>)}</tbody></table></div>
                <div className="ord-items-mobile">
                  {r.items.map((it, i) => (
                    <div key={i} className="ord-item-m">
                      <div className="ord-item-m-top">
                        <Thumb sm />
                        <div className="ord-item-m-info">
                          <div className="prod-name">{it.name}</div>
                          <div className="sku">{it.sku}</div>
                        </div>
                      </div>
                      <div className="ord-item-m-foot">
                        <span>الكمية: <strong className="num">{it.qty}</strong>{it.reason ? ` · ${it.reason}` : ''}</span>
                        <div className="row" style={{ gap: 6 }}>
                          {(it.attachments || []).map((u, k) => (
                            <button key={k} type="button" className="photo-chip ret-photo-btn" title="تنزيل الصورة" aria-label="تنزيل الصورة" onClick={() => dlPhoto(u)}>
                              <i className="fa-regular fa-image" aria-hidden="true" />
                            </button>
                          ))}
                          {it.photos > 0 && !it.attachments?.length && <span className="soft small num">{it.photos} صور</span>}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </Panel>
              <Panel title="السبب العام"><p style={{ margin: 0 }}>{r.reason}</p></Panel>
            </div>
            <div className="stack">
              <Panel title="الاسترداد" en="REFUND">
                <dl className="kv"><dt>المبلغ</dt><dd><Money v={r.refund} strong /></dd><dt>الطريقة</dt><dd>{REFUND_METHOD[r.method] || r.method || '—'}</dd><dt>المرجع</dt><dd className="sku">{r.ref || '—'}</dd></dl>
              </Panel>
              <Panel title="المراحل" en="TIMELINE">
                <ul className="tl">{steps.map(([s, d], i) => <li key={s} className={d ? (steps[i + 1] && steps[i + 1][1] ? 'is-done' : 'is-now') : 'is-future'}><div className="tl-title">{RETURN_STATUS[s] ? RETURN_STATUS[s][0] : s}</div>{d && <div className="tl-meta num">{d}</div>}</li>)}</ul>
              </Panel>
            </div>
          </div>
          )}
        </Async>
      </DataBoundary>
    </>
  );
};

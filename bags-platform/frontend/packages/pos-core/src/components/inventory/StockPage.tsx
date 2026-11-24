import React, { useState, useEffect, useRef } from 'react';
import { Icon } from '../ui/Icons';
import { fmt, Empty, cx } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import type { AppScreen, Branch, PosStockRow } from '../../types/pos.types';

interface StockPageProps {
  branch?: Branch | null;
  onScreenChange?: (screen: AppScreen) => void;
}

function getStockStatus(row: PosStockRow, lowFilter: boolean) {
  const stock = Number(row.available);
  const reorder = row.reorder_level;
  const st: 'out' | 'low' | 'ok' = stock <= 0 ? 'out' : row.is_low_stock || lowFilter ? 'low' : 'ok';
  const denominator = reorder > 0 ? reorder * 3 : Math.max(1, stock);
  const pct = Math.max(0, Math.min(100, (stock / denominator) * 100));
  const barColor = st === 'ok' ? 'var(--ok)' : st === 'low' ? 'var(--warn)' : 'var(--bad)';
  return { stock, reorder, st, pct, barColor };
}

function StockBadge({ st }: { st: 'out' | 'low' | 'ok' }) {
  if (st === 'out') {
    return (
      <span className="badge b-bad">
        <span className="dot" />
        نفد
      </span>
    );
  }
  if (st === 'low') {
    return (
      <span className="badge b-warn">
        <span className="dot" />
        منخفض
      </span>
    );
  }
  return (
    <span className="badge b-ok">
      <span className="dot" />
      متوفر
    </span>
  );
}

export function StockPage({ branch }: StockPageProps) {
  const [q, setQ] = useState('');
  const [low, setLow] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [rows, setRows] = useState<PosStockRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [reloadNonce, setReloadNonce] = useState(0);
  const searchRef = useRef<HTMLInputElement>(null);

  // Focus search automatically only on desktop pointer devices so mobile
  // doesn't pop up the virtual keyboard over the stock list on entry.
  useEffect(() => {
    if (typeof window === 'undefined') return;
    if (window.innerWidth > 760 && window.matchMedia?.('(pointer: fine)').matches) {
      searchRef.current?.focus();
    }
  }, []);

  useEffect(() => {
    let active = true;
    let request = 0;
    const load = async () => {
      const current = ++request;
      setLoading(true);
      setFailed(false);
      try {
        const result = await pos.getStockPage(q.trim(), low, page);
        if (!active || current !== request) return;
        setRows(result.rows);
        setLastPage(result.lastPage);
        if (page > result.lastPage) setPage(result.lastPage);
      } catch {
        if (active && current === request) {
          setRows([]);
          setFailed(true);
        }
      } finally {
        if (active && current === request) setLoading(false);
      }
    };
    const timer = setTimeout(load, q ? 200 : 0);
    const unsubscribe = pos.subscribe(load);
    return () => {
      active = false;
      clearTimeout(timer);
      unsubscribe();
    };
  }, [branch?.id, q, low, page, reloadNonce]);

  return (
    <div className="page" data-screen-label="08 Branch Stock">
      <div className="page-in page-enter">
        <div className="page-title">
          <div>
            <h1>مخزون الفرع</h1>
            <p>أرصدة {branch?.name || 'الفرع'} لحظيًا — بدون مغادرة نقطة البيع.</p>
          </div>
        </div>
        <div className="card pos-stock-card">
          <div className="pos-stock-toolbar">
            <div className="input-group pos-stock-search">
              <Icon n="barcode" s={16} />
              <input
                ref={searchRef}
                type="search"
                enterKeyHint="search"
                className="input"
                value={q}
                onChange={(e) => { setQ(e.target.value); setPage(1); }}
                placeholder="SKU أو الاسم أو الباركود"
                aria-label="ابحث بالاسم أو الكود أو الباركود"
              />
              {q && (
                <button
                  type="button"
                  className="pos-stock-clear"
                  aria-label="مسح البحث"
                  onClick={() => {
                    setQ('');
                    setPage(1);
                    searchRef.current?.focus();
                  }}
                >
                  <Icon n="x" s={15} />
                </button>
              )}
            </div>
            <div className="pos-stock-filters">
              <button
                type="button"
                className={cx('chip pos-stock-low-chip', low && 'on')}
                aria-pressed={low}
                onClick={() => { setLow(!low); setPage(1); }}
              >
                <Icon n="alert" s={14} />
                <span>الأصناف المنخفضة</span>
              </button>
              {!loading && !failed && rows.length > 0 && (
                <span className="pos-stock-count num">
                  {fmt(rows.length)} صنف
                </span>
              )}
            </div>
          </div>

          {/* Desktop Table */}
          <div className="table-wrap pos-stock-table-wrap">
            <table className="tbl">
              <thead>
                <tr>
                  <th>الصنف</th>
                  <th>الكود</th>
                  <th>الباركود</th>
                  <th>الموقع</th>
                  <th>المستوى</th>
                  <th className="n">الرصيد المتاح</th>
                  <th className="n">حد إعادة الطلب</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {!loading && rows.map((row) => {
                  const { stock, reorder, st, pct, barColor } = getStockStatus(row, low);
                  return (
                    <tr key={row.id}>
                      <td>
                        <div className="row gap-3">
                          <span className="fw-5">{row.variant?.product_name_ar || row.variant?.product_name_en || row.variant?.sku || row.product_variant_id}</span>
                        </div>
                      </td>
                      <td>
                        <span className="mono">{row.variant?.sku || '—'}</span>
                      </td>
                      <td>
                        <span className="mono t-3">{row.variant?.barcode || '—'}</span>
                      </td>
                      <td>{row.location?.name || '—'}</td>
                      <td>
                        <div className="lvl">
                          <span style={{ width: pct + '%', background: barColor }} />
                          <i style={{ insetInlineStart: 100 / 3 + '%' }} />
                        </div>
                      </td>
                      <td className="n">
                        <span
                          className={cx(
                            'fs-16 fw-6',
                            st === 'out' ? 't-bad' : st === 'low' ? 't-warn' : ''
                          )}
                        >
                          {fmt(stock)}
                        </span>
                      </td>
                      <td className="n t-2">{fmt(reorder)}</td>
                      <td>
                        <StockBadge st={st} />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {/* Mobile Card List */}
          {!loading && !failed && rows.length > 0 && (
            <ul className="pos-stock-cards" aria-label="قائمة أصناف المخزون">
              {rows.map((row) => {
                const { stock, reorder, st, pct, barColor } = getStockStatus(row, low);
                const name = row.variant?.product_name_ar || row.variant?.product_name_en || row.variant?.sku || String(row.product_variant_id);
                const sku = row.variant?.sku;
                const barcode = row.variant?.barcode;
                const loc = row.location?.name;
                return (
                  <li key={row.id} className={cx('pos-stock-item', `is-${st}`)}>
                    <div className="pos-stock-item-head">
                      <span className="pos-stock-item-name">{name}</span>
                      <StockBadge st={st} />
                    </div>
                    {(sku || barcode || loc) && (
                      <div className="pos-stock-item-meta">
                        {sku && <span className="mono ltr">{sku}</span>}
                        {barcode && (
                          <>
                            {sku && <span className="dot-sep" aria-hidden="true" />}
                            <span className="mono ltr t-3">{barcode}</span>
                          </>
                        )}
                        {loc && (
                          <>
                            {(sku || barcode) && <span className="dot-sep" aria-hidden="true" />}
                            <span>{loc}</span>
                          </>
                        )}
                      </div>
                    )}
                    <div className="pos-stock-item-stats">
                      <div className="pos-stock-item-qty">
                        <span className="pos-stock-item-label">الرصيد المتاح</span>
                        <b className={cx('num fs-20 fw-7', st === 'out' ? 't-bad' : st === 'low' ? 't-warn' : 't-1')}>
                          {fmt(stock)}
                        </b>
                      </div>
                      <div className="pos-stock-item-reorder">
                        <span className="pos-stock-item-label">حد الطلب</span>
                        <span className="num fs-13 fw-6 t-2">{fmt(reorder)}</span>
                      </div>
                    </div>
                    <div className="lvl pos-stock-item-lvl" aria-hidden="true">
                      <span style={{ width: pct + '%', background: barColor }} />
                      <i style={{ insetInlineStart: 100 / 3 + '%' }} />
                    </div>
                  </li>
                );
              })}
            </ul>
          )}

          {loading && (
            <div className="pos-stock-loading" role="status" aria-label="جارٍ تحميل المخزون">
              {Array.from({ length: 5 }).map((_, idx) => (
                <div key={idx} className="pos-stock-skel-row">
                  <div className="row between gap-3">
                    <span className="skel" style={{ width: '58%', height: 16 }} />
                    <span className="skel" style={{ width: 64, height: 22, borderRadius: 99 }} />
                  </div>
                  <span className="skel" style={{ width: '40%', height: 12 }} />
                  <span className="skel" style={{ width: '100%', height: 6, borderRadius: 99 }} />
                </div>
              ))}
            </div>
          )}

          {failed && (
            <div className="pos-stock-error" role="alert">
              <Empty
                icon="alert"
                title="تعذّر تحميل المخزون"
                body="تحقق من الاتصال ثم أعد المحاولة."
                action={
                  <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={() => setReloadNonce((n) => n + 1)}
                  >
                    إعادة المحاولة
                  </button>
                }
              />
            </div>
          )}

          {!loading && !failed && rows.length === 0 && (
            <Empty
              icon="search"
              title="لا توجد نتائج"
              body="جرّب كلمة بحث مختلفة أو أزل فلتر المنخفضة."
              action={
                (q || low) ? (
                  <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={() => {
                      setQ('');
                      setLow(false);
                      setPage(1);
                    }}
                  >
                    إظهار كل الأصناف
                  </button>
                ) : undefined
              }
            />
          )}

          {lastPage > 1 && (
            <nav className="pos-stock-pager" aria-label="صفحات المخزون">
              <button
                type="button"
                className="btn btn-secondary"
                disabled={page <= 1 || loading}
                onClick={() => setPage(page - 1)}
              >
                السابق
              </button>
              <span className="pos-stock-page-info num">
                {fmt(page)} / {fmt(lastPage)}
              </span>
              <button
                type="button"
                className="btn btn-secondary"
                disabled={page >= lastPage || loading}
                onClick={() => setPage(page + 1)}
              >
                التالي
              </button>
            </nav>
          )}
        </div>
      </div>
    </div>
  );
}

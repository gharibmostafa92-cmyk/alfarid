import React, { useState, useEffect } from 'react';
import { Icon } from '../ui/Icons';
import { fmt, Api, Empty, cx } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import type { Branch, PosStockRow } from '../../types/pos.types';

interface StockPageProps {
  branch?: Branch | null;
}

export function StockPage({ branch }: StockPageProps) {
  const [q, setQ] = useState('');
  const [low, setLow] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [rows, setRows] = useState<PosStockRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

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
  }, [branch?.id, q, low, page]);

  return (
    <div className="page" data-screen-label="08 Branch Stock">
      <div className="page-in page-enter">
        <div className="page-title">
          <div>
            <h1>مخزون الفرع</h1>
            <p>أرصدة {branch?.name || 'الفرع'} لحظيًا — بدون مغادرة نقطة البيع.</p>
          </div>
          {import.meta.env.DEV && (
            <Api path={`/pos/stock?q=${q || '…'}&low_stock=${low}&per_page=20`} />
          )}
        </div>
        <div className="card" style={{ overflow: 'hidden' }}>
          <div className="toolbar">
            <div className="input-group" style={{ width: 360 }}>
              <Icon n="barcode" s={16} />
              <input
                className="input"
                autoFocus
                value={q}
                onChange={(e) => { setQ(e.target.value); setPage(1); }}
                placeholder="SKU أو الاسم أو الباركود"
              />
            </div>
            <button className={cx('chip', low && 'on')} aria-pressed={low} onClick={() => { setLow(!low); setPage(1); }}>
              <Icon n="alert" s={13} />
              الأصناف المنخفضة
            </button>
          </div>
          <div className="table-wrap">
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
                  const stock = Number(row.available);
                  const reorder = row.reorder_level;
                  const st = stock <= 0 ? 'out' : row.is_low_stock || low ? 'low' : 'ok';
                  const denominator = reorder > 0 ? reorder * 3 : Math.max(1, stock);
                  const pct = Math.max(0, Math.min(100, (stock / denominator) * 100));
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
                          <span
                            style={{
                              width: pct + '%',
                              background:
                                st === 'ok'
                                  ? 'var(--ok)'
                                  : st === 'low'
                                  ? 'var(--warn)'
                                  : 'var(--bad)',
                            }}
                          />
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
                        {st === 'out' ? (
                          <span className="badge b-bad">
                            <span className="dot" />
                            نفد
                          </span>
                        ) : st === 'low' ? (
                          <span className="badge b-warn">
                            <span className="dot" />
                            منخفض
                          </span>
                        ) : (
                          <span className="badge b-ok">
                            <span className="dot" />
                            متوفر
                          </span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {loading && <p role="status">جارٍ تحميل المخزون…</p>}
          {failed && <p role="alert">تعذّر تحميل المخزون.</p>}
          {!loading && !failed && rows.length === 0 && (
            <Empty
              icon="search"
              title="لا توجد نتائج"
              body="جرّب كلمة بحث مختلفة أو أزل فلتر المنخفضة."
            />
          )}
          {lastPage > 1 && (
            <nav className="toolbar" aria-label="صفحات المخزون">
              <button className="btn" disabled={page <= 1 || loading} onClick={() => setPage(page - 1)}>السابق</button>
              <span>{fmt(page)} / {fmt(lastPage)}</span>
              <button className="btn" disabled={page >= lastPage || loading} onClick={() => setPage(page + 1)}>التالي</button>
            </nav>
          )}
        </div>
      </div>
    </div>
  );
}

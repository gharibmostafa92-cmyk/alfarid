import React, { useState, useEffect } from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Api, KPI, FilterSelect, Empty, cx } from '../ui/SharedUI';
import { posMockService } from '../../services/pos-mock.service';
import type { PosProduct, ProductCategory, Branch } from '../../types/pos.types';

interface StockPageProps {
  branch?: Branch | null;
}

export function StockPage({ branch }: StockPageProps) {
  const [q, setQ] = useState('');
  const [low, setLow] = useState(false);
  const [cat, setCat] = useState('all');
  const [products, setProducts] = useState<PosProduct[]>([]);
  const [categories, setCategories] = useState<ProductCategory[]>([]);

  const loadData = () => {
    posMockService.getProducts().then(setProducts);
    posMockService.getCategories().then(setCategories);
  };

  useEffect(() => {
    loadData();
    const unsub = posMockService.subscribe(loadData);
    return unsub;
  }, []);

  const list = products.filter(
    (p) =>
      (!q ||
        p.name.includes(q) ||
        p.sku.toLowerCase().includes(q.toLowerCase()) ||
        p.barcode.includes(q)) &&
      (!low || p.stock <= p.reorder) &&
      (cat === 'all' || p.cat === cat)
  );

  const lowCount = products.filter((p) => p.stock <= p.reorder).length;
  const outCount = products.filter((p) => p.stock === 0).length;

  return (
    <div className="page" data-screen-label="08 Branch Stock">
      <div className="page-in page-enter">
        <div className="page-title">
          <div>
            <h1>مخزون الفرع</h1>
            <p>أرصدة {branch?.name || 'فرع مدينة نصر'} لحظيًا — بدون مغادرة نقطة البيع.</p>
          </div>
          <Api path={`/pos/stock?q=${q || '…'}&low_stock=${low}&per_page=20`} />
        </div>
        <div
          className="grid"
          style={{ gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, marginBottom: 16 }}
        >
          <KPI
            label="أصناف بالفرع"
            value={fmt(products.length)}
            icon="box"
            foot={
              <>
                إجمالي{' '}
                <b className="num t-1">
                  {fmt(products.reduce((s, p) => s + p.stock, 0))}
                </b>{' '}
                قطعة
              </>
            }
          />
          <KPI
            label="منخفضة عن حد الطلب"
            value={fmt(lowCount)}
            icon="alert"
            warn
            onClick={() => setLow(!low)}
            active={low}
            foot="اضغط للفلترة"
          />
          <KPI
            label="نفدت من الفرع"
            value={fmt(outCount)}
            icon="x"
            foot="اطلب تحويل من فرع آخر"
          />
        </div>
        <div className="card" style={{ overflow: 'hidden' }}>
          <div className="toolbar">
            <div className="input-group" style={{ width: 360 }}>
              <Icon n="barcode" s={16} />
              <input
                className="input"
                autoFocus
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="SKU أو الاسم أو الباركود"
              />
            </div>
            <button className={cx('chip', low && 'on')} onClick={() => setLow(!low)}>
              <Icon n="alert" s={13} />
              الأصناف المنخفضة
              <span className="count">{fmt(lowCount)}</span>
            </button>
            <FilterSelect
              label="الفئة"
              value={cat === 'all' ? '' : cat}
              options={categories.slice(1).map((c) => ({ value: c.id, label: c.name }))}
              onChange={(v) => setCat(v || 'all')}
            />
            <span className="ms-auto fs-12 t-3">{fmt(list.length)} صنف</span>
          </div>
          <div className="table-wrap">
            <table className="tbl">
              <thead>
                <tr>
                  <th>الصنف</th>
                  <th>الكود</th>
                  <th>الباركود</th>
                  <th className="n">السعر</th>
                  <th>المستوى</th>
                  <th className="n">الرصيد المتاح</th>
                  <th className="n">حد إعادة الطلب</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {list.map((p) => {
                  const st = p.stock === 0 ? 'out' : p.stock <= p.reorder ? 'low' : 'ok';
                  const pct = Math.min(100, (p.stock / (p.reorder * 3)) * 100);
                  return (
                    <tr key={p.id}>
                      <td>
                        <div className="row gap-3">
                          <PImg p={p} size="sm" />
                          <span className="fw-5">{p.name}</span>
                        </div>
                      </td>
                      <td>
                        <span className="mono">{p.sku}</span>
                      </td>
                      <td>
                        <span className="mono t-3">{p.barcode}</span>
                      </td>
                      <td className="n">{egp(p.price)}</td>
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
                          {fmt(p.stock)}
                        </span>
                      </td>
                      <td className="n t-2">{fmt(p.reorder)}</td>
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
          {list.length === 0 && (
            <Empty
              icon="search"
              title="لا توجد نتائج"
              body="جرّب كلمة بحث مختلفة أو أزل فلتر المنخفضة."
            />
          )}
        </div>
      </div>
    </div>
  );
}

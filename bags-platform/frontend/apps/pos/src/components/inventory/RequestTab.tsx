import React, { useState, useEffect } from 'react';
import { Icon } from '../ui/Icons';
import { fmt, PImg, Api, Empty, Search, Field, cx } from '../ui/SharedUI';
import { posMockService } from '../../services/pos-mock.service';
import type { PosProduct, TransferSource, Branch } from '../../types/pos.types';

interface RequestTabProps {
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
  branch?: Branch | null;
}

export function RequestTab({ toast, branch }: RequestTabProps) {
  const [src, setSrc] = useState<number>(1);
  const [lines, setLines] = useState<{ id: number; qty: number }[]>([
    { id: 106, qty: 8 },
    { id: 112, qty: 10 },
  ]);
  const [q, setQ] = useState('');
  const [note, setNote] = useState('');
  const [allProducts, setAllProducts] = useState<PosProduct[]>([]);
  const [sources, setSources] = useState<TransferSource[]>([]);

  useEffect(() => {
    posMockService.getProducts().then(setAllProducts);
    posMockService.getTransferSources().then((s) => {
      setSources(s);
      if (s.length > 0) setSrc(s[0].id);
    });
  }, []);

  const res = q
    ? allProducts
        .filter(
          (p) =>
            p.name.includes(q) ||
            p.sku.toLowerCase().includes(q.toLowerCase())
        )
        .slice(0, 5)
    : [];

  const suggestions = allProducts.filter(
    (p) => p.stock <= p.reorder && !lines.find((l) => l.id === p.id)
  );

  const add = (p: PosProduct, qty = 1) =>
    setLines((l) => (l.find((x) => x.id === p.id) ? l : [...l, { id: p.id, qty }]));

  const handleSubmit = async () => {
    if (!lines.length) return;
    await posMockService.requestTransfer(src, lines, note);
    toast('تم إرسال طلب التحويل للاعتماد');
    setLines([]);
    setNote('');
  };

  const currentSource = sources.find((s) => s.id === src);

  return (
    <div
      className="grid"
      style={{
        gridTemplateColumns: 'minmax(0, 1fr) 340px',
        gap: 16,
        alignItems: 'start',
      }}
    >
      <div className="card" style={{ overflow: 'visible' }}>
        <div className="card-head">
          <h3>الأصناف المطلوبة</h3>
          <span className="meta">
            {fmt(lines.length)} أصناف · {fmt(lines.reduce((s, l) => s + l.qty, 0))} قطعة
          </span>
        </div>
        <div style={{ padding: 16, position: 'relative' }}>
          <Search value={q} onChange={setQ} placeholder="أضف صنفًا بالاسم أو الكود…" />
          {res.length > 0 && (
            <div className="scan-results" style={{ insetInline: 16 }}>
              {res.map((p) => (
                <button
                  key={p.id}
                  className="scan-row"
                  onClick={() => {
                    add(p);
                    setQ('');
                  }}
                >
                  <PImg p={p} size="xs" />
                  <span className="grow fs-13">{p.name}</span>
                  <span className="badge b-neutral">رصيدنا {fmt(p.stock)}</span>
                </button>
              ))}
            </div>
          )}
        </div>
        <table className="tbl">
          <thead>
            <tr>
              <th>الصنف</th>
              <th className="n">رصيدنا</th>
              <th className="n">حد الطلب</th>
              <th style={{ textAlign: 'center' }}>الكمية المطلوبة</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {lines.map((l) => {
              const p = posMockService.getProductById(l.id);
              if (!p) return null;
              return (
                <tr key={l.id}>
                  <td>
                    <div className="row gap-3">
                      <PImg p={p} size="xs" />
                      <div>
                        <div className="fw-5">{p.name}</div>
                        <div className="mono t-3" style={{ fontSize: 11 }}>
                          {p.sku}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td className={cx('n', p.stock <= p.reorder && 't-warn fw-6')}>
                    {fmt(p.stock)}
                  </td>
                  <td className="n t-2">{fmt(p.reorder)}</td>
                  <td style={{ textAlign: 'center' }}>
                    <div className="stepper-q">
                      <button
                        onClick={() =>
                          setLines((x) =>
                            x.map((y) => (y.id === l.id ? { ...y, qty: y.qty + 1 } : y))
                          )
                        }
                      >
                        <Icon n="plus" s={13} />
                      </button>
                      <span>{fmt(l.qty)}</span>
                      <button
                        onClick={() =>
                          setLines((x) =>
                            x.map((y) =>
                              y.id === l.id ? { ...y, qty: Math.max(1, y.qty - 1) } : y
                            )
                          )
                        }
                      >
                        <Icon n="minus" s={13} />
                      </button>
                    </div>
                  </td>
                  <td>
                    <button
                      className="icon-btn sm"
                      onClick={() => setLines((x) => x.filter((y) => y.id !== l.id))}
                    >
                      <Icon n="trash" s={15} />
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {lines.length === 0 && (
          <Empty
            icon="box"
            title="لم تتم إضافة أصناف"
            body="ابحث عن صنف أو اختر من المقترحات."
          />
        )}
        {suggestions.length > 0 && (
          <div style={{ padding: 16, borderTop: '1px solid var(--line)' }}>
            <div className="section-label row gap-2" style={{ marginBottom: 10 }}>
              <Icon n="sparkle" s={13} className="t-acc" />
              مقترح — أصناف تحت حد الطلب
            </div>
            <div className="row gap-2 wrap">
              {suggestions.map((p) => (
                <button
                  key={p.id}
                  className="chip"
                  onClick={() => add(p, Math.max(1, p.reorder * 2 - p.stock))}
                >
                  <Icon n="plus" s={12} />
                  {p.name.split('—')[0]}
                  <span className="count">{fmt(p.stock)}</span>
                </button>
              ))}
            </div>
          </div>
        )}
      </div>
      <div className="card card-pad col gap-4" style={{ position: 'sticky', top: 0 }}>
        <div className="col gap-2">
          <span className="fs-13 fw-5">الفرع المصدر</span>
          <div className="col gap-2">
            {sources.map((s) => (
              <button
                key={s.id}
                role="radio"
                aria-checked={src === s.id}
                onClick={() => setSrc(s.id)}
                className="row gap-3"
                style={{
                  padding: '10px 12px',
                  borderRadius: 12,
                  border:
                    '1.5px solid ' +
                    (src === s.id ? 'var(--acc)' : 'var(--line-strong)'),
                  background: src === s.id ? 'var(--acc-tint)' : 'var(--bg-card)',
                  textAlign: 'start',
                }}
              >
                <span className="radio-dot" />
                <Icon n={s.kind} s={17} className="t-3" />
                <span className="fs-13 fw-5 grow">{s.name}</span>
              </button>
            ))}
          </div>
        </div>
        <div
          className="route"
          style={{ padding: '10px 12px', borderRadius: 12, background: 'var(--bg-sunken)' }}
        >
          <Icon n="warehouse" s={15} />
          <span className="truncate">{currentSource?.name || 'المخزن الرئيسي — العبور'}</span>
          <span className="ln" />
          <Icon n="store" s={15} className="t-acc" />
          {branch?.name || 'فرع مدينة نصر'}
        </div>
        <Field
          label={
            <>
              ملاحظة <span className="t-3 fs-12">(اختياري)</span>
            </>
          }
        >
          <textarea
            className="input"
            rows={2}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="مثال: مطلوب قبل الويك إند"
          />
        </Field>
        <button
          className="btn btn-accent btn-lg"
          disabled={!lines.length}
          onClick={handleSubmit}
        >
          <Icon n="arrowLeft" s={16} />
          إرسال طلب التحويل
        </button>
        <div className="col gap-1">
          <Api m="POST" path="/pos/transfer-requests" />
          <span className="perm">
            <Icon n="shield" s={11} />
            transfers.create · Idempotency-Key
          </span>
        </div>
      </div>
    </div>
  );
}

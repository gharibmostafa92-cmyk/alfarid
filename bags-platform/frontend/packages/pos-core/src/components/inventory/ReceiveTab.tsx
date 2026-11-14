import React, { useState, useRef } from 'react';
import { Icon } from '../ui/Icons';
import { fmt, PImg, Api, Empty, Status, toAsciiNum, cx } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import { isCancel, normalizeError } from '../../lib/pos-error';
import type { StockTransfer } from '../../types/pos.types';

interface ReceiveTabProps {
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
  list: StockTransfer[];
  onReceived: (id: number) => void;
}

export function ReceiveTab({ toast, list, onReceived }: ReceiveTabProps) {
  const [cur, setCur] = useState<number | null>(null);
  const [edits, setEdits] = useState<{ id: number; quantities: Record<number, string> } | null>(null);
  const [busy, setBusy] = useState(false);
  const submittingRef = useRef(false);

  const t = list.find((x) => x.id === cur) ?? list[0];

  if (!t) {
    return (
      <div className="card">
        <Empty
          icon="truck"
          title="لا توجد شحنات واردة"
          body="كل التحويلات المشحونة للفرع تم استلامها."
        />
      </div>
    );
  }

  const quantitiesEdited = edits?.id === t.id ? edits.quantities : {};
  const rec = Object.fromEntries(t.items.map((item) => {
    const lineId = item.transferItemId ?? item.id;
    return [lineId, quantitiesEdited[lineId] ?? String(item.qty)];
  }));
  const shipped = t.items.reduce((s, i) => s + i.qty, 0);
  const got = t.items.reduce((s, i) => s + Number(rec[i.transferItemId ?? i.id] || 0), 0);
  const diffs = t.items.filter((i) => Number(rec[i.transferItemId ?? i.id] || 0) !== i.qty).length;

  const handleReceive = async () => {
    if (submittingRef.current || busy) return;

    for (const item of t.items) {
      const raw = rec[item.transferItemId ?? item.id];
      if (raw === undefined || raw.trim() === '') {
        toast('يرجى تحديد الكمية المستلمة لكل الأصناف', 'bad');
        return;
      }
      const num = Number(raw);
      if (!Number.isFinite(num) || num < 0) {
        toast('الكميات المستلمة يجب أن تكون أرقاماً موجبة أو صفر', 'bad');
        return;
      }
      if (num > item.qty) {
        toast(`الكمية المستلمة للصنف لا يمكن أن تتجاوز الكمية المشحونة (${item.qty})`, 'bad');
        return;
      }
    }

    submittingRef.current = true;
    setBusy(true);
    try {
      const quantities: Record<number, number> = {};
      for (const [id, val] of Object.entries(rec)) {
        quantities[Number(id)] = Number(val);
      }
      await pos.receiveTransfer(t.id, quantities);
      onReceived(t.id);
      toast(`تم استلام ${t.number} ورفع رصيد الفرع`, 'ok');
    } catch (err) {
      if (!isCancel(err)) toast(normalizeError(err).message, 'bad');
    } finally {
      submittingRef.current = false;
      setBusy(false);
    }
  };

  return (
    <div
      className="grid"
      style={{
        gridTemplateColumns: '320px minmax(0, 1fr)',
        gap: 16,
        alignItems: 'start',
      }}
    >
      <div className="tr-list">
        {list.map((x) => (
          <button
            key={x.id}
            className="tr-item"
            aria-pressed={x.id === t.id}
            onClick={() => {
              if (x.id !== t.id) setEdits(null);
              setCur(x.id);
            }}
          >
            <div className="row between">
              <b className="mono" style={{ fontSize: 12.5 }}>
                {x.number}
              </b>
              <Status kind="transfer" s="shipped" />
            </div>
            <div className="route">
              <Icon
                n={x.from.includes('المخزن') ? 'warehouse' : 'store'}
                s={15}
                className="t-3"
              />
              <span className="truncate" style={{ maxWidth: 110 }}>
                {x.from}
              </span>
              <span className="ln" />
              <Icon n="store" s={15} className="t-acc" />
              <span>فرعنا</span>
            </div>
            <div className="row between fs-12 t-3">
              <span>{x.shipped}</span>
              <span className="num">
                {fmt(x.items.reduce((s, i) => s + i.qty, 0))} قطعة · {fmt(x.items.length)} أصناف
              </span>
            </div>
          </button>
        ))}
      </div>
      <div className="card" style={{ overflow: 'hidden' }}>
        <div className="card-head">
          <div className="col">
            <h3>{t.number}</h3>
            <span className="fs-12 t-3">
              من {t.from} · السائق {t.driver}
            </span>
          </div>
          <button
            className="btn btn-secondary btn-sm"
            onClick={() => setEdits(null)}
          >
            <Icon n="check" s={14} />
            الكل مطابق
          </button>
        </div>
        <table className="tbl">
          <thead>
            <tr>
              <th>الصنف</th>
              <th className="n">المشحون</th>
              <th style={{ textAlign: 'center' }}>المستلم فعليًا</th>
              <th className="n">الفرق</th>
              <th className="n">رصيد الفرع بعد</th>
            </tr>
          </thead>
          <tbody>
            {t.items.map((i) => {
              const p = pos.getProductById(i.id);
              const lineId = i.transferItemId ?? i.id;
              const r = Number(rec[lineId] || 0);
              const d = r - i.qty;
              const stock = p?.stock || 0;
              return (
                <tr key={lineId} className={d !== 0 ? 'selected' : ''}>
                  <td>
                    <div className="row gap-3">
                      <PImg p={p} size="xs" />
                      <div>
                        <div className="fw-5">{p?.name}</div>
                        <div className="mono t-3" style={{ fontSize: 11 }}>
                          {p?.sku}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td className="n fw-6">{fmt(i.qty)}</td>
                  <td style={{ textAlign: 'center' }}>
                    <input
                      className={cx('qty-input', d !== 0 && 'bad')}
                      value={rec[lineId] ?? ''}
                      onChange={(e) =>
                        setEdits({ id: t.id, quantities: { ...quantitiesEdited, [lineId]: toAsciiNum(e.target.value, 4) } })
                      }
                    />
                  </td>
                  <td className="n">
                    {d === 0 ? (
                      <span className="t-ok">
                        <Icon n="check" s={15} w={2.4} />
                      </span>
                    ) : (
                      <span className="badge b-bad num">
                        {d > 0 ? '+' : '−'}
                        {fmt(Math.abs(d))}
                      </span>
                    )}
                  </td>
                  <td className="n">
                    <span className="t-3 num">{fmt(stock)}</span>{' '}
                    <Icon n="arrowLeft" s={12} className="t-3" />{' '}
                    <b className="num">{fmt(stock + r)}</b>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        <div
          className="row gap-3"
          style={{
            padding: '14px 16px',
            borderTop: '1px solid var(--line)',
            background: 'var(--bg-sunken)',
          }}
        >
          <div className="grow col gap-1">
            <div className="fs-13">
              <b className="num">{fmt(got)}</b> من <b className="num">{fmt(shipped)}</b> قطعة{' '}
              {diffs > 0 && (
                <span className="badge b-bad" style={{ marginInlineStart: 6 }}>
                  {fmt(diffs)} صنف به فرق
                </span>
              )}
            </div>
            {import.meta.env.DEV && (
              <div className="row gap-2">
                <Api m="POST" path={`/pos/transfers/${t.id}/receive`} />
                <span className="perm">
                  <Icon n="shield" s={11} />
                  transfers.receive
                </span>
              </div>
            )}
          </div>
          <button className="btn btn-accent btn-lg" onClick={handleReceive} disabled={busy}>
            <Icon n="check" s={16} w={2.4} />
            {busy ? 'جاري الاستلام...' : 'تأكيد الاستلام ورفع الرصيد'}
          </button>
        </div>
      </div>
    </div>
  );
}

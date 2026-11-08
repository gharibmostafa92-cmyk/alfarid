import React, { useState, useEffect } from 'react';
import { Icon } from '../ui/Icons';
import { fmt, PImg, Api, Empty, Status, toAsciiNum, cx } from '../ui/SharedUI';
import { pos as posMockService } from '../../services/pos-data-source';
import type { StockTransfer } from '../../types/pos.types';

interface ReceiveTabProps {
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
}

export function ReceiveTab({ toast }: ReceiveTabProps) {
  const [list, setList] = useState<StockTransfer[]>([]);
  const [cur, setCur] = useState<number | null>(null);
  const [rec, setRec] = useState<Record<number, string>>({});

  useEffect(() => {
    posMockService.getTransfersIn().then((transfers) => {
      setList(transfers);
      if (transfers.length > 0) {
        setCur(transfers[0].id);
      }
    });
  }, []);

  const t = list.find((x) => x.id === cur);

  useEffect(() => {
    if (t) {
      setRec(Object.fromEntries(t.items.map((i) => [i.id, String(i.qty)])));
    }
  }, [cur, t]);

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

  const shipped = t.items.reduce((s, i) => s + i.qty, 0);
  const got = t.items.reduce((s, i) => s + Number(rec[i.id] || 0), 0);
  const diffs = t.items.filter((i) => Number(rec[i.id] || 0) !== i.qty).length;

  const handleReceive = async () => {
    const quantities: Record<number, number> = {};
    for (const [id, val] of Object.entries(rec)) {
      quantities[Number(id)] = Number(val);
    }
    await posMockService.receiveTransfer(t.id, quantities);
    toast(`تم استلام ${t.number} ورفع رصيد الفرع`);
    const rest = list.filter((x) => x.id !== t.id);
    setList(rest);
    setCur(rest[0]?.id || null);
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
            aria-pressed={x.id === cur}
            onClick={() => setCur(x.id)}
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
            onClick={() =>
              setRec(Object.fromEntries(t.items.map((i) => [i.id, String(i.qty)])))
            }
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
              const p = posMockService.getProductById(i.id);
              const r = Number(rec[i.id] || 0);
              const d = r - i.qty;
              const stock = p?.stock || 0;
              return (
                <tr key={i.id} className={d !== 0 ? 'selected' : ''}>
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
                      value={rec[i.id] ?? ''}
                      onChange={(e) =>
                        setRec({ ...rec, [i.id]: toAsciiNum(e.target.value) })
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
            <div className="row gap-2">
              <Api m="POST" path={`/pos/transfers/${t.id}/receive`} />
              <span className="perm">
                <Icon n="shield" s={11} />
                transfers.receive
              </span>
            </div>
          </div>
          <button className="btn btn-accent btn-lg" onClick={handleReceive}>
            <Icon n="check" s={16} w={2.4} />
            تأكيد الاستلام ورفع الرصيد
          </button>
        </div>
      </div>
    </div>
  );
}

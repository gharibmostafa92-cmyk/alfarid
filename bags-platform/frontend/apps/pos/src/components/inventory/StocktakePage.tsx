import React, { useState, useEffect } from 'react';
import { Icon } from '../ui/Icons';
import { fmt, PImg, Api, toAsciiNum, cx } from '../ui/SharedUI';
import { posMockService } from '../../services/pos-mock.service';
import type { PosProduct, StocktakeSession } from '../../types/pos.types';

interface StocktakePageProps {
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
}

export function StocktakePage({ toast }: StocktakePageProps) {
  const [scope, setScope] = useState<PosProduct[]>([]);
  const [session, setSession] = useState<StocktakeSession | null>(null);
  const [code, setCode] = useState('');
  const [qty, setQty] = useState('1');
  const [listening, setListening] = useState(false);
  const [heard, setHeard] = useState('');

  const loadSession = () => {
    posMockService.getStocktakeSession().then((res) => {
      setScope(res.scope);
      setSession(res.session);
    });
  };

  useEffect(() => {
    loadSession();
    const unsub = posMockService.subscribe(loadSession);
    return unsub;
  }, []);

  if (!session) return null;

  const counts = session.counts;
  const counted = Object.keys(counts).length;
  const last = session.lastScan || {
    id: 111,
    qty: 85,
    via: 'voice' as const,
    words: 'خمسة وتمانين',
  };
  const log = session.log;

  const submitCode = async () => {
    const p =
      posMockService.getProductById(101) ||
      scope.find(
        (x) =>
          x.barcode === code.trim() ||
          x.sku.toLowerCase() === code.trim().toLowerCase()
      ) ||
      scope[4];

    await posMockService.recordStocktakeScan(p.id, Number(qty || 1), 'barcode');
    setCode('');
    setQty('1');
    loadSession();
  };

  const mic = () => {
    if (listening) return;
    setListening(true);
    setHeard('');
    const words = 'اتناشر';
    let i = 0;
    const iv = setInterval(() => {
      i++;
      setHeard(words.slice(0, i));
      if (i >= words.length) {
        clearInterval(iv);
        setTimeout(async () => {
          setListening(false);
          await posMockService.recordStocktakeScan(105, 12, 'voice', words);
          toast('سُجّل ١٢ — باك باك لابتوب');
          loadSession();
        }, 500);
      }
    }, 140);
  };

  const lp = posMockService.getProductById(last.id) || scope[0];

  return (
    <div className="page" data-screen-label="10 Stocktake">
      <div className="page-in page-enter">
        <div className="page-title">
          <div>
            <div className="row gap-2" style={{ marginBottom: 4 }}>
              <span className="badge b-acc">
                <span className="dot" />
                جرد جارٍ
              </span>
              <span className="mono t-3">{session.code}</span>
            </div>
            <h1>جرد الفرع</h1>
            <p>امسح الباركود أو قل الكمية بصوتك — بالعامية المصرية.</p>
          </div>
          <div className="col gap-2" style={{ minWidth: 280 }}>
            <div className="row between fs-13">
              <span className="t-2">تقدم الجرد</span>
              <b className="num">
                {fmt(counted)} / {fmt(scope.length)} صنف
              </b>
            </div>
            <div className="progress">
              <span style={{ width: (counted / (scope.length || 1)) * 100 + '%' }} />
            </div>
          </div>
        </div>
        <div className="st-grid">
          <div className="col gap-4">
            <div className="card card-pad row gap-6" style={{ alignItems: 'center' }}>
              <button
                className={cx('mic', listening && 'on')}
                onClick={mic}
                aria-label="الإدخال الصوتي"
              >
                <Icon n="mic" s={44} w={1.6} />
              </button>
              <div className="grow col gap-3">
                <div className="fs-13 t-2">
                  {listening
                    ? 'بسمعك… قول الكمية'
                    : 'اضغط الميكروفون وقل الكمية للصنف الحالي'}
                </div>
                <div className="row gap-3" style={{ minHeight: 44 }}>
                  {listening && (
                    <div className="wave">
                      {Array.from({ length: 14 }).map((_, i) => (
                        <i key={i} style={{ animationDelay: i * 0.06 + 's' }} />
                      ))}
                    </div>
                  )}
                  <span className="display" style={{ fontSize: 30, fontWeight: 500 }}>
                    {heard ? (
                      `«${heard}»`
                    ) : (
                      <span className="t-3 fs-20">مثال: «اتناشر» ، «خمسة وعشرين»</span>
                    )}
                  </span>
                </div>
                <div className="divider" style={{ height: 1, background: 'var(--line)' }} />
                <div className="row gap-2">
                  <div className="scanbar grow" style={{ height: 48 }}>
                    <span className="scan-ic" style={{ width: 30, height: 30 }}>
                      <Icon n="barcode" s={16} w={2} />
                    </span>
                    <input
                      autoFocus
                      value={code}
                      onChange={(e) => setCode(e.target.value)}
                      onKeyDown={(e) => e.key === 'Enter' && submitCode()}
                      placeholder="امسح باركود الصنف"
                    />
                  </div>
                  <div className="input-group" style={{ width: 110 }}>
                    <input
                      className="input num"
                      style={{
                        height: 48,
                        textAlign: 'center',
                        fontSize: 18,
                        fontWeight: 600,
                      }}
                      value={qty}
                      onChange={(e) => setQty(toAsciiNum(e.target.value))}
                      aria-label="الكمية"
                    />
                  </div>
                  <button className="btn btn-primary" style={{ height: 48 }} onClick={submitCode}>
                    تسجيل
                  </button>
                </div>
                <div className="row gap-2">
                  <Api m="POST" path={`/pos/stocktakes/${session.id}/scan`} />
                  <span className="fs-12 t-3 ltr mono">{'{ barcode, qty | qty_words }'}</span>
                </div>
              </div>
            </div>
            {lp && (
              <div
                className="last-scan"
                key={last.id + '-' + last.qty}
                style={{ animation: 'popIn .3s both' }}
              >
                <PImg p={lp} size="sm" />
                <div className="grow">
                  <div className="fs-12 t-3">
                    آخر تسجيل{' '}
                    {last.via === 'voice' ? (
                      <span className="badge b-acc" style={{ height: 20 }}>
                        <Icon n="mic" s={11} />
                        «{last.words}»
                      </span>
                    ) : (
                      <span className="badge b-neutral" style={{ height: 20 }}>
                        <Icon n="barcode" s={11} />
                        باركود
                      </span>
                    )}
                  </div>
                  <div className="fs-16 fw-5">{lp.name}</div>
                  <div className="mono t-3" style={{ fontSize: 11 }}>
                    {lp.sku}
                  </div>
                </div>
                <div className="col" style={{ alignItems: 'flex-end' }}>
                  <span className="big-qty">{fmt(last.qty)}</span>
                  <span className="fs-12 t-3">العدد الحالي</span>
                </div>
              </div>
            )}
            <div className="card" style={{ overflow: 'hidden' }}>
              <div className="card-head">
                <h3>نطاق الجرد</h3>
                <span className="meta">النظام لا يعرض الرصيد الدفتري أثناء العد</span>
              </div>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>الصنف</th>
                    <th>الكود</th>
                    <th className="n">المعدود</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {scope.map((p) => (
                    <tr key={p.id} className={last.id === p.id ? 'selected' : ''}>
                      <td>
                        <div className="row gap-3">
                          <PImg p={p} size="xs" />
                          <span className="fw-5">{p.name}</span>
                        </div>
                      </td>
                      <td>
                        <span className="mono">{p.sku}</span>
                      </td>
                      <td className="n fs-16 fw-6">
                        {counts[p.id] != null ? fmt(counts[p.id]) : <span className="t-3">—</span>}
                      </td>
                      <td>
                        {counts[p.id] != null ? (
                          <span className="badge b-ok">
                            <span className="dot" />
                            تم العد
                          </span>
                        ) : (
                          <span className="badge b-neutral">لم يُعد</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
          <div className="card" style={{ position: 'sticky', top: 0 }}>
            <div className="card-head">
              <h3>سجل المسح</h3>
              <span className="meta">آخر {fmt(log.length)}</span>
            </div>
            <div className="col" style={{ padding: '6px 10px 10px' }}>
              {log.map((l, i) => {
                const p = posMockService.getProductById(l.id);
                return (
                  <div
                    key={i}
                    className="row gap-3"
                    style={{
                      padding: '10px 6px',
                      borderBottom: i < log.length - 1 ? '1px solid var(--line)' : 0,
                    }}
                  >
                    <span
                      className="kpi-icon"
                      style={{
                        width: 30,
                        height: 30,
                        background:
                          l.via === 'voice' ? 'var(--acc-soft)' : 'var(--bg-sunken)',
                        color: l.via === 'voice' ? 'var(--acc)' : 'var(--text-2)',
                      }}
                    >
                      <Icon n={l.via === 'voice' ? 'mic' : 'barcode'} s={14} />
                    </span>
                    <div className="grow" style={{ minWidth: 0 }}>
                      <div className="fs-13 fw-5 truncate">{p?.name}</div>
                      <div className="fs-12 t-3">
                        {l.words ? `«${l.words}»` : 'مسح باركود'} ·{' '}
                        <span className="num">{l.t}</span>
                      </div>
                    </div>
                    <b className="num t-ok">+{fmt(l.add)}</b>
                  </div>
                );
              })}
            </div>
            <div style={{ padding: 14, borderTop: '1px solid var(--line)' }}>
              <button
                className="btn btn-secondary"
                style={{ width: '100%' }}
                onClick={() => toast('تم إرسال مسودة الجرد للمراجعة')}
              >
                إنهاء الجرد وإرساله للمراجعة
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

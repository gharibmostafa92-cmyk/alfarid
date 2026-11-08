import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Api, Empty, Seg, Check, Search, useEsc } from '../ui/SharedUI';
import { posMockService } from '../../services/pos-mock.service';
import type { PastSale, PosProduct } from '../../types/pos.types';

interface ReturnModalProps {
  open: boolean;
  onClose: () => void;
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
}

export function ReturnModal({ open, onClose, toast }: ReturnModalProps) {
  const [num, setNum] = useState<string>('');
  const [sale, setSale] = useState<PastSale | null>(null);
  const [err, setErr] = useState<string>('');
  const [mode, setMode] = useState<'return' | 'exchange'>('return');
  const [sel, setSel] = useState<Record<number, { qty: number; to: 'on_hand' | 'damaged' }>>({});
  const [repl, setRepl] = useState<{ id: number; qty: number }[]>([]);
  const [rq, setRq] = useState<string>('');
  const [busy, setBusy] = useState<boolean>(false);
  const [allProducts, setAllProducts] = useState<PosProduct[]>([]);

  useEffect(() => {
    if (open) {
      setNum('');
      setSale(null);
      setErr('');
      setMode('return');
      setSel({});
      setRepl([]);
      setRq('');
      setBusy(false);
      posMockService.getProducts().then(setAllProducts);
    }
  }, [open]);

  useEsc(onClose, open);
  if (!open) return null;

  const find = async () => {
    if (!num.trim()) return;
    const s = await posMockService.getPastSale(num);
    if (s) {
      setSale(s);
      setErr('');
    } else {
      setSale(null);
      setErr('لم يتم العثور على فاتورة بهذا الرقم في الفرع');
    }
  };

  const loadExample = async () => {
    const s = await posMockService.getPastSale('POS-NSR-10233');
    if (s) {
      setNum('POS-NSR-10233');
      setSale(s);
      setErr('');
    }
  };

  const retTotal = sale
    ? sale.items.reduce((s, i) => s + (sel[i.id]?.qty || 0) * i.price, 0)
    : 0;

  const replTotal = repl.reduce((s, r) => {
    const p = posMockService.getProductById(r.id);
    return s + (p?.price || 0) * r.qty;
  }, 0);

  const net = replTotal - retTotal;
  const anySel = Object.values(sel).some((v) => v.qty > 0);
  const canSubmit = anySel && (mode === 'return' || repl.length > 0) && !busy;

  const results = rq
    ? allProducts
        .filter(
          (p) =>
            p.name.includes(rq) ||
            p.sku.toLowerCase().includes(rq.toLowerCase())
        )
        .slice(0, 4)
    : [];

  const handleSubmit = async () => {
    if (!canSubmit || !sale) return;
    setBusy(true);
    try {
      const returnPayload = Object.entries(sel)
        .filter(([, v]) => v.qty > 0)
        .map(([id, v]) => ({
          id: Number(id),
          qty: v.qty,
          return_to: v.to,
        }));

      if (mode === 'return') {
        await posMockService.processReturn(sale.id, returnPayload);
        toast('تم تسجيل المرتجع وتحديث المخزون');
      } else {
        await posMockService.processExchange(sale.id, returnPayload, repl, net);
        toast('تم الاستبدال وإصدار فاتورة جديدة');
      }
      onClose();
    } finally {
      setBusy(false);
    }
  };

  return ReactDOM.createPortal(
    <div
      dir="rtl"
      className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}
    >
      <div className="overlay" onClick={onClose} />
      <div
        className="modal"
        style={{ width: 'min(920px, calc(100vw - 32px))' }}
        data-screen-label="07 Return & Exchange"
        role="dialog"
        aria-modal="true"
      >
        <div className="pop">
          <div
            className="row gap-3"
            style={{ padding: '18px 22px', borderBottom: '1px solid var(--line)' }}
          >
            <div className="grow col">
              <h3 className="fs-16">مرتجع / استبدال</h3>
              <span className="perm">
                <Icon n="shield" s={12} />
                pos.sales.refund
              </span>
            </div>
            <Seg
              value={mode}
              onChange={setMode}
              options={[
                { value: 'return', label: 'إرجاع فقط' },
                { value: 'exchange', label: 'استبدال بصنف آخر' },
              ]}
            />
            <button className="icon-btn sm" onClick={onClose} aria-label="إغلاق">
              <Icon n="x" s={16} />
            </button>
          </div>

          <div
            style={{
              padding: '18px 22px',
              display: 'flex',
              flexDirection: 'column',
              gap: 16,
            }}
          >
            <div className="ret-head">
              <div className="scanbar grow" style={{ height: 48 }}>
                <span className="scan-ic" style={{ width: 30, height: 30 }}>
                  <Icon n="barcode" s={16} w={2} />
                </span>
                <input
                  autoFocus
                  value={num}
                  onChange={(e) => setNum(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && find()}
                  placeholder="امسح باركود الإيصال أو اكتب رقم الفاتورة (order_number)"
                  className="ltr"
                  style={{ textAlign: 'right' }}
                />
              </div>
              <button className="btn btn-primary" style={{ height: 48 }} onClick={find}>
                بحث
              </button>
              {!sale && (
                <button className="btn btn-ghost" style={{ height: 48 }} onClick={loadExample}>
                  مثال
                </button>
              )}
            </div>
            {err && (
              <div className="banner b-bad">
                <Icon n="alert" s={16} />
                {err}
              </div>
            )}

            {!sale ? (
              <Empty
                icon="ret"
                title="ابدأ بمسح إيصال البيع الأصلي"
                body="سيتم عرض بنود الفاتورة لتختار الأصناف والكميات المرتجعة وحالتها."
              />
            ) : (
              <>
                <div className="order-meta">
                  <div>
                    <span>رقم الفاتورة</span>
                    <b className="mono">{sale.number}</b>
                  </div>
                  <div>
                    <span>التاريخ</span>
                    <b>{sale.date}</b>
                  </div>
                  <div>
                    <span>العميل</span>
                    <b>{sale.customer}</b>
                  </div>
                  <div>
                    <span>الدفع / الكاشير</span>
                    <b>
                      {sale.method} · {sale.cashier}
                    </b>
                  </div>
                </div>
                <div className="card" style={{ overflow: 'hidden' }}>
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th style={{ width: 40 }}></th>
                        <th>الصنف</th>
                        <th className="n">السعر</th>
                        <th className="n">المباع</th>
                        <th style={{ textAlign: 'center' }}>كمية الإرجاع</th>
                        <th>حالة الصنف الراجع</th>
                        <th className="n">المسترد</th>
                      </tr>
                    </thead>
                    <tbody>
                      {sale.items.map((i) => {
                        const p = posMockService.getProductById(i.id);
                        const s = sel[i.id] || { qty: 0, to: 'on_hand' };
                        const on = s.qty > 0;
                        const upd = (v: Partial<{ qty: number; to: 'on_hand' | 'damaged' }>) =>
                          setSel((x) => ({ ...x, [i.id]: { ...s, ...v } }));

                        return (
                          <tr key={i.id} className={on ? 'selected' : ''}>
                            <td>
                              <Check
                                on={on}
                                onChange={(v) => upd({ qty: v ? 1 : 0 })}
                              />
                            </td>
                            <td>
                              <div className="row gap-2">
                                <PImg p={p} size="xs" />
                                <div>
                                  <div className="fs-13 fw-5">{p?.name}</div>
                                  <div className="mono t-3" style={{ fontSize: 11 }}>
                                    {p?.sku}
                                  </div>
                                </div>
                              </div>
                            </td>
                            <td className="n">{egp(i.price)}</td>
                            <td className="n">{fmt(i.qty)}</td>
                            <td style={{ textAlign: 'center' }}>
                              <div className="stepper-q" style={{ opacity: on ? 1 : 0.5 }}>
                                <button
                                  onClick={() => upd({ qty: Math.min(i.qty, s.qty + 1) })}
                                >
                                  <Icon n="plus" s={13} />
                                </button>
                                <span>{fmt(s.qty)}</span>
                                <button
                                  onClick={() => upd({ qty: Math.max(0, s.qty - 1) })}
                                >
                                  <Icon n="minus" s={13} />
                                </button>
                              </div>
                            </td>
                            <td>
                              <div
                                className="seg"
                                style={{
                                  opacity: on ? 1 : 0.45,
                                  pointerEvents: on ? 'auto' : 'none',
                                }}
                              >
                                <button
                                  aria-pressed={s.to === 'on_hand'}
                                  onClick={() => upd({ to: 'on_hand' })}
                                >
                                  <span className="row gap-1">
                                    <i
                                      style={{
                                        width: 7,
                                        height: 7,
                                        borderRadius: 9,
                                        background: 'var(--ok)',
                                      }}
                                    />
                                    سليم
                                  </span>
                                </button>
                                <button
                                  aria-pressed={s.to === 'damaged'}
                                  onClick={() => upd({ to: 'damaged' })}
                                >
                                  <span className="row gap-1">
                                    <i
                                      style={{
                                        width: 7,
                                        height: 7,
                                        borderRadius: 9,
                                        background: 'var(--bad)',
                                      }}
                                    />
                                    تالف
                                  </span>
                                </button>
                              </div>
                            </td>
                            <td className="n fw-6">{on ? egp(s.qty * i.price) : '—'}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                {mode === 'exchange' && (
                  <div
                    className="card card-pad col gap-3"
                    style={{ background: 'var(--bg-sunken)' }}
                  >
                    <div className="row between">
                      <span className="section-label">الصنف البديل</span>
                      <span className="fs-12 t-3">
                        ينفَّذ الإرجاع والفاتورة الجديدة في عملية واحدة
                      </span>
                    </div>
                    <div style={{ position: 'relative' }}>
                      <Search
                        value={rq}
                        onChange={setRq}
                        placeholder="ابحث عن الصنف البديل بالاسم أو الكود…"
                      />
                      {results.length > 0 && (
                        <div className="scan-results" style={{ maxHeight: 240 }}>
                          {results.map((p) => (
                            <button
                              key={p.id}
                              className="scan-row"
                              onClick={() => {
                                setRepl((r) =>
                                  r.find((x) => x.id === p.id)
                                    ? r.map((x) =>
                                        x.id === p.id ? { ...x, qty: x.qty + 1 } : x
                                      )
                                    : [...r, { id: p.id, qty: 1 }]
                                );
                                setRq('');
                              }}
                            >
                              <PImg p={p} size="xs" />
                              <span className="grow fs-13">{p.name}</span>
                              <span className="badge b-neutral">رصيد {fmt(p.stock)}</span>
                              <span className="price">{egp(p.price)}</span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                    {repl.map((r) => {
                      const p = posMockService.getProductById(r.id);
                      if (!p) return null;
                      return (
                        <div
                          key={r.id}
                          className="row gap-3"
                          style={{
                            background: 'var(--bg-card)',
                            padding: 10,
                            borderRadius: 12,
                            border: '1px solid var(--line)',
                          }}
                        >
                          <PImg p={p} size="xs" />
                          <span className="grow fs-13 fw-5">{p.name}</span>
                          <div className="stepper-q">
                            <button
                              onClick={() =>
                                setRepl((x) =>
                                  x.map((y) => (y.id === r.id ? { ...y, qty: y.qty + 1 } : y))
                                )
                              }
                            >
                              <Icon n="plus" s={13} />
                            </button>
                            <span>{fmt(r.qty)}</span>
                            <button
                              onClick={() =>
                                setRepl((x) =>
                                  r.qty > 1
                                    ? x.map((y) =>
                                        y.id === r.id ? { ...y, qty: y.qty - 1 } : y
                                      )
                                    : x.filter((y) => y.id !== r.id)
                                )
                              }
                            >
                              <Icon n="minus" s={13} />
                            </button>
                          </div>
                          <span className="price" style={{ width: 80, textAlign: 'end' }}>
                            {egp(p.price * r.qty)}
                          </span>
                        </div>
                      );
                    })}
                    {repl.length === 0 && (
                      <div className="fs-13 t-3">لم يتم اختيار صنف بديل بعد.</div>
                    )}
                  </div>
                )}
              </>
            )}
          </div>

          {sale && (
            <div
              className="row gap-3"
              style={{
                padding: '14px 22px',
                borderTop: '1px solid var(--line)',
                background: 'var(--bg-card)',
              }}
            >
              {mode === 'return' ? (
                <div
                  className="net grow"
                  style={{
                    background: 'var(--bad-bg)',
                    color: 'var(--bad)',
                    padding: '10px 14px',
                  }}
                >
                  <Icon n="cash" s={18} />
                  <span className="fs-13 fw-6">رد نقدية للعميل</span>
                  <span className="ms-auto display fs-20 fw-6 num">{egp(retTotal)} ج</span>
                </div>
              ) : (
                <div
                  className="net grow"
                  style={{
                    background:
                      !anySel || !repl.length
                        ? 'var(--bg-sunken)'
                        : net > 0
                        ? 'var(--ok-bg)'
                        : net < 0
                        ? 'var(--bad-bg)'
                        : 'var(--info-bg)',
                    color:
                      !anySel || !repl.length
                        ? 'var(--text-2)'
                        : net > 0
                        ? 'var(--ok)'
                        : net < 0
                        ? 'var(--bad)'
                        : 'var(--info)',
                    padding: '10px 14px',
                  }}
                >
                  <span className="fs-12 t-2" style={{ color: 'inherit', opacity: 0.8 }}>
                    مرتجع {egp(retTotal)} ← بديل {egp(replTotal)}
                  </span>
                  <span className="fs-13 fw-6">
                    {net > 0
                      ? 'تحصيل فرق من العميل'
                      : net < 0
                      ? 'رد فرق للعميل'
                      : 'بدون فروق'}
                  </span>
                  <span className="ms-auto display fs-20 fw-6 num">{egp(Math.abs(net))} ج</span>
                </div>
              )}
              <Api m="POST" path={`/pos/sales/${sale.id}/${mode}`} />
              <button
                className="btn btn-primary btn-lg"
                disabled={!canSubmit}
                onClick={handleSubmit}
              >
                <Icon n="check" s={16} w={2.4} />
                {busy
                  ? 'جارِ التنفيذ…'
                  : mode === 'return'
                  ? 'تأكيد المرتجع'
                  : 'تأكيد الاستبدال'}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>,
    document.body
  );
}

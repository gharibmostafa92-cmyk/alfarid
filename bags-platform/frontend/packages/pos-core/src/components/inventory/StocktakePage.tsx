import React, { useState, useEffect, useLayoutEffect, useRef, useCallback } from 'react';
import { Icon } from '../ui/Icons';
import { fmt, PImg, Api, toAsciiNum, cx } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import { isCancel, normalizeError } from '../../lib/pos-error';
import { beepError } from '../../lib/sound';
import type { PosProduct, StocktakeSession } from '../../types/pos.types';

interface StocktakePageProps {
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
}

type SpeechListener = {
  lang: string;
  interimResults: boolean;
  maxAlternatives: number;
  start: () => void;
  abort: () => void;
  onresult: ((event: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void) | null;
  onerror: (() => void) | null;
  onend: (() => void) | null;
};

type SpeechWindow = Window & {
  SpeechRecognition?: new () => SpeechListener;
  webkitSpeechRecognition?: new () => SpeechListener;
};

type Scan = {
  id: string;
  sessionId: number;
  product: Pick<PosProduct, 'id' | 'name'>;
  source: 'barcode' | 'voice';
  amount?: number;
  transcript?: string;
};

export function StocktakePage({ toast }: StocktakePageProps) {
  const [scope, setScope] = useState<PosProduct[]>([]);
  const [session, setSession] = useState<StocktakeSession | null>(null);
  const [code, setCode] = useState('');
  const [qty, setQty] = useState('1');
  const [listening, setListening] = useState(false);
  const [heard, setHeard] = useState('');
  const [pendingScan, setPendingScan] = useState<Scan | null>(null);
  const [busy, setBusy] = useState(false);

  const recognitionRef = useRef<SpeechListener | null>(null);
  const submitting = useRef(false);
  const toastRef = useRef(toast);
  const refreshSession = useRef<(() => void) | null>(null);

  const last = session?.lastScan;
  // Server-provided names also cover variants absent from the catalog.
  const lp = last
    ? pos.getProductById(last.id) ||
      scope.find((item) => item.id === last.id) ||
      (last.name ? ({ id: last.id, name: last.name } as PosProduct) : undefined)
    : undefined;
  const lpName = last?.name ?? lp?.name ?? '';
  const sessionId = session?.id ?? 0;
  const voiceContext = useRef({ sessionId, product: lp, pendingScan });

  useLayoutEffect(() => {
    toastRef.current = toast;
    voiceContext.current = { sessionId, product: lp, pendingScan };
  }, [sessionId, lp, pendingScan, toast]);

  const stopRecognition = useCallback((abort: boolean) => {
    const listener = recognitionRef.current;
    if (!listener) return;
    recognitionRef.current = null;
    listener.onresult = null;
    listener.onerror = null;
    listener.onend = null;
    if (abort) listener.abort();
    setListening(false);
  }, []);

  useLayoutEffect(() => {
    setPendingScan(null);
    setHeard('');
    setCode('');
    setQty('1');
    return () => stopRecognition(true);
  }, [sessionId, stopRecognition]);

  useEffect(() => {
    let active = true;
    let request = 0;
    const loadSession = async () => {
      const current = ++request;
      try {
        const res = await pos.getStocktakeSession();
        if (!active || current !== request) return;
        setScope(res.scope);
        setSession(res.session);
      } catch (error) {
        if (active && current === request && !isCancel(error)) {
          toastRef.current(normalizeError(error).message, 'bad');
        }
      }
    };
    refreshSession.current = loadSession;
    void loadSession();
    const unsubscribe = pos.subscribe(loadSession);
    return () => {
      active = false;
      refreshSession.current = null;
      unsubscribe();
    };
  }, []);

  if (!session || session.id === 0) {
    return (
      <div className="page" data-screen-label="10 Stocktake">
        <div className="page-in page-enter">
          <div className="page-title">
            <div>
              <h1>جرد الفرع</h1>
              <p>متابعة وتدقيق المخزون الفعلي بالمقارنة مع النظام.</p>
            </div>
          </div>
          <div className="card card-pad center" style={{ padding: 48, textAlign: 'center' }}>
            {/* F-14: the "inventory" icon does not exist in the registry — use "clipboard". */}
            <Icon n="clipboard" s={48} style={{ color: 'var(--text-3)', marginBottom: 12 }} />
            <h3 className="fs-18">لا توجد جلسة جرد مفتوحة</h3>
            <p className="t-2" style={{ maxWidth: 420, margin: '8px auto 0' }}>
              لا توجد جلسة جرد نشطة حاليًا لهذا الفرع. يمكنك فتح جلسة جرد جديدة من لوحة الإدارة.
            </p>
          </div>
        </div>
      </div>
    );
  }

  const counts = session.counts;
  const counted = Object.keys(counts).length;
  const log = session.log;

  // F-14: "0" is a truthy string and "." is NaN — both must become 1, never 0/NaN.
  const parseCount = (raw: string): number => {
    const n = Number(raw);
    if (!Number.isFinite(n) || n <= 0) return 1;
    return Math.round(n * 10000) / 10000;
  };

  const submit = async (action: () => Promise<void>) => {
    if (submitting.current) return;
    submitting.current = true;
    setBusy(true);
    try {
      await action();
    } catch (error) {
      if (!isCancel(error)) toastRef.current(normalizeError(error).message, 'bad');
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  const recordScan = async (scan: Scan) => {
    if (scan.sessionId !== voiceContext.current.sessionId) return;
    const { product, amount, source, transcript, id } = scan;
    try {
      const result = await pos.recordStocktakeScan(product.id, amount, source, transcript, id);
      if (scan.sessionId !== voiceContext.current.sessionId) return;
      setPendingScan(null);
      if (source === 'barcode') {
        if (!result.duplicate && amount !== undefined) {
          setSession((current) => current?.id === scan.sessionId
            ? { ...current, lastScan: { id: product.id, qty: amount, via: source, name: result.product_name ?? product.name } }
            : current);
        }
        setCode('');
        setQty('1');
      }
      const message = source === 'barcode' ? fmt(amount ?? 1) : `«${transcript}»`;
      toastRef.current(result.duplicate
        ? 'تم تأكيد تسجيل هذا المسح'
        : `سُجّل ${message} — ${result.product_name ?? product.name}`);
      refreshSession.current?.();
    } catch (error) {
      if (scan.sessionId !== voiceContext.current.sessionId) return;
      if (isCancel(error)) return;
      const failure = normalizeError(error);
      setPendingScan(failure.retryable || failure.status >= 500 ? scan : null);
      throw failure;
    }
  };

  const submitCode = () => {
    const trimmed = code.trim();
    if (!trimmed || pendingScan) return;
    void submit(async () => {
      const p = scope.find(
        (x) => x.barcode === trimmed || x.sku.toLowerCase() === trimmed.toLowerCase()
      ) ?? await pos.lookupByBarcode(trimmed);
      if (!p) {
        beepError();
        toastRef.current(`لا يوجد صنف بالرمز ${trimmed}`, 'bad');
        return;
      }
      await recordScan({
        id: globalThis.crypto.randomUUID(),
        sessionId,
        product: p,
        amount: parseCount(qty),
        source: 'barcode',
      });
    });
  };

  const mic = () => {
    if (recognitionRef.current || submitting.current || pendingScan) return;
    const Speech =
      typeof window !== 'undefined'
        ? (window as SpeechWindow).SpeechRecognition ?? (window as SpeechWindow).webkitSpeechRecognition
        : undefined;

    if (!Speech) {
      toast('الإدخال الصوتي غير مدعوم في هذا المتصفح', 'bad');
      return;
    }

    try {
      const listener = new Speech();
      recognitionRef.current = listener;
      listener.lang = 'ar-EG';
      listener.interimResults = false;
      listener.maxAlternatives = 1;

      listener.onresult = (event) => {
        if (recognitionRef.current !== listener) return;
        stopRecognition(true);
        const context = voiceContext.current;
        if (context.sessionId !== sessionId || context.pendingScan || submitting.current) return;
        const transcript = event.results?.[0]?.[0]?.transcript?.trim() ?? '';
        setHeard(transcript);
        if (!transcript) return;
        const product = context.product;
        if (!product) {
          toastRef.current('امسح باركود الصنف أولًا قبل تسجيل الكمية بالصوت', 'bad');
          return;
        }
        void submit(() => recordScan({
          id: globalThis.crypto.randomUUID(),
          sessionId,
          product,
          source: 'voice',
          transcript,
        }));
      };

      listener.onerror = () => {
        if (recognitionRef.current !== listener) return;
        stopRecognition(true);
        toastRef.current('تعذّر سماع العدد؛ اكتب العدد بالخانة يدويًا', 'bad');
      };

      listener.onend = () => {
        if (recognitionRef.current === listener) stopRecognition(false);
      };

      setListening(true);
      setHeard('');
      listener.start();
    } catch {
      stopRecognition(true);
      toast('تعذّر بدء التسجيل الصوتي', 'bad');
    }
  };

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
                disabled={listening || busy || !!pendingScan}
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
                      readOnly={busy || !!pendingScan}
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
                      onChange={(e) => setQty(toAsciiNum(e.target.value, 4))}
                      aria-label="الكمية"
                      readOnly={busy || !!pendingScan}
                    />
                  </div>
                  <button
                    className="btn btn-primary"
                    style={{ height: 48 }}
                    disabled={busy}
                    onClick={pendingScan ? () => submit(() => recordScan(pendingScan)) : submitCode}
                  >
                    {pendingScan ? 'إعادة محاولة التسجيل' : 'تسجيل'}
                  </button>
                </div>
                {pendingScan && (
                  <p role="status" className="fs-12 t-2">
                    تعذّر تأكيد تسجيل {pendingScan.product.name}. أعد المحاولة قبل تسجيل قطعة أخرى.
                  </p>
                )}
                {import.meta.env.DEV && (
                  <div className="row gap-2">
                    <Api m="POST" path={`/pos/stocktakes/${session.id}/scan`} />
                    <span className="fs-12 t-3 ltr mono">{'{ barcode, qty | qty_words }'}</span>
                  </div>
                )}
              </div>
            </div>
            {last && lp && (
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
                  {/* F-14: server name first — the card must work even when the catalog never loaded. */}
                  <div className="fs-16 fw-5">{lpName || lp.name}</div>
                  <div className="mono t-3" style={{ fontSize: 11 }}>
                    {lp.sku ?? ''}
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
                    <tr key={p.id} className={last?.id === p.id ? 'selected' : ''}>
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
                const p = pos.getProductById(l.id);
                // F-14: server name first so the log works on the real API.
                const rowName = l.name ?? p?.name ?? '';
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
                      <div className="fs-13 fw-5 truncate">{rowName}</div>
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
            <div style={{ padding: 14, borderTop: '1px solid var(--line)', textAlign: 'center' }}>
              <button
                className="btn btn-secondary"
                style={{ width: '100%', opacity: 0.85 }}
                disabled
                title="اعتماد الجرد وترحيل الفروقات يتم من لوحة الإدارة"
              >
                المسودة تُحفظ تلقائيًا مع كل مسح (الاعتماد من لوحة الإدارة)
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

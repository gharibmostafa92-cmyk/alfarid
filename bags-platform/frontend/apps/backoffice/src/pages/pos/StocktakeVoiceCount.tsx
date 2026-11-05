import { useEffect, useRef, useState } from 'react';
import { defaultApiClient } from '@alfarid/api-client';

interface Recognition {
  lang: string;
  onresult: ((event: { results: { transcript: string }[][] }) => void) | null;
  onerror: (() => void) | null;
  onend: (() => void) | null;
  start(): void;
  abort(): void;
}
type SpeechWindow = Window & { SpeechRecognition?: new () => Recognition; webkitSpeechRecognition?: new () => Recognition };

function scanId(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 15) | 64;
  bytes[8] = (bytes[8] & 63) | 128;
  const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export default function StocktakeVoiceCount() {
  const [session, setSession] = useState('');
  const [barcode, setBarcode] = useState('');
  const [words, setWords] = useState('');
  const [busy, setBusy] = useState(false);
  const [listening, setListening] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const request = useRef<{ payload: string; id: string } | null>(null);
  const submitting = useRef(false);
  const recognition = useRef<Recognition | null>(null);
  const barcodeInput = useRef<HTMLInputElement>(null);
  const Speech = (window as SpeechWindow).SpeechRecognition ?? (window as SpeechWindow).webkitSpeechRecognition;
  useEffect(() => () => { recognition.current?.abort(); }, []);

  const listen = () => {
    if (!Speech || busy || listening) return;
    setError('');
    const listener = new Speech;
    recognition.current = listener;
    listener.lang = 'ar-EG';
    listener.onresult = event => setWords(event.results[0]?.[0]?.transcript ?? '');
    listener.onerror = () => setError('تعذّر سماع العدد؛ جرّب تاني أو اكتبه.');
    listener.onend = () => setListening(false);
    try { setListening(true); listener.start(); }
    catch { setListening(false); setError('تعذّر تشغيل الميكروفون؛ اكتب العدد.'); }
  };

  return <section dir="rtl" aria-label="عد الجرد بالصوت" style={{ maxWidth: 600, margin: '24px auto', padding: 24, border: '1px solid #d4d4d8', borderRadius: 12, display: 'grid', gap: 16 }}>
    <h2>عد الجرد بالصوت</h2>
    <p>امسح الباركود، وقول العدد أو اكتبه. راجع العدد قبل تسجيل المسحة.</p>
    <form style={{ display: 'grid', gap: 16 }} onSubmit={async event => {
      event.preventDefault();
      if (submitting.current || listening) return;
      submitting.current = true; setBusy(true); setError(''); setMessage('');
      try {
        const normalized = session.replace(/[٠-٩۰-۹]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.includes(digit) ? '٠١٢٣٤٥٦٧٨٩'.indexOf(digit) : '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)));
        if (!/^[1-9]\d*$/.test(normalized)) throw new Error('اكتب معرّف جلسة الجرد كرقم صحيح.');
        const payload = JSON.stringify({ session: normalized, barcode: barcode.trim(), words: words.trim() });
        if (!request.current || request.current.payload !== payload) request.current = { payload, id: scanId() };
        const response = await defaultApiClient.post<{ sku: string; counted_qty: string | number }>(`/api/v1/pos/stocktakes/${normalized}/scan`, { barcode: barcode.trim(), qty_words: words.trim(), scan_id: request.current.id });
        setMessage(`اتسجل ${response.data.sku} — إجمالي العد: ${response.data.counted_qty}`);
        request.current = null; setBarcode(''); setWords(''); barcodeInput.current?.focus();
      } catch (failure) {
        const e = failure as { message?: string; response?: { data?: { message?: string } } };
        setError(e.response?.data?.message ?? e.message ?? 'تعذّر التسجيل؛ أعد المحاولة بنفس البيانات.');
      } finally { submitting.current = false; setBusy(false); }
    }}>
      <label>معرّف جلسة الجرد<input aria-label="معرّف جلسة الجرد" value={session} onChange={e => setSession(e.target.value)} required inputMode="numeric" disabled={busy || listening} /></label>
      <label>الباركود<input ref={barcodeInput} aria-label="باركود الجرد" value={barcode} onChange={e => setBarcode(e.target.value)} required maxLength={100} disabled={busy || listening} /></label>
      <label>العدد<input aria-label="العدد المنطوق" value={words} onChange={e => setWords(e.target.value)} required maxLength={50} placeholder="اتنين وعشرين أو ٢٢" disabled={busy || listening} /></label>
      {Speech ? <button type="button" onClick={listen} disabled={busy || listening || !barcode.trim()}>{listening ? 'بسمع العدد…' : 'قول العدد'}</button> : <p>الإملاء الصوتي غير متاح في المتصفح ده؛ اكتب العدد.</p>}
      <button type="submit" disabled={busy || listening || !session || !barcode.trim() || !words.trim()}>{busy ? 'جارٍ التسجيل…' : 'تسجيل المسحة'}</button>
    </form>
    {message && <p role="status">{message}</p>}
    {error && <p role="alert">{error}</p>}
  </section>;
}

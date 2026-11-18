import { useEffect, useRef, useState } from "react";
import { defaultApiClient } from "@alfarid/api-client";
import { createClientRequestId } from "../../lib/idempotency";

interface Recognition {
  lang: string;
  continuous?: boolean;
  interimResults?: boolean;
  onresult: ((event: { results: { transcript: string }[][] }) => void) | null;
  onerror: (() => void) | null;
  onend: (() => void) | null;
  start(): void;
  abort(): void;
}
type SpeechWindow = Window & {
  SpeechRecognition?: new () => Recognition;
  webkitSpeechRecognition?: new () => Recognition;
};

export default function StocktakeVoiceCount() {
  const [session, setSession] = useState("");
  const [barcode, setBarcode] = useState("");
  const [words, setWords] = useState("");
  const [busy, setBusy] = useState(false);
  const [listening, setListening] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const request = useRef<{ payload: string; id: string } | null>(null);
  const submitting = useRef(false);
  const recognition = useRef<Recognition | null>(null);
  const receivedSpeech = useRef(false);
  const speechFailed = useRef(false);
  const manuallyStopped = useRef(false);
  const listenTimeout = useRef<number | null>(null);
  const barcodeInput = useRef<HTMLInputElement>(null);
  const Speech =
    (window as SpeechWindow).SpeechRecognition ??
    (window as SpeechWindow).webkitSpeechRecognition;
  const clearListenTimeout = () => {
    if (listenTimeout.current !== null) {
      window.clearTimeout(listenTimeout.current);
      listenTimeout.current = null;
    }
  };
  useEffect(
    () => () => {
      clearListenTimeout();
      manuallyStopped.current = true;
      const active = recognition.current;
      recognition.current = null;
      active?.abort();
    },
    [],
  );

  // Manual escape hatch: aborting ends the session even on engines that
  // never fire onend/onerror after start.
  const stopListening = () => {
    clearListenTimeout();
    manuallyStopped.current = true;
    const active = recognition.current;
    recognition.current = null;
    active?.abort();
    setListening(false);
  };

  const listen = () => {
    if (!Speech || busy || listening) return;
    setError("");
    const listener = new Speech();
    recognition.current = listener;
    receivedSpeech.current = false;
    speechFailed.current = false;
    manuallyStopped.current = false;
    listener.lang = "ar-EG";
    listener.continuous = false;
    listener.interimResults = false;
    listener.onresult = (event) => {
      if (recognition.current !== listener) return;
      clearListenTimeout();
      request.current = null;
      const transcript = event.results[0]?.[0]?.transcript ?? "";
      receivedSpeech.current = transcript.trim() !== "";
      setWords(transcript);
      if (!receivedSpeech.current) setError("ماسمعناش العدد؛ جرّب تاني أو اكتبه.");
    };
    // Some embedded WebViews never fire onend after certain onerror kinds;
    // without this the form locks in listening=true with no way out.
    listener.onerror = () => {
      if (recognition.current !== listener) return;
      recognition.current = null;
      clearListenTimeout();
      speechFailed.current = true;
      setListening(false);
      setError("تعذّر سماع العدد؛ جرّب تاني أو اكتبه.");
    };
    listener.onend = () => {
      if (recognition.current !== listener) return;
      recognition.current = null;
      clearListenTimeout();
      setListening(false);
      if (!receivedSpeech.current && !speechFailed.current && !manuallyStopped.current) {
        setError("ماوصلش صوت قبل انتهاء الاستماع؛ جرّب تاني أو اكتب العدد.");
      }
    };
    try {
      setListening(true);
      clearListenTimeout();
      listenTimeout.current = window.setTimeout(() => {
        const stillActive = recognition.current === listener;
        stopListening();
        if (stillActive && !receivedSpeech.current && !speechFailed.current) setError("انتهت مهلة الاستماع بعد 15 ثانية؛ جرّب تاني أو اكتب العدد.");
      }, 15_000);
      listener.start();
    } catch {
      clearListenTimeout();
      recognition.current = null;
      setListening(false);
      setError("تعذّر تشغيل الميكروفون؛ اكتب العدد.");
    }
  };

  return (
    <section
      dir="rtl"
      aria-label="عد الجرد بالصوت"
      style={{
        maxWidth: 600,
        margin: "24px auto",
        padding: 24,
        border: "1px solid #d4d4d8",
        borderRadius: 12,
        display: "grid",
        gap: 16,
      }}
    >
      <h2>عد الجرد بالصوت</h2>
      <p>امسح الباركود، وقول العدد أو اكتبه. راجع العدد قبل تسجيل المسحة.</p>
      <form
        style={{ display: "grid", gap: 16 }}
        onSubmit={async (event) => {
          event.preventDefault();
          if (submitting.current || listening) return;
          submitting.current = true;
          setBusy(true);
          setError("");
          setMessage("");
          try {
            // Arabic-Indic and Eastern Arabic digits arrive from Arabic
            // keyboards in both the session and the barcode fields.
            const toAsciiDigits = (value: string) =>
              value.replace(/[\u0660-\u0669\u06F0-\u06F9]/g, (digit) => {
                const code = digit.charCodeAt(0);
                return String(code >= 0x06f0 ? code - 0x06f0 : code - 0x0660);
              });
            const normalized = toAsciiDigits(session);
            const cleanBarcode = toAsciiDigits(barcode).trim();
            const cleanWords = toAsciiDigits(words).trim();
            if (!/^[1-9]\d*$/.test(normalized))
              throw new Error("اكتب معرّف جلسة الجرد كرقم صحيح.");
            const payload = JSON.stringify({
              session: normalized,
              barcode: cleanBarcode,
              words: cleanWords,
            });
            if (!request.current || request.current.payload !== payload)
              request.current = { payload, id: createClientRequestId() };
            const response = await defaultApiClient.post<{
              sku: string;
              counted_qty: string | number;
            }>(`/api/v1/pos/stocktakes/${normalized}/scan`, {
              barcode: cleanBarcode,
              qty_words: cleanWords,
              scan_id: request.current.id,
            });
            setMessage(
              `اتسجل ${response.data.sku} — إجمالي العد: ${response.data.counted_qty}`,
            );
            request.current = null;
            setBarcode("");
            setWords("");
            barcodeInput.current?.focus();
          } catch (failure) {
            const e = failure as {
              message?: string;
              response?: { data?: { message?: string } };
            };
            setError(
              e.response?.data?.message ??
                e.message ??
                "تعذّر التسجيل؛ أعد المحاولة بنفس البيانات.",
            );
          } finally {
            submitting.current = false;
            setBusy(false);
          }
        }}
      >
        <label>
          معرّف جلسة الجرد
          <input
            aria-label="معرّف جلسة الجرد"
            value={session}
            onChange={(e) => { request.current = null; setSession(e.target.value); }}
            required
            inputMode="numeric"
            disabled={busy || listening}
          />
        </label>
        <label>
          الباركود
          <input
            ref={barcodeInput}
            aria-label="باركود الجرد"
            value={barcode}
            onChange={(e) => { request.current = null; setBarcode(e.target.value); }}
            required
            maxLength={100}
            disabled={busy || listening}
          />
        </label>
        <label>
          العدد
          <input
            aria-label="العدد المنطوق"
            value={words}
            onChange={(e) => { request.current = null; setWords(e.target.value); }}
            required
            maxLength={50}
            placeholder="اتنين وعشرين أو ٢٢"
            disabled={busy || listening}
          />
        </label>
        {Speech ? (
          <button
            type="button"
            onClick={listening ? stopListening : listen}
            disabled={busy || (!listening && !barcode.trim())}
          >
            {listening ? "إيقاف الاستماع" : "قول العدد"}
          </button>
        ) : (
          <p>الإملاء الصوتي غير متاح في المتصفح ده؛ اكتب العدد.</p>
        )}
        <button
          type="submit"
          disabled={
            busy || listening || !session || !barcode.trim() || !words.trim()
          }
        >
          {busy ? "جارٍ التسجيل…" : "تسجيل المسحة"}
        </button>
      </form>
      {message && <p role="status">{message}</p>}
      {error && <p role="alert">{error}</p>}
    </section>
  );
}

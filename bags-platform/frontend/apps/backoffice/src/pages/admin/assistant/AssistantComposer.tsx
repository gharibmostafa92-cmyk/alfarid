import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { assistantApi } from './api';
import { Icon, entityIcon, entityLabel } from './icons';

interface Mention { entity: string; id: string | number; label: string }
interface Option extends Mention { meta?: string }

interface Props {
  /** مفعّلة لو الأدمن عنده customers.view — بدونها علامة $ للعملاء متظهرش. */
  enableCustomers?: boolean;
  /** مفعّلة لو الأدمن عنده products.view أو categories.view — بتفعّل $ للمنتجات و & للأقسام ورفع الصور. */
  enableCatalog?: boolean;
  busy: boolean;
  onSend: (text: string, selection?: undefined, mentions?: Mention[], files?: File[]) => Promise<boolean>;
  quickCommands: string[];
  seed?: { text: string; n: number; mentions?: Mention[] };
  hint?: string;
}

const MAX = 8000;
const markerMeta: Record<string, { key: string; entity: string; title: string }> = {
  '@': { key: 'users', entity: 'user', title: 'الموظفين' },
  '#': { key: 'branches', entity: 'branch', title: 'الفروع' },
  '!': { key: 'roles', entity: 'role', title: 'الأدوار والصلاحيات' },
  $: { key: 'customers', entity: 'customer', title: 'العملاء والمنتجات' },
  '&': { key: 'categories', entity: 'category', title: 'الأقسام' },
};

const COMMAND_PROMPTS: Record<string, string> = {
  '/منتج-جديد': 'اعمل منتج مسودة جديد باسم: ',
  '/جاهزية P00042': 'افحص جاهزية المنتج لنشره: P00042',
  '/صحة-الكتالوج': 'اعرض تقرير صحة الكتالوج والمنتجات الناقصة',
  '/أسعار': 'عدّل أسعار تشكيلات منتج ',
  '/قسم-جديد': 'أنشئ قسم جديد باسم: ',
};

function Highlight({ text, q }: { text: string; q: string }) {
  if (!q) return <>{text}</>;
  const i = text.indexOf(q);
  if (i < 0) return <>{text}</>;
  return <>{text.slice(0, i)}<mark>{text.slice(i, i + q.length)}</mark>{text.slice(i + q.length)}</>;
}

export default function AssistantComposer({ busy, onSend, quickCommands, seed, hint, enableCustomers = false, enableCatalog = false }: Props) {
  const [text, setText] = useState('');
  const [mentions, setMentions] = useState<Mention[]>([]);
  const [files, setFiles] = useState<File[]>([]);
  const [options, setOptions] = useState<Option[]>([]);
  const [loading, setLoading] = useState(false);
  const [searchError, setSearchError] = useState('');
  const [active, setActive] = useState(0);
  const [dismissed, setDismissed] = useState('');
  const [dragOver, setDragOver] = useState(false);
  const input = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const listId = 'as-mention-list';

  const canDollar = enableCustomers || enableCatalog;
  const match = text.match(/(?:^|\s)([@#!$&])([^\s@#!$&]*)$/);
  const rawMarker = match?.[1];
  const marker = rawMarker && ((rawMarker !== '$' || canDollar) && (rawMarker !== '&' || enableCatalog)) ? rawMarker : undefined;
  const query = marker ? match?.[2] : undefined;
  const slash = text.startsWith('/') && !text.includes('\n');
  const commands = slash ? quickCommands.filter(c => c.includes(text.slice(1).trim())) : [];
  const popKey = slash ? `/${text}` : marker ? `${marker}${query}` : '';
  const popOpen = !!popKey && dismissed !== popKey && (slash ? quickCommands.length > 0 : true);

  useEffect(() => {
    setOptions([]); setSearchError(''); setActive(0);
    if (!marker) { setLoading(false); return; }
    let live = true;
    setLoading(true);
    const timer = setTimeout(() => {
      assistantApi.mentions(query ?? '').then(result => {
        if (!live) return;
        if (marker === '$') {
          const combined: Option[] = [];
          if (enableCatalog) {
            for (const p of result.products ?? []) combined.push({ entity: 'product', id: p.id, label: p.label, meta: p.meta });
            for (const v of result.variants ?? []) combined.push({ entity: 'variant', id: v.id, label: v.label, meta: v.meta });
          }
          if (enableCustomers) {
            for (const c of result.customers ?? []) combined.push({ entity: 'customer', id: c.id, label: c.label, meta: c.meta });
          }
          setOptions(combined.slice(0, 12));
        } else {
          const { key, entity } = markerMeta[marker];
          setOptions((result[key] ?? []).slice(0, 10).map(o => ({ entity, id: o.id, label: o.label, meta: o.meta })));
        }
      }).catch(() => { if (live) setSearchError('تعذر تحميل الاقتراحات.'); })
        .finally(() => { if (live) setLoading(false); });
    }, 200);
    return () => { live = false; clearTimeout(timer); };
  }, [marker, query, enableCatalog, enableCustomers]);

  useEffect(() => { setActive(0); }, [text.startsWith('/') ? text : '']);

  useLayoutEffect(() => {
    const el = input.current; if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
  }, [text]);

  useEffect(() => {
    if (!seed) return;
    setText(seed.text);
    setMentions(seed.mentions ?? []);
    requestAnimationFrame(() => { const el = input.current; if (el) { el.focus(); el.setSelectionRange(seed.text.length, seed.text.length); } });
  }, [seed?.n]);

  const wasBusy = useRef(busy);
  useEffect(() => { if (wasBusy.current && !busy) input.current?.focus(); wasBusy.current = busy; }, [busy]);

  const addFiles = (incoming: FileList | File[]) => {
    const valid = Array.from(incoming).filter(f => /^image\/(jpeg|png|webp)$/.test(f.type));
    if (!valid.length) return;
    setFiles(prev => [...prev, ...valid].slice(0, 4));
  };

  const submit = async () => {
    if (busy || (!text.trim() && files.length === 0)) return;
    const draft = text.trim() || 'حلّل الصور المرفقة';
    const refs = mentions;
    const attached = files;
    setText(''); setMentions([]); setFiles([]); setOptions([]);
    if (!await onSend(draft, undefined, refs, attached.length ? attached : undefined)) {
      setText(draft); setMentions(refs); setFiles(attached);
    }
    input.current?.focus();
  };

  const pickMention = (o: Option) => {
    setText(t => t.replace(/([@#!$&])([^\s@#!$&]*)$/, () => `${o.label} `));
    setMentions(items => [...items.filter(m => m.id !== o.id || m.entity !== o.entity), { entity: o.entity, id: o.id, label: o.label }]);
    input.current?.focus();
  };
  const pickCommand = (c: string) => {
    const expanded = COMMAND_PROMPTS[c] ?? `${c} `;
    setText(expanded);
    setDismissed(`//${expanded}`);
    input.current?.focus();
  };
  const insertMarker = (m: string) => {
    setDismissed('');
    setText(t => (t === '' || /\s$/.test(t) ? `${t}${m}` : `${t} ${m}`));
    input.current?.focus();
  };

  const count = slash ? commands.length : options.length;
  const onKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (popOpen && count > 0) {
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(a => (a + 1) % count); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); setActive(a => (a - 1 + count) % count); return; }
      if ((e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) || e.key === 'Tab') {
        e.preventDefault();
        if (slash) pickCommand(commands[active]); else pickMention(options[active]);
        return;
      }
    }
    if (e.key === 'Escape') { if (popOpen) { e.preventDefault(); setDismissed(popKey); setOptions([]); } return; }
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); void submit(); }
  };

  const meta = marker ? markerMeta[marker] : undefined;
  const near = text.length > MAX * 0.9;

  return (
    <form
      className="as-composer"
      data-disabled={busy}
      data-drag={dragOver}
      onDragOver={e => { if (enableCatalog) { e.preventDefault(); setDragOver(true); } }}
      onDragLeave={() => setDragOver(false)}
      onDrop={e => {
        if (!enableCatalog) return;
        e.preventDefault();
        setDragOver(false);
        if (e.dataTransfer.files?.length) addFiles(e.dataTransfer.files);
      }}
      onSubmit={e => { e.preventDefault(); void submit(); }}
    >
      {popOpen && (
        <div className="as-pop" role="dialog" aria-label={slash ? 'الأوامر السريعة' : 'اقتراحات المنشن'}>
          <div className="as-pop-head">
            <Icon name={slash ? 'command' : entityIcon[meta!.entity]} size={14} />
            <strong>{slash ? 'الأوامر السريعة' : meta!.title}</strong>
            {!slash && query ? <span>· «{query}»</span> : null}
            <span className="as-kbdrow as-only-desktop"><span className="as-kbd">↑</span><span className="as-kbd">↓</span> تنقّل <span className="as-kbd">↵</span> اختيار <span className="as-kbd">Esc</span></span>
          </div>
          {slash ? (
            commands.length ? (
              <ul className="as-pop-list" role="listbox" id={listId}>
                {commands.map((c, i) => (
                  <li key={c} role="option" aria-selected={i === active}>
                    <button type="button" className="as-opt" aria-selected={i === active} disabled={busy} onMouseEnter={() => setActive(i)} onClick={() => pickCommand(c)}>
                      <span className="as-opt-ic"><Icon name="bolt" size={15} /></span>
                      <span className="as-opt-txt"><span className="as-opt-label"><Highlight text={c} q={text.slice(1).trim()} /></span></span>
                      <span className="as-kbd as-opt-enter">↵</span>
                    </button>
                  </li>
                ))}
              </ul>
            ) : (
              <div className="as-pop-empty"><Icon name="command" size={22} /><strong>مفيش أمر بالاسم ده</strong><span>اكتب طلبك عادي والمساعد هيفهمه.</span></div>
            )
          ) : loading ? (
            <div className="as-pop-loading" aria-label="جارٍ التحميل"><i /><i /><i /></div>
          ) : searchError ? (
            <div className="as-pop-empty" role="alert"><Icon name="alert" size={22} /><strong>{searchError}</strong><span>تأكد من الاتصال وحاول تاني.</span></div>
          ) : options.length ? (
            <ul className="as-pop-list" role="listbox" id={listId}>
              {options.map((o, i) => (
                <li key={`${o.entity}-${o.id}`} role="option" aria-selected={i === active}>
                  <button type="button" className="as-opt" aria-selected={i === active} disabled={busy} onMouseEnter={() => setActive(i)} onClick={() => pickMention(o)}>
                    <span className="as-opt-ic"><Icon name={entityIcon[o.entity]} size={15} /></span>
                    <span className="as-opt-txt">
                      <span className="as-opt-label"><Highlight text={o.label} q={query ?? ''} /></span>
                      {o.meta && <span className="as-opt-meta">{o.meta}</span>}
                    </span>
                    <span className="as-kbd as-opt-enter">↵</span>
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <div className="as-pop-empty">
              <Icon name="search" size={22} />
              <strong>مفيش {entityLabel[meta!.entity]} مطابق{query ? ` لـ «${query}»` : ''}</strong>
              <span>جرّب جزء من الاسم أو الكود.</span>
            </div>
          )}
        </div>
      )}

      <div className="as-composer-box">
        {mentions.length > 0 && (
          <div className="as-tags" aria-label="العناصر المشار إليها">
            {mentions.map(m => (
              <span className="as-tag" data-entity={m.entity} key={`${m.entity}-${m.id}`}>
                <Icon name={entityIcon[m.entity] ?? 'layers'} size={13} />
                {m.label}
                <button type="button" disabled={busy} onClick={() => setMentions(items => items.filter(o => o !== m))} aria-label={`إزالة منشن ${m.label}`}><Icon name="x" size={12} /></button>
              </span>
            ))}
          </div>
        )}
        {files.length > 0 && (
          <div className="as-tags" aria-label="الصور المرفقة" style={{ gap: '.5rem', padding: '.4rem .6rem' }}>
            {files.map((f, idx) => (
              <span key={`${f.name}-${idx}`} className="as-tag" style={{ display: 'inline-flex', alignItems: 'center', gap: '.4rem' }}>
                <Icon name="image" size={13} />
                <span style={{ maxWidth: 140, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{f.name}</span>
                <button type="button" disabled={busy} onClick={() => setFiles(list => list.filter((_, i) => i !== idx))} aria-label={`إزالة ${f.name}`}><Icon name="x" size={12} /></button>
              </span>
            ))}
          </div>
        )}
        <textarea
          ref={input}
          aria-label="رسالة المساعد"
          aria-autocomplete="list"
          aria-controls={popOpen ? listId : undefined}
          aria-expanded={popOpen}
          value={text}
          maxLength={MAX}
          disabled={busy}
          rows={1}
          onChange={e => { setText(e.target.value); if (dismissed && !e.target.value) setDismissed(''); }}
          placeholder={busy ? 'المساعد بيجهّز الرد…' : `اكتب طلبك… استخدم @ للموظفين، # للفروع، ! للأدوار،${canDollar ? ' $ للمنتجات والعملاء،' : ''}${enableCatalog ? ' & للأقسام،' : ''} / للأوامر`}
          onKeyDown={onKeyDown}
        />
        <div className="as-composer-foot">
          <button type="button" className="as-trigger as-trigger--main" disabled={busy} onClick={() => insertMarker('@')} title="منشن موظف" aria-label="منشن موظف">@</button>
          <button type="button" className="as-trigger" disabled={busy} onClick={() => insertMarker('#')} title="منشن فرع" aria-label="منشن فرع">#</button>
          <button type="button" className="as-trigger" disabled={busy} onClick={() => insertMarker('!')} title="منشن دور" aria-label="منشن دور">!</button>
          {canDollar && <button type="button" className="as-trigger" disabled={busy} onClick={() => insertMarker('$')} title="منشن منتج أو عميل" aria-label="منشن منتج أو عميل">$</button>}
          {enableCatalog && <button type="button" className="as-trigger" disabled={busy} onClick={() => insertMarker('&')} title="منشن قسم" aria-label="منشن قسم">&amp;</button>}
          {enableCatalog && (
            <>
              <input
                ref={fileInputRef}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                 hidden
                onChange={e => { if (e.target.files?.length) addFiles(e.target.files); e.target.value = ''; }}
              />
              <button type="button" className="as-trigger" disabled={busy || files.length >= 4} onClick={() => fileInputRef.current?.click()} title="إرفاق صور منتج (حتى 4 صور)" aria-label="إرفاق صور منتج">
                <Icon name="paperclip" size={14} />
              </button>
            </>
          )}
          {quickCommands.length > 0 && <button type="button" className="as-trigger" disabled={busy} onClick={() => { setDismissed(''); setText('/'); input.current?.focus(); }} title="الأوامر السريعة" aria-label="الأوامر السريعة">/</button>}
          <span className="as-counter as-num" data-near={near}>{text.length > 0 ? `${text.length.toLocaleString('ar-EG')} / ${MAX.toLocaleString('ar-EG')}` : ''}</span>
          <button className="as-send" type="submit" disabled={busy || (!text.trim() && files.length === 0)} data-busy={busy} aria-label={busy ? 'جارٍ الإرسال' : 'إرسال'}>
            {busy ? <span className="as-spin" /> : <Icon name="send" size={18} />}
          </button>
        </div>
      </div>
      <p className="as-composer-note">
        {hint ?? <><span className="as-kbd">↵</span> إرسال · <span className="as-kbd">Shift</span>+<span className="as-kbd">↵</span> سطر جديد · مفيش أي تعديل بيتنفذ قبل تأكيدك</>}
      </p>
    </form>
  );
}

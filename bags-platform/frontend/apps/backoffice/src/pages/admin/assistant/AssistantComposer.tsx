import { useEffect, useRef, useState } from 'react';
import { assistantApi } from './api';

interface Mention { entity: string; id: string | number; label: string }
export default function AssistantComposer({ busy, onSend, quickCommands }: { busy: boolean; onSend: (text: string, selection?: undefined, mentions?: Mention[]) => Promise<boolean>; quickCommands: string[] }) {
  const [text, setText] = useState('');
  const [mentions, setMentions] = useState<Mention[]>([]);
  const [options, setOptions] = useState<Mention[]>([]);
  const [searchError, setSearchError] = useState('');
  const input = useRef<HTMLTextAreaElement>(null);
  const match = text.match(/(?:^|\s)([@#!])([^\s@#!]*)$/);
  const marker = match?.[1], query = match?.[2];
  useEffect(() => {
    setOptions([]); setSearchError('');
    if (!marker) return;
    let live = true;
    const timer = setTimeout(() => {
      assistantApi.mentions(query ?? '').then(result => {
        if (!live) return;
        const key = marker === '@' ? 'users' : marker === '#' ? 'branches' : 'roles';
        const entity = marker === '@' ? 'user' : marker === '#' ? 'branch' : 'role';
        setOptions((result[key] ?? []).slice(0, 10).map(o => ({ entity, id: o.id, label: o.label })));
      }).catch(() => { if (live) setSearchError('تعذر تحميل الاقتراحات.'); });
    }, 200);
    return () => { live = false; clearTimeout(timer); };
  }, [marker, query]);
  const submit = async () => {
    if (busy || !text.trim()) return;
    const draft = text; const refs = mentions; setText(''); setMentions([]); setOptions([]);
    if (!await onSend(draft, undefined, refs)) { setText(draft); setMentions(refs); }
    input.current?.focus();
  };
  return <form className="assistant-composer" onSubmit={e => { e.preventDefault(); void submit(); }}>
    {text.startsWith('/') && <div className="assistant-mentions" aria-label="الأوامر السريعة">{quickCommands.filter(command => command.includes(text.slice(1))).map(command => <button type="button" disabled={busy} key={command} onClick={() => { setText(command); input.current?.focus(); }}>{command}</button>)}</div>}
    {marker && <div className="assistant-mentions" aria-label="اقتراحات المنشن">{options.map(o => <button type="button" key={`${o.entity}-${o.id}`} disabled={busy} onClick={() => { setText(t => t.replace(/([@#!])([^\s@#!]*)$/, `${o.label} `)); setMentions(items => [...items.filter(m => m.id !== o.id || m.entity !== o.entity), o]); input.current?.focus(); }}>{o.label}</button>)}{searchError && <small role="alert">{searchError}</small>}</div>}
    {mentions.length > 0 && <div className="assistant-mention-tags">{mentions.map(m => <button type="button" disabled={busy} key={`${m.entity}-${m.id}`} onClick={() => setMentions(items => items.filter(o => o !== m))} aria-label={`إزالة منشن ${m.label}`}>{m.label} ×</button>)}</div>}
    <div className="assistant-compose-row"><textarea ref={input} aria-label="رسالة المساعد" value={text} maxLength={8000} disabled={busy} rows={2} onChange={e => setText(e.target.value)} placeholder="اكتب طلبك… @ للموظفين، # للفروع، ! للأدوار" onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); void submit(); } if (e.key === 'Escape') setOptions([]); }} /><button className="assistant-primary" type="submit" disabled={busy || !text.trim()}>إرسال</button></div>
  </form>;
}

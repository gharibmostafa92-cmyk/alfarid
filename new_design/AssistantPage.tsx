import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { assistantApi, type Conv, type ConvMsg, type UiBlock } from './api';
import AssistantBlock from './AssistantBlock';
import AssistantComposer from './AssistantComposer';
import AssistantAdministration from './AssistantAdministration';
import { RichText } from './RichText';
import { Icon, entityIcon } from './icons';
import assistantStyles from './assistant.css?inline';

interface Mention {
  entity: string;
  id: string | number;
  label: string;
}

// L-1: مع responseType: 'text' تصل response.data نصًا — تُفقد رسائل
// 429 (الميزانية) و423 (طلب جارٍ) و403. JSON.parse آمن داخل catch.
function apiErrorMessage(e: unknown, fallback: string): string {
  const data = (e as { response?: { data?: unknown } }).response?.data;
  if (typeof data === 'string') {
    try {
      const parsed = JSON.parse(data) as { message?: unknown };
      if (typeof parsed.message === 'string' && parsed.message !== '') return parsed.message;
    } catch {
      if (data !== '') return data;
    }
  }
  if (typeof data === 'object' && data !== null && typeof (data as { message?: unknown }).message === 'string') {
    return (data as { message: string }).message;
  }
  return fallback;
}
// تصنيف الخطأ عشان نعرض شكل مناسب (ميزانية / طلب جارٍ / صلاحية / مؤقت).
function apiErrorKind(e: unknown): 'budget' | 'locked' | 'forbidden' | 'transient' {
  const code = (e as { response?: { status?: number } }).response?.status;
  if (code === 429) return 'budget';
  if (code === 423) return 'locked';
  if (code === 403) return 'forbidden';
  return 'transient';
}
const errorTitles = { budget: 'وصلت للحد اليومي', locked: 'في طلب لسه شغال', forbidden: 'مش مسموح', transient: 'حصلت مشكلة' } as const;

const STARTERS: { icon: string; tag: string; text: string }[] = [
  { icon: 'branch', tag: 'الفروع', text: 'اعرض الفروع المتوقفة حاليًا مع آخر تحديث لكل فرع' },
  { icon: 'key', tag: 'الصلاحيات', text: 'مين الموظفين اللي عندهم صلاحية حذف موظف؟' },
  { icon: 'user', tag: 'الموظفين', text: 'أضف موظف جديد في فرع ' },
  { icon: 'role', tag: 'الأدوار', text: 'قارن صلاحيات دور مدير فرع ودور أمين مخزن' },
];
const QUICK_COMMANDS = ['/ملخص الفروع', '/موظفين بدون دور', '/صلاحيات حساسة', '/آخر التعديلات'];

const dayKey = (iso: string | null) => {
  if (!iso) return 'older';
  const d = new Date(iso); const t = new Date();
  const start = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const diff = Math.round((start(t) - start(d)) / 86400000);
  return diff <= 0 ? 'today' : diff === 1 ? 'yesterday' : diff < 7 ? 'week' : 'older';
};
const groupTitles: Record<string, string> = { pinned: 'المثبّتة', today: 'النهارده', yesterday: 'امبارح', week: 'آخر ٧ أيام', older: 'أقدم' };
const relTime = (iso: string | null) => {
  if (!iso) return '';
  const d = new Date(iso);
  return dayKey(iso) === 'today' ? d.toLocaleTimeString('ar-EG', { hour: 'numeric', minute: '2-digit' }) : d.toLocaleDateString('ar-EG', { day: 'numeric', month: 'short' });
};

// props جديدة اختيارية (مش بتكسر الاستدعاء الحالي <AssistantPage can={can} />):
// - theme: إجبار light/dark بدل prefers-color-scheme
// - initialConversationId: فتح محادثة مباشرة (deep link)
export default function AssistantPage({ can, theme, initialConversationId }: { can: (permission: string) => boolean; theme?: 'light' | 'dark'; initialConversationId?: number }) {
  const [resolvedTheme, setResolvedTheme] = useState<'light' | 'dark'>(() => {
    if (theme) return theme;
    if (typeof document !== 'undefined') {
      const doc = document.documentElement.getAttribute('data-theme');
      if (doc === 'dark' || doc === 'light') return doc;
    }
    return 'light';
  });

  useEffect(() => {
    if (theme) {
      setResolvedTheme(theme);
      return;
    }
    const update = () => {
      const doc = document.documentElement.getAttribute('data-theme');
      if (doc === 'dark' || doc === 'light') setResolvedTheme(doc);
    };
    const observer = new MutationObserver(update);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    return () => observer.disconnect();
  }, [theme]);

  const [conversations, setConversations] = useState<Conv[]>([]);
  const [listLoading, setListLoading] = useState(true);
  const [currentId, setCurrentId] = useState<number | null>(null);
  const [messages, setMessages] = useState<ConvMsg[]>([]);
  const [actionStatus, setActionStatus] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [streaming, setStreaming] = useState(false);
  const [status, setStatus] = useState('جاهز');
  const [steps, setSteps] = useState<string[]>([]);
  const [startedAt, setStartedAt] = useState(0);
  const [pendingText, setPendingText] = useState<{ text: string; mentions: Mention[] } | null>(null);
  const [error, setError] = useState<{ message: string; kind: ReturnType<typeof apiErrorKind>; retry?: () => void } | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');
  const [oncePassword, setOncePassword] = useState('');
  const [copied, setCopied] = useState(false);
  const [revealed, setRevealed] = useState(false);
  const [tab, setTab] = useState<'chat' | 'admin'>('chat');
  const [drawer, setDrawer] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<number | null>(null);
  const [seed, setSeed] = useState<{ text: string; n: number }>();
  const [atBottom, setAtBottom] = useState(true);
  const [, tick] = useState(0);
  const feedRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const execute = can('ai_agent.execute');
  const current = conversations.find(c => c.id === currentId);

  useEffect(() => { const t = setTimeout(() => setDebouncedQuery(searchQuery), 250); return () => clearTimeout(t); }, [searchQuery]);

  const refreshList = useCallback(async () => {
    setListLoading(true);
    try {
      const page = await assistantApi.listPaginated(1, debouncedQuery);
      setConversations(page.data);
    } catch {
      setError({ message: 'تعذر تحميل المحادثات.', kind: 'transient', retry: () => void refreshList() });
    } finally {
      setListLoading(false);
    }
  }, [debouncedQuery]);

  useEffect(() => { void refreshList(); }, [refreshList]);

  // عدّاد زمن التفكير
  useEffect(() => {
    if (!streaming) return;
    const t = setInterval(() => tick(n => n + 1), 1000);
    return () => clearInterval(t);
  }, [streaming]);

  // scroll فوري (مش smooth) عشان أحداث الـ scroll الوسيطة متلغيش "atBottom".
  const scrollToBottom = useCallback(() => {
    const el = feedRef.current; if (!el) return;
    requestAnimationFrame(() => { el.scrollTop = el.scrollHeight; });
  }, []);
  useEffect(() => { if (atBottom) scrollToBottom(); }, [messages, steps, pendingText, streaming, atBottom, scrollToBottom]);

  // اختصارات لوحة المفاتيح: Ctrl/⌘+K بحث، Ctrl/⌘+Shift+O محادثة جديدة.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const mod = e.ctrlKey || e.metaKey;
      if (mod && e.key.toLowerCase() === 'k') { e.preventDefault(); setTab('chat'); setCollapsed(false); setDrawer(true); setTimeout(() => searchRef.current?.focus(), 50); }
      if (mod && e.shiftKey && e.key.toLowerCase() === 'o') { e.preventDefault(); void createConversation(); }
      if (e.key === 'Escape') setDrawer(false);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  });

  const openConversation = useCallback(async (id: number) => {
    setBusy(true);
    setError(null);
    setDrawer(false);
    setTab('chat');
    try {
      const conv = await assistantApi.show(id);
      setCurrentId(conv.id);
      setMessages(conv.messages ?? []);
      const pending: Record<string, string> = {};
      for (const action of conv.pending_actions ?? []) pending[String(action.id)] = action.status;
      setActionStatus(pending);
      setConversations(prev => prev.map(c => (c.id === conv.id ? { ...c, ...conv, messages: undefined } : c)));
      setAtBottom(true);
    } catch {
      setError({ message: 'تعذر فتح المحادثة.', kind: 'transient', retry: () => void openConversation(id) });
    } finally {
      setBusy(false);
    }
  }, []);

  useEffect(() => { if (initialConversationId) void openConversation(initialConversationId); }, [initialConversationId, openConversation]);

  const createConversation = useCallback(async () => {
    // لو المحادثة الحالية فاضية أصلاً، مفيش داعي ننشئ واحدة جديدة.
    if (currentId && messages.length === 0) { setDrawer(false); setTab('chat'); return; }
    setBusy(true);
    setError(null);
    try {
      const conv = await assistantApi.create();
      setConversations(prev => [conv, ...prev]);
      setCurrentId(conv.id);
      setMessages([]);
      setActionStatus({});
      setDrawer(false);
      setTab('chat');
    } catch {
      setError({ message: 'تعذر إنشاء محادثة.', kind: 'transient', retry: () => void createConversation() });
    } finally {
      setBusy(false);
    }
  }, [currentId, messages.length]);

  // حذف بتأكيد inline بدل window.confirm
  const deleteConversation = useCallback(async (id: number) => {
    setConfirmDelete(null);
    try {
      await assistantApi.remove(id);
      setConversations(prev => prev.filter(c => c.id !== id));
      if (currentId === id) { setCurrentId(null); setMessages([]); }
    } catch {
      setError({ message: 'تعذر حذف المحادثة.', kind: 'transient' });
    }
  }, [currentId]);

  const togglePin = useCallback(async (c: Conv) => {
    setConversations(prev => prev.map(x => (x.id === c.id ? { ...x, is_pinned: !c.is_pinned } : x)));
    try { await assistantApi.pin(c.id, !c.is_pinned); }
    catch {
      setConversations(prev => prev.map(x => (x.id === c.id ? { ...x, is_pinned: c.is_pinned } : x)));
      setError({ message: 'تعذر تثبيت المحادثة.', kind: 'transient' });
    }
  }, []);

  const send = useCallback(
    async (text: string, _selection?: undefined, mentions?: Mention[]): Promise<boolean> => {
      let id = currentId;
      if (!id) {
        try {
          const conv = await assistantApi.create();
          id = conv.id;
          setConversations(prev => [conv, ...prev]);
          setCurrentId(id);
        } catch {
          setError({ message: 'تعذر إنشاء محادثة.', kind: 'transient' });
          return false;
        }
      }
      setBusy(true);
      setStreaming(true);
      setStatus('ببعت الطلب…');
      setSteps([]);
      setStartedAt(Date.now());
      setPendingText({ text, mentions: mentions ?? [] });
      setError(null);
      setAtBottom(true);
      try {
        await assistantApi.stream(
          id,
          { text, mentions: mentions?.map(m => ({ entity: m.entity, id: m.id })) },
          message => {
            setStatus(prev => {
              if (prev !== message && prev !== 'جاهز') setSteps(s => (s.at(-1) === prev ? s : [...s, prev].slice(-4)));
              return message;
            });
          },
        );
        setStreaming(false);
        setPendingText(null);
        await openConversation(id);
        return true;
      } catch (e) {
        const convId = id;
        setError({
          message: apiErrorMessage(e, 'تعذر إتمام طلب المساعد. حاول مرة تانية.'),
          kind: apiErrorKind(e),
          retry: apiErrorKind(e) === 'budget' ? undefined : () => { setCurrentId(convId); void send(text, undefined, mentions); },
        });
        return false;
      } finally {
        setBusy(false);
        setStreaming(false);
        setPendingText(null);
        setStatus('جاهز');
      }
    },
    [currentId, openConversation],
  );

  const pick = useCallback(
    async (entity: string, id: string | number, label: string) => {
      if (!currentId) return;
      await send(`اخترت: ${label}`, undefined, [{ entity, id, label }]);
    },
    [currentId, send],
  );

  const act = useCallback(
    async (kind: 'confirm' | 'cancel' | 'undo', id: string, typed?: string, selected?: string[]) => {
      setBusy(true);
      setError(null);
      try {
        if (kind === 'confirm') {
          const res = await assistantApi.confirm(id, typed, selected);
          const pw = (res.result as { generated_password?: unknown } | undefined)?.generated_password;
          if (typeof pw === 'string' && pw !== '') { setOncePassword(pw); setCopied(false); setRevealed(false); }
        } else if (kind === 'cancel') await assistantApi.cancel(id);
        else await assistantApi.undo(id);
        if (currentId) await openConversation(currentId);
      } catch (e) {
        setError({ message: apiErrorMessage(e, 'تعذر تنفيذ الإجراء.'), kind: apiErrorKind(e) });
      } finally {
        setBusy(false);
      }
    },
    [currentId, openConversation],
  );

  const groups = useMemo(() => {
    const g: Record<string, Conv[]> = {};
    for (const c of conversations) {
      const k = c.is_pinned ? 'pinned' : dayKey(c.last_message_at);
      (g[k] ??= []).push(c);
    }
    return ['pinned', 'today', 'yesterday', 'week', 'older'].filter(k => g[k]?.length).map(k => ({ key: k, items: g[k] }));
  }, [conversations]);

  const pendingCount = (c: Conv) => (c.pending_actions ?? []).filter(a => a.status === 'pending').length;
  const elapsed = streaming ? Math.floor((Date.now() - startedAt) / 1000) : 0;
  const totalTokens = conversations.reduce((s, c) => s + (c.total_tokens || 0), 0);

  /* ---------------- Sidebar ---------------- */
  const sidebar = (
    <aside className="as-sidebar" aria-label="المحادثات">
      <div className="as-brand">
        <span className="as-mark"><Icon name="spark" size={18} /></span>
        <div>
          <h1>مساعد الفريد</h1>
          <p>الفروع، الموظفين، والصلاحيات</p>
        </div>
        <button type="button" className="as-iconbtn as-only-desktop" onClick={() => setCollapsed(true)} aria-label="إخفاء القائمة" title="إخفاء القائمة"><Icon name="sidebar" /></button>
        <button type="button" className="as-iconbtn as-only-mobile" onClick={() => setDrawer(false)} aria-label="إغلاق القائمة"><Icon name="x" /></button>
      </div>
      <div className="as-sidebar-tools">
        <button type="button" className="as-btn as-btn--primary as-btn--block" onClick={() => void createConversation()} disabled={busy}>
          <Icon name="plus" size={16} />محادثة جديدة
        </button>
        <div className="as-searchfield">
          <Icon name="search" size={16} />
          <input ref={searchRef} className="as-input" aria-label="بحث في المحادثات" type="search" value={searchQuery} onChange={e => setSearchQuery(e.target.value)} placeholder="ابحث في المحادثات…" />
          {!searchQuery && <span className="as-kbd as-only-desktop">⌘K</span>}
        </div>
      </div>
      <nav className="as-convlist" aria-label="قائمة المحادثات">
        {listLoading && !conversations.length ? (
          <>{[0, 1, 2, 3].map(i => <div className="as-skel" key={i} style={{ opacity: 1 - i * 0.2 }} />)}</>
        ) : groups.length ? (
          groups.map(g => (
            <div key={g.key} role="group" aria-label={groupTitles[g.key]}>
              <div className="as-group-label">{g.key === 'pinned' && <Icon name="pin" size={12} />}{groupTitles[g.key]}</div>
              {g.items.map(c => {
                const pc = pendingCount(c);
                return (
                  <div className="as-conv" key={c.id} data-active={c.id === currentId} data-confirming={confirmDelete === c.id}>
                    {confirmDelete === c.id ? (
                      <>
                        <span className="as-conv-open" style={{ color: 'var(--as-danger)', fontSize: '.84rem' }}>حذف «{c.title ?? `محادثة ${c.id}`}»؟</span>
                        <div className="as-conv-delete-confirm">
                          <button type="button" className="as-btn as-btn--sm as-btn--danger" onClick={() => void deleteConversation(c.id)}>حذف</button>
                          <button type="button" className="as-btn as-btn--sm as-btn--ghost" onClick={() => setConfirmDelete(null)} autoFocus>لا</button>
                        </div>
                      </>
                    ) : (
                      <>
                        <button type="button" className="as-conv-open" disabled={busy} aria-current={c.id === currentId ? 'page' : undefined} onClick={() => void openConversation(c.id)}>
                          <span className="as-conv-title">{c.title ?? `محادثة ${c.id}`}</span>
                          <span className="as-conv-meta">
                            {relTime(c.last_message_at) || 'جديدة'}
                            {pc > 0 && <><span className="as-dot" />{pc} بانتظار التأكيد</>}
                          </span>
                        </button>
                        <div className="as-conv-actions">
                          <button type="button" className="as-iconbtn as-iconbtn--sm" aria-pressed={c.is_pinned} aria-label={c.is_pinned ? 'إلغاء التثبيت' : 'تثبيت المحادثة'} title={c.is_pinned ? 'إلغاء التثبيت' : 'تثبيت'} onClick={() => void togglePin(c)}><Icon name="pin" size={15} /></button>
                          <button type="button" className="as-iconbtn as-iconbtn--sm" disabled={busy} aria-label="حذف المحادثة" title="حذف" onClick={() => setConfirmDelete(c.id)}><Icon name="trash" size={15} /></button>
                        </div>
                      </>
                    )}
                  </div>
                );
              })}
            </div>
          ))
        ) : debouncedQuery ? (
          <div className="as-list-empty">
            <span className="as-empty-icon"><Icon name="search" size={20} /></span>
            <strong>مفيش نتائج لـ «{debouncedQuery}»</strong>
            <span>جرّب كلمة تانية أو امسح البحث.</span>
            <button type="button" className="as-btn as-btn--sm" onClick={() => setSearchQuery('')}>مسح البحث</button>
          </div>
        ) : (
          <div className="as-list-empty">
            <span className="as-empty-icon"><Icon name="chat" size={20} /></span>
            <strong>مفيش محادثات لسه</strong>
            <span>محادثاتك مع المساعد هتظهر هنا، وتقدر تثبّت المهم منها.</span>
          </div>
        )}
      </nav>
      <div className="as-sidebar-foot">
        <Icon name="chart" size={14} />
        <span className="as-num">{totalTokens.toLocaleString('ar-EG')} توكن</span>
        <button type="button" className="as-btn as-btn--ghost as-btn--sm" style={{ marginInlineStart: 'auto' }} onClick={() => { setTab('admin'); setDrawer(false); }}>التفاصيل</button>
      </div>
    </aside>
  );

  /* ---------------- Chat feed ---------------- */
  const welcome = (
    <div className="as-welcome">
      <span className="as-welcome-mark"><Icon name="spark" size={28} /></span>
      <div style={{ display: 'grid', gap: '.5rem', justifyItems: 'center' }}>
        <h3>{currentId ? 'محادثة جديدة — ابدأ بطلبك' : 'أهلاً، أقدر أساعدك في إيه النهارده؟'}</h3>
        <p>اسأل عن الفروع والموظفين والصلاحيات. أي تعديل بيظهر لك كمعاينة «قبل / بعد» ومبيتنفذش غير بعد تأكيدك.</p>
      </div>
      <div className="as-starters">
        {STARTERS.map(s => (
          <button type="button" key={s.text} className="as-starter" disabled={busy} onClick={() => setSeed({ text: s.text, n: Date.now() })}>
            <span className="as-starter-top"><Icon name={s.icon} size={14} />{s.tag}</span>
            <span className="as-starter-text">{s.text}</span>
          </button>
        ))}
      </div>
      <div className="as-hints">
        <span><span className="as-kbd">@</span> موظف</span>
        <span><span className="as-kbd">#</span> فرع</span>
        <span><span className="as-kbd">!</span> دور</span>
        <span><span className="as-kbd">/</span> أمر سريع</span>
      </div>
    </div>
  );

  const errorAlert = error && (
    <div className={`as-alert as-alert--${error.kind === 'transient' ? 'danger' : 'warning'}`} role="alert">
      <Icon name={error.kind === 'budget' ? 'chart' : error.kind === 'locked' ? 'clock' : error.kind === 'forbidden' ? 'lock' : 'alert'} size={17} />
      <div className="as-alert-body">
        <strong>{errorTitles[error.kind]}</strong>
        <span>{error.message}</span>
      </div>
      <div className="as-alert-actions">
        {error.retry && <button type="button" className="as-btn as-btn--sm" onClick={() => { const r = error.retry!; setError(null); r(); }}><Icon name="refresh" size={14} />إعادة المحاولة</button>}
        {error.kind === 'budget' && <button type="button" className="as-btn as-btn--sm" onClick={() => { setError(null); setTab('admin'); }}>الاستهلاك</button>}
        <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="إخفاء التنبيه" onClick={() => setError(null)}><Icon name="x" size={14} /></button>
      </div>
    </div>
  );

  const chat = (
    <>
      <div
        className="as-feed"
        ref={feedRef}
        role="log"
        aria-live="polite"
        aria-busy={busy}
        onScroll={e => { const el = e.currentTarget; setAtBottom(el.scrollHeight - el.scrollTop - el.clientHeight < 80); }}
      >
        {!messages.length && !pendingText ? welcome : (
          <div className="as-feed-inner">
            {messages.map(m => {
              const isUser = m.role === 'user';
              return (
                <article className={`as-msg ${isUser ? 'as-msg--user' : 'as-msg--assistant'}`} key={m.id}>
                  {isUser ? (
                    <>
                      {m.content && <div className="as-bubble">{m.content}</div>}
                      <span className="as-msg-user-meta">{relTime(m.created_at)}</span>
                    </>
                  ) : (
                    <>
                      <div className="as-msg-head">
                        <span className="as-avatar"><Icon name="spark" size={14} /></span>
                        <strong>المساعد</strong>
                        <span>{relTime(m.created_at)}</span>
                        {m.content && (
                          <span className="as-msg-tools">
                            <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="نسخ الرد" title="نسخ" onClick={() => void navigator.clipboard?.writeText(m.content ?? '').catch(() => undefined)}><Icon name="copy" size={14} /></button>
                          </span>
                        )}
                      </div>
                      <div className="as-msg-body">
                        <RichText text={m.content} />
                        {(m.ui_blocks ?? []).map((block: UiBlock, i: number) => (
                          <AssistantBlock
                            key={`${m.id}-${i}`}
                            block={block}
                            busy={busy}
                            status={actionStatus[String(block.action_id ?? block.undo_action_id ?? '')]}
                            execute={execute}
                            onPick={(entity, id, label) => void pick(entity, id, label)}
                            onAction={(kind, id, typed, selected) => void act(kind, id, typed, selected)}
                          />
                        ))}
                      </div>
                    </>
                  )}
                </article>
              );
            })}

            {pendingText && (
              <article className="as-msg as-msg--user">
                <div className="as-bubble">{pendingText.text}</div>
                {pendingText.mentions.length > 0 && (
                  <span className="as-mention-inline">
                    {pendingText.mentions.map(mm => <span key={`${mm.entity}-${mm.id}`} className="as-tag as-tag--static" data-entity={mm.entity}><Icon name={entityIcon[mm.entity] ?? 'layers'} size={12} />{mm.label}</span>)}
                  </span>
                )}
              </article>
            )}
            {streaming && (
              <article className="as-msg as-msg--assistant" aria-label="المساعد بيجهّز الرد">
                <div className="as-msg-head">
                  <span className="as-avatar"><Icon name="spark" size={14} /></span>
                  <strong>المساعد</strong>
                </div>
                <div className="as-msg-body">
                  <div className="as-thinking">
                    <div className="as-thinking-now">
                      <span className="as-dots" aria-hidden="true"><i /><i /><i /></span>
                      <span>{status}</span>
                    </div>
                    {steps.length > 0 && (
                      <ul className="as-steps">
                        {steps.map((s, i) => <li key={i}><Icon name="check" size={13} />{s}</li>)}
                      </ul>
                    )}
                    <span className="as-thinking-time as-num">{elapsed > 0 ? `${elapsed.toLocaleString('ar-EG')} ث` : ''}</span>
                  </div>
                </div>
              </article>
            )}
          </div>
        )}
      </div>
      {!atBottom && messages.length > 0 && (
        <button type="button" className="as-jump" onClick={() => { setAtBottom(true); scrollToBottom(); }}><Icon name="arrowDown" size={14} />آخر الرسائل</button>
      )}
      {error && <div className="as-error-dock">{errorAlert}</div>}
      <span className="as-sr" role="status">{status}</span>
      <div className="as-composer-wrap">
        <AssistantComposer busy={busy} onSend={send} quickCommands={QUICK_COMMANDS} seed={seed} />
      </div>
    </>
  );

  return (
    <div className="assistant" dir="rtl" data-theme={resolvedTheme}>
      <style>{assistantStyles}</style>
      <div className="as-shell" data-collapsed={collapsed} data-drawer={drawer}>
        {sidebar}
        <div className="as-drawer-scrim" onClick={() => setDrawer(false)} aria-hidden="true" />
        <main className="as-main" aria-label="المحادثة الحالية">
          <header className="as-topbar">
            <button type="button" className="as-iconbtn as-only-mobile" onClick={() => setDrawer(true)} aria-label="فتح قائمة المحادثات"><Icon name="menu" /></button>
            {collapsed && <button type="button" className="as-iconbtn as-only-desktop" onClick={() => setCollapsed(false)} aria-label="إظهار القائمة" title="إظهار القائمة"><Icon name="sidebar" /></button>}
            <div className="as-topbar-title">
              <h2>{tab === 'admin' ? 'الاستهلاك والإعدادات' : current?.title ?? (currentId ? `محادثة ${currentId}` : 'محادثة جديدة')}</h2>
              <span>{tab === 'admin' ? 'إدارة المساعد' : current ? `${(current.total_tokens || 0).toLocaleString('ar-EG')} توكن · ${current.model}` : 'اسأل عن الفروع والصلاحيات، وراجع أي تعديل قبل تنفيذه.'}</span>
            </div>
            {tab === 'chat' && <span className="as-status-chip" data-busy={busy} role="status"><span className="as-status-txt">{busy ? (streaming ? 'بيفكر…' : 'جارٍ التحميل…') : 'جاهز'}</span></span>}
            <div className="as-tabs" role="tablist" aria-label="أقسام المساعد">
              <button type="button" role="tab" className="as-tab" aria-selected={tab === 'chat'} onClick={() => setTab('chat')}><Icon name="chat" size={15} /><span className="as-tab-txt">المحادثة</span></button>
              <button type="button" role="tab" className="as-tab" aria-selected={tab === 'admin'} onClick={() => setTab('admin')}><Icon name="chart" size={15} /><span className="as-tab-txt">الإدارة</span></button>
            </div>
            {tab === 'chat' && <button type="button" className="as-iconbtn as-only-mobile" onClick={() => void createConversation()} disabled={busy} aria-label="محادثة جديدة"><Icon name="plus" /></button>}
          </header>
          {tab === 'chat' ? chat : <AssistantAdministration can={can} />}
        </main>
      </div>

      {oncePassword !== '' && (
        <div className="as-scrim">
          <div role="dialog" aria-modal="true" aria-labelledby="as-pw-title" className="as-modal">
            <span className="as-modal-ic"><Icon name="key" size={22} /></span>
            <div style={{ display: 'grid', gap: '.35rem' }}>
              <h3 id="as-pw-title">تم إنشاء الموظف</h3>
              <p>دي كلمة المرور المؤقتة. <b style={{ color: 'var(--as-text)' }}>هتظهر مرة واحدة بس</b> — انسخها وابعتها للموظف بطريقة آمنة، وهيُطلب منه تغييرها أول دخول.</p>
            </div>
            <div className="as-secret">
              <code>{revealed ? oncePassword : '•'.repeat(Math.min(oncePassword.length, 16))}</code>
              <button type="button" className="as-iconbtn" onClick={() => setRevealed(r => !r)} aria-label={revealed ? 'إخفاء' : 'إظهار'}><Icon name={revealed ? 'eyeOff' : 'eye'} /></button>
              <button
                type="button"
                className={`as-btn as-btn--sm ${copied ? '' : 'as-btn--primary'}`}
                onClick={() => { void navigator.clipboard?.writeText(oncePassword).then(() => setCopied(true)).catch(() => undefined); }}
              >
                <Icon name={copied ? 'check' : 'copy'} size={14} />{copied ? 'اتنسخت' : 'نسخ'}
              </button>
            </div>
            {!copied && (
              <div className="as-alert as-alert--warning"><Icon name="alert" size={16} /><div className="as-alert-body"><span>لو قفلت النافذة من غير ما تنسخ، هتحتاج تعمل إعادة تعيين لكلمة المرور.</span></div></div>
            )}
            <div className="as-modal-actions">
              <button type="button" className={`as-btn ${copied ? 'as-btn--primary' : ''}`} onClick={() => { setOncePassword(''); setRevealed(false); }} autoFocus={copied}>
                {copied ? 'تمام، إغلاق' : 'إغلاق'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

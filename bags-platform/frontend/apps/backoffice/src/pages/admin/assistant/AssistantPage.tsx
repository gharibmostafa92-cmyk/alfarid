import { useCallback, useEffect, useState } from 'react';
import { assistantApi, type Conv, type ConvMsg, type UiBlock } from './api';
import AssistantBlock from './AssistantBlock';
import AssistantComposer from './AssistantComposer';
import assistantStyles from './assistant.css?inline';

interface Mention {
  entity: string;
  id: string | number;
  label: string;
}

// H6: صفحة حقيقية فوق assistantApi — بلا بيانات وهمية، وبلا
// dangerouslySetInnerHTML. نص الموديل يُعرض كنص (pre-wrap) لأن
// أسماء الفروع/الموظفين قد تحمل HTML ضار (stored XSS).
function MessageText({ text }: { text: string | null }) {
  if (!text) return null;

  return (
    <div className="chat-msg-text" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>
      {text}
    </div>
  );
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

export default function AssistantPage({ can }: { can: (permission: string) => boolean }) {
  const [conversations, setConversations] = useState<Conv[]>([]);
  const [currentId, setCurrentId] = useState<number | null>(null);
  const [messages, setMessages] = useState<ConvMsg[]>([]);
  const [actionStatus, setActionStatus] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState('جاهز');
  const [error, setError] = useState('');
  const [searchQuery, setSearchQuery] = useState('');
  const [oncePassword, setOncePassword] = useState('');
  const execute = can('ai_agent.execute');

  const refreshList = useCallback(async () => {
    try {
      const page = await assistantApi.listPaginated(1, searchQuery);
      setConversations(page.data);
    } catch {
      setError('تعذر تحميل المحادثات.');
    }
  }, [searchQuery]);

  useEffect(() => {
    void refreshList();
  }, [refreshList]);

  const openConversation = useCallback(async (id: number) => {
    setBusy(true);
    setError('');
    try {
      const conv = await assistantApi.show(id);
      setCurrentId(conv.id);
      setMessages(conv.messages ?? []);
      const pending: Record<string, string> = {};
      for (const action of conv.pending_actions ?? []) {
        pending[String(action.id)] = action.status;
      }
      setActionStatus(pending);
    } catch {
      setError('تعذر فتح المحادثة.');
    } finally {
      setBusy(false);
    }
  }, []);

  const createConversation = useCallback(async () => {
    setBusy(true);
    setError('');
    try {
      const conv = await assistantApi.create();
      setConversations(prev => [conv, ...prev]);
      setCurrentId(conv.id);
      setMessages([]);
      setActionStatus({});
    } catch {
      setError('تعذر إنشاء محادثة.');
    } finally {
      setBusy(false);
    }
  }, []);

  const deleteConversation = useCallback(
    async (id: number) => {
      if (!window.confirm('حذف هذه المحادثة؟')) return;
      try {
        await assistantApi.remove(id);
        setConversations(prev => prev.filter(c => c.id !== id));
        if (currentId === id) {
          setCurrentId(null);
          setMessages([]);
        }
      } catch {
        setError('تعذر حذف المحادثة.');
      }
    },
    [currentId],
  );

  const send = useCallback(
    async (text: string, _selection?: undefined, mentions?: Mention[]) => {
      let id = currentId;
      if (!id) {
        try {
          const conv = await assistantApi.create();
          id = conv.id;
          setConversations(prev => [conv, ...prev]);
          setCurrentId(id);
        } catch {
          setError('تعذر إنشاء محادثة.');

          return false;
        }
      }
      setBusy(true);
      setStatus('ببعت الطلب…');
      setError('');
      try {
        await assistantApi.stream(
          id,
          { text, mentions: mentions?.map(m => ({ entity: m.entity, id: m.id })) },
          message => setStatus(message),
        );
        await openConversation(id);

        return true;
      } catch (e) {
        setError(apiErrorMessage(e, 'تعذر إتمام طلب المساعد. حاول مرة تانية.'));

        return false;
      } finally {
        setBusy(false);
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
      setError('');
      try {
        if (kind === 'confirm') {
          const res = await assistantApi.confirm(id, typed, selected);
          const pw = (res.result as { generated_password?: unknown } | undefined)?.generated_password;
          if (typeof pw === 'string' && pw !== '') setOncePassword(pw);
        } else if (kind === 'cancel') await assistantApi.cancel(id);
        else await assistantApi.undo(id);
        if (currentId) await openConversation(currentId);
      } catch (e) {
        setError(apiErrorMessage(e, 'تعذر تنفيذ الإجراء.'));
      } finally {
        setBusy(false);
      }
    },
    [currentId, openConversation],
  );

  return (
    <div className="assistant" dir="rtl">
      <style>{assistantStyles}</style>
      <header className="assistant-header">
        <div>
          <h1>مساعد الفريد</h1>
          <p>اسأل عن الفروع والصلاحيات، وراجع أي تعديل قبل تنفيذه.</p>
        </div>
        <div className="assistant-actions">
          <button onClick={() => void createConversation()} disabled={busy}>
            محادثة جديدة +
          </button>
        </div>
      </header>
      <div className="assistant-layout">
        <aside className="assistant-conversations v-subcard" aria-label="المحادثات">
          <input
            aria-label="بحث في المحادثات"
            type="search"
            value={searchQuery}
            onChange={e => setSearchQuery(e.target.value)}
            placeholder="ابحث في المحادثات…"
          />
          {conversations.map(c => (
            <div className="assistant-conversation" key={c.id}>
              <button disabled={busy} onClick={() => void openConversation(c.id)}>
                {c.is_pinned ? '★ ' : ''}
                {c.title ?? `محادثة ${c.id}`}
              </button>
              <button disabled={busy} aria-label="حذف المحادثة" onClick={() => void deleteConversation(c.id)}>
                ×
              </button>
            </div>
          ))}
          {!conversations.length && <p className="assistant-muted">مفيش محادثات بعد.</p>}
        </aside>
        <main className="assistant-chat v-subcard" aria-label="المحادثة الحالية" aria-busy={busy}>
          <div className="assistant-feed" role="log" aria-live="polite">
            {messages.map(m => (
              <div className={`chat-msg chat-msg-${m.role}`} key={m.id}>
                <MessageText text={m.content} />
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
            ))}
            {!messages.length && <p className="assistant-muted">ابدأ محادثة جديدة من الزر أعلاه.</p>}
          </div>
          <p className="assistant-activity" role="status">
            {status}
          </p>
          {error && (
            <p role="alert" className="assistant-warning">
              {error}
            </p>
          )}
          <AssistantComposer busy={busy || currentId === null} onSend={send} quickCommands={[]} />
        </main>
      </div>
      {oncePassword !== '' && (
        <div role="dialog" aria-modal="true" aria-label="كلمة المرور المؤقتة" className="assistant-password-modal">
          <p>تم إنشاء الموظف. كلمة المرور المؤقتة (تظهر مرة واحدة):</p>
          <code>{oncePassword}</code>
          <div>
            <button
              onClick={() => {
                void navigator.clipboard?.writeText(oncePassword).catch(() => undefined);
              }}
            >
              نسخ
            </button>
            <button onClick={() => setOncePassword('')}>إغلاق</button>
          </div>
        </div>
      )}
    </div>
  );
}

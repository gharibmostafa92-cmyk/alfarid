import { useCallback, useEffect, useRef, useState } from 'react';
import assistantStyles from './assistant.css?inline';

/* ─── Mock Types ─── */
interface MockMessage {
  id: number;
  role: 'user' | 'assistant';
  content: string;
  timestamp: string;
  model?: string;
}

interface MockConversation {
  id: number;
  title: string;
  messages: MockMessage[];
  model: string;
  lastActive: string;
  isPinned: boolean;
  totalTokens: number;
}

/* ─── Mock Data ─── */
const MODELS = [
  { group: 'نماذج Gemini المتاحة', items: [
    { value: 'gemini-3.1-flash-lite', label: 'Gemini 3.1 Flash Lite (المساعد)' },
    { value: 'gemini-3.5-flash-lite', label: 'Gemini 3.5 Flash Lite (الفواتير)' },
  ]},
];

const MOCK_RESPONSES: Record<string, string> = {
  'مين معاه صلاحية pos.discount؟': `## صلاحية \`pos.discount\`

الموظفين اللي معاهم الصلاحية دي:

| # | الاسم | الدور | الفرع |
|---|-------|-------|-------|
| 1 | أحمد محمد | مدير فرع | الفرع الرئيسي |
| 2 | سارة علي | مشرف مبيعات | فرع المعادي |
| 3 | محمد حسن | مدير نقطة بيع | فرع مدينة نصر |

> **ملاحظة:** الصلاحية دي بتسمح بإعطاء خصم مباشر على نقطة البيع.`,

  'آخر تغييرات في الصلاحيات النهارده': `## تغييرات الصلاحيات اليوم

تم رصد **3 تغييرات** في الصلاحيات:

### 1. إضافة صلاحيات لـ "سارة علي"
- ✅ \`inventory.adjust\` — تعديل المخزون
- ✅ \`reports.export\` — تصدير التقارير
- 🕐 الوقت: 10:35 ص

### 2. إزالة صلاحيات من "خالد أحمد"
- ❌ \`pos.refund\` — استرجاع نقطة البيع
- 🕐 الوقت: 11:20 ص

### 3. تعديل دور "محاسب"
- ✅ تمت إضافة \`finance.view_reports\`
- ❌ تمت إزالة \`finance.approve_expenses\`
- 🕐 الوقت: 2:15 م`,

  'الفروع المتوقفة': `## الفروع المتوقفة حالياً

| # | الفرع | المدينة | سبب التوقف | تاريخ التوقف |
|---|-------|---------|------------|-------------|
| 1 | فرع الهرم | الجيزة | صيانة دورية | 28 أكتوبر 2026 |
| 2 | فرع المنصورة | الدقهلية | تجديدات | 25 أكتوبر 2026 |

> **تنبيه:** فرع الهرم المتوقع يرجع يشتغل بكرة الأحد.
> فرع المنصورة لسه مفيش تاريخ محدد للرجوع.`,
};

const GENERIC_RESPONSES = [
  `تمام، خليني أراجع البيانات المتاحة…

بناءً على البيانات الحالية:
- إجمالي الطلبات النهارده: **47 طلب**
- إجمالي المبيعات: **23,450 ج.م**
- متوسط قيمة الطلب: **499 ج.م**

هل تحب أعرض لك تفاصيل أكتر؟`,

  `أهلاً! أنا مساعد الفريد الذكي 🤖

أقدر أساعدك في:
- 📊 مراجعة الصلاحيات والأدوار
- 🏪 إدارة الفروع
- 📋 متابعة التغييرات والسجلات
- 💰 تقارير المبيعات والمخزون

اكتب سؤالك وأنا هساعدك!`,

  `تم تحليل طلبك بنجاح ✅

### ملخص العملية:
- تم مراجعة **12 سجل** من قاعدة البيانات
- تم التحقق من **3 شروط** أمان
- النتيجة: العملية آمنة ومتوافقة مع الصلاحيات

لو عايز أنفذ أي تعديل، أكد الأمر وأنا هجهز لك خطة التنفيذ.`,
];

const INITIAL_CONVERSATIONS: MockConversation[] = [
  {
    id: 1,
    title: 'مراجعة صلاحيات نقطة البيع',
    model: 'claude-opus-4.8',
    lastActive: '2026-10-30T18:30:00',
    isPinned: true,
    totalTokens: 2340,
    messages: [
      { id: 1, role: 'user', content: 'مين معاه صلاحية pos.discount؟', timestamp: '2026-10-30T18:25:00' },
      { id: 2, role: 'assistant', content: MOCK_RESPONSES['مين معاه صلاحية pos.discount؟']!, timestamp: '2026-10-30T18:25:05', model: 'claude-opus-4.8' },
    ],
  },
  {
    id: 2,
    title: 'تغييرات الصلاحيات',
    model: 'claude-sonnet-5',
    lastActive: '2026-10-30T16:00:00',
    isPinned: false,
    totalTokens: 4120,
    messages: [
      { id: 3, role: 'user', content: 'آخر تغييرات في الصلاحيات النهارده', timestamp: '2026-10-30T15:50:00' },
      { id: 4, role: 'assistant', content: MOCK_RESPONSES['آخر تغييرات في الصلاحيات النهارده']!, timestamp: '2026-10-30T15:50:08', model: 'claude-sonnet-5' },
    ],
  },
  {
    id: 3,
    title: 'حالة الفروع',
    model: 'gemini-3.7-flash',
    lastActive: '2026-10-30T12:00:00',
    isPinned: false,
    totalTokens: 1560,
    messages: [
      { id: 5, role: 'user', content: 'الفروع المتوقفة', timestamp: '2026-10-30T11:55:00' },
      { id: 6, role: 'assistant', content: MOCK_RESPONSES['الفروع المتوقفة']!, timestamp: '2026-10-30T11:55:04', model: 'gemini-3.7-flash' },
    ],
  },
];

/* ─── Simple Markdown Renderer ─── */
function renderMarkdown(md: string): string {
  let html = md
    // Code blocks
    .replace(/```(\w*)\n([\s\S]*?)```/g, (_m, lang, code) =>
      `<pre><code class="lang-${lang}">${code.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</code></pre>`)
    // Inline code
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    // Headers
    .replace(/^### (.+)$/gm, '<h4>$1</h4>')
    .replace(/^## (.+)$/gm, '<h3>$1</h3>')
    .replace(/^# (.+)$/gm, '<h2>$1</h2>')
    // Bold
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    // Italic
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    // Blockquotes
    .replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>')
    // Horizontal rule
    .replace(/^---$/gm, '<hr />')
    // Unordered lists
    .replace(/^- (.+)$/gm, '<li>$1</li>')
    // Line breaks
    .replace(/\n\n/g, '</p><p>')
    .replace(/\n/g, '<br />');

  // Wrap in paragraph
  html = `<p>${html}</p>`;

  // Simple table rendering
  html = html.replace(/<p>\|(.+)\|<br \/>\|[-| :]+\|([\s\S]*?)<\/p>/g, (_m, header, body) => {
    const headers = header.split('|').map((h: string) => h.trim()).filter(Boolean);
    const rows = body.split('<br />').filter((r: string) => r.trim().startsWith('|'));
    let table = '<div class="chat-table-wrap"><table><thead><tr>';
    headers.forEach((h: string) => { table += `<th>${h}</th>`; });
    table += '</tr></thead><tbody>';
    rows.forEach((row: string) => {
      const cells = row.split('|').map((c: string) => c.trim()).filter(Boolean);
      if (cells.length) {
        table += '<tr>';
        cells.forEach((c: string) => { table += `<td>${c}</td>`; });
        table += '</tr>';
      }
    });
    table += '</tbody></table></div>';
    return table;
  });

  return html;
}

/* ─── Main Component ─── */
export default function AssistantPage({ can: _can }: { can: (permission: string) => boolean }) {
  const [conversations, setConversations] = useState<MockConversation[]>(INITIAL_CONVERSATIONS);
  const [current, setCurrent] = useState<MockConversation | null>(null);
  const [messages, setMessages] = useState<MockMessage[]>([]);
  const [inputText, setInputText] = useState('');
  const [model, setModel] = useState('claude-opus-4.8');
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState('جاهز');
  const [searchQuery, setSearchQuery] = useState('');
  const [msgIdCounter, setMsgIdCounter] = useState(100);
  const [convIdCounter, setConvIdCounter] = useState(100);
  const feedRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    feedRef.current?.scrollTo({ top: feedRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, busy]);

  const selectConversation = useCallback((conv: MockConversation) => {
    setCurrent(conv);
    setMessages(conv.messages);
    setModel(conv.model);
  }, []);

  const createConversation = useCallback(() => {
    const newConv: MockConversation = {
      id: convIdCounter,
      title: 'محادثة جديدة',
      model,
      lastActive: new Date().toISOString(),
      isPinned: false,
      totalTokens: 0,
      messages: [],
    };
    setConvIdCounter(c => c + 1);
    setConversations(prev => [newConv, ...prev]);
    setCurrent(newConv);
    setMessages([]);
    inputRef.current?.focus();
  }, [model, convIdCounter]);

  const deleteConversation = useCallback((id: number) => {
    setConversations(prev => prev.filter(c => c.id !== id));
    if (current?.id === id) {
      setCurrent(null);
      setMessages([]);
    }
  }, [current]);

  const togglePin = useCallback((id: number) => {
    setConversations(prev =>
      prev.map(c => c.id === id ? { ...c, isPinned: !c.isPinned } : c)
    );
  }, []);

  const clearChat = useCallback(() => {
    if (window.confirm('هل تريد مسح المحادثة الحالية؟')) {
      if (current) {
        setMessages([]);
        setConversations(prev =>
          prev.map(c => c.id === current.id ? { ...c, messages: [], totalTokens: 0 } : c)
        );
      }
    }
  }, [current]);

  const simulateTyping = useCallback(async (text: string, bubble: MockMessage) => {
    const words = text.split(' ');
    let accumulated = '';
    for (let i = 0; i < words.length; i++) {
      accumulated += (i > 0 ? ' ' : '') + words[i];
      const snapshot = accumulated;
      setMessages(prev =>
        prev.map(m => m.id === bubble.id ? { ...m, content: snapshot } : m)
      );
      await new Promise(resolve => setTimeout(resolve, 15 + Math.random() * 25));
    }
  }, []);

  const sendMessage = useCallback(async (text: string) => {
    if (busy || !text.trim()) return;
    setBusy(true);

    const userMsg: MockMessage = {
      id: msgIdCounter,
      role: 'user',
      content: text.trim(),
      timestamp: new Date().toISOString(),
    };

    const placeholderMsg: MockMessage = {
      id: msgIdCounter + 1,
      role: 'assistant',
      content: '',
      timestamp: new Date().toISOString(),
      model,
    };

    setMsgIdCounter(c => c + 2);

    let conv = current;
    if (!conv) {
      conv = {
        id: convIdCounter,
        title: text.trim().slice(0, 40) + (text.trim().length > 40 ? '…' : ''),
        model,
        lastActive: new Date().toISOString(),
        isPinned: false,
        totalTokens: 0,
        messages: [],
      };
      setConvIdCounter(c => c + 1);
      setCurrent(conv);
      setConversations(prev => [conv!, ...prev]);
    }

    const newMessages = [...(conv.id === current?.id ? messages : conv.messages), userMsg, placeholderMsg];
    setMessages(newMessages);
    setInputText('');

    const modelLabel = MODELS.flatMap(g => g.items).find(m => m.value === model)?.label ?? model;
    setStatus(`جاري استدعاء ${modelLabel}...`);

    // Simulate response delay
    await new Promise(resolve => setTimeout(resolve, 800 + Math.random() * 1200));

    const response = MOCK_RESPONSES[text.trim()]
      ?? GENERIC_RESPONSES[Math.floor(Math.random() * GENERIC_RESPONSES.length)];

    await simulateTyping(response!, placeholderMsg);

    // Update conversation
    const finalMessages = newMessages.map(m =>
      m.id === placeholderMsg.id ? { ...m, content: response! } : m
    );
    setMessages(finalMessages);

    const newTokens = Math.floor(Math.random() * 800) + 200;
    setConversations(prev =>
      prev.map(c => c.id === conv!.id
        ? {
            ...c,
            messages: finalMessages,
            totalTokens: c.totalTokens + newTokens,
            lastActive: new Date().toISOString(),
            title: c.title === 'محادثة جديدة' ? text.trim().slice(0, 40) + (text.trim().length > 40 ? '…' : '') : c.title,
          }
        : c
      )
    );

    setStatus(`تم الاستلام بنجاح (${modelLabel})`);
    setBusy(false);
    inputRef.current?.focus();
  }, [busy, current, messages, model, msgIdCounter, convIdCounter, simulateTyping]);

  const suggestions = [
    'مين معاه صلاحية pos.discount؟',
    'آخر تغييرات في الصلاحيات النهارده',
    'الفروع المتوقفة',
  ];

  const filteredConversations = conversations
    .filter(c => !searchQuery || c.title.includes(searchQuery))
    .sort((a, b) => {
      if (a.isPinned !== b.isPinned) return a.isPinned ? -1 : 1;
      return new Date(b.lastActive).getTime() - new Date(a.lastActive).getTime();
    });

  return (
    <div className="assistant" dir="rtl">
      <style>{assistantStyles}</style>
      <style>{`
        /* ─── Enhanced Chat Styling ─── */
        .chat-header-bar {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 1rem;
          padding: .75rem 1rem;
          border-bottom: 1px solid var(--border-color, #d8d8d8);
          background: var(--bg-secondary, #88888808);
          border-radius: .75rem .75rem 0 0;
        }
        .chat-header-info {
          display: flex;
          align-items: center;
          gap: .75rem;
        }
        .chat-avatar-lg {
          width: 2.5rem;
          height: 2.5rem;
          border-radius: .75rem;
          background: linear-gradient(135deg, #b73513, #e0491f);
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 1.2rem;
          color: white;
          flex-shrink: 0;
        }
        .chat-header-info h2 {
          margin: 0;
          font-size: 1.1rem;
          display: flex;
          align-items: center;
          gap: .5rem;
        }
        .chat-status-badge {
          font-size: .7rem;
          padding: .15rem .5rem;
          border-radius: 999px;
          background: #16a34a15;
          color: #16a34a;
          border: 1px solid #16a34a30;
          font-weight: 500;
        }
        .chat-header-info small {
          font-size: .8rem;
          color: var(--text-secondary, #626262);
        }
        .chat-controls {
          display: flex;
          align-items: center;
          gap: .5rem;
        }
        .chat-model-select {
          font: inherit;
          background: var(--bg-input, transparent);
          border: 1px solid var(--border-color, #d8d8d8);
          border-radius: .5rem;
          padding: .4rem .6rem;
          color: inherit;
          font-size: .85rem;
          cursor: pointer;
          max-width: 14rem;
        }
        .chat-clear-btn {
          display: flex;
          align-items: center;
          gap: .3rem;
          font-size: .8rem;
          padding: .4rem .6rem;
          border: 1px solid var(--border-color, #d8d8d8);
          border-radius: .5rem;
          background: transparent;
          color: var(--text-secondary, #626262);
          cursor: pointer;
          transition: all .15s;
        }
        .chat-clear-btn:hover {
          background: #dc262615;
          color: #dc2626;
          border-color: #dc262640;
        }

        /* Message Bubbles */
        .chat-msg {
          display: flex;
          align-items: flex-start;
          gap: .75rem;
          margin-block: .75rem;
          animation: chatFadeIn .3s ease;
        }
        .chat-msg-user {
          flex-direction: row-reverse;
        }
        .chat-msg-avatar {
          width: 2rem;
          height: 2rem;
          border-radius: .5rem;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: .85rem;
          font-weight: 700;
          flex-shrink: 0;
        }
        .chat-msg-user .chat-msg-avatar {
          background: var(--bg-secondary, #88888820);
          color: var(--text-primary, #242424);
        }
        .chat-msg-assistant .chat-msg-avatar {
          background: linear-gradient(135deg, #b73513, #e0491f);
          color: white;
        }
        .chat-msg-bubble {
          max-width: 85%;
          padding: .75rem 1rem;
          border-radius: 1rem;
          font-size: .9rem;
          line-height: 1.7;
          overflow-wrap: anywhere;
        }
        .chat-msg-user .chat-msg-bubble {
          background: #b73513;
          color: white;
          border-top-left-radius: 1rem;
          border-top-right-radius: .25rem;
        }
        .chat-msg-assistant .chat-msg-bubble {
          background: var(--bg-secondary, #88888810);
          border: 1px solid var(--border-color, #d8d8d8);
          border-top-right-radius: 1rem;
          border-top-left-radius: .25rem;
        }
        .chat-msg-bubble p { margin: .25rem 0; }
        .chat-msg-bubble h2, .chat-msg-bubble h3, .chat-msg-bubble h4 {
          margin-top: .75rem;
          margin-bottom: .35rem;
        }
        .chat-msg-bubble h2 { font-size: 1.1rem; }
        .chat-msg-bubble h3 { font-size: 1rem; }
        .chat-msg-bubble h4 { font-size: .95rem; }
        .chat-msg-bubble code {
          direction: ltr;
          unicode-bidi: isolate;
          background: var(--bg-secondary, #88888820);
          padding: .1rem .35rem;
          border-radius: .25rem;
          font-size: .8rem;
          font-family: 'Fira Code', 'Consolas', monospace;
        }
        .chat-msg-bubble pre {
          direction: ltr;
          text-align: left;
          background: var(--bg-secondary, #88888815);
          border: 1px solid var(--border-color, #d8d8d8);
          border-radius: .5rem;
          padding: .75rem;
          overflow-x: auto;
          margin: .5rem 0;
        }
        .chat-msg-bubble pre code {
          background: transparent;
          padding: 0;
        }
        .chat-msg-bubble blockquote {
          border-inline-start: 3px solid #e0491f;
          padding-inline-start: .75rem;
          margin: .5rem 0;
          color: var(--text-secondary, #626262);
        }
        .chat-msg-bubble ul, .chat-msg-bubble ol {
          padding-inline-start: 1.5rem;
          list-style: revert;
        }
        .chat-msg-bubble li { margin-block: .25rem; }
        .chat-msg-bubble strong { font-weight: 700; }
        .chat-msg-bubble hr {
          border: none;
          border-top: 1px solid var(--border-color, #d8d8d8);
          margin: .75rem 0;
        }
        .chat-table-wrap {
          overflow-x: auto;
          margin: .5rem 0;
        }
        .chat-msg-bubble table {
          border-collapse: collapse;
          width: 100%;
          font-size: .85rem;
        }
        .chat-msg-bubble th, .chat-msg-bubble td {
          padding: .45rem .65rem;
          border: 1px solid var(--border-color, #d8d8d8);
          text-align: right;
        }
        .chat-msg-bubble th {
          background: var(--bg-secondary, #88888810);
          font-weight: 600;
        }
        .chat-msg-model {
          font-size: .7rem;
          color: var(--text-secondary, #626262);
          margin-top: .25rem;
          padding-inline-start: .25rem;
        }

        /* Typing indicator */
        .chat-typing {
          display: flex;
          gap: .35rem;
          padding: .5rem 0;
          padding-inline-start: .25rem;
        }
        .chat-typing-dot {
          width: .45rem;
          height: .45rem;
          border-radius: 50%;
          background: #b73513;
          animation: typingBounce .6s infinite alternate;
        }
        .chat-typing-dot:nth-child(2) { animation-delay: .15s; }
        .chat-typing-dot:nth-child(3) { animation-delay: .3s; }

        /* Input */
        .chat-input-area {
          border-top: 1px solid var(--border-color, #d8d8d8);
          padding: .75rem;
        }
        .chat-input-row {
          display: flex;
          align-items: flex-end;
          gap: .5rem;
          background: var(--bg-input, transparent);
          border: 1px solid var(--border-color, #d8d8d8);
          border-radius: 1rem;
          padding: .4rem .4rem .4rem .75rem;
          transition: border-color .15s;
        }
        .chat-input-row:focus-within {
          border-color: #e0491f;
        }
        .chat-input-row textarea {
          flex: 1;
          border: none;
          background: transparent;
          color: inherit;
          font: inherit;
          font-size: .9rem;
          resize: none;
          max-height: 8rem;
          min-height: 2.5rem;
          padding: .4rem 0;
          outline: none;
          line-height: 1.5;
        }
        .chat-send-btn {
          background: #b73513;
          color: white;
          border: none;
          border-radius: .75rem;
          width: 2.5rem;
          height: 2.5rem;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          flex-shrink: 0;
          transition: all .15s;
          font-size: 1.1rem;
        }
        .chat-send-btn:hover:not(:disabled) {
          background: #982a0e;
          transform: scale(1.05);
        }
        .chat-send-btn:disabled {
          opacity: .4;
          cursor: default;
        }
        .chat-send-btn:active:not(:disabled) {
          transform: scale(.95);
        }
        .chat-footer {
          display: flex;
          justify-content: space-between;
          align-items: center;
          font-size: .75rem;
          color: var(--text-secondary, #626262);
          padding: .25rem .75rem;
        }

        /* Welcome */
        .chat-welcome {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          padding: 3rem 1rem;
          text-align: center;
          gap: 1rem;
        }
        .chat-welcome-icon {
          width: 4rem;
          height: 4rem;
          border-radius: 1rem;
          background: linear-gradient(135deg, #b73513, #e0491f);
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 2rem;
          color: white;
        }
        .chat-welcome h2 {
          font-size: 1.4rem;
          margin: 0;
        }
        .chat-welcome p {
          color: var(--text-secondary, #626262);
          max-width: 24rem;
          margin: 0;
        }
        .chat-model-badges {
          display: flex;
          flex-wrap: wrap;
          justify-content: center;
          gap: .4rem;
          margin-top: .25rem;
        }
        .chat-model-badge {
          font-size: .75rem;
          padding: .2rem .6rem;
          border-radius: .35rem;
          background: var(--bg-secondary, #88888815);
          border: 1px solid var(--border-color, #d8d8d8);
        }
        .chat-suggestions-row {
          display: flex;
          flex-wrap: wrap;
          justify-content: center;
          gap: .5rem;
          margin-top: .5rem;
        }
        .chat-suggestion-btn {
          font: inherit;
          font-size: .85rem;
          padding: .5rem 1rem;
          border: 1px solid var(--border-color, #d8d8d8);
          border-radius: .75rem;
          background: transparent;
          color: inherit;
          cursor: pointer;
          transition: all .15s;
        }
        .chat-suggestion-btn:hover:not(:disabled) {
          background: #e0491f15;
          border-color: #e0491f;
          color: #b73513;
        }

        /* Conversations sidebar */
        .conv-item-actions {
          display: flex;
          gap: .15rem;
          flex-shrink: 0;
        }
        .conv-item-actions button {
          padding: .25rem .4rem;
          font-size: .8rem;
          border-radius: .35rem;
          min-width: 0;
        }

        /* Animations */
        @keyframes chatFadeIn {
          from { opacity: 0; transform: translateY(.5rem); }
          to { opacity: 1; transform: translateY(0); }
        }
        @keyframes typingBounce {
          from { transform: translateY(0); opacity: .4; }
          to { transform: translateY(-.35rem); opacity: 1; }
        }

        @media (max-width: 767px) {
          .chat-header-bar { flex-direction: column; align-items: stretch; gap: .5rem; }
          .chat-controls { justify-content: flex-end; }
          .chat-model-select { max-width: 100%; }
        }
      `}</style>

      {/* Header */}
      <header className="assistant-header">
        <div>
          <h1>مساعد الفريد</h1>
          <p>اسأل عن الفروع والصلاحيات، وراجع أي تعديل قبل تنفيذه.</p>
        </div>
        <div className="assistant-actions">
          <button onClick={createConversation} disabled={busy}>محادثة جديدة +</button>
        </div>
      </header>

      <div className="assistant-layout">
        {/* Conversations Sidebar */}
        <aside className="assistant-conversations v-subcard" aria-label="المحادثات">
          <input
            aria-label="بحث في المحادثات"
            type="search"
            value={searchQuery}
            onChange={e => setSearchQuery(e.target.value)}
            placeholder="ابحث في المحادثات…"
          />
          {filteredConversations.map(c => (
            <div className="assistant-conversation" key={c.id}>
              <button
                disabled={busy}
                aria-current={current?.id === c.id ? 'page' : undefined}
                onClick={() => selectConversation(c)}
              >
                {c.isPinned ? '★ ' : ''}{c.title}
                <small>{c.totalTokens.toLocaleString('ar-EG')} توكن</small>
              </button>
              <div className="conv-item-actions">
                <button
                  disabled={busy}
                  aria-label={c.isPinned ? 'إلغاء تثبيت' : 'تثبيت'}
                  onClick={() => togglePin(c.id)}
                >
                  {c.isPinned ? '★' : '☆'}
                </button>
                <button
                  disabled={busy}
                  aria-label={`حذف ${c.title}`}
                  onClick={() => deleteConversation(c.id)}
                >
                  ×
                </button>
              </div>
            </div>
          ))}
          {!filteredConversations.length && (
            <p className="assistant-muted">مفيش محادثات مطابقة.</p>
          )}
        </aside>

        {/* Chat Area */}
        <main className="assistant-chat v-subcard" aria-label="المحادثة الحالية" aria-busy={busy}>
          {/* Chat Header Bar */}
          <div className="chat-header-bar">
            <div className="chat-header-info">
              <div className="chat-avatar-lg">⚡</div>
              <div>
                <h2>
                  <span>Radox AI Studio</span>
                  <span className="chat-status-badge">متصل</span>
                </h2>
                <small>{MODELS.flatMap(g => g.items).find(m => m.value === model)?.label ?? model}</small>
              </div>
            </div>
            <div className="chat-controls">
              <select
                className="chat-model-select"
                value={model}
                onChange={e => setModel(e.target.value)}
                disabled={busy}
              >
                {MODELS.map(group => (
                  <optgroup key={group.group} label={group.group}>
                    {group.items.map(m => (
                      <option key={m.value} value={m.value}>{m.label}</option>
                    ))}
                  </optgroup>
                ))}
              </select>
              <button
                className="chat-clear-btn"
                onClick={clearChat}
                disabled={busy || !current}
                title="مسح المحادثة"
              >
                🗑️ <span>مسح</span>
              </button>
            </div>
          </div>

          {/* Messages Feed */}
          <div className="assistant-feed" ref={feedRef} role="log" aria-live="polite">
            {!messages.length && (
              <div className="chat-welcome">
                <div className="chat-welcome-icon">⚡</div>
                <h2>مرحباً بك! مساعد الفريد جاهز للعمل 🚀</h2>
                <p>
                  يمكنك كتابة أي سؤال أو طلب، وتجربة أحدث النماذج:
                </p>
                <div className="chat-model-badges">
                  <span className="chat-model-badge">Claude Opus 4.8</span>
                  <span className="chat-model-badge">Claude Sonnet 5</span>
                  <span className="chat-model-badge">Gemini 3.7 Flash</span>
                </div>
                <div className="chat-suggestions-row">
                  {suggestions.map(s => (
                    <button
                      key={s}
                      className="chat-suggestion-btn"
                      disabled={busy}
                      onClick={() => sendMessage(s)}
                    >
                      {s}
                    </button>
                  ))}
                </div>
              </div>
            )}

            {messages.map(m => (
              <div
                className={`chat-msg chat-msg-${m.role}`}
                key={m.id}
              >
                <div className="chat-msg-avatar">
                  {m.role === 'user' ? '👤' : '⚡'}
                </div>
                <div>
                  <div
                    className="chat-msg-bubble"
                    {...(m.role === 'assistant'
                      ? { dangerouslySetInnerHTML: { __html: renderMarkdown(m.content || '') } }
                      : { children: m.content }
                    )}
                  />
                  {m.role === 'assistant' && m.model && (
                    <div className="chat-msg-model">
                      {MODELS.flatMap(g => g.items).find(i => i.value === m.model)?.label ?? m.model}
                    </div>
                  )}
                </div>
              </div>
            ))}

            {busy && (
              <div className="chat-msg chat-msg-assistant">
                <div className="chat-msg-avatar">⚡</div>
                <div className="chat-typing">
                  <div className="chat-typing-dot" />
                  <div className="chat-typing-dot" />
                  <div className="chat-typing-dot" />
                </div>
              </div>
            )}
          </div>

          {/* Status */}
          <p className="assistant-activity" role="status">{status}</p>

          {/* Input Area */}
          <div className="chat-input-area">
            <form
              onSubmit={e => { e.preventDefault(); void sendMessage(inputText); }}
              className="chat-input-row"
            >
              <textarea
                ref={inputRef}
                aria-label="رسالة المساعد"
                value={inputText}
                onChange={e => {
                  setInputText(e.target.value);
                  e.target.style.height = 'auto';
                  e.target.style.height = e.target.scrollHeight + 'px';
                }}
                onKeyDown={e => {
                  if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
                    e.preventDefault();
                    void sendMessage(inputText);
                  }
                }}
                placeholder="اكتب رسالتك هنا... (Enter للإرسال، Shift+Enter لسطر جديد)"
                rows={1}
                disabled={busy}
                maxLength={8000}
              />
              <button
                type="submit"
                className="chat-send-btn"
                disabled={busy || !inputText.trim()}
              >
                ➤
              </button>
            </form>
            <div className="chat-footer">
              <span>مدعوم بواسطة مساعد الفريد • متوافق مع OpenAI API</span>
              <span>{status}</span>
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}

// Preview-only mock for '@alfarid/api-client' — in-memory backend that mimics
// the real endpoints (incl. SSE streaming via onDownloadProgress).
(function () {
  const BASE = '/api/v1/admin/ai';
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const iso = (offsetMs) => new Date(Date.now() + offsetMs).toISOString();
  const H = 3600e3, D = 24 * H;

  const people = [
    { id: 11, label: 'أحمد سمير', meta: 'مدير فرع · المعادي' },
    { id: 12, label: 'أحمد فتحي', meta: 'أمين مخزن · مدينة نصر' },
    { id: 13, label: 'سارة محمود', meta: 'محاسبة · المقر الرئيسي' },
    { id: 14, label: 'محمد عادل', meta: 'كاشير · الإسكندرية' },
    { id: 15, label: 'منى إبراهيم', meta: 'موارد بشرية · المقر الرئيسي' },
    { id: 16, label: 'يوسف خالد', meta: 'كاشير · المعادي' },
  ];
  const branches = [
    { id: 1, label: 'فرع المعادي', meta: 'BR-001 · القاهرة' },
    { id: 2, label: 'فرع مدينة نصر', meta: 'BR-002 · القاهرة' },
    { id: 3, label: 'فرع الإسكندرية', meta: 'BR-003 · الإسكندرية' },
    { id: 4, label: 'فرع المنصورة', meta: 'BR-004 · الدقهلية' },
    { id: 5, label: 'المقر الرئيسي', meta: 'HQ · القاهرة' },
  ];
  const roles = [
    { id: 'branch_manager', label: 'مدير فرع', meta: '24 صلاحية' },
    { id: 'storekeeper', label: 'أمين مخزن', meta: '11 صلاحية' },
    { id: 'cashier', label: 'كاشير', meta: '6 صلاحيات' },
    { id: 'hr', label: 'موارد بشرية', meta: '14 صلاحية' },
  ];

  function demoConversation() {
    let mid = 1;
    const M = (role, content, ui_blocks = null, ago = 0) => ({ id: mid++, role, content, ui_blocks, created_at: iso(-ago) });
    return [
      M('user', 'عايز ملخص سريع لحالة الفروع النهارده', null, 50 * 60e3),
      M('assistant', 'ده ملخص الفروع النهارده:\n\n- **٤ فروع** شغالة طبيعي\n- **فرع المنصورة** متوقف من امبارح بسبب صيانة\n- فيه **٣ موظفين** من غير دور محدد\n\nاستخدمت الاستعلام ده عشان أجيب البيانات:\n```sql\nSELECT code, name, is_active FROM branches WHERE deleted_at IS NULL;\n```', [
        { type: 'table', columns: ['الكود', 'الفرع', 'المدينة', 'الموظفين', 'الحالة'], rows: [['BR-001', 'فرع المعادي', 'القاهرة', 18, 'نشط'], ['BR-002', 'فرع مدينة نصر', 'القاهرة', 22, 'نشط'], ['BR-003', 'فرع الإسكندرية', 'الإسكندرية', 15, 'نشط'], ['BR-004', 'فرع المنصورة', 'الدقهلية', 9, 'متوقف']] },
      ], 49 * 60e3),
      M('user', 'شغّل فرع المنصورة تاني وغيّر رقم تليفونه', null, 40 * 60e3),
      M('assistant', 'جهّزت التعديل ده. راجع الفرق قبل التأكيد — الاقتراح ده انتهت صلاحيته لأنه فضل مفتوح أكتر من المهلة.', [
        { type: 'confirm_action', action_id: 'act-expired', title: 'تعديل بيانات فرع المنصورة', expires_at: iso(-5 * 60e3), before: { is_active: false, phone: '050-2231100' }, after: { is_active: true, phone: '050-2231199' } },
      ], 39 * 60e3),
      M('user', 'ابعتهولي تاني', null, 8 * 60e3),
      M('assistant', 'تمام، ده اقتراح جديد بنفس التعديل:', [
        { type: 'confirm_action', action_id: 'act-branch', title: 'تعديل بيانات فرع المنصورة', expires_at: iso(4 * 60e3 + 30e3), before: { name: 'فرع المنصورة', is_active: false, phone: '050-2231100', city: 'المنصورة' }, after: { name: 'فرع المنصورة', is_active: true, phone: '050-2231199', city: 'المنصورة' } },
      ], 7 * 60e3),
      M('user', 'أضف موظفة جديدة اسمها هدى رمضان كاشير في فرع المعادي', null, 6 * 60e3),
      M('assistant', 'هنشئ الحساب ده. بعد التأكيد هتظهر لك كلمة مرور مؤقتة مرة واحدة بس.', [
        { type: 'confirm_action', action_id: 'act-create', title: 'إنشاء موظف جديد', expires_at: iso(14 * 60e3), after: { name: 'هدى رمضان', email: 'hoda.r@alfarid.eg', branch_id: 'فرع المعادي', roles: ['كاشير'], is_active: true } },
      ], 6 * 60e3 - 5e3),
      M('user', 'اديني صلاحيات إدارة الأدوار لدور مدير فرع', null, 5 * 60e3),
      M('assistant', 'ده تعديل **حساس** هيأثر على كل مديري الفروع. راجع الإضافات والإزالات واكتب كلمة التأكيد.', [
        { type: 'permission_diff', action_id: 'act-perm', title: 'تعديل صلاحيات دور «مدير فرع»', expires_at: iso(52e3), add: ['roles.manage', 'reports.export'], remove: ['inventory.adjust'], affected_users: 5, typed_confirmation: 'CONFIRM', warnings: ['صلاحية «إدارة الأدوار» بتسمح للمستخدم يمنح نفسه أي صلاحية تانية.'] },
      ], 5 * 60e3 - 4e3),
      M('user', 'وقّف كل الكاشيرية اللي ملهمش دخول من ٣٠ يوم', null, 4 * 60e3),
      M('assistant', 'لقيت ٤ حسابات مطابقة. تقدر تستبعد أي حساب قبل التنفيذ:', [
        { type: 'bulk_plan', action_id: 'act-bulk', title: 'إيقاف ٤ حسابات كاشير غير نشطة', expires_at: iso(9 * 60e3), items: [
          { key: 'u14', label: 'محمد عادل', change: 'آخر دخول من ٤١ يوم · الإسكندرية', before: { is_active: true, roles: ['كاشير'] }, after: { is_active: false, roles: ['كاشير'] } },
          { key: 'u16', label: 'يوسف خالد', change: 'آخر دخول من ٣٣ يوم · المعادي', before: { is_active: true }, after: { is_active: false } },
          { key: 'u21', label: 'كريم ناصر', change: 'آخر دخول من ٦٠ يوم · مدينة نصر', before: { is_active: true }, after: { is_active: false } },
          { key: 'u22', label: 'ريهام سعيد', change: 'لم يسجل دخول مطلقًا · المنصورة', before: { is_active: true }, after: { is_active: false } },
        ] },
      ], 4 * 60e3 - 3e3),
      M('user', 'غيّر دور أحمد لأمين مخزن', null, 3 * 60e3),
      M('assistant', 'فيه أكتر من موظف اسمه أحمد. اختار المقصود:', [
        { type: 'entity_picker', entity: 'user', purpose: 'مين «أحمد» اللي تقصده؟', options: [
          { id: 11, label: 'أحمد سمير', meta: 'مدير فرع · المعادي' },
          { id: 12, label: 'أحمد فتحي', meta: 'أمين مخزن · مدينة نصر', disabled: 'عنده الدور ده بالفعل' },
          { id: 17, label: 'أحمد الشربيني', meta: 'كاشير · الإسكندرية' },
          { id: 18, label: 'أحمد منصور', meta: 'محاسب · المقر الرئيسي' },
          { id: 19, label: 'أحمد ياسر', meta: 'كاشير · المنصورة' },
          { id: 20, label: 'أحمد عبد الله', meta: 'موارد بشرية · المقر الرئيسي' },
        ] },
      ], 3 * 60e3 - 2e3),
      M('user', 'اخترت: أحمد سمير', null, 2 * 60e3),
      M('assistant', 'تم تغيير دور **أحمد سمير** لأمين مخزن. تقدر تتراجع خلال الدقايق الجاية لو ده مش المقصود.', [
        { type: 'result', ok: true, message: 'اتغيّر الدور من «مدير فرع» إلى «أمين مخزن».', deep_link: '/users?id=11', undo_action_id: 'act-role', undo_available_until: iso(2 * 60e3 + 40e3) },
      ], 2 * 60e3 - 3e3),
    ];
  }

  function freshState(scenario) {
    const s = { nextConv: 300, nextMsg: 1000, convs: [], statuses: {}, scenario };
    if (scenario === 'empty' || scenario === 'listError') return s;
    s.convs = [
      { id: 201, user_id: 1, is_pinned: true, title: 'مراجعة صلاحيات مديري الفروع', model: 'alfarid-pro', total_tokens: 18420, last_message_at: iso(-2 * 60e3), messages: demoConversation() },
      { id: 202, user_id: 1, is_pinned: true, title: 'تقرير الموظفين بدون دور', model: 'alfarid-pro', total_tokens: 4210, last_message_at: iso(-3 * D), messages: [] },
      { id: 203, user_id: 1, is_pinned: false, title: 'نقل ٣ موظفين لفرع الإسكندرية', model: 'alfarid-pro', total_tokens: 6120, last_message_at: iso(-3 * H), messages: [] },
      { id: 204, user_id: 1, is_pinned: false, title: 'إنشاء فرع المنصورة الجديد', model: 'alfarid-pro', total_tokens: 2280, last_message_at: iso(-26 * H), messages: [] },
      { id: 205, user_id: 1, is_pinned: false, title: 'مين عنده صلاحية تصدير التقارير؟', model: 'alfarid-pro', total_tokens: 1310, last_message_at: iso(-4 * D), messages: [] },
      { id: 206, user_id: 1, is_pinned: false, title: 'تحديث أرقام تليفونات الفروع', model: 'alfarid-pro', total_tokens: 980, last_message_at: iso(-12 * D), messages: [] },
    ];
    for (const c of s.convs.slice(1)) {
      c.messages = [
        { id: s.nextMsg++, role: 'user', content: c.title, ui_blocks: null, created_at: c.last_message_at },
        { id: s.nextMsg++, role: 'assistant', content: 'تمام، ده ملخص اللي لقيته:\n\n1. البيانات اتجمعت من آخر تحديث\n2. مفيش تعارضات\n3. تقدر تطلب أي تعديل وأنا أجهّزه لك للمراجعة', ui_blocks: null, created_at: c.last_message_at },
      ];
    }
    s.statuses = { 'act-expired': 'pending', 'act-branch': 'pending', 'act-create': 'pending', 'act-perm': 'pending', 'act-bulk': 'pending', 'act-role': 'executed' };
    return s;
  }

  let S = freshState('full');
  const summary = c => ({ ...c, messages: undefined, pending_actions: pendingFor(c) });
  function pendingFor(c) {
    const ids = [];
    for (const m of c.messages) for (const b of m.ui_blocks ?? []) { if (b.action_id) ids.push(b.action_id); if (b.undo_action_id) ids.push(b.undo_action_id); }
    return ids.filter(id => S.statuses[id]).map(id => ({ id, status: S.statuses[id], expires_at: iso(60e3) }));
  }
  const findAction = id => { for (const c of S.convs) for (const m of c.messages) for (const b of m.ui_blocks ?? []) if (b.action_id === id) return { c, b }; return {}; };
  const httpError = (status, message) => ({ response: { status, data: JSON.stringify({ message }) } });

  function replyFor(text) {
    const t = text;
    if (/موظف|موظفة/.test(t) && /(أضف|اضف|ضيف|إنشاء)/.test(t)) {
      const id = 'act-' + Math.random().toString(36).slice(2, 7);
      S.statuses[id] = 'pending';
      return { content: 'جهّزت إنشاء الحساب. راجع البيانات وأكّد:', blocks: [{ type: 'confirm_action', action_id: id, title: 'إنشاء موظف جديد', expires_at: iso(10 * 60e3), after: { name: 'موظف جديد', branch_id: 'فرع المعادي', roles: ['كاشير'], is_active: true } }], create: true };
    }
    if (/صلاحي/.test(t)) {
      return { content: 'دول الموظفين اللي عندهم الصلاحية دي:', blocks: [{ type: 'table', columns: ['الموظف', 'الدور', 'الفرع'], rows: [['أحمد سمير', 'مدير فرع', 'المعادي'], ['سارة محمود', 'محاسبة', 'المقر الرئيسي'], ['منى إبراهيم', 'موارد بشرية', 'المقر الرئيسي']] }] };
    }
    if (/فرع|فروع/.test(t)) {
      return { content: 'اختار الفرع اللي تقصده:', blocks: [{ type: 'entity_picker', entity: 'branch', purpose: 'أي فرع؟', options: branches.map((b, i) => ({ ...b, disabled: i === 3 ? 'الفرع متوقف حاليًا' : undefined })) }] };
    }
    return { content: 'تمام، فهمت طلبك. ده اللي أقدر أعمله:\n\n- أجمع البيانات المطلوبة من الفروع\n- أجهّز لك أي تعديل كمعاينة **قبل / بعد**\n- مفيش حاجة بتتنفذ غير بعد تأكيدك\n\nحابب أبدأ بإيه؟', blocks: [] };
  }

  async function route(method, url, body, config = {}) {
    const path = url.replace(BASE, '');
    await sleep(method === 'get' ? 260 : 380);
    let m;
    if (method === 'get' && path === '/conversations') {
      if (S.scenario === 'listError') throw httpError(500, 'Server error');
      const q = (config.params?.q ?? '').trim();
      const data = S.convs.filter(c => !q || (c.title ?? '').includes(q)).sort((a, b) => (b.last_message_at ?? '').localeCompare(a.last_message_at ?? '')).map(summary);
      return { data: { data, last_page: 1 } };
    }
    if (method === 'post' && path === '/conversations') {
      const c = { id: S.nextConv++, user_id: 1, is_pinned: false, title: null, model: 'alfarid-pro', total_tokens: 0, last_message_at: null, messages: [] };
      S.convs.unshift(c);
      return { data: { data: summary(c) } };
    }
    if ((m = path.match(/^\/conversations\/(\d+)$/))) {
      const c = S.convs.find(x => x.id === +m[1]);
      if (!c) throw httpError(404, 'غير موجودة');
      if (method === 'delete') { S.convs = S.convs.filter(x => x !== c); return { data: {} }; }
      return { data: { data: { ...c, messages: c.messages.slice(), pending_actions: pendingFor(c) } } };
    }
    if ((m = path.match(/^\/conversations\/(\d+)\/pin$/))) { const c = S.convs.find(x => x.id === +m[1]); c.is_pinned = body.is_pinned; return { data: {} }; }
    if ((m = path.match(/^\/conversations\/(\d+)\/stream$/))) {
      const c = S.convs.find(x => x.id === +m[1]);
      if (/خطأ|ميزانية/.test(body.text)) { await sleep(700); throw httpError(429, 'وصلت للحد اليومي للتوكنز (٢٠٠٬٠٠٠). هيتجدد الساعة ١٢ بالليل.'); }
      if (/انقطاع/.test(body.text)) { await sleep(900); throw { response: { data: { message: 'الاتصال بالموديل اتقطع مؤقتًا. حاول تاني بعد ثواني.' } } }; }
      const statuses = ['بفهم طلبك…', 'بدوّر في بيانات الفروع والموظفين…', 'بجهّز معاينة التعديل…', 'بكتب الرد…'];
      let sse = '';
      for (const s of statuses) {
        await sleep(950);
        sse += `event: status\ndata: ${JSON.stringify({ message: s })}\n\n`;
        config.onDownloadProgress?.({ event: { target: { responseText: sse } } });
      }
      await sleep(600);
      const r = replyFor(body.text);
      c.messages.push({ id: S.nextMsg++, role: 'user', content: body.text, ui_blocks: null, created_at: iso(0) });
      c.messages.push({ id: S.nextMsg++, role: 'assistant', content: r.content, ui_blocks: r.blocks, created_at: iso(0) });
      if (!c.title) c.title = body.text.slice(0, 40);
      c.last_message_at = iso(0); c.total_tokens += 900 + Math.round(Math.random() * 800);
      sse += `event: reply\ndata: ${JSON.stringify({ ok: true })}\n\n`;
      return { data: sse };
    }
    if ((m = path.match(/^\/actions\/([\w-]+)\/confirm$/))) {
      const { c, b } = findAction(m[1]);
      if (body.typed_confirmation !== undefined && b.typed_confirmation && body.typed_confirmation !== b.typed_confirmation) throw httpError(422, 'كلمة التأكيد غير مطابقة.');
      S.statuses[m[1]] = 'executed';
      const isCreate = b.title === 'إنشاء موظف جديد';
      const undoId = 'undo-' + m[1];
      let block;
      if (b.type === 'bulk_plan') {
        const sel = body.selected_items ?? [];
        block = { type: 'result', ok: true, message: `اتنفذت العملية على ${sel.length} من ${b.items.length}.`, deep_link: '/users?status=inactive', items: b.items.filter(i => sel.includes(i.key)).map((i, k) => ({ ok: k !== 2, message: k !== 2 ? `${i.label}: اتوقف الحساب` : `${i.label}: الحساب مقفول بواسطة مستخدم تاني` })) };
      } else {
        S.statuses[undoId] = 'executed';
        block = { type: 'result', ok: true, message: isCreate ? 'اتعمل حساب الموظف وأُرسل له بريد الترحيب.' : 'اتحفظ التعديل بنجاح.', deep_link: isCreate ? '/users?id=31' : b.type === 'permission_diff' ? '/roles?id=branch_manager' : '/branches?id=4', undo_action_id: undoId, undo_available_until: iso(3 * 60e3) };
      }
      c.messages.push({ id: S.nextMsg++, role: 'assistant', content: null, ui_blocks: [block], created_at: iso(0) });
      return { data: { data: { ok: true, message: 'ok', status: 'executed', ui_block: block, result: isCreate ? { generated_password: 'Hx7#qT2v-Lm9p' } : {} } } };
    }
    if ((m = path.match(/^\/actions\/([\w-]+)\/cancel$/))) { S.statuses[m[1]] = 'cancelled'; return { data: { data: { status: 'cancelled' } } }; }
    if ((m = path.match(/^\/actions\/([\w-]+)\/undo$/))) { S.statuses[m[1]] = 'undone'; return { data: { data: { ok: true, message: 'تم التراجع', status: 'undone' } } }; }
    if (path === '/mentions') {
      const q = (config.params?.q ?? '').trim();
      const f = arr => arr.filter(o => !q || o.label.includes(q) || (o.meta ?? '').includes(q));
      return { data: { data: { users: f(people), branches: f(branches), roles: f(roles) } } };
    }
    if (path === '/usage') {
      const data = [];
      for (let i = 29; i >= 0; i--) {
        const d = new Date(Date.now() - i * D);
        const w = d.getDay();
        const base = (w === 5 ? 0.25 : w === 6 ? 0.55 : 1) * (8000 + ((i * 7919) % 9000)) * (config.params?.all ? 4.2 : 1);
        data.push({ day: d.toISOString().slice(0, 10), input_tokens: Math.round(base * 0.68), output_tokens: Math.round(base * 0.32) });
      }
      return { data: { data } };
    }
    if (path === '/settings' && method === 'get') {
      return { data: { data: S.settings ?? (S.settings = { ai_enabled: true, ai_daily_token_budget_per_user: 200000, ai_disabled_tools: ['users.delete', 'settings.update'], ai_action_ttl_minutes: 15, ai_retention_days: 90, ai_bulk_max_items: 25 }), tools: ['branches.list', 'branches.update', 'users.list', 'users.create', 'users.update', 'users.delete', 'roles.list', 'roles.update', 'permissions.diff', 'settings.update', 'reports.export'] } };
    }
    if (path === '/settings' && method === 'put') { const { expected, ...rest } = body; S.settings = rest; return { data: {} }; }
    throw httpError(404, 'Not found: ' + path);
  }

  window.__mockApi = {
    reset(scenario) { S = freshState(scenario); },
    defaultApiClient: {
      get: (url, config) => route('get', url, undefined, config),
      post: (url, body, config) => route('post', url, body, config),
      patch: (url, body, config) => route('patch', url, body, config),
      put: (url, body, config) => route('put', url, body, config),
      delete: (url, config) => route('delete', url, undefined, config),
    },
  };
})();

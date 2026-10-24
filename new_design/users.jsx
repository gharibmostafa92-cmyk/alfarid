// ===== Users Management =====
const CURRENT_USER_ID = 1;

function KpiCard({ label, value, icon, foot, accent, delay, loading, bar }) {
  return (
    <div className={`${accent ? 'v-orange-earnings' : 'v-card'} rounded-[22px] p-5 flex flex-col justify-between min-h-[150px] stagger-item ${delay}`}>
      <div className="flex items-center justify-between">
        <span className={`text-[13px] font-medium ${accent ? 'text-white/90' : ''}`} style={accent ? null : { color: 'var(--text-secondary)' }}>{label}</span>
        <span className={`w-8 h-8 rounded-full flex items-center justify-center ${accent ? 'bg-white/15 text-white' : 'v-icon-well'}`}>
          <Icon name={icon} className="w-4 h-4" />
        </span>
      </div>
      {loading ? <Skeleton className="h-9 w-20 my-3 rounded-xl" style={accent ? { background: 'rgba(255,255,255,.25)' } : null} />
        : <div className="font-display text-[34px] font-bold tracking-tight my-2 tabular-nums" style={accent ? null : { color: 'var(--text-primary)' }}>{value}</div>}
      {bar != null && (
        <div className="h-3 w-full rounded-full overflow-hidden flex items-center gap-1 p-0.5 mb-2">
          <div className="h-full rounded-full transition-all duration-700" style={{ width: `${bar}%`, background: accent ? '#fff' : 'var(--accent-orange)' }} />
          <div className={`h-full flex-1 rounded-full ${accent ? 'rewards-hatched-track' : 'spending-hatched-track'}`} />
        </div>
      )}
      <div className={`text-[11.5px] ${accent ? 'text-white/80' : ''}`} style={accent ? null : { color: 'var(--text-muted)' }}>{foot}</div>
    </div>
  );
}

function UsersScreen({ users, setUsers, roles }) {
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState('');
  const [branch, setBranch] = useState('all');
  const [role, setRole] = useState('all');
  const [status, setStatus] = useState('all');
  const [page, setPage] = useState(1);
  const perPage = 8;
  const [drawer, setDrawer] = useState(null); // null | {mode:'create'} | {mode:'edit', user}
  const [confirm, setConfirm] = useState(null);

  useEffect(() => { const t = setTimeout(() => setLoading(false), 900); return () => clearTimeout(t); }, []);
  useEffect(() => setPage(1), [q, branch, role, status]);

  const me = users.find(u => u.id === CURRENT_USER_ID);
  const iAmSuper = me && me.roles.includes(1);
  const roleById = id => roles.find(r => r.id === id);
  const branchById = id => ADMIN_BRANCHES.find(b => b.id === id);

  const filtered = users.filter(u => {
    if (q && !(u.name.includes(q) || u.email.toLowerCase().includes(q.toLowerCase()))) return false;
    if (branch === 'none' && u.branch_id !== null) return false;
    if (typeof branch === 'number' && u.branch_id !== branch) return false;
    if (role !== 'all' && !u.roles.includes(role)) return false;
    if (status === 'active' && !u.is_active) return false;
    if (status === 'inactive' && u.is_active) return false;
    return true;
  });
  const pages = Math.max(1, Math.ceil(filtered.length / perPage));
  const rows = filtered.slice((page - 1) * perPage, page * perPage);

  const total = users.length, active = users.filter(u => u.is_active).length, inactive = total - active;
  const hasAccessAll = u => u.roles.some(rid => roleById(rid)?.perms.includes('branches.access_all'));
  const anyFilter = q || branch !== 'all' || role !== 'all' || status !== 'all';
  const clearFilters = () => { setQ(''); setBranch('all'); setRole('all'); setStatus('all'); };

  const saveUser = (data, mode) => {
    if (mode === 'create') {
      const id = Math.max(...users.map(u => u.id)) + 1;
      setUsers([{ ...data, id, created_at: new Date().toISOString().slice(0, 10) }, ...users]);
      toast('تم إنشاء المستخدم بنجاح');
    } else {
      setUsers(users.map(u => u.id === data.id ? { ...u, ...data } : u));
      toast(data.password ? 'تم الحفظ وإنهاء جميع جلسات المستخدم' : 'تم حفظ التعديلات');
    }
    setDrawer(null);
  };

  const doDeactivate = u => {
    setUsers(users.map(x => x.id === u.id ? { ...x, is_active: false } : x));
    setConfirm(null);
    toast(`تم تعطيل حساب ${u.name} وإنهاء جلساته`, 'warn');
  };
  const doActivate = u => {
    setUsers(users.map(x => x.id === u.id ? { ...x, is_active: true } : x));
    toast(`تم تفعيل حساب ${u.name}`);
  };

  return (
    <section className="space-y-5" data-screen-label="01 Users">
      <PageHeader title="المستخدمون" subtitle="إدارة حسابات فريق العمل وتوزيع الفروع والأدوار."
        action={<PrimaryButton icon="plus" onClick={() => setDrawer({ mode: 'create' })} className="stagger-item stagger-2">مستخدم جديد</PrimaryButton>} />

      <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
        <KpiCard accent label="إجمالي المستخدمين" icon="users" value={total} loading={loading} delay="stagger-2"
          foot={<span className="flex items-center gap-2"><span className="px-2 py-0.5 rounded-full text-[10.5px] font-semibold bg-white/20 text-white">+3</span>خلال آخر 30 يوماً</span>} />
        <KpiCard label="المستخدمون النشطون" icon="userCheck" value={active} loading={loading} delay="stagger-3" bar={total ? Math.round(active / total * 100) : 0}
          foot={<span className="flex items-center gap-2"><Badge tone="green">{total ? Math.round(active / total * 100) : 0}%</Badge> من إجمالي الحسابات</span>} />
        <KpiCard label="الحسابات المعطلة" icon="userOff" value={inactive} loading={loading} delay="stagger-4"
          foot={<span className="flex items-center gap-2"><Badge tone="red">موقوفة</Badge> لا يمكنها تسجيل الدخول</span>} />
      </div>

      <div className="v-card p-5 stagger-item stagger-5">
        {/* Toolbar */}
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div className="flex items-center gap-3">
            <h3 className="font-display text-[17px] font-semibold" style={{ color: 'var(--text-primary)' }}>فريق العمل</h3>
            <Badge tone="neutral">{filtered.length} مستخدم</Badge>
          </div>
          <div className="flex flex-wrap items-center gap-2.5">
            <div className="v-subcard px-3.5 h-9 rounded-full flex items-center gap-2">
              <Icon name="search" className="w-3.5 h-3.5" style={{ color: 'var(--text-muted)' }} />
              <input value={q} onChange={e => setQ(e.target.value)} placeholder="بحث بالاسم أو البريد" className="bg-transparent text-[12.5px] outline-none w-40 sm:w-48" style={{ color: 'var(--text-primary)' }} />
              {q && <button onClick={() => setQ('')} style={{ color: 'var(--text-muted)' }}><Icon name="close" className="w-3 h-3" strokeWidth={2.4} /></button>}
            </div>
            <Select compact icon="building" width={170} value={branch} onChange={setBranch} searchable
              options={[{ value: 'all', label: 'كل الفروع' }, { value: 'none', label: 'بدون فرع' }, ...ADMIN_BRANCHES.map(b => ({ value: b.id, label: b.name }))]} />
            <Select compact icon="shield" width={150} value={role} onChange={setRole}
              options={[{ value: 'all', label: 'كل الأدوار' }, ...roles.map(r => ({ value: r.id, label: r.name }))]} />
            <Segmented size="sm" value={status} onChange={setStatus}
              options={[{ value: 'all', label: 'الكل' }, { value: 'active', label: 'نشط' }, { value: 'inactive', label: 'معطل' }]} />
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-start border-collapse min-w-[900px]">
            <thead>
              <tr className="v-subcard text-[11.5px] font-medium" style={{ color: 'var(--text-secondary)' }}>
                <th className="py-2.5 ps-4 pe-3 text-start rounded-s-xl font-medium">المستخدم</th>
                <th className="py-2.5 px-3 text-start font-medium">الهاتف</th>
                <th className="py-2.5 px-3 text-start font-medium">الفرع</th>
                <th className="py-2.5 px-3 text-start font-medium">الأدوار</th>
                <th className="py-2.5 px-3 text-start font-medium">الحالة</th>
                <th className="py-2.5 px-3 text-start font-medium">تاريخ الإنشاء</th>
                <th className="py-2.5 ps-3 pe-4 text-end rounded-e-xl font-medium">الإجراءات</th>
              </tr>
            </thead>
            <tbody className="text-[12.5px] v-divide">
              {loading && Array.from({ length: 6 }).map((_, i) => (
                <tr key={i}>
                  <td className="py-3.5 ps-4 pe-3"><div className="flex items-center gap-3"><Skeleton className="w-9 h-9 rounded-full" /><div className="space-y-1.5"><Skeleton className="h-3 w-28 rounded" /><Skeleton className="h-2.5 w-36 rounded" /></div></div></td>
                  <td className="px-3"><Skeleton className="h-3 w-24 rounded" /></td>
                  <td className="px-3"><Skeleton className="h-6 w-28 rounded-full" /></td>
                  <td className="px-3"><Skeleton className="h-6 w-20 rounded-full" /></td>
                  <td className="px-3"><Skeleton className="h-6 w-14 rounded-full" /></td>
                  <td className="px-3"><Skeleton className="h-3 w-20 rounded" /></td>
                  <td className="pe-4"><Skeleton className="h-8 w-[72px] rounded-full ms-auto" /></td>
                </tr>
              ))}
              {!loading && rows.map(u => {
                const b = branchById(u.branch_id);
                const isMe = u.id === CURRENT_USER_ID;
                return (
                  <tr key={u.id} className={`v-row transition-colors ${!u.is_active ? 'is-muted' : ''}`}>
                    <td className="py-3 ps-4 pe-3">
                      <div className="flex items-center gap-3">
                        <Avatar name={u.name} id={u.id} ring={isMe} />
                        <div className="min-w-0">
                          <div className="font-semibold text-[13px] flex items-center gap-1.5" style={{ color: 'var(--text-primary)' }}>
                            {u.name}{isMe && <Badge tone="orange" className="!py-0 !px-2 !text-[10px]">أنت</Badge>}
                          </div>
                          <div className="text-[11.5px] mt-0.5" dir="ltr" style={{ color: 'var(--text-muted)', textAlign: 'right' }}>{u.email}</div>
                        </div>
                      </div>
                    </td>
                    <td className="px-3 tabular-nums" dir="ltr" style={{ color: u.phone ? 'var(--text-primary)' : 'var(--text-muted)', textAlign: 'right' }}>{u.phone || '—'}</td>
                    <td className="px-3">
                      {hasAccessAll(u) ? <Badge tone="dark"><Icon name="globe" className="w-3 h-3" />كل الفروع</Badge>
                        : b ? <Badge tone="outline"><Icon name="building" className="w-3 h-3 opacity-60" />{b.name}</Badge>
                        : <span className="text-[12px]" style={{ color: 'var(--text-muted)' }}>بدون فرع</span>}
                    </td>
                    <td className="px-3">
                      <div className="flex flex-wrap gap-1.5 max-w-[220px]">
                        {u.roles.length === 0 && <span className="text-[12px]" style={{ color: 'var(--text-muted)' }}>لا يوجد دور</span>}
                        {u.roles.map(rid => { const r = roleById(rid); return r && (
                          <Badge key={rid} tone={r.system ? 'orange' : 'neutral'}>{r.system && <Icon name="shieldCheck" className="w-3 h-3" />}{r.name}</Badge>
                        ); })}
                      </div>
                    </td>
                    <td className="px-3">{u.is_active ? <Badge tone="green" dot>نشط</Badge> : <Badge tone="red" dot>معطل</Badge>}</td>
                    <td className="px-3 tabular-nums" style={{ color: 'var(--text-secondary)' }}>{fmtDate(u.created_at)}</td>
                    <td className="ps-3 pe-4">
                      <div className="flex items-center justify-end gap-1.5">
                        <IconButton icon="edit" title="تعديل" onClick={() => setDrawer({ mode: 'edit', user: u })} />
                        <ActionMenu items={[
                          { icon: 'edit', label: 'تعديل البيانات', onClick: () => setDrawer({ mode: 'edit', user: u }) },
                          { icon: 'key', label: 'إعادة تعيين كلمة المرور', onClick: () => setDrawer({ mode: 'edit', user: u, focusPwd: true }) },
                          { divider: true },
                          u.is_active
                            ? { icon: 'power', label: isMe ? 'لا يمكنك تعطيل حسابك' : 'تعطيل الحساب', danger: !isMe, disabled: isMe, onClick: () => setConfirm(u) }
                            : { icon: 'power', label: 'إعادة تفعيل الحساب', onClick: () => doActivate(u) },
                        ]} />
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {!loading && rows.length === 0 && (
            <EmptyState title="لا توجد نتائج مطابقة" text="جرّب تعديل كلمات البحث أو إزالة بعض عوامل التصفية لعرض المزيد من المستخدمين."
              action={anyFilter && <GhostButton icon="refresh" onClick={clearFilters}>مسح التصفية</GhostButton>} />
          )}
        </div>

        {/* Pagination */}
        {!loading && filtered.length > 0 && (
          <div className="flex items-center justify-between mt-4 pt-4" style={{ borderTop: '1px solid var(--divider-color)' }}>
            <span className="text-[12px]" style={{ color: 'var(--text-muted)' }}>
              عرض {(page - 1) * perPage + 1}–{Math.min(page * perPage, filtered.length)} من {filtered.length}
            </span>
            <div className="flex items-center gap-1.5">
              <IconButton icon="chevRight" onClick={() => setPage(Math.max(1, page - 1))} />
              {Array.from({ length: pages }).map((_, i) => (
                <button key={i} onClick={() => setPage(i + 1)} className="w-8 h-8 rounded-full text-[12px] font-semibold transition"
                  style={page === i + 1 ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' } : { color: 'var(--text-secondary)' }}>{i + 1}</button>
              ))}
              <IconButton icon="chevLeft" onClick={() => setPage(Math.min(pages, page + 1))} />
            </div>
          </div>
        )}
      </div>

      <UserDrawer state={drawer} onClose={() => setDrawer(null)} onSave={saveUser} roles={roles} users={users} iAmSuper={iAmSuper} />

      <Modal open={!!confirm} onClose={() => setConfirm(null)}>
        {confirm && <>
          <div className="w-12 h-12 rounded-2xl v-badge-red flex items-center justify-center mb-4"><Icon name="power" className="w-5 h-5" strokeWidth={2.2} /></div>
          <h3 className="font-display text-[18px] font-semibold" style={{ color: 'var(--text-primary)' }}>تعطيل حساب {confirm.name}؟</h3>
          <p className="text-[13px] mt-2 leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
            لن يتمكن المستخدم من تسجيل الدخول، وسيتم <b style={{ color: 'var(--text-primary)' }}>إنهاء جميع جلساته الفعالة فوراً</b>. يمكنك إعادة تفعيل الحساب في أي وقت.
          </p>
          <div className="v-subcard p-3 mt-4 flex items-center gap-3">
            <Avatar name={confirm.name} id={confirm.id} size={32} />
            <div className="text-[12px]"><div className="font-semibold" style={{ color: 'var(--text-primary)' }}>{confirm.name}</div><div dir="ltr" style={{ color: 'var(--text-muted)', textAlign: 'right' }}>{confirm.email}</div></div>
          </div>
          <div className="grid grid-cols-2 gap-2.5 mt-5">
            <GhostButton onClick={() => setConfirm(null)}>إلغاء</GhostButton>
            <button onClick={() => doDeactivate(confirm)} className="h-10 rounded-full text-[13px] font-semibold text-white" style={{ background: 'var(--badge-red-text)' }}>تعطيل الحساب</button>
          </div>
        </>}
      </Modal>
    </section>
  );
}

function UserDrawer({ state, onClose, onSave, roles, users, iAmSuper }) {
  const open = !!state;
  const mode = state?.mode;
  const blank = { name: '', email: '', password: '', phone: '', branch_id: null, roles: [], is_active: true };
  const [f, setF] = useState(blank);
  const [showPwd, setShowPwd] = useState(false);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const pwdRef = useRef(null);

  useEffect(() => {
    if (!state) return;
    setErrors({}); setShowPwd(false); setSaving(false);
    setF(state.mode === 'edit' ? { ...state.user, password: '', phone: state.user.phone || '' } : blank);
    if (state.focusPwd) setTimeout(() => pwdRef.current?.querySelector('input')?.focus(), 450);
  }, [state]);

  const isMe = mode === 'edit' && f.id === CURRENT_USER_ID;
  const set = (k, v) => { setF(p => ({ ...p, [k]: v })); setErrors(e => ({ ...e, [k]: undefined })); };

  const validate = () => {
    const e = {};
    if (!f.name.trim()) e.name = 'الاسم مطلوب';
    else if (f.name.length > 255) e.name = 'الحد الأقصى 255 حرفاً';
    if (!f.email.trim()) e.email = 'البريد الإلكتروني مطلوب';
    else if (!/^\S+@\S+\.\S+$/.test(f.email)) e.email = 'صيغة البريد غير صحيحة';
    else if (users.some(u => u.email.toLowerCase() === f.email.toLowerCase() && u.id !== f.id)) e.email = 'هذا البريد مستخدم بالفعل في النظام';
    if (mode === 'create' && !f.password) e.password = 'كلمة المرور مطلوبة عند الإنشاء';
    else if (f.password && f.password.length < 8) e.password = 'يجب ألا تقل عن 8 أحرف';
    if (f.phone && f.phone.length > 30) e.phone = 'الحد الأقصى 30 حرفاً';
    setErrors(e);
    return !Object.keys(e).length;
  };

  const submit = async () => {
    if (!validate()) return;
    setSaving(true); await sleep(700);
    onSave({ ...f, phone: f.phone || null }, mode);
  };

  const pwdStrength = (() => {
    const p = f.password; if (!p) return 0;
    let s = 0; if (p.length >= 8) s++; if (/[A-Z]/.test(p) && /[a-z]/.test(p)) s++; if (/\d/.test(p)) s++; if (/[^A-Za-z0-9]/.test(p)) s++;
    return s;
  })();

  const roleOptions = roles.map(r => ({
    value: r.id, label: r.name, system: r.system,
    disabled: r.system && !iAmSuper,
    note: `${r.perms.length} صلاحية`,
  }));

  return (
    <Drawer open={open} onClose={onClose}>
      <div className="px-6 pt-5 pb-4 flex items-start justify-between gap-3" style={{ borderBottom: '1px solid var(--divider-color)' }}>
        <div className="flex items-center gap-3">
          {mode === 'edit' && f.name ? <Avatar name={f.name} id={f.id || 0} size={44} /> :
            <div className="w-11 h-11 rounded-full flex items-center justify-center text-white" style={{ background: 'radial-gradient(circle at 30% 25%, #ff8a65 0%, #e0491f 75%)' }}><Icon name="plus" className="w-5 h-5" strokeWidth={2.4} /></div>}
          <div>
            <h2 className="font-display text-[19px] font-semibold" style={{ color: 'var(--text-primary)' }}>{mode === 'edit' ? 'تعديل المستخدم' : 'مستخدم جديد'}</h2>
            <p className="text-[12px] mt-0.5" style={{ color: 'var(--text-secondary)' }}>{mode === 'edit' ? 'حدّث البيانات والأدوار والفرع التابع له.' : 'أنشئ حساباً لموظف جديد وحدد صلاحياته.'}</p>
          </div>
        </div>
        <IconButton icon="close" onClick={onClose} />
      </div>

      <div className="flex-1 overflow-y-auto px-6 py-5 space-y-5">
        {isMe && <Notice tone="info" title="أنت تعدّل حسابك الشخصي">لا يمكنك تعطيل حسابك أو إزالة أدوارك بنفسك.</Notice>}

        <div>
          <div className="text-[11px] font-semibold tracking-wide mb-3" style={{ color: 'var(--text-muted)' }}>البيانات الأساسية</div>
          <div className="space-y-4">
            <Field label="الاسم كامل" required error={errors.name}>
              <TextInput value={f.name} onChange={v => set('name', v)} placeholder="مثال: محمد أحمد علي" maxLength={255} error={errors.name} />
            </Field>
            <Field label="البريد الإلكتروني" required error={errors.email}>
              <TextInput value={f.email} onChange={v => set('email', v)} placeholder="name@company.com" dir="ltr" icon="mail" error={errors.email} />
            </Field>
            <div ref={pwdRef}>
              <Field label="كلمة المرور" required={mode === 'create'} error={errors.password}
                hint={mode === 'edit' ? 'اتركه فارغاً للإبقاء على كلمة المرور الحالية.' : '8 أحرف على الأقل.'}
                aside={pwdStrength > 0 && (
                  <span className="flex items-center gap-1">
                    {[1, 2, 3, 4].map(i => <span key={i} className="w-5 h-1 rounded-full" style={{ background: i <= pwdStrength ? (pwdStrength < 2 ? '#e11d48' : pwdStrength < 3 ? '#f59e0b' : '#16a34a') : 'var(--toggle-off-bg)' }} />)}
                  </span>
                )}>
                <TextInput value={f.password} onChange={v => set('password', v)} type={showPwd ? 'text' : 'password'} dir="ltr" icon="lock"
                  placeholder={mode === 'edit' ? '••••••••' : 'أدخل كلمة مرور قوية'} error={errors.password}
                  suffix={<button type="button" onClick={() => setShowPwd(!showPwd)} style={{ color: 'var(--text-muted)' }} className="hover:opacity-70"><Icon name={showPwd ? 'eyeOff' : 'eye'} className="w-4 h-4" /></button>} />
              </Field>
              {mode === 'edit' && f.password && <Notice tone="warn" className="mt-2.5">سيتم إنهاء جميع جلسات هذا المستخدم فور حفظ كلمة المرور الجديدة.</Notice>}
            </div>
            <Field label="رقم الهاتف" hint="اختياري" error={errors.phone}>
              <TextInput value={f.phone} onChange={v => set('phone', v)} placeholder="+20 1xx xxx xxxx" dir="ltr" icon="phone" maxLength={30} error={errors.phone} />
            </Field>
          </div>
        </div>

        <div className="h-px" style={{ background: 'var(--divider-color)' }} />

        <div>
          <div className="text-[11px] font-semibold tracking-wide mb-3" style={{ color: 'var(--text-muted)' }}>الفرع والصلاحيات</div>
          <div className="space-y-4">
            <Field label="الفرع">
              <Select searchable icon="building" value={f.branch_id} onChange={v => set('branch_id', v)} placeholder="اختر الفرع"
                options={[{ value: null, label: 'بدون فرع', icon: 'ban' }, ...ADMIN_BRANCHES.map(b => ({ value: b.id, label: b.name }))]} />
            </Field>
            <Field label="الأدوار" hint={!iAmSuper ? 'إسناد دور Super Admin متاح فقط لمن يمتلك هذا الدور.' : 'يمكن إسناد أكثر من دور للمستخدم.'}>
              <MultiSelect values={f.roles} onChange={v => set('roles', v)} options={roleOptions} placeholder="اختر دوراً أو أكثر" disabled={isMe} />
            </Field>
          </div>
        </div>

        <div className={`v-subcard p-4 flex items-center justify-between gap-4 ${isMe ? 'opacity-60' : ''}`}>
          <div className="flex items-center gap-3">
            <span className={`w-9 h-9 rounded-full flex items-center justify-center ${f.is_active ? 'v-badge-green' : 'v-badge-red'}`}><Icon name={f.is_active ? 'userCheck' : 'userOff'} className="w-4 h-4" /></span>
            <div>
              <div className="text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>{f.is_active ? 'حساب نشط' : 'موقوف مؤقتاً'}</div>
              <div className="text-[11.5px]" style={{ color: 'var(--text-muted)' }}>{f.is_active ? 'يمكنه تسجيل الدخول واستخدام النظام' : 'سيتم إنهاء جلساته ومنعه من الدخول'}</div>
            </div>
          </div>
          <Toggle on={f.is_active} onChange={v => set('is_active', v)} disabled={isMe} />
        </div>
      </div>

      <div className="px-6 py-4 flex items-center gap-2.5" style={{ borderTop: '1px solid var(--divider-color)' }}>
        <AccentButton icon="check" onClick={submit} loading={saving} className="flex-1">{mode === 'edit' ? 'حفظ التعديلات' : 'إنشاء المستخدم'}</AccentButton>
        <GhostButton onClick={onClose}>إلغاء</GhostButton>
      </div>
    </Drawer>
  );
}

Object.assign(window, { UsersScreen, CURRENT_USER_ID });

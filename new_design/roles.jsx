// ===== Roles & Permissions (master-detail) =====
function RolesScreen({ roles, setRoles, users }) {
  const [loading, setLoading] = useState(true);
  const [selId, setSelId] = useState(2);
  const [draft, setDraft] = useState(null); // {id?, name, perms:Set-like array, isNew}
  const [q, setQ] = useState('');
  const [openGroups, setOpenGroups] = useState(() => new Set(['inventory', 'products', 'pos']));
  const [saving, setSaving] = useState(false);
  const [del, setDel] = useState(null);
  const [roleQ, setRoleQ] = useState('');

  useEffect(() => { const t = setTimeout(() => setLoading(false), 800); return () => clearTimeout(t); }, []);

  const usersCount = id => users.filter(u => u.roles.includes(id)).length;
  const sel = roles.find(r => r.id === selId);

  useEffect(() => {
    if (sel) setDraft({ id: sel.id, name: sel.name, perms: [...sel.perms], system: sel.system });
  }, [selId, roles]);

  const original = draft?.isNew ? { name: '', perms: [] } : sel;
  const isDirty = draft && original && (draft.isNew || draft.name !== original.name || draft.perms.length !== original.perms.length || draft.perms.some(p => !original.perms.includes(p)));
  const changeCount = draft && original && !draft.isNew
    ? draft.perms.filter(p => !original.perms.includes(p)).length + original.perms.filter(p => !draft.perms.includes(p)).length + (draft.name !== original.name ? 1 : 0)
    : null;

  const isSystem = draft?.system;
  const togglePerm = k => { if (isSystem) return; setDraft(d => ({ ...d, perms: d.perms.includes(k) ? d.perms.filter(x => x !== k) : [...d.perms, k] })); };
  const toggleGroup = (g, on) => { if (isSystem) return; const keys = g.perms.map(p => p[0]); setDraft(d => ({ ...d, perms: on ? [...new Set([...d.perms, ...keys])] : d.perms.filter(x => !keys.includes(x)) })); };
  const toggleOpen = m => setOpenGroups(s => { const n = new Set(s); n.has(m) ? n.delete(m) : n.add(m); return n; });

  const newRole = () => { setSelId(null); setDraft({ name: '', perms: [], isNew: true }); setOpenGroups(new Set(PERMISSION_GROUPS.map(g => g.module))); };
  const reset = () => {
    if (draft.isNew) { setSelId(roles[1].id); return; }
    setDraft({ id: sel.id, name: sel.name, perms: [...sel.perms], system: sel.system });
  };
  const save = async () => {
    if (!draft.name.trim()) { toast('اسم الدور مطلوب', 'err'); return; }
    setSaving(true); await sleep(700); setSaving(false);
    if (draft.isNew) {
      const id = Math.max(...roles.map(r => r.id)) + 1;
      setRoles([...roles, { id, name: draft.name, perms: draft.perms }]);
      setSelId(id); toast('تم إنشاء الدور بنجاح');
    } else {
      setRoles(roles.map(r => r.id === draft.id ? { ...r, name: draft.name, perms: draft.perms } : r));
      toast('تم حفظ صلاحيات الدور');
    }
  };
  const doDelete = r => {
    setRoles(roles.filter(x => x.id !== r.id)); setDel(null); setSelId(roles.find(x => x.id !== r.id && !x.system)?.id || 1);
    toast(`تم حذف الدور «${r.name}»`, 'warn');
  };

  const total = ALL_PERMS.length;
  const granted = draft ? draft.perms.length : 0;
  const pct = Math.round(granted / total * 100);
  const ql = q.trim().toLowerCase();
  const groups = PERMISSION_GROUPS.map(g => ({ ...g, visible: g.perms.filter(p => !ql || p[1].includes(q.trim()) || p[0].toLowerCase().includes(ql)) }))
    .filter(g => g.visible.length > 0);
  const sensitiveGranted = draft ? PERMISSION_GROUPS.flatMap(g => g.perms).filter(p => p[2] && draft.perms.includes(p[0])).length : 0;
  const shownRoles = roles.filter(r => !roleQ || r.name.includes(roleQ));

  return (
    <section className="space-y-5 pb-20" data-screen-label="02 Roles">
      <PageHeader title="الأدوار والصلاحيات" subtitle="حدّد الهيكل الإداري ووزّع الصلاحيات بدقة — كل موظف يحصل فقط على ما تحتاجه وظيفته." />

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
        {/* Roles list */}
        <div className="lg:col-span-4 xl:col-span-4 v-card p-4 stagger-item stagger-2 lg:sticky lg:top-5">
          <div className="flex items-center justify-between mb-3 px-1">
            <div className="flex items-center gap-2">
              <h3 className="font-display text-[16px] font-semibold" style={{ color: 'var(--text-primary)' }}>الأدوار</h3>
              <span className="text-[11.5px]" style={{ color: 'var(--text-muted)' }}>| &nbsp;{roles.length} أدوار</span>
            </div>
            <button onClick={newRole} className="h-8 px-3.5 rounded-full text-[12px] font-semibold flex items-center gap-1.5 hover:opacity-90" style={{ background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }}>
              <Icon name="plus" className="w-3.5 h-3.5" strokeWidth={2.4} />دور جديد
            </button>
          </div>
          <div className="v-subcard px-3.5 h-9 rounded-full flex items-center gap-2 mb-3">
            <Icon name="search" className="w-3.5 h-3.5" style={{ color: 'var(--text-muted)' }} />
            <input value={roleQ} onChange={e => setRoleQ(e.target.value)} placeholder="بحث في الأدوار" className="bg-transparent text-[12.5px] outline-none w-full" style={{ color: 'var(--text-primary)' }} />
          </div>
          <div className="space-y-2">
            {loading && Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-[68px] rounded-2xl" />)}
            {!loading && draft?.isNew && (
              <div className="rounded-2xl p-3.5 flex items-center gap-3 v-role-active">
                <span className="w-10 h-10 rounded-xl flex items-center justify-center text-white v-accent-btn"><Icon name="plus" className="w-4 h-4" strokeWidth={2.4} /></span>
                <div className="flex-1"><div className="text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>{draft.name || 'دور جديد'}</div><div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>مسودة غير محفوظة</div></div>
              </div>
            )}
            {!loading && shownRoles.map(r => {
              const active = r.id === selId && !draft?.isNew;
              const uc = usersCount(r.id);
              return (
                <button key={r.id} onClick={() => setSelId(r.id)}
                  className={`w-full rounded-2xl p-3.5 flex items-center gap-3 text-start transition ${active ? 'v-role-active' : 'v-role-item'}`}>
                  <span className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${r.system ? 'text-white v-accent-btn' : 'v-role-icon'}`}>
                    <Icon name={r.system ? 'shieldCheck' : 'shield'} className="w-[18px] h-[18px]" />
                  </span>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1.5">
                      <span className="text-[13.5px] font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{r.name}</span>
                      {r.system && <Badge tone="orange" className="!py-0 !px-2 !text-[10px]">نظام</Badge>}
                    </div>
                    <div className="flex items-center gap-3 mt-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                      <span className="flex items-center gap-1"><Icon name="users" className="w-3 h-3" />{uc} مستخدم</span>
                      <span className="flex items-center gap-1"><Icon name="key" className="w-3 h-3" />{r.perms.length} صلاحية</span>
                    </div>
                  </div>
                  <div className="w-9 shrink-0">
                    <svg viewBox="0 0 36 36" className="w-9 h-9 -rotate-90">
                      <circle cx="18" cy="18" r="14" fill="none" strokeWidth="3.5" style={{ stroke: 'var(--toggle-off-bg)' }} />
                      <circle cx="18" cy="18" r="14" fill="none" strokeWidth="3.5" strokeLinecap="round" stroke="#e0491f" strokeDasharray={`${r.perms.length / total * 88} 88`} />
                    </svg>
                  </div>
                </button>
              );
            })}
          </div>
        </div>

        {/* Detail */}
        <div className="lg:col-span-8 xl:col-span-8 space-y-4 stagger-item stagger-3">
          {loading || !draft ? (
            <div className="v-card p-5 space-y-4"><Skeleton className="h-11 rounded-2xl" /><Skeleton className="h-24 rounded-2xl" />{[1, 2, 3].map(i => <Skeleton key={i} className="h-16 rounded-2xl" />)}</div>
          ) : <>
            <div className="v-card p-5">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex-1 min-w-[240px]">
                  <Field label="اسم الدور" required aside={isSystem && <span className="flex items-center gap-1 text-[11px] font-medium" style={{ color: 'var(--text-muted)' }}><Icon name="lock" className="w-3 h-3" />دور نظام مقفل</span>}>
                    <TextInput value={draft.name} onChange={v => setDraft(d => ({ ...d, name: v }))} disabled={isSystem} icon={isSystem ? 'lock' : 'shield'} placeholder="مثال: مشرف مبيعات" />
                  </Field>
                </div>
                {!draft.isNew && !isSystem && (
                  <button onClick={() => setDel(sel)} className="mt-7 h-11 px-4 rounded-full text-[12.5px] font-semibold flex items-center gap-2 v-badge-red hover:opacity-85 transition">
                    <Icon name="trash" className="w-4 h-4" />حذف الدور
                  </button>
                )}
              </div>

              {/* Coverage */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-5">
                <div className="v-orange-card rounded-[18px] p-4 sm:col-span-2 relative overflow-hidden">
                  <div className="flex items-center justify-between">
                    <span className="text-[12.5px] font-medium text-white/90">تغطية الصلاحيات</span>
                    <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-white/20">{pct}%</span>
                  </div>
                  <div className="font-display text-[30px] font-bold mt-1 tabular-nums">{granted} <span className="text-[16px] font-medium text-white/75">/ {total} صلاحية محددة</span></div>
                  <div className="mt-3 h-3.5 w-full rounded-full overflow-hidden flex items-center gap-1 p-0.5 bg-white/10">
                    <div className="h-full rounded-full bg-white transition-all duration-500" style={{ width: `${Math.max(pct, 2)}%` }} />
                    <div className="h-full flex-1 rounded-full rewards-hatched-track" />
                  </div>
                </div>
                <div className="v-subcard p-4 flex flex-col justify-between">
                  <div className="flex items-center justify-between">
                    <span className="text-[12.5px] font-medium" style={{ color: 'var(--text-secondary)' }}>صلاحيات حساسة</span>
                    <span className="w-7 h-7 rounded-full flex items-center justify-center v-badge-orange"><Icon name="alert" className="w-3.5 h-3.5" /></span>
                  </div>
                  <div className="font-display text-[28px] font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>{sensitiveGranted}</div>
                  <div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>تكلفة، اعتماد مالي، أسعار</div>
                </div>
              </div>

              {isSystem && <Notice tone="info" className="mt-4" icon="lock" title="دور النظام الأساسي">Super Admin يمتلك جميع الصلاحيات تلقائياً ولا يمكن تعديله أو حذفه.</Notice>}
            </div>

            {/* Search */}
            <div className="flex flex-wrap items-center gap-2.5">
              <div className="v-card !rounded-full px-4 h-11 flex items-center gap-2.5 flex-1 min-w-[240px]">
                <Icon name="search" className="w-4 h-4" style={{ color: 'var(--text-muted)' }} />
                <input value={q} onChange={e => setQ(e.target.value)} placeholder="ابحث بالاسم أو بمفتاح الكود مثل inventory.adjust" className="bg-transparent outline-none text-[13px] w-full" style={{ color: 'var(--text-primary)' }} />
                {q && <button onClick={() => setQ('')} style={{ color: 'var(--text-muted)' }}><Icon name="close" className="w-3.5 h-3.5" strokeWidth={2.4} /></button>}
              </div>
              <GhostButton onClick={() => setOpenGroups(openGroups.size ? new Set() : new Set(PERMISSION_GROUPS.map(g => g.module)))} className="!h-11 v-card !bg-[var(--bg-card)]">
                {openGroups.size ? 'طي الكل' : 'توسيع الكل'}
              </GhostButton>
            </div>

            {/* Groups */}
            <div className="space-y-3">
              {groups.length === 0 && <div className="v-card"><EmptyState title="لا توجد صلاحيات مطابقة" text={`لم نجد نتائج لـ «${q}». جرّب كلمة أخرى أو مفتاح كود مختلف.`} action={<GhostButton onClick={() => setQ('')}>مسح البحث</GhostButton>} /></div>}
              {groups.map(g => {
                const keys = g.perms.map(p => p[0]);
                const on = keys.filter(k => draft.perms.includes(k)).length;
                const all = on === keys.length, some = on > 0 && !all;
                const isOpen = ql ? true : openGroups.has(g.module);
                return (
                  <div key={g.module} className="v-card overflow-hidden">
                    <div className="flex items-center gap-3 p-4 cursor-pointer select-none" onClick={() => toggleOpen(g.module)}>
                      <span className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${on ? 'v-badge-orange' : 'v-icon-well'}`}><Icon name={g.icon} className="w-[18px] h-[18px]" /></span>
                      <div className="flex-1 min-w-0">
                        <div className="text-[14px] font-semibold" style={{ color: 'var(--text-primary)' }}>{g.label}</div>
                        <div className="text-[11px] mt-0.5 font-mono" dir="ltr" style={{ color: 'var(--text-muted)', textAlign: 'right' }}>{g.keys}</div>
                      </div>
                      <div className="hidden sm:flex items-center gap-2 w-[120px]">
                        <div className="flex-1 h-1.5 rounded-full overflow-hidden" style={{ background: 'var(--toggle-off-bg)' }}>
                          <div className="h-full rounded-full transition-all duration-300" style={{ width: `${on / keys.length * 100}%`, background: 'var(--accent-orange)' }} />
                        </div>
                        <span className="text-[11.5px] font-semibold tabular-nums" style={{ color: 'var(--text-secondary)' }}>{on}/{keys.length}</span>
                      </div>
                      <div onClick={e => e.stopPropagation()} className={`flex items-center gap-2 ps-3 ${isSystem ? 'opacity-50' : ''}`} style={{ borderInlineStart: '1px solid var(--divider-color)' }}>
                        <span className="text-[11.5px] font-medium hidden md:inline" style={{ color: 'var(--text-secondary)' }}>تحديد الكل</span>
                        <Toggle size="sm" on={all} onChange={v => toggleGroup(g, v)} disabled={isSystem} />
                      </div>
                      <Icon name="chevDown" className={`w-4 h-4 transition-transform duration-300 ${isOpen ? 'rotate-180' : ''}`} style={{ color: 'var(--text-muted)' }} />
                    </div>
                    <div className="v-collapse" style={{ gridTemplateRows: isOpen ? '1fr' : '0fr' }}>
                      <div className="overflow-hidden">
                        <div className="px-4 pb-4 grid grid-cols-1 md:grid-cols-2 gap-2">
                          {g.visible.map(([k, label, sensitive]) => {
                            const checked = draft.perms.includes(k);
                            const changed = !draft.isNew && sel && checked !== sel.perms.includes(k);
                            return (
                              <div key={k} onClick={() => togglePerm(k)}
                                className={`v-perm rounded-2xl px-3.5 py-3 flex items-center gap-3 ${checked ? 'is-on' : ''} ${isSystem ? 'cursor-default' : 'cursor-pointer'}`}>
                                <Checkbox checked={checked} disabled={isSystem} />
                                <div className="flex-1 min-w-0">
                                  <div className="text-[12.5px] font-semibold flex items-center gap-1.5" style={{ color: 'var(--text-primary)' }}>
                                    {label}
                                    {changed && <span className="w-1.5 h-1.5 rounded-full" style={{ background: 'var(--accent-orange)' }} title="تعديل غير محفوظ" />}
                                  </div>
                                  <div className="text-[10.5px] font-mono mt-0.5" dir="ltr" style={{ color: 'var(--text-muted)', textAlign: 'right' }}>{k}</div>
                                </div>
                                {sensitive && <Badge tone="orange" className="!text-[10px] !px-2"><Icon name="alert" className="w-3 h-3" />حساسة</Badge>}
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </>}
        </div>
      </div>

      <StickyBar show={!!isDirty && !isSystem} onSave={save} onReset={reset} saving={saving}
        message={draft?.isNew ? 'دور جديد غير محفوظ' : 'تغييرات غير محفوظة على الدور'} count={changeCount} />

      <Modal open={!!del} onClose={() => setDel(null)}>
        {del && (() => { const uc = usersCount(del.id); return <>
          <div className={`w-12 h-12 rounded-2xl flex items-center justify-center mb-4 ${uc ? 'v-badge-orange' : 'v-badge-red'}`}><Icon name={uc ? 'alert' : 'trash'} className="w-5 h-5" strokeWidth={2.2} /></div>
          <h3 className="font-display text-[18px] font-semibold" style={{ color: 'var(--text-primary)' }}>{uc ? 'لا يمكن حذف هذا الدور' : `حذف دور «${del.name}»؟`}</h3>
          <p className="text-[13px] mt-2 leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
            {uc ? <>الدور مُسند حالياً إلى <b style={{ color: 'var(--text-primary)' }}>{uc} مستخدم</b>. أزل الدور من المستخدمين أولاً ثم أعد المحاولة.</> : 'سيتم حذف الدور وجميع صلاحياته نهائياً. لا يمكن التراجع عن هذا الإجراء.'}
          </p>
          <div className="grid grid-cols-2 gap-2.5 mt-5">
            <GhostButton onClick={() => setDel(null)} className={uc ? 'col-span-2' : ''}>{uc ? 'حسناً' : 'إلغاء'}</GhostButton>
            {!uc && <button onClick={() => doDelete(del)} className="h-10 rounded-full text-[13px] font-semibold text-white" style={{ background: 'var(--badge-red-text)' }}>حذف نهائي</button>}
          </div>
        </>; })()}
      </Modal>
    </section>
  );
}
Object.assign(window, { RolesScreen });

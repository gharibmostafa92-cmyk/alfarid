'use client';

import React, { cloneElement, Fragment, isValidElement, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal, flushSync } from 'react-dom';
import { initialState } from './initial-state';
import { dashboardStyles } from './styles';
import { BrandLogo, useBranding } from '../components/branding-provider';

function AnimatedNumber({ value, start, duration, trigger, format }) {
  const [display, setDisplay] = useState(start);
  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      setDisplay(value);
      return;
    }
    let frame;
    const started = performance.now();
    function tick(now) {
      const progress = Math.min((now - started) / duration, 1);
      setDisplay(start + (value - start) * (1 - Math.pow(1 - progress, 3)));
      if (progress < 1) frame = requestAnimationFrame(tick);
    }
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [value, start, duration, trigger]);
  return <>{format(display)}</>;
}

function NavigationIcon({ type }) {
  return <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    {type === 'products' ? <><path d="M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10" /></>
      : type === 'inventory' ? <><path d="M3 3h9l9 9-9 9-9-9z" /><circle cx="8" cy="8" r="1.5" /></>
      : type === 'categories' ? <><rect x="9" y="3" width="6" height="5" rx="1.5" /><path d="M12 8v5M5 13h14M5 13v3M19 13v3" /><rect x="2" y="16" width="6" height="5" rx="1.5" /><rect x="16" y="16" width="6" height="5" rx="1.5" /></>
      : type === 'users' ? <><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 010 6.8M18 14.8c1.9.7 3.2 2.4 3.5 5.2"/></>
      : type === 'roles' ? <><path d="M12 3l7.5 3v5.5c0 4.6-3.2 8.3-7.5 9.5-4.3-1.2-7.5-4.9-7.5-9.5V6L12 3z"/><path d="M9 12l2 2 4-4"/></>
      : type === 'settings' ? <><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/></>
      : type === 'messages' ? <><rect x="3" y="5" width="18" height="14" rx="2.5" /><path d="m3 7 9 6 9-6" /></>
      : type === 'more' ? <><circle cx="5" cy="12" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="19" cy="12" r="1" /></>
      : <><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></>}
  </svg>;
}

/**
 * The supplied Vortix prototype, with its original labels, sample values and visual structure.
 * @param {{ onLogout?: () => void | Promise<void>, categoriesPage?: import("react").ReactNode, categoriesActive?: boolean, onOpenCategories?: () => void, onCloseCategories?: () => void, catalogPage?: import("react").ReactNode, catalogActive?: string | null, catalogNav?: {id: string, label: string, onOpen: () => void}[], onCloseCatalog?: () => void, onOpenNotifications?: () => void }} props
 */
export function FinancialDashboard({ onLogout, categoriesPage, categoriesActive = false, onOpenCategories, onCloseCategories, catalogPage, catalogActive = null, catalogNav = [], onCloseCatalog, onOpenNotifications } = {}) {
  const { branding } = useBranding();
  const hostRef = useRef(null);
  const viewportRef = useRef(null);
  const underlayRef = useRef(null);
  const timers = useRef({ toast: null, copied: null, wipe: null });
  const [shadowRoot, setShadowRoot] = useState(null);
  const [state, setState] = useState(() => {
    const s = structuredClone(initialState);
    try {
      const saved = localStorage.getItem('vortix_theme');
      if (saved) s.darkMode = saved === 'dark';
    } catch {}
    return s;
  });
  const currentView = catalogActive || (categoriesActive ? 'categories' : state.currentView);
  const [activityQuery, setActivityQuery] = useState('');
  const [selectedActivities, setSelectedActivities] = useState([]);
  const [selectedPnlIdx, setSelectedPnlIdx] = useState(null);
  const [chatQuery, setChatQuery] = useState('');
  const [composer, setComposer] = useState('');
  const [toast, setToast] = useState('');
  const [copied, setCopied] = useState(false);
  const [modal, setModal] = useState(null);
  const [counterEpoch, setCounterEpoch] = useState(0);

  useLayoutEffect(() => {
    setShadowRoot(hostRef.current.shadowRoot ?? hostRef.current.attachShadow({ mode: 'open' }));
  }, []);

  useEffect(() => {
    const previousLanguage = document.documentElement.lang;
    document.documentElement.lang = 'en';
    let fonts = document.querySelector('[data-vortix-fonts]');
    if (!fonts) {
      fonts = document.createElement('link');
      fonts.rel = 'stylesheet';
      fonts.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap';
      fonts.setAttribute('data-vortix-fonts', '');
      document.head.appendChild(fonts);
    }
    const scrollbars = document.createElement('style');
    scrollbars.textContent = 'html.vortix-active::-webkit-scrollbar{width:5px;height:5px}html.vortix-active::-webkit-scrollbar-track{background:transparent}html.vortix-active::-webkit-scrollbar-thumb{background:rgba(150,145,160,.25);border-radius:999px}';
    document.head.appendChild(scrollbars);
    document.documentElement.classList.add('vortix-active');
    return () => {
      document.documentElement.lang = previousLanguage;
      document.documentElement.classList.remove('vortix-active');
      scrollbars.remove();
      clearTimeout(timers.current.toast);
      clearTimeout(timers.current.copied);
      cancelAnimationFrame(timers.current.wipe);
    };
  }, []);

  useEffect(() => {
    hostRef.current?.setAttribute('data-theme', state.darkMode ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', state.darkMode ? 'dark' : 'light');
    try {
      localStorage.setItem('vortix_theme', state.darkMode ? 'dark' : 'light');
    } catch {}
  }, [state.darkMode]);

  useEffect(() => {
    const section = shadowRoot?.getElementById('view-' + currentView);
    section?.querySelectorAll('.stagger-item').forEach(element => {
      element.style.animation = 'none';
      void element.offsetWidth;
      element.style.animation = '';
    });
  }, [shadowRoot, currentView, counterEpoch]);

  useEffect(() => {
    if (!modal || !shadowRoot) return;
    const dialog = shadowRoot.querySelector('.v-modal-card');
    const previousFocus = shadowRoot.activeElement;
    const previousOverflow = document.body.style.overflow;
    const viewport = viewportRef.current;
    const previousInert = viewport.inert;
    viewport.inert = true;
    document.body.style.overflow = 'hidden';
    const focusable = () => [...dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])')]
      .filter(element => element.getClientRects().length > 0);
    const frame = requestAnimationFrame(() => (dialog.querySelector('input') || focusable()[0] || dialog).focus());
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        setModal(null);
      }
      if (event.key === 'Tab') {
        const targets = focusable();
        const first = targets[0];
        const last = targets.at(-1);
        const active = shadowRoot.activeElement;
        if (!first) { event.preventDefault(); dialog.focus(); }
        else if (event.shiftKey && (active === first || !dialog.contains(active))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (active === last || !dialog.contains(active))) { event.preventDefault(); first.focus(); }
      }
    };
    window.addEventListener('keydown', onKeyDown, true);
    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener('keydown', onKeyDown, true);
      viewport.inert = previousInert;
      document.body.style.overflow = previousOverflow;
      if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
    };
  }, [modal, shadowRoot]);

  const currency = state.currencies[state.currencyIndex];
  const chat = state.chats.find(item => item.id === state.activeChatId) || state.chats[0];
  const filteredActivities = state.activities.filter(row => [row.id, row.name, row.status].some(value => value.toLowerCase().includes(activityQuery.toLowerCase())));
  const money = value => '$' + Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const update = callback => setState(previous => { const next = structuredClone(previous); callback(next); return next; });

  function showToast(message) {
    clearTimeout(timers.current.toast);
    setToast(message);
    timers.current.toast = setTimeout(() => setToast(''), 2400);
  }
  function switchView(view) {
    if (categoriesActive) onCloseCategories?.();
    if (catalogActive) onCloseCatalog?.();
    update(next => { next.currentView = view; });
    if (view === 'overview') setCounterEpoch(value => value + 1);
  }
  const navStyle = view => ({ whiteSpace: 'nowrap', flexShrink: 0, backgroundColor: currentView === view ? 'var(--pill-active-bg)' : 'transparent', color: currentView === view ? 'var(--pill-active-text)' : 'var(--text-secondary)' });
  const navigationItems = [
    { id: 'overview', label: 'الرئيسية', onOpen: () => switchView('overview') },
    ...catalogNav,
    ...(onOpenCategories ? [{ id: 'categories', label: 'الأقسام', onOpen: onOpenCategories }] : []),
    { id: 'messages', label: 'الرسائل', onOpen: () => switchView('messages') },
  ];
  const primaryNavigation = ['overview', 'inventory', 'products', 'categories']
    .map(id => navigationItems.find(item => item.id === id)).filter(Boolean);
  function openNavigation(item) {
    setModal(null);
    item.onOpen();
  }
  function renderMobileNavigation() {
    return <nav className="v-mobile-nav" aria-label="التنقل الرئيسي على الموبايل" dir="rtl" style={{ gridTemplateColumns: `repeat(${primaryNavigation.length + 1}, minmax(0, 1fr))` }}>
      {primaryNavigation.map(item => <button type="button" key={item.id} data-mobile-nav={item.id} aria-current={currentView === item.id ? 'page' : undefined} onClick={() => openNavigation(item)}>
        <NavigationIcon type={item.id} /><span>{item.label}</span>
      </button>)}
      <button type="button" data-mobile-nav="more" aria-haspopup="dialog" aria-expanded={modal === 'navigation'} aria-controls="v-modal-backdrop" aria-current={!primaryNavigation.some(item => item.id === currentView) ? 'page' : undefined} onClick={() => setModal('navigation')}>
        <NavigationIcon type="more" /><span>المزيد</span>
      </button>
    </nav>;
  }
  function cycleCurrency() {
    const nextIndex = (state.currencyIndex + 1) % state.currencies.length;
    update(next => { next.currencyIndex = nextIndex; });
    setCounterEpoch(value => value + 1);
    showToast(`Switched display currency to ${state.currencies[nextIndex].code}`);
  }
  function walletClick(wallet) {
    if (wallet !== 'GBP') { showToast(`${wallet} Wallet is active`); return; }
    update(next => { next.gbpActive = !next.gbpActive; });
    showToast(state.gbpActive ? 'GBP Wallet set to inactive' : 'GBP Wallet reactivated');
  }
  function selectChatFilter(filter) {
    update(next => { next.chatFilter = filter; });
  }
  function selectChat(id) {
    update(next => {
      next.activeChatId = id;
      const target = next.chats.find(item => item.id === id);
      if (target) target.unread = 0;
    });
  }
  function sendMessage(event) {
    event.preventDefault();
    const text = composer.trim();
    if (!text) return;
    update(next => {
      const target = next.chats.find(item => item.id === next.activeChatId);
      target.messages.push({ sender: 'me', text, time: 'Just now' });
      target.time = 'Just now';
    });
    setComposer('');
  }
  function logout() {
    if (onLogout) { void onLogout(); return; }
    showToast('Session locked · Code XR Studio');
  }

  function changeTheme(targetDark, origin) {
    const isDark = targetDark === state.darkMode ? !state.darkMode : targetDark;
    cancelAnimationFrame(timers.current.wipe);
    const viewport = viewportRef.current;
    const underlay = underlayRef.current;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      viewport.style.clipPath = 'none';
      viewport.classList.remove('no-theme-transitions');
      underlay.classList.add('hidden');
      underlay.replaceChildren();
      window.dispatchEvent(new CustomEvent('vortix:theme-change', { detail: { darkMode: isDark } }));
      update(next => { next.darkMode = isDark; });
      return;
    }
    const rect = origin.getBoundingClientRect();
    const cx = Math.round(rect.left + rect.width / 2);
    const cy = Math.round(rect.top + rect.height / 2);
    const scrollY = window.scrollY || 0;
    const maxRadius = Math.ceil(Math.hypot(Math.max(cx, window.innerWidth - cx), Math.max(cy, window.innerHeight - cy))) + 30;
    const snapshot = viewport.cloneNode(true);
    const liveInputs = viewport.querySelectorAll('input');
    snapshot.querySelectorAll('input').forEach((input, index) => {
      input.value = liveInputs[index].value;
      input.checked = liveInputs[index].checked;
    });
    const liveHosts = viewport.querySelectorAll('[data-catalog-mock], [data-categories-mock], [data-inventory-mock], [data-admin-mock]');
    snapshot.querySelectorAll('[data-catalog-mock], [data-categories-mock], [data-inventory-mock], [data-admin-mock]').forEach((hostClone, index) => {
      const liveHost = liveHosts[index];
      if (liveHost?.shadowRoot) {
        const cloneRoot = hostClone.attachShadow({ mode: 'open' });
        cloneRoot.innerHTML = liveHost.shadowRoot.innerHTML;
      }
    });
    snapshot.removeAttribute('id');
    snapshot.style.clipPath = 'none';
    snapshot.style.width = viewport.offsetWidth + 'px';
    snapshot.style.transform = `translateY(-${scrollY}px)`;
    snapshot.querySelectorAll('[id]').forEach(element => element.removeAttribute('id'));
    underlay.replaceChildren(snapshot);
    underlay.classList.remove('hidden');
    try {
      localStorage.setItem('vortix_theme', isDark ? 'dark' : 'light');
    } catch {}
    window.dispatchEvent(new CustomEvent('vortix:theme-change', { detail: { darkMode: isDark } }));
    document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
    flushSync(() => update(next => { next.darkMode = isDark; }));
    hostRef.current.setAttribute('data-theme', isDark ? 'dark' : 'light');
    viewport.classList.add('no-theme-transitions');
    const clipY = cy + scrollY;
    viewport.style.clipPath = `circle(0px at ${cx}px ${clipY}px)`;
    const started = performance.now();
    function step(now) {
      const progress = Math.min((now - started) / 660, 1);
      const eased = progress < 0.5 ? 2 * progress * progress : 1 - Math.pow(-2 * progress + 2, 2) / 2;
      viewport.style.clipPath = `circle(${(eased * maxRadius).toFixed(1)}px at ${cx}px ${clipY}px)`;
      if (progress < 1) timers.current.wipe = requestAnimationFrame(step);
      else {
        viewport.style.clipPath = 'none';
        viewport.classList.remove('no-theme-transitions');
        underlay.classList.add('hidden');
        underlay.replaceChildren();
      }
    }
    timers.current.wipe = requestAnimationFrame(step);
  }

  const modalTitle = { transfer: 'Transfer Funds', request: 'Request Payment', navigation: 'المزيد' }[modal] ?? 'Quick Transfer';
  function confirmModal() {
    showToast({ transfer: 'Transfer sent successfully', request: 'Payment request link copied' }[modal]);
    setModal(null);
  }
  function renderModal() {
    if (!modal) return null;
    if (modal === 'navigation') return <div className="v-mobile-menu" dir="rtl">
      <nav aria-label="جميع أقسام لوحة التحكم">
        {navigationItems.map(item => <button type="button" key={item.id} data-menu-nav={item.id} aria-current={currentView === item.id ? 'page' : undefined} onClick={() => openNavigation(item)}><NavigationIcon type={item.id} /><span>{item.label}</span></button>)}
      </nav>
      <div className="v-mobile-menu-tools">
        <button type="button" onClick={event => changeTheme(!state.darkMode, event.currentTarget)} aria-pressed={state.darkMode}>الوضع الداكن<span aria-hidden="true">{state.darkMode ? 'مفعّل' : 'غير مفعّل'}</span></button>
        <button type="button" onClick={() => { setModal(null); showToast(`${branding.name} · All systems operational`); }}>حالة النظام</button>
        <button type="button" onClick={() => { setModal(null); logout(); }}>تسجيل الخروج</button>
      </div>
    </div>;
    const fields = modal === 'transfer'
      ? [['Recipient', 'text', 'Alex Rivera (alex@studio.io)', 'modal-transfer-to'], ['Amount (USD)', 'number', '1250', 'modal-transfer-amt']]
      : [['Client Email', 'email', 'billing@acme.corp', 'modal-request-email'], ['Amount (USD)', 'number', '4800', 'modal-request-amt']];
    return <>
      {fields.map(([label, type, value, id]) => <div key={label}>
        <label htmlFor={id} className="text-[12px] font-medium" style={{ color: 'var(--text-secondary)' }}>{label}</label>
        <input id={id} type={type} inputMode={type === 'number' ? 'decimal' : undefined} defaultValue={value} className="v-subcard w-full px-3.5 py-2.5 rounded-xl mt-1 outline-none" style={{ minHeight: '48px', fontSize: 16, color: 'var(--text-primary)' }} />
      </div>)}
      <button type="button" id={'modal-confirm-' + modal} className={'v-action-cta w-full py-3 rounded-full font-semibold mt-3 text-[14px]' + (modal === 'request' ? '' : ' bg-[#e0491f] text-white')} style={modal === 'request' ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' } : undefined} onClick={confirmModal}>
        {{ transfer: 'Send Instant Transfer', request: 'Create Payment Link' }[modal]}
      </button>
    </>;
  }

  // ==================== OVERVIEW: PNL BAR CHART & RECENT ACTIVITIES ====================
  function renderPnlBars() {
    return state.pnlMonths.map((item, index) => <Fragment key={index}>{" "}<div role="button" tabIndex={0} aria-label={`${item.month}: Profit ${item.profitVal}, Loss ${item.lossVal}`} data-selected={selectedPnlIdx === index ? "true" : undefined} onClick={() => setSelectedPnlIdx(prev => prev === index ? null : index)} onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setSelectedPnlIdx(prev => prev === index ? null : index); } }} className="v-pnl-col group relative flex-1 h-full flex flex-col items-center justify-end cursor-pointer">{" "}<div className="v-pnl-tooltip opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity absolute -top-8 z-20 px-2 py-1 rounded-md text-[10px] font-semibold whitespace-nowrap shadow-md" style={{
          "background": "var(--pill-active-bg)",
          "color": "var(--pill-active-text)"
        }}>{"" + " P: " + item.profitVal + " \xB7 L: " + item.lossVal + " "}</div>{" "}<div className="w-full max-w-[26px] h-full flex flex-col justify-end gap-[3px]">{" "}{item.profitPct > 0 ? <><div className="w-full rounded-[6px] bar-profit-hatched transition-all duration-500" style={{
              "height": "" + item.profitPct + "%"
            }}></div></> : ''}{" "}<div className="w-full rounded-[6px] transition-all duration-500" style={{
            "height": "" + item.lossPct + "%",
            "background": "var(--bar-loss)"
          }}></div>{" "}</div>{" "}<span className="text-[10.5px] mt-1.5 font-medium" style={{
          "color": selectedPnlIdx === index ? "var(--text-primary)" : "var(--text-muted)"
        }}>{"" + item.month}</span>{" "}</div>{" "}</Fragment>);
  }

  function renderActivitiesTable() {
    const q = activityQuery.toLowerCase();
    const rows = state.activities.filter(a => a.id.toLowerCase().includes(q) || a.name.toLowerCase().includes(q) || a.status.toLowerCase().includes(q));
    if (rows.length === 0) {
      return <tr><td colSpan={6} className="py-8 px-4 text-center" style={{ color: 'var(--text-secondary)' }}>
        <div className="flex flex-col items-center gap-2">
          <span className="text-[13px] font-medium">No matching activities found</span>
          {activityQuery && <button type="button" className="v-subcard px-3.5 py-1.5 rounded-full text-[12px] font-semibold" style={{ color: 'var(--text-primary)' }} onClick={() => setActivityQuery('')}>Clear search</button>}
        </div>
      </td></tr>;
    }
    return rows.map((r, index) => <Fragment key={r.id}><tr className="hover:opacity-90 transition"><td className="py-3 pl-3.5 pr-2">{" "}<input type="checkbox" aria-label={`Select ${r.name}`} className="row-activity-check w-4 h-4 rounded border-gray-300 accent-[#e0491f] cursor-pointer" checked={selectedActivities.includes(r.id)} onChange={event => setSelectedActivities(ids => event.target.checked ? [...ids, r.id] : ids.filter(id => id !== r.id))} />{" "}</td><td className="py-3 px-3 font-medium whitespace-nowrap" style={{
          "color": "var(--text-secondary)"
        }}>{"" + r.id}</td><td className="py-3 px-3">{" "}<div className="flex items-center gap-2.5">{" "}<span className="w-6 h-6 rounded-lg flex items-center justify-center text-white text-[11px] font-bold shrink-0" style={{
              "background": r.color
            }}>A</span>{" "}<span className="font-semibold whitespace-nowrap" style={{
              "color": "var(--text-primary)"
            }}>{"" + r.name}</span>{" "}</div>{" "}</td><td className="py-3 px-3 font-semibold whitespace-nowrap" style={{
          "color": "var(--text-primary)"
        }}>{"" + r.price}</td><td className="py-3 px-3 whitespace-nowrap">{" "}<span className={"inline-flex items-center gap-1.5 text-[12px] font-medium " + (r.status === 'Completed' ? 'text-emerald-500' : 'text-amber-500') + ""}>{" "}<span className={"w-1.5 h-1.5 rounded-full " + (r.status === 'Completed' ? 'bg-emerald-500' : 'bg-amber-500') + ""}></span>{" "}{r.status}{" "}</span>{" "}</td><td className="py-3 px-3 flex items-center justify-between gap-2 whitespace-nowrap" style={{
          "color": "var(--text-secondary)"
        }}>{" "}<span>{"" + r.date}</span>{" "}<button type="button" data-order-id={r.id} aria-label={`More actions for ${r.id}`} onClick={() => showToast(`${r.id} · ${r.name} (${r.status})`)} className="act-row-more v-touch-icon-btn text-xs px-2 opacity-75 hover:opacity-100">•••</button>{" "}</td></tr></Fragment>);
  }

  // ==================== MESSAGES VIEW ====================
  function renderChatThreads() {
    const q = chatQuery.toLowerCase();
    const threads = state.chats.filter(c => {
      if (state.chatFilter === 'unread' && c.unread === 0) return false;
      return c.name.toLowerCase().includes(q);
    });
    if (threads.length === 0) {
      return <div className="v-subcard rounded-2xl p-4 text-center space-y-2" style={{ color: 'var(--text-secondary)' }}>
        <div className="text-[12.5px] font-medium">No conversations match your filter</div>
        <button type="button" className="v-card px-3.5 py-1.5 rounded-full text-[11.5px] font-semibold" style={{ color: 'var(--text-primary)' }} onClick={() => { selectChatFilter('all'); setChatQuery(''); }}>Show all chats</button>
      </div>;
    }
    return threads.map((c, index) => {
      const isSelected = c.id === state.activeChatId;
      const lastMsg = c.messages[c.messages.length - 1]?.text || '';
      return <Fragment key={c.id}>{" "}<div role="button" tabIndex={0} data-chat-id={c.id} className={"chat-thread-item relative rounded-2xl p-3 flex items-center justify-between gap-2.5 cursor-pointer transition " + (isSelected ? 'v-subcard' : 'hover:opacity-85') + ""} onClick={() => selectChat(c.id)} onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectChat(c.id); } }}>{" "}{isSelected ? <><span className="w-1 h-7 rounded-full bg-[#e0491f] absolute left-0"></span></> : ''}{" "}<div className="flex items-center gap-3 min-w-0">{" "}<div className="w-9 h-9 rounded-full flex items-center justify-center text-white font-bold text-xs shrink-0 relative" style={{
              "background": c.avatarBg
            }}>{" "}<span>{"" + c.avatarText}</span>{" "}<span className="w-2.5 h-2.5 rounded-full bg-emerald-500 absolute -bottom-0.5 -right-0.5" style={{
                "boxShadow": "0 0 0 2px var(--bg-card)"
              }}></span>{" "}</div>{" "}<div className="min-w-0">{" "}<div className="text-[13px] font-semibold truncate" style={{
                "color": "var(--text-primary)"
              }}>{"" + c.name}</div>{" "}<div className="text-[11.5px] truncate" style={{
                "color": "var(--text-muted)"
              }}>{lastMsg}</div>{" "}</div>{" "}</div>{" "}<div className="flex flex-col items-end gap-1 shrink-0">{" "}<span className="text-[10.5px]" style={{
              "color": "var(--text-muted)"
            }}>{"" + c.time}</span>{" "}{c.unread > 0 ? <><span className="w-4 h-4 rounded-full bg-[#e0491f] text-white text-[10px] font-bold flex items-center justify-center">{"" + c.unread}</span></> : ''}{" "}</div>{" "}</div>{" "}</Fragment>;
    });
  }

  function renderActiveChat() {
    const chat = state.chats.find(c => c.id === state.activeChatId) || state.chats[0];
    return chat.messages.map((m, index) => {
      if (m.sender === 'them') {
        return <Fragment key={index}>{" "}<div className="max-w-[360px] v-subcard rounded-2xl rounded-tl-xs px-4 py-2.5 text-[13px] leading-relaxed" style={{
            "color": "var(--text-primary)"
          }}>{" "}<div>{"" + m.text}</div>{" "}<div className="text-[10px] text-right mt-1" style={{
              "color": "var(--text-muted)"
            }}>{"" + m.time}</div>{" "}</div>{" "}</Fragment>;
      }
      return <Fragment key={index}>{" "}<div className="max-w-[360px] ml-auto rounded-2xl rounded-tr-xs px-4 py-2.5 text-[13px] leading-relaxed bg-[#e0491f] text-white">{" "}<div>{"" + m.text}</div>{" "}<div className="text-[10px] text-right mt-1 text-white/80">{"" + m.time}</div>{" "}</div>{" "}</Fragment>;
    });
  }

  return <div ref={hostRef} data-vortix-host="" dir="ltr" lang="en">
    {shadowRoot && createPortal(<><style>{dashboardStyles}</style><>{" "}<div id="theme-wipe-overlay" className="hidden" ref={underlayRef}></div>{" "}<div id="v-toast" role="status" aria-live="polite" className={'fixed bottom-6 right-6 z-50 pointer-events-none transition-all duration-300 ' + (toast ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2')}>{" "}<div className="px-4 py-2.5 rounded-full shadow-lg text-xs font-medium flex items-center gap-2" style={{
      "background": "var(--pill-active-bg)",
      "color": "var(--pill-active-text)"
    }}>{" "}<span id="v-toast-dot" className="w-2 h-2 rounded-full bg-[#e0491f]"></span>{" "}<span id="v-toast-text">{toast || "Action completed"}</span>{" "}</div>{" "}</div>{" "}<div id="app-viewport" className={state.darkMode ? 'theme-dark' : 'theme-light'} ref={viewportRef}>{" "}<div className="v-shell-container max-w-[1360px] mx-auto px-4 sm:px-7 pt-5 pb-8">{" "}<header dir="rtl" className="v-shell-header flex items-center justify-between gap-4 mb-6">{" "}<button id="brand-home-btn" type="button" className="v-pill-surface rounded-full p-1.5 flex items-center gap-2.5 shadow-xs hover:opacity-95 transition" style={{ paddingInlineEnd: 20, minHeight: 48 }} onClick={() => switchView('overview')}>{" "}<div className="w-9 h-9 rounded-full flex items-center justify-center shadow-inner" style={{
            "background": "radial-gradient(circle at 30% 25%, #ff8a65 0%, #e0491f 75%)"
          }}>{" "}<BrandLogo className="w-6 h-6" fallback={<svg className="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">{" "}<path d="M5 5l7 14 7-14" />{" "}</svg>} />{" "}</div>{" "}<span dir="auto" className="font-display font-bold text-[15px] tracking-tight" style={{
            "color": "var(--text-primary)", maxWidth: 180, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap"}}>{branding.name}</span>{" "}</button>{" "}<nav aria-label="Primary Navigation" style={{ minWidth: 0, flex: '1 1 0%', overflowX: 'auto' }} className="v-pill-surface rounded-full p-1.5 hidden md:flex items-center gap-1 shadow-xs">{" "}<button type="button" data-nav="overview" aria-current={currentView === 'overview' ? 'page' : undefined} onClick={() => switchView('overview')} style={navStyle('overview')} className={"top-nav-btn px-5 py-2 rounded-full text-[13px] font-medium transition-colors duration-150" + (currentView === 'overview' ? ' font-semibold shadow-xs' : '')}>Overview</button>{" "}{catalogNav.map(item => <button key={item.id} type="button" data-nav={item.id} aria-current={catalogActive === item.id ? 'page' : undefined} onClick={item.onOpen} style={navStyle(item.id)} className={"top-nav-btn px-5 py-2 rounded-full text-[13px] font-medium transition-colors duration-150" + (catalogActive === item.id ? ' font-semibold shadow-xs' : '')}>{item.label}</button>)}{onOpenCategories && <button type="button" data-nav="categories" aria-current={categoriesActive ? 'page' : undefined} onClick={onOpenCategories} style={navStyle('categories')} className={"top-nav-btn px-5 py-2 rounded-full text-[13px] font-medium transition-colors duration-150" + (categoriesActive ? ' font-semibold shadow-xs' : '')}>الأقسام</button>}{" "}</nav>{" "}<div className="flex items-center gap-2 sm:gap-3">{" "}<div className="v-pill-surface rounded-full px-2 py-1 flex items-center gap-1 sm:gap-2 shadow-xs">{" "}<button type="button" id="top-search-btn" title="Quick Search" aria-label="Quick Search" className="v-touch-icon-btn hover:opacity-75 transition" style={{
              "color": "var(--text-primary)"
            }} onClick={() => {
              switchView('overview');
              requestAnimationFrame(() => shadowRoot?.getElementById('activity-search-input')?.focus());
            }}>{" "}<svg className="w-[17px] h-[17px]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="11" cy="11" r="7"></circle>{" "}<path strokeLinecap="round" d="M20 20l-3.5-3.5"></path>{" "}</svg>{" "}</button>{" "}<button type="button" id="top-notif-btn" title="Notifications" aria-label="Notifications" className="v-touch-icon-btn relative hover:opacity-75 transition" style={{
              "color": "var(--text-primary)"
            }} onClick={onOpenNotifications || (() => showToast('2 new reward offers available'))}>{" "}<svg className="w-[17px] h-[17px]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 10-2 0v1.1A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>{" "}</svg>{" "}<span className="absolute top-2 right-2 w-2 h-2 rounded-full bg-[#e0491f]" style={{
                "boxShadow": "0 0 0 2px var(--bg-card)"
              }}></span>{" "}</button>{" "}<button type="button" id="top-help-btn" title="Help Center" aria-label="Help Center" className="v-touch-icon-btn hover:opacity-75 transition" style={{
              "color": "var(--text-primary)"
            }} onClick={() => switchView('messages')}>{" "}<svg className="w-[17px] h-[17px]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="9"></circle>{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M9.5 9a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3.5m0 3h.01"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}<button type="button" id="profile-pill-btn" className="v-pill-surface rounded-full p-1.5 flex items-center gap-2.5 shadow-xs hover:opacity-95 transition" style={{ paddingInlineEnd: 14, textAlign: 'start', minHeight: 48 }} onClick={() => showToast('Code XR · hello@codexr.studio')}>{" "}<div className="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center bg-[#f4e3d7] border border-black/5 shrink-0">{" "}<BrandLogo className="w-6 h-6" fallback={<svg viewBox="0 0 64 64" className="w-full h-full">{" "}<rect width="64" height="64" fill="#f3dfd1" />{" "}<path d="M12 64c2-11 10-16 20-16s18 5 20 16H12z" fill="#18151c" />{" "}<path d="M27 48l5 8 5-8z" fill="#ffffff" />{" "}<circle cx="32" cy="28" r="11" fill="#e0b193" />{" "}<path d="M21 26c0-8 5-13 11-13s11 5 11 13c-2-3-5-4-11-4s-9 1-11 4z" fill="#231c18" />{" "}<rect x="24" y="25" width="6.5" height="4.5" rx="1.5" fill="none" stroke="#18151c" strokeWidth="1.6" />{" "}<rect x="33.5" y="25" width="6.5" height="4.5" rx="1.5" fill="none" stroke="#18151c" strokeWidth="1.6" />{" "}<line x1="30.5" y1="27" x2="33.5" y2="27" stroke="#18151c" strokeWidth="1.6" />{" "}<path d="M22.5 30c.5 6 4.5 10 9.5 10s9-4 9.5-10c-2.5 1.5-5.5 2.5-9.5 2.5s-7-1-9.5-2.5z" fill="#231c18" />{" "}<rect x="29.5" y="34" width="5" height="2" rx="1" fill="#e0b193" />{" "}</svg>} />{" "}</div>{" "}<div className="leading-tight hidden sm:block">{" "}<div className="text-[13px] font-semibold tracking-tight" style={{
                "color": "var(--text-primary)"
              }}>Code XR</div>{" "}<div className="text-[11px]" style={{
                "color": "var(--text-secondary)"
              }}>hello@codexr.studio</div>{" "}</div>{" "}<svg className="w-3.5 h-3.5" style={{ marginInlineStart: 4,
              "color": "var(--text-secondary)"
            }} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}</header>{" "}<div dir="rtl" className="v-shell-layout flex gap-6 items-start">{" "}<aside aria-label="Quick Navigation & Actions" className="v-aside-nav flex flex-col items-center gap-4 shrink-0 pt-1">{" "}<div className="v-side-group v-pill-surface rounded-full p-1.5 flex flex-col items-center gap-1.5 shadow-xs w-[44px]">{" "}<button type="button" id="theme-light-btn" title="Light Mode" aria-label="Light Mode" data-label="Light" className="theme-btn-light w-8 h-8 rounded-full flex items-center justify-center cursor-pointer" onClick={event => changeTheme(false, event.currentTarget)} style={{
              backgroundColor: state.darkMode === false ? 'var(--theme-active-circle)' : 'transparent',
              color: state.darkMode === false ? 'var(--text-primary)' : 'var(--text-secondary)'
            }}>{" "}<svg className="w-[17px] h-[17px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="4"></circle>{" "}<path strokeLinecap="round" d="M12 3v2m0 14v2M3 12h2m14 0h2m-2.6-6.4l-1.4 1.4M7 17l-1.4 1.4m0-12.8L7 7m10 10l1.4 1.4"></path>{" "}</svg>{" "}</button>{" "}<button type="button" id="theme-dark-btn" title="Dark Mode" aria-label="Dark Mode" data-label="Dark" className="theme-btn-dark w-8 h-8 rounded-full flex items-center justify-center cursor-pointer" onClick={event => changeTheme(true, event.currentTarget)} style={{
              backgroundColor: state.darkMode === true ? 'var(--theme-active-circle)' : 'transparent',
              color: state.darkMode === true ? 'var(--text-primary)' : 'var(--text-secondary)'
            }}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}<div className="v-side-group v-pill-surface rounded-full p-1.5 flex flex-col items-center gap-2 shadow-xs w-[44px] mt-4">{" "}<button type="button" data-side="overview" title="Overview" aria-label="Overview" data-label="Overview" aria-current={currentView === 'overview' ? 'page' : undefined} className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={() => switchView('overview')} style={navStyle('overview')}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<rect x="3" y="3" width="7" height="7" rx="1.5"></rect>{" "}<rect x="14" y="3" width="7" height="7" rx="1.5"></rect>{" "}<rect x="3" y="14" width="7" height="7" rx="1.5"></rect>{" "}<rect x="14" y="14" width="7" height="7" rx="1.5"></rect>{" "}</svg>{" "}</button>{" "}<button type="button" data-side="messages" title="Messages" aria-label="Messages" data-label="Messages" aria-current={currentView === 'messages' ? 'page' : undefined} className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={() => switchView('messages')} style={navStyle('messages')}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<rect x="3" y="5" width="18" height="14" rx="2.5"></rect>{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M3 7l9 6 9-6"></path>{" "}</svg>{" "}</button>{" "}{catalogNav.map(item => <button key={item.id} type="button" data-side={item.id} aria-label={item.label} title={item.label} data-label={item.label} aria-current={catalogActive === item.id ? 'page' : undefined} className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={item.onOpen} style={navStyle(item.id)}><svg width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{item.id === "products" ? <><path d="M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10"/></> : item.id === "dealers" ? <><path strokeLinecap="round" strokeLinejoin="round" d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path strokeLinecap="round" strokeLinejoin="round" d="M23 21v-2a4 4 0 0 0-3-3.87"/><path strokeLinecap="round" strokeLinejoin="round" d="M16 3.13a4 4 0 0 1 0 7.75"/></> : item.id === "production" ? <><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path strokeLinecap="round" strokeLinejoin="round" d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></> : item.id === "users" ? <><circle cx="9" cy="8" r="3.5"/><path strokeLinecap="round" d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path strokeLinecap="round" d="M16 4.6a3.5 3.5 0 010 6.8M18 14.8c1.9.7 3.2 2.4 3.5 5.2"/></> : item.id === "roles" ? <><path strokeLinecap="round" strokeLinejoin="round" d="M12 3l7.5 3v5.5c0 4.6-3.2 8.3-7.5 9.5-4.3-1.2-7.5-4.9-7.5-9.5V6L12 3z"/><path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4"/></> : item.id === "settings" ? <><circle cx="12" cy="12" r="3"/><path strokeLinecap="round" strokeLinejoin="round" d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/></> : item.id === "orders" ? <><path strokeLinecap="round" strokeLinejoin="round" d="M16 11V7a4 4 0 0 0-8 0v4M5 9h14l1 12H4L5 9z"/></> : item.id === "returns" ? <><path strokeLinecap="round" strokeLinejoin="round" d="M9 14L4 9m0 0l5-5M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v1"/></> : item.id === "wholesale" ? <><path strokeLinecap="round" strokeLinejoin="round" d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></> : item.id === "shipping" ? <><rect x="1" y="3" width="15" height="13" rx="1"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></> : item.id === "pricing" ? <><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></> : item.id === "promos" ? <><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><line x1="13" y1="5" x2="13" y2="19" strokeDasharray="2 2"/></> : item.id === "brands" ? <><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></> : item.id === "suppliers" ? <><path d="M2 20h20M2 10l5-3v5l5-3v5l8-4v10H2V10z"/></> : item.id === "purchasing" ? <><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></> : item.id === "finance" ? <><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="6" x2="12" y2="9"/><line x1="12" y1="15" x2="12" y2="18"/></> : item.id === "shifts" ? <><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></> : item.id === "reports" ? <><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></> : item.id === "notifications" ? <><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h24s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></> : <><path d="M3 3h9l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.5"/></>}</svg></button>)}{onOpenCategories && <button type="button" data-side="categories" aria-label="الأقسام" title="الأقسام" data-label="الأقسام" aria-current={categoriesActive ? 'page' : undefined} className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={onOpenCategories} style={navStyle('categories')}><svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" viewBox="0 0 24 24"><rect x="9" y="3" width="6" height="5" rx="1.5"/><path d="M12 8v5M5 13h14M5 13v3M19 13v3"/><rect x="2" y="16" width="6" height="5" rx="1.5"/><rect x="16" y="16" width="6" height="5" rx="1.5"/></svg></button>}{" "}</div>{" "}<div className="v-side-group v-pill-surface rounded-full p-1.5 flex flex-col items-center gap-1.5 shadow-xs w-[44px] mt-10">{" "}<button type="button" id="side-info-btn" title="System Status" aria-label="System Status" data-label="Status" className="w-8 h-8 rounded-full flex items-center justify-center hover:opacity-75 transition" style={{
              "color": "var(--text-secondary)"
            }} onClick={() => showToast(`${branding.name} · All systems operational`)}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="9"></circle>{" "}<path strokeLinecap="round" d="M12 8h.01M12 11v5"></path>{" "}</svg>{" "}</button>{" "}<button type="button" id="side-logout-btn" title="Log out" aria-label="Log out" data-label="Logout" className="w-8 h-8 rounded-full flex items-center justify-center hover:opacity-75 transition" style={{
              "color": "var(--text-secondary)"
            }} onClick={logout}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4m7 14l5-5-5-5m5 5H9"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}</aside>{" "}<main dir="ltr" className="flex-1 min-w-0 w-full">{catalogActive && <section id={"view-" + catalogActive}>{React.isValidElement(catalogPage) ? React.cloneElement(catalogPage, { darkMode: state.darkMode, theme: state.darkMode ? 'dark' : 'light' }) : catalogPage}</section>}{categoriesActive && <section id="view-categories" aria-label="الأقسام">{React.isValidElement(categoriesPage) ? React.cloneElement(categoriesPage, { darkMode: state.darkMode, theme: state.darkMode ? 'dark' : 'light' }) : categoriesPage}</section>}{" "}<section id="view-overview" className={"view-section space-y-5" + (currentView === 'overview' ? '' : ' hidden')}>{" "}<div className="stagger-item stagger-1 pt-1">{" "}<h1 className="font-display text-[32px] sm:text-[40px] font-medium tracking-[-0.025em] leading-tight" style={{
                "color": "var(--text-primary)"
              }}>Good morning, Code XR</h1>{" "}<p className="text-[13.5px] mt-1" style={{
                "color": "var(--text-secondary)"
              }}>Stay on top of your tasks, monitor progress, and track status.</p>{" "}</div>{" "}<div className="grid grid-cols-1 lg:grid-cols-12 gap-5">{" "}<div className="lg:col-span-4 v-card p-5 flex flex-col justify-between stagger-item stagger-2">{" "}<div>{" "}<div className="flex items-center justify-between">{" "}<span className="text-[14px] font-medium" style={{
                      "color": "var(--text-secondary)"
                    }}>Total Balance</span>{" "}<button type="button" id="currency-switch-btn" aria-label="Switch display currency" className="v-subcard px-3 py-1.5 rounded-full flex items-center gap-1.5 text-[12px] font-semibold hover:opacity-85 transition" style={{ minHeight: 38 }} onClick={cycleCurrency}>{" "}<span id="currency-flag" className="w-4 h-4 rounded-full overflow-hidden inline-flex items-center justify-center text-[10px]">{currency.flag}</span>{" "}<span id="currency-code">{currency.code}</span>{" "}<svg className="w-3 h-3 opacity-60" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7" /></svg>{" "}</button>{" "}</div>{" "}<div className="mt-2 font-display text-[32px] font-bold tracking-tight" id="overview-balance-counter" style={{
                    "color": "var(--text-primary)"
                  }}>{<AnimatedNumber value={currency.balance} start={currency.balance * 0.49} duration={850} trigger={counterEpoch} format={value => currency.symbol + value.toLocaleString("en-US", {
                      minimumFractionDigits: 2,
                      maximumFractionDigits: 2
                    })} />}</div>{" "}<div className="flex items-center gap-2 mt-1.5">{" "}<span className="v-badge-green inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[11px] font-semibold">↑ 5%</span>{" "}<span className="text-[11.5px]" style={{
                      "color": "var(--text-muted)"
                    }}>than last month</span>{" "}</div>{" "}<div className="grid grid-cols-2 gap-3 mt-4">{" "}<button type="button" id="btn-open-transfer" className="v-action-cta py-3 px-4 rounded-full text-[13.5px] font-medium flex items-center justify-center gap-2 transition hover:opacity-90" style={{
                      "background": "var(--pill-active-bg)",
                      "color": "var(--pill-active-text)"
                    }} onClick={() => setModal('transfer')}>{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M7 10l5-5 5 5M12 5v14" /></svg>{" "}<span>Transfer</span>{" "}</button>{" "}<button type="button" id="btn-open-request" className="v-action-cta v-subcard py-3 px-4 rounded-full text-[13.5px] font-medium flex items-center justify-center gap-2 transition hover:opacity-85" style={{
                      "color": "var(--text-primary)"
                    }} onClick={() => setModal('request')}>{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M17 14l-5 5-5-5M12 19V5" /></svg>{" "}<span>Request</span>{" "}</button>{" "}</div>{" "}</div>{" "}<div className="v-subcard p-3.5 mt-5">{" "}<div className="flex items-center gap-2 mb-3">{" "}<span className="text-[12px] font-semibold" style={{
                      "color": "var(--text-primary)"
                    }}>Wallets</span>{" "}<span className="text-[11px]" style={{
                      "color": "var(--text-muted)"
                    }}>| &nbsp;Total 6 wallets</span>{" "}</div>{" "}<div className="v-wallets-grid grid grid-cols-3 gap-2">{" "}<div role="button" tabIndex={0} data-wallet="USD" className="wallet-card v-card !rounded-[13px] p-2.5 cursor-pointer hover:shadow-xs transition" onClick={() => walletClick('USD')} onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); walletClick('USD'); } }}>{" "}<div className="flex items-center justify-between mb-2">{" "}<div className="flex items-center gap-1.5">{" "}<span className="text-xs">🇺🇸</span>{" "}<span className="text-[11px] font-semibold">USD</span>{" "}</div>{" "}<span className="text-[10px]" style={{
                          "color": "var(--text-muted)"
                        }}>⋮</span>{" "}</div>{" "}<div className="text-[12px] font-bold tracking-tight" id="wallet-usd-val">$19,230.51</div>{" "}<div className="text-[10px] mt-0.5 truncate" style={{
                        "color": "var(--text-muted)"
                      }}>Limit is $10k a month</div>{" "}<div className="wallet-status text-[10px] font-semibold text-emerald-500 mt-1.5">Active</div>{" "}</div>{" "}<div role="button" tabIndex={0} data-wallet="EUR" className="wallet-card v-card !rounded-[13px] p-2.5 cursor-pointer hover:shadow-xs transition" onClick={() => walletClick('EUR')} onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); walletClick('EUR'); } }}>{" "}<div className="flex items-center justify-between mb-2">{" "}<div className="flex items-center gap-1.5">{" "}<span className="text-xs">🇩🇪</span>{" "}<span className="text-[11px] font-semibold">EUR</span>{" "}</div>{" "}<span className="text-[10px]" style={{
                          "color": "var(--text-muted)"
                        }}>⋮</span>{" "}</div>{" "}<div className="text-[12px] font-bold tracking-tight" id="wallet-eur-val">€14,160.54</div>{" "}<div className="text-[10px] mt-0.5 truncate" style={{
                        "color": "var(--text-muted)"
                      }}>Limit is €8k a month</div>{" "}<div className="wallet-status text-[10px] font-semibold text-emerald-500 mt-1.5">Active</div>{" "}</div>{" "}<div role="button" tabIndex={0} data-wallet="GBP" className="wallet-card v-card !rounded-[13px] p-2.5 cursor-pointer hover:shadow-xs transition" onClick={() => walletClick('GBP')} onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); walletClick('GBP'); } }}>{" "}<div className="flex items-center justify-between mb-2">{" "}<div className="flex items-center gap-1.5">{" "}<span className="text-xs">🇬🇧</span>{" "}<span className="text-[11px] font-semibold">GBP</span>{" "}</div>{" "}<span className="text-[10px]" style={{
                          "color": "var(--text-muted)"
                        }}>⋮</span>{" "}</div>{" "}<div className="text-[12px] font-bold tracking-tight" id="wallet-gbp-val">£10,054.67</div>{" "}<div className="text-[10px] mt-0.5 truncate" style={{
                        "color": "var(--text-muted)"
                      }}>Limit is £7.5k a month</div>{" "}<div id="gbp-wallet-status" className={'wallet-status text-[10px] font-semibold mt-1.5 ' + (state.gbpActive ? 'text-emerald-500' : 'text-rose-500')}>{state.gbpActive ? 'Active' : 'Inactive'}</div>{" "}</div>{" "}</div>{" "}</div>{" "}</div>{" "}<div className="lg:col-span-4 v-card p-4 grid grid-cols-2 gap-3.5 stagger-item stagger-3">{" "}<div className="v-orange-earnings rounded-[18px] p-4 flex flex-col justify-between shadow-xs">{" "}<div className="flex items-center justify-between">{" "}<span className="text-[12.5px] font-medium text-white/90">Total Earnings</span>{" "}<span className="w-7 h-7 rounded-full bg-white/15 flex items-center justify-center">{" "}<svg className="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<rect x="3" y="6" width="18" height="13" rx="2"></rect>{" "}<path d="M3 10h18"></path>{" "}</svg>{" "}</span>{" "}</div>{" "}<div className="font-display text-[32px] font-bold tracking-tight my-3" id="kpi-earnings">{<AnimatedNumber value={849} start={209} duration={750} trigger={counterEpoch} format={value => '$' + Math.round(value)} />}</div>{" "}<div className="flex items-center gap-2">{" "}<span className="px-2 py-0.5 rounded-full text-[10.5px] font-semibold bg-white/20 text-white">↑ 7%</span>{" "}<span className="text-[11px] text-white/80">This month</span>{" "}</div>{" "}</div>{" "}<div className="v-subcard rounded-[18px] p-4 flex flex-col justify-between">{" "}<div className="flex items-center justify-between">{" "}<span className="text-[12.5px] font-medium" style={{
                      "color": "var(--text-secondary)"
                    }}>Total Spending</span>{" "}<span className="v-icon-well w-7 h-7 rounded-full flex items-center justify-center">{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>{" "}</svg>{" "}</span>{" "}</div>{" "}<div className="font-display text-[32px] font-bold tracking-tight my-3" id="kpi-spending" style={{
                    "color": "var(--text-primary)"
                  }}>{<AnimatedNumber value={586} start={140} duration={750} trigger={counterEpoch} format={value => '$' + Math.round(value)} />}</div>{" "}<div className="flex items-center gap-2">{" "}<span className="v-badge-red px-2 py-0.5 rounded-full text-[10.5px] font-semibold">↓ 5%</span>{" "}<span className="text-[11px]" style={{
                      "color": "var(--text-muted)"
                    }}>This month</span>{" "}</div>{" "}</div>{" "}<div className="v-subcard rounded-[18px] p-4 flex flex-col justify-between">{" "}<div className="flex items-center justify-between">{" "}<span className="text-[12.5px] font-medium" style={{
                      "color": "var(--text-secondary)"
                    }}>Total Income</span>{" "}<span className="v-icon-well w-7 h-7 rounded-full flex items-center justify-center">{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<rect x="4" y="3" width="16" height="18" rx="2"></rect>{" "}<path d="M8 8h8M8 12h8M8 16h4"></path>{" "}</svg>{" "}</span>{" "}</div>{" "}<div className="font-display text-[32px] font-bold tracking-tight my-3" id="kpi-income" style={{
                    "color": "var(--text-primary)"
                  }}>{<AnimatedNumber value={799} start={190} duration={750} trigger={counterEpoch} format={value => '$' + Math.round(value)} />}</div>{" "}<div className="flex items-center gap-2">{" "}<span className="v-badge-green px-2 py-0.5 rounded-full text-[10.5px] font-semibold">↑ 8%</span>{" "}<span className="text-[11px]" style={{
                      "color": "var(--text-muted)"
                    }}>This month</span>{" "}</div>{" "}</div>{" "}<div className="v-subcard rounded-[18px] p-4 flex flex-col justify-between">{" "}<div className="flex items-center justify-between">{" "}<span className="text-[12.5px] font-medium" style={{
                      "color": "var(--text-secondary)"
                    }}>Total Revenue</span>{" "}<span className="v-icon-well w-7 h-7 rounded-full flex items-center justify-center">{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="8"></circle>{" "}<path strokeLinecap="round" d="M12 8v8m-2.5-5.5c0-1 1.1-1.8 2.5-1.8s2.5.8 2.5 1.8-1.1 1.5-2.5 1.8-2.5.8-2.5 1.8 1.1 1.8 2.5 1.8 2.5-.8 2.5-1.8"></path>{" "}</svg>{" "}</span>{" "}</div>{" "}<div className="font-display text-[32px] font-bold tracking-tight my-3" id="kpi-revenue" style={{
                    "color": "var(--text-primary)"
                  }}>{<AnimatedNumber value={561} start={120} duration={750} trigger={counterEpoch} format={value => '$' + Math.round(value)} />}</div>{" "}<div className="flex items-center gap-2">{" "}<span className="v-badge-green px-2 py-0.5 rounded-full text-[10.5px] font-semibold">↑ 4%</span>{" "}<span className="text-[11px]" style={{
                      "color": "var(--text-muted)"
                    }}>This month</span>{" "}</div>{" "}</div>{" "}</div>{" "}<div className="lg:col-span-4 v-card p-5 flex flex-col justify-between stagger-item stagger-4">{" "}<div>{" "}<h2 className="font-display text-[17px] font-semibold tracking-tight" style={{
                    "color": "var(--text-primary)"
                  }}>Total Income</h2>{" "}<p className="text-[11.5px] mt-0.5" style={{
                    "color": "var(--text-muted)"
                  }}>View your income in a certain period of time</p>{" "}</div>{" "}<div className="v-subcard p-4 mt-3.5 flex-1 flex flex-col justify-between">{" "}<div className="flex items-center justify-between mb-3">{" "}<span className="text-[12.5px] font-semibold" style={{
                      "color": "var(--text-primary)"
                    }}>Profit and Loss</span>{" "}<div className="flex items-center gap-3 text-[11px]">{" "}<span className="flex items-center gap-1.5 font-medium" style={{
                        "color": "var(--text-secondary)"
                      }}>{" "}<span className="w-2.5 h-2.5 rounded-xs bg-[#e0491f] inline-block"></span> Profit
                      </span>{" "}<span className="flex items-center gap-1.5 font-medium" style={{
                        "color": "var(--text-secondary)"
                      }}>{" "}<span className="w-2.5 h-2.5 rounded-xs inline-block" style={{
                          "background": "var(--bar-loss)"
                        }}></span> Loss
                      </span>{" "}</div>{" "}</div>{" "}<div className="relative flex items-end gap-2.5 h-[190px] pt-3">{" "}<div className="flex flex-col justify-between h-full text-[10px] pr-1 pb-5 shrink-0" style={{
                      "color": "var(--text-muted)"
                    }}>{" "}<span>50k</span>{" "}<span>40k</span>{" "}<span>30k</span>{" "}<span>20k</span>{" "}<span>10k</span>{" "}<span>00</span>{" "}</div>{" "}<div className="relative flex-1 h-full flex items-end justify-between gap-2 pb-5">{" "}<div className="absolute inset-x-0 top-0 bottom-5 flex flex-col justify-between pointer-events-none">{" "}<div className="v-border-b border-dashed w-full"></div>{" "}<div className="v-border-b border-dashed w-full"></div>{" "}<div className="v-border-b border-dashed w-full"></div>{" "}<div className="v-border-b border-dashed w-full"></div>{" "}<div className="v-border-b border-dashed w-full"></div>{" "}<div className="v-border-b w-full"></div>{" "}</div>{" "}<div id="pnl-bars-container" className="relative z-10 w-full h-full flex items-end justify-between gap-1.5 px-1">{renderPnlBars()}</div>{" "}</div>{" "}</div>{" "}</div>{" "}</div>{" "}</div>{" "}<div className="grid grid-cols-1 lg:grid-cols-12 gap-5">{" "}<div className="lg:col-span-4 v-card p-5 flex flex-col justify-between stagger-item stagger-4">{" "}<div>{" "}<h3 className="font-display text-[15px] font-semibold" style={{
                    "color": "var(--text-primary)"
                  }}>Monthly Spending Limit</h3>{" "}<div className="mt-5 h-3.5 w-full rounded-full overflow-hidden flex items-center gap-1 p-0.5">{" "}<div className="h-full rounded-full bg-[#e0491f] transition-all duration-500" style={{
                      "width": "24%"
                    }}></div>{" "}<div className="h-full flex-1 rounded-r-full spending-hatched-track"></div>{" "}</div>{" "}<div className="flex items-center justify-between mt-3 text-[12px]">{" "}<div>{" "}<span className="font-bold" id="overview-spent-counter" style={{
                        "color": "var(--text-primary)"
                      }}>$1,210.38</span>{" "}<span className="text-[11px] ml-1" style={{
                        "color": "var(--text-muted)"
                      }}>spent out of</span>{" "}</div>{" "}<span className="font-semibold" style={{
                      "color": "var(--text-primary)"
                    }}>$5,500.00</span>{" "}</div>{" "}</div>{" "}<div className="v-subcard mt-5 px-3.5 py-2.5 flex items-center justify-between text-[11.5px]">{" "}<span style={{ "color": "var(--text-secondary)" }}>Automatic limit reset in 9 days</span>{" "}</div>{" "}</div>{" "}<div className="lg:col-span-8 v-card p-5 stagger-item stagger-5">{" "}<div className="flex flex-wrap items-center justify-between gap-3 mb-4">{" "}<h3 className="font-display text-[17px] font-semibold" style={{
                    "color": "var(--text-primary)"
                  }}>Recent Activities</h3>{" "}<div className="flex items-center gap-2.5">{" "}<div className="v-subcard px-3.5 py-2 rounded-full flex items-center gap-2" style={{ minHeight: 40 }}>{" "}<svg className="w-3.5 h-3.5" style={{
                        "color": "var(--text-muted)"
                      }} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="11" cy="11" r="7"></circle>{" "}<path strokeLinecap="round" d="M20 20l-3.5-3.5"></path>{" "}</svg>{" "}<input id="activity-search-input" type="text" placeholder="Search" aria-label="Search activities" className="bg-transparent text-[13px] outline-none w-28 sm:w-36" style={{
                        "color": "var(--text-primary)"
                      }} value={activityQuery} onChange={event => setActivityQuery(event.target.value)} />{" "}</div>{" "}<button type="button" id="activity-filter-btn" className="v-subcard px-4 py-2 rounded-full text-[12.5px] font-medium flex items-center gap-1.5 hover:opacity-85 transition" style={{
                      "color": "var(--text-primary)",
                      minHeight: 40
                    }} onClick={() => {
                      update(next => {
                        next.activities.reverse();
                      });
                      showToast('Sorted recent activities');
                    }}>{" "}<span>Filter</span>{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" d="M4 6h16M7 12h10M10 18h4"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}</div>{" "}<div className="overflow-x-auto">{" "}<table className="w-full text-left border-collapse"><thead><tr className="v-subcard text-[11.5px] font-medium" style={{
                        "color": "var(--text-secondary)"
                      }}><th className="py-2.5 pl-3.5 pr-2 rounded-l-xl w-8">{" "}<input type="checkbox" id="select-all-activities" aria-label="Select all activities" className="w-4 h-4 rounded border-gray-300 accent-[#e0491f] cursor-pointer" checked={filteredActivities.length > 0 && filteredActivities.every(row => selectedActivities.includes(row.id))} onChange={event => setSelectedActivities(event.target.checked ? filteredActivities.map(row => row.id) : [])} />{" "}</th><th className="py-2.5 px-3">Order ID</th><th className="py-2.5 px-3">Activity</th><th className="py-2.5 px-3">Price</th><th className="py-2.5 px-3">Status</th><th className="py-2.5 px-3 rounded-r-xl">Date</th></tr></thead><tbody id="recent-activities-tbody" className="text-[12.5px] v-divide">{renderActivitiesTable()}</tbody></table>{" "}</div>{" "}</div>{" "}</div>{" "}</section>{" "}<section id="view-messages" className={"view-section space-y-5" + (currentView === 'messages' ? '' : ' hidden')}>{" "}<div className="stagger-item stagger-1 pt-1">{" "}<h1 className="font-display text-[32px] sm:text-[40px] font-medium tracking-[-0.025em] leading-tight" style={{
                "color": "var(--text-primary)"
              }}>Messages</h1>{" "}<p className="text-[13.5px] mt-1" style={{
                "color": "var(--text-secondary)"
              }}>Talk to your bank, your team and the people you pay.</p>{" "}</div>{" "}<div className="v-card p-4 sm:p-5 grid grid-cols-1 lg:grid-cols-12 gap-5 min-h-[470px] stagger-item stagger-2">{" "}<div className="lg:col-span-4 flex flex-col v-border-r pr-0 lg:pr-4 pb-4 lg:pb-0">{" "}<div className="v-subcard px-3.5 py-2.5 rounded-full flex items-center gap-2 mb-3" style={{ minHeight: 44 }}>{" "}<svg className="w-4 h-4" style={{
                    "color": "var(--text-muted)"
                  }} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="11" cy="11" r="7"></circle>{" "}<path strokeLinecap="round" d="M20 20l-3.5-3.5"></path>{" "}</svg>{" "}<input id="chat-search-input" type="text" placeholder="Search chats" aria-label="Search chats" className="bg-transparent text-[13.5px] outline-none w-full" style={{
                    "color": "var(--text-primary)"
                  }} value={chatQuery} onChange={event => setChatQuery(event.target.value)} />{" "}</div>{" "}<div className="v-subcard inline-flex self-start p-1 rounded-full mb-3.5">{" "}<button type="button" data-chat-filter="all" onClick={() => selectChatFilter('all')} style={{
                    background: state.chatFilter === 'all' ? 'var(--seg-active-bg)' : 'transparent',
                    color: state.chatFilter === 'all' ? 'var(--text-primary)' : 'var(--text-secondary)',
                    minHeight: 36
                  }} className={"chat-filter-btn px-4 py-1.5 rounded-full text-[12px]" + (state.chatFilter === 'all' ? ' font-semibold shadow-xs' : '')}>All</button>{" "}<button type="button" data-chat-filter="unread" onClick={() => selectChatFilter('unread')} style={{
                    background: state.chatFilter === 'unread' ? 'var(--seg-active-bg)' : 'transparent',
                    color: state.chatFilter === 'unread' ? 'var(--text-primary)' : 'var(--text-secondary)',
                    minHeight: 36
                  }} className={"chat-filter-btn px-4 py-1.5 rounded-full text-[12px] font-medium" + (state.chatFilter === 'unread' ? ' font-semibold shadow-xs' : '')}>Unread</button>{" "}</div>{" "}<div id="chat-threads-list" className="space-y-2 flex-1">{renderChatThreads()}</div>{" "}</div>{" "}<div className="lg:col-span-8 flex flex-col justify-between">{" "}<div className="flex items-center justify-between pb-3.5 v-border-b">{" "}<div className="flex items-center gap-3">{" "}<div id="active-chat-avatar" className="w-9 h-9 rounded-full flex items-center justify-center text-white font-bold text-sm relative" style={{
                      "background": chat.avatarBg
                    }}>{" "}<span>{chat.avatarText}</span>{" "}<span className="w-2.5 h-2.5 rounded-full bg-emerald-500 absolute -bottom-0.5 -right-0.5" style={{
                        "boxShadow": "0 0 0 2px var(--bg-card)"
                      }}></span>{" "}</div>{" "}<div>{" "}<div id="active-chat-name" className="text-[13.5px] font-semibold" style={{
                        "color": "var(--text-primary)"
                      }}>{chat.name}</div>{" "}<div id="active-chat-status" className="text-[11px] font-medium text-emerald-500">{chat.status}</div>{" "}</div>{" "}</div>{" "}<div className="flex items-center gap-2">{" "}<button type="button" id="chat-call-btn" aria-label="Voice call" className="v-touch-icon-btn w-9 h-9 rounded-full v-subcard flex items-center justify-center hover:opacity-80 transition" style={{
                      "color": "var(--text-secondary)"
                    }} onClick={() => showToast('Starting priority voice call...')}>{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M3 5a2 2 0 012-2h2.6a1 1 0 01.95.68l1.1 3.3a1 1 0 01-.27 1.02l-1.5 1.5a12 12 0 005.6 5.6l1.5-1.5a1 1 0 011.02-.27l3.3 1.1a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.82 21 3 14.18 3 6V5z" />{" "}</svg>{" "}</button>{" "}<button type="button" id="chat-more-btn" aria-label="Conversation options" className="v-touch-icon-btn w-9 h-9 rounded-full v-subcard flex items-center justify-center hover:opacity-80 transition" style={{
                      "color": "var(--text-secondary)"
                    }} onClick={() => showToast('Conversation transcript saved')}>{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="12" cy="5" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="12" cy="19" r="1" />{" "}</svg>{" "}</button>{" "}</div>{" "}</div>{" "}<div className="py-4 flex-1 flex flex-col justify-start space-y-3 overflow-y-auto max-h-[320px]">{" "}<div className="self-center v-subcard px-3 py-1 rounded-full text-[10.5px] font-medium" style={{
                    "color": "var(--text-muted)"
                  }}>Today</div>{" "}<div id="chat-messages-feed" className="space-y-3">{renderActiveChat()}</div>{" "}</div>{" "}<form id="chat-send-form" className="v-subcard rounded-full px-3 py-1.5 flex items-center gap-2 mt-2" style={{ minHeight: 48 }} onSubmit={sendMessage}>{" "}<button type="button" id="chat-attach-btn" aria-label="Attach file" className="v-touch-icon-btn hover:opacity-75 transition" style={{
                    "color": "var(--text-muted)"
                  }} onClick={() => showToast('Select an invoice or receipt to attach')}>{" "}<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />{" "}</svg>{" "}</button>{" "}<input id="chat-composer-input" type="text" className="bg-transparent text-[14px] outline-none flex-1" style={{
                    "color": "var(--text-primary)"
                  }} value={composer} onChange={event => setComposer(event.target.value)} placeholder={`Message ${chat.name}...`} />{" "}<button type="button" id="chat-emoji-btn" aria-label="Insert wave emoji" className="v-touch-icon-btn hover:opacity-75 transition" style={{
                    "color": "var(--text-muted)"
                  }} onClick={() => {
                    setComposer(value => value + ' 👋');
                    shadowRoot?.getElementById('chat-composer-input')?.focus();
                  }}>{" "}<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="9" /><path strokeLinecap="round" d="M8.5 14.5s1.5 2 3.5 2 3.5-2 3.5-2M9 9.5h.01M15 9.5h.01" />{" "}</svg>{" "}</button>{" "}<button type="submit" aria-label="Send message" className="v-touch-icon-btn w-9 h-9 rounded-full bg-[#e0491f] text-white flex items-center justify-center hover:opacity-90 transition shrink-0">{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2.2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" />{" "}</svg>{" "}</button>{" "}</form>{" "}</div>{" "}</div>{" "}</section></main>{" "}</div>{" "}</div>{renderMobileNavigation()}</div>{" "}<div id="v-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="v-modal-title" className={'fixed inset-0 bg-black/40 backdrop-blur-xs z-50 items-center justify-center p-4 ' + (modal ? 'flex' : 'hidden')} onClick={event => {
    if (event.target === event.currentTarget) setModal(null);
  }}>{" "}<div className="v-modal-card v-card max-w-md w-full p-6 shadow-2xl" tabIndex={-1} dir={modal === 'navigation' ? 'rtl' : 'ltr'}><div className="v-sheet-handle" aria-hidden="true"></div>{" "}<div className="flex items-center justify-between mb-4">{" "}<h3 id="v-modal-title" className="font-display text-[18px] font-semibold" style={{
          "color": "var(--text-primary)"
        }}>{modalTitle}</h3>{" "}<button type="button" id="v-modal-close" aria-label="Close dialog" className="v-touch-icon-btn w-9 h-9 rounded-full v-subcard flex items-center justify-center text-sm" style={{
          "color": "var(--text-secondary)"
        }} onClick={() => setModal(null)}>✕</button>{" "}</div>{" "}<div id="v-modal-body" className="space-y-3 text-[13px]">{renderModal()}</div>{" "}</div>{" "}</div>{" "}</></>, shadowRoot)}
  </div>;
}

export default FinancialDashboard;

'use client';

import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal, flushSync } from 'react-dom';
import { initialState } from './initial-state';
import { dashboardStyles } from './styles';

function AnimatedNumber({ value, start, duration, trigger, format }) {
  const [display, setDisplay] = useState(start);
  useEffect(() => {
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

/**
 * The supplied Vortix prototype, with its original labels, sample values and visual structure.
 * @param {{ onLogout?: () => void | Promise<void>, categoriesPage?: import("react").ReactNode, categoriesActive?: boolean, onOpenCategories?: () => void, onCloseCategories?: () => void }} props
 */
export function FinancialDashboard({ onLogout, categoriesPage, categoriesActive = false, onOpenCategories, onCloseCategories } = {}) {
  const hostRef = useRef(null);
  const viewportRef = useRef(null);
  const underlayRef = useRef(null);
  const timers = useRef({ toast: null, copied: null, wipe: null });
  const [shadowRoot, setShadowRoot] = useState(null);
  const [state, setState] = useState(() => structuredClone(initialState));
  const currentView = categoriesActive ? 'categories' : state.currentView;
  const [activityQuery, setActivityQuery] = useState('');
  const [activityStatus, setActivityStatus] = useState('all');
  const [selectedActivities, setSelectedActivities] = useState([]);
  const [chatQuery, setChatQuery] = useState('');
  const [composer, setComposer] = useState('');
  const [toast, setToast] = useState('');
  const [copied, setCopied] = useState(false);
  const [modal, setModal] = useState(null);
  const [counterEpoch, setCounterEpoch] = useState(0);
  const [programEpoch, setProgramEpoch] = useState(0);

  useLayoutEffect(() => {
    setShadowRoot(hostRef.current.shadowRoot ?? hostRef.current.attachShadow({ mode: 'open' }));
  }, []);

  useEffect(() => {
    const previousTitle = document.title;
    const previousLanguage = document.documentElement.lang;
    document.title = 'Vortix — Financial Dashboard';
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
      document.title = previousTitle;
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
  }, [state.darkMode]);

  useEffect(() => {
    const section = shadowRoot?.getElementById('view-' + currentView);
    section?.querySelectorAll('.stagger-item').forEach(element => {
      element.style.animation = 'none';
      void element.offsetWidth;
      element.style.animation = '';
    });
  }, [shadowRoot, currentView, counterEpoch, programEpoch]);

  const currency = state.currencies[state.currencyIndex];
  const card = state.cards[state.selectedCardIdx];
  const chat = state.chats.find(item => item.id === state.activeChatId) || state.chats[0];
  const spentPercent = Math.min(Math.round(card.spent / card.limit * 100), 100);
  const limitPercent = (card.limit - 500) / (20000 - 500) * 100;
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
    update(next => { next.currentView = view; });
    if (view === 'overview') setCounterEpoch(value => value + 1);
    if (view === 'program') setProgramEpoch(value => value + 1);
  }
  const navStyle = view => ({ backgroundColor: currentView === view ? 'var(--pill-active-bg)' : 'transparent', color: currentView === view ? 'var(--pill-active-text)' : 'var(--text-secondary)' });
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
  const flipCard = () => update(next => { next.cardFlipped = !next.cardFlipped; });
  function freezeCard() {
    update(next => { next.cards[next.selectedCardIdx].frozen = !next.cards[next.selectedCardIdx].frozen; });
    showToast(card.frozen ? `Card •• ${card.last4} unfrozen` : `Card •• ${card.last4} frozen`);
  }
  async function copyCard() {
    try {
      if (navigator.clipboard) await navigator.clipboard.writeText(card.full.replace(/\s+/g, ''));
      else {
        const textarea = document.createElement('textarea');
        textarea.value = card.full.replace(/\s+/g, '');
        shadowRoot.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
      }
    } catch { /* Preserve the prototype's visual copy feedback. */ }
    clearTimeout(timers.current.copied);
    setCopied(true);
    showToast(`Card number •• ${card.last4} copied`);
    timers.current.copied = setTimeout(() => setCopied(false), 1500);
  }
  const selectTier = tier => update(next => { next.selectedTier = tier; });
  const selectChatFilter = filter => update(next => { next.chatFilter = filter; });
  function toggleOffer(id) {
    const offer = state.offers.find(item => item.id === id);
    update(next => { const target = next.offers.find(item => item.id === id); target.active = !target.active; });
    showToast(offer.active ? `Deactivated "${offer.title}"` : `Activated "${offer.title}"`);
  }
  function selectChat(id) {
    update(next => { next.activeChatId = id; next.chats.find(item => item.id === id).unread = 0; });
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
    snapshot.removeAttribute('id');
    snapshot.style.clipPath = 'none';
    snapshot.style.width = viewport.offsetWidth + 'px';
    snapshot.style.transform = `translateY(-${scrollY}px)`;
    snapshot.querySelectorAll('[id]').forEach(element => element.removeAttribute('id'));
    underlay.replaceChildren(snapshot);
    underlay.classList.remove('hidden');
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

  const modalTitle = { transfer: 'Transfer Funds', request: 'Request Payment', newcard: 'Issue New Virtual Card', redeem: 'Redeem Rewards Points' }[modal] ?? 'Quick Transfer';
  function confirmModal() {
    if (modal === 'redeem' && state.points >= 2500) {
      update(next => {
        next.points -= 2500;
        next.pointsHistory.unshift({ title: 'Redeemed — Statement Credit', date: 'Just now', pts: '-2,500', positive: false, type: 'gift' });
      });
    }
    showToast({ transfer: 'Transfer sent successfully', request: 'Payment request link copied', newcard: 'New virtual card issued', redeem: 'Redeemed 2,500 points for $25 credit' }[modal]);
    setModal(null);
  }
  function renderModal() {
    if (!modal) return null;
    if (modal === 'redeem') return <>
      <p style={{ color: 'var(--text-secondary)' }}>You have <strong>{state.points.toLocaleString()} points</strong> (≈ $125.00 statement credit).</p>
      <button type="button" id="modal-confirm-redeem" className="w-full py-2.5 rounded-full font-semibold mt-3 bg-[#e0491f] text-white" onClick={confirmModal}>Redeem 2,500 pts ($25 Credit)</button>
    </>;
    const fields = modal === 'transfer'
      ? [['Recipient', 'text', 'Alex Rivera (alex@studio.io)', 'modal-transfer-to'], ['Amount (USD)', 'number', '1250', 'modal-transfer-amt']]
      : modal === 'request' ? [['Client Email', 'email', 'billing@acme.corp'], ['Amount (USD)', 'number', '4800']]
      : [['Card Label', 'text', 'Cloud & SaaS Subscriptions'], ['Monthly Limit', 'number', '3000']];
    return <>
      {fields.map(([label, type, value, id]) => <div key={label}>
        <label className="text-[11.5px]" style={{ color: 'var(--text-secondary)' }}>{label}</label>
        <input id={id} type={type} defaultValue={value} className="v-subcard w-full px-3.5 py-2 rounded-xl mt-1 outline-none" />
      </div>)}
      <button type="button" id={'modal-confirm-' + modal} className={'w-full py-2.5 rounded-full font-semibold mt-2' + (modal === 'request' ? '' : ' bg-[#e0491f] text-white')} style={modal === 'request' ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' } : undefined} onClick={confirmModal}>
        {{ transfer: 'Send Instant Transfer', request: 'Create Payment Link', newcard: 'Issue Card Instantly' }[modal]}
      </button>
    </>;
  }

  // ==================== OVERVIEW: PNL BAR CHART & RECENT ACTIVITIES ====================
function renderPnlBars() {
  return state.pnlMonths.map((item, index) => <Fragment key={index}>{" "}<div className="group relative flex-1 h-full flex flex-col items-center justify-end">{" "}<div className="opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity absolute -top-8 z-20 px-2 py-1 rounded-md text-[10px] font-semibold whitespace-nowrap shadow-md" style={{
        "background": "var(--pill-active-bg)",
        "color": "var(--pill-active-text)"
      }}>{"" + " P: " + item.profitVal + " \xB7 L: " + item.lossVal + " "}</div>{" "}<div className="w-full max-w-[26px] h-full flex flex-col justify-end gap-[3px]">{" "}{item.profitPct > 0 ? <><div className="w-full rounded-[6px] bar-profit-hatched transition-all duration-500" style={{
            "height": "" + item.profitPct + "%"
          }}></div></> : ''}{" "}<div className="w-full rounded-[6px] transition-all duration-500" style={{
          "height": "" + item.lossPct + "%",
          "background": "var(--bar-loss)"
        }}></div>{" "}</div>{" "}<span className="text-[10px] mt-1.5 font-medium" style={{
        "color": "var(--text-muted)"
      }}>{"" + item.month}</span>{" "}</div>{" "}</Fragment>);
}

function renderActivitiesTable() {
  const q = activityQuery.toLowerCase();
  const rows = state.activities.filter(a => a.id.toLowerCase().includes(q) || a.name.toLowerCase().includes(q) || a.status.toLowerCase().includes(q));
  return rows.map((r, index) => <Fragment key={r.id}><tr className="hover:opacity-90 transition"><td className="py-3 pl-3.5 pr-2">{" "}<input type="checkbox" className="row-activity-check rounded border-gray-300 accent-[#e0491f] cursor-pointer" checked={selectedActivities.includes(r.id)} onChange={event => setSelectedActivities(ids => event.target.checked ? [...ids, r.id] : ids.filter(id => id !== r.id))} />{" "}</td><td className="py-3 px-3 font-medium" style={{
        "color": "var(--text-secondary)"
      }}>{"" + r.id}</td><td className="py-3 px-3">{" "}<div className="flex items-center gap-2.5">{" "}<span className="w-6 h-6 rounded-lg flex items-center justify-center text-white text-[11px] font-bold shrink-0" style={{
            "background": r.color
          }}>A</span>{" "}<span className="font-semibold" style={{
            "color": "var(--text-primary)"
          }}>{"" + r.name}</span>{" "}</div>{" "}</td><td className="py-3 px-3 font-semibold" style={{
        "color": "var(--text-primary)"
      }}>{"" + r.price}</td><td className="py-3 px-3">{" "}<span className={"inline-flex items-center gap-1.5 text-[12px] font-medium " + (r.status === 'Completed' ? 'text-emerald-500' : 'text-amber-500') + ""}>{" "}<span className={"w-1.5 h-1.5 rounded-full " + (r.status === 'Completed' ? 'bg-emerald-500' : 'bg-amber-500') + ""}></span>{" "}{r.status}{" "}</span>{" "}</td><td className="py-3 px-3 flex items-center justify-between" style={{
        "color": "var(--text-secondary)"
      }}>{" "}<span>{"" + r.date}</span>{" "}<button type="button" data-order-id={r.id} className="act-row-more text-xs px-1.5 opacity-60 hover:opacity-100">•••</button>{" "}</td></tr></Fragment>);
}

function renderFullActivityView(filterStatus = 'all') {
  const items = filterStatus === 'all' ? state.activities : state.activities.filter(a => a.status === filterStatus);
  return items.map((r, index) => <Fragment key={r.id}><tr><td className="py-3.5 px-4 font-medium" style={{
        "color": "var(--text-secondary)"
      }}>{"" + r.id}</td><td className="py-3.5 px-4 font-semibold" style={{
        "color": "var(--text-primary)"
      }}>{"" + r.name}</td><td className="py-3.5 px-4"><span className="v-subcard px-2.5 py-1 rounded-full text-[11px]">{"" + r.category}</span></td><td className="py-3.5 px-4 font-semibold" style={{
        "color": "var(--text-primary)"
      }}>{"" + r.price}</td><td className="py-3.5 px-4">{" "}<span className={"inline-flex items-center gap-1.5 text-[12px] font-medium " + (r.status === 'Completed' ? 'text-emerald-500' : 'text-amber-500') + ""}>{" "}<span className={"w-1.5 h-1.5 rounded-full " + (r.status === 'Completed' ? 'bg-emerald-500' : 'bg-amber-500') + ""}></span>{" "}{r.status}{" "}</span>{" "}</td><td className="py-3.5 px-4" style={{
        "color": "var(--text-secondary)"
      }}>{"" + r.date}</td></tr></Fragment>);
}

// ==================== MANAGE VIEW: CARDS & CONTROLS ====================

// ==================== PROGRAM VIEW: TIERS, OFFERS & POINTS HISTORY ====================
function renderTierBenefits() {
  const tierInfo = state.tierData[state.selectedTier];
  return tierInfo.benefits.map((b, index) => <Fragment key={b}>{" "}<li className="flex items-center gap-3">{" "}<span className="w-5 h-5 rounded-full flex items-center justify-center text-[11px] font-bold shrink-0" style={{
        "background": "var(--accent-orange-light)",
        "color": "var(--accent-orange)"
      }}>✓</span>{" "}<span style={{
        "color": "var(--text-primary)"
      }}>{b}</span>{" "}</li>{" "}</Fragment>);
}

function getOfferIconSvg(type) {
  if (type === 'plane') {
    return <><svg className="w-4 h-4 text-[#e0491f]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" /></svg></>;
  }
  if (type === 'cart') {
    return <><svg className="w-4 h-4 text-[#e0491f]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1" /><circle cx="20" cy="21" r="1" /><path strokeLinecap="round" strokeLinejoin="round" d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6" /></svg></>;
  }
  return <><svg className="w-4 h-4 text-[#e0491f]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M18 8h1a4 4 0 010 8h-1M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8zm4-7v3m4-3v3m4-3v3" /></svg></>;
}

function renderOffers() {
  return state.offers.map((o, index) => {
    const cardStyle = {
      border: o.active ? '1.5px solid rgba(224, 73, 31, 0.65)' : '1.5px solid transparent',
      background: o.active ? 'var(--offer-active-bg)' : 'var(--bg-subcard)'
    };
    const btnHtml = o.active ? <><button type="button" data-offer-id={o.id} className="offer-toggle-btn px-3.5 py-1.5 rounded-full text-[11.5px] font-semibold bg-[#e0491f] text-white transition hover:opacity-90" onClick={() => toggleOffer(o.id)}>✓ Activated</button></> : <><button type="button" data-offer-id={o.id} className="offer-toggle-btn px-4 py-1.5 rounded-full text-[11.5px] font-semibold transition hover:opacity-90" style={{
        "background": "var(--pill-active-bg)",
        "color": "var(--pill-active-text)"
      }} onClick={() => toggleOffer(o.id)}>Activate</button></>;
    return <Fragment key={o.id}>{" "}<div className="rounded-[18px] p-4 flex flex-col justify-between min-h-[168px]" style={cardStyle}>{" "}<div>{" "}<div className="w-9 h-9 rounded-xl flex items-center justify-center mb-3" style={{
            "background": "var(--offer-icon-bg)"
          }}>{" "}{getOfferIconSvg(o.icon)}{" "}</div>{" "}<div className="text-[13.5px] font-semibold" style={{
            "color": "var(--text-primary)"
          }}>{"" + o.title}</div>{" "}<p className="text-[11.5px] mt-1 leading-relaxed" style={{
            "color": "var(--text-muted)"
          }}>{"" + o.desc}</p>{" "}</div>{" "}<div className="flex items-center justify-between mt-4 pt-1">{" "}<span className="text-[11px]" style={{
            "color": "var(--text-muted)"
          }}>{"" + o.meta}</span>{" "}{btnHtml}{" "}</div>{" "}</div>{" "}</Fragment>;
  });
}

function renderPointsHistory() {
  return state.pointsHistory.map((item, index) => <Fragment key={index}>{" "}<div className="py-3 flex items-center justify-between gap-3">{" "}<div className="flex items-center gap-3">{" "}<div className="w-8 h-8 rounded-full flex items-center justify-center text-xs shrink-0" style={{
          "background": item.type === 'star' ? 'var(--accent-orange-light)' : 'var(--bg-subcard)',
          "color": item.type === 'star' ? 'var(--accent-orange)' : 'var(--text-secondary)'
        }}>{" "}{item.type === 'star' ? '☆' : '🎁'}{" "}</div>{" "}<div>{" "}<div className="text-[13px] font-semibold" style={{
            "color": "var(--text-primary)"
          }}>{"" + item.title}</div>{" "}<div className="text-[11px]" style={{
            "color": "var(--text-muted)"
          }}>{"" + item.date}</div>{" "}</div>{" "}</div>{" "}<span className={"text-[13px] font-bold " + (item.positive ? 'text-emerald-500' : 'text-[#e0491f]') + ""}>{"" + item.pts}</span>{" "}</div>{" "}</Fragment>);
}

// ==================== MESSAGES VIEW ====================

// ==================== MESSAGES VIEW ====================
function renderChatThreads() {
  const q = chatQuery.toLowerCase();
  const threads = state.chats.filter(c => {
    if (state.chatFilter === 'unread' && c.unread === 0) return false;
    return c.name.toLowerCase().includes(q);
  });
  return threads.map((c, index) => {
    const isSelected = c.id === state.activeChatId;
    const lastMsg = c.messages[c.messages.length - 1]?.text || '';
    return <Fragment key={c.id}>{" "}<div data-chat-id={c.id} className={"chat-thread-item relative rounded-2xl p-3 flex items-center justify-between gap-2.5 cursor-pointer transition " + (isSelected ? 'v-subcard' : 'hover:opacity-85') + ""} onClick={() => selectChat(c.id)}>{" "}{isSelected ? <><span className="w-1 h-7 rounded-full bg-[#e0491f] absolute left-0"></span></> : ''}{" "}<div className="flex items-center gap-3 min-w-0">{" "}<div className="w-9 h-9 rounded-full flex items-center justify-center text-white font-bold text-xs shrink-0 relative" style={{
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
      return <Fragment key={index}>{" "}<div className="max-w-[360px] v-subcard rounded-2xl rounded-tl-xs px-4 py-2.5 text-[12.5px] leading-relaxed" style={{
          "color": "var(--text-primary)"
        }}>{" "}<div>{"" + m.text}</div>{" "}<div className="text-[10px] text-right mt-1" style={{
            "color": "var(--text-muted)"
          }}>{"" + m.time}</div>{" "}</div>{" "}</Fragment>;
    }
    return <Fragment key={index}>{" "}<div className="max-w-[360px] ml-auto rounded-2xl rounded-tr-xs px-4 py-2.5 text-[12.5px] leading-relaxed bg-[#e0491f] text-white">{" "}<div>{"" + m.text}</div>{" "}<div className="text-[10px] text-right mt-1 text-white/75">{"" + m.time}</div>{" "}</div>{" "}</Fragment>;
  });
}

// ==================== MODAL HELPERS ====================

  return <div ref={hostRef} data-vortix-host="" dir="ltr" lang="en">
    {shadowRoot && createPortal(<><style>{dashboardStyles}</style><>{" "}<div id="theme-wipe-overlay" className="hidden" ref={underlayRef}></div>{" "}<div id="v-toast" className={'fixed bottom-6 right-6 z-50 pointer-events-none transition-all duration-300 ' + (toast ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2')}>{" "}<div className="px-4 py-2.5 rounded-full shadow-lg text-xs font-medium flex items-center gap-2" style={{
      "background": "var(--pill-active-bg)",
      "color": "var(--pill-active-text)"
    }}>{" "}<span id="v-toast-dot" className="w-2 h-2 rounded-full bg-[#e0491f]"></span>{" "}<span id="v-toast-text">{toast || "Action completed"}</span>{" "}</div>{" "}</div>{" "}<div id="app-viewport" className={state.darkMode ? 'theme-dark' : 'theme-light'} ref={viewportRef}>{" "}<div className="max-w-[1360px] mx-auto px-4 sm:px-7 pt-5 pb-8">{" "}<header dir="rtl" className="flex items-center justify-between gap-4 mb-6">{" "}<button id="brand-home-btn" type="button" className="v-pill-surface rounded-full p-1.5 flex items-center gap-2.5 shadow-xs hover:opacity-95 transition" style={{ paddingInlineEnd: 20 }} onClick={() => switchView('overview')}>{" "}<div className="w-9 h-9 rounded-full flex items-center justify-center shadow-inner" style={{
            "background": "radial-gradient(circle at 30% 25%, #ff8a65 0%, #e0491f 75%)"
          }}>{" "}<svg className="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">{" "}<path d="M5 5l7 14 7-14" />{" "}</svg>{" "}</div>{" "}<span className="font-display font-bold text-[15px] tracking-tight" style={{
            "color": "var(--text-primary)"
          }}>Vortix</span>{" "}</button>{" "}<nav aria-label="Primary Navigation" className="v-pill-surface rounded-full p-1.5 hidden md:flex items-center gap-1 shadow-xs">{" "}<button type="button" data-nav="overview" onClick={() => switchView('overview')} style={navStyle('overview')} className={"top-nav-btn px-5 py-2 rounded-full text-[13px] font-medium transition-colors duration-150" + (currentView === 'overview' ? ' font-semibold shadow-xs' : '')}>Overview</button>{" "}<button type="button" data-nav="activity" onClick={() => switchView('activity')} style={navStyle('activity')} className={"top-nav-btn px-5 py-2 rounded-full text-[13px] font-medium transition-colors duration-150" + (currentView === 'activity' ? ' font-semibold shadow-xs' : '')}>Activity</button>{" "}<button type="button" data-nav="manage" onClick={() => switchView('manage')} style={navStyle('manage')} className={"top-nav-btn px-5 py-2 rounded-full text-[13px] font-medium transition-colors duration-150" + (currentView === 'manage' ? ' font-semibold shadow-xs' : '')}>Manage</button>{" "}<button type="button" data-nav="program" onClick={() => switchView('program')} style={navStyle('program')} className={"top-nav-btn px-5 py-2 rounded-full text-[13px] font-medium transition-colors duration-150" + (currentView === 'program' ? ' font-semibold shadow-xs' : '')}>Program</button>{" "}<button type="button" data-nav="account" onClick={() => switchView('account')} style={navStyle('account')} className={"top-nav-btn px-5 py-2 rounded-full text-[13px] font-medium transition-colors duration-150" + (currentView === 'account' ? ' font-semibold shadow-xs' : '')}>Account</button>{" "}{onOpenCategories && <button type="button" data-nav="categories" onClick={onOpenCategories} style={navStyle('categories')} className={"top-nav-btn px-5 py-2 rounded-full text-[13px] font-medium transition-colors duration-150" + (categoriesActive ? ' font-semibold shadow-xs' : '')}>الأقسام</button>}{" "}<button type="button" data-nav="reports" onClick={() => switchView('reports')} style={navStyle('reports')} className={"top-nav-btn px-5 py-2 rounded-full text-[13px] font-medium transition-colors duration-150" + (currentView === 'reports' ? ' font-semibold shadow-xs' : '')}>Reports</button>{" "}</nav>{" "}<div className="flex items-center gap-3">{" "}<div className="v-pill-surface rounded-full px-4 py-2.5 flex items-center gap-4 shadow-xs">{" "}<button type="button" id="top-search-btn" title="Quick Search" className="hover:opacity-75 transition" style={{
              "color": "var(--text-primary)"
            }} onClick={() => {
              switchView('overview');
              requestAnimationFrame(() => shadowRoot?.getElementById('activity-search-input')?.focus());
            }}>{" "}<svg className="w-[17px] h-[17px]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="11" cy="11" r="7"></circle>{" "}<path strokeLinecap="round" d="M20 20l-3.5-3.5"></path>{" "}</svg>{" "}</button>{" "}<button type="button" id="top-notif-btn" title="Notifications" className="relative hover:opacity-75 transition" style={{
              "color": "var(--text-primary)"
            }} onClick={() => showToast('2 new reward offers available')}>{" "}<svg className="w-[17px] h-[17px]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 10-2 0v1.1A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>{" "}</svg>{" "}<span className="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-[#e0491f]" style={{
                "boxShadow": "0 0 0 2px var(--bg-card)"
              }}></span>{" "}</button>{" "}<button type="button" id="top-help-btn" title="Help Center" className="hover:opacity-75 transition" style={{
              "color": "var(--text-primary)"
            }} onClick={() => switchView('messages')}>{" "}<svg className="w-[17px] h-[17px]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="9"></circle>{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M9.5 9a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3.5m0 3h.01"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}<button type="button" id="profile-pill-btn" className="v-pill-surface rounded-full p-1.5 flex items-center gap-2.5 shadow-xs hover:opacity-95 transition" style={{ paddingInlineEnd: 16, textAlign: 'start' }} onClick={() => switchView('account')}>{" "}<div className="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center bg-[#f4e3d7] border border-black/5 shrink-0">{" "}<svg viewBox="0 0 64 64" className="w-full h-full">{" "}<rect width="64" height="64" fill="#f3dfd1" />{" "}<path d="M12 64c2-11 10-16 20-16s18 5 20 16H12z" fill="#18151c" />{" "}<path d="M27 48l5 8 5-8z" fill="#ffffff" />{" "}<circle cx="32" cy="28" r="11" fill="#e0b193" />{" "}<path d="M21 26c0-8 5-13 11-13s11 5 11 13c-2-3-5-4-11-4s-9 1-11 4z" fill="#231c18" />{" "}<rect x="24" y="25" width="6.5" height="4.5" rx="1.5" fill="none" stroke="#18151c" strokeWidth="1.6" />{" "}<rect x="33.5" y="25" width="6.5" height="4.5" rx="1.5" fill="none" stroke="#18151c" strokeWidth="1.6" />{" "}<line x1="30.5" y1="27" x2="33.5" y2="27" stroke="#18151c" strokeWidth="1.6" />{" "}<path d="M22.5 30c.5 6 4.5 10 9.5 10s9-4 9.5-10c-2.5 1.5-5.5 2.5-9.5 2.5s-7-1-9.5-2.5z" fill="#231c18" />{" "}<rect x="29.5" y="34" width="5" height="2" rx="1" fill="#e0b193" />{" "}</svg>{" "}</div>{" "}<div className="leading-tight hidden sm:block">{" "}<div className="text-[13px] font-semibold tracking-tight" style={{
                "color": "var(--text-primary)"
              }}>Code XR</div>{" "}<div className="text-[11px]" style={{
                "color": "var(--text-secondary)"
              }}>hello@codexr.studio</div>{" "}</div>{" "}<svg className="w-3.5 h-3.5" style={{ marginInlineStart: 4,
              "color": "var(--text-secondary)"
            }} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}</header>{" "}<div dir="rtl" className="flex gap-6 items-start">{" "}<aside className="flex flex-col items-center gap-4 shrink-0 pt-1">{" "}<div className="v-pill-surface rounded-full p-1.5 flex flex-col items-center gap-1.5 shadow-xs w-[44px]">{" "}<button type="button" id="theme-light-btn" title="Light Mode" className="theme-btn-light w-8 h-8 rounded-full flex items-center justify-center cursor-pointer" onClick={event => changeTheme(false, event.currentTarget)} style={{
              backgroundColor: state.darkMode === false ? 'var(--theme-active-circle)' : 'transparent',
              color: state.darkMode === false ? 'var(--text-primary)' : 'var(--text-secondary)'
            }}>{" "}<svg className="w-[17px] h-[17px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="4"></circle>{" "}<path strokeLinecap="round" d="M12 3v2m0 14v2M3 12h2m14 0h2m-2.6-6.4l-1.4 1.4M7 17l-1.4 1.4m0-12.8L7 7m10 10l1.4 1.4"></path>{" "}</svg>{" "}</button>{" "}<button type="button" id="theme-dark-btn" title="Dark Mode" className="theme-btn-dark w-8 h-8 rounded-full flex items-center justify-center cursor-pointer" onClick={event => changeTheme(true, event.currentTarget)} style={{
              backgroundColor: state.darkMode === true ? 'var(--theme-active-circle)' : 'transparent',
              color: state.darkMode === true ? 'var(--text-primary)' : 'var(--text-secondary)'
            }}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}<div className="v-pill-surface rounded-full p-1.5 flex flex-col items-center gap-2 shadow-xs w-[44px] mt-4">{" "}<button type="button" data-side="overview" title="Overview" className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={() => switchView('overview')} style={navStyle('overview')}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<rect x="3" y="3" width="7" height="7" rx="1.5"></rect>{" "}<rect x="14" y="3" width="7" height="7" rx="1.5"></rect>{" "}<rect x="3" y="14" width="7" height="7" rx="1.5"></rect>{" "}<rect x="14" y="14" width="7" height="7" rx="1.5"></rect>{" "}</svg>{" "}</button>{" "}<button type="button" data-side="activity" title="Activity" className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={() => switchView('activity')} style={navStyle('activity')}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<rect x="3" y="4" width="18" height="17" rx="2.5"></rect>{" "}<path strokeLinecap="round" d="M16 2v4M8 2v4M3 10h18"></path>{" "}</svg>{" "}</button>{" "}<button type="button" data-side="messages" title="Messages" className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={() => switchView('messages')} style={navStyle('messages')}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<rect x="3" y="5" width="18" height="14" rx="2.5"></rect>{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M3 7l9 6 9-6"></path>{" "}</svg>{" "}</button>{" "}<button type="button" data-side="manage" title="Manage Cards" className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={() => switchView('manage')} style={navStyle('manage')}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<rect x="4" y="3" width="16" height="18" rx="2.5"></rect>{" "}<path strokeLinecap="round" d="M8 8h8M8 12h8M8 16h5"></path>{" "}</svg>{" "}</button>{" "}<button type="button" data-side="account" title="Account & Team" className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={() => switchView('account')} style={navStyle('account')}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<circle cx="9" cy="8" r="3.5"></circle>{" "}<path strokeLinecap="round" d="M3 19c0-3.3 2.7-6 6-6s6 2.7 6 6"></path>{" "}<circle cx="17" cy="9" r="2.5"></circle>{" "}<path strokeLinecap="round" d="M16 13.5c2.5.4 4.5 2.5 4.5 5.5"></path>{" "}</svg>{" "}</button>{" "}<button type="button" data-side="program" title="Rewards Program" className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={() => switchView('program')} style={navStyle('program')}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"></path>{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M12 12l8-4.5M12 12v9M12 12L4 7.5"></path>{" "}</svg>{" "}</button>{" "}{onOpenCategories && <button type="button" data-side="categories" aria-label="الأقسام" title="الأقسام" className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={onOpenCategories} style={navStyle('categories')}><svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" viewBox="0 0 24 24"><rect x="9" y="3" width="6" height="5" rx="1.5"/><path d="M12 8v5M5 13h14M5 13v3M19 13v3"/><rect x="2" y="16" width="6" height="5" rx="1.5"/><rect x="16" y="16" width="6" height="5" rx="1.5"/></svg></button>}{" "}<button type="button" data-side="reports" title="Reports & Settings" className="side-nav-btn w-8 h-8 rounded-full flex items-center justify-center" onClick={() => switchView('reports')} style={navStyle('reports')}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="3"></circle>{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}<div className="v-pill-surface rounded-full p-1.5 flex flex-col items-center gap-1.5 shadow-xs w-[44px] mt-10">{" "}<button type="button" id="side-info-btn" title="System Status" className="w-8 h-8 rounded-full flex items-center justify-center hover:opacity-75 transition" style={{
              "color": "var(--text-secondary)"
            }} onClick={() => showToast('Vortix v2.4 · All systems operational')}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="9"></circle>{" "}<path strokeLinecap="round" d="M12 8h.01M12 11v5"></path>{" "}</svg>{" "}</button>{" "}<button type="button" id="side-logout-btn" title="Log out" className="w-8 h-8 rounded-full flex items-center justify-center hover:opacity-75 transition" style={{
              "color": "var(--text-secondary)"
            }} onClick={logout}>{" "}<svg className="w-[16px] h-[16px]" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4m7 14l5-5-5-5m5 5H9"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}</aside>{" "}<main dir="ltr" className="flex-1 min-w-0">{categoriesActive && <section id="view-categories" aria-label="الأقسام">{categoriesPage}</section>}{" "}<section id="view-overview" className={"view-section space-y-5" + (currentView === 'overview' ? '' : ' hidden')}>{" "}<div className="stagger-item stagger-1 pt-1">{" "}<h1 className="font-display text-[36px] sm:text-[40px] font-medium tracking-[-0.025em] leading-tight" style={{
                "color": "var(--text-primary)"
              }}>Good morning, Code XR</h1>{" "}<p className="text-[13.5px] mt-1" style={{
                "color": "var(--text-secondary)"
              }}>Stay on top of your tasks, monitor progress, and track status.</p>{" "}</div>{" "}<div className="grid grid-cols-1 lg:grid-cols-12 gap-5">{" "}<div className="lg:col-span-4 v-card p-5 flex flex-col justify-between stagger-item stagger-2">{" "}<div>{" "}<div className="flex items-center justify-between">{" "}<span className="text-[14px] font-medium" style={{
                      "color": "var(--text-secondary)"
                    }}>Total Balance</span>{" "}<button type="button" id="currency-switch-btn" className="v-subcard px-2.5 py-1 rounded-full flex items-center gap-1.5 text-[11.5px] font-semibold hover:opacity-85 transition" onClick={cycleCurrency}>{" "}<span id="currency-flag" className="w-4 h-4 rounded-full overflow-hidden inline-flex items-center justify-center text-[10px]">{currency.flag}</span>{" "}<span id="currency-code">{currency.code}</span>{" "}<svg className="w-3 h-3 opacity-60" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7" /></svg>{" "}</button>{" "}</div>{" "}<div className="mt-2 font-display text-[32px] font-bold tracking-tight" id="overview-balance-counter" style={{
                    "color": "var(--text-primary)"
                  }}>{<AnimatedNumber value={currency.balance} start={currency.balance * 0.49} duration={850} trigger={counterEpoch} format={value => currency.symbol + value.toLocaleString("en-US", {
                      minimumFractionDigits: 2,
                      maximumFractionDigits: 2
                    })} />}</div>{" "}<div className="flex items-center gap-2 mt-1.5">{" "}<span className="v-badge-green inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[11px] font-semibold">↑ 5%</span>{" "}<span className="text-[11.5px]" style={{
                      "color": "var(--text-muted)"
                    }}>than last month</span>{" "}</div>{" "}<div className="grid grid-cols-2 gap-3 mt-4">{" "}<button type="button" id="btn-open-transfer" className="py-2.5 px-4 rounded-full text-[13px] font-medium flex items-center justify-center gap-2 transition hover:opacity-90" style={{
                      "background": "var(--pill-active-bg)",
                      "color": "var(--pill-active-text)"
                    }} onClick={() => setModal('transfer')}>{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M7 10l5-5 5 5M12 5v14" /></svg>{" "}<span>Transfer</span>{" "}</button>{" "}<button type="button" id="btn-open-request" className="v-subcard py-2.5 px-4 rounded-full text-[13px] font-medium flex items-center justify-center gap-2 transition hover:opacity-85" style={{
                      "color": "var(--text-primary)"
                    }} onClick={() => setModal('request')}>{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M17 14l-5 5-5-5M12 19V5" /></svg>{" "}<span>Request</span>{" "}</button>{" "}</div>{" "}</div>{" "}<div className="v-subcard p-3.5 mt-5">{" "}<div className="flex items-center gap-2 mb-3">{" "}<span className="text-[12px] font-semibold" style={{
                      "color": "var(--text-primary)"
                    }}>Wallets</span>{" "}<span className="text-[11px]" style={{
                      "color": "var(--text-muted)"
                    }}>| &nbsp;Total 6 wallets</span>{" "}</div>{" "}<div className="grid grid-cols-3 gap-2">{" "}<div data-wallet="USD" className="wallet-card v-card !rounded-[13px] p-2.5 cursor-pointer hover:shadow-xs transition" onClick={() => walletClick('USD')}>{" "}<div className="flex items-center justify-between mb-2">{" "}<div className="flex items-center gap-1.5">{" "}<span className="text-xs">🇺🇸</span>{" "}<span className="text-[11px] font-semibold">USD</span>{" "}</div>{" "}<span className="text-[10px]" style={{
                          "color": "var(--text-muted)"
                        }}>⋮</span>{" "}</div>{" "}<div className="text-[12px] font-bold tracking-tight" id="wallet-usd-val">$19,230.51</div>{" "}<div className="text-[9px] mt-0.5 truncate" style={{
                        "color": "var(--text-muted)"
                      }}>Limit is $10k a month</div>{" "}<div className="wallet-status text-[9.5px] font-semibold text-emerald-500 mt-1.5">Active</div>{" "}</div>{" "}<div data-wallet="EUR" className="wallet-card v-card !rounded-[13px] p-2.5 cursor-pointer hover:shadow-xs transition" onClick={() => walletClick('EUR')}>{" "}<div className="flex items-center justify-between mb-2">{" "}<div className="flex items-center gap-1.5">{" "}<span className="text-xs">🇩🇪</span>{" "}<span className="text-[11px] font-semibold">EUR</span>{" "}</div>{" "}<span className="text-[10px]" style={{
                          "color": "var(--text-muted)"
                        }}>⋮</span>{" "}</div>{" "}<div className="text-[12px] font-bold tracking-tight" id="wallet-eur-val">€14,160.54</div>{" "}<div className="text-[9px] mt-0.5 truncate" style={{
                        "color": "var(--text-muted)"
                      }}>Limit is €8k a month</div>{" "}<div className="wallet-status text-[9.5px] font-semibold text-emerald-500 mt-1.5">Active</div>{" "}</div>{" "}<div data-wallet="GBP" className="wallet-card v-card !rounded-[13px] p-2.5 cursor-pointer hover:shadow-xs transition" onClick={() => walletClick('GBP')}>{" "}<div className="flex items-center justify-between mb-2">{" "}<div className="flex items-center gap-1.5">{" "}<span className="text-xs">🇬🇧</span>{" "}<span className="text-[11px] font-semibold">GBP</span>{" "}</div>{" "}<span className="text-[10px]" style={{
                          "color": "var(--text-muted)"
                        }}>⋮</span>{" "}</div>{" "}<div className="text-[12px] font-bold tracking-tight" id="wallet-gbp-val">£10,054.67</div>{" "}<div className="text-[9px] mt-0.5 truncate" style={{
                        "color": "var(--text-muted)"
                      }}>Limit is £7.5k a month</div>{" "}<div id="gbp-wallet-status" className={'wallet-status text-[9.5px] font-semibold mt-1.5 ' + (state.gbpActive ? 'text-emerald-500' : 'text-rose-500')}>{state.gbpActive ? 'Active' : 'Inactive'}</div>{" "}</div>{" "}</div>{" "}</div>{" "}</div>{" "}<div className="lg:col-span-4 v-card p-4 grid grid-cols-2 gap-3.5 stagger-item stagger-3">{" "}<div className="v-orange-earnings rounded-[18px] p-4 flex flex-col justify-between shadow-xs">{" "}<div className="flex items-center justify-between">{" "}<span className="text-[12.5px] font-medium text-white/90">Total Earnings</span>{" "}<span className="w-7 h-7 rounded-full bg-white/15 flex items-center justify-center">{" "}<svg className="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<rect x="3" y="6" width="18" height="13" rx="2"></rect>{" "}<path d="M3 10h18"></path>{" "}</svg>{" "}</span>{" "}</div>{" "}<div className="font-display text-[32px] font-bold tracking-tight my-3" id="kpi-earnings">{<AnimatedNumber value={849} start={209} duration={750} trigger={counterEpoch} format={value => '$' + Math.round(value)} />}</div>{" "}<div className="flex items-center gap-2">{" "}<span className="px-2 py-0.5 rounded-full text-[10.5px] font-semibold bg-white/20 text-white">↑ 7%</span>{" "}<span className="text-[11px] text-white/80">This month</span>{" "}</div>{" "}</div>{" "}<div className="v-subcard rounded-[18px] p-4 flex flex-col justify-between">{" "}<div className="flex items-center justify-between">{" "}<span className="text-[12.5px] font-medium" style={{
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
                    }}>$5,500.00</span>{" "}</div>{" "}</div>{" "}<div className="v-subcard mt-5 px-3.5 py-2.5 flex items-center justify-between text-[11.5px]">{" "}<span style={{
                    "color": "var(--text-secondary)"
                  }}>Automatic limit reset in 9 days</span>{" "}<button type="button" data-goto-manage="true" className="font-semibold text-[#e0491f] hover:underline" onClick={() => switchView('manage')}>Adjust →</button>{" "}</div>{" "}</div>{" "}<div className="lg:col-span-8 v-card p-5 stagger-item stagger-5">{" "}<div className="flex flex-wrap items-center justify-between gap-3 mb-4">{" "}<h3 className="font-display text-[17px] font-semibold" style={{
                    "color": "var(--text-primary)"
                  }}>Recent Activities</h3>{" "}<div className="flex items-center gap-2.5">{" "}<div className="v-subcard px-3 py-1.5 rounded-full flex items-center gap-2">{" "}<svg className="w-3.5 h-3.5" style={{
                        "color": "var(--text-muted)"
                      }} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="11" cy="11" r="7"></circle>{" "}<path strokeLinecap="round" d="M20 20l-3.5-3.5"></path>{" "}</svg>{" "}<input id="activity-search-input" type="text" placeholder="Search" className="bg-transparent text-[12px] outline-none w-28 sm:w-36" style={{
                        "color": "var(--text-primary)"
                      }} value={activityQuery} onChange={event => setActivityQuery(event.target.value)} />{" "}</div>{" "}<button type="button" id="activity-filter-btn" className="v-subcard px-3.5 py-1.5 rounded-full text-[12px] font-medium flex items-center gap-1.5 hover:opacity-85 transition" style={{
                      "color": "var(--text-primary)"
                    }} onClick={() => {
                      update(next => {
                        next.activities.reverse();
                      });
                      showToast('Sorted recent activities');
                    }}>{" "}<span>Filter</span>{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" d="M4 6h16M7 12h10M10 18h4"></path>{" "}</svg>{" "}</button>{" "}</div>{" "}</div>{" "}<div className="overflow-x-auto">{" "}<table className="w-full text-left border-collapse"><thead><tr className="v-subcard text-[11.5px] font-medium" style={{
                        "color": "var(--text-secondary)"
                      }}><th className="py-2.5 pl-3.5 pr-2 rounded-l-xl w-8">{" "}<input type="checkbox" id="select-all-activities" className="rounded border-gray-300 accent-[#e0491f] cursor-pointer" checked={filteredActivities.length > 0 && filteredActivities.every(row => selectedActivities.includes(row.id))} onChange={event => setSelectedActivities(event.target.checked ? filteredActivities.map(row => row.id) : [])} />{" "}</th><th className="py-2.5 px-3">Order ID</th><th className="py-2.5 px-3">Activity</th><th className="py-2.5 px-3">Price</th><th className="py-2.5 px-3">Status</th><th className="py-2.5 px-3 rounded-r-xl">Date</th></tr></thead><tbody id="recent-activities-tbody" className="text-[12.5px] v-divide">{renderActivitiesTable()}</tbody></table>{" "}</div>{" "}</div>{" "}</div>{" "}</section>{" "}<section id="view-manage" className={"view-section  space-y-5" + (currentView === 'manage' ? '' : ' hidden')}>{" "}<div className="flex flex-wrap items-end justify-between gap-4 pt-1 stagger-item stagger-1">{" "}<div>{" "}<h1 className="font-display text-[36px] sm:text-[40px] font-medium tracking-[-0.025em] leading-tight" style={{
                  "color": "var(--text-primary)"
                }}>Manage</h1>{" "}<p className="text-[13.5px] mt-1" style={{
                  "color": "var(--text-secondary)"
                }}>Freeze cards, fine-tune limits and decide which wallets stay switched on.</p>{" "}</div>{" "}<button type="button" id="btn-new-card" className="px-5 py-2.5 rounded-full text-[13px] font-medium flex items-center gap-2 shadow-xs hover:opacity-90 transition" style={{
                "background": "var(--pill-active-bg)",
                "color": "var(--pill-active-text)"
              }} onClick={() => setModal('newcard')}>{" "}<span className="text-base leading-none">+</span>{" "}<span>New card</span>{" "}</button>{" "}</div>{" "}<div className="grid grid-cols-1 lg:grid-cols-12 gap-5">{" "}<div className="lg:col-span-6 v-card p-6 flex flex-col justify-between stagger-item stagger-2">{" "}<div>{" "}<div className="flex items-center justify-between mb-4">{" "}<h2 className="font-display text-[17px] font-semibold" style={{
                      "color": "var(--text-primary)"
                    }}>Your cards</h2>{" "}<span id="cards-count-label" className="text-[12px]" style={{
                      "color": "var(--text-muted)"
                    }}>2 cards</span>{" "}</div>{" "}<div className="v-subcard p-7 flex flex-col items-center justify-center">{" "}<div id="credit-card-3d" className="card-3d-perspective w-full max-w-[330px] h-[202px] cursor-pointer" onClick={flipCard}>{" "}<div id="credit-card-inner" className={'card-3d-inner' + (state.cardFlipped ? ' is-flipped' : '')}>{" "}<div id="credit-card-front" className="card-3d-face p-6 flex flex-col justify-between text-white shadow-xl" style={{
                          background: card.bg
                        }}>{" "}<div className="absolute inset-0 pointer-events-none opacity-[0.04] flex items-center justify-center">{" "}<div className="w-24 h-24 border-8 border-white rounded-xl rotate-12"></div>{" "}</div>{" "}<div className="relative z-10 flex items-center justify-between">{" "}<div className="flex items-center gap-2">{" "}<svg className="w-4 h-4 text-white/75" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" d="M8.5 14.5A4 4 0 018.5 9.5M11.5 17a7.5 7.5 0 000-10M14.5 19.5a11 11 0 000-15" />{" "}</svg>{" "}<span id="card-status-pill" className={'px-3 py-0.5 rounded-full text-[11px] font-semibold ' + (card.frozen ? 'bg-sky-100 text-sky-800' : 'bg-white text-[#18151c]')}>{card.frozen ? 'Frozen' : 'Active'}</span>{" "}</div>{" "}<div className="flex items-center">{" "}<span className="w-6 h-6 rounded-full bg-[#eb001b] inline-block"></span>{" "}<span className="w-6 h-6 rounded-full bg-[#f79e1b] -ml-2.5 inline-block mix-blend-screen"></span>{" "}</div>{" "}</div>{" "}<div className="relative z-10 flex items-end justify-between">{" "}<div>{" "}<div className="text-[10px] text-white/50 font-medium">Card Number</div>{" "}<div id="card-number-display" className="font-mono text-[15px] font-semibold tracking-wider mt-1">{state.showCardNumber ? card.full : `•••• •••• ${card.last4}`}</div>{" "}</div>{" "}<div className="flex items-end gap-5">{" "}<div>{" "}<div className="text-[9.5px] text-white/50 uppercase">EXP</div>{" "}<div id="card-exp-display" className="text-[13px] font-semibold mt-0.5">{card.exp}</div>{" "}</div>{" "}<div>{" "}<div className="text-[9.5px] text-white/50 uppercase">CVV</div>{" "}<div id="card-cvv-display" className="text-[13px] font-semibold mt-0.5">{card.cvv}</div>{" "}</div>{" "}</div>{" "}</div>{" "}</div>{" "}<div className="card-3d-face card-3d-back flex flex-col justify-between text-white shadow-xl" style={{
                          "background": "linear-gradient(145deg, #1f1c24 0%, #121015 100%)"
                        }}>{" "}<div className="w-full h-10 bg-black/80 mt-5"></div>{" "}<div className="px-6">{" "}<div className="text-[9.5px] text-white/50 uppercase mb-1">Authorized Signature &amp; Security Code</div>{" "}<div className="h-8 rounded bg-white/15 flex items-center justify-between px-3">{" "}<span className="text-[11px] tracking-widest italic text-white/75">Code XR Studio</span>{" "}<span id="card-back-cvv" className="text-xs font-mono font-bold bg-white text-black px-2 py-0.5 rounded">{card.cvv}</span>{" "}</div>{" "}</div>{" "}<div className="px-6 pb-4 flex items-center justify-between text-[10px] text-white/50">{" "}<span>Vortix Commercial World Elite</span>{" "}<span>Tap to flip back</span>{" "}</div>{" "}</div>{" "}</div>{" "}</div>{" "}<p className="text-[11px] mt-3.5" style={{
                      "color": "var(--text-muted)"
                    }}>Tap the card to flip it</p>{" "}</div>{" "}<div className="flex items-center gap-2.5 mt-4">{" "}<button type="button" data-card-idx="0" onClick={() => update(next => {
                      next.selectedCardIdx = 0;
                      next.cardFlipped = false;
                    })} style={{
                      borderColor: state.selectedCardIdx === 0 ? 'var(--text-primary)' : 'transparent',
                      background: state.selectedCardIdx === 0 ? 'var(--bg-card)' : 'var(--bg-subcard)',
                      color: state.selectedCardIdx === 0 ? 'var(--text-primary)' : 'var(--text-secondary)'
                    }} className={"card-select-pill px-3.5 py-1.5 rounded-full text-[12px]  flex items-center gap-2 border transition" + (state.selectedCardIdx === 0 ? ' font-semibold' : '')}>{" "}<span className="w-4 h-3 rounded-[3px] bg-[#18151c] inline-block"></span>{" "}<span>•• 6782</span>{" "}</button>{" "}<button type="button" data-card-idx="1" onClick={() => update(next => {
                      next.selectedCardIdx = 1;
                      next.cardFlipped = false;
                    })} style={{
                      borderColor: state.selectedCardIdx === 1 ? 'var(--text-primary)' : 'transparent',
                      background: state.selectedCardIdx === 1 ? 'var(--bg-card)' : 'var(--bg-subcard)',
                      color: state.selectedCardIdx === 1 ? 'var(--text-primary)' : 'var(--text-secondary)'
                    }} className={"card-select-pill px-3.5 py-1.5 rounded-full text-[12px] font-medium flex items-center gap-2 border border-transparent v-subcard transition" + (state.selectedCardIdx === 1 ? ' font-semibold' : '')}>{" "}<span className="w-4 h-3 rounded-[3px] bg-[#e0491f] inline-block"></span>{" "}<span>•• 4356</span>{" "}</button>{" "}<button type="button" id="btn-inline-add-card" title="Add virtual card" className="w-7 h-7 rounded-full v-subcard flex items-center justify-center text-sm font-semibold hover:opacity-80 transition" style={{
                      "color": "var(--text-secondary)"
                    }} onClick={() => setModal('newcard')}>+</button>{" "}</div>{" "}</div>{" "}<div className="grid grid-cols-4 gap-2.5 mt-4">{" "}<button type="button" id="card-act-freeze" className="v-subcard py-3.5 px-2 rounded-2xl flex flex-col items-center justify-center gap-1.5 hover:opacity-85 transition" onClick={freezeCard}>{" "}<svg className="w-4 h-4" style={{
                      "color": "var(--text-secondary)"
                    }} fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" d="M12 2v20M4.93 4.93l14.14 14.14M2 12h20M4.93 19.07L19.07 4.93" />{" "}</svg>{" "}<span id="freeze-btn-label" className="text-[11.5px] font-medium" style={{
                      "color": "var(--text-primary)"
                    }}>{card.frozen ? 'Unfreeze' : 'Freeze'}</span>{" "}</button>{" "}<button type="button" id="card-act-show" className="v-subcard py-3.5 px-2 rounded-2xl flex flex-col items-center justify-center gap-1.5 hover:opacity-85 transition" onClick={() => update(next => {
                    next.showCardNumber = !next.showCardNumber;
                  })}>{" "}<svg className="w-4 h-4" style={{
                      "color": "var(--text-secondary)"
                    }} fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" />{" "}<circle cx="12" cy="12" r="3" />{" "}</svg>{" "}<span id="show-btn-label" className="text-[11.5px] font-medium" style={{
                      "color": "var(--text-primary)"
                    }}>{state.showCardNumber ? 'Hide number' : 'Show number'}</span>{" "}</button>{" "}<button type="button" id="card-act-copy" className="v-subcard py-3.5 px-2 rounded-2xl flex flex-col items-center justify-center gap-1.5 hover:opacity-85 transition" onClick={copyCard}>{" "}<svg className="w-4 h-4" style={{
                      "color": "var(--text-secondary)"
                    }} fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<rect x="9" y="9" width="11" height="11" rx="2" />{" "}<path strokeLinecap="round" d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" />{" "}</svg>{" "}<span id="copy-btn-label" className="text-[11.5px] font-medium" style={{
                      "color": "var(--text-primary)"
                    }}>{copied ? 'Copied!' : 'Copy number'}</span>{" "}</button>{" "}<button type="button" id="card-act-flip" className="v-subcard py-3.5 px-2 rounded-2xl flex flex-col items-center justify-center gap-1.5 hover:opacity-85 transition" onClick={flipCard}>{" "}<svg className="w-4 h-4" style={{
                      "color": "var(--text-secondary)"
                    }} fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h5M20 20v-5h-5" />{" "}<path strokeLinecap="round" d="M20.49 9A9 9 0 005.64 5.64L4 9m16 6l-1.64 3.36A9 9 0 013.51 15" />{" "}</svg>{" "}<span className="text-[11.5px] font-medium" style={{
                      "color": "var(--text-primary)"
                    }}>Flip card</span>{" "}</button>{" "}</div>{" "}</div>{" "}<div className="lg:col-span-6 v-card p-6 flex flex-col justify-between stagger-item stagger-3">{" "}<div>{" "}<div className="flex items-center justify-between mb-4">{" "}<h2 className="font-display text-[17px] font-semibold" style={{
                      "color": "var(--text-primary)"
                    }}>Card controls</h2>{" "}<span id="controls-status-badge" className={card.frozen ? 'px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-sky-100 text-sky-700' : 'v-badge-green px-2.5 py-0.5 rounded-full text-[11px] font-semibold'}>{card.frozen ? 'Frozen' : 'Active'}</span>{" "}</div>{" "}<div className="v-divide">{" "}<div className="py-3.5 flex items-center justify-between gap-3">{" "}<div className="flex items-center gap-3.5">{" "}<div className="w-9 h-9 rounded-xl v-subcard flex items-center justify-center shrink-0" style={{
                          "color": "var(--text-secondary)"
                        }}>{" "}<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="9" />{" "}<path d="M3.6 9h16.8M3.6 15h16.8M12 3a14 14 0 010 18M12 3a14 14 0 000 18" />{" "}</svg>{" "}</div>{" "}<div>{" "}<div className="text-[13px] font-semibold" style={{
                            "color": "var(--text-primary)"
                          }}>Online payments</div>{" "}<div className="text-[11.5px]" style={{
                            "color": "var(--text-muted)"
                          }}>Shops, subscriptions and in-app purchases</div>{" "}</div>{" "}</div>{" "}<button type="button" data-control="online" onClick={() => {
                        update(next => {
                          next.cards[next.selectedCardIdx].controls['online'] = !next.cards[next.selectedCardIdx].controls['online'];
                        });
                        showToast('Updated online control');
                      }} className={'control-toggle w-11 h-6 rounded-full p-0.5 transition-colors duration-200 flex items-center ' + (card.controls['online'] ? 'justify-end' : 'justify-start')} style={{
                        background: card.controls['online'] ? '#e0491f' : 'var(--toggle-off-bg)'
                      }}>{" "}<span className="w-5 h-5 rounded-full bg-white shadow-xs"></span>{" "}</button>{" "}</div>{" "}<div className="py-3.5 flex items-center justify-between gap-3">{" "}<div className="flex items-center gap-3.5">{" "}<div className="w-9 h-9 rounded-xl v-subcard flex items-center justify-center shrink-0" style={{
                          "color": "var(--text-secondary)"
                        }}>{" "}<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" d="M8.5 14.5A4 4 0 018.5 9.5M11.5 17a7.5 7.5 0 000-10M14.5 19.5a11 11 0 000-15" />{" "}</svg>{" "}</div>{" "}<div>{" "}<div className="text-[13px] font-semibold" style={{
                            "color": "var(--text-primary)"
                          }}>Contactless</div>{" "}<div className="text-[11.5px]" style={{
                            "color": "var(--text-muted)"
                          }}>Tap to pay up to $250 per payment</div>{" "}</div>{" "}</div>{" "}<button type="button" data-control="contactless" onClick={() => {
                        update(next => {
                          next.cards[next.selectedCardIdx].controls['contactless'] = !next.cards[next.selectedCardIdx].controls['contactless'];
                        });
                        showToast('Updated contactless control');
                      }} className={'control-toggle w-11 h-6 rounded-full p-0.5 transition-colors duration-200 flex items-center ' + (card.controls['contactless'] ? 'justify-end' : 'justify-start')} style={{
                        background: card.controls['contactless'] ? '#e0491f' : 'var(--toggle-off-bg)'
                      }}>{" "}<span className="w-5 h-5 rounded-full bg-white shadow-xs"></span>{" "}</button>{" "}</div>{" "}<div className="py-3.5 flex items-center justify-between gap-3">{" "}<div className="flex items-center gap-3.5">{" "}<div className="w-9 h-9 rounded-xl v-subcard flex items-center justify-center shrink-0" style={{
                          "color": "var(--text-secondary)"
                        }}>{" "}<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<rect x="3" y="4" width="18" height="16" rx="2" />{" "}<path strokeLinecap="round" d="M7 8h10M9 14h6" />{" "}</svg>{" "}</div>{" "}<div>{" "}<div className="text-[13px] font-semibold" style={{
                            "color": "var(--text-primary)"
                          }}>ATM withdrawals</div>{" "}<div className="text-[11.5px]" style={{
                            "color": "var(--text-muted)"
                          }}>Up to $1,000 per day</div>{" "}</div>{" "}</div>{" "}<button type="button" data-control="atm" onClick={() => {
                        update(next => {
                          next.cards[next.selectedCardIdx].controls['atm'] = !next.cards[next.selectedCardIdx].controls['atm'];
                        });
                        showToast('Updated atm control');
                      }} className={'control-toggle w-11 h-6 rounded-full p-0.5 transition-colors duration-200 flex items-center ' + (card.controls['atm'] ? 'justify-end' : 'justify-start')} style={{
                        background: card.controls['atm'] ? '#e0491f' : 'var(--toggle-off-bg)'
                      }}>{" "}<span className="w-5 h-5 rounded-full bg-white shadow-xs"></span>{" "}</button>{" "}</div>{" "}<div className="py-3.5 flex items-center justify-between gap-3">{" "}<div className="flex items-center gap-3.5">{" "}<div className="w-9 h-9 rounded-xl v-subcard flex items-center justify-center shrink-0" style={{
                          "color": "var(--text-secondary)"
                        }}>{" "}<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.9" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" />{" "}</svg>{" "}</div>{" "}<div>{" "}<div className="text-[13px] font-semibold" style={{
                            "color": "var(--text-primary)"
                          }}>Use abroad</div>{" "}<div className="text-[11.5px]" style={{
                            "color": "var(--text-muted)"
                          }}>Payments outside Bangladesh</div>{" "}</div>{" "}</div>{" "}<button type="button" data-control="abroad" onClick={() => {
                        update(next => {
                          next.cards[next.selectedCardIdx].controls['abroad'] = !next.cards[next.selectedCardIdx].controls['abroad'];
                        });
                        showToast('Updated abroad control');
                      }} className={'control-toggle w-11 h-6 rounded-full p-0.5 transition-colors duration-200 flex items-center ' + (card.controls['abroad'] ? 'justify-end' : 'justify-start')} style={{
                        background: card.controls['abroad'] ? '#e0491f' : 'var(--toggle-off-bg)'
                      }}>{" "}<span className="w-5 h-5 rounded-full bg-white shadow-xs"></span>{" "}</button>{" "}</div>{" "}</div>{" "}</div>{" "}<div className="v-subcard p-4 mt-4">{" "}<div className="flex items-center justify-between mb-3">{" "}<span className="text-[12.5px] font-medium" style={{
                      "color": "var(--text-secondary)"
                    }}>Monthly spending limit</span>{" "}<span id="manage-limit-display" className="font-display text-[17px] font-bold" style={{
                      "color": "var(--text-primary)"
                    }}>{money(card.limit)}</span>{" "}</div>{" "}<input id="manage-limit-slider" type="range" min="500" max="20000" step="100" className="v-slider" value={card.limit} onChange={event => update(next => {
                    next.cards[next.selectedCardIdx].limit = Number(event.target.value);
                  })} style={{
                    background: `linear-gradient(to right, #e0491f ${limitPercent}%, rgba(150,145,160,0.25) ${limitPercent}%)`
                  }} />{" "}<div className="flex items-center justify-between text-[10.5px] mt-1.5" style={{
                    "color": "var(--text-muted)"
                  }}>{" "}<span>$500</span>{" "}<span>$20,000</span>{" "}</div>{" "}<div className="mt-3.5 h-3 w-full rounded-full overflow-hidden flex items-center gap-1">{" "}<div id="manage-spent-bar" className="h-full rounded-full bg-[#e0491f] transition-all duration-300" style={{
                      width: spentPercent + "%"
                    }}></div>{" "}<div className="h-full flex-1 rounded-r-full spending-hatched-track"></div>{" "}</div>{" "}<div className="mt-2 text-[11.5px]">{" "}<span className="font-bold" style={{
                      "color": "var(--text-primary)"
                    }}>$1,210.38</span>{" "}<span id="manage-spent-pct" className="ml-1" style={{
                      "color": "var(--text-muted)"
                    }}>{`spent · ${spentPercent}%`}</span>{" "}</div>{" "}</div>{" "}</div>{" "}</div>{" "}</section>{" "}<section id="view-program" className={"view-section  space-y-5" + (currentView === 'program' ? '' : ' hidden')}>{" "}<div className="stagger-item stagger-1 pt-1">{" "}<h1 className="font-display text-[36px] sm:text-[40px] font-medium tracking-[-0.025em] leading-tight" style={{
                "color": "var(--text-primary)"
              }}>Rewards Program</h1>{" "}<p className="text-[13.5px] mt-1" style={{
                "color": "var(--text-secondary)"
              }}>Earn points on every purchase and unlock better perks as you climb tiers.</p>{" "}</div>{" "}<div className="grid grid-cols-1 lg:grid-cols-12 gap-5">{" "}<div className="lg:col-span-7 v-orange-card rounded-[22px] p-6 sm:p-7 flex flex-col justify-between shadow-xs stagger-item stagger-2">{" "}<div className="flex items-center justify-between">{" "}<span className="px-3.5 py-1 rounded-full text-[12px] font-medium bg-white/20 text-white backdrop-blur-xs flex items-center gap-1.5">{" "}<span>☆</span>{" "}<span id="current-tier-pill-label">Gold member</span>{" "}</span>{" "}<span className="text-[12px] text-white/85">Member since March 2021</span>{" "}</div>{" "}<div className="my-6">{" "}<div className="flex items-baseline gap-2.5">{" "}<span id="program-points-counter" className="font-display text-[46px] sm:text-[52px] font-semibold tracking-tight leading-none">{<AnimatedNumber value={state.points} start={8125} duration={850} trigger={programEpoch} format={value => Math.round(value).toLocaleString("en-US")} />}</span>{" "}<span className="text-[16px] text-white/85 font-medium">points</span>{" "}</div>{" "}<div className="mt-5 h-3 w-full rounded-full overflow-hidden flex items-center gap-1">{" "}<div id="program-points-bar" className="h-full bg-white rounded-full transition-all duration-500" style={{
                      "width": "49%"
                    }}></div>{" "}<div className="h-full flex-1 rewards-hatched-track"></div>{" "}</div>{" "}<div className="flex items-center justify-between text-[12px] text-white/85 mt-2">{" "}<span id="tier-progress-left">Gold</span>{" "}<span id="tier-progress-right">7,520 pts to Platinum</span>{" "}</div>{" "}</div>{" "}<div className="flex items-center gap-4">{" "}<button type="button" id="btn-redeem-points" className="px-5 py-2.5 rounded-full bg-white text-[#18151c] text-[13px] font-semibold flex items-center gap-2 shadow-xs hover:bg-white/95 transition" onClick={() => setModal('redeem')}>{" "}<svg className="w-4 h-4 text-[#18151c]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<rect x="3" y="8" width="18" height="13" rx="2" />{" "}<path d="M12 8v13M3 12h18M12 8c-2-3-6-3-6 0s6 0 6 0zm0 0c2-3 6-3 6 0s-6 0-6 0z" />{" "}</svg>{" "}<span>Redeem points</span>{" "}</button>{" "}<span id="rewards-cash-equiv" className="text-[12.5px] text-white/90 font-medium">≈ $125 in rewards</span>{" "}</div>{" "}</div>{" "}<div className="lg:col-span-5 v-card p-6 flex flex-col justify-between stagger-item stagger-3">{" "}<div>{" "}<h2 className="font-display text-[17px] font-semibold" style={{
                    "color": "var(--text-primary)"
                  }}>Tier benefits</h2>{" "}<div className="v-subcard inline-flex p-1 rounded-full mt-3.5 mb-4">{" "}<button type="button" data-tier="Silver" onClick={() => selectTier('Silver')} style={{
                      background: state.selectedTier === 'Silver' ? 'var(--seg-active-bg)' : 'transparent',
                      color: state.selectedTier === 'Silver' ? 'var(--text-primary)' : 'var(--text-secondary)'
                    }} className={"tier-tab-btn px-4 py-1.5 rounded-full text-[12px] font-medium" + (state.selectedTier === 'Silver' ? ' font-semibold shadow-xs' : '')}>Silver</button>{" "}<button type="button" data-tier="Gold" onClick={() => selectTier('Gold')} style={{
                      background: state.selectedTier === 'Gold' ? 'var(--seg-active-bg)' : 'transparent',
                      color: state.selectedTier === 'Gold' ? 'var(--text-primary)' : 'var(--text-secondary)'
                    }} className={"tier-tab-btn px-4 py-1.5 rounded-full text-[12px]" + (state.selectedTier === 'Gold' ? ' font-semibold shadow-xs' : '')}>Gold</button>{" "}<button type="button" data-tier="Platinum" onClick={() => selectTier('Platinum')} style={{
                      background: state.selectedTier === 'Platinum' ? 'var(--seg-active-bg)' : 'transparent',
                      color: state.selectedTier === 'Platinum' ? 'var(--text-primary)' : 'var(--text-secondary)'
                    }} className={"tier-tab-btn px-4 py-1.5 rounded-full text-[12px] font-medium" + (state.selectedTier === 'Platinum' ? ' font-semibold shadow-xs' : '')}>Platinum</button>{" "}</div>{" "}<ul id="tier-benefits-list" className="space-y-3 text-[13px]">{renderTierBenefits()}</ul>{" "}</div>{" "}<div id="tier-status-note" className="text-[11.5px] mt-4" style={{
                  "color": "var(--text-muted)"
                }}>{state.tierData[state.selectedTier].note}</div>{" "}</div>{" "}</div>{" "}<div className="grid grid-cols-1 lg:grid-cols-12 gap-5">{" "}<div className="lg:col-span-7 v-card p-6 stagger-item stagger-4">{" "}<div className="flex items-start justify-between gap-3 mb-4">{" "}<div>{" "}<h3 className="font-display text-[17px] font-semibold" style={{
                      "color": "var(--text-primary)"
                    }}>Offers for you</h3>{" "}<p className="text-[12px] mt-0.5" style={{
                      "color": "var(--text-muted)"
                    }}>Activate an offer and it applies to your next eligible payment.</p>{" "}</div>{" "}<span id="active-offers-badge" className="px-3 py-1 rounded-full text-[11px] font-semibold" style={{
                    "background": "var(--accent-orange-light)",
                    "color": "var(--accent-orange)"
                  }}>{`${state.offers.filter(offer => offer.active).length} active`}</span>{" "}</div>{" "}<div id="offers-grid" className="grid grid-cols-1 sm:grid-cols-3 gap-3.5">{renderOffers()}</div>{" "}</div>{" "}<div className="lg:col-span-5 v-card p-6 stagger-item stagger-5">{" "}<h3 className="font-display text-[17px] font-semibold mb-3.5" style={{
                  "color": "var(--text-primary)"
                }}>Points history</h3>{" "}<div id="points-history-list" className="v-divide">{renderPointsHistory()}</div>{" "}</div>{" "}</div>{" "}</section>{" "}<section id="view-messages" className={"view-section  space-y-5" + (currentView === 'messages' ? '' : ' hidden')}>{" "}<div className="stagger-item stagger-1 pt-1">{" "}<h1 className="font-display text-[36px] sm:text-[40px] font-medium tracking-[-0.025em] leading-tight" style={{
                "color": "var(--text-primary)"
              }}>Messages</h1>{" "}<p className="text-[13.5px] mt-1" style={{
                "color": "var(--text-secondary)"
              }}>Talk to your bank, your team and the people you pay.</p>{" "}</div>{" "}<div className="v-card p-4 sm:p-5 grid grid-cols-1 lg:grid-cols-12 gap-5 min-h-[470px] stagger-item stagger-2">{" "}<div className="lg:col-span-4 flex flex-col v-border-r pr-0 lg:pr-4 pb-4 lg:pb-0">{" "}<div className="v-subcard px-3.5 py-2 rounded-full flex items-center gap-2 mb-3">{" "}<svg className="w-4 h-4" style={{
                    "color": "var(--text-muted)"
                  }} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="11" cy="11" r="7"></circle>{" "}<path strokeLinecap="round" d="M20 20l-3.5-3.5"></path>{" "}</svg>{" "}<input id="chat-search-input" type="text" placeholder="Search chats" className="bg-transparent text-[12.5px] outline-none w-full" style={{
                    "color": "var(--text-primary)"
                  }} value={chatQuery} onChange={event => setChatQuery(event.target.value)} />{" "}</div>{" "}<div className="v-subcard inline-flex self-start p-1 rounded-full mb-3.5">{" "}<button type="button" data-chat-filter="all" onClick={() => selectChatFilter('all')} style={{
                    background: state.chatFilter === 'all' ? 'var(--seg-active-bg)' : 'transparent',
                    color: state.chatFilter === 'all' ? 'var(--text-primary)' : 'var(--text-secondary)'
                  }} className={"chat-filter-btn px-3.5 py-1 rounded-full text-[11.5px]" + (state.chatFilter === 'all' ? ' font-semibold shadow-xs' : '')}>All</button>{" "}<button type="button" data-chat-filter="unread" onClick={() => selectChatFilter('unread')} style={{
                    background: state.chatFilter === 'unread' ? 'var(--seg-active-bg)' : 'transparent',
                    color: state.chatFilter === 'unread' ? 'var(--text-primary)' : 'var(--text-secondary)'
                  }} className={"chat-filter-btn px-3.5 py-1 rounded-full text-[11.5px] font-medium" + (state.chatFilter === 'unread' ? ' font-semibold shadow-xs' : '')}>Unread</button>{" "}</div>{" "}<div id="chat-threads-list" className="space-y-2 flex-1">{renderChatThreads()}</div>{" "}</div>{" "}<div className="lg:col-span-8 flex flex-col justify-between">{" "}<div className="flex items-center justify-between pb-3.5 v-border-b">{" "}<div className="flex items-center gap-3">{" "}<div id="active-chat-avatar" className="w-9 h-9 rounded-full flex items-center justify-center text-white font-bold text-sm relative" style={{
                      "background": "radial-gradient(circle at 30% 25%, #ff8a65 0%, #e0491f 75%)"
                    }}>{" "}<span>V</span>{" "}<span className="w-2.5 h-2.5 rounded-full bg-emerald-500 absolute -bottom-0.5 -right-0.5" style={{
                        "boxShadow": "0 0 0 2px var(--bg-card)"
                      }}></span>{" "}</div>{" "}<div>{" "}<div id="active-chat-name" className="text-[13.5px] font-semibold" style={{
                        "color": "var(--text-primary)"
                      }}>{chat.name}</div>{" "}<div id="active-chat-status" className="text-[11px] font-medium text-emerald-500">{chat.status}</div>{" "}</div>{" "}</div>{" "}<div className="flex items-center gap-2">{" "}<button type="button" id="chat-call-btn" className="w-8 h-8 rounded-full v-subcard flex items-center justify-center hover:opacity-80 transition" style={{
                      "color": "var(--text-secondary)"
                    }} onClick={() => showToast('Starting priority voice call...')}>{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M3 5a2 2 0 012-2h2.6a1 1 0 01.95.68l1.1 3.3a1 1 0 01-.27 1.02l-1.5 1.5a12 12 0 005.6 5.6l1.5-1.5a1 1 0 011.02-.27l3.3 1.1a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.82 21 3 14.18 3 6V5z" />{" "}</svg>{" "}</button>{" "}<button type="button" id="chat-more-btn" className="w-8 h-8 rounded-full v-subcard flex items-center justify-center hover:opacity-80 transition" style={{
                      "color": "var(--text-secondary)"
                    }} onClick={() => showToast('Conversation transcript saved')}>{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="12" cy="5" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="12" cy="19" r="1" />{" "}</svg>{" "}</button>{" "}</div>{" "}</div>{" "}<div className="py-4 flex-1 flex flex-col justify-start space-y-3 overflow-y-auto max-h-[320px]">{" "}<div className="self-center v-subcard px-3 py-1 rounded-full text-[10.5px] font-medium" style={{
                    "color": "var(--text-muted)"
                  }}>Today</div>{" "}<div id="chat-messages-feed" className="space-y-3">{renderActiveChat()}</div>{" "}</div>{" "}<form id="chat-send-form" className="v-subcard rounded-full px-4 py-2 flex items-center gap-3 mt-2" onSubmit={sendMessage}>{" "}<button type="button" id="chat-attach-btn" className="hover:opacity-75 transition" style={{
                    "color": "var(--text-muted)"
                  }} onClick={() => showToast('Select an invoice or receipt to attach')}>{" "}<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />{" "}</svg>{" "}</button>{" "}<input id="chat-composer-input" type="text" className="bg-transparent text-[12.5px] outline-none flex-1" style={{
                    "color": "var(--text-primary)"
                  }} value={composer} onChange={event => setComposer(event.target.value)} placeholder={`Message ${chat.name}...`} />{" "}<button type="button" id="chat-emoji-btn" className="hover:opacity-75 transition" style={{
                    "color": "var(--text-muted)"
                  }} onClick={() => {
                    setComposer(value => value + ' 👋');
                    shadowRoot?.getElementById('chat-composer-input')?.focus();
                  }}>{" "}<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{" "}<circle cx="12" cy="12" r="9" /><path strokeLinecap="round" d="M8.5 14.5s1.5 2 3.5 2 3.5-2 3.5-2M9 9.5h.01M15 9.5h.01" />{" "}</svg>{" "}</button>{" "}<button type="submit" className="w-8 h-8 rounded-full bg-[#e0491f] text-white flex items-center justify-center hover:opacity-90 transition shrink-0">{" "}<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2.2" viewBox="0 0 24 24">{" "}<path strokeLinecap="round" strokeLinejoin="round" d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" />{" "}</svg>{" "}</button>{" "}</form>{" "}</div>{" "}</div>{" "}</section>{" "}<section id="view-activity" className={"view-section  space-y-5" + (currentView === 'activity' ? '' : ' hidden')}>{" "}<div className="stagger-item stagger-1 pt-1 flex flex-wrap items-end justify-between gap-4">{" "}<div>{" "}<h1 className="font-display text-[36px] sm:text-[40px] font-medium tracking-[-0.025em] leading-tight" style={{
                  "color": "var(--text-primary)"
                }}>Activity</h1>{" "}<p className="text-[13.5px] mt-1" style={{
                  "color": "var(--text-secondary)"
                }}>Track every incoming settlement, card payment, and recurring subscription in real time.</p>{" "}</div>{" "}<button type="button" id="btn-export-csv" className="px-5 py-2.5 rounded-full text-[13px] font-medium shadow-xs hover:opacity-90 transition" style={{
                "background": "var(--pill-active-bg)",
                "color": "var(--pill-active-text)"
              }} onClick={() => showToast('Exported April 2026 ledger CSV')}>Export CSV</button>{" "}</div>{" "}<div className="v-card p-6 stagger-item stagger-2">{" "}<div className="flex flex-wrap items-center justify-between gap-3 mb-4">{" "}<div className="flex items-center gap-2">{" "}<button type="button" data-act-tab="all" className="act-status-pill px-4 py-1.5 rounded-full text-[12px] font-semibold" onClick={() => setActivityStatus('all')} style={{
                    background: activityStatus === 'all' ? 'var(--pill-active-bg)' : 'var(--bg-subcard)',
                    color: activityStatus === 'all' ? 'var(--pill-active-text)' : 'var(--text-secondary)'
                  }}>All (6)</button>{" "}<button type="button" data-act-tab="Completed" className="act-status-pill v-subcard px-4 py-1.5 rounded-full text-[12px] font-medium" onClick={() => setActivityStatus('Completed')} style={{
                    background: activityStatus === 'Completed' ? 'var(--pill-active-bg)' : 'var(--bg-subcard)',
                    color: activityStatus === 'Completed' ? 'var(--pill-active-text)' : 'var(--text-secondary)'
                  }}>Completed</button>{" "}<button type="button" data-act-tab="Pending" className="act-status-pill v-subcard px-4 py-1.5 rounded-full text-[12px] font-medium" onClick={() => setActivityStatus('Pending')} style={{
                    background: activityStatus === 'Pending' ? 'var(--pill-active-bg)' : 'var(--bg-subcard)',
                    color: activityStatus === 'Pending' ? 'var(--pill-active-text)' : 'var(--text-secondary)'
                  }}>Pending</button>{" "}</div>{" "}<span className="text-[12px]" style={{
                  "color": "var(--text-muted)"
                }}>Showing April 2026 ledger</span>{" "}</div>{" "}<div className="overflow-x-auto">{" "}<table className="w-full text-left border-collapse"><thead><tr className="v-subcard text-[11.5px] font-medium" style={{
                      "color": "var(--text-secondary)"
                    }}><th className="py-2.5 px-4 rounded-l-xl">Order ID</th><th className="py-2.5 px-4">Activity</th><th className="py-2.5 px-4">Category</th><th className="py-2.5 px-4">Price</th><th className="py-2.5 px-4">Status</th><th className="py-2.5 px-4 rounded-r-xl">Date</th></tr></thead><tbody id="full-activity-tbody" className="text-[13px] v-divide">{renderFullActivityView(activityStatus)}</tbody></table>{" "}</div>{" "}</div>{" "}</section>{" "}<section id="view-account" className={"view-section  space-y-5" + (currentView === 'account' ? '' : ' hidden')}>{" "}<div className="stagger-item stagger-1 pt-1">{" "}<h1 className="font-display text-[36px] sm:text-[40px] font-medium tracking-[-0.025em] leading-tight" style={{
                "color": "var(--text-primary)"
              }}>Account</h1>{" "}<p className="text-[13.5px] mt-1" style={{
                "color": "var(--text-secondary)"
              }}>Manage your Code XR studio profile, settlement bank details, and security preferences.</p>{" "}</div>{" "}<div className="grid grid-cols-1 lg:grid-cols-12 gap-5 stagger-item stagger-2">{" "}<div className="lg:col-span-6 v-card p-6 space-y-4">{" "}<h2 className="font-display text-[17px] font-semibold" style={{
                  "color": "var(--text-primary)"
                }}>Studio Profile</h2>{" "}<div className="space-y-3">{" "}<div>{" "}<label className="text-[11.5px] font-medium" style={{
                      "color": "var(--text-secondary)"
                    }}>Display Name</label>{" "}<input id="acc-name-input" type="text" defaultValue="Code XR" className="v-subcard w-full px-4 py-2.5 rounded-xl text-[13px] mt-1 outline-none" style={{
                      "color": "var(--text-primary)"
                    }} />{" "}</div>{" "}<div>{" "}<label className="text-[11.5px] font-medium" style={{
                      "color": "var(--text-secondary)"
                    }}>Primary Email</label>{" "}<input id="acc-email-input" type="email" defaultValue="hello@codexr.studio" className="v-subcard w-full px-4 py-2.5 rounded-xl text-[13px] mt-1 outline-none" style={{
                      "color": "var(--text-primary)"
                    }} />{" "}</div>{" "}<button type="button" id="btn-save-account" className="px-5 py-2.5 rounded-full text-[13px] font-medium mt-2" style={{
                    "background": "var(--pill-active-bg)",
                    "color": "var(--pill-active-text)"
                  }} onClick={() => showToast('Studio profile settings saved')}>Save changes</button>{" "}</div>{" "}</div>{" "}<div className="lg:col-span-6 v-card p-6 space-y-4">{" "}<h2 className="font-display text-[17px] font-semibold" style={{
                  "color": "var(--text-primary)"
                }}>Security &amp; Verification</h2>{" "}<div className="v-subcard p-4 flex items-center justify-between">{" "}<div>{" "}<div className="text-[13px] font-semibold" style={{
                      "color": "var(--text-primary)"
                    }}>Two-Factor Authentication</div>{" "}<div className="text-[11.5px]" style={{
                      "color": "var(--text-muted)"
                    }}>Protected via Authenticator App</div>{" "}</div>{" "}<span className="v-badge-green px-3 py-1 rounded-full text-[11px] font-semibold">Enabled</span>{" "}</div>{" "}<div className="v-subcard p-4 flex items-center justify-between">{" "}<div>{" "}<div className="text-[13px] font-semibold" style={{
                      "color": "var(--text-primary)"
                    }}>KYB Business Verification</div>{" "}<div className="text-[11.5px]" style={{
                      "color": "var(--text-muted)"
                    }}>Code XR Studio LLC · Verified March 2021</div>{" "}</div>{" "}<span className="v-badge-green px-3 py-1 rounded-full text-[11px] font-semibold">Verified</span>{" "}</div>{" "}</div>{" "}</div>{" "}</section>{" "}<section id="view-reports" className={"view-section  space-y-5" + (currentView === 'reports' ? '' : ' hidden')}>{" "}<div className="stagger-item stagger-1 pt-1">{" "}<h1 className="font-display text-[36px] sm:text-[40px] font-medium tracking-[-0.025em] leading-tight" style={{
                "color": "var(--text-primary)"
              }}>Reports</h1>{" "}<p className="text-[13.5px] mt-1" style={{
                "color": "var(--text-secondary)"
              }}>Download audited monthly statements, tax documents, and FX settlement breakdowns.</p>{" "}</div>{" "}<div className="grid grid-cols-1 md:grid-cols-3 gap-5 stagger-item stagger-2">{" "}<div className="v-card p-6 flex flex-col justify-between">{" "}<div>{" "}<span className="px-2.5 py-1 rounded-full text-[11px] font-semibold" style={{
                    "background": "var(--accent-orange-light)",
                    "color": "var(--accent-orange)"
                  }}>Q1 2026</span>{" "}<h3 className="font-display text-[16px] font-semibold mt-3" style={{
                    "color": "var(--text-primary)"
                  }}>Consolidated Balance Statement</h3>{" "}<p className="text-[12px] mt-1" style={{
                    "color": "var(--text-muted)"
                  }}>Includes USD, EUR, and GBP multi-currency ledgers.</p>{" "}</div>{" "}<button type="button" data-report="Q1-2026-Statement.pdf" className="report-dl-btn v-subcard mt-5 py-2.5 rounded-full text-[12.5px] font-semibold hover:opacity-85 transition" style={{
                  "color": "var(--text-primary)"
                }} onClick={() => showToast('Downloaded Q1-2026-Statement.pdf')}>Download PDF</button>{" "}</div>{" "}<div className="v-card p-6 flex flex-col justify-between">{" "}<div>{" "}<span className="px-2.5 py-1 rounded-full text-[11px] font-semibold" style={{
                    "background": "var(--accent-orange-light)",
                    "color": "var(--accent-orange)"
                  }}>April 2026</span>{" "}<h3 className="font-display text-[16px] font-semibold mt-3" style={{
                    "color": "var(--text-primary)"
                  }}>Profit &amp; Loss Audit</h3>{" "}<p className="text-[12px] mt-1" style={{
                    "color": "var(--text-muted)"
                  }}>Monthly breakdown of gross revenue, card spend, and FX fees.</p>{" "}</div>{" "}<button type="button" data-report="April-2026-PnL.pdf" className="report-dl-btn v-subcard mt-5 py-2.5 rounded-full text-[12.5px] font-semibold hover:opacity-85 transition" style={{
                  "color": "var(--text-primary)"
                }} onClick={() => showToast('Downloaded April-2026-PnL.pdf')}>Download PDF</button>{" "}</div>{" "}<div className="v-card p-6 flex flex-col justify-between">{" "}<div>{" "}<span className="px-2.5 py-1 rounded-full text-[11px] font-semibold" style={{
                    "background": "var(--accent-orange-light)",
                    "color": "var(--accent-orange)"
                  }}>Tax Year 2025</span>{" "}<h3 className="font-display text-[16px] font-semibold mt-3" style={{
                    "color": "var(--text-primary)"
                  }}>Annual Rewards &amp; Cashback Summary</h3>{" "}<p className="text-[12px] mt-1" style={{
                    "color": "var(--text-muted)"
                  }}>Summary of 12,480 Gold tier points and redemptions.</p>{" "}</div>{" "}<button type="button" data-report="Tax-2025-Summary.pdf" className="report-dl-btn v-subcard mt-5 py-2.5 rounded-full text-[12.5px] font-semibold hover:opacity-85 transition" style={{
                  "color": "var(--text-primary)"
                }} onClick={() => showToast('Downloaded Tax-2025-Summary.pdf')}>Download PDF</button>{" "}</div>{" "}</div>{" "}</section>{" "}</main>{" "}</div>{" "}</div>{" "}</div>{" "}<div id="v-modal-backdrop" className={'fixed inset-0 bg-black/40 backdrop-blur-xs z-50 items-center justify-center p-4 ' + (modal ? 'flex' : 'hidden')} onClick={event => {
    if (event.target === event.currentTarget) setModal(null);
  }}>{" "}<div className="v-card max-w-md w-full p-6 shadow-2xl">{" "}<div className="flex items-center justify-between mb-4">{" "}<h3 id="v-modal-title" className="font-display text-[18px] font-semibold" style={{
          "color": "var(--text-primary)"
        }}>{modalTitle}</h3>{" "}<button type="button" id="v-modal-close" className="w-8 h-8 rounded-full v-subcard flex items-center justify-center text-sm" style={{
          "color": "var(--text-secondary)"
        }} onClick={() => setModal(null)}>✕</button>{" "}</div>{" "}<div id="v-modal-body" className="space-y-3 text-[13px]">{renderModal()}</div>{" "}</div>{" "}</div>{" "}</></>, shadowRoot)}
  </div>;
}

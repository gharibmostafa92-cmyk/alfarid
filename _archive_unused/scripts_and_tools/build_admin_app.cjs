const fs = require('fs');
const path = require('path');

const srcPath = path.resolve(__dirname, 'dasign.txt');
const content = fs.readFileSync(srcPath, 'utf8');
const lines = content.split(/\r?\n/);

console.log('Total lines in dasign.txt:', lines.length);

// Extract CSS: lines 0 to 503
const cssLines = lines.slice(0, 504);
const outCssPath = path.resolve(__dirname, 'bags-platform/frontend/apps/backoffice/src/index.css');

// Append new CSS components if not already there
let baseCss = cssLines.join('\n');
const extraCss = `
/* ============ DASHBOARD & KPI CARDS ============ */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px}
.kpi-card{background:#fff;border:1px solid var(--line);border-radius:var(--r2);padding:18px 20px;position:relative;overflow:hidden;transition:all .2s var(--ease)}
.kpi-card:hover{border-color:var(--ink-4);box-shadow:0 6px 18px rgba(0,0,0,.04);transform:translateY(-1px)}
.kpi-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.kpi-label{font-family:var(--display);font-size:11.5px;letter-spacing:.6px;color:var(--ink-4);font-weight:500}
.kpi-icon{width:32px;height:32px;border-radius:var(--r);background:var(--soft);display:grid;place-items:center;color:var(--ink-2);font-size:13px}
.kpi-val{font-family:var(--mono);font-size:24px;font-weight:700;letter-spacing:-.5px;color:var(--ink);line-height:1.2}
.kpi-sub{font-size:11.5px;color:var(--ink-4);margin-top:6px;display:flex;align-items:center;gap:6px}
.kpi-trend{display:inline-flex;align-items:center;gap:3px;font-family:var(--display);font-size:11px;font-weight:600;padding:1px 6px;border-radius:var(--r)}
.kpi-trend.up{background:var(--ok-soft);color:var(--ok)}
.kpi-trend.down{background:var(--danger-soft);color:var(--danger)}

/* ============ CSS BAR CHART ============ */
.chart-box{background:#fff;border:1px solid var(--line);border-radius:var(--r2);padding:20px}
.chart-bars{display:flex;align-items:flex-end;gap:12px;height:160px;padding-top:20px;border-bottom:1px solid var(--line)}
.chart-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:8px;height:100%;justify-content:flex-end}
.chart-bar{width:100%;max-width:34px;background:var(--band);border-radius:var(--r) var(--r) 0 0;transition:all .3s var(--ease);position:relative;cursor:pointer}
.chart-bar:hover{background:var(--ink);transform:scaleY(1.02)}
.chart-bar.active{background:var(--accent)}
.chart-lbl{font-size:11px;color:var(--ink-4);font-family:var(--display);letter-spacing:.4px;white-space:nowrap}
.chart-val{font-size:10px;font-family:var(--mono);color:var(--ink-3);margin-bottom:4px}

/* ============ COMMAND PALETTE (CTRL+K) ============ */
.cmd-scrim{position:fixed;inset:0;background:rgba(17,17,17,.5);backdrop-filter:blur(3px);z-index:300;display:flex;align-items:flex-start;justify-content:center;padding-top:10vh;animation:fade .15s}
.cmd-box{background:#fff;border-radius:var(--r2);width:min(600px,94vw);box-shadow:0 24px 60px rgba(0,0,0,.25);overflow:hidden;border:1px solid var(--line-2);animation:pop .2s var(--ease)}
.cmd-head{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--line)}
.cmd-head i{color:var(--ink-4);font-size:14px}
.cmd-input{flex:1;border:0;outline:0;font-size:14px;background:transparent}
.cmd-esc{font-family:var(--mono);font-size:10.5px;padding:2px 6px;border-radius:var(--r);background:var(--soft);color:var(--ink-4);border:1px solid var(--line)}
.cmd-list{max-height:360px;overflow-y:auto;padding:8px}
.cmd-grp{font-family:var(--display);font-size:11px;letter-spacing:.8px;color:var(--ink-4);padding:8px 12px 4px}
.cmd-item{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:var(--r);cursor:pointer;font-size:13px;transition:background .15s}
.cmd-item:hover,.cmd-item.active{background:var(--soft);color:var(--ink)}
.cmd-item i{width:16px;text-align:center;color:var(--ink-4)}
.cmd-item .badge{margin-inline-start:auto}
.cmd-foot{padding:8px 18px;background:var(--soft-2);border-top:1px solid var(--line);display:flex;align-items:center;gap:16px;font-size:11px;color:var(--ink-4)}

/* ============ NOTIFICATIONS POPOVER ============ */
.notify-pop{position:absolute;top:calc(100% + 8px);inset-inline-end:16px;width:340px;background:#fff;border:1px solid var(--line-2);border-radius:var(--r2);box-shadow:0 16px 40px rgba(0,0,0,.15);z-index:100;overflow:hidden;animation:pop .2s var(--ease)}
.notify-h{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--soft-2)}
.notify-h b{font-family:var(--display);font-size:13px;letter-spacing:.4px}
.notify-b{max-height:320px;overflow-y:auto}
.notify-item{display:flex;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line);font-size:12.5px;transition:background .15s;cursor:pointer}
.notify-item:hover{background:var(--soft-2)}
.notify-item:last-child{border-bottom:0}
.notify-item .ni{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;font-size:11px;flex-shrink:0}
.notify-item.unread{background:#fafcfb}
.notify-item.unread::after{content:"";width:6px;height:6px;border-radius:50%;background:var(--accent);margin-inline-start:auto;margin-top:6px}
`;
fs.writeFileSync(outCssPath, baseCss + '\n' + extraCss, 'utf8');
console.log('Wrote complete design CSS to index.css');

// Extract JS: lines 504 to end
let jsContent = lines.slice(504).join('\n');

// Remove duplicate destructured hooks declaration
jsContent = jsContent.replace(
  'const { useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } = React;',
  '// const { useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } = React;'
);

// Update TWEAK_DEFAULTS
jsContent = jsContent.replace(
  'const TWEAK_DEFAULTS = window.ALFARID_TWEAKS;',
  `const TWEAK_DEFAULTS = (typeof window !== 'undefined' && window.ALFARID_TWEAKS) || {
  canViewCost: true,
  canPublish: true,
  canManageRoles: true,
  navMode: 'normal',
  dataState: 'normal',
  publishSim: 'success',
  accent: '#4f7d88',
  band: '#dfe5e9',
};`
);

// Update IMG paths with high quality fallback URLs
const oldImgRegex = /const IMG = \{[\s\S]*?\};/;
const newImgStr = `const IMG = {
  bag1: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=600&auto=format&fit=crop&q=80',
  bag2: 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=600&auto=format&fit=crop&q=80',
  bag3: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=600&auto=format&fit=crop&q=80',
  wallet: 'https://images.unsplash.com/photo-1627123424574-724758594e93?w=600&auto=format&fit=crop&q=80',
  bp1: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=600&auto=format&fit=crop&q=80',
  bp2: 'https://images.unsplash.com/photo-1622560480605-d83c853bc5c3?w=600&auto=format&fit=crop&q=80',
  walletPhoto: 'https://images.unsplash.com/photo-1627123424574-724758594e93?w=600&auto=format&fit=crop&q=80',
  shoulder: 'https://images.unsplash.com/photo-1590874103328-eac38a683ce7?w=600&auto=format&fit=crop&q=80',
};`;
jsContent = jsContent.replace(oldImgRegex, newImgStr);

// In LoginPage: redirect to /dashboard upon login
jsContent = jsContent.replace("go('/products'); return;", "go('/dashboard'); return;");

// Comprehensive Departments Navigation
const newNavStr = `const NAV = [
  { g: 'الرئيسية', items: [
    ['/dashboard', 'fa-solid fa-chart-pie', 'لوحة المعلومات', null],
  ]},
  { g: 'الكتالوج', items: [
    ['/products', 'fa-solid fa-bag-shopping', 'المنتجات', 'products.view'],
    ['/categories', 'fa-solid fa-sitemap', 'الأقسام', 'categories.view'],
    ['/brands', 'fa-solid fa-copyright', 'البراندات', 'brands.view'],
  ]},
  { g: 'المبيعات والعملاء', items: [
    ['/orders', 'fa-solid fa-receipt', 'الطلبات والمبيعات', 'orders.view'],
    ['/customers', 'fa-solid fa-users', 'العملاء والجملة', 'customers.view'],
  ]},
  { g: 'التشغيل والمخزون', items: [
    ['/branches', 'fa-solid fa-store', 'الفروع والمخازن', 'branches.view'],
    ['/inventory', 'fa-solid fa-boxes-stacked', 'المخزون والتحويلات', 'inventory.view'],
    ['/purchases', 'fa-solid fa-file-invoice-dollar', 'أوامر الشراء', 'purchases.view'],
  ]},
  { g: 'المالية والتقارير', items: [
    ['/treasury', 'fa-solid fa-vault', 'الخزائن والمالية', 'treasuries.view'],
    ['/reports', 'fa-solid fa-chart-line', 'التقارير التحليلية', 'reports.view'],
  ]},
  { g: 'الإدارة والنظام', items: [
    ['/users', 'fa-solid fa-user-group', 'المستخدمون', 'users.view'],
    ['/roles', 'fa-solid fa-shield-halved', 'الأدوار والصلاحيات', 'roles.manage'],
    ['/settings', 'fa-solid fa-sliders', 'الإعدادات العامة', 'settings.view'],
  ]},
  { g: 'مرجع التصميم', items: [
    ['/kit', 'fa-solid fa-swatchbook', 'المكونات المشتركة', null],
  ]},
];`;

const oldNavRegex = /const NAV = \[[\s\S]*?\];/;
jsContent = jsContent.replace(oldNavRegex, newNavStr);

// Enhanced Layout with Command Palette (Ctrl+K) & Live Notifications Popover
const oldLayoutRegex = /function Layout\(\{ children \}\) \{[\s\S]*?return \([\s\S]*?<\/div>\s*\);\s*\}/;
const newLayoutStr = `function Layout({ children }) {
  const { route, go, can, sessionExpired, setSessionExpired, tw } = useApp();
  const [cmdOpen, setCmdOpen] = useState(false);
  const [notifyOpen, setNotifyOpen] = useState(false);
  const base = '/' + (route.path.split('/')[1] || '');

  return (
    <div className="app">
      <aside className="side">
        <div className="side-logo"><LogoMark /> <div>ALFARID<small>ADMIN CONSOLE</small></div></div>
        <nav className="side-nav">
          {NAV.map(g => {
            const items = g.items.filter(it => tw.navMode === 'show-locked' || !it[3] || can(it[3]));
            if (!items.length) return null;
            return <div key={g.g}><div className="side-group">{g.g}</div>
              {items.map(it => {
                const locked = it[3] && !can(it[3]);
                return <button key={it[0]} className={'side-item ' + (base === it[0] ? 'active' : '')} onClick={() => go(it[0])}>
                  <i className={it[1]}></i>{it[2]}{locked && <i className="fa-solid fa-lock lock"></i>}
                </button>;
              })}
            </div>;
          })}
        </nav>
        <div className="side-foot">
          <div className="avatar">أف</div>
          <div className="who"><b>أحمد الفريد</b><span>مدير النظام</span></div>
          <button className="icon-btn" title="تسجيل الخروج" onClick={() => go('/login')}><i className="fa-solid fa-arrow-right-from-bracket"></i></button>
        </div>
      </aside>

      <main className="main" onClick={() => notifyOpen && setNotifyOpen(false)}>
        <div className="topbar">
          <span className="branch-chip" style={{ cursor: 'pointer' }} onClick={() => go('/branches')} title="عرض تفاصيل الفروع">
            <i className="fa-solid fa-store muted"></i>كل الفروع <Badge tone="accent" nodot>امتياز صريح</Badge>
          </span>
          <div className="sp"></div>
          <span className="muted" style={{ fontSize: 12, display: 'flex', alignItems: 'center', gap: 6 }}><span className="session-dot"></span>جلسة الإدارة</span>

          <button className="ico-btn" aria-label="بحث سريع" onClick={() => setCmdOpen(true)} title="بحث سريع (Ctrl + K)">
            <i className="fa-solid fa-magnifying-glass"></i>
          </button>
          <span className="cmd-esc" style={{ fontSize: 10, cursor: 'pointer', padding: '2px 6px' }} onClick={() => setCmdOpen(true)} title="البحث السريع">Ctrl + K</span>

          <div style={{ position: 'relative' }}>
            <button className="ico-btn" aria-label="الإشعارات" onClick={(e) => { e.stopPropagation(); setNotifyOpen(o => !o); }} title="الإشعارات والتنبيهات">
              <i className="fa-regular fa-bell"></i>
              <span style={{ position: 'absolute', top: 5, right: 6, width: 7, height: 7, borderRadius: '50%', background: 'var(--accent)' }}></span>
            </button>
            <NotificationsPopover open={notifyOpen} onClose={() => setNotifyOpen(false)} go={go} />
          </div>
        </div>

        {children}
      </main>

      <CommandPalette open={cmdOpen} onClose={() => setCmdOpen(false)} go={go} />

      {sessionExpired && (
        <div className="scrim">
          <div className="modal">
            <div className="modal-b"><div className="mi warn"><i className="fa-solid fa-clock-rotate-left"></i></div>
              <div><h4>انتهت الجلسة</h4><p>لحمايتك تم تسجيل خروجك بعد فترة من عدم النشاط. سجّل الدخول مجددًا وستعود لنفس الصفحة، والمسودة المحلية محفوظة.</p><span className="mono muted">401 Unauthorized</span></div></div>
            <div className="modal-f"><Btn onClick={() => { setSessionExpired(false); go('/login'); }}>تسجيل الدخول</Btn></div>
          </div>
        </div>
      )}
    </div>
  );
}`;
jsContent = jsContent.replace(oldLayoutRegex, newLayoutStr);

// Updated App router mapping
const oldInnerRegex = /let inner;[\s\S]*?page = <Layout>\{inner\}<\/Layout>;/;
const newInnerStr = `let inner;
    if (tw.dataState === 'forbidden' && p[0] !== 'kit') inner = <><PageHeader crumbs={[['الرئيسية', '/dashboard'], ['غير مصرح']]} bold="غير مصرح" /><div className="content"><div className="panel"><NoAccessState perm={'products.view'} go={go} /></div></div></>;
    else if (p[0] === 'dashboard') inner = <DashboardPage />;
    else if (p[0] === 'orders') inner = <OrdersPage />;
    else if (p[0] === 'customers') inner = <CustomersPage />;
    else if (p[0] === 'inventory') inner = <InventoryPage />;
    else if (p[0] === 'purchases') inner = <PurchasesPage />;
    else if (p[0] === 'treasury') inner = <TreasuryPage />;
    else if (p[0] === 'reports') inner = <ReportsPage />;
    else if (p[0] === 'products' && p[1]) inner = <ProductForm id={p[1]} key={p[1]} />;
    else if (p[0] === 'products') inner = <ProductsPage />;
    else if (p[0] === 'categories') inner = <CategoriesPage />;
    else if (p[0] === 'brands') inner = <BrandsPage />;
    else if (p[0] === 'branches' && p[1]) inner = <BranchDetail id={+p[1]} />;
    else if (p[0] === 'branches') inner = <BranchesPage />;
    else if (p[0] === 'users') inner = <UsersPage />;
    else if (p[0] === 'roles') inner = can('roles.manage') ? <RolesPage /> : <><PageHeader crumbs={[['الإدارة'], ['الأدوار']]} bold="الأدوار والصلاحيات" /><div className="content"><div className="panel"><NoAccessState perm="roles.manage" go={go} /></div></div></>;
    else if (p[0] === 'settings') inner = <SettingsPage />;
    else if (p[0] === 'kit') inner = <KitPage />;
    else inner = <DashboardPage />;
    page = <Layout>{inner}</Layout>;`;
jsContent = jsContent.replace(oldInnerRegex, newInnerStr);

const header = `// @ts-nocheck
import React, { useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } from 'react';
import {
  DashboardPage,
  OrdersPage,
  CustomersPage,
  InventoryPage,
  PurchasesPage,
  TreasuryPage,
  ReportsPage,
  CommandPalette,
  NotificationsPopover,
} from './PlatformExtensions';
`;

const footer = `
export default App;
export { App as AdminApp };
`;

const finalJs = header + '\n' + jsContent + '\n' + footer;
const outJsPath = path.resolve(__dirname, 'bags-platform/frontend/apps/backoffice/src/AdminApp.jsx');
fs.writeFileSync(outJsPath, finalJs, 'utf8');
console.log('Successfully generated full AdminApp.jsx with all extended modules and command palette!');

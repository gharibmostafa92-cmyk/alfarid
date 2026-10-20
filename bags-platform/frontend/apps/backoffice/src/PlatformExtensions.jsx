// @ts-nocheck
import React, { useState, useMemo, useEffect } from 'react';

// ==========================================
// MOCK DATA FOR NEW EXTENDED PLATFORM MODULES
// ==========================================

export const MOCK_ORDERS = [
  { id: 'ORD-2026-00412', customer: 'محمد محمود عبد المنعم', phone: '01001234567', channel: 'retail', items: 2, total: 3200, payMethod: 'فودافون كاش', payStatus: 'paid', status: 'delivered', date: 'اليوم 12:40 م', branch: 'فرع مصر الجديدة', address: '١٥ شارع النزهة، مصر الجديدة، القاهرة' },
  { id: 'ORD-2026-00411', customer: 'شركة النيل للتجارة (جملة)', phone: '01112223334', channel: 'wholesale', items: 24, total: 48500, payMethod: 'آجل (ائتماني)', payStatus: 'partial', status: 'processing', date: 'اليوم 10:15 ص', branch: 'المخزن الرئيسي', address: 'مخازن النيل، المنطقة الصناعية، العبور' },
  { id: 'ORD-2026-00410', customer: 'سارة عبد الله يوسف', phone: '01223334445', channel: 'retail', items: 1, total: 1890, payMethod: 'بطاقة بنكية', payStatus: 'paid', status: 'shipped', date: 'أمس 08:20 م', branch: 'فرع الشيخ زايد', address: 'كمبوند الياسمين، فيلا ٤، الشيخ زايد' },
  { id: 'ORD-2026-00409', customer: 'مؤسسة الأهرام للجلود', phone: '01009988776', channel: 'wholesale', items: 50, total: 92000, payMethod: 'تحويل بنكي', payStatus: 'paid', status: 'delivered', date: 'أمس 02:10 م', branch: 'المخزن الرئيسي', address: 'شارع الهرم الرئيسي، الجيزة' },
  { id: 'ORD-2026-00408', customer: 'خالد إبراهيم متولي', phone: '01556677889', channel: 'retail', items: 3, total: 4100, payMethod: 'إنستاباي', payStatus: 'paid', status: 'cancelled', date: 'منذ يومين', branch: 'فرع مصر الجديدة', address: 'مدينة نصر، الحي السابع' },
  { id: 'ORD-2026-00407', customer: 'بوتيك الأناقة - سموحة', phone: '01224466880', channel: 'wholesale', items: 15, total: 28400, payMethod: 'شيك بنكي', payStatus: 'pending', status: 'processing', date: 'منذ ٣ أيام', branch: 'فرع سموحة', address: 'شارع فوزي معاذ، سموحة، الإسكندرية' },
];

export const MOCK_CUSTOMERS = [
  { id: 1, name: 'شركة النيل للتجارة', contact: 'أ. طارق عبد المجيد', phone: '01112223334', type: 'wholesale', tier: 'VIP ذهبي', balance: 15200, limit: 100000, orders: 18, totalSpent: 342000, status: 'active', city: 'القاهرة' },
  { id: 2, name: 'محمد محمود عبد المنعم', contact: 'محمد محمود', phone: '01001234567', type: 'retail', tier: 'عميل دائم', balance: 0, limit: 0, orders: 6, totalSpent: 9800, status: 'active', city: 'القاهرة' },
  { id: 3, name: 'بوتيك الأناقة', contact: 'مدام جيهان فوزي', phone: '01224466880', type: 'wholesale', tier: 'فضي', balance: 42000, limit: 50000, orders: 9, totalSpent: 184000, status: 'active', city: 'الإسكندرية' },
  { id: 4, name: 'سارة عبد الله يوسف', contact: 'سارة عبد الله', phone: '01223334445', type: 'retail', tier: 'عميل جديد', balance: 0, limit: 0, orders: 1, totalSpent: 1890, status: 'active', city: 'الجيزة' },
  { id: 5, name: 'معرض رويال باجز للأحذية والجلود', contact: 'حسام البدري', phone: '01055667788', type: 'wholesale', tier: 'طلب جديد معلق', balance: 0, limit: 0, orders: 0, totalSpent: 0, status: 'pending', city: 'طنطا' },
  { id: 6, name: 'سلسلة جلديات العروبة', contact: 'م. أحمد شكري', phone: '01011224455', type: 'wholesale', tier: 'بلاتيني', balance: 0, limit: 250000, orders: 32, totalSpent: 890000, status: 'active', city: 'القاهرة' },
];

export const MOCK_INVENTORY = [
  { id: 1, name: 'حقيبة يد جلد طبيعي «ليلى»', code: 'PRD-024180', variant: 'أسود / وسط', branch: 'فرع مصر الجديدة', stock: 14, reorder: 5, status: 'ok' },
  { id: 2, name: 'حقيبة يد جلد طبيعي «ليلى»', code: 'PRD-024180', variant: 'عنابي / وسط', branch: 'فرع مصر الجديدة', stock: 2, reorder: 5, status: 'low' },
  { id: 3, name: 'حقيبة كتف «ميرا» كلاسيك', code: 'PRD-024187', variant: 'جملي / موحد', branch: 'المخزن الرئيسي', stock: 48, reorder: 10, status: 'ok' },
  { id: 4, name: 'محفظة كروت جلد «سليم»', code: 'PRD-024194', variant: 'أسود / ميني', branch: 'فرع الشيخ زايد', stock: 0, reorder: 8, status: 'out' },
  { id: 5, name: 'شنطة ظهر لابتوب «أوربان»', code: 'PRD-024201', variant: 'كحلي / 20L', branch: 'المخزن الرئيسي', stock: 25, reorder: 10, status: 'ok' },
  { id: 6, name: 'توت باج «صحارى» كانفاس', code: 'PRD-024208', variant: 'بيج رملي / كبير', branch: 'فرع مصر الجديدة', stock: 3, reorder: 6, status: 'low' },
  { id: 7, name: 'محفظة رجالي «آدم» بايفولد', code: 'PRD-024222', variant: 'بني داكن', branch: 'فرع سموحة', stock: 11, reorder: 4, status: 'ok' },
];

export const MOCK_TRANSFERS = [
  { id: 'TR-2026-0089', from: 'المخزن الرئيسي (العبور)', to: 'فرع مصر الجديدة', items: 40, status: 'in_transit', date: 'اليوم 09:30 ص', by: 'كريم سامي' },
  { id: 'TR-2026-0088', from: 'المخزن الرئيسي (العبور)', to: 'فرع الشيخ زايد', items: 25, status: 'completed', date: 'أمس 04:15 م', by: 'كريم سامي' },
  { id: 'TR-2026-0087', from: 'فرع مصر الجديدة', to: 'مخزن المرتجعات', items: 4, status: 'completed', date: '١٨ أكتوبر', by: 'منى عبد الحميد' },
];

export const MOCK_PURCHASES = [
  { id: 'PO-2026-00128', supplier: 'مصنع الأهرام للجلود الطبيعية', branch: 'المخزن الرئيسي', total: 145000, items: 200, status: 'pending', date: 'اليوم 11:00 ص', creator: 'أحمد الفريد' },
  { id: 'PO-2026-00127', supplier: 'شركة إيطاليا للإكسسوارات والسوست', branch: 'المخزن الرئيسي', total: 38000, items: 1200, status: 'approved', date: 'أمس 01:20 م', creator: 'كريم سامي' },
  { id: 'PO-2026-00126', supplier: 'مدبغة النور للجلود البقرية', branch: 'المخزن الرئيسي', total: 84000, items: 350, status: 'received', date: '١٥ أكتوبر', creator: 'أحمد الفريد' },
];

export const MOCK_TREASURIES = [
  { id: 1, name: 'خزينة الفرع الرئيسي (مصر الجديدة)', balance: 48590, type: 'cash', lastTx: '+3,200 مبيعات' },
  { id: 2, name: 'خزينة فرع الشيخ زايد', balance: 24150, type: 'cash', lastTx: '+1,890 مبيعات' },
  { id: 3, name: 'الحساب البنكي (البنك التجاري الدولي CIB)', balance: 342000, type: 'bank', lastTx: '+92,000 جملة' },
  { id: 4, name: 'محفظة فودافون كاش & إنستاباي', balance: 18420, type: 'digital', lastTx: '+4,100 مبيعات' },
];

export const MOCK_TRANSACTIONS = [
  { id: 'TX-9041', treasury: 'خزينة الفرع الرئيسي', type: 'in', category: 'مبيعات نقدية', amount: 3200, date: 'اليوم 12:40 م', desc: 'فاتورة ORD-00412' },
  { id: 'TX-9040', treasury: 'الحساب البنكي CIB', type: 'in', category: 'تحصيل جملة', amount: 92000, date: 'أمس 02:10 م', desc: 'مؤسسة الأهرام للجلود' },
  { id: 'TX-9039', treasury: 'خزينة الفرع الرئيسي', type: 'out', category: 'مصروفات تشغيل', amount: 750, date: 'أمس 11:00 ص', desc: 'شراء أدوات تغليف وكراتين' },
  { id: 'TX-9038', treasury: 'الحساب البنكي CIB', type: 'out', category: 'دفعة مورد', amount: 38000, date: '١٨ أكتوبر', desc: 'سداد أمر شراء PO-00127' },
];

export const MOCK_NOTIFICATIONS = [
  { id: 1, title: 'طلب انضمام جملة معلق', text: 'معرض رويال باجز بطنطا قدم طلب فتح حساب تاجر جملة.', time: 'منذ ١٠ دقائق', unread: true, tone: 'warn', icon: 'fa-solid fa-user-plus' },
  { id: 2, title: 'تنبيه مخزون منخفض', text: 'محفظة سليم كروت (أسود) رصيدها نافد في فرع الشيخ زايد.', time: 'منذ ساعة', unread: true, tone: 'danger', icon: 'fa-solid fa-triangle-exclamation' },
  { id: 3, title: 'طلب شراء بانتظار الاعتماد', text: 'أمر الشراء PO-2026-00128 بقيمة 145,000 EGP بانتظار موافقتك.', time: 'منذ ساعتين', unread: true, tone: 'info', icon: 'fa-solid fa-file-signature' },
  { id: 4, title: 'اكتمال تحويل مخزني', text: 'تم استلام الشحنة TR-2026-0088 في فرع الشيخ زايد بنجاح.', time: 'أمس 04:30 م', unread: false, tone: 'ok', icon: 'fa-solid fa-circle-check' },
];

// Helper to format currency
const fmtMoney = n => n == null ? '—' : Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });

// ==========================================
// 1. DASHBOARD PAGE (الرئيسية ولوحة المعلومات)
// ==========================================
export function DashboardPage() {
  const { go } = window.useApp ? window.useApp() : { go: () => {} };

  return (
    <>
      <div className="page-band has-decor">
        <div className="page-band-in">
          <div>
            <nav className="crumbs"><span className="cur">لوحة المعلومات الرئيسية</span></nav>
            <h1 className="page-title"><span className="lt">نظرة</span> <span className="bd">عامة</span></h1>
            <p className="page-sub">ملخص حي ومباشر لأداء المبيعات، حركة الفروع، والعمليات المعلقة عبر منصة الفريد.</p>
          </div>
          <div className="page-actions">
            <button className="btn sm ghost" onClick={() => go('/reports')}><i className="fa-solid fa-chart-line"></i>التقارير المفصلة</button>
            <button className="btn sm" onClick={() => go('/products/new')}><i className="fa-solid fa-plus"></i>إضافة منتج</button>
          </div>
        </div>
      </div>

      <div className="content" data-screen-label="Dashboard">
        {/* KPI Grid */}
        <div className="kpi-grid">
          <div className="kpi-card" onClick={() => go('/orders')} style={{ cursor: 'pointer' }}>
            <div className="kpi-head">
              <span className="kpi-label">مبيعات اليوم</span>
              <div className="kpi-icon"><i className="fa-solid fa-coins" style={{ color: 'var(--accent)' }}></i></div>
            </div>
            <div className="kpi-val">48,590 <small style={{ fontSize: 13, fontWeight: 400 }}>EGP</small></div>
            <div className="kpi-sub">
              <span className="kpi-trend up"><i className="fa-solid fa-arrow-trend-up"></i>+14.2%</span>
              <span>مقارنة بالأمس</span>
            </div>
          </div>

          <div className="kpi-card" onClick={() => go('/orders')} style={{ cursor: 'pointer' }}>
            <div className="kpi-head">
              <span className="kpi-label">إجمالي طلبات اليوم</span>
              <div className="kpi-icon"><i className="fa-solid fa-receipt"></i></div>
            </div>
            <div className="kpi-val">28 <small style={{ fontSize: 13, fontWeight: 400 }}>طلب</small></div>
            <div className="kpi-sub">
              <span className="badge ok" style={{ fontSize: 10 }}>19 تجزئة</span>
              <span className="badge ink" style={{ fontSize: 10 }}>9 جملة</span>
            </div>
          </div>

          <div className="kpi-card" onClick={() => go('/treasury')} style={{ cursor: 'pointer' }}>
            <div className="kpi-head">
              <span className="kpi-label">إجمالي السيولة والخزائن</span>
              <div className="kpi-icon"><i className="fa-solid fa-vault"></i></div>
            </div>
            <div className="kpi-val">433,160 <small style={{ fontSize: 13, fontWeight: 400 }}>EGP</small></div>
            <div className="kpi-sub">
              <span>٤ خزائن وحسابات بنكية</span>
            </div>
          </div>

          <div className="kpi-card" onClick={() => go('/inventory')} style={{ cursor: 'pointer' }}>
            <div className="kpi-head">
              <span className="kpi-label">أصناف حرجة (مخزون منخفض)</span>
              <div className="kpi-icon" style={{ background: 'var(--danger-soft)' }}><i className="fa-solid fa-triangle-exclamation" style={{ color: 'var(--danger)' }}></i></div>
            </div>
            <div className="kpi-val" style={{ color: 'var(--danger)' }}>4 <small style={{ fontSize: 13, fontWeight: 400 }}>أصناف</small></div>
            <div className="kpi-sub">
              <span>تحت حد إعادة الطلب الأدنى</span>
            </div>
          </div>
        </div>

        {/* Charts & Operational Highlights */}
        <div className="grid g2" style={{ marginBottom: 28, alignItems: 'stretch' }}>
          {/* Weekly Sales Chart */}
          <div className="chart-box">
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
              <div>
                <h3 style={{ fontFamily: 'var(--display)', fontSize: 15, fontWeight: 600 }}>حركة المبيعات خلال الأسبوع</h3>
                <span className="muted" style={{ fontSize: 11.5 }}>إجمالي الأسبوع: 286,400 EGP</span>
              </div>
              <span className="badge ok"><i className="fa-solid fa-arrow-trend-up"></i>نمو مستقر</span>
            </div>

            <div className="chart-bars">
              {[
                { day: 'السبت', val: 32, label: '32k' },
                { day: 'الأحد', val: 28, label: '28k' },
                { day: 'الإثنين', val: 41, label: '41k' },
                { day: 'الثلاثاء', val: 36, label: '36k' },
                { day: 'الأربعاء', val: 52, label: '52k' },
                { day: 'الخميس', val: 68, label: '68k', active: true },
                { day: 'اليوم', val: 49, label: '49k' },
              ].map(c => (
                <div key={c.day} className="chart-col">
                  <span className="chart-val">{c.label}</span>
                  <div className={'chart-bar ' + (c.active ? 'active' : '')} style={{ height: `${c.val * 1.8}%` }} title={`${c.day}: ${c.label} EGP`}></div>
                  <span className="chart-lbl">{c.day}</span>
                </div>
              ))}
            </div>
          </div>

          {/* Quick Action Alerts */}
          <div className="panel" style={{ display: 'flex', flexDirection: 'column' }}>
            <div className="panel-h">
              <h3>تنبيهات العمليات والاعتمادات</h3>
              <span className="sp"></span>
              <span className="badge warn">٣ معلقات</span>
            </div>
            <div className="panel-b" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 12 }}>
              <div className="alert warn" style={{ margin: 0 }}>
                <i className="fa-solid fa-file-signature"></i>
                <div className="grow">
                  <b>أمر شراء بحاجة لاعتماد</b>
                  <p style={{ fontSize: 12, marginTop: 2 }}>PO-2026-00128 من مصنع الأهرام للجلود بقيمة 145,000 EGP.</p>
                </div>
                <button className="btn sm" onClick={() => go('/purchases')}>مراجعة</button>
              </div>

              <div className="alert info" style={{ margin: 0 }}>
                <i className="fa-solid fa-user-clock"></i>
                <div className="grow">
                  <b>طلب تاجر جملة جديد</b>
                  <p style={{ fontSize: 12, marginTop: 2 }}>معرض رويال باجز (طنطا) ينتظر تفعيل حسابه التجاري.</p>
                </div>
                <button className="btn sm ghost" onClick={() => go('/customers')}>اعتماد</button>
              </div>

              <div className="alert danger" style={{ margin: 0 }}>
                <i className="fa-solid fa-boxes-packing"></i>
                <div className="grow">
                  <b>رصيد منعدم في فرع الشيخ زايد</b>
                  <p style={{ fontSize: 12, marginTop: 2 }}>محفظة كروت جلد «سليم» (أسود) نفدت بالكامل.</p>
                </div>
                <button className="btn sm ghost" onClick={() => go('/inventory')}>تحويل مخزن</button>
              </div>
            </div>
          </div>
        </div>

        {/* Latest Orders Table */}
        <div className="panel">
          <div className="panel-h">
            <div>
              <h3>أحدث طلبات البيع اليوم</h3>
              <p>متابعة لحظية للفواتير الصادرة للعملاء وتجار الجملة.</p>
            </div>
            <span className="sp"></span>
            <button className="btn sm ghost" onClick={() => go('/orders')}>عرض كل الطلبات ({MOCK_ORDERS.length}) ←</button>
          </div>
          <table className="tbl">
            <thead>
              <tr>
                <th>رقم الطلب</th>
                <th>العميل</th>
                <th>القناة</th>
                <th>الفرع</th>
                <th className="num">الإجمالي</th>
                <th>التحصيل</th>
                <th>الحالة</th>
                <th className="act"></th>
              </tr>
            </thead>
            <tbody>
              {MOCK_ORDERS.slice(0, 5).map(o => (
                <tr key={o.id} onClick={() => go('/orders')} style={{ cursor: 'pointer' }}>
                  <td><b className="mono ltr" style={{ display: 'inline-block' }}>{o.id}</b></td>
                  <td><div><b>{o.customer}</b><small className="muted ltr" style={{ display: 'block', textAlign: 'right' }}>{o.phone}</small></div></td>
                  <td><span className="badge line">{o.channel === 'wholesale' ? 'جملة' : 'تجزئة'}</span></td>
                  <td>{o.branch}</td>
                  <td className="num"><span className="ltr" style={{ display: 'inline-block' }}>{fmtMoney(o.total)} <small className="muted">EGP</small></span></td>
                  <td><span className={'badge ' + (o.payStatus === 'paid' ? 'ok' : o.payStatus === 'partial' ? 'warn' : 'line')}>{o.payStatus === 'paid' ? 'مدفوع' : o.payStatus === 'partial' ? 'تحصيل جزئي' : 'معلق'}</span></td>
                  <td><span className={'badge ' + (o.status === 'delivered' ? 'ok' : o.status === 'shipped' ? 'accent' : o.status === 'cancelled' ? 'danger' : 'warn')}>{o.status === 'delivered' ? 'مكتمل' : o.status === 'shipped' ? 'تم الشحن' : o.status === 'cancelled' ? 'ملغي' : 'قيد التجهيز'}</span></td>
                  <td className="act"><button className="icon-btn" title="تفاصيل"><i className="fa-solid fa-arrow-left"></i></button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}

// ==========================================
// 2. ORDERS PAGE (الطلبات والمبيعات)
// ==========================================
export function OrdersPage() {
  const { toast } = window.useApp ? window.useApp() : { toast: () => {} };
  const [selectedOrder, setSelectedOrder] = useState(null);
  const [q, setQ] = useState('');
  const [channelFilter, setChannelFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [orders, setOrders] = useState(MOCK_ORDERS);

  const filtered = useMemo(() => {
    return orders.filter(o => {
      if (q && !(o.id + o.customer + o.phone).toLowerCase().includes(q.toLowerCase())) return false;
      if (channelFilter && o.channel !== channelFilter) return false;
      if (statusFilter && o.status !== statusFilter) return false;
      return true;
    });
  }, [orders, q, channelFilter, statusFilter]);

  const updateOrderStatus = (id, newSt) => {
    setOrders(prev => prev.map(o => o.id === id ? { ...o, status: newSt } : o));
    if (selectedOrder && selectedOrder.id === id) {
      setSelectedOrder(prev => ({ ...prev, status: newSt }));
    }
    toast && toast(`تم تحديث حالة الطلب إلى «${newSt === 'shipped' ? 'تم الشحن' : newSt === 'delivered' ? 'مكتمل' : 'ملغي'}»`);
  };

  return (
    <>
      <div className="page-band">
        <div className="page-band-in">
          <div>
            <nav className="crumbs"><a href="#/dashboard">الرئيسية</a><span className="sep">/</span><span className="cur">المبيعات والطلبات</span></nav>
            <h1 className="page-title"><span className="lt">فواتير</span> <span className="bd">المبيعات</span></h1>
            <p className="page-sub">سجل الطلبات الصادرة للعملاء وتجار الجملة مع متابعة حالات التحصيل والشحن وطباعة الإيصالات.</p>
          </div>
          <div className="page-actions">
            <button className="btn sm ghost" onClick={() => toast('جاري تصدير ملف إكسيل…')}><i className="fa-solid fa-file-excel"></i>تصدير</button>
            <button className="btn sm" onClick={() => toast('لإنشاء طلب كاشير فوري انتقل لنقطة البيع POS')}><i className="fa-solid fa-plus"></i>طلب جديد</button>
          </div>
        </div>
      </div>

      <div className="content" data-screen-label="Orders">
        <div className="dt">
          <div className="dt-toolbar">
            <div className="dt-search">
              <i className="fa-solid fa-magnifying-glass"></i>
              <input className="inp" placeholder="ابحث برقم الطلب، اسم العميل، الهاتف…" value={q} onChange={e => setQ(e.target.value)} />
            </div>
            <div className="dt-filters">
              <select className="mini-sel" value={channelFilter} onChange={e => setChannelFilter(e.target.value)}>
                <option value="">كل القنوات</option>
                <option value="retail">تجزئة</option>
                <option value="wholesale">جملة</option>
              </select>

              <select className="mini-sel" value={statusFilter} onChange={e => setStatusFilter(e.target.value)}>
                <option value="">كل الحالات</option>
                <option value="processing">قيد التجهيز</option>
                <option value="shipped">تم الشحن</option>
                <option value="delivered">مكتمل</option>
                <option value="cancelled">ملغي</option>
              </select>
            </div>
            <span style={{ marginInlineStart: 'auto', fontSize: 12, color: 'var(--ink-4)' }}>{filtered.length} طلب</span>
          </div>

          <table className="tbl">
            <thead>
              <tr>
                <th>رقم الطلب</th>
                <th>العميل</th>
                <th>القناة</th>
                <th>طريقة الدفع</th>
                <th className="num">الإجمالي</th>
                <th>التحصيل</th>
                <th>حالة الطلب</th>
                <th>التاريخ والوقت</th>
                <th className="act"></th>
              </tr>
            </thead>
            <tbody>
              {filtered.map(o => (
                <tr key={o.id} onClick={() => setSelectedOrder(o)} style={{ cursor: 'pointer' }}>
                  <td><b className="mono ltr" style={{ display: 'inline-block' }}>{o.id}</b></td>
                  <td>
                    <div>
                      <b>{o.customer}</b>
                      <small className="muted ltr" style={{ display: 'block', textAlign: 'right' }}>{o.phone}</small>
                    </div>
                  </td>
                  <td><span className={'badge ' + (o.channel === 'wholesale' ? 'ink' : 'line')}>{o.channel === 'wholesale' ? 'جملة' : 'تجزئة'}</span></td>
                  <td><span className="mono" style={{ fontSize: 12 }}>{o.payMethod}</span></td>
                  <td className="num"><b className="ltr" style={{ display: 'inline-block' }}>{fmtMoney(o.total)}</b> <small className="muted">EGP</small></td>
                  <td>
                    <span className={'badge ' + (o.payStatus === 'paid' ? 'ok' : o.payStatus === 'partial' ? 'warn' : 'line')}>
                      {o.payStatus === 'paid' ? 'مدفوع' : o.payStatus === 'partial' ? 'تحصيل جزئي' : 'معلق'}
                    </span>
                  </td>
                  <td>
                    <span className={'badge ' + (o.status === 'delivered' ? 'ok' : o.status === 'shipped' ? 'accent' : o.status === 'cancelled' ? 'danger' : 'warn')}>
                      {o.status === 'delivered' ? 'مكتمل' : o.status === 'shipped' ? 'تم الشحن' : o.status === 'cancelled' ? 'ملغي' : 'قيد التجهيز'}
                    </span>
                  </td>
                  <td><span className="muted" style={{ fontSize: 12 }}>{o.date}</span></td>
                  <td className="act">
                    <button className="icon-btn" onClick={e => { e.stopPropagation(); setSelectedOrder(o); }} title="عرض"><i className="fa-regular fa-eye"></i></button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Order Details Drawer */}
        {selectedOrder && (
          <div className="scrim drawer-scrim" onClick={() => setSelectedOrder(null)}>
            <div className="drawer" onClick={e => e.stopPropagation()}>
              <div className="drawer-h">
                <div>
                  <div className="crumbs"><span className="cur">{selectedOrder.id}</span></div>
                  <h3>تفاصيل الطلب</h3>
                </div>
                <span style={{ marginInlineStart: 'auto' }}></span>
                <span className={'badge ' + (selectedOrder.status === 'delivered' ? 'ok' : selectedOrder.status === 'shipped' ? 'accent' : 'warn')}>
                  {selectedOrder.status === 'delivered' ? 'مكتمل' : selectedOrder.status === 'shipped' ? 'تم الشحن' : 'قيد المعالجة'}
                </span>
                <button className="icon-btn" onClick={() => setSelectedOrder(null)}><i className="fa-solid fa-xmark"></i></button>
              </div>

              <div className="drawer-b">
                <div className="panel" style={{ marginBottom: 18, background: 'var(--soft-2)' }}>
                  <div className="panel-b grid g2">
                    <div>
                      <small className="muted" style={{ display: 'block', marginBottom: 2 }}>اسم العميل</small>
                      <b>{selectedOrder.customer}</b>
                    </div>
                    <div>
                      <small className="muted" style={{ display: 'block', marginBottom: 2 }}>الهاتف</small>
                      <b className="mono ltr" style={{ display: 'block', textAlign: 'right' }}>{selectedOrder.phone}</b>
                    </div>
                    <div className="spanall">
                      <small className="muted" style={{ display: 'block', marginBottom: 2 }}>عنوان التوصيل</small>
                      <span>{selectedOrder.address}</span>
                    </div>
                  </div>
                </div>

                <div className="sec-title">بنود الفاتورة</div>
                <div style={{ border: '1px solid var(--line)', borderRadius: 'var(--r2)', overflow: 'hidden', marginBottom: 20 }}>
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>المنتج</th>
                        <th className="num">الكمية</th>
                        <th className="num">السعر</th>
                        <th className="num">الإجمالي</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>
                          <b>حقيبة يد جلد طبيعي «ليلى»</b>
                          <small className="muted" style={{ display: 'block' }}>أسود · PRD-024180</small>
                        </td>
                        <td className="num">{selectedOrder.items}</td>
                        <td className="num ltr">{fmtMoney(selectedOrder.total / selectedOrder.items)}</td>
                        <td className="num ltr"><b>{fmtMoney(selectedOrder.total)}</b></td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div className="sec-title">تحديث حالة الشحن والتسليم</div>
                <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                  <button className="btn sm ghost" onClick={() => updateOrderStatus(selectedOrder.id, 'processing')}><i className="fa-solid fa-boxes-packing"></i>قيد التجهيز</button>
                  <button className="btn sm accent" onClick={() => updateOrderStatus(selectedOrder.id, 'shipped')}><i className="fa-solid fa-truck-fast"></i>تم الشحن</button>
                  <button className="btn sm" onClick={() => updateOrderStatus(selectedOrder.id, 'delivered')}><i className="fa-solid fa-check"></i>تسليم ناجح (مكتمل)</button>
                  <button className="btn sm danger ghost" onClick={() => updateOrderStatus(selectedOrder.id, 'cancelled')}><i className="fa-solid fa-ban"></i>إلغاء الطلب</button>
                </div>
              </div>

              <div className="drawer-f">
                <button className="btn ghost" onClick={() => toast('جاري فتح الفاتورة للطباعة…')}><i className="fa-solid fa-print"></i>طباعة الفاتورة</button>
                <span style={{ marginInlineStart: 'auto' }}></span>
                <button className="btn" onClick={() => setSelectedOrder(null)}>إغلاق</button>
              </div>
            </div>
          </div>
        )}
      </div>
    </>
  );
}

// ==========================================
// 3. CUSTOMERS PAGE (العملاء وتجار الجملة)
// ==========================================
export function CustomersPage() {
  const { toast } = window.useApp ? window.useApp() : { toast: () => {} };
  const [tab, setTab] = useState('all');
  const [q, setQ] = useState('');
  const [customers, setCustomers] = useState(MOCK_CUSTOMERS);

  const approveWholesale = id => {
    setCustomers(prev => prev.map(c => c.id === id ? { ...c, status: 'active', tier: 'فضي', limit: 50000 } : c));
    toast && toast('تم اعتماد تاجر الجملة بنجاح وتفعيل حسابه.');
  };

  const filtered = customers.filter(c => {
    if (tab === 'pending') return c.status === 'pending';
    if (tab === 'wholesale') return c.type === 'wholesale';
    if (tab === 'retail') return c.type === 'retail';
    return true;
  }).filter(c => !q || (c.name + c.contact + c.phone + c.city).toLowerCase().includes(q.toLowerCase()));

  const pendingCount = customers.filter(c => c.status === 'pending').length;

  return (
    <>
      <div className="page-band">
        <div className="page-band-in">
          <div>
            <nav className="crumbs"><a href="#/dashboard">الرئيسية</a><span className="sep">/</span><span className="cur">العملاء والجملة</span></nav>
            <h1 className="page-title"><span className="lt">قاعدة</span> <span className="bd">العملاء والجملة</span></h1>
            <p className="page-sub">إدارة العملاء، الأرصدة والحدود الائتمانية، واعتماد طلبات الانضمام المعلقة لتجار الجملة.</p>
          </div>
          <div className="page-actions">
            <button className="btn sm" onClick={() => toast('لإضافة تاجر جملة جديد استخدم شاشة تسجيل الجملة')}><i className="fa-solid fa-plus"></i>عميل جديد</button>
          </div>
        </div>
      </div>

      <div className="content" data-screen-label="Customers">
        <div className="tabs">
          <button className={'tab ' + (tab === 'all' ? 'on' : '')} onClick={() => setTab('all')}>جميع العملاء <span className="n">{customers.length}</span></button>
          <button className={'tab ' + (tab === 'wholesale' ? 'on' : '')} onClick={() => setTab('wholesale')}>تجار الجملة <span className="n">{customers.filter(c => c.type === 'wholesale').length}</span></button>
          <button className={'tab ' + (tab === 'retail' ? 'on' : '')} onClick={() => setTab('retail')}>عملاء التجزئة <span className="n">{customers.filter(c => c.type === 'retail').length}</span></button>
          <button className={'tab ' + (tab === 'pending' ? 'on' : '')} onClick={() => setTab('pending')}>
            طلبات جملة معلقة {pendingCount > 0 && <span className="errdot"></span>}
            <span className="n" style={pendingCount > 0 ? { background: 'var(--warn-soft)', color: 'var(--warn)' } : null}>{pendingCount}</span>
          </button>
        </div>

        <div className="dt">
          <div className="dt-toolbar">
            <div className="dt-search">
              <i className="fa-solid fa-magnifying-glass"></i>
              <input className="inp" placeholder="ابحث بالاسم، المسؤول، الهاتف، المدينة…" value={q} onChange={e => setQ(e.target.value)} />
            </div>
            <span style={{ marginInlineStart: 'auto', fontSize: 12, color: 'var(--ink-4)' }}>{filtered.length} عميل</span>
          </div>

          <table className="tbl">
            <thead>
              <tr>
                <th>العميل</th>
                <th>النوع / الفئة</th>
                <th>المدينة</th>
                <th>رصيد المديونية</th>
                <th>الحد الائتماني</th>
                <th className="num">الطلبات</th>
                <th>الحالة</th>
                <th className="act"></th>
              </tr>
            </thead>
            <tbody>
              {filtered.map(c => (
                <tr key={c.id}>
                  <td>
                    <div>
                      <b>{c.name}</b>
                      <small className="muted" style={{ display: 'block' }}>المسؤول: {c.contact} · <span className="mono ltr">{c.phone}</span></small>
                    </div>
                  </td>
                  <td>
                    <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                      <span className={'badge ' + (c.type === 'wholesale' ? 'ink' : 'line')}>{c.type === 'wholesale' ? 'جملة' : 'تجزئة'}</span>
                      <span className="badge line">{c.tier}</span>
                    </div>
                  </td>
                  <td>{c.city}</td>
                  <td>
                    <b className={'ltr ' + (c.balance > 0 ? 'muted' : '')} style={c.balance > 0 ? { color: 'var(--warn)' } : null}>
                      {fmtMoney(c.balance)} EGP
                    </b>
                  </td>
                  <td><span className="mono">{c.limit ? `${fmtMoney(c.limit)} EGP` : '—'}</span></td>
                  <td className="num">{c.orders}</td>
                  <td>
                    <span className={'badge ' + (c.status === 'active' ? 'ok' : 'warn')}>
                      {c.status === 'active' ? 'نشط' : 'بانتظار الاعتماد'}
                    </span>
                  </td>
                  <td className="act">
                    {c.status === 'pending' ? (
                      <button className="btn sm ok" onClick={() => approveWholesale(c.id)}><i className="fa-solid fa-check"></i>اعتماد</button>
                    ) : (
                      <button className="icon-btn" title="كشف حساب" onClick={() => toast(`كشف حساب: ${c.name}`)}><i className="fa-solid fa-file-invoice"></i></button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}

// ==========================================
// 4. INVENTORY & TRANSFERS PAGE (المخزون)
// ==========================================
export function InventoryPage() {
  const { toast } = window.useApp ? window.useApp() : { toast: () => {} };
  const [tab, setTab] = useState('stocks');
  const [openTransfer, setOpenTransfer] = useState(false);
  const [q, setQ] = useState('');
  const [branchFilter, setBranchFilter] = useState('');

  const filteredStock = MOCK_INVENTORY.filter(item => {
    if (q && !(item.name + item.code + item.variant).toLowerCase().includes(q.toLowerCase())) return false;
    if (branchFilter && item.branch !== branchFilter) return false;
    return true;
  });

  return (
    <>
      <div className="page-band">
        <div className="page-band-in">
          <div>
            <nav className="crumbs"><a href="#/dashboard">الرئيسية</a><span className="sep">/</span><span className="cur">المخزون والتحويلات</span></nav>
            <h1 className="page-title"><span className="lt">إدارة</span> <span className="bd">المخزون والمستودعات</span></h1>
            <p className="page-sub">متابعة أرصدة الحقائب والمحافظ بكل فرع، التحويلات بين المستودعات، وتنبيهات إعادة الطلب.</p>
          </div>
          <div className="page-actions">
            <button className="btn sm ghost" onClick={() => toast('جاري فتح نموذج تسوية الجرد…')}><i className="fa-solid fa-scale-balanced"></i>تسوية جرد</button>
            <button className="btn sm" onClick={() => setOpenTransfer(true)}><i className="fa-solid fa-dolly"></i>تحويل مخزني جديد</button>
          </div>
        </div>
      </div>

      <div className="content" data-screen-label="Inventory">
        <div className="tabs">
          <button className={'tab ' + (tab === 'stocks' ? 'on' : '')} onClick={() => setTab('stocks')}>أرصدة الأصناف <span className="n">{MOCK_INVENTORY.length}</span></button>
          <button className={'tab ' + (tab === 'transfers' ? 'on' : '')} onClick={() => setTab('transfers')}>التحويلات بين الفروع <span className="n">{MOCK_TRANSFERS.length}</span></button>
        </div>

        {tab === 'stocks' && (
          <div className="dt">
            <div className="dt-toolbar">
              <div className="dt-search">
                <i className="fa-solid fa-magnifying-glass"></i>
                <input className="inp" placeholder="ابحث باسم المنتج، الكود، المتغير…" value={q} onChange={e => setQ(e.target.value)} />
              </div>
              <div className="dt-filters">
                <select className="mini-sel" value={branchFilter} onChange={e => setBranchFilter(e.target.value)}>
                  <option value="">كل الفروع والمخازن</option>
                  <option value="فرع مصر الجديدة">فرع مصر الجديدة</option>
                  <option value="فرع الشيخ زايد">فرع الشيخ زايد</option>
                  <option value="المخزن الرئيسي">المخزن الرئيسي</option>
                  <option value="فرع سموحة">فرع سموحة</option>
                </select>
              </div>
              <span style={{ marginInlineStart: 'auto', fontSize: 12, color: 'var(--ink-4)' }}>{filteredStock.length} صنف</span>
            </div>

            <table className="tbl">
              <thead>
                <tr>
                  <th>المنتج</th>
                  <th>المتغير</th>
                  <th>الفرع / الموقع</th>
                  <th className="num">الرصيد الفعلي</th>
                  <th className="num">حد الطلب الأدنى</th>
                  <th>حالة المخزون</th>
                  <th className="act"></th>
                </tr>
              </thead>
              <tbody>
                {filteredStock.map(i => (
                  <tr key={i.id}>
                    <td>
                      <div>
                        <b>{i.name}</b>
                        <small className="mono ltr" style={{ display: 'block', textAlign: 'right' }}>{i.code}</small>
                      </div>
                    </td>
                    <td><span className="badge line">{i.variant}</span></td>
                    <td>{i.branch}</td>
                    <td className="num"><b>{i.stock}</b> قطعة</td>
                    <td className="num">{i.reorder} قطعة</td>
                    <td>
                      <span className={'badge ' + (i.status === 'ok' ? 'ok' : i.status === 'low' ? 'warn' : 'danger')}>
                        {i.status === 'ok' ? 'متوفر' : i.status === 'low' ? 'قارب على النفاد' : 'رصيد منعدم'}
                      </span>
                    </td>
                    <td className="act">
                      <button className="btn sm ghost" onClick={() => setOpenTransfer(true)}><i className="fa-solid fa-share"></i>تحويل</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {tab === 'transfers' && (
          <div className="dt">
            <table className="tbl">
              <thead>
                <tr>
                  <th>رقم التحويل</th>
                  <th>من مستودع</th>
                  <th>إلى مستودع</th>
                  <th className="num">عدد القطع</th>
                  <th>بواسطة</th>
                  <th>الحالة</th>
                  <th>التاريخ</th>
                </tr>
              </thead>
              <tbody>
                {MOCK_TRANSFERS.map(t => (
                  <tr key={t.id}>
                    <td><b className="mono ltr" style={{ display: 'inline-block' }}>{t.id}</b></td>
                    <td>{t.from}</td>
                    <td>{t.to}</td>
                    <td className="num"><b>{t.items}</b></td>
                    <td>{t.by}</td>
                    <td>
                      <span className={'badge ' + (t.status === 'completed' ? 'ok' : 'warn')}>
                        {t.status === 'completed' ? 'مكتمل الاستلام' : 'جاري النقل'}
                      </span>
                    </td>
                    <td><small className="muted">{t.date}</small></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* New Transfer Modal */}
        {openTransfer && (
          <div className="scrim" onClick={() => setOpenTransfer(false)}>
            <div className="modal" onClick={e => e.stopPropagation()}>
              <div className="modal-b">
                <div className="mi"><i className="fa-solid fa-dolly"></i></div>
                <div style={{ flex: 1 }}>
                  <h4>إنشاء تحويل مخزني جديد</h4>
                  <p>نقل بضاعة بين فروع ومستودعات المنصة مع تسجيل حركات الخروج والاستلام.</p>
                  <div className="grid g2" style={{ marginTop: 14 }}>
                    <div className="field">
                      <label className="field-label">من فرع / مخزن</label>
                      <select className="inp"><option>المخزن الرئيسي (العبور)</option><option>فرع مصر الجديدة</option></select>
                    </div>
                    <div className="field">
                      <label className="field-label">إلى فرع / مخزن</label>
                      <select className="inp"><option>فرع الشيخ زايد</option><option>فرع مصر الجديدة</option></select>
                    </div>
                    <div className="field spanall">
                      <label className="field-label">المنتج والكمية</label>
                      <input className="inp" placeholder="اختر الصنف واكتب الكمية…" defaultValue="حقيبة يد ليلى أسود — ٢٠ قطعة" />
                    </div>
                  </div>
                </div>
              </div>
              <div className="modal-f">
                <button className="btn" onClick={() => { setOpenTransfer(false); toast('تم إنشاء أمر التحويل وجاري تجهيز الشحنة.'); }}>إصدار أمر التحويل</button>
                <button className="btn ghost" onClick={() => setOpenTransfer(false)}>إلغاء</button>
              </div>
            </div>
          </div>
        )}
      </div>
    </>
  );
}

// ==========================================
// 5. PURCHASES PAGE (أوامر الشراء والموردين)
// ==========================================
export function PurchasesPage() {
  const { toast } = window.useApp ? window.useApp() : { toast: () => {} };
  const [purchases, setPurchases] = useState(MOCK_PURCHASES);

  const approvePurchase = id => {
    setPurchases(prev => prev.map(p => p.id === id ? { ...p, status: 'approved' } : p));
    toast && toast(`تم اعتماد أمر الشراء ${id} بنجاح وإرساله للمورد.`);
  };

  return (
    <>
      <div className="page-band">
        <div className="page-band-in">
          <div>
            <nav className="crumbs"><a href="#/dashboard">الرئيسية</a><span className="sep">/</span><span className="cur">أوامر الشراء</span></nav>
            <h1 className="page-title"><span className="lt">أوامر</span> <span className="bd">الشراء والتوريد</span></h1>
            <p className="page-sub">إصدار ومتابعة أوامر الشراء الموجهة للمصانع والموردين، واعتماد استلامات المخزن.</p>
          </div>
          <div className="page-actions">
            <button className="btn sm" onClick={() => toast('جاري فتح نموذج أمر شراء جديد…')}><i className="fa-solid fa-plus"></i>أمر شراء جديد</button>
          </div>
        </div>
      </div>

      <div className="content" data-screen-label="Purchases">
        <div className="dt">
          <table className="tbl">
            <thead>
              <tr>
                <th>رقم الأمر</th>
                <th>المورد</th>
                <th>المخزن المستلم</th>
                <th className="num">إجمالي التكلفة</th>
                <th className="num">البنود</th>
                <th>المنشئ</th>
                <th>الحالة</th>
                <th className="act"></th>
              </tr>
            </thead>
            <tbody>
              {purchases.map(p => (
                <tr key={p.id}>
                  <td><b className="mono ltr" style={{ display: 'inline-block' }}>{p.id}</b></td>
                  <td><b>{p.supplier}</b></td>
                  <td>{p.branch}</td>
                  <td className="num"><b className="ltr" style={{ display: 'inline-block' }}>{fmtMoney(p.total)}</b> <small className="muted">EGP</small></td>
                  <td className="num">{p.items} قطعة</td>
                  <td>{p.creator}</td>
                  <td>
                    <span className={'badge ' + (p.status === 'received' ? 'ok' : p.status === 'approved' ? 'accent' : 'warn')}>
                      {p.status === 'received' ? 'تم الاستلام' : p.status === 'approved' ? 'معتمد' : 'بانتظار الاعتماد'}
                    </span>
                  </td>
                  <td className="act">
                    {p.status === 'pending' && (
                      <button className="btn sm" onClick={() => approvePurchase(p.id)}><i className="fa-solid fa-check"></i>اعتماد</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}

// ==========================================
// 6. TREASURY & EXPENSES PAGE (الخزينة والمالية)
// ==========================================
export function TreasuryPage() {
  const { toast } = window.useApp ? window.useApp() : { toast: () => {} };
  const [openModal, setOpenModal] = useState(false);

  return (
    <>
      <div className="page-band">
        <div className="page-band-in">
          <div>
            <nav className="crumbs"><a href="#/dashboard">الرئيسية</a><span className="sep">/</span><span className="cur">الخزينة والمصروفات</span></nav>
            <h1 className="page-title"><span className="lt">الخزائن</span> <span className="bd">والحسابات المالية</span></h1>
            <p className="page-sub">أرصدة الخزائن النقدية للفروع، الحسابات البنكية، والمحافظ الرقمية مع تسجيل المصروفات والإيرادات.</p>
          </div>
          <div className="page-actions">
            <button className="btn sm ghost" onClick={() => toast('فتح نموذج تحويل سيولة بين الخزائن')}><i className="fa-solid fa-arrow-right-arrow-left"></i>تحويل بين خزائن</button>
            <button className="btn sm" onClick={() => setOpenModal(true)}><i className="fa-solid fa-plus"></i>تسجيل حركة مالية</button>
          </div>
        </div>
      </div>

      <div className="content" data-screen-label="Treasury">
        {/* Treasury Cards */}
        <div className="grid g4" style={{ marginBottom: 28 }}>
          {MOCK_TREASURIES.map(t => (
            <div key={t.id} className="panel" style={{ padding: 18 }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                <span className="badge line">{t.type === 'bank' ? 'حساب بنكي' : t.type === 'digital' ? 'محفظة' : 'نقدي'}</span>
                <i className={t.type === 'bank' ? 'fa-solid fa-building-columns' : t.type === 'digital' ? 'fa-solid fa-mobile-screen' : 'fa-solid fa-vault'} style={{ color: 'var(--ink-4)' }}></i>
              </div>
              <h4 style={{ fontFamily: 'var(--display)', fontSize: 14, fontWeight: 600, marginBottom: 6 }}>{t.name}</h4>
              <div className="mono" style={{ fontSize: 20, fontWeight: 700, color: 'var(--ink)' }}>{fmtMoney(t.balance)} <small style={{ fontSize: 11, fontWeight: 400 }}>EGP</small></div>
              <small className="muted" style={{ display: 'block', marginTop: 6 }}>آخر حركة: {t.lastTx}</small>
            </div>
          ))}
        </div>

        {/* Transactions Table */}
        <div className="panel">
          <div className="panel-h">
            <h3>سجل الحركات المالية والمصروفات</h3>
            <span className="sp"></span>
            <span className="muted" style={{ fontSize: 12 }}>أحدث القيود</span>
          </div>
          <table className="tbl">
            <thead>
              <tr>
                <th>كود الحركة</th>
                <th>الخزينة / الحساب</th>
                <th>التصنيف</th>
                <th>البيان والتفاصيل</th>
                <th className="num">المبلغ</th>
                <th>النوع</th>
                <th>التاريخ</th>
              </tr>
            </thead>
            <tbody>
              {MOCK_TRANSACTIONS.map(tx => (
                <tr key={tx.id}>
                  <td><b className="mono ltr" style={{ display: 'inline-block' }}>{tx.id}</b></td>
                  <td><b>{tx.treasury}</b></td>
                  <td><span className="badge line">{tx.category}</span></td>
                  <td>{tx.desc}</td>
                  <td className="num">
                    <b className={'ltr ' + (tx.type === 'in' ? 'ok' : 'danger')} style={{ display: 'inline-block', color: tx.type === 'in' ? 'var(--ok)' : 'var(--danger)' }}>
                      {tx.type === 'in' ? '+' : '-'}{fmtMoney(tx.amount)} EGP
                    </b>
                  </td>
                  <td><span className={'badge ' + (tx.type === 'in' ? 'ok' : 'danger')}>{tx.type === 'in' ? 'إيراد / تحصيل' : 'مصروف / سداد'}</span></td>
                  <td><small className="muted">{tx.date}</small></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Record Transaction Modal */}
        {openModal && (
          <div className="scrim" onClick={() => setOpenModal(false)}>
            <div className="modal" onClick={e => e.stopPropagation()}>
              <div className="modal-b">
                <div className="mi"><i className="fa-solid fa-coins"></i></div>
                <div style={{ flex: 1 }}>
                  <h4>تسجيل حركة مالية جديدة</h4>
                  <p>قيد إيراد مبيعات، أو سداد مصروفات وفواتير تشغيلية.</p>
                  <div className="grid g2" style={{ marginTop: 14 }}>
                    <div className="field">
                      <label className="field-label">النوع</label>
                      <select className="inp"><option>إيراد تحصيل (+)</option><option>مصروف تشغيل (-)</option></select>
                    </div>
                    <div className="field">
                      <label className="field-label">الخزينة</label>
                      <select className="inp">{MOCK_TREASURIES.map(t => <option key={t.id}>{t.name}</option>)}</select>
                    </div>
                    <div className="field spanall">
                      <label className="field-label">المبلغ (EGP)</label>
                      <input className="inp ltr" type="number" placeholder="0.00" defaultValue="1500" />
                    </div>
                    <div className="field spanall">
                      <label className="field-label">البيان</label>
                      <input className="inp" placeholder="سبب القيد…" defaultValue="مصروفات صيانة وتجديد" />
                    </div>
                  </div>
                </div>
              </div>
              <div className="modal-f">
                <button className="btn" onClick={() => { setOpenModal(false); toast('تم قيد الحركة المالية في الخزينة بنجاح.'); }}>حفظ الحركة</button>
                <button className="btn ghost" onClick={() => setOpenModal(false)}>إلغاء</button>
              </div>
            </div>
          </div>
        )}
      </div>
    </>
  );
}

// ==========================================
// 7. REPORTS PAGE (التقارير التحليلية)
// ==========================================
export function ReportsPage() {
  const { toast } = window.useApp ? window.useApp() : { toast: () => {} };

  return (
    <>
      <div className="page-band">
        <div className="page-band-in">
          <div>
            <nav className="crumbs"><a href="#/dashboard">الرئيسية</a><span className="sep">/</span><span className="cur">التقارير</span></nav>
            <h1 className="page-title"><span className="lt">التقارير</span> <span className="bd">والتحليلات المالية</span></h1>
            <p className="page-sub">تحليلات الأرباح، أداء الفروع، حركة دوران الأصناف، ومبيعات قنوات التجزئة والجملة.</p>
          </div>
          <div className="page-actions">
            <button className="btn sm" onClick={() => toast('جاري إنشاء تقرير PDF شامل للمنصة…')}><i className="fa-solid fa-file-pdf"></i>تحميل تقرير PDF</button>
          </div>
        </div>
      </div>

      <div className="content" data-screen-label="Reports">
        {/* KPI Summaries */}
        <div className="kpi-grid">
          <div className="kpi-card">
            <span className="kpi-label">مبيعات الشهر الحالي</span>
            <div className="kpi-val">684,200 <small style={{ fontSize: 13, fontWeight: 400 }}>EGP</small></div>
            <div className="kpi-sub"><span className="kpi-trend up">+18%</span><span>مقارنة بالشهر السابق</span></div>
          </div>

          <div className="kpi-card">
            <span className="kpi-label">صافي الربح التقديري</span>
            <div className="kpi-val" style={{ color: 'var(--ok)' }}>218,900 <small style={{ fontSize: 13, fontWeight: 400 }}>EGP</small></div>
            <div className="kpi-sub"><span>هامش ربح إجمالي ~32%</span></div>
          </div>

          <div className="kpi-card">
            <span className="kpi-label">متوسط قيمة الطلب</span>
            <div className="kpi-val">1,950 <small style={{ fontSize: 13, fontWeight: 400 }}>EGP</small></div>
            <div className="kpi-sub"><span>تجزئة وجملة مجمعة</span></div>
          </div>
        </div>

        {/* Best Sellers */}
        <div className="panel">
          <div className="panel-h">
            <h3>أفضل المنتجات مبيعاً وتحقيقاً للأرباح</h3>
            <span className="sp"></span>
            <span className="muted" style={{ fontSize: 12 }}>أكتوبر 2026</span>
          </div>
          <table className="tbl">
            <thead>
              <tr>
                <th>المنتج</th>
                <th>القسم</th>
                <th className="num">الكمية المباعة</th>
                <th className="num">إجمالي المبيعات</th>
                <th className="num">صافي الربح</th>
                <th>الأداء</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><b>حقيبة يد جلد طبيعي «ليلى»</b></td>
                <td>حقائب يد</td>
                <td className="num">84 قطعة</td>
                <td className="num ltr">214,200 EGP</td>
                <td className="num ltr ok" style={{ color: 'var(--ok)' }}>72,800 EGP</td>
                <td><span className="badge ok">الأعلى مبيعاً ★</span></td>
              </tr>
              <tr>
                <td><b>حقيبة كتف «ميرا» كلاسيك</b></td>
                <td>كروس بودي</td>
                <td className="num">62 قطعة</td>
                <td className="num ltr">117,180 EGP</td>
                <td className="num ltr ok" style={{ color: 'var(--ok)' }}>38,400 EGP</td>
                <td><span className="badge ok">ممتاز</span></td>
              </tr>
              <tr>
                <td><b>محفظة كروت جلد «سليم»</b></td>
                <td>محافظ كروت</td>
                <td className="num">120 قطعة</td>
                <td className="num ltr">46,800 EGP</td>
                <td className="num ltr ok" style={{ color: 'var(--ok)' }}>18,200 EGP</td>
                <td><span className="badge ok">طلب متكرر</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}

// ==========================================
// 8. COMMAND PALETTE (البحث السريع والتنقل الفوري CTRL+K)
// ==========================================
export function CommandPalette({ open, onClose, go }) {
  const [q, setQ] = useState('');

  const items = useMemo(() => [
    { title: 'لوحة المعلومات الرئيسية', path: '/dashboard', cat: 'التنقل الرئيسي', icon: 'fa-solid fa-chart-pie' },
    { title: 'كتالوج المنتجات', path: '/products', cat: 'التنقل الرئيسي', icon: 'fa-solid fa-bag-shopping' },
    { title: 'إضافة منتج جديد', path: '/products/new', cat: 'إجراءات سريعة', icon: 'fa-solid fa-plus' },
    { title: 'فواتير المبيعات والطلبات', path: '/orders', cat: 'التنقل الرئيسي', icon: 'fa-solid fa-receipt' },
    { title: 'العملاء وتجار الجملة', path: '/customers', cat: 'التنقل الرئيسي', icon: 'fa-solid fa-users' },
    { title: 'شجرة الأقسام', path: '/categories', cat: 'الكتالوج', icon: 'fa-solid fa-sitemap' },
    { title: 'قائمة البراندات', path: '/brands', cat: 'الكتالوج', icon: 'fa-solid fa-copyright' },
    { title: 'أرصدة المخزون والتحويلات', path: '/inventory', cat: 'التشغيل', icon: 'fa-solid fa-boxes-stacked' },
    { title: 'أوامر الشراء والتوريد', path: '/purchases', cat: 'التشغيل', icon: 'fa-solid fa-file-invoice-dollar' },
    { title: 'الفروع ومواقع التخزين', path: '/branches', cat: 'التشغيل', icon: 'fa-solid fa-store' },
    { title: 'الخزائن والحسابات المالية', path: '/treasury', cat: 'المالية', icon: 'fa-solid fa-vault' },
    { title: 'التقارير التحليلية', path: '/reports', cat: 'المالية', icon: 'fa-solid fa-chart-line' },
    { title: 'طاقم العمل والمستخدمون', path: '/users', cat: 'الإدارة', icon: 'fa-solid fa-user-group' },
    { title: 'الأدوار والصلاحيات', path: '/roles', cat: 'الإدارة', icon: 'fa-solid fa-shield-halved' },
    { title: 'الإعدادات العامة للنظام', path: '/settings', cat: 'الإدارة', icon: 'fa-solid fa-sliders' },
    { title: 'مرجع المكونات والتصميم', path: '/kit', cat: 'المطور', icon: 'fa-solid fa-swatchbook' },
  ], []);

  const filtered = useMemo(() => {
    if (!q) return items;
    return items.filter(it => (it.title + it.cat).toLowerCase().includes(q.toLowerCase()));
  }, [items, q]);

  useEffect(() => {
    const handleKey = e => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        open ? onClose() : onClose(false); // toggle
      }
      if (e.key === 'Escape' && open) onClose();
    };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="cmd-scrim" onClick={onClose}>
      <div className="cmd-box" onClick={e => e.stopPropagation()}>
        <div className="cmd-head">
          <i className="fa-solid fa-magnifying-glass"></i>
          <input
            autoFocus
            className="cmd-input"
            placeholder="ابحث عن أي شاشة، منتج، فرع، أو إجراء سريع…"
            value={q}
            onChange={e => setQ(e.target.value)}
          />
          <span className="cmd-esc">ESC</span>
        </div>
        <div className="cmd-list">
          {filtered.map(it => (
            <div
              key={it.path}
              className="cmd-item"
              onClick={() => {
                go(it.path);
                onClose();
              }}
            >
              <i className={it.icon}></i>
              <span>{it.title}</span>
              <span className="badge line">{it.cat}</span>
            </div>
          ))}
          {filtered.length === 0 && (
            <div style={{ padding: '24px', textAlign: 'center', color: 'var(--ink-4)', fontSize: 13 }}>
              لا توجد شاشات أو نتائج تطابق بحثك.
            </div>
          )}
        </div>
        <div className="cmd-foot">
          <span><i className="fa-solid fa-arrows-up-down" style={{ marginInlineEnd: 4 }}></i>للتنقل</span>
          <span><i className="fa-solid fa-arrow-turn-down" style={{ marginInlineEnd: 4 }}></i>للفتح</span>
          <span style={{ marginInlineStart: 'auto' }}>منصة الفريد للحقائب</span>
        </div>
      </div>
    </div>
  );
}

// ==========================================
// 9. NOTIFICATIONS POPOVER
// ==========================================
export function NotificationsPopover({ open, onClose, go }) {
  const [list, setList] = useState(MOCK_NOTIFICATIONS);

  if (!open) return null;

  const markAllRead = () => {
    setList(prev => prev.map(n => ({ ...n, unread: false })));
  };

  return (
    <div className="notify-pop" onClick={e => e.stopPropagation()}>
      <div className="notify-h">
        <b>الإشعارات والتنبيهات الحية</b>
        <button className="muted" style={{ fontSize: 11, cursor: 'pointer' }} onClick={markAllRead}>تعيين الكل كمقروء</button>
      </div>
      <div className="notify-b">
        {list.map(n => (
          <div
            key={n.id}
            className={'notify-item ' + (n.unread ? 'unread' : '')}
            onClick={() => {
              if (n.tone === 'warn') go('/customers');
              else if (n.tone === 'danger') go('/inventory');
              else if (n.tone === 'info') go('/purchases');
              else go('/dashboard');
              onClose();
            }}
          >
            <div className="ni" style={{ background: n.tone === 'danger' ? 'var(--danger-soft)' : n.tone === 'warn' ? 'var(--warn-soft)' : 'var(--accent-soft)', color: n.tone === 'danger' ? 'var(--danger)' : n.tone === 'warn' ? 'var(--warn)' : 'var(--accent)' }}>
              <i className={n.icon}></i>
            </div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <b style={{ display: 'block', fontSize: 12.5, fontWeight: 600 }}>{n.title}</b>
              <p style={{ margin: '2px 0 0', color: 'var(--ink-3)', fontSize: 11.5, lineHeight: 1.4 }}>{n.text}</p>
              <small className="muted" style={{ fontSize: 10.5, marginTop: 4, display: 'block' }}>{n.time}</small>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

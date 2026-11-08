import { AnimatedNumber } from '@alfarid/ui';
import { AdminLoading } from '../../../components/admin/AdminLoading';
import { isAxiosCancel, reportsApi, type DashboardSummary, type SalesReportRow } from '@alfarid/api-client';
import { ArrowDown, ArrowUp, Boxes, ClipboardList, RefreshCw, Search, ShoppingBag, Store, UserRoundCheck, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import styles from './AdminOverview.css?inline';

const count = new Intl.NumberFormat('ar-EG', { maximumFractionDigits: 2 });
const money = new Intl.NumberFormat('ar-EG', {
  style: 'currency', currency: 'EGP', minimumFractionDigits: 0, maximumFractionDigits: 2,
});
const channels = [
  { key: 'store', label: 'المتجر الإلكتروني' },
  { key: 'wholesale', label: 'الجملة' },
  { key: 'pos', label: 'نقاط البيع' },
] as const;
const periods = [
  { key: 'today', label: 'اليوم' },
  { key: 'month', label: 'هذا الشهر' },
] as const;
const chartDay = new Intl.DateTimeFormat('ar-EG', { day: 'numeric', month: 'short', timeZone: 'UTC' });
const cairoDay = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit' });

function recentDates() {
  const parts = cairoDay.formatToParts(new Date());
  const day = ['year', 'month', 'day'].map(type => parts.find(part => part.type === type)?.value).join('-');
  const lastDay = Date.parse(day + 'T00:00:00Z');
  return Array.from({ length: 8 }, (_, index) => new Date(lastDay - (7 - index) * 86_400_000).toISOString().slice(0, 10));
}

type OverviewState =
  | { status: 'loading' | 'error' }
  | { status: 'ready'; data: DashboardSummary };

export default function AdminOverview({ can, name }: { can: (permission: string) => boolean; name?: string }) {
  const allowed = can('reports.view');
  const [period, setPeriod] = useState<(typeof periods)[number]['key']>('today');
  const [ranking, setRanking] = useState<'products' | 'branches'>('products');
  const [query, setQuery] = useState('');
  const [revision, setRevision] = useState(0);
  const [state, setState] = useState<OverviewState>({ status: 'loading' });
  const [dates] = useState(recentDates);
  const [dailySales, setDailySales] = useState<SalesReportRow[] | 'error' | null>(null);

  useEffect(() => {
    if (!allowed) return;
    const controller = new AbortController();
    reportsApi.dashboard(controller.signal)
      .then((data) => {
        if (!controller.signal.aborted) setState({ status: 'ready', data });
      })
      .catch((error: unknown) => {
        if (!controller.signal.aborted && !isAxiosCancel(error)) setState({ status: 'error' });
      });
    reportsApi.sales({ from: dates[0], to: dates[dates.length - 1], granularity: 'daily' }, controller.signal)
      .then(result => { if (!controller.signal.aborted) setDailySales(result.rows ?? []); })
      .catch(() => { if (!controller.signal.aborted) setDailySales('error'); });
    return () => controller.abort();
  }, [allowed, dates, revision]);

  const reload = () => {
    setState({ status: 'loading' });
    setDailySales(null);
    setRevision((previous) => previous + 1);
  };
  const data = allowed && state.status === 'ready' ? state.data : null;
  const sales = data?.[period];
  const salesByDay = new Map(Array.isArray(dailySales) ? dailySales.map(row => [row.period, row]) : []);
  const chartDays = dates.map(date => {
    const row = salesByDay.get(date);
    return { date, net: Number(row?.net_sales ?? 0), discounts: Number(row?.discounts ?? 0) };
  });
  const chartMax = Math.max(0, ...chartDays.map(day => Math.max(0, day.net) + Math.max(0, day.discounts)));
  const monthTotal = Number(data?.month.sales_total ?? 0);
  const todayTotal = Number(data?.today.sales_total ?? 0);
  const dailyShare = monthTotal > 0 ? Math.min(100, Math.max(0, todayTotal / monthTotal * 100)) : 0;
  const metrics = data ? [
    { title: 'مبيعات الشهر', value: monthTotal, format: money.format, icon: Wallet, href: '#/reports', permission: 'reports.view', period: 'هذا الشهر' },
    { title: 'طلبات جديدة', value: data.today.new_orders_count, format: count.format, icon: ClipboardList, href: '#/orders', permission: 'orders.view', period: 'اليوم' },
    { title: 'مخزون منخفض', value: data.low_stock_count, format: count.format, icon: Boxes, href: '#/inventory/stock', permission: 'inventory.view', period: 'حاليًا' },
    { title: 'طلبات الجملة', value: data.pending_wholesale_applications, format: count.format, icon: UserRoundCheck, href: '#/dealers', permission: 'customers.view', period: 'قيد المراجعة' },
  ] : [];
  const rows = ranking === 'products'
    ? (data?.top_products ?? []).map(row => ({
      id: row.product_variant_id, name: row.product_name || row.sku || 'صنف بدون اسم', detail: row.sku,
      quantity: row.qty_sold, revenue: row.revenue,
    }))
    : (data?.top_branches ?? []).map(row => ({
      id: row.branch_id, name: row.branch_name || 'فرع بدون اسم', detail: null,
      quantity: row.orders_count, revenue: row.revenue,
    }));
  const search = query.trim().toLocaleLowerCase();
  const visibleRows = rows.filter(row => [row.name, row.detail].some(value => value?.toLocaleLowerCase().includes(search)));
  const channelIcons = [Store, ShoppingBag, Wallet];

  return (
    <div className="admin-overview space-y-5" dir="rtl" lang="ar">
      <style>{styles}</style>
      <header className="admin-overview-header pt-1">
        <div className="admin-overview-intro">
          <h1 className="font-display text-[26px] sm:text-[34px] md:text-[40px] font-medium tracking-[-0.025em] leading-tight">مرحبًا بك{name && <>، <bdi>{name}</bdi></>}</h1>
          <p className="text-[13px] sm:text-[13.5px] mt-1">تابع المبيعات والطلبات والمخزون في مكان واحد.</p>
        </div>
        {data && <button type="button" className="admin-overview-button v-subcard" onClick={reload}><RefreshCw size={15} aria-hidden="true" /> تحديث البيانات</button>}
      </header>

      {!allowed ? (
        <div className="v-card p-5"><h2>مؤشرات النشاط</h2><p>يتطلب عرض المؤشرات صلاحية التقارير.</p></div>
      ) : state.status === 'loading' ? (
        <AdminLoading label="جارٍ تحميل مؤشرات النشاط" />
      ) : state.status === 'error' ? (
        <div className="v-card p-5 space-y-3" role="alert">
          <h2>تعذّر تحميل مؤشرات النشاط</h2>
          <p>حاول تحميل بيانات لوحة التحكم مرة أخرى.</p>
          <button type="button" className="admin-overview-button v-subcard" onClick={reload}>إعادة المحاولة</button>
        </div>
      ) : data && sales && (
        <>
          <div className="admin-vortix-grid grid grid-cols-1 lg:grid-cols-12 gap-5">
            <section dir="rtl" className="lg:col-span-4 v-card p-5 flex flex-col justify-between" aria-labelledby="admin-sales-heading">
              <div>
                <div className="admin-overview-header">
                  <h2 id="admin-sales-heading" className="text-[14px] font-medium">إجمالي المبيعات</h2>
                  <span className="v-subcard px-3 py-1.5 rounded-full text-[12px] font-semibold">ج.م.</span>
                </div>
                <div className="admin-sales-total mt-2 font-display text-[32px] font-bold tracking-tight">
                  <bdi><AnimatedNumber value={Number(sales.sales_total)} format={money.format} /></bdi>
                </div>
                <div className="flex items-center gap-2 mt-1.5 text-[11.5px]"><span className="v-badge-green px-2 py-0.5 rounded-full">{period === 'today' ? 'اليوم' : 'هذا الشهر'}</span><span>إجمالي القنوات</span></div>
                <div className="admin-overview-period grid grid-cols-2 gap-3 mt-4" role="group" aria-label="فترة المبيعات">
                  {periods.map(({ key, label }) => {
                    const Icon = key === 'today' ? ArrowUp : ArrowDown;
                    return <button className="v-action-cta v-subcard rounded-full" key={key} type="button" aria-pressed={period === key} onClick={() => setPeriod(key)}><Icon size={14} aria-hidden="true" />{label}</button>;
                  })}
                </div>
              </div>
              <div className="v-subcard p-3.5 mt-5">
                <h3 className="text-[12px] font-semibold mb-3">قنوات البيع <span className="admin-overview-muted">· ٣ قنوات</span></h3>
                <ul className="v-wallets-grid grid grid-cols-3 gap-2">
                  {channels.map(({ key, label }, index) => {
                    const Icon = channelIcons[index];
                    return <li className="wallet-card v-card p-2.5" key={key}>
                      <div className="flex items-center gap-1.5 text-[11px] font-semibold"><Icon size={13} aria-hidden="true" />{label}</div>
                      <div className="text-[12px] font-bold"><bdi><AnimatedNumber value={Number(sales.sales_by_channel[key] ?? 0)} format={money.format} /></bdi></div>
                    </li>;
                  })}
                </ul>
              </div>
            </section>

            <section aria-label="متابعة التشغيل" className="admin-vortix-metrics lg:col-span-4 v-card p-4 grid grid-cols-2 gap-3.5">
              {metrics.map(({ title, value, format, icon: Icon, href, permission, period: metricPeriod }, index) => (
                <article dir="rtl" key={title} className={`${index === 0 ? 'v-orange-earnings' : 'v-subcard'} rounded-[18px] p-4 flex flex-col justify-between`}>
                  <div className="flex items-center justify-between gap-2">
                    <h2 className="text-[12.5px] font-medium">{title}</h2>
                    <span className="v-icon-well w-7 h-7 rounded-full flex items-center justify-center shrink-0"><Icon size={14} aria-hidden="true" /></span>
                  </div>
                  <div className="admin-metric-value font-display text-[32px] font-bold tracking-tight my-3"><bdi><AnimatedNumber value={value} format={format} /></bdi></div>
                  {can(permission) ? <a className="admin-metric-link" href={href} aria-label={title}>{metricPeriod} <span aria-hidden="true">↖</span></a> : <span className="text-[11px]">{metricPeriod}</span>}
                </article>
              ))}
            </section>

            <section dir="rtl" className="lg:col-span-4 v-card p-5 flex flex-col justify-between" aria-labelledby="admin-comparison-heading">
              <div>
                <h2 id="admin-comparison-heading" className="font-display text-[17px] font-semibold tracking-tight">مقارنة المبيعات</h2>
                <p className="text-[11.5px] mt-0.5">المبيعات والخصومات خلال آخر ٨ أيام</p>
              </div>
              <div className="v-subcard p-4 mt-3.5 flex-1 flex flex-col justify-between">
                <div className="admin-overview-header mb-3">
                  <h3 className="text-[12.5px] font-semibold">أداء المبيعات</h3>
                  <ul className="admin-comparison-legend" aria-hidden="true">
                    <li><span className="bar-profit-hatched" />الخصومات</li>
                    <li><span className="admin-net-bar" />المبيعات</li>
                  </ul>
                </div>
                {dailySales === null ? <AdminLoading compact label="جارٍ تحميل الرسم" /> : dailySales === 'error' ? (
                  <p className="text-[12px]" role="status">تعذّر تحميل حركة المبيعات.</p>
                ) : <div className="admin-chart">
                  <div className="admin-chart-axis" aria-hidden="true">{[1, .8, .6, .4, .2, 0].map(tick => <span key={tick}>{count.format(chartMax * tick)}</span>)}</div>
                  <ul className="admin-comparison-columns">
                    {chartDays.map(({ date, net, discounts }) => (
                      <li key={date}>
                        <div className="admin-comparison-bars" role="img"
                          aria-label={`${chartDay.format(new Date(date))} · المبيعات ${money.format(net)} · الخصومات ${money.format(discounts)}`}>
                          {[{ value: net, className: 'admin-net-bar' }, { value: discounts, className: 'bar-profit-hatched' }].map(({ value, className }) => (
                            <span key={className} aria-hidden="true" title={money.format(value)}
                              className={className}
                              style={{ height: chartMax > 0 ? `${Math.max(0, value) / chartMax * 100}%` : 0 }} />
                          ))}
                        </div>
                        <span>{count.format(Number(date.slice(-2)))}</span>
                      </li>
                    ))}
                  </ul>
                </div>}
              </div>
            </section>
          </div>

          <div className="admin-vortix-grid grid grid-cols-1 lg:grid-cols-12 gap-5">
            <section dir="rtl" className="lg:col-span-4 v-card p-5 flex flex-col justify-between">
              <div>
                <h2 className="font-display text-[15px] font-semibold">مبيعات اليوم من إجمالي الشهر</h2>
                <div className="mt-5 h-3.5 w-full rounded-full overflow-hidden flex items-center gap-1 p-0.5" aria-hidden="true">
                  <span className="admin-sales-share h-full rounded-full" style={{ width: `${dailyShare}%` }} />
                  <span className="h-full flex-1 rounded-full spending-hatched-track" />
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2 mt-3 text-[12px]">
                  <span>اليوم <strong><bdi><AnimatedNumber value={todayTotal} format={money.format} /></bdi></strong></span>
                  <span>هذا الشهر <strong><bdi><AnimatedNumber value={monthTotal} format={money.format} /></bdi></strong></span>
                </div>
              </div>
              <div className="v-subcard mt-5 px-3.5 py-2.5 text-[11.5px]">حصة اليوم من مبيعات الشهر <bdi><AnimatedNumber value={dailyShare} format={count.format} />٪</bdi></div>
            </section>

            <section dir="rtl" className="lg:col-span-8 v-card p-5">
              <div className="admin-overview-header mb-4">
                <h2 className="font-display text-[17px] font-semibold">{ranking === 'products' ? 'أفضل المنتجات' : 'أفضل الفروع'}</h2>
                <div className="admin-overview-header">
                  <label className="admin-ranking-search v-subcard rounded-full">
                    <Search size={14} aria-hidden="true" />
                    <input type="search" aria-label="بحث في المبيعات" placeholder="بحث" value={query} onChange={event => setQuery(event.target.value)} />
                  </label>
                  <button type="button" className="admin-overview-button v-subcard" onClick={() => { setRanking(ranking === 'products' ? 'branches' : 'products'); setQuery(''); }}>
                    {ranking === 'products' ? 'عرض الفروع' : 'عرض المنتجات'}
                  </button>
                </div>
              </div>
              {visibleRows.length ? (
                <div className="overflow-x-auto"><table className="admin-ranking-table w-full">
                  <thead><tr className="v-subcard">
                    {ranking === 'products' && <th scope="col">الكود</th>}
                    <th scope="col">{ranking === 'products' ? 'المنتج' : 'الفرع'}</th><th scope="col">{ranking === 'products' ? 'الوحدات' : 'عمليات البيع'}</th><th scope="col">المبيعات</th>
                  </tr></thead>
                  <tbody>{visibleRows.map(row => <tr key={row.id}>
                    {ranking === 'products' && <td><bdi>{row.detail || '—'}</bdi></td>}
                    <th scope="row"><span className="admin-ranking-name">
                      <span className="admin-ranking-icon" aria-hidden="true">{ranking === 'products' ? <ShoppingBag size={13} /> : <Store size={13} />}</span>
                      <bdi>{row.name}</bdi>
                    </span></th>
                    <td>{count.format(row.quantity)}</td><td><bdi>{money.format(Number(row.revenue))}</bdi></td>
                  </tr>)}</tbody>
                </table></div>
              ) : <p className="admin-overview-muted text-[13px]">{search ? 'لا توجد نتائج مطابقة.' : ranking === 'products' ? 'لا توجد مبيعات منتجات مسجّلة هذا الشهر.' : 'لا توجد مبيعات فروع مسجّلة هذا الشهر.'}</p>}
            </section>
          </div>
        </>
      )}
    </div>
  );
}

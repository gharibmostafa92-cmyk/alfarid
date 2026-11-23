import { AnimatedNumber } from '@alfarid/ui';
import { AdminLoading } from '../../../components/admin/AdminLoading';
import { isAxiosCancel, reportsApi, type DashboardSummary, type SalesReportRow } from '@alfarid/api-client';
import { ArrowUpLeft, Boxes, Calendar, ClipboardList, Clock, RefreshCw, Search, ShoppingBag, Store, UserRoundCheck, Wallet, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import styles from './AdminOverview.css?inline';

const count = new Intl.NumberFormat('ar-EG', { maximumFractionDigits: 2 });
const compactCount = new Intl.NumberFormat('ar-EG', { notation: 'compact', maximumFractionDigits: 1 });
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
const headerDate = new Intl.DateTimeFormat('ar-EG', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'Africa/Cairo' });
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
  const [hoveredDay, setHoveredDay] = useState<string | null>(null);
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
  const activeChartDay = hoveredDay ? chartDays.find(day => day.date === hoveredDay) ?? null : null;
  const monthTotal = Number(data?.month.sales_total ?? 0);
  const todayTotal = Number(data?.today.sales_total ?? 0);
  const dailyShare = monthTotal > 0 ? Math.min(100, Math.max(0, todayTotal / monthTotal * 100)) : 0;
  const salesTotalNum = Number(sales?.sales_total ?? 0);
  const topChannel = sales
    ? channels.reduce<{ label: string; amount: number } | null>((best, ch) => {
      const amount = Number(sales.sales_by_channel[ch.key] ?? 0);
      return !best || amount > best.amount ? { label: ch.label, amount } : best;
    }, null)
    : null;
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
  const todayLabel = headerDate.format(new Date());

  return (
    <div className="admin-overview space-y-4 sm:space-y-5 lg:space-y-6" dir="rtl" lang="ar">
      <style>{styles}</style>
      <header className="admin-overview-header admin-page-header pt-1">
        <div className="admin-overview-intro">
          <div className="flex flex-wrap items-center gap-2 sm:gap-3">
            <h1 className="font-display text-[26px] sm:text-[34px] md:text-[38px] font-medium tracking-[-0.025em] leading-[1.12]">مرحبًا بك{name && <>، <bdi>{name}</bdi></>}</h1>
            <span className="admin-date-pill inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1 rounded-full text-[11px] sm:text-[11.5px] font-medium v-subcard">
              <span className="admin-live-dot" aria-hidden="true" />
              {todayLabel}
            </span>
          </div>
          <p className="text-[13px] sm:text-[13.5px] mt-1.5">تابع المبيعات والطلبات والمخزون في مكان واحد.</p>
        </div>
        {data && (
          <button type="button" className="admin-overview-button admin-refresh-btn v-subcard" onClick={reload}>
            <RefreshCw size={15} aria-hidden="true" />
            <span>تحديث البيانات</span>
          </button>
        )}
      </header>

      {!allowed ? (
        <div className="v-card p-6"><h2>مؤشرات النشاط</h2><p className="mt-1 text-[13.5px]">يتطلب عرض المؤشرات صلاحية التقارير.</p></div>
      ) : state.status === 'loading' ? (
        <AdminLoading label="جارٍ تحميل مؤشرات النشاط" />
      ) : state.status === 'error' ? (
        <div className="v-card p-6 space-y-3" role="alert">
          <h2 className="text-[16px] font-semibold">تعذّر تحميل مؤشرات النشاط</h2>
          <p className="text-[13.5px]">حاول تحميل بيانات لوحة التحكم مرة أخرى.</p>
          <button type="button" className="admin-overview-button v-subcard" onClick={reload}>إعادة المحاولة</button>
        </div>
      ) : data && sales && (
        <>
          <div className="admin-vortix-grid grid grid-cols-1 lg:grid-cols-12 gap-5">
            <section dir="rtl" className="lg:col-span-4 v-card admin-panel p-5 lg:p-6 flex flex-col justify-between" aria-labelledby="admin-sales-heading">
              <div>
                <div className="admin-overview-header admin-ranking-toolbar">
                  <h2 id="admin-sales-heading" className="text-[14px] font-medium">إجمالي المبيعات</h2>
                  <span className="v-subcard px-3 py-1 rounded-full text-[11.5px] font-semibold">ج.م.</span>
                </div>
                <div className="admin-sales-total mt-2.5 font-display text-[32px] lg:text-[36px] font-bold tracking-[-0.03em] leading-[1.08]">
                  <bdi><AnimatedNumber value={salesTotalNum} format={money.format} /></bdi>
                </div>
                <div className="flex items-center gap-2 mt-2 text-[11.5px]">
                  <span className="v-badge-green px-2.5 py-0.5 rounded-full font-medium">{period === 'today' ? 'اليوم' : 'هذا الشهر'}</span>
                  <span className="admin-overview-muted">إجمالي القنوات</span>
                </div>
                <div className="admin-overview-period v-subcard p-1 grid grid-cols-2 gap-1.5 mt-4 rounded-full" role="group" aria-label="فترة المبيعات">
                  {periods.map(({ key, label }) => {
                    const Icon = key === 'today' ? Clock : Calendar;
                    return (
                      <button className="v-action-cta rounded-full" key={key} type="button" aria-pressed={period === key} onClick={() => setPeriod(key)}>
                        <Icon size={14} aria-hidden="true" />
                        <span>{label}</span>
                      </button>
                    );
                  })}
                </div>
              </div>
              <div className="v-subcard p-3.5 mt-5">
                <div className="flex items-center justify-between mb-3">
                  <h3 className="text-[12px] font-semibold">قنوات البيع</h3>
                  <span className="admin-overview-muted text-[11px]">٣ قنوات</span>
                </div>
                <ul className="v-wallets-grid grid grid-cols-1 gap-2">
                  {channels.map(({ key, label }, index) => {
                    const Icon = channelIcons[index];
                    const channelValue = Number(sales.sales_by_channel[key] ?? 0);
                    const channelPct = salesTotalNum > 0 ? Math.min(100, Math.max(0, (channelValue / salesTotalNum) * 100)) : 0;
                    return (
                      <li className="wallet-card v-card px-3 py-2.5" key={key}>
                        <div className="flex items-center justify-between gap-2">
                          <div className="flex items-center gap-2 min-w-0 text-[12px] font-medium">
                            <span className="admin-channel-icon" aria-hidden="true"><Icon size={13} /></span>
                            <span className="truncate">{label}</span>
                          </div>
                          <div className="flex items-center gap-2 shrink-0">
                            <span className="admin-channel-pct text-[10.5px] font-medium"><bdi>{count.format(channelPct)}٪</bdi></span>
                            <span className="text-[12.5px] font-bold"><bdi><AnimatedNumber value={channelValue} format={money.format} /></bdi></span>
                          </div>
                        </div>
                        <div className="admin-channel-bar mt-2" aria-hidden="true">
                          <span style={{ width: `${channelPct}%` }} />
                        </div>
                      </li>
                    );
                  })}
                </ul>
              </div>
            </section>

            <section aria-label="متابعة التشغيل" className="admin-vortix-metrics lg:col-span-4 v-card admin-panel p-4 lg:p-5 grid grid-cols-2 gap-3.5">
              {metrics.map(({ title, value, format, icon: Icon, href, permission, period: metricPeriod }, index) => {
                const isHero = index === 0;
                const isInteractive = can(permission);
                return (
                  <article dir="rtl" key={title} className={`${isHero ? 'v-orange-earnings' : 'v-subcard'} ${isInteractive ? 'admin-metric-card-interactive' : ''} rounded-[18px] p-4 flex flex-col justify-between`}>
                    <div className="flex items-center justify-between gap-2">
                      <h2 className="text-[12.5px] font-medium">{title}</h2>
                      <span className="v-icon-well w-7 h-7 rounded-full flex items-center justify-center shrink-0"><Icon size={14} aria-hidden="true" /></span>
                    </div>
                    <div className="admin-metric-value font-display text-[32px] font-bold tracking-[-0.025em] my-3"><bdi><AnimatedNumber value={value} format={format} /></bdi></div>
                    {isInteractive ? (
                      <a className="admin-metric-link" href={href} aria-label={title}>
                        <span>{metricPeriod}</span>
                        <ArrowUpLeft size={13} aria-hidden="true" />
                      </a>
                    ) : (
                      <span className="text-[11px] opacity-75">{metricPeriod}</span>
                    )}
                  </article>
                );
              })}
            </section>

            <section dir="rtl" className="lg:col-span-4 v-card admin-panel p-5 lg:p-6 flex flex-col justify-between" aria-labelledby="admin-comparison-heading">
              <div>
                <div className="admin-overview-header admin-ranking-toolbar">
                  <div>
                    <h2 id="admin-comparison-heading" className="font-display text-[17px] font-semibold tracking-tight">مقارنة المبيعات</h2>
                    <p className="text-[11.5px] mt-0.5">المبيعات والخصومات خلال آخر ٨ أيام</p>
                  </div>
                </div>
              </div>
              <div className="v-subcard p-4 mt-3.5 flex-1 flex flex-col justify-between">
                <div className="admin-overview-header mb-2">
                  <h3 className="text-[12.5px] font-semibold">أداء المبيعات</h3>
                  <ul className="admin-comparison-legend" aria-hidden="true">
                    <li><span className="bar-profit-hatched" />الخصومات</li>
                    <li><span className="admin-net-bar" />المبيعات</li>
                  </ul>
                </div>
                <div className="admin-chart-readout v-card px-3 py-1.5 mb-3 text-[11px] flex items-center justify-between gap-2" aria-live="polite">
                  {activeChartDay ? (
                    <>
                      <span className="font-semibold">{chartDay.format(new Date(activeChartDay.date))}</span>
                      <span className="flex items-center gap-2.5">
                        <span>المبيعات <strong className="font-semibold"><bdi>{money.format(activeChartDay.net)}</bdi></strong></span>
                        <span className="admin-overview-muted">الخصومات <strong><bdi>{money.format(activeChartDay.discounts)}</bdi></strong></span>
                      </span>
                    </>
                  ) : (
                    <span className="admin-overview-muted">اختر أي عمود لعرض تفاصيل اليوم</span>
                  )}
                </div>
                {dailySales === null ? <AdminLoading compact label="جارٍ تحميل الرسم" /> : dailySales === 'error' ? (
                  <p className="text-[12px]" role="status">تعذّر تحميل حركة المبيعات.</p>
                ) : <div className="admin-chart">
                  <div className="admin-chart-axis" aria-hidden="true">{[1, .8, .6, .4, .2, 0].map(tick => <span key={tick}>{compactCount.format(chartMax * tick)}</span>)}</div>
                  <ul className="admin-comparison-columns">
                    {chartDays.map(({ date, net, discounts }) => (
                      <li
                        key={date}
                        className={hoveredDay === date ? 'is-active' : undefined}
                        onMouseEnter={() => setHoveredDay(date)}
                        onMouseLeave={() => setHoveredDay(current => current === date ? null : current)}
                      >
                        <div className="admin-comparison-bars" role="img" tabIndex={0}
                          onClick={() => setHoveredDay(current => current === date ? null : date)}
                          onFocus={() => setHoveredDay(date)}
                          onBlur={() => setHoveredDay(current => current === date ? null : current)}
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
            <section dir="rtl" className="lg:col-span-4 v-card admin-panel p-5 lg:p-6 flex flex-col justify-between">
              <div>
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <h2 className="font-display text-[16px] font-semibold tracking-tight">مبيعات اليوم من إجمالي الشهر</h2>
                    <p className="text-[11.5px] mt-0.5">نسبة المساهمة اليومية في هدف الشهر</p>
                  </div>
                  <span className="admin-share-badge px-2.5 py-1 rounded-full text-[12px] font-bold">
                    <bdi><AnimatedNumber value={dailyShare} format={count.format} />٪</bdi>
                  </span>
                </div>
                <div className="admin-share-track mt-5 h-3.5 w-full rounded-full overflow-hidden flex items-center gap-1 p-0.5" aria-hidden="true">
                  <span className="admin-sales-share h-full rounded-full" style={{ width: `${dailyShare}%` }} />
                  <span className="h-full flex-1 rounded-full spending-hatched-track" />
                </div>
                <div className="grid grid-cols-2 gap-2.5 mt-4">
                  <div className="v-subcard px-3.5 py-2.5">
                    <div className="text-[11px] admin-overview-muted">اليوم</div>
                    <div className="text-[13.5px] font-bold mt-0.5"><bdi><AnimatedNumber value={todayTotal} format={money.format} /></bdi></div>
                  </div>
                  <div className="v-subcard px-3.5 py-2.5">
                    <div className="text-[11px] admin-overview-muted">هذا الشهر</div>
                    <div className="text-[13.5px] font-bold mt-0.5"><bdi><AnimatedNumber value={monthTotal} format={money.format} /></bdi></div>
                  </div>
                </div>
              </div>
              <div className="v-subcard mt-4 px-3.5 py-2.5 text-[11.5px] flex items-center justify-between gap-2">
                <span>حصة اليوم من مبيعات الشهر <strong className="font-semibold"><bdi><AnimatedNumber value={dailyShare} format={count.format} />٪</bdi></strong></span>
                {topChannel && topChannel.amount > 0 && (
                  <span className="admin-overview-muted truncate">الأعلى: {topChannel.label}</span>
                )}
              </div>
            </section>

            <section dir="rtl" className="lg:col-span-8 v-card admin-panel p-5 lg:p-6">
              <div className="admin-overview-header mb-4">
                <div className="flex items-center gap-2.5">
                  <h2 className="font-display text-[17px] font-semibold tracking-tight">{ranking === 'products' ? 'أفضل المنتجات' : 'أفضل الفروع'}</h2>
                  {rows.length > 0 && (
                    <span className="v-subcard px-2.5 py-0.5 rounded-full text-[11px] font-semibold admin-overview-muted">
                      <bdi>{count.format(visibleRows.length)}</bdi>
                    </span>
                  )}
                </div>
                <div className="admin-overview-header admin-ranking-toolbar">
                  <label className="admin-ranking-search v-subcard rounded-full">
                    <Search size={14} aria-hidden="true" />
                    <input type="search" aria-label="بحث في المبيعات" placeholder="بحث" value={query} onChange={event => setQuery(event.target.value)} />
                    {query && (
                      <button
                        type="button"
                        className="admin-search-clear"
                        aria-label="مسح البحث"
                        onClick={() => setQuery('')}
                      >
                        <X size={13} aria-hidden="true" />
                      </button>
                    )}
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
                  <tbody>{visibleRows.map((row, index) => <tr key={row.id}>
                    {ranking === 'products' && <td className="admin-ranking-sku" data-label="الكود"><bdi>{row.detail || '—'}</bdi></td>}
                    <th scope="row" className="admin-ranking-title-cell"><span className="admin-ranking-name">
                      <span className="admin-ranking-rank" aria-hidden="true"><bdi>{count.format(index + 1)}</bdi></span>
                      <span className="admin-ranking-icon" aria-hidden="true">{ranking === 'products' ? <ShoppingBag size={13} /> : <Store size={13} />}</span>
                      <bdi className="admin-ranking-label">{row.name}</bdi>
                    </span></th>
                    <td className="admin-ranking-qty" data-label={ranking === 'products' ? 'الوحدات' : 'عمليات البيع'}><bdi>{count.format(row.quantity)}</bdi></td>
                    <td className="admin-ranking-rev font-semibold" data-label="المبيعات"><bdi>{money.format(Number(row.revenue))}</bdi></td>
                  </tr>)}</tbody>
                </table></div>
              ) : <p className="admin-overview-muted text-[13px] py-4">{search ? 'لا توجد نتائج مطابقة.' : ranking === 'products' ? 'لا توجد مبيعات منتجات مسجّلة هذا الشهر.' : 'لا توجد مبيعات فروع مسجّلة هذا الشهر.'}</p>}
            </section>
          </div>
        </>
      )}
    </div>
  );
}

import React, { useState } from 'react';
import { createPortal } from 'react-dom';
import { reportsApi, validatePeriod } from '@alfarid/api-client';
import { RemoteFeedback } from './api/RemoteFeedback';
import { notificationTarget } from './api/useNotifications';
import { num, toDate } from './api/mappers';
import { mockSales, mockInventory, mockProfit, mockSuppliers, mockShifts } from './api/mockData';
import { useRemote, useAction } from './api/useRemote';
import {
  BRANCHES,
  SUPPLIERS,
  SHIFTS,
  VARIANTS,
  NotificationItem,
} from './data';
import {
  Icon,
  Empty,
  Badge,
  Avatar,
  PageHead,
  Card,
  CardHead,
  KPI,
  FSelect,
  FChip,
  FDate,
  TabsBar,
  DataTable,
  Pager,
  Note,
  Num,
  fmtNum,
  fmtMoney,
  fmtInt,
  HatchBar,
  Seg,
} from './ui';

/* ============ 11. Reports · 13. Notifications ============ */

export function BarsChart({
  data,
  h = 210,
  keyA,
  keyB,
  labelA,
  labelB,
  fmt = (v: any) => v,
}: {
  data: any[];
  h?: number;
  keyA: string;
  keyB?: string;
  labelA?: string;
  labelB?: string;
  fmt?: (v: any) => any;
}) {
  const max = Math.max(0, ...data.map((d) => (d[keyA] || 0) + (keyB ? d[keyB] || 0 : 0))) * 1.1 || 1;
  const ticks = [1, 0.8, 0.6, 0.4, 0.2, 0].map((t) => max * t);

  return (
    <div>
      {labelA && (
        <div className="flex items-center gap-4 text-[11px] mb-3 justify-end">
          <span className="flex items-center gap-1.5 tx2 font-medium">
            <span className="w-2.5 h-2.5 rounded-sm hatch-orange inline-block"></span>
            {labelA}
          </span>
          {labelB && (
            <span className="flex items-center gap-1.5 tx2 font-medium">
              <span className="w-2.5 h-2.5 rounded-sm inline-block" style={{ background: 'var(--bar-loss)' }}></span>
              {labelB}
            </span>
          )}
        </div>
      )}
      <div className="flex gap-2.5" style={{ height: h }}>
        <div className="flex flex-col justify-between text-[10px] tx3 pb-5 shrink-0 num text-end w-9">
          {ticks.map((t, i) => (
            <span key={i}>{t >= 1000 ? (t / 1000).toFixed(0) + 'k' : t.toFixed(0)}</span>
          ))}
        </div>
        <div className="relative flex-1 pb-5">
          <div className="absolute inset-x-0 top-0 bottom-5 flex flex-col justify-between pointer-events-none">
            {ticks.map((_, i) => (
              <div
                key={i}
                className="v-bb w-full"
                style={{ borderBottomStyle: i === ticks.length - 1 ? 'solid' : 'dashed' }}
              ></div>
            ))}
          </div>
          <div className="relative z-10 h-full flex items-end justify-between gap-1.5 px-1" dir="ltr">
            {data.map((d, i) => (
              <div key={i} className="group relative flex-1 h-full flex flex-col items-center justify-end">
                <div
                  className="opacity-0 group-hover:opacity-100 pointer-events-none transition absolute -top-2 -translate-y-full z-20 px-2 py-1 rounded-md text-[10px] font-semibold whitespace-nowrap shadow-md num"
                  style={{ background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }}
                >
                  {fmt(d[keyA])}
                  {keyB && ' · ' + fmt(d[keyB])}
                </div>
                <div className="w-full max-w-[28px] flex flex-col justify-end gap-[3px]" style={{ height: 'calc(100% - 0px)' }}>
                  <div
                    className="w-full rounded-[6px] hatch-orange transition-all duration-500"
                    style={{ height: (d[keyA] / max) * 100 + '%' }}
                  ></div>
                  {keyB && (
                    <div
                      className="w-full rounded-[6px] transition-all duration-500"
                      style={{ height: (d[keyB] / max) * 100 + '%', background: 'var(--bar-loss)' }}
                    ></div>
                  )}
                </div>
                <span
                  className="absolute -bottom-0 text-[10px] tx3 font-medium num translate-y-0"
                  style={{ bottom: -2 }}
                >
                  {d.l}
                </span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

const today = () => toDate(new Date().toISOString());
type PeriodProps = { from: string; to: string; setFrom: (v: string) => void; setTo: (v: string) => void };
export function ReportsPage() {
  const [from, setFrom] = useState(() => today().slice(0, 8) + '01');
  const [to, setTo] = useState(today);
  const period = { from, to, setFrom, setTo };
  const [tab, setTab] = useState<'sales' | 'inv' | 'profit' | 'sup' | 'shifts'>('sales');

  return (
    <div className="space-y-5">
      <PageHead title="التقارير" sub="أقصى فترة لأي تقرير 366 يومًا." />
      <TabsBar
        value={tab}
        onChange={(v: any) => setTab(v)}
        tabs={[
          { value: 'sales', label: 'المبيعات', icon: 'chart' },
          { value: 'inv', label: 'المخزون', icon: 'box' },
          { value: 'profit', label: 'الأرباح', icon: 'percent' },
          { value: 'shifts', label: 'الورديات', icon: 'clock' },
        ]}
      />
      {tab === 'sales' && <SalesReport {...period} />}
      {tab === 'inv' && <InventoryReport />}
      {tab === 'profit' && <ProfitReport {...period} />}
      {tab === 'sup' && <SupplierReport />}
      {tab === 'shifts' && <ShiftReport {...period} />}
    </div>
  );
}

export function DateRange({
  from,
  to,
  setFrom,
  setTo,
}: {
  from: string;
  to: string;
  setFrom: (v: string) => void;
  setTo: (v: string) => void;
}) {
  const periodError = validatePeriod(from, to);

  return (
    <>
      <FDate label="من" value={from} onChange={setFrom} />
      <FDate label="إلى" value={to} onChange={setTo} />
      {periodError && (
        <span className="badge t-red">
          <Icon name="alert" size={11} />
          {periodError}
        </span>
      )}
    </>
  );
}

export function SalesReport({ from, to, setFrom, setTo }: PeriodProps) {
  const [grp, setGrp] = useState<'day' | 'month'>('month');
  const [by, setBy] = useState('');
  const periodErr = validatePeriod(from, to);
  const live = useRemote(signal => reportsApi.sales({ from, to, granularity: grp === 'month' ? 'monthly' : 'daily', group_by: (by || undefined) as 'branch' | 'channel' | undefined }, signal), [from, to, grp, by], { enabled: !periodErr, mock: mockSales });
  const data = (live.data?.rows ?? []).map(r => ({ l: [r.period, r.group_label].filter(Boolean).join(' · '), gross: num(r.gross_sales), disc: num(r.discounts), tax: num(r.tax), ship: num(r.shipping), orders: num(r.orders_count), net: num(r.net_sales) }));
  const tot = (k: 'gross' | 'disc' | 'tax' | 'ship' | 'orders' | 'net') => data.reduce((a, d) => a + d[k], 0);

  return (
    <>
      <RemoteFeedback state={live} />
      <Card stg={3} pad="p-4">
        <div className="flex flex-wrap items-center gap-2">
          <DateRange {...{ from, to, setFrom, setTo }} />
          <Seg
            value={grp}
            onChange={(v: any) => setGrp(v)}
            options={[
              { value: 'day', label: 'يومي' },
              { value: 'month', label: 'شهري' },
            ]}
          />
          <FSelect
            value={by}
            onChange={setBy}
            placeholder="بدون تقسيم"
            options={[
              { value: 'branch', label: 'حسب الفرع' },
              { value: 'channel', label: 'حسب القناة' },
            ]}
          />
        </div>
      </Card>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stg stg-3">
        <KPI
          hero
          label="إجمالي المبيعات"
          icon="chart"
          value={<span className="ltr">{(tot('gross') / 1e6).toFixed(2)}M</span>}
          foot="EGP شامل الضرائب"
        />
        <KPI
          label="صافي المبيعات"
          icon="wallet"
          value={<span className="ltr">{(tot('net') / 1e6).toFixed(2)}M</span>}
          foot="بعد الخصومات"
        />
        <KPI label="الطلبات المكتملة" icon="orders" value={fmtInt(tot('orders'))} />
        <KPI
          label="متوسط قيمة الطلب"
          icon="tag"
          value={<span className="ltr">{fmtNum((tot('orders') ? tot('gross') / tot('orders') : 0), 0)}</span>}
          foot="EGP"
        />
      </div>
      <Card stg={4}>
        <CardHead title="حركة المبيعات" sub="مقارنة إجمالي المبيعات بالصافي بعد الخصومات" />
        <BarsChart
          data={data}
          keyA="gross"
          keyB="disc"
          labelA="إجمالي المبيعات"
          labelB="الخصومات"
          fmt={(v) => fmtMoney(v, 0)}
        />
      </Card>
      <Card stg={4}>
        <DataTable
          rows={data}
          cols={[
            { h: 'الفترة', r: (d) => <b className="num tx1">{d.l}</b> },
            { h: 'الطلبات', align: 'center', r: (d) => <Num>{fmtInt(d.orders)}</Num> },
            { h: 'إجمالي المبيعات', r: (d) => fmtMoney(d.gross, 0) },
            {
              h: 'الخصومات',
              r: (d) => <span className="c-red">−{fmtMoney(d.disc, 0)}</span>,
            },
            { h: 'الضرائب', r: (d) => fmtMoney(d.tax, 0) },
            { h: 'الشحن', r: (d) => fmtMoney(d.ship, 0) },
            { h: 'صافي المبيعات', r: (d) => <b>{fmtMoney(d.net, 0)}</b> },
          ]}
        />
      </Card>
    </>
  );
}

export function InventoryReport() {
  const [days, setDays] = useState(90);
  const validDays = Number.isInteger(days) && days >= 1 && days <= 3650;
  const live = useRemote(signal => reportsApi.inventory({ dead_days: days }, signal), [days], { enabled: validDays, mock: mockInventory });
  const br = (live.data?.valuation ?? []).map(r => ({ b: r.branch_name, variants: r.variants_count, qty: num(r.total_qty), value: num(r.total_value) }));
  const totV = br.reduce((a, x) => a + x.value, 0);

  return (
    <>
      <RemoteFeedback state={live} />
      {!validDays && <Note tone="red">عدد الأيام يجب أن يكون من 1 إلى 3650.</Note>}
      <Card stg={3} pad="p-4">
        <div className="flex flex-wrap items-center gap-3">
          <span className="text-[12px] tx2">اعتبار الصنف راكدًا بعد</span>
          <div className="flex items-center gap-2">
            <input
              type="number"
              min="1"
              className="pill-inp w-20 num text-center"
              value={days}
              onChange={(e) => setDays(Number(e.target.value))}
            />
            <span className="text-[12px] tx2">يومًا بدون حركة</span>
          </div>
          {days !== 90 && (
            <button className="btn btn-ghost btn-xs" onClick={() => setDays(90)}>
              الافتراضي 90
            </button>
          )}
        </div>
      </Card>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stg stg-3">
        <KPI
          hero
          label="القيمة الإجمالية"
          icon="box"
          value={<span className="ltr">{totV >= 1e6 ? (totV / 1e6).toFixed(2) + 'M' : fmtInt(totV)}</span>}
          foot="EGP بسعر التكلفة"
        />
        <KPI label="عدد المتغيرات بالفروع" value={fmtInt(br.reduce((a, x) => a + x.variants, 0))} />
        <KPI label="إجمالي الكميات" value={fmtInt(br.reduce((a, x) => a + x.qty, 0))} />
        <KPI
          label="أصناف راكدة"
          value={live.data?.dead_stock.length ?? 0}
          foot={
            <>
              بدون حركة منذ <Num>{days}</Num> يومًا
            </>
          }
        />
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <Card stg={4} className="lg:col-span-6">
          <CardHead title="قيمة المخزون حسب الفرع" />
          <div className="space-y-3.5">
            {br.map((x) => (
              <div key={x.b}>
                <div className="flex items-center justify-between text-[12px] mb-1.5">
                  <span className="font-medium tx1">{x.b}</span>
                  <span>{fmtMoney(x.value, 0)}</span>
                </div>
                <HatchBar pct={(totV ? x.value / totV : 0) * 100} h={12} />
                <div className="flex gap-4 text-[11px] tx3 mt-1">
                  <span>
                    <Num>{x.variants}</Num> متغير
                  </span>
                  <span>
                    <Num>{fmtInt(x.qty)}</Num> وحدة
                  </span>
                </div>
              </div>
            ))}
          </div>
        </Card>
        <Card
          stg={4}
          className="lg:col-span-6"
        >
          <CardHead
            title="الأصناف الراكدة"
            sub={
              <>
                لا حركة منذ <Num>{days}</Num> يومًا أو أكثر
              </>
            }
          />
          <DataTable
            compact
            rows={(live.data?.dead_stock ?? []).map(r => ({ v: { name: r.product_name, sku: r.sku }, qty: num(r.on_hand), br: '—', last: r.last_movement_at ? Math.max(0, Math.floor((Date.now() - Date.parse(r.last_movement_at)) / 864e5)) : '—' }))}
            cols={[
              {
                h: 'الصنف',
                r: (r: any) => (
                  <div className="leading-tight">
                    <div className="font-medium tx1">{r.v.name}</div>
                    <div className="text-[11px] tx3 num">{r.v.sku}</div>
                  </div>
                ),
              },
              {
                h: 'الفرع',
                r: (r: any) => <span className="text-[11.5px] tx2">{r.br}</span>,
              },
              { h: 'الكمية', r: (r: any) => <Num>{r.qty}</Num> },
              {
                h: 'آخر حركة',
                r: (r: any) => (
                  <span className="c-amber font-semibold">
                    <Num>{r.last}</Num> يوم
                  </span>
                ),
              },
            ]}
          />
        </Card>
      </div>
    </>
  );
}

export function ProfitReport({ from, to, setFrom, setTo }: PeriodProps) {
  const periodErr = validatePeriod(from, to);
  const live = useRemote(signal => reportsApi.profit({ from, to }, signal), [from, to], { enabled: !periodErr, mock: mockProfit });
  const rev = num(live.data?.net_sales_revenue), cogs = num(live.data?.cogs), gross = num(live.data?.gross_profit), exp = num(live.data?.total_expenses), net = num(live.data?.net_profit);
  const steps: [string, number, string][] = [
    ['صافي إيراد المبيعات', rev, 'a'],
    ['تكلفة البضاعة', -cogs, 'b'],
    ['مجمل الربح', gross, 'a'],
    ['المصروفات', -exp, 'b'],
    ['صافي الربح', net, 'hero'],
  ];

  return (
    <>
      <RemoteFeedback state={live} />
      <Card stg={3} pad="p-4">
        <div className="flex flex-wrap items-center gap-2">
          <DateRange {...{ from, to, setFrom, setTo }} />
        </div>
      </Card>
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <Card stg={4} className="lg:col-span-7">
          <CardHead title="من الإيراد إلى صافي الربح" />
          <div className="space-y-3">
            {steps.map(([l, v, k]) => (
              <div key={l} className={'rounded-[16px] p-4 ' + (k === 'hero' ? 'v-orange' : 'v-sub')}>
                <div className="flex items-center justify-between mb-2">
                  <span className={'text-[13px] font-medium ' + (k === 'hero' ? 'text-white' : 'tx1')}>{l}</span>
                  <span
                    className={
                      'font-display font-bold text-[16px] num ltr ' +
                      (k === 'hero' ? 'text-white' : v < 0 ? 'c-red' : 'tx1')
                    }
                  >
                    {fmtNum(v, 0)}
                  </span>
                </div>
                <div
                  className="h-2.5 rounded-full overflow-hidden"
                  style={{ background: k === 'hero' ? 'rgba(255,255,255,.2)' : 'transparent' }}
                >
                  <div
                    className={'h-full rounded-full ' + (k === 'hero' ? 'bg-white' : k === 'a' ? 'hatch-orange' : '')}
                    style={{
                      width: (rev ? Math.abs(v) / Math.abs(rev) : 0) * 100 + '%',
                      background: k === 'b' ? 'var(--bar-loss)' : undefined,
                    }}
                  ></div>
                </div>
              </div>
            ))}
          </div>
        </Card>
        <Card stg={4} className="lg:col-span-5">
          <CardHead title="الهوامش" />
          <div className="grid grid-cols-2 gap-3">
            <KPI hero label="هامش مجمل الربح" value={<Num>{(rev ? (gross / rev) * 100 : 0).toFixed(1)}%</Num>} />
            <KPI label="هامش صافي الربح" value={<Num>{(rev ? (net / rev) * 100 : 0).toFixed(1)}%</Num>} />
            <KPI label="تكلفة البضاعة" value={<Num>{(rev ? (cogs / rev) * 100 : 0).toFixed(1)}%</Num>} foot="من الإيراد" />
            <KPI label="المصروفات" value={<Num>{(rev ? (exp / rev) * 100 : 0).toFixed(1)}%</Num>} foot="من الإيراد" />
          </div>
        </Card>
      </div>
    </>
  );
}

export function SupplierReport() {
  const live = useRemote(signal => reportsApi.suppliers(signal), [], { mock: mockSuppliers });
  const rows = live.data ?? [];
  const keys = ['not_due', 'overdue_1_30', 'overdue_31_60', 'overdue_61_90', 'overdue_90_plus'] as const;
  const aging: [string, number][] = ['غير مستحق', '1–30', '31–60', '61–90', '+90'].map((label, index) => [label, rows.reduce((sum, r) => sum + num(r.aging[keys[index]]), 0)]);
  if (live.error?.status === 403) return <Card><Empty title="هذا التقرير متاح للإدارة المركزية فقط" /></Card>;
  const tot = aging.reduce((a, x) => a + x[1], 0);

  return (
    <>
      <RemoteFeedback state={live} />
      <Note icon="lock" className="stg stg-3">
        يعرض البيانات الحالية بدون فلتر فترة، ويتطلب صلاحية وصول مركزي.
      </Note>
      <Card stg={3}>
        <CardHead
          title="أعمار مديونية الموردين"
          sub="إجمالي المستحق موزّعًا على فترات التأخير"
          right={<span className="font-display font-bold text-[18px]">{fmtMoney(tot, 0)}</span>}
        />
        <div className="flex h-10 rounded-full overflow-hidden gap-1 p-1 v-sub">
          {aging.map(([l, v], i) => (
            <div
              key={l}
              className="h-full rounded-full flex items-center justify-center text-[10.5px] font-semibold"
              style={{
                width: (tot ? v / tot : 0) * 100 + '%',
                background: ['#22c55e33', 'var(--accent-light)', '#e0491f99', '#e0491f', 'var(--bar-loss)'][i],
                color: i >= 2 ? '#fff' : 'var(--text-primary)',
              }}
            >
              {(tot ? v / tot : 0) > 0.08 && <Num>{((v / tot) * 100).toFixed(0)}%</Num>}
            </div>
          ))}
        </div>
        <div className="grid grid-cols-5 gap-2 mt-4">
          {aging.map(([l, v], i) => (
            <div key={l} className="v-sub p-3">
              <div className="text-[11px] tx2">
                {i === 0 ? (
                  l
                ) : (
                  <>
                    متأخر <Num>{l}</Num> يوم
                  </>
                )}
              </div>
              <div className="font-semibold text-[13px] mt-1">{fmtMoney(v, 0)}</div>
            </div>
          ))}
        </div>
      </Card>
      <Card stg={4}>
        <DataTable
          rows={rows}
          cols={[
            {
              h: 'المورد',
              r: (s) => (
                <div className="flex items-center gap-2">
                  <Avatar name={s.supplier_name} tone={s.supplier_id} size={26} />
                  <span className="font-medium tx1">{s.supplier_name}</span>
                </div>
              ),
            },
            {
              h: 'الرصيد',
              r: (s) => <b className={num(s.current_balance) < 0 ? 'c-green' : ''}>{fmtMoney(num(s.current_balance), 0)}</b>,
            },
            { h: 'إجمالي المشتريات', r: (s) => fmtMoney(num(s.total_purchases), 0) },
            { h: 'غير مستحق', r: (s) => fmtMoney(num(s.aging.not_due), 0) },
            { h: '1–30', r: (s) => fmtMoney(num(s.aging.overdue_1_30), 0) },
            { h: '31–60', r: (s) => fmtMoney(num(s.aging.overdue_31_60), 0) },
            { h: '61–90', r: (s) => fmtMoney(num(s.aging.overdue_61_90), 0) },
            {
              h: '+90',
              r: (s) => (
                <span className={num(s.aging.overdue_90_plus) > 1000 ? 'c-red font-semibold' : ''}>
                  {fmtMoney(num(s.aging.overdue_90_plus), 0)}
                </span>
              ),
            },
          ]}
        />
      </Card>
    </>
  );
}

export function ShiftReport({ from, to, setFrom, setTo }: PeriodProps) {
  const [diffOnly, setDiffOnly] = useState(false);
  const periodErr = validatePeriod(from, to);
  const live = useRemote(signal => reportsApi.shifts({ from, to, only_differences: diffOnly }, signal), [from, to, diffOnly], { enabled: !periodErr, mock: mockShifts });
  const rows = (live.data?.rows ?? []).map(s => ({ ...s, no: s.shift_number, cashier: s.cashier_name, exp: num(s.expected_closing_cash), act: s.actual_closing_cash === null ? null : num(s.actual_closing_cash) }));
  const returnsExpenses = rows.reduce((sum, s) => sum + num(s.cash_returns_total) + num(s.cash_expenses_total), 0);
  const difference = rows.reduce((sum, s) => sum + num(s.cash_difference), 0);
  return (
    <>
      <RemoteFeedback state={live} />
      <Card stg={3} pad="p-4">
        <div className="flex flex-wrap items-center gap-2">
          <DateRange {...{ from, to, setFrom, setTo }} />
          <FChip on={diffOnly} onClick={() => setDiffOnly(!diffOnly)} icon="alert">
            الورديات ذات الفروق فقط
          </FChip>
        </div>
      </Card>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stg stg-3">
        <KPI hero label="المبيعات النقدية" value={<span className="ltr">{fmtNum(num(live.data?.totals.cash_sales))}</span>} foot="EGP" />
        <KPI label="مبيعات البطاقات" value={<span className="ltr">{fmtNum(num(live.data?.totals.card_sales))}</span>} foot="EGP" />
        <KPI label="المرتجعات والمصروفات" value={<span className="ltr">{fmtNum(returnsExpenses)}</span>} foot="للورديات المعروضة" />
        <KPI label="صافي الفروق" value={<span className="ltr c-red">{fmtNum(difference)}</span>} foot="صافي فرق الورديات المعروضة" />
      </div>
      <Card stg={4}>
        <DataTable
          rows={rows}
          cols={[
            { h: 'الوردية', r: (s) => <Num className="font-semibold">{s.no}</Num> },
            { h: 'الكاشير', r: (s) => s.cashier },
            { h: 'نقدي', r: (s) => fmtMoney(num(s.cash_sales_total), 0) },
            { h: 'بطاقات', r: (s) => fmtMoney(num(s.card_sales_total), 0) },
            { h: 'مرتجعات ومصروفات', r: (s) => fmtMoney(num(s.cash_returns_total) + num(s.cash_expenses_total), 0) },
            { h: 'المتوقعة', r: (s) => (s.exp != null ? fmtMoney(s.exp) : '—') },
            { h: 'الفعلية', r: (s) => (s.act != null ? fmtMoney(s.act) : '—') },
            { h: 'الفرق', r: (s) => <DiffCellR s={s} /> },
          ]}
        />
      </Card>
    </>
  );
}

export function DiffCellR({ s }: { s: any }) {
  if (s.act == null || s.exp == null) return <span>—</span>;
  const d = s.act - s.exp;
  return d === 0 ? (
    <Badge tone="green" dot={false}>
      مطابق
    </Badge>
  ) : (
    <span className={'font-semibold ' + (d < 0 ? 'c-red' : 'c-amber')}>
      {d < 0 ? 'عجز ' : 'زيادة '}
      <Num>{fmtNum(Math.abs(d))}</Num>
    </span>
  );
}

/* ---------------- NOTIFICATIONS ---------------- */

export function notifView(n: NotificationItem) {
  const d = n.data ?? {};
  switch (n.type) {
    case 'order.created':
      return {
        icon: 'orders',
        tone: 'orange',
        text: (
          <>
            طلب جديد <b className="num">{d.order_no}</b> بقيمة {fmtMoney(d.total, 0)} من {d.channel}
          </>
        ),
      };
    case 'return.requested':
      return {
        icon: 'returns',
        tone: 'amber',
        text: (
          <>
            طلب إرجاع <b className="num">{d.return_no}</b> على الطلب <span className="num">{d.order_no}</span>
          </>
        ),
      };
    case 'payment_notice.submitted':
      return {
        icon: 'bank',
        tone: 'blue',
        text: (
          <>
            إشعار سداد <b className="num">{d.notice_no}</b> من {d.company} بمبلغ {fmtMoney(d.amount, 0)}
          </>
        ),
      };
    case 'shift.closed':
      return {
        icon: 'clock',
        tone: d.difference ? 'red' : 'green',
        text: (
          <>
            أُغلقت الوردية <b className="num">{d.shift_no}</b> — {d.cashier}
            {d.difference ? (
              <>
                {' '}
                · عجز <Num>{Math.abs(d.difference)}</Num>
              </>
            ) : (
              ''
            )}
          </>
        ),
      };
    case 'purchase_order.revision_requested':
      return {
        icon: 'cart',
        tone: 'violet',
        text: (
          <>
            {d.supplier} طلب تعديل أمر الشراء <b className="num">{d.po_no}</b>
          </>
        ),
      };
    case 'quotation.requested':
      return {
        icon: 'quote',
        tone: 'orange',
        text: (
          <>
            عرض أسعار <b className="num">{d.quote_no}</b> بانتظار التسعير — {d.company}
          </>
        ),
      };
    case 'stock.low':
      return {
        icon: 'box',
        tone: 'amber',
        text: (
          <>
            مخزون منخفض: <span className="num">{d.sku}</span> في {d.branch} (<Num>{d.qty}</Num> متبقي)
          </>
        ),
      };
    default:
      return {
        icon: 'bell',
        tone: 'gray',
        text: d.message || <span className="tx3 num">{n.type}</span>,
      };
  }
}

export function NotifItem({
  n,
  onRead,
  onOpen,
  compact,
}: {
  n: NotificationItem;
  onRead: (id: string) => void;
  onOpen: (n: NotificationItem) => void;
  compact?: boolean;
}) {
  const v = notifView(n);
  const unread = !n.read_at;

  return (
    <div
      className={
        'flex items-start gap-3 rounded-[16px] transition ' +
        (compact ? 'p-3' : 'p-4') +
        (unread ? ' v-sub' : '')
      }
      style={!unread ? { background: 'transparent' } : {}}
    >
      <span className={'w-9 h-9 rounded-full flex items-center justify-center shrink-0 t-' + v.tone}>
        <Icon name={v.icon} size={15} />
      </span>
      <div className="flex-1 min-w-0">
        <div className={'text-[12.5px] leading-relaxed pretty ' + (unread ? 'tx1 font-medium' : 'tx2')}>{v.text}</div>
        <div className="flex items-center gap-3 mt-1.5 flex-wrap">
          <span className="text-[11px] tx3">{n.created_at}</span>
          {notificationTarget(n) && (
            <button
              className="text-[11.5px] font-semibold accent flex items-center gap-1 hover:underline"
              onClick={() => onOpen(n)}
            >
              فتح السجل
              <Icon name="chevL" size={11} sw={2.4} />
            </button>
          )}
          {unread && (
            <button
              className="text-[11.5px] font-medium tx2 hover:tx1 flex items-center gap-1"
              onClick={() => onRead(n.id)}
            >
              <Icon name="check" size={11} sw={2.4} />
              تعليم كمقروء
            </button>
          )}
        </div>
      </div>
      {unread && <span className="w-2 h-2 rounded-full mt-2 shrink-0" style={{ background: 'var(--accent)' }}></span>}
    </div>
  );
}

export function NotifDrawer({
  open,
  onClose,
  items,
  onRead,
  go,
  container,
}: {
  open: boolean;
  onClose: () => void;
  items: NotificationItem[];
  onRead: (id: string) => void;
  go: (r: { m: string; id?: any; sub?: any }) => void;
  container?: Element | DocumentFragment | null;
}) {
  if (!open) return null;
  const unread = items.filter((n) => !n.read_at).length;

  const content = (
    <>
      <div className="drawer-bd" onClick={onClose}></div>
      <div className="drawer" dir="rtl">
        <div className="flex items-center justify-between p-5 pb-3">
          <div>
            <h3 className="font-display text-[18px] font-semibold tx1">الإشعارات</h3>
            <div className="text-[11.5px] tx3 mt-0.5">
              <Num>{unread}</Num> غير مقروء
            </div>
          </div>
          <button className="icon-btn" onClick={onClose}>
            <Icon name="x" size={14} />
          </button>
        </div>
        <div className="flex-1 overflow-y-auto px-3 space-y-1">
          {items.slice(0, 6).map((n) => (
            <NotifItem
              compact
              key={n.id}
              n={n}
              onRead={onRead}
              onOpen={(x) => {
                onRead(x.id);
                onClose();
                const target = notificationTarget(x);
                if (target) go(target);
              }}
            />
          ))}
        </div>
        <div className="p-4 v-bt">
          <button
            className="btn btn-dark w-full"
            onClick={() => {
              onClose();
              go({ m: 'notifications' });
            }}
          >
            عرض كل الإشعارات
          </button>
        </div>
      </div>
    </>
  );

  return container ? createPortal(content, container) : content;
}

export function NotificationsPage({
  items,
  onRead,
  onReadAll,
  onMore,
  loading,
  go,
}: {
  items: NotificationItem[];
  onRead: (id: string) => void;
  onReadAll: () => void;
  onMore?: () => void;
  loading?: boolean;
  go: (r: { m: string; id?: any; sub?: any }) => void;
}) {
  const unread = items.filter((n) => !n.read_at).length;

  return (
    <div className="space-y-5">
      <PageHead
        title="الإشعارات"
        sub={
          <>
            <Num>{unread}</Num> غير مقروءة في الإشعارات المحمّلة · <Num>20</Num> إشعارًا لكل صفحة
          </>
        }
      />
      <Card stg={2} pad="p-3">
        <button className="btn btn-soft" onClick={onReadAll} disabled={!unread}>تعليم المحمّل كمقروء</button>
        {loading && <Note>جارٍ تحميل الإشعارات…</Note>}
        {!loading && !items.length && <Empty title="لا توجد إشعارات" />}
        <div className="space-y-1">
          {items.map((n) => (
            <NotifItem
              key={n.id}
              n={n}
              onRead={onRead}
              onOpen={(x) => {
                onRead(x.id);
                const target = notificationTarget(x);
                if (target) go(target);
              }}
            />
          ))}
        </div>
        <div className="px-2">
          {onMore && <button className="btn btn-soft" disabled={loading} onClick={onMore}>تحميل المزيد</button>}
        </div>
      </Card>
    </div>
  );
}

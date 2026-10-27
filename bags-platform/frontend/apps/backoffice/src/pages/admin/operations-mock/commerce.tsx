import React, { useEffect, useMemo, useState } from 'react';
import {
  SHIP_COMPANIES,
  SHIP_ZONES,
  SHIP_RATES,
  CUSTOMER_GROUPS,
  PRICE_LISTS,
  PROMOS,
  COUPONS,
  BRANDS,
  BRANCHES,
  CITIES,
  WHOLESALE,
  VARIANTS,
  ShipCompany,
  ShipZone,
  ShipRate,
  PriceListRow,
  CustomerGroup,
  PromoRow,
  CouponRow,
  BrandRow,
} from './data';
import { shippingCompaniesApi, shippingZonesApi, shippingRatesApi, customerGroupsApi, priceListsApi, priceListItemsApi, promotionsApi, couponsApi, brandsApi, commerceLookupsApi, parseApiError, type PriceListImportReport, type Paginated, type PriceListApi, type ShippingCompanyPayload, type ShippingZonePayload, type ShippingRatePayload, type PriceListType } from '@alfarid/api-client';
import { useRemote, useDebounced, useAction } from './api/useRemote';
import { useFormState } from './api/useFormState';
import { mapShipCompany, mapShipZone, mapShipRate, mapCustomerGroup, mapPriceList, mapPromo, mapCoupon, mapErrors, promoToBackend, couponToBackend } from './api/mappers';
import { checkoutEndpoints, branchesApi } from '@alfarid/api-client';
import { RemoteFeedback } from './api/RemoteFeedback';
import {
  Icon,
  useToast,
  Badge,
  ActiveDot,
  PageHead,
  Card,
  SearchPill,
  FSelect,
  TabsBar,
  DataTable,
  Pager,
  RowActions,
  Modal,
  ConfirmModal,
  Field,
  TextArea,
  ToggleRow,
  Note,
  Dropzone,
  Num,
  fmtNum,
  fmtMoney,
  fmtInt,
  HatchBar,
  Seg,
} from './ui';

export const PL_TYPES: Record<string, string> = {
  retail: 'تجزئة عامة',
  wholesale: 'جملة',
  distributor: 'موزعون',
  group: 'مجموعة عملاء',
  customer: 'عميل مخصص',
};

export const PROMO_TYPES: Record<string, string> = {
  percent: 'خصم نسبة',
  amount: 'خصم مبلغ',
  bxgy: 'اشترِ X واحصل على Y',
  free_ship: 'شحن مجاني',
};

export const SCOPES: Record<string, string> = {
  all: 'الكل',
  categories: 'أقسام',
  variants: 'متغيرات منتجات',
};

export const COUPON_TYPES: Record<string, string> = {
  percent: 'خصم نسبة',
  amount: 'خصم مبلغ',
  free_ship: 'شحن مجاني',
};

/* ---------------- SHIPPING ---------------- */
export function ShippingPage() {
  const [tab, setTab] = useState<'companies' | 'zones' | 'rates'>('companies');
  const [edit, setEditState] = useState<{ kind: 'companies' | 'zones' | 'rates'; row: any } | null>(null);
  const toast = useToast();
  const companies = useRemote((s) => shippingCompaniesApi.all(s).then((rows) => rows.map(mapShipCompany)), [], { mock: () => SHIP_COMPANIES });
  const zones = useRemote((s) => shippingZonesApi.all(s).then((rows) => rows.map(mapShipZone)), [], { mock: () => SHIP_ZONES as any });
  const rates = useRemote((s) => shippingRatesApi.all(s).then((rows) => rows.map(mapShipRate)), [], { mock: () => SHIP_RATES as any });
  const C = companies.data ?? [], Z = zones.data ?? [], RT = rates.data ?? [];
  const setEdit = (next: typeof edit) => {
    if (next && (companies.usingMock || zones.usingMock || rates.usingMock)) return toast('البيانات تجريبية — لا يمكن الحفظ', 'red');
    setEditState(next);
  };

  return (
    <div className="space-y-5">
      <PageHead
        title="الشحن"
        sub="شركات الشحن، ومناطق التغطية، وأسعار كل شركة لكل منطقة."
        actions={
          <button className="btn btn-dark" onClick={() => setEdit({ kind: tab, row: null })}>
            <Icon name="plus" size={14} />
            {{ companies: 'شركة شحن', zones: 'منطقة شحن', rates: 'سعر شحن' }[tab]}
          </button>
        }
      />
      <RemoteFeedback state={tab === 'companies' ? companies : tab === 'zones' ? zones : rates} />
      <TabsBar
        value={tab}
        onChange={(v: any) => setTab(v)}
        tabs={[
          { value: 'companies', label: 'شركات الشحن', icon: 'truck', count: C.length },
          { value: 'zones', label: 'مناطق الشحن', icon: 'globe', count: Z.length },
          { value: 'rates', label: 'أسعار الشحن', icon: 'tag', count: RT.length },
        ]}
      />
      {tab === 'companies' && (
        <Card stg={3}>
          <DataTable
            rows={C}
            onRow={(r: ShipCompany) => setEdit({ kind: 'companies', row: r })}
            cols={[
              {
                h: 'الشركة',
                r: (c: ShipCompany) => (
                  <div className="flex items-center gap-2.5">
                    <span className="w-9 h-9 rounded-[11px] v-sub flex items-center justify-center tx2">
                      <Icon name="truck" size={15} />
                    </span>
                    <span className="font-semibold tx1">{c.name}</span>
                  </div>
                ),
              },
              {
                h: 'الكود',
                r: (c: ShipCompany) => (
                  <span className="num text-[11.5px] font-semibold v-sub px-2 py-1 rounded-md tx2">{c.code}</span>
                ),
              },
              {
                h: 'قالب رابط التتبع',
                r: (c: ShipCompany) =>
                  c.url ? (
                    <span className="num text-[11.5px] tx2 ltr clamp1 max-w-[340px]" dir="ltr">
                      {c.url.split('{awb}')[0]}
                      <b className="accent">{'{awb}'}</b>
                      {c.url.split('{awb}')[1]}
                    </span>
                  ) : (
                    <span className="tx3 text-[11.5px]">بدون تتبع</span>
                  ),
              },
              {
                h: 'تكامل API',
                r: (c: ShipCompany) => (c.api ? <Badge tone="blue">متصل</Badge> : <span className="tx3 text-[11.5px]">—</span>),
              },
              { h: 'التفعيل', r: (c: ShipCompany) => <ActiveDot on={c.active} /> },
              {
                h: '',
                r: (c: ShipCompany) => (
                  <RowActions>
                    <button className="icon-btn !w-7 !h-7" onClick={() => setEdit({ kind: 'companies', row: c })}>
                      <Icon name="edit" size={13} />
                    </button>
                  </RowActions>
                ),
              },
            ]}
          />
        </Card>
      )}
      {tab === 'zones' && (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {(Z as any[]).map((z: ShipZone) => (
            <div
              key={z.id}
              onClick={() => setEdit({ kind: 'zones', row: z })}
              className="v-card p-5 cursor-pointer hover:border-[var(--border-strong)] transition space-y-3"
            >
              <div className="flex items-start justify-between">
                <div>
                  <div className="font-semibold tx1 text-[14px]">{z.name}</div>
                  <div className="text-[11.5px] tx3 mt-0.5">
                    <Num>{z.cities.length}</Num> مدن مشمولة
                  </div>
                </div>
                <ActiveDot on={z.active} />
              </div>
              <div className="flex flex-wrap gap-1.5">
                {z.cities.map((c) => (
                  <span key={c} className="v-sub px-2 py-0.5 rounded-md text-[11.5px] tx2">
                    {c}
                  </span>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
      {tab === 'rates' && (
        <Card stg={3}>
          <DataTable
            rows={RT}
            onRow={(r: ShipRate) => setEdit({ kind: 'rates', row: r })}
            cols={[
              {
                h: 'الشركة',
                r: (r: ShipRate) => (
                  <span className="font-semibold tx1">
                    {(C as ShipCompany[]).find((c) => c.code === (r as any).co || c.id === (r as any).companyId)?.name || (r as any).co}
                  </span>
                ),
              },
              {
                h: 'المنطقة',
                r: (r: ShipRate) => (
                  <span className="tx2">{(Z as ShipZone[]).find((z) => z.id === r.zone)?.name}</span>
                ),
              },
              { h: 'السعر الأساسي', r: (r: ShipRate) => <span className="font-semibold">{fmtMoney(r.base)}</span> },
              {
                h: 'حد الشحن المجاني',
                r: (r: ShipRate) =>
                  r.free === 0 ? (
                    <Badge tone="green">مجاني دائمًا</Badge>
                  ) : r.free ? (
                    <span className="tx2 font-medium">{fmtMoney(r.free)}</span>
                  ) : (
                    <span className="tx3 text-[11.5px]">لا يوجد</span>
                  ),
              },
              {
                h: 'رسوم COD',
                r: (r: ShipRate) => (r.cod ? fmtMoney(r.cod) : <span className="tx3 text-[11.5px]">بدون رسوم</span>),
              },
              {
                h: 'مدة التوصيل',
                r: (r: ShipRate) => (
                  <span className="num tx2">
                    {r.min}–{r.max} يوم
                  </span>
                ),
              },
              {
                h: '',
                r: (r: ShipRate) => (
                  <RowActions>
                    <button className="icon-btn !w-7 !h-7" onClick={() => setEdit({ kind: 'rates', row: r })}>
                      <Icon name="edit" size={13} />
                    </button>
                  </RowActions>
                ),
              },
            ]}
          />
        </Card>
      )}
      {edit?.kind === 'companies' && <CompanyModal row={edit.row} onClose={() => setEdit(null)} onSaved={companies.reload} />}
      {edit?.kind === 'zones' && <ZoneModal row={edit.row} onClose={() => setEdit(null)} onSaved={zones.reload} />}
      {edit?.kind === 'rates' && <RateModal row={edit.row} companies={C} zones={Z} onClose={() => setEdit(null)} onSaved={rates.reload} />}
    </div>
  );
}

export function CompanyModal({ row, onClose, onSaved }: { row: ShipCompany | null; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({ name: row?.name ?? '', code: row?.code ?? '', url: row?.url ?? '', active: row?.active ?? true, api: row?.api ?? false });
  const urlErr = f.v.url && (!f.v.url.startsWith('https://') || !f.v.url.includes('{awb}')) ? 'يجب أن يبدأ القالب بـ https:// ويحتوي على متغير {awb}' : null;
  const save = async () => {
    const body: ShippingCompanyPayload = { name: f.v.name, code: f.v.code, tracking_url_template: f.v.url || null, is_active: f.v.active };
    const r = await run(() => (row ? shippingCompaniesApi.update(row.id, body) : shippingCompaniesApi.create(body)),
      { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { name: 'name', code: 'code', tracking_url_template: 'url' })) });
    if (r) { onSaved(); onClose(); }
  };
  return (
    <Modal
      open
      onClose={onClose}
      title={row ? 'تعديل شركة شحن' : 'شركة شحن جديدة'}
      icon="truck"
      footer={
        <>
          <button className="btn btn-soft" onClick={onClose}>
            إلغاء
          </button>
          <button
            className="btn btn-dark"
            disabled={!!urlErr || busy}
            onClick={save}
          >
            حفظ
          </button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="اسم الشركة" req err={f.errors.name}>
            <input className="inp" value={f.v.name} onChange={f.on('name')} />
          </Field>
          <Field label="الكود" req hint="فريد بالإنجليزية" err={f.errors.code}>
            <input className="inp num" dir="ltr" value={f.v.code} onChange={f.on('code')} />
          </Field>
        </div>
        <Field label="قالب رابط التتبع" err={urlErr ?? f.errors.url} hint="استخدم {awb} مكان رقم البوليصة">
          <input
            className={'inp num ' + (urlErr ? 'err' : '')}
            dir="ltr"
            value={f.v.url}
            onChange={f.on('url')}
            placeholder="https://tracker.com/track/{awb}"
          />
        </Field>
        <ToggleRow label="مفعّلة" on={f.v.active} onChange={(v) => f.set('active', v)} />
        <ToggleRow label="بيانات تكامل API موجودة" sub="مؤشر للقراءة؛ تُدار بيانات الاعتماد من إعدادات التكامل" on={f.v.api} onChange={() => toast('تُدار بيانات الاعتماد من إعدادات التكامل', 'accent')} />
      </div>
    </Modal>
  );
}

export function ZoneModal({ row, onClose, onSaved }: { row: (ShipZone & { cityIds?: number[] }) | null; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({ name: row?.name ?? '', active: row?.active ?? true });
  const cities = useRemote(() => checkoutEndpoints.cities(), []);
  const [cityIds, setCityIds] = useState<number[]>((row as any)?.cityIds ?? []);
  const tg = (id: number) => setCityIds(cityIds.includes(id) ? cityIds.filter((x) => x !== id) : [...cityIds, id]);
  const save = async () => {
    if (!f.v.name.trim()) return toast('اسم المنطقة مطلوب', 'red');
    const body: ShippingZonePayload = { name: f.v.name, is_active: f.v.active, city_ids: cityIds };
    const r = await run(() => (row ? shippingZonesApi.update(row.id, body) : shippingZonesApi.create(body)),
      { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { name: 'name', city_ids: 'cities' })) });
    if (r) { onSaved(); onClose(); }
  };
  return (
    <Modal
      open
      onClose={onClose}
      title={row ? 'تعديل منطقة شحن' : 'منطقة شحن جديدة'}
      icon="globe"
      width={600}
      footer={
        <>
          <button className="btn btn-soft" onClick={onClose}>
            إلغاء
          </button>
          <button
            className="btn btn-dark"
            disabled={busy}
            onClick={save}
          >
            حفظ
          </button>
        </>
      }
    >
      <div className="space-y-4">
        <RemoteFeedback state={cities} />
        <Field label="اسم المنطقة" req err={f.errors.name}>
          <input className="inp" value={f.v.name} onChange={f.on('name')} />
        </Field>
        <div>
          <div className="flex items-center justify-between mb-2">
            <span className="lbl">المدن المشمولة</span>
            <span className="text-[11px] tx3">
              <Num>{cityIds.length}</Num> مختارة
            </span>
          </div>
          <div className="flex flex-wrap gap-1.5 p-3 rounded-[14px] v-sub max-h-[160px] overflow-y-auto">
            {(cities.data ?? []).map((c: { id: number; name_ar: string }) => (
              <button
                key={c.id}
                type="button"
                onClick={() => tg(c.id)}
                className={'pill-inp text-[12px] ' + (cityIds.includes(c.id) ? 'on' : '')}
              >
                {c.name_ar}
              </button>
            ))}
          </div>
        </div>
        <ToggleRow label="مفعّلة" on={f.v.active} onChange={(v) => f.set('active', v)} />
      </div>
    </Modal>
  );
}

export function RateModal({ row, companies, zones, onClose, onSaved }: { row: (ShipRate & { companyId?: number }) | null; companies: ShipCompany[]; zones: ShipZone[]; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({
    companyId: String((row as any)?.companyId ?? ''),
    zone: String(row?.zone ?? ''),
    base: row?.base ?? '',
    free: (row?.free ?? '') as any,
    cod: (row?.cod ?? 0) as any,
    min: row?.min ?? 1,
    max: row?.max ?? 3,
  });
  const min = Number(f.v.min), max = Number(f.v.max);
  const err = min > max ? 'الحد الأدنى لا يمكن أن يتجاوز الحد الأقصى' : null;
  const save = async () => {
    if (!f.v.companyId || !f.v.zone) return toast('اختر الشركة والمنطقة', 'red');
    if (err) return;
    const body: ShippingRatePayload = {
      shipping_company_id: Number(f.v.companyId), shipping_zone_id: Number(f.v.zone),
      base_cost: Number(f.v.base), free_shipping_threshold: f.v.free === '' ? null : Number(f.v.free),
      cod_fee: f.v.cod === '' ? null : Number(f.v.cod), estimated_days_min: Number(f.v.min), estimated_days_max: Number(f.v.max),
    };
    const r = await run(() => (row ? shippingRatesApi.update(row.id, body) : shippingRatesApi.create(body)),
      { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { shipping_company_id: 'companyId', shipping_zone_id: 'zone', base_cost: 'base', free_shipping_threshold: 'free', cod_fee: 'cod', estimated_days_min: 'min', estimated_days_max: 'max' })) });
    if (r) { onSaved(); onClose(); }
  };
  return (
    <Modal
      open
      onClose={onClose}
      title={row ? 'تعديل سعر شحن' : 'سعر شحن جديد'}
      icon="tag"
      footer={
        <>
          <button className="btn btn-soft" onClick={onClose}>
            إلغاء
          </button>
          <button
            className="btn btn-dark"
            disabled={!!err || busy}
            onClick={save}
          >
            حفظ
          </button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="شركة الشحن" req err={f.errors.companyId}>
            <select className="inp" value={f.v.companyId} onChange={f.on('companyId' as any)}>
              <option value="">اختر الشركة</option>
              {companies.map((c) => (
                <option key={c.id} value={String(c.id)}>
                  {c.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="المنطقة" req err={f.errors.zone}>
            <select className="inp" value={f.v.zone} onChange={f.on('zone' as any)}>
              <option value="">اختر المنطقة</option>
              {zones.map((z) => (
                <option key={z.id} value={String(z.id)}>
                  {z.name}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <div className="grid grid-cols-3 gap-3">
          <Field label="السعر الأساسي" req err={f.errors.base}>
            <input type="number" step="0.01" className="inp num" value={f.v.base} onChange={f.on('base' as any)} />
          </Field>
          <Field label="حد الشحن المجاني" hint="0 = مجاني دائمًا، فارغ = لا يوجد" err={f.errors.free}>
            <input type="number" step="0.01" className="inp num" value={f.v.free} onChange={f.on('free' as any)} placeholder="لا يوجد" />
          </Field>
          <Field label="رسوم الدفع عند الاستلام" err={f.errors.cod}>
            <input type="number" step="0.01" className="inp num" value={f.v.cod} onChange={f.on('cod' as any)} />
          </Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="أقل مدة تسليم (أيام)" err={f.errors.min}>
            <input
              type="number"
              min="0"
              className="inp num"
              value={f.v.min}
              onChange={f.on('min' as any)}
            />
          </Field>
          <Field label="أقصى مدة تسليم (أيام)" err={err ?? f.errors.max}>
            <input
              type="number"
              min="0"
              className={'inp num ' + (err ? 'err' : '')}
              value={f.v.max}
              onChange={f.on('max' as any)}
            />
          </Field>
        </div>
      </div>
    </Modal>
  );
}

/* ---------------- PRICING ---------------- */
export function PricingPage({ go }: { go: (r: { m: string; id?: any; sub?: any }) => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [tab, setTab] = useState<'lists' | 'groups'>('lists');
  const [q, setQ] = useState('');
  const [ty, setTy] = useState('');
  const [cus, setCus] = useState('');
  const [grp, setGrp] = useState('');
  const [br, setBr] = useState('');
  const [act, setAct] = useState('');
  const [form, setForm] = useState<PriceListRow | { id?: any } | null>(null);
  const [gform, setGform] = useState<CustomerGroup | { id?: any; name?: string; code?: string; desc?: string; disc?: number; active?: boolean } | null>(null);
  const [page, setPage] = useState(1);
  const dq = useDebounced(q);
  useEffect(() => { setPage(1); }, [dq, ty, cus, grp, br, act]);

  const typeToBackend = (t: string): PriceListType | undefined => {
    if (!t) return undefined;
    if (t === 'group') return 'customer_group';
    if (t === 'customer') return 'customer_specific';
    return t as PriceListType;
  };
  const lists = useRemote<Paginated<PriceListApi>>((s) => priceListsApi.list({
    page, per_page: 20, search: dq || undefined, type: typeToBackend(ty),
    customer_id: cus ? Number(cus) : undefined, customer_group_id: grp ? Number(grp) : undefined,
    branch_id: br ? Number(br) : undefined, is_active: act === '' ? undefined : act === 'true',
  }, s), [page, dq, ty, cus, grp, br, act], { mock: () => ({ data: [], meta: { current_page: 1, last_page: 1, total: PRICE_LISTS.length, per_page: 20, from: 1, to: PRICE_LISTS.length } }), onError: (e) => toast(e.message, 'red') });
  const rows = lists.usingMock ? PRICE_LISTS : (lists.data?.data ?? []).map((p: any) => mapPriceList(p));

  const groups = useRemote((s) => customerGroupsApi.all(s).then((rows) => rows.map(mapCustomerGroup)), [], { mock: () => CUSTOMER_GROUPS });
  const branchesList = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
  const customers = useRemote((s) => commerceLookupsApi.customers(s), []);

  return (
    <div className="space-y-5">
      <PageHead
        title="التسعير ومجموعات العملاء"
        sub="قوائم أسعار التجزئة والجملة، وخصومات مجموعات العملاء، واستيراد الأسعار."
        actions={
          <>
            <button className="btn btn-soft" onClick={() => toast('افتح قائمة الأسعار ثم استورد', 'accent')}>
              <Icon name="upload" size={14} />
              استيراد أسعار
            </button>
            <button className="btn btn-dark" onClick={() => (lists.usingMock || groups.usingMock) ? toast('البيانات تجريبية — لا يمكن الحفظ', 'red') : (tab === 'lists' ? setForm({}) : setGform({}))}>
              <Icon name="plus" size={14} />
              {tab === 'lists' ? 'قائمة أسعار' : 'مجموعة عملاء'}
            </button>
          </>
        }
      />
      <RemoteFeedback state={tab === 'lists' ? lists : groups} />
      <TabsBar
        value={tab}
        onChange={(v: any) => setTab(v)}
        tabs={[
          { value: 'lists', label: 'قوائم الأسعار', icon: 'tag', count: lists.data?.meta.total ?? 0 },
          { value: 'groups', label: 'مجموعات العملاء', icon: 'users', count: groups.data?.length ?? 0 },
        ]}
      />
      {tab === 'lists' && (
        <Card stg={3}>
          <div className="flex flex-wrap items-center gap-2 mb-4">
            <SearchPill value={q} onChange={setQ} placeholder="اسم القائمة" />
            <FSelect
              value={ty}
              onChange={setTy}
              placeholder="النوع"
              options={Object.entries(PL_TYPES).map(([v, l]) => ({ value: v, label: l }))}
            />
            <FSelect value={cus} onChange={setCus} placeholder="العميل" options={(customers.data ?? []).map((c: { id: number; name: string }) => ({ value: String(c.id), label: c.name }))} />
            <FSelect
              value={grp}
              onChange={setGrp}
              placeholder="المجموعة"
              options={(groups.data ?? []).map((g: CustomerGroup) => ({ value: String(g.id), label: g.name }))}
            />
            <FSelect value={br} onChange={setBr} placeholder="الفرع" options={(branchesList.data ?? []).map((b: { id: number; name: string }) => ({ value: String(b.id), label: b.name }))} />
            <FSelect
              value={act}
              onChange={setAct}
              placeholder="التفعيل"
              options={[
                { value: 'true', label: 'مفعّلة' },
                { value: 'false', label: 'معطّلة' },
              ]}
            />
          </div>
          <DataTable
            rows={lists.loading ? [] : rows}
            empty={lists.loading ? 'جارٍ التحميل…' : undefined}
            onRow={(p: PriceListRow) => lists.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : go({ m: 'pricing', id: p.id })}
            cols={[
              { h: 'الاسم', r: (p: PriceListRow) => <span className="font-semibold tx1">{p.name}</span> },
              {
                h: 'النوع',
                r: (p: PriceListRow) => (
                  <Badge
                    dot={false}
                    tone={
                      ({
                        retail: 'gray',
                        wholesale: 'violet',
                        distributor: 'blue',
                        group: 'orange',
                        customer: 'green',
                      } as const)[p.type as 'retail'] || 'gray'
                    }
                  >
                    {PL_TYPES[p.type] || p.type}
                  </Badge>
                ),
              },
              {
                h: 'العميل / المجموعة',
                r: (p: PriceListRow) => (p.target ? <span className="tx1 text-[12px]">{p.target}</span> : <span className="tx3">—</span>),
              },
              {
                h: 'الفرع',
                r: (p: PriceListRow) => (p.branch ? <span className="tx2 text-[12px]">{p.branch}</span> : <span className="tx3 text-[11.5px]">كل الفروع</span>),
              },
              { h: 'العملة', r: () => <span className="num text-[11.5px] font-semibold tx2">EGP</span> },
              {
                h: 'السريان',
                r: (p: PriceListRow) => (
                  <span className="num text-[11.5px] tx2 ltr">
                    {p.from} → {p.to || '∞'}
                  </span>
                ),
              },
              {
                h: 'الأولوية',
                align: 'center',
                r: (p: PriceListRow) => <Num className="font-semibold">{p.prio}</Num>,
              },
              {
                h: 'البنود',
                align: 'center',
                r: (p: PriceListRow) => <Num className="tx2">{fmtInt(p.items)}</Num>,
              },
              { h: 'التفعيل', r: (p: PriceListRow) => <ActiveDot on={p.active} /> },
            ]}
            foot={<Pager total={lists.data?.meta.total ?? rows.length} per={20} page={page} onPage={setPage} />}
          />
        </Card>
      )}
      {tab === 'groups' && (
        <Card stg={3}>
          <DataTable
            rows={groups.data ?? []}
            onRow={(g: CustomerGroup) => groups.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setGform(g)}
            cols={[
              {
                h: 'المجموعة',
                r: (g: CustomerGroup) => (
                  <div className="leading-tight">
                    <div className="font-semibold tx1">{g.name}</div>
                    {g.desc && <div className="text-[11.5px] tx3 mt-0.5">{g.desc}</div>}
                  </div>
                ),
              },
              {
                h: 'الكود',
                r: (g: CustomerGroup) => (
                  <span className="num text-[11.5px] font-semibold v-sub px-2 py-1 rounded-md tx2">{g.code}</span>
                ),
              },
              {
                h: 'نسبة الخصم',
                r: (g: CustomerGroup) => (
                  <div className="flex items-center gap-2.5 min-w-[140px]">
                    <div className="flex-1">
                      <HatchBar pct={g.disc * 4} h={10} />
                    </div>
                    <Num className="font-semibold">{g.disc}%</Num>
                  </div>
                ),
              },
              { h: 'التفعيل', r: (g: CustomerGroup) => <ActiveDot on={g.active} /> },
              {
                h: '',
                r: (g: CustomerGroup) => (
                  <RowActions>
                    <button className="icon-btn !w-7 !h-7" onClick={() => groups.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setGform(g)}>
                      <Icon name="edit" size={13} />
                    </button>
                  </RowActions>
                ),
              },
            ]}
          />
        </Card>
      )}
      {form && <PriceListForm row={form.id ? (form as PriceListRow) : null} onClose={() => setForm(null)} onSaved={() => lists.reload()} />}
      {gform && (
        <GroupForm row={(gform as CustomerGroup).id ? (gform as CustomerGroup) : null} onClose={() => setGform(null)} onSaved={() => groups.reload()} />
      )}
    </div>
  );
}

export function PriceImportModal({ open, priceListId, onClose, onSaved }: { open: boolean; priceListId: number; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [file, setFile] = useState<File | null>(null);
  const [report, setReport] = useState<PriceListImportReport | null>(null);
  if (!open) return null;
  const doImport = async () => {
    if (!file) return toast('اختر ملف CSV', 'red');
    if (file.size > 10 * 1024 * 1024) return toast('حجم الملف يتجاوز 10MB', 'red');
    const r = await run(() => priceListsApi.import(priceListId, file), { toast, success: 'تم الاستيراد' });
    if (r) { setReport((r as any).data ?? (r as any)); onSaved(); }
  };
  return (
    <Modal open onClose={onClose} title="استيراد قوائم الأسعار" icon="upload" width={540}
      footer={<><button className="btn btn-soft" onClick={onClose}>إغلاق</button><button className="btn btn-dark" disabled={busy || !file} onClick={doImport}>رفع واستيراد</button></>}>
      <div className="space-y-4">
        <Dropzone accept=".csv" max="10MB" label="اسحب ملف CSV هنا" onFile={setFile} />
        {report && (
          <div>
            <div className="lbl mb-2">نتيجة الاستيراد</div>
            <div className="grid grid-cols-3 gap-2 mb-2">
              <div className="v-sub p-3"><div className="text-[11px] tx2">صفوف ناجحة</div><div className="font-display text-[20px] font-bold num c-green">{report.created}</div></div>
              <div className="v-sub p-3"><div className="text-[11px] tx2">تم تحديثها</div><div className="font-display text-[20px] font-bold num tx1">{report.updated}</div></div>
              <div className="v-sub p-3"><div className="text-[11px] tx2">أخطاء</div><div className="font-display text-[20px] font-bold num c-red">{report.errors.length}</div></div>
            </div>
            {!!report.errors.length && (
              <div className="v-sub p-1 max-h-[160px] overflow-y-auto">
                <table className="tbl compact"><tbody>
                  {report.errors.map((e) => (<tr key={e.line}><td className="num tx3 !w-14">#{e.line}</td><td className="text-[12px] c-red">{e.message}</td></tr>))}
                </tbody></table>
              </div>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}

export function GroupForm({ row, onClose, onSaved }: { row: CustomerGroup | null; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({ name: row?.name ?? '', code: row?.code ?? '', desc: row?.desc ?? '', disc: row?.disc ?? 0, active: row?.active ?? true });
  const save = async () => {
    if (!f.v.name.trim() || !f.v.code.trim()) return toast('الاسم والكود مطلوبان', 'red');
    if (f.v.code.length > 64) return toast('الكود حتى 64 حرفًا', 'red');
    const body = { name: f.v.name, code: f.v.code, description: (f.v.desc as string) || null, discount_percentage: Number(f.v.disc || 0), is_active: !!f.v.active };
    const r = await run(() => (row ? customerGroupsApi.update(row.id, body) : customerGroupsApi.create(body)),
      { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { name: 'name', code: 'code', description: 'desc', discount_percentage: 'disc' })) });
    if (r) { onSaved(); onClose(); }
  };
  return (
    <Modal open onClose={onClose} title={row ? 'تعديل مجموعة عملاء' : 'مجموعة عملاء جديدة'} icon="users"
      footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={busy} onClick={save}>حفظ</button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="الاسم" req err={f.errors.name}><input className="inp" value={f.v.name} onChange={f.on('name')} /></Field>
          <Field label="الكود" req hint="فريد" err={f.errors.code}><input className="inp num" dir="ltr" value={f.v.code} onChange={f.on('code')} /></Field>
        </div>
        <TextArea label="الوصف" value={f.v.desc} onChange={(v) => f.set('desc', v)} />
        <Field label="نسبة الخصم" hint="من 0 إلى 100" err={f.errors.disc}>
          <div className="relative"><input className="inp num" type="number" min="0" max="100" step="0.01" value={f.v.disc} onChange={f.on('disc' as any)} /><span className="absolute top-1/2 -translate-y-1/2 tx3 text-[12px]" style={{ insetInlineEnd: 14 }}>%</span></div>
        </Field>
        <ToggleRow label="مفعّلة" on={f.v.active} onChange={(v) => f.set('active', v)} />
      </div>
    </Modal>
  );
}

export function PriceListForm({ row, onClose, onSaved }: { row: (PriceListRow & { raw?: any }) | null; onClose: () => void; onSaved?: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [type, setType] = useState(row?.type || 'retail');
  const f = useFormState({
    name: row?.name ?? '', customerId: String(row?.raw?.customer?.id ?? ''), groupId: String(row?.raw?.customer_group?.id ?? ''), branchId: String(row?.raw?.branch?.id ?? ''),
    from: row?.from ?? '', to: (row?.to ?? '') as string, prio: row?.prio ?? 0, active: row?.active ?? true,
  });
  const [prio, setPrio] = useState(row?.prio ?? 0);
  const prErr = prio < -10000 || prio > 10000 ? 'الأولوية من ‎-10000 إلى 10000' : null;
  const groups = useRemote((s) => customerGroupsApi.all(s).then((rows) => rows.map(mapCustomerGroup)), [], { mock: () => CUSTOMER_GROUPS });
  const branchesList = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
  const customers = useRemote((s) => commerceLookupsApi.customers(s), []);
  const toBackendType = (t: string): PriceListType => (t === 'group' ? 'customer_group' : t === 'customer' ? 'customer_specific' : t) as PriceListType;
  const save = async () => {
    if (!f.v.name.trim()) return toast('اسم القائمة مطلوب', 'red');
    if (prErr) return;
    const raw = (row as any)?.raw;
    const body: any = {
      name: f.v.name, type: toBackendType(type),
      customer_id: type === 'customer' ? (f.v.customerId ? Number(f.v.customerId) : (raw?.customer?.id ?? null)) : null,
      customer_group_id: type === 'group' ? (f.v.groupId ? Number(f.v.groupId) : (raw?.customer_group?.id ?? null)) : null,
      branch_id: f.v.branchId ? Number(f.v.branchId) : null,
      currency: 'EGP', starts_at: f.v.from || null, ends_at: f.v.to || null,
      priority: prio, is_active: f.v.active,
    };
    const r = await run(() => (row && (row as any).id ? priceListsApi.update((row as any).id, body) : priceListsApi.create(body)),
      { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { starts_at: 'from', ends_at: 'to', priority: 'prio', customer_id: 'customerId', customer_group_id: 'groupId', branch_id: 'branchId' })) });
    if (r) { onSaved?.(); onClose(); }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title={row ? 'تعديل قائمة أسعار' : 'قائمة أسعار جديدة'}
      icon="tag"
      width={620}
      footer={
        <>
          <button className="btn btn-soft" onClick={onClose}>
            إلغاء
          </button>
          <button
            className="btn btn-dark"
            disabled={!!prErr || busy}
            onClick={save}
          >
            حفظ
          </button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="الاسم" req err={f.errors.name}>
          <input className="inp" value={f.v.name} onChange={f.on('name')} />
        </Field>
        <Field label="النوع" req>
          <div className="flex flex-wrap gap-1.5">
            {Object.entries(PL_TYPES).map(([k, l]) => (
              <button
                key={k}
                type="button"
                onClick={() => setType(k)}
                className={'pill-inp font-medium ' + (type === k ? 'on' : '')}
              >
                {l}
              </button>
            ))}
          </div>
        </Field>
        {type === 'customer' && (
          <Field label="العميل" req err={f.errors.customerId}>
            <select className="inp" value={f.v.customerId} onChange={f.on('customerId' as any)}>
              <option value="">اختر العميل</option>
              {(customers.data ?? []).map((w: { id: number; name: string }) => (
                <option key={w.id} value={String(w.id)}>{w.name}</option>
              ))}
            </select>
          </Field>
        )}
        {type === 'group' && (
          <Field label="مجموعة العملاء" req err={f.errors.groupId}>
            <select className="inp" value={f.v.groupId} onChange={f.on('groupId' as any)}>
              <option value="">اختر المجموعة</option>
              {(groups.data ?? []).map((g: CustomerGroup) => (
                <option key={g.id} value={String(g.id)}>{g.name}</option>
              ))}
            </select>
          </Field>
        )}
        <div className="grid grid-cols-2 gap-3">
          <Field label="الفرع" hint="اتركه فارغًا لكل الفروع" err={f.errors.branchId}>
            <select className="inp" value={f.v.branchId} onChange={f.on('branchId' as any)}>
              <option value="">كل الفروع</option>
              {(branchesList.data ?? []).map((b: { id: number; name: string }) => (
                <option key={b.id} value={String(b.id)}>{b.name}</option>
              ))}
            </select>
          </Field>
          <Field label="العملة">
            <input className="inp num" value="EGP" readOnly dir="ltr" />
          </Field>
          <Field label="بداية السريان" err={f.errors.from}>
            <input type="date" className="inp" value={f.v.from} onChange={f.on('from' as any)} />
          </Field>
          <Field label="نهاية السريان" err={f.errors.to}>
            <input type="date" className="inp" value={f.v.to} onChange={f.on('to' as any)} />
          </Field>
        </div>
        <Field label="الأولوية" err={prErr ?? f.errors.prio} hint="الأعلى يُطبَّق أولًا عند تعارض القوائم · من ‎-10000 إلى 10000">
          <input
            type="number"
            className={'inp num ' + (prErr ? 'err' : '')}
            value={prio}
            onChange={(e) => setPrio(Number(e.target.value))}
          />
        </Field>
        <ToggleRow label="مفعّلة" on={f.v.active} onChange={(v) => f.set('active', v)} />
      </div>
    </Modal>
  );
}

export function AddPriceItemModal({ priceListId, onClose, onSaved }: { priceListId: number; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({ variantId: '', min: 1, price: '' as any, from: '', to: '' });
  const variants = useRemote((signal) => commerceLookupsApi.variants(signal), []);
  const variantOptions = variants.data ?? [];
  const save = async () => {
    if (!f.v.variantId) return toast('اختر متغير المنتج', 'red');
    if (!f.v.price && f.v.price !== 0) return toast('أدخل السعر', 'red');
    const r = await run(() => priceListItemsApi(priceListId).create({
      product_variant_id: Number(f.v.variantId), min_qty: Number(f.v.min || 1), price: Number(f.v.price),
      starts_at: (f.v.from as string) || null, ends_at: (f.v.to as string) || null,
    }), { toast, success: 'تمت الإضافة', onFieldErrors: (e) => f.setErrors(e as any) });
    if (r) { onSaved(); onClose(); }
  };
  return (
    <Modal open onClose={onClose} title="إضافة بند للقائمة" icon="tag" width={560}
      footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={busy} onClick={save}>إضافة</button></>}>
      <div className="space-y-4">
        <RemoteFeedback state={variants} />
        <Field label="متغير المنتج" req err={f.errors.product_variant_id}>
          <select className="inp" value={f.v.variantId} onChange={f.on('variantId' as any)}>
            <option value="">اختر المتغير</option>
            {variantOptions.map((v) => (<option key={v.id} value={String(v.id)}>{v.label}</option>))}
          </select>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="أقل كمية لتطبيق السعر" req hint="1 أو أكثر" err={f.errors.min_qty}><input type="number" min="1" className="inp num" value={f.v.min} onChange={f.on('min' as any)} /></Field>
          <Field label="السعر (EGP)" req hint="حتى 4 منازل عشرية" err={f.errors.price}><input type="number" step="0.0001" min="0" className="inp num" value={f.v.price} onChange={f.on('price' as any)} /></Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="بداية سريان البند" hint="اختياري"><input type="date" className="inp" value={f.v.from} onChange={f.on('from' as any)} /></Field>
          <Field label="نهاية سريان البند" hint="اختياري"><input type="date" className="inp" value={f.v.to} onChange={f.on('to' as any)} /></Field>
        </div>
      </div>
    </Modal>
  );
}

export function PriceListDetail({ id, go }: { id: number; go: (r: { m: string; id?: any; sub?: any }) => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const pl = useRemote((s) => priceListsApi.show(id, s).then(mapPriceList), [id], { mock: () => (PRICE_LISTS.find((row) => row.id === id) ?? PRICE_LISTS[0]) as any, onError: (e) => toast(e.message, 'red') });
  const p = pl.data;
  const itemsApi = useMemo(() => priceListItemsApi(id), [id]);
  const [search, setSearch] = useState('');
  const dsearch = useDebounced(search);
  const remoteItems = useRemote((s) => itemsApi.all(s), [id], { mock: () => [] });
  const [items, setItems] = useState<any[]>([]);
  useEffect(() => {
    setItems((remoteItems.data ?? []).map((it) => ({
      id: it.id, v: { name: it.variant?.product_name_ar ?? `#${it.product_variant_id}`, sku: it.variant?.sku ?? '' },
      variantId: it.product_variant_id, min: it.min_qty, price: it.price, from: it.starts_at?.slice(0, 10) ?? '', to: it.ends_at?.slice(0, 10) ?? '',
    })));
  }, [remoteItems.data]);
  const [editRow, setEditRow] = useState<any>(null);
  const [form, setForm] = useState(false);
  const [pct, setPct] = useState('');
  const [imp, setImp] = useState(false);
  const upd = (rowId: number, k: string, v: any) =>
    setItems(items.map((it) => (it.id === rowId ? { ...it, [k]: v } : it)));
  const saveRow = async (r: any) => {
    await run(() => itemsApi.update(r.id, { product_variant_id: r.variantId, min_qty: Number(r.min), price: Number(r.price), starts_at: r.from || null, ends_at: r.to || null }),
      { toast, success: 'تم حفظ البند' });
  };
  const deleteRow = async (r: any) => {
    const ok = await run(() => itemsApi.remove(r.id), { toast, success: 'تم حذف البند' });
    if (ok) setItems(items.filter((x) => x.id !== r.id));
  };
  const applyPercent = async (pctVal: number) => {
    if (pctVal < -100) return toast('النسبة لا تقل عن -100%', 'red');
    const result = await run(async () => {
      for (const r of items) {
        const price = Number((Number(r.price) * (1 + pctVal / 100)).toFixed(4));
        await itemsApi.update(r.id, { product_variant_id: r.variantId, min_qty: Number(r.min), price, starts_at: r.from || null, ends_at: r.to || null });
      }
      return true;
    }, { toast, success: 'تم تطبيق التعديل' });
    remoteItems.reload();
    if (result) setPct('');
  };
  if (!p) return <RemoteFeedback state={pl} />;

  return (
    <div className="space-y-5">
      <PageHead
        back={{ label: 'قوائم الأسعار', onClick: () => go({ m: 'pricing' }) }}
        title={p.name}
        sub={
          <span className="flex flex-wrap items-center gap-2">
            <Badge dot={false} tone="violet">
              {PL_TYPES[p.type] || p.type}
            </Badge>
            {p.target && <span>{p.target} ·</span>}
            <span>{p.branch || 'كل الفروع'}</span>·
            <span className="num ltr">
              {p.from} → {p.to || '∞'}
            </span>
            ·
            <span>
              أولوية <Num>{p.prio}</Num>
            </span>
          </span>
        }
        actions={
          <>
            <button className="btn btn-soft" disabled={pl.usingMock} onClick={() => setForm(true)}>
              <Icon name="edit" size={14} />
              تعديل القائمة
            </button>
            <button className="btn btn-soft" disabled={busy || pl.usingMock || remoteItems.usingMock} onClick={() => run(() => priceListsApi.exportCsv(id, p.name), { toast, success: 'تم تصدير CSV' })}>
              <Icon name="download" size={14} />
              تصدير CSV
            </button>
            <button className="btn btn-soft" disabled={pl.usingMock} onClick={() => setImp(true)}><Icon name="upload" size={14} />استيراد أسعار</button>
            <button className="btn btn-dark" disabled={pl.usingMock || remoteItems.usingMock} onClick={() => setEditRow({})}>
              <Icon name="plus" size={14} />
              إضافة بند
            </button>
          </>
        }
      />
      <RemoteFeedback state={pl} />
      <RemoteFeedback state={remoteItems} />
      <Card stg={2}>
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div className="flex items-center gap-2">
            <SearchPill value={search} onChange={setSearch} placeholder="بحث بالاسم أو SKU" />
            <span className="text-[11.5px] tx3">
              <Num>{items.length}</Num> بند مسجّل
            </span>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-[12px] tx2">تعديل نسبة مئوية جماعي:</span>
            <input type="number" step="0.5" placeholder="+5%" className="inp inp-sm num w-20" value={pct} onChange={(e) => setPct(e.target.value)} />
            <button className="btn btn-soft btn-sm" disabled={busy || pl.usingMock || remoteItems.usingMock || !pct} onClick={() => { const v = Number(pct); if (!Number.isFinite(v)) return toast('أدخل نسبة صحيحة', 'red'); void applyPercent(v); }}>
              تطبيق
            </button>
          </div>
        </div>
        <DataTable
          rows={items.filter((row) => !dsearch || (row.v.name + ' ' + row.v.sku).toLowerCase().includes(dsearch.toLowerCase()))}
          cols={[
            {
              h: 'المنتج / المتغير',
              r: (r: any) => (
                <div className="leading-tight">
                  <div className="font-medium tx1">{r.v.name}</div>
                  <div className="text-[11px] tx3 num">{r.v.sku}</div>
                </div>
              ),
            },
            {
              h: 'أقل كمية',
              align: 'center',
              r: (r: any) => (
                <input
                  type="number"
                  min="1"
                  className="inp inp-sm num !w-20 text-center"
                  disabled={pl.usingMock || remoteItems.usingMock}
                  value={r.min}
                  onChange={(e) => upd(r.id, 'min', e.target.value)}
                  onBlur={() => saveRow(r)}
                />
              ),
            },
            {
              h: 'السعر (حتى 4 منازل)',
              r: (r: any) => (
                <div className="flex items-center gap-1.5">
                  <input
                    type="number"
                    step="0.0001"
                    className="inp inp-sm num !w-28 text-end"
                    value={r.price}
                    onChange={(e) => upd(r.id, 'price', e.target.value)}
                    onBlur={() => saveRow(r)}
                  />
                  <span className="text-[11px] tx3">EGP</span>
                </div>
              ),
            },
            {
              h: 'فترة سريان البند',
              r: (r: any) => (
                <div className="flex items-center gap-1 num text-[11px]">
                  <input
                    type="date"
                    className="inp inp-sm text-[11px] !py-0.5"
                    value={r.from}
                    onChange={(e) => upd(r.id, 'from', e.target.value)}
                    onBlur={() => saveRow(r)}
                  />
                  <span className="tx3">→</span>
                  <input
                    type="date"
                    className="inp inp-sm text-[11px] !py-0.5"
                    value={r.to}
                    onChange={(e) => upd(r.id, 'to', e.target.value)}
                    onBlur={() => saveRow(r)}
                  />
                </div>
              ),
            },
            {
              h: '',
              r: (r: any) => (
                <RowActions>
                  <button className="icon-btn !w-7 !h-7" disabled={busy} onClick={() => deleteRow(r)}>
                    <Icon name="trash" size={13} />
                  </button>
                </RowActions>
              ),
            },
          ]}
        />
      </Card>
      <PriceImportModal open={imp} priceListId={id} onClose={() => setImp(false)} onSaved={() => { remoteItems.reload(); pl.reload(); }} />
      {editRow && (
        <AddPriceItemModal priceListId={id} onClose={() => setEditRow(null)} onSaved={() => { remoteItems.reload(); pl.reload(); }} />
      )}
      {form && <PriceListForm row={p as any} onClose={() => setForm(false)} onSaved={() => pl.reload()} />}
    </div>
  );
}

/* ---------------- PROMOS & COUPONS ---------------- */
export function PromosPage() {
  const toast = useToast();
  const { run } = useAction();
  const [tab, setTab] = useState<'promos' | 'coupons'>('promos');
  const [pf, setPf] = useState<PromoRow | null>(null);
  const [cf, setCf] = useState<CouponRow | null>(null);
  const [newPromo, setNewPromo] = useState(false);
  const [newCoupon, setNewCoupon] = useState(false);
  const promos = useRemote((s) => promotionsApi.all(s).then((rows) => rows.map((p) => ({ ...mapPromo(p), targetIds: p.target_ids ?? [], targets: (p.target_ids ?? []).map(String) }))), [], { mock: () => PROMOS });
  const coupons = useRemote((s) => couponsApi.all(s).then((rows) => rows.map(mapCoupon)), [], { mock: () => COUPONS });

  const promoVal = (p: PromoRow) =>
    p.type === 'percent' ? (
      <Num className="font-semibold">{p.value}%</Num>
    ) : p.type === 'amount' ? (
      <span className="font-semibold">{fmtMoney(p.value || 0, 0)}</span>
    ) : (
      <span className="tx3">—</span>
    );

  return (
    <div className="space-y-5">
      <PageHead
        title="العروض والكوبونات"
        sub="العروض تُطبق على نطاق محدد، والكوبونات بأكواد يُدخلها العميل."
        actions={
          <button className="btn btn-dark" onClick={() => (promos.usingMock || coupons.usingMock) ? toast('البيانات تجريبية — لا يمكن الحفظ', 'red') : (tab === 'promos' ? setNewPromo(true) : setNewCoupon(true))}>
            <Icon name="plus" size={14} />
            {tab === 'promos' ? 'عرض ترويجي' : 'كوبون جديد'}
          </button>
        }
      />
      <RemoteFeedback state={tab === 'promos' ? promos : coupons} />
      <Note>العروض مسجلة للإدارة ولا يطبقها الخادم تلقائيًا عند الشراء.</Note>
      <TabsBar
        value={tab}
        onChange={(v: any) => setTab(v)}
        tabs={[
          { value: 'promos', label: 'العروض الترويجية', icon: 'ticket', count: (promos.data ?? []).length },
          { value: 'coupons', label: 'كوبونات الخصم', icon: 'tag', count: (coupons.data ?? []).length },
        ]}
      />
      {tab === 'promos' && (
        <Card stg={3}>
          <DataTable
            rows={promos.data ?? []}
            onRow={(p: PromoRow) => promos.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setPf(p)}
            cols={[
              { h: 'اسم العرض', r: (p: PromoRow) => <span className="font-semibold tx1">{p.name}</span> },
              {
                h: 'النوع',
                r: (p: PromoRow) => (
                  <Badge
                    dot={false}
                    tone={
                      ({
                        percent: 'orange',
                        amount: 'blue',
                        free_ship: 'green',
                        bxgy: 'violet',
                      } as const)[p.type as 'percent'] || 'gray'
                    }
                  >
                    {PROMO_TYPES[p.type] || p.type}
                  </Badge>
                ),
              },
              { h: 'القيمة', r: promoVal },
              {
                h: 'الحد الأدنى',
                r: (p: PromoRow) =>
                  p.min ? <span className="tx2 font-medium">{fmtMoney(p.min, 0)}</span> : <span className="tx3">—</span>,
              },
              {
                h: 'النطاق والمستهدفات',
                r: (p: PromoRow) => (
                  <div className="leading-tight">
                    <span className="text-[12px] font-medium">{SCOPES[p.scope]}</span>
                    {p.targets?.length > 0 && (
                      <div className="text-[11px] tx3 clamp1 mt-0.5">{p.targets.join('، ')}</div>
                    )}
                  </div>
                ),
              },
              {
                h: 'السريان',
                r: (p: PromoRow) => (
                  <span className="num text-[11.5px] tx2 ltr">
                    {p.from} → {p.to || '∞'}
                  </span>
                ),
              },
              { h: 'التفعيل', r: (p: PromoRow) => <ActiveDot on={p.active} /> },
              {
                h: '',
                r: (p: PromoRow) => (
                  <RowActions>
                    <button className="icon-btn !w-7 !h-7" onClick={() => promos.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setPf(p)}>
                      <Icon name="edit" size={13} />
                    </button>
                  </RowActions>
                ),
              },
            ]}
          />
        </Card>
      )}
      {tab === 'coupons' && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {(coupons.data ?? []).map((c, i) => {
            const pct = c.total ? Math.min(100, (c.used / c.total) * 100) : null;
            return (
              <div
                key={c.id}
                className={'v-card p-5 stg stg-' + (2 + i) + ' cursor-pointer hover:shadow-sm transition'}
                onClick={() => coupons.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setCf(c)}
              >
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="font-display text-[22px] font-bold num tracking-[.06em] tx1 ltr">{c.code}</div>
                    <div className="flex items-center gap-2 mt-1.5">
                      <Badge dot={false} tone="orange">
                        {COUPON_TYPES[c.type]}
                      </Badge>
                      {c.value && (
                        <span className="font-semibold text-[13px]">
                          {c.type === 'percent' ? <Num>{c.value}%</Num> : fmtMoney(c.value, 0)}
                        </span>
                      )}
                      {c.maxDisc && (
                        <span className="text-[11.5px] tx3">حد أقصى {fmtMoney(c.maxDisc, 0)}</span>
                      )}
                    </div>
                  </div>
                  <ActiveDot on={c.active} />
                </div>
                <div className="mt-4">
                  <div className="flex items-center justify-between text-[12px] mb-1.5">
                    <span>
                      <b className="num tx1">{fmtInt(c.used)}</b> <span className="tx3">مرة استخدام</span>
                    </span>
                    <span className="tx2">{c.total ? <>من <Num>{fmtInt(c.total)}</Num></> : 'غير محدود'}</span>
                  </div>
                  {pct != null ? <HatchBar pct={pct} h={12} /> : <div className="h-3 rounded-full hatch-track"></div>}
                </div>
                <div className="grid grid-cols-3 gap-2 mt-4">
                  <div className="v-sub px-3 py-2">
                    <div className="text-[10.5px] tx3">الحد الأدنى</div>
                    <div className="text-[12px] font-semibold">{fmtMoney(c.min, 0)}</div>
                  </div>
                  <div className="v-sub px-3 py-2">
                    <div className="text-[10.5px] tx3">لكل عميل</div>
                    <div className="text-[12px] font-semibold">{c.perCust ? <Num>{c.perCust}</Num> : '∞'}</div>
                  </div>
                  <div className="v-sub px-3 py-2">
                    <div className="text-[10.5px] tx3">السريان</div>
                    <div className="text-[11px] font-semibold num ltr">{c.to ? c.to.slice(5) : '∞'}</div>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
      {(pf || newPromo) && <PromoForm row={pf} onClose={() => { setPf(null); setNewPromo(false); }} onSaved={() => promos.reload()} />}
      {(cf || newCoupon) && <CouponForm row={cf} onClose={() => { setCf(null); setNewCoupon(false); }} onSaved={() => coupons.reload()} />}
    </div>
  );
}

export function PromoForm({ row, onClose, onSaved }: { row: PromoRow | null; onClose: () => void; onSaved?: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [deleting, setDeleting] = useState(false);
  const [type, setType] = useState(row?.type || 'percent');
  const [scope, setScope] = useState(row?.scope || 'all');
  const [targets, setTargets] = useState<number[]>((row as PromoRow & { targetIds?: number[] } | null)?.targetIds ?? []);
  const options = useRemote((signal) => scope === 'categories' ? commerceLookupsApi.categories(signal) : scope === 'brands' ? commerceLookupsApi.brands(signal) : commerceLookupsApi.variants(signal), [scope], { enabled: scope !== 'all' && scope !== 'brands' });
  const f = useFormState({ name: row?.name ?? '', value: (row?.value ?? '') as any, min: (row?.min ?? '') as any, from: row?.from ?? '', to: (row?.to ?? '') as string, active: row?.active ?? true });
  const save = async () => {
    if (!f.v.name.trim()) return toast('اسم العرض مطلوب', 'red');
    const body: any = {
      name: f.v.name, type: promoToBackend(type),
      value: type === 'percent' || type === 'amount' ? (f.v.value === '' ? undefined : Number(f.v.value)) : undefined,
      min_order_amount: f.v.min === '' ? null : Number(f.v.min),
      applies_to: scope, target_ids: scope === 'all' ? [] : targets,
      starts_at: f.v.from || null, ends_at: (f.v.to as string) || null, is_active: !!f.v.active,
    };
    const r = await run(() => (row ? promotionsApi.update(row.id, body) : promotionsApi.create(body)),
      { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { min_order_amount: 'min', applies_to: 'scope', target_ids: 'targets', starts_at: 'from', ends_at: 'to' })) });
    if (r) { onSaved?.(); onClose(); }
  };

  const opts = options.data ?? [];
  const on = (id: number) => setTargets(targets.includes(id) ? targets.filter((target) => target !== id) : [...targets, id]);

  return (
    <Modal
      open
      onClose={onClose}
      title={row ? 'تعديل عرض ترويجي' : 'عرض ترويجي جديد'}
      icon="ticket"
      width={640}
      footer={
        <>
          {row && <button className="btn btn-danger" disabled={busy} onClick={() => setDeleting(true)}>حذف</button>}
          <button className="btn btn-soft" onClick={onClose}>
            إلغاء
          </button>
          <button
            className="btn btn-dark"
            disabled={busy}
            onClick={save}
          >
            حفظ
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {scope !== 'all' && <RemoteFeedback state={options} />}
        <Field label="اسم العرض" req err={f.errors.name}>
          <input className="inp" value={f.v.name} onChange={f.on('name')} />
        </Field>
        <Field label="النوع" req>
          <div className="flex flex-wrap gap-1.5">
            {Object.entries(PROMO_TYPES).map(([k, l]) => (
              <button
                key={k}
                type="button"
                onClick={() => setType(k as any)}
                className={'pill-inp font-medium ' + (type === k ? 'on' : '')}
              >
                {l}
              </button>
            ))}
          </div>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          {type !== 'free_ship' && type !== 'bxgy' && (
            <Field label={type === 'percent' ? 'نسبة الخصم %' : 'مبلغ الخصم (EGP)'} req err={f.errors.value}>
              <input type="number" step="0.01" min="0" className="inp num" value={f.v.value} onChange={f.on('value' as any)} />
            </Field>
          )}
          {type === 'bxgy' && (
            <>
              <Field label="اشترِ (كمية X)" hint="الخادم لا يدعم حفظ كميات X/Y حاليًا">
                <input type="number" min="1" className="inp num" value="2" readOnly disabled />
              </Field>
              <Field label="واحصل على (كمية Y)" hint="العرض يُحفظ للإدارة فقط ولا يُطبق تلقائيًا">
                <input type="number" min="1" className="inp num" value="1" readOnly disabled />
              </Field>
            </>
          )}
          <Field label="الحد الأدنى لقيمة الطلب" hint="اختياري" err={f.errors.min}>
            <input type="number" step="0.01" min="0" className="inp num" value={f.v.min} onChange={f.on('min' as any)} />
          </Field>
        </div>
        <Field label="نطاق التطبيق" req err={f.errors.scope}>
          <div className="flex flex-wrap gap-1.5">
            {Object.entries(SCOPES).map(([k, l]) => (
              <button
                key={k}
                type="button"
                onClick={() => {
                  setScope(k as any);
                  setTargets([]);
                }}
                className={'pill-inp font-medium ' + (scope === k ? 'on' : '')}
              >
                {l}
              </button>
            ))}
          </div>
        </Field>
        {f.errors.targets && <Note tone="red">{f.errors.targets}</Note>}
        {scope !== 'all' && (
          <div>
            <div className="flex items-center justify-between mb-2">
              <span className="lbl">المستهدفات المشمولة</span>
              <span className="text-[11px] tx3">
                <Num>{targets.length}</Num> مختارة
              </span>
            </div>
            <div className="flex flex-wrap gap-1.5 p-3 rounded-[14px] v-sub max-h-[140px] overflow-y-auto">
              {opts.map((o) => (
                <button
                  key={o.id}
                  type="button"
                  onClick={() => on(o.id)}
                  className={'pill-inp text-[12px] ' + (targets.includes(o.id) ? 'on' : '')}
                >
                  {o.label}
                </button>
              ))}
            </div>
          </div>
        )}
        <div className="grid grid-cols-2 gap-3">
          <Field label="بداية السريان" req err={f.errors.from}>
            <input type="date" className="inp" value={f.v.from} onChange={f.on('from' as any)} />
          </Field>
          <Field label="نهاية السريان" hint="اتركه فارغًا لمستمر" err={f.errors.to}>
            <input type="date" className="inp" value={f.v.to} onChange={f.on('to' as any)} />
          </Field>
        </div>
        <ToggleRow label="مفعّل" on={f.v.active} onChange={(v) => f.set('active', v)} />
      </div>
      <ConfirmModal open={deleting} danger title="حذف العرض" text="سيُحذف السجل نهائيًا." cta="حذف" onClose={() => setDeleting(false)} onConfirm={async () => {
        if (!row || busy) return false;
        const deleted = await run(() => promotionsApi.remove(row.id), { toast, success: 'تم الحذف' });
        if (deleted) { onSaved?.(); onClose(); }
        return !!deleted;
      }} />
    </Modal>
  );
}

export function CouponForm({ row, onClose, onSaved }: { row: CouponRow | null; onClose: () => void; onSaved?: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const [deleting, setDeleting] = useState(false);
  const [code, setCode] = useState(row?.code || '');
  const [type, setType] = useState(row?.type || 'percent');
  const f = useFormState({
    value: (row?.value ?? '') as any, maxDisc: (row?.maxDisc ?? '') as any, min: (row?.min ?? '') as any,
    total: (row?.total ?? '') as any, perCust: (row?.perCust ?? '') as any,
    from: row?.from ?? '', to: (row?.to ?? '') as string, active: row?.active ?? true,
  });
  const save = async () => {
    if (!code.trim()) return toast('كود الكوبون مطلوب', 'red');
    const body: any = {
      code: code.toUpperCase().replace(/\s/g, ''), type: couponToBackend(type),
      value: type === 'free_ship' ? undefined : Number(f.v.value || 0),
      max_discount_amount: (f.v.maxDisc as string) === '' ? null : Number(f.v.maxDisc || 0),
      min_order_amount: Number(f.v.min || 0),
      usage_limit: (f.v.total as string) === '' ? null : Number(f.v.total),
      usage_limit_per_customer: (f.v.perCust as string) === '' ? 0 : Number(f.v.perCust),
      starts_at: f.v.from, ends_at: (f.v.to as string) || null, is_active: !!f.v.active,
    };
    const r = await run(() => (row ? couponsApi.update(row.id, body) : couponsApi.create(body)),
      { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { max_discount_amount: 'maxDisc', min_order_amount: 'min', usage_limit: 'total', usage_limit_per_customer: 'perCust', starts_at: 'from', ends_at: 'to' })) });
    if (r) { onSaved?.(); onClose(); }
  };
  return (
    <Modal
      open
      onClose={onClose}
      title={row ? 'تعديل كوبون' : 'كوبون جديد'}
      icon="ticket"
      width={600}
      footer={
        <>
          {row && <button className="btn btn-danger" disabled={busy} onClick={() => setDeleting(true)}>حذف</button>}
          <button className="btn btn-soft" onClick={onClose}>
            إلغاء
          </button>
          <button
            className="btn btn-dark"
            disabled={!code || busy}
            onClick={save}
          >
            حفظ
          </button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="الكود" req count={`${code.length} / 50`} hint="فريد · يُحوَّل تلقائيًا لحروف كبيرة" err={f.errors.code}>
          <input
            className="inp num font-bold tracking-[.08em]"
            dir="ltr"
            maxLength={50}
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase().replace(/\s/g, ''))}
            placeholder="SUMMER25"
          />
        </Field>
        <Field label="النوع" req>
          <Seg
            value={type}
            onChange={(v: any) => setType(v)}
            options={Object.entries(COUPON_TYPES).map(([v, l]) => ({ value: v, label: l }))}
          />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          {type !== 'free_ship' && (
            <Field label="قيمة الخصم" req err={f.errors.value}>
              <div className="relative">
                <input className="inp num" type="number" value={f.v.value} onChange={f.on('value' as any)} />
                <span
                  className="absolute top-1/2 -translate-y-1/2 tx3 text-[12px]"
                  style={{ insetInlineEnd: 14 }}
                >
                  {type === 'percent' ? '%' : 'EGP'}
                </span>
              </div>
            </Field>
          )}
          {type === 'percent' && (
            <Field label="أقصى مبلغ خصم" err={f.errors.maxDisc}>
              <input className="inp num" type="number" value={f.v.maxDisc} onChange={f.on('maxDisc' as any)} placeholder="بدون حد" />
            </Field>
          )}
          <Field label="الحد الأدنى للطلب" err={f.errors.min}>
            <input className="inp num" type="number" value={f.v.min} onChange={f.on('min' as any)} />
          </Field>
          <Field label="الحد الإجمالي للاستخدام" err={f.errors.total}>
            <input className="inp num" type="number" value={f.v.total} onChange={f.on('total' as any)} placeholder="غير محدود" />
          </Field>
          <Field label="حد الاستخدام لكل عميل" err={f.errors.perCust}>
            <input className="inp num" type="number" value={f.v.perCust} onChange={f.on('perCust' as any)} placeholder="غير محدود" />
          </Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="بداية السريان" err={f.errors.from}>
            <input type="date" className="inp" value={f.v.from} onChange={f.on('from' as any)} />
          </Field>
          <Field label="نهاية السريان" err={f.errors.to}>
            <input type="date" className="inp" value={f.v.to} onChange={f.on('to' as any)} />
          </Field>
        </div>
        <ToggleRow label="مفعّل" on={f.v.active} onChange={(v) => f.set('active', v)} />
      </div>
      <ConfirmModal open={deleting} danger title="حذف الكوبون" text="سيُحذف السجل نهائيًا." cta="حذف" onClose={() => setDeleting(false)} onConfirm={async () => {
        if (!row || busy) return false;
        const deleted = await run(() => couponsApi.remove(row.id), { toast, success: 'تم الحذف' });
        if (deleted) { onSaved?.(); onClose(); }
        return !!deleted;
      }} />
    </Modal>
  );
}

/* ---------------- BRANDS ---------------- */
export function BrandForm({ row, onClose, onSaved }: { row: BrandRow | null; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({
    ar: row?.ar ?? '', en: row?.en ?? '', slug: row?.slug ?? '', sort: row?.sort ?? 0,
    active: row?.active ?? true, descAr: (row as BrandRow & { descAr?: string } | null)?.descAr ?? '', descEn: (row as BrandRow & { descEn?: string } | null)?.descEn ?? '',
  });
  const [logoFile, setLogoFile] = useState<File | null>(null);
  const [removeLogo, setRemoveLogo] = useState(false);
  useEffect(() => {
    if (!logoFile) return;
    const url = URL.createObjectURL(logoFile);
    setLogoPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [logoFile]);
  const [logoPreview, setLogoPreview] = useState<string | null>(typeof row?.logo === 'string' && /^(https?:\/\/|\/)/.test(row.logo) ? row.logo : null);
  const onFile = (file: File | null) => {
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return toast('نوع الملف غير مدعوم', 'red');
    if (file.size > 4 * 1024 * 1024) return toast('الملف أكبر من 4MB', 'red');
    setLogoFile(file);
    setRemoveLogo(false);
  };
  const save = async () => {
    if (!f.v.ar.trim() || !f.v.en.trim()) return toast('الاسم العربي والإنجليزي مطلوبان', 'red');
    const body: any = {
      name_ar: f.v.ar, name_en: f.v.en, slug: (f.v.slug as string) || null,
      description_ar: (f.v.descAr as string) || null, description_en: (f.v.descEn as string) || null,
      sort_order: Number(f.v.sort || 0), is_active: !!f.v.active, logo: logoFile, remove_logo: removeLogo,
    };
    const r = await run(() => row ? brandsApi.update(row.id, body) : brandsApi.create(body), { toast, success: 'تم الحفظ', onFieldErrors: (e) => f.setErrors(mapErrors(e, { name_ar: 'ar', name_en: 'en', sort_order: 'sort' })) });
    if (r) { onSaved(); onClose(); }
  };
  return (
    <Modal open onClose={onClose} title={row ? 'تعديل براند' : 'براند جديد'} icon="star" width={620}
      footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={busy} onClick={save}>حفظ</button></>}>
      <div className="space-y-4">
        <div className="flex items-start gap-4">
          <div className="shrink-0 text-center">
            {logoPreview ? (
              <div className="relative">
                <img src={logoPreview ?? String(row?.logo)} alt="" className="w-[88px] h-[88px] rounded-[20px] object-cover" />
                <button className="btn btn-danger btn-xs mt-2 w-full" onClick={() => { setLogoFile(null); setLogoPreview(null); setRemoveLogo(true); }}>
                  <Icon name="trash" size={11} /> إزالة الشعار
                </button>
              </div>
            ) : (
              <label className="w-[88px] h-[88px] rounded-[20px] drop !p-0 flex flex-col items-center justify-center gap-1 cursor-pointer">
                <input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => onFile(e.target.files?.[0] ?? null)} />
                <Icon name="upload" size={18} className="tx3" />
                <span className="text-[10.5px] tx3">رفع شعار</span>
              </label>
            )}
            <div className="text-[10px] tx3 mt-1.5 leading-tight num">JPG · PNG · WebP<br />حتى 4MB</div>
          </div>
          <div className="flex-1 grid grid-cols-1 gap-3">
            <Field label="الاسم العربي" req hint="حتى 150 حرفًا" err={f.errors.ar}><input className="inp" maxLength={150} value={f.v.ar} onChange={f.on('ar')} /></Field>
            <Field label="الاسم الإنجليزي" req hint="حتى 150 حرفًا" err={f.errors.en}><input className="inp num" dir="ltr" maxLength={150} value={f.v.en} onChange={f.on('en' as any)} /></Field>
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="slug" hint="اختياري" err={f.errors.slug}><input className="inp num" dir="ltr" value={f.v.slug} onChange={f.on('slug' as any)} placeholder="auto-generated" /></Field>
          <Field label="ترتيب العرض" err={f.errors.sort}><input className="inp num" type="number" value={f.v.sort} onChange={f.on('sort' as any)} /></Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <TextArea label="الوصف العربي" value={f.v.descAr} onChange={(v) => f.set('descAr', v)} />
          <Field label="الوصف الإنجليزي"><textarea className="inp num" dir="ltr" value={f.v.descEn} onChange={f.on('descEn' as any)} /></Field>
        </div>
        <ToggleRow label="مفعّل" on={f.v.active} onChange={(v) => f.set('active', v)} />
      </div>
    </Modal>
  );
}

export function BrandsPage() {
  const toast = useToast();
  const { run } = useAction();
  const [page, setPage] = useState(1);
  const [form, setForm] = useState<BrandRow | { id?: any; logo?: any; ar?: string; en?: string; slug?: string; sort?: number; active?: boolean; products?: number } | null>(null);
  const [del, setDel] = useState<BrandRow | null>(null);
  const [blocked, setBlocked] = useState<BrandRow | null>(null);
  const [brandTotal, setBrandTotal] = useState(0);
  const list = useRemote((s) => brandsApi.list({ per_page: 20, page }, s).then((p) => { setBrandTotal(p.meta?.total ?? p.data.length); return p.data.map((b: any) => ({
    id: b.id, ar: b.name_ar, en: b.name_en, slug: b.slug, logo: b.logo_url, sort: b.sort_order, active: b.is_active, products: b.all_products_count ?? b.products_count ?? 0, descAr: b.description_ar ?? '', descEn: b.description_en ?? '',
  }) as BrandRow); }), [page], { mock: () => BRANDS });

  return (
    <div className="space-y-5">
      <PageHead
        title="البراندات"
        sub="العلامات التجارية المعروضة في المتجر وترتيب ظهورها."
        actions={
          <button className="btn btn-dark" onClick={() => list.usingMock ? toast('البيانات تجريبية — لا يمكن الحفظ', 'red') : setForm({})}>
            <Icon name="plus" size={14} />
            براند جديد
          </button>
        }
      />
      <RemoteFeedback state={list} />
      {blocked && (
        <Note tone="red" icon="ban" className="stg stg-2">
          لا يمكن حذف «{blocked.ar}» لأنه مرتبط بـ <Num>{blocked.products}</Num> منتجًا. انقل المنتجات لبراند آخر أو عطّل البراند بدلًا من حذفه.
        </Note>
      )}
      <Card stg={2}>
        <DataTable
          rows={list.data ?? []}
          foot={<Pager total={list.usingMock ? BRANDS.length : brandTotal} per={20} page={page} onPage={setPage} />}
          onRow={(row) => list.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setForm(row)}
          cols={[
            {
              h: 'الشعار',
              w: 64,
              r: (b: BrandRow) =>
                b.logo ? (
                  <span
                    className="w-10 h-10 rounded-[12px] flex items-center justify-center text-white font-display font-bold"
                    style={{ background: /^(https?:\/\/|\/)/.test(b.logo) ? `url("${b.logo}") center / cover` : b.logo }}
                  >
                    {!/^(https?:\/\/|\/)/.test(b.logo) && b.en[0]}
                  </span>
                ) : (
                  <span className="w-10 h-10 rounded-[12px] v-sub flex items-center justify-center tx3">
                    <Icon name="image" size={15} />
                  </span>
                ),
            },
            { h: 'الاسم العربي', r: (b: BrandRow) => <span className="font-semibold tx1">{b.ar}</span> },
            { h: 'الاسم الإنجليزي', r: (b: BrandRow) => <span className="num tx2 ltr">{b.en}</span> },
            {
              h: 'الرابط المختصر',
              r: (b: BrandRow) =>
                b.slug ? (
                  <span className="num text-[11.5px] v-sub px-2 py-1 rounded-md tx2 ltr">/{b.slug}</span>
                ) : (
                  <span className="tx3 text-[11.5px]">يُولّد تلقائيًا</span>
                ),
            },
            {
              h: 'الترتيب',
              align: 'center',
              r: (b: BrandRow) => <Num className="font-semibold">{b.sort}</Num>,
            },
            { h: 'التفعيل', r: (b: BrandRow) => <ActiveDot on={b.active} /> },
            {
              h: '',
              r: (b: BrandRow) => (
                <RowActions>
                  <button className="icon-btn !w-7 !h-7" onClick={() => list.usingMock ? toast('البيانات تجريبية — لا يمكن التعديل', 'red') : setForm(b)}>
                    <Icon name="edit" size={13} />
                  </button>
                  <button className="icon-btn !w-7 !h-7" onClick={() => list.usingMock ? toast('البيانات تجريبية — لا يمكن الحذف', 'red') : setDel(b)}>
                    <Icon name="trash" size={13} />
                  </button>
                </RowActions>
              ),
            },
          ]}
        />
      </Card>
      {form && (
        <BrandForm row={form.id ? (form as BrandRow) : null} onClose={() => setForm(null)} onSaved={() => list.reload()} />
      )}
      <ConfirmModal
        open={!!del}
        onClose={() => setDel(null)}
        danger
        title={'حذف «' + (del?.ar || '') + '»'}
        cta="حذف"
        text="سيُحذف البراند نهائيًا. سيرفض النظام الحذف إن كان مرتبطًا بمنتجات."
        onConfirm={async () => {
          if (!del) return false;
          const r = await run(async () => {
            try { return await brandsApi.remove(del.id); }
            catch (error) {
              const parsed = parseApiError(error);
              if (/منتج|product/i.test(parsed.message)) setBlocked(del);
              throw error;
            }
          }, { toast, success: 'تم الحذف' });
          if (r) { setDel(null); list.reload(); }
          return !!r;
        }}
      />
    </div>
  );
}

import { productsApi, categoriesApi, productMediaApi } from '@alfarid/api-client';
import { useApi } from '../catalog/useApi';
import { DealersPage, ProductionPage } from '../catalog/manufacturing-pages';
import { productFromApi, productToPayload, variantToPayload, BASIC_SERVER_KEY_TO_UI } from '../catalog/mappers';
import { persistProduct } from '../catalog/persist';
import { normalizeError } from '../catalog/errors';
import React, { useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } from 'react';
function Drawer({ open, onClose, title, subtitle, children, footer, width = 520 }) {
  useEffect(() => {
    if (!open) return;
    const h = (e) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', h); return () => window.removeEventListener('keydown', h);
  }, [open]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-[60] fade" style={{ background: 'var(--overlay)', backdropFilter: 'blur(2px)' }} onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="drawer absolute top-3 bottom-3 start-3 v-card flex flex-col overflow-hidden" style={{ width, maxWidth: 'calc(100vw - 24px)', boxShadow: 'var(--shadow-pop)', borderRadius: 26 }}>
        <div className="px-6 pt-6 pb-4 flex items-start justify-between gap-3 v-border-b">
          <div>
            <h3 className="font-display text-[19px] font-semibold t-1">{title}</h3>
            {subtitle && <p className="text-[12.5px] t-2 mt-0.5">{subtitle}</p>}
          </div>
          <button className="icon-btn" onClick={onClose}><Icon name="x" size={16} /></button>
        </div>
        <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>
        {footer && <div className="px-6 py-4 v-border-t flex items-center justify-end gap-2">{footer}</div>}
      </div>
    </div>
  );
}

function RowActions({ onEdit, onDelete, extra }) {
  return (
    <div className="flex items-center justify-end gap-0.5" onClick={(e) => e.stopPropagation()}>
      {extra}
      {onEdit && <button className="icon-btn" title="تعديل" onClick={onEdit}><Icon name="edit" size={15} /></button>}
      {onDelete && <button className="icon-btn hover:!text-[var(--badge-red-text)]" title="حذف" onClick={onDelete}><Icon name="trash" size={15} /></button>}
    </div>
  );
}

/* ====================== PRODUCTS LIST ====================== */
function ProductsPage() {
  const [deleting,setDeleting]=useState(null);
  const { user, categories: CATEGORIES } = useApp();
  const [q, setQ] = useQueryState({ page: 1, per_page: 10, search: '', category: '', sub: '1', status: '', channel: '', featured: '', sort: 'newest' });
  const params={search:q.search||undefined,category_id:q.category||undefined,include_children:q.sub==='1'?1:0,status:q.status||undefined,sales_channel:q.channel||undefined,is_featured:q.featured?1:undefined,sort:q.sort==='newest'?'latest':q.sort,page:+q.page,per_page:+q.per_page};
  const query=useApi(sig=>productsApi.list(params,sig),[JSON.stringify(params)]);
  const stats=useApi(sig=>Promise.all(['','published','draft','archived'].map(status=>productsApi.list({per_page:1,...(status?{status}: {})},sig))),[]);
  const phase=query.loading&&!query.data?'loading':query.error?'error':'ready', retry=query.reload;
  if (!user.can('products.view')) return <Forbidden perm="products.view" />;
  const flat = flattenCats(CATEGORIES);
  const catNode = q.category && flat.find((f) => String(f.id) === String(q.category));
  const rows=(query.data?.data||[]).map(p=>({id:p.id,ar:p.name_ar,en:p.name_en,code:p.code,cat:flat.find(c=>c.id===p.category?.id)?.path.join(' › ') || p.category?.name_ar,brand:p.brand?.name_ar,status:p.status,channel:p.sales_channel,variants:p.variants_count,min:p.min_retail_price==null?null:Number(p.min_retail_price),max:p.max_retail_price==null?null:Number(p.max_retail_price),featured:p.is_featured,img:p.main_image ? 'url("'+p.main_image+'") center / cover' : 'var(--bg-subcard)'}));
  const res={data:rows,meta:query.data?.meta || {current_page:1,last_page:1,total:0,per_page:10,from:null,to:null}};
  const activeFilters = ['category', 'status', 'channel', 'featured'].filter((k) => q[k]).length + (q.search ? 1 : 0);
  const counts = Object.fromEntries(['all','published','draft','archived'].map((k,i)=>[k,stats.data?.[i]?.meta.total||0]));
  return (
    <div className="space-y-5">
      <PageHeader crumbs={[{ label: 'الكتالوج' }, { label: 'المنتجات' }]} title="المنتجات" subtitle="المنتج بيتعمل مسودة الأول، بعدها الصور، وبعدها النشر."
        actions={user.can('products.create') && <button className="btn btn-ink" onClick={() => nav('/products/new')}><Icon name="plus" size={15} />منتج جديد</button>} />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stagger s2">
        {[['', 'كل المنتجات', counts.all, 'box'], ['published', 'منشور', counts.published, 'globe'], ['draft', 'مسودة', counts.draft, 'edit'], ['archived', 'مؤرشف', counts.archived, 'folder']].map(([k, l, n, ic], i) => {
          const on = q.status === k;
          return (
            <button key={k} onClick={() => setQ({ status: k, page: 1 })} className={cx('rounded-[22px] p-5 text-start transition', on ? 'v-orange shadow-sm' : 'v-card hover:shadow-sm')}>
              <div className={cx('flex items-center justify-between text-[12.5px] font-medium', on ? 'text-white/90' : 't-2')}>{l}<span className={cx('w-7 h-7 rounded-full flex items-center justify-center', on ? 'bg-white/15' : 'v-well')}><Icon name={ic} size={14} /></span></div>
              <div className={cx('font-display text-[32px] font-bold mt-3 num', !on && 't-1')}>{n}</div>
              <div className={cx('mt-2 h-1.5 rounded-full overflow-hidden', on ? 'bg-white/20' : 'hatched-track')}><div className={cx('h-full rounded-full', on ? 'bg-white' : 'hatched-acc')} style={{ width: `${(counts.all ? n / counts.all : 0) * 100}%` }} /></div>
            </button>
          );
        })}
      </div>

      <ConfirmDialog open={!!deleting} onClose={()=>setDeleting(null)} title="حذف المنتج؟" message="سيتم حذف المنتج من الكتالوج." danger confirmLabel="حذف" onConfirm={async()=>{await productsApi.remove(deleting.id);query.reload();stats.reload();}}/>
      <div className="stagger s3">
        <DataTable error={query.error} state={phase} onRetry={retry} rows={res.data} meta={res.meta} filtered={activeFilters > 0}
          onPage={(p) => setQ({ page: p })} onPerPage={(n) => setQ({ per_page: n, page: 1 })} onRowClick={(r) => nav('/products/' + r.id)}
          empty={activeFilters > 0 ? <EmptyState icon="search" title="لا منتجات مطابقة" body="البحث بيشمل الاسم والكود وSKU والباركود. جرّب كلمات أقل أو امسح الفلاتر." action={<button className="btn btn-soft" onClick={() => setQ({ search: '', category: '', status: '', channel: '', featured: '', page: 1 })}>مسح كل الفلاتر</button>} />
            : <EmptyState icon="box" title="لا توجد منتجات بعد" body="ابدأ بأول منتج كمسودة — تقدر تضيف الصور وتنشره بعدين." action={user.can('products.create') && <button className="btn btn-ink" onClick={() => nav('/products/new')}><Icon name="plus" size={15} />منتج جديد</button>} />}
          toolbar={
            <div className="space-y-3">
              <div className="flex flex-wrap items-center gap-2">
                <SearchBox value={q.search} onChange={(v) => setQ({ search: v, page: 1 })} placeholder="الاسم، الكود، SKU أو الباركود…" width={320} />
                <div className="w-[230px]"><SearchSelect value={q.category ? +q.category : null} onChange={(v) => setQ({ category: v, page: 1 })} placeholder="كل الأقسام" options={flat.map((f) => ({ value: f.id, label: f.ar, depth: f.depth }))} /></div>
                {q.category && <label className="h-10 px-3.5 rounded-full v-subcard !rounded-full flex items-center gap-2 text-[12px] font-medium t-1 cursor-pointer" onClick={() => setQ({ sub: q.sub === '1' ? '0' : '1', page: 1 })}><Check on={q.sub === '1'} />تضمين الأقسام الفرعية</label>}
                <FilterMenu label="القناة" value={q.channel || null} onChange={(v) => setQ({ channel: v, page: 1 })} options={[{ value: 'retail', label: 'تجزئة' }, { value: 'wholesale', label: 'جملة' }, { value: 'both', label: 'تجزئة + جملة' }]} />
                <button onClick={() => setQ({ featured: q.featured ? '' : '1', page: 1 })} className={cx('h-10 px-3.5 rounded-full inline-flex items-center gap-2 text-[12.5px] font-medium border transition', q.featured ? 'bg-ink border-transparent' : 'btn-outline')}><Icon name="star" size={14} />مميز</button>
                <div className="ms-auto flex items-center gap-2">
                  <span className="text-[12px] t-3">ترتيب</span>
                  <Seg value={q.sort} onChange={(v) => setQ({ sort: v, page: 1 })} options={[{ value: 'newest', label: 'الأحدث' }, { value: 'oldest', label: 'الأقدم' }, { value: 'name', label: 'الاسم (EN)' }]} />
                </div>
              </div>
              {activeFilters > 0 && (
                <div className="flex items-center gap-2 text-[12px] pop">
                  <span className="t-3"><span className="num font-semibold t-1">{res.meta?.total}</span> نتيجة ·</span>
                  <button className="font-semibold t-acc" onClick={() => setQ({ search: '', category: '', status: '', channel: '', featured: '', page: 1 })}>مسح الفلاتر ({activeFilters})</button>
                  <span className="t-3 ms-auto hidden md:flex items-center gap-1"><Icon name="link" size={12} />الفلاتر محفوظة في الرابط</span>
                </div>
              )}
            </div>
          }
          columns={[
            { key: 'p', label: 'المنتج', render: (r) => (
              <div className="flex items-center gap-3 min-w-[240px]">
                <span className="w-12 h-12 rounded-[14px] shrink-0 relative" style={{ background: r.img }}>{r.featured && <span className="absolute -top-1 -end-1 w-5 h-5 rounded-full bg-acc flex items-center justify-center" style={{ boxShadow: '0 0 0 2px var(--bg-card)' }}><Icon name="star" size={10} sw={2.5} /></span>}</span>
                <div className="min-w-0"><div className="font-semibold t-1 truncate">{r.ar}</div><div className="text-[11px] t-3 ltr inline-block">{r.code}</div></div>
              </div>), skel: () => <div className="flex items-center gap-3"><Skel w={48} h={48} r={14} /><div className="space-y-1.5 flex-1"><Skel w="70%" h={10} /><Skel w="40%" h={8} /></div></div> },
            { key: 'cat', label: 'القسم', render: (r) => <div><div className="t-1 text-[12.5px]">{r.cat}</div></div> },
            { key: 'status', label: 'الحالة', render: (r) => <StatusBadge s={r.status} /> },
            { key: 'channel', label: 'القناة', render: (r) => <span className="text-[12px] t-2 whitespace-nowrap">{CHANNEL_LABEL[r.channel]}</span> },
            { key: 'variants', label: 'المتغيرات', align: 'center', render: (r) => <span className="inline-flex w-7 h-7 rounded-full v-well items-center justify-center text-[11.5px] font-bold num t-1">{r.variants}</span> },
            { key: 'price', label: 'سعر التجزئة', render: (r) => r.channel === 'wholesale' ? <span className="t-3 text-[12px]">جملة فقط</span> : r.min == null ? <span className="text-[11.5px] font-medium" style={{ color: 'var(--badge-amber-text)' }}>غير مكتمل</span> : <span className="num font-semibold t-1 whitespace-nowrap">{r.min === r.max ? fmtMoney(r.min) : `${fmtMoney(r.min)} – ${fmtMoney(r.max)}`} <span className="text-[10.5px] t-3 font-medium">ج.م</span></span> },
            { key: 'a', label: '', render: (r) => <RowActions onEdit={() => nav('/products/' + r.id)} onDelete={user.can('products.delete') ? ()=>setDeleting(r) : undefined} /> },
          ]} />
      </div>
    </div>
  );
}

const IMG = [
  'linear-gradient(135deg,#f6d5c8 0%,#e7825f 100%)',
  'linear-gradient(135deg,#e9e4ef 0%,#a99cb8 100%)',
  'linear-gradient(135deg,#ece6dc 0%,#c2ae8c 100%)',
  'linear-gradient(135deg,#dfe8e3 0%,#89a698 100%)',
  'linear-gradient(135deg,#f3e3d3 0%,#d49c6b 100%)',
  'linear-gradient(135deg,#e4e7ee 0%,#8b95ab 100%)',
  'linear-gradient(135deg,#2a2530 0%,#5c5363 100%)',
  'linear-gradient(135deg,#f7ece6 0%,#efc0aa 100%)',
];

const CHANNEL_LABEL = { retail: 'تجزئة', wholesale: 'جملة', both: 'تجزئة + جملة' };

function flattenCats(nodes, depth = 0, path = []) {
  return nodes.flatMap((n) => [{ ...n, depth, path: [...path, n.ar] }, ...flattenCats(n.children || [], depth + 1, [...path, n.ar])]);
}

const ICON_PATHS = {
  grid: <><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></>,
  box: <><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/></>,
  store: <><path d="M4 9l1.5-5h13L20 9"/><path d="M4 9v11h16V9"/><path d="M4 9c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3"/><path d="M10 20v-5h4v5"/></>,
  users: <><circle cx="9" cy="8" r="3.5"/><path d="M3 19c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.5"/><path d="M16 13.5c2.5.4 4.5 2.5 4.5 5.5"/></>,
  shield: <><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></>,
  gear: <><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></>,
  layers: <><path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/></>,
  tag: <><path d="M3 12V4a1 1 0 011-1h8l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.5"/></>,
  tree: <><rect x="3" y="3" width="6" height="5" rx="1.2"/><rect x="15" y="10" width="6" height="5" rx="1.2"/><rect x="15" y="17" width="6" height="4" rx="1.2"/><path d="M6 8v11h9M6 12.5h9"/></>,
  component: <><rect x="3" y="3" width="8" height="8" rx="2"/><circle cx="17" cy="7" r="4"/><path d="M7 14l4 7H3l4-7z"/><rect x="13" y="13" width="8" height="8" rx="2"/></>,
  search: <><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></>,
  bell: <path d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 10-2 0v1.1A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>,
  help: <><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3.5m0 3h.01"/></>,
  sun: <><circle cx="12" cy="12" r="4"/><path d="M12 3v2m0 14v2M3 12h2m14 0h2m-2.6-6.4l-1.4 1.4M7 17l-1.4 1.4m0-12.8L7 7m10 10l1.4 1.4"/></>,
  moon: <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>,
  logout: <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4m7 14l5-5-5-5m5 5H9"/>,
  info: <><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M12 11v5"/></>,
  plus: <path d="M12 5v14M5 12h14"/>,
  minus: <path d="M5 12h14"/>,
  x: <path d="M6 6l12 12M18 6L6 18"/>,
  check: <path d="M5 12.5l4.5 4.5L19 7.5"/>,
  chevDown: <path d="M19 9l-7 7-7-7"/>,
  chevLeft: <path d="M15 18l-6-6 6-6"/>,
  chevRight: <path d="M9 18l6-6-6-6"/>,
  chevUp: <path d="M5 15l7-7 7 7"/>,
  edit: <><path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/></>,
  trash: <><path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/></>,
  dots: <><circle cx="12" cy="5" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="12" cy="19" r="1.3"/></>,
  drag: <><circle cx="9" cy="6" r="1.2"/><circle cx="15" cy="6" r="1.2"/><circle cx="9" cy="12" r="1.2"/><circle cx="15" cy="12" r="1.2"/><circle cx="9" cy="18" r="1.2"/><circle cx="15" cy="18" r="1.2"/></>,
  eye: <><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></>,
  eyeOff: <><path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0112 5c6.5 0 10 7 10 7a17 17 0 01-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7c1.6 0 3-.4 4.3-1"/><path d="M9.9 9.9a3 3 0 004.2 4.2"/></>,
  mail: <><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 7l9 6 9-6"/></>,
  lock: <><rect x="5" y="11" width="14" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 018 0v3"/></>,
  upload: <><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3"/></>,
  image: <><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></>,
  video: <><rect x="3" y="6" width="13" height="12" rx="2.5"/><path d="M16 10l5-3v10l-5-3"/></>,
  refresh: <><path d="M20 11a8 8 0 00-14.9-3M4 4v4h4"/><path d="M4 13a8 8 0 0014.9 3M20 20v-4h-4"/></>,
  alert: <><path d="M12 3l9.5 17h-19L12 3z"/><path d="M12 10v4m0 3h.01"/></>,
  alertCircle: <><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5m0 3h.01"/></>,
  checkCircle: <><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 10"/></>,
  filter: <path d="M4 5h16l-6 8v6l-4-2v-4L4 5z"/>,
  sort: <path d="M7 4v16M3 8l4-4 4 4M17 20V4M13 16l4 4 4-4"/>,
  copyDown: <><path d="M12 4v12M7 11l5 5 5-5"/><path d="M5 20h14"/></>,
  copy: <><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 00-2-2H6a2 2 0 00-2 2v8a2 2 0 002 2h2"/></>,
  wand: <><path d="M4 20L15 9M13 7l4 4"/><path d="M18 3v3M16.5 4.5h3M20 9v2M19 10h2M10 3v2M9 4h2"/></>,
  palette: <><path d="M12 3a9 9 0 100 18c1 0 1.5-.7 1.5-1.5 0-1.2-1-1.5-1-2.5s.8-1.5 2-1.5H17a4 4 0 004-4c0-4.7-4-8.5-9-8.5z"/><circle cx="7.5" cy="11" r="1.2"/><circle cx="10" cy="7" r="1.2"/><circle cx="15" cy="7" r="1.2"/></>,
  ruler: <><rect x="2" y="8" width="20" height="8" rx="1.5"/><path d="M6 8v3M10 8v4M14 8v3M18 8v4"/></>,
  star: <path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8L3.5 9.7l5.9-.8L12 3.5z"/>,
  building: <><rect x="4" y="3" width="11" height="18" rx="1.5"/><path d="M15 9h5v12h-5M8 7h3M8 11h3M8 15h3"/></>,
  warehouse: <><path d="M3 9l9-5 9 5v11H3V9z"/><path d="M7 20v-7h10v7M7 16h10"/></>,
  pin: <><path d="M12 21s-7-6.2-7-12a7 7 0 0114 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></>,
  phone: <path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/>,
  hash: <path d="M5 9h14M5 15h14M10 3L8 21M16 3l-2 18"/>,
  percent: <><path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/></>,
  receipt: <><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2V3z"/><path d="M9 8h6M9 12h6M9 16h3"/></>,
  rotate: <><path d="M3 12a9 9 0 109-9"/><path d="M3 4v5h5"/></>,
  cart: <><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M3 3h2.5l2.2 12h11l2-8H6.5"/></>,
  link: <><path d="M10 14a4.5 4.5 0 006.4 0l3-3a4.5 4.5 0 00-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 00-6.4 0l-3 3a4.5 4.5 0 006.4 6.4l1-1"/></>,
  globe: <><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z"/></>,
  clock: <><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></>,
  send: <path d="M21 3L10 14M21 3l-7 18-4-7-7-4 18-7z"/>,
  barcode: <path d="M4 5v14M7 5v14M10 5v14M14 5v14M16 5v14M20 5v14"/>,
  keyboard: <><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/></>,
  folder: <path d="M3 6a2 2 0 012-2h4l2 2h8a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V6z"/>,
  folderOpen: <path d="M3 7a2 2 0 012-2h4l2 2h7a2 2 0 012 2v1H6.5L3 18V7zM3 18l3.5-8H22l-3.5 8H3z"/>,
  arrowLeft: <path d="M19 12H5M11 18l-6-6 6-6"/>,
  arrowRight: <path d="M5 12h14M13 6l6 6-6 6"/>,
  ban: <><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></>,
  wifiOff: <><path d="M3 3l18 18M8.5 16.5a5 5 0 017 0M5 12.5a10 10 0 0110-2.8M2 9a15 15 0 015.5-3.2M19 12.5a10 10 0 00-2-1.6M22 9a15 15 0 00-7-3.8"/><path d="M12 20h.01"/></>,
  sparkle: <path d="M12 3l1.8 5.4L19 10l-5.2 1.6L12 17l-1.8-5.4L5 10l5.2-1.6L12 3zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8L19 16z"/>,
};

function Icon({ name, size = 16, className = '', style, sw = 1.9 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round" className={'shrink-0 ' + className} style={style} aria-hidden="true">
      {ICON_PATHS[name] || ICON_PATHS.info}
    </svg>
  );
}

function blankProduct() {
  return {
    status: null, code: null,
    basic: { ar: '', en: '', category: null, brand: null, channel: 'retail', slug: '', desc_ar: '', desc_en: '', mat_ar: '', mat_en: '', l: '', w: '', h: '', kg: '', warranty: '', warranty_details: '', origin: '', featured: false, supplier: null },
    mode: 'single', axes: { colors: [], sizes: [], capacities: [] }, rows: [emptyVariant(null, '', '', 0)],
    media: { general: [], byVariant: {}, video: null },
  };
}

function ProductForm({ id, initial }) {
  const { user, categories: CATEGORIES } = useApp();
  const isNew = id === 'new';
  const [p, setP] = useState(() => initial || blankProduct());
  const [saved, setSaved] = useState(JSON.stringify(p));
  const [tab, setTabQ] = useQueryState({ tab: 'basic' });
  const [errs, setErrs] = useState({ basic: {}, variants: {} });
  const [summary, setSummary] = useState(null);
  const [flow, setFlow] = useState(null); // {steps:[{k,label,state}], done}
  const [blockModal, setBlockModal] = useState(null);
  const [leave, setLeave] = useState(false);
  const canCost = user.can('products.view_cost');
  const canPublish = user.can('products.publish');
  const writable = isNew ? user.can('products.create') : user.can('products.update');
  const dirty = JSON.stringify(p) !== saved;
  useUnsavedGuard(dirty);
  const setBasic = (k, val) => setP({ ...p, basic: { ...p.basic, [k]: val } });
  const B = p.basic;
  const flat = flattenCats(CATEGORIES);
  const catNode = flat.find((f) => f.id === B.category);
  const vErrs = validateVariants(p.rows, B.channel, p.mode);

  // Readiness
  const catChain=[];let ancestor=catNode;const seenAncestors=new Set();while(ancestor&&!seenAncestors.has(ancestor.id)){seenAncestors.add(ancestor.id);catChain.push(ancestor);ancestor=flat.find(f=>f.id===ancestor.parent_id);}
  const inactiveAncestor = catChain.find((c) => c && c.status !== 'active');
  const activeRows = p.rows.filter((r) => r.status === 'active');
  const needRetail = B.channel !== 'wholesale', needWh = B.channel !== 'retail';
  const missingPrice = activeRows.filter((r) => (needRetail && !(Number(r.retail)>0)) || (needWh && !(Number(r.wholesale)>0)));
  const imgCount = p.media.general.length;
  const ready = [
    { k: 'cat', ok: !!catNode && !inactiveAncestor, label: 'القسم وكل الأقسام الأب نشطين', detail: !catNode ? 'لم يتم اختيار قسم' : inactiveAncestor ? `القسم «${inactiveAncestor.ar}» غير نشط` : catNode.path.join(' › '), tab: 'basic' },
    { k: 'var', ok: activeRows.length > 0, label: 'متغير نشط واحد على الأقل', detail: `${activeRows.length} متغير نشط من ${p.rows.length}`, tab: 'variants' },
    { k: 'price', ok: activeRows.length > 0 && missingPrice.length === 0, label: 'أسعار المتغيرات النشطة مكتملة حسب قناة البيع', detail: missingPrice.length ? `${missingPrice.length} متغير ناقصه ${needRetail && needWh ? 'سعر تجزئة أو جملة' : needRetail ? 'سعر التجزئة' : 'سعر الجملة'}` : `القناة: ${CHANNEL_LABEL[B.channel]}`, tab: 'variants' },
    { k: 'img', ok: imgCount > 0, label: 'صورة واحدة على الأقل', detail: `${imgCount} صورة`, tab: 'media' },
    { k: 'perm', ok: canPublish, label: 'صلاحية نشر المنتجات', detail: canPublish ? 'متاحة لدورك' : 'دورك لا يحتوي products.publish', tab: null },
  ];
  const readyCount = ready.filter((r) => r.ok).length;

  const tabState = (t) => {
    if (t === 'basic') return Object.keys(errs.basic).length ? 'error' : (B.ar && B.en && B.category ? 'done' : null);
    if (t === 'variants') return Object.keys(vErrs).length && (errs.variantsShown) ? 'error' : (activeRows.length && !missingPrice.length ? 'done' : null);
    if (t === 'media') return imgCount ? 'done' : null;
    if (t === 'review') return readyCount === ready.length ? 'done' : null;
  };

  const validateBasic = () => {
    const e = {};
    if((B.ar||'').length>255)e.ar='الحد الأقصى 255 حرف';if((B.en||'').length>255)e.en='الحد الأقصى 255 حرف';
    if(B.warranty!=='' && (!Number.isInteger(Number(B.warranty)) || +B.warranty<0 || +B.warranty>120))e.warranty='الضمان من 0 إلى 120 شهراً';
    for(const k of ['l','w','h','kg'])if(B[k]!=='' && (!Number.isFinite(Number(B[k])) || +B[k]<0))e[k]='القيمة يجب أن تكون رقماً موجباً';
    if (!B.ar?.trim()) e.ar = 'الاسم العربي مطلوب';
    if (!B.en?.trim()) e.en = 'الاسم الإنجليزي مطلوب';
    if (!B.category) e.category = 'اختر القسم';
    if (!B.channel) e.channel = 'اختر قناة البيع';
    return e;
  };

    const run = async (publish) => {
    const be = validateBasic();
    const ve = validateVariants(p.rows, B.channel, p.mode, publish);
    // Drafts tolerate missing prices; only structural errors block
    const structural = ve;
    if(p.rows.length>100){vtoast('الحد الأقصى 100 متغير','error');return;}
    setErrs({ basic: be, variants: structural, variantsShown: true });
    if (Object.keys(be).length || Object.keys(structural).length) {
      const items = [
        ...Object.values(be).map((t) => ({ text: t, tab: 'البيانات الأساسية', go: 'basic' })),
        ...Object.entries(structural).flatMap(([rid, e]) => { const i = p.rows.findIndex((r) => r._id === rid); return Object.entries(e).map(([f, t]) => ({ text: `variants.${i}.${f === 'dup' ? 'combination' : f === 'retail' ? 'retail_price' : f === 'wholesale' ? 'wholesale_price' : f} — ${t}`, tab: 'المتغيرات', go: 'variants' })); }),
      ];
      setSummary({ title: 'تعذّر الحفظ — راجع الحقول التالية', subtitle: 'كل خطأ مربوط بالحقل بتاعه في التبويب.', items });
      setTabQ({ tab: items[0].go });
      return;
    }
    setSummary(null);
    const steps = [
      { k: 'save', label: p.status ? 'تحديث المسودة والمتغيرات' : 'حفظ المسودة والمتغيرات', state: 'run' },
      { k: 'upload', label: `رفع الصور (${p.media.general.filter((f) => f.status !== 'done').length + Object.values(p.media.byVariant).flat().filter((f) => f.status !== 'done').length + (p.media.video && p.media.video.status !== 'done' ? 1 : 0)} ملف)`, state: 'wait' },
      ...(publish ? [{ k: 'publish', label: 'تحديث الحالة إلى منشور', state: 'wait' }] : []),
    ];
    setFlow({ steps: [...steps], publish });
    const mark = (k, state, extra) => setFlow((f) => ({ ...f, steps: f.steps.map((s) => s.k === k ? { ...s, state, ...extra } : s) }));
    let saveCompleted=false;
    try {
      let next = await persistProduct(p, JSON.parse(saved), canCost, value => {setP({...value});},()=>{saveCompleted=true;mark('save','done');mark('upload','run');});
      mark('save','done');mark('upload','done');
      if(publish){mark('publish','run'); const published=await productsApi.update(next.id,{status:'published'});next={...next,status:published.status,publishedAt:published.published_at};mark('publish','done'); }
      setP(next);setSaved(JSON.stringify(next));setFlow(f=>({...f,done:'ok',code:next.code}));
    } catch(e) {
      const error=normalizeError(e);if(!saveCompleted)setFlow(null);else setFlow(f=>({...f,done:error.summary.length?'blocked':'upload_fail',reasons:error.summary.length?error.summary:[error.message],steps:f.steps.map(step=>({...step,state:step.state==='run'?'fail':step.state==='wait'?'skip':step.state}))}));
      if(e.catalogProductId){try{const [product,media]=await Promise.all([productsApi.show(e.catalogProductId),productMediaApi.list(e.catalogProductId)]);const fresh={...productFromApi(product,media,{canViewCost:canCost}),id:product.id};setP(fresh);setSaved(JSON.stringify(fresh));}catch{vtoast('تعذّر تحديث البيانات بعد الحفظ الجزئي. أعد تحميل المنتج قبل التعديل.','error');}}
      const basic={},variants={}; const map=BASIC_SERVER_KEY_TO_UI;
      if(e.catalogRowKey)variants[e.catalogRowKey]=Object.fromEntries(Object.entries(error.fields).map(([key,messages])=>[{retail_price:'retail',wholesale_price:'wholesale',cost_price:'cost',compare_at_price:'compare',color_name_en:'color_en'}[key]||key,messages[0]]));
      for(const [key,messages] of Object.entries(error.fields)){const match=key.match(/^variants\.(\d+)\.(.+)$/);if(match){const row=p.rows[+match[1]];if(row){const k={retail_price:'retail',wholesale_price:'wholesale',cost_price:'cost',compare_at_price:'compare',color_name_en:'color_en'}[match[2]]||match[2];variants[row._id]={...(variants[row._id]||{}),[k]:messages[0]};}}else if(!e.catalogRowKey)basic[map[key]||key]=messages[0];}
      setErrs({basic,variants,variantsShown:true});setTabQ({tab:e.catalogTab || (Object.keys(variants).length?'variants':'basic')});
      setSummary({title:error.message || e.message,items:[...error.summary,...Object.values(error.fields).flat(),...(error.summary.length||Object.keys(error.fields).length?[]:[error.message])].map(text=>({text,go:e.catalogTab || (e.catalogRowKey?'variants':'basic'),tab:e.catalogTab==='media'?'الصور والفيديو':e.catalogRowKey?'المتغيرات':'البيانات الأساسية'}))});
    }
  };

  const onDeactivateLast = () => {
    if (p.status === 'published' && activeRows.length <= 1) { setBlockModal('last'); return true; }
    return false;
  };

  const tabs = [
    { value: 'basic', label: 'البيانات الأساسية', num: 1, state: tabState('basic') },
    { value: 'variants', label: 'المتغيرات والأسعار', num: 2, state: tabState('variants') },
    { value: 'media', label: 'الصور والفيديو', num: 3, state: tabState('media') },
    { value: 'review', label: 'المراجعة والنشر', num: 4, state: tabState('review') },
  ];

  return (
    <div className="space-y-5">
      {p.status === 'published' && <Banner tone="amber">المنتج منشور: أي تعديل لازم يحافظ على شروط النشر</Banner>}
      <PageHeader crumbs={[{ label: 'الكتالوج' }, { label: 'المنتجات', href: '#/products' }, { label: isNew && !p.code ? 'منتج جديد' : (B.ar || p.code) }]}
        title={isNew && !p.code ? 'منتج جديد' : B.ar || 'بدون اسم'}
        badge={<>{p.status ? <StatusBadge s={p.status} /> : <Badge>لم يُحفظ بعد</Badge>}{p.code && <span className="ltr text-[12px] font-semibold px-2.5 py-1 rounded-full v-pill t-2">{p.code}</span>}</>}
        actions={<button className="btn btn-soft" onClick={() => dirty ? setLeave(true) : nav('/products')}><Icon name="arrowRight" size={14} />المنتجات</button>} />

      <div className="flex flex-wrap items-center justify-between gap-3 stagger s2">
        <Tabs value={tab.tab} onChange={(t) => setTabQ({ tab: t })} tabs={tabs} />
        <div className="flex items-center gap-3 v-pill rounded-full ps-4 pe-1.5 h-11">
          <span className="text-[12px] t-2">الجاهزية للنشر</span>
          <div className="w-24 h-2 rounded-full hatched-track overflow-hidden"><div className="h-full rounded-full hatched-acc transition-all" style={{ width: `${(readyCount / ready.length) * 100}%` }} /></div>
          <span className={cx('h-8 px-3 rounded-full text-[12px] font-bold num flex items-center', readyCount === ready.length ? 'b-green' : 'v-well t-1')}>{readyCount}/{ready.length}</span>
        </div>
      </div>

      {summary && <ErrorSummary title={summary.title} subtitle={summary.subtitle} items={summary.items} onClose={() => setSummary(null)} onJump={(it) => setTabQ({ tab: it.go })} />}

      <fieldset disabled={!writable || (!!flow && !flow.done)} className="stagger s3">
        {tab.tab === 'basic' && <BasicTab B={B} set={setBasic} errs={errs.basic} code={p.code} flat={flat} />}
        {tab.tab === 'variants' && (
          <VariantsTab channel={B.channel} canCost={canCost} mode={p.mode}
            setMode={(m) => setP({ ...p, mode: m, rows: m === 'single' ? [p.rows[0] || emptyVariant(null, '', '', 0)].map((r) => ({ ...r, color: null, size: '', capacity: '' })) : p.rows })}
            axes={p.axes} setAxes={(a) => setP({ ...p, axes: a })} rows={p.rows}
            setRows={(rows) => {
              const nowActive = rows.filter((r) => r.status === 'active').length;
              if (p.status === 'published' && nowActive === 0) { setBlockModal('last'); return; }
              const removedPrice = p.status === 'published' && rows.some((r) => r.status === 'active' && ((needRetail && !r.retail) || (needWh && !r.wholesale))) && missingPrice.length === 0;
              if (removedPrice) { setBlockModal('price'); return; }
              const ids=new Set(rows.map(r=>r.serverId));const byVariant=Object.fromEntries(Object.entries(p.media.byVariant).map(([color,items])=>[color,items.filter(file=>!file.variantId||ids.has(file.variantId))]));
              setP({ ...p, rows,media:{...p.media,byVariant} });
            }}
            errs={errs.variantsShown ? {...vErrs,...errs.variants} : {}} />
        )}
        {tab.tab === 'media' && <MediaTab media={p.media} setMedia={(m) => setP({ ...p, media: m })} axes={p.axes} mode={p.mode} saved={!!p.status} onRetry={()=>run(false)} />}
        {tab.tab === 'review' && <ReviewTab p={p} ready={ready} canCost={canCost} canPublish={canPublish} goto={(t) => setTabQ({ tab: t })} catNode={catNode} />}
      </fieldset>

      {writable && <StickyBar dirty={dirty} note={p.status ? null : 'الصور هتترفع بعد حفظ المسودة'}>
        {tab.tab !== 'review' && <button className="btn btn-ghost" onClick={() => setTabQ({ tab: tabs[Math.min(3, tabs.findIndex((t) => t.value === tab.tab) + 1)].value })}>التالي<Icon name="chevLeft" size={14} /></button>}
        <button className="btn btn-outline" onClick={() => run(false)} disabled={!writable || (!!flow && !flow.done)}><Icon name="edit" size={14} />{p.status === 'published'||p.status==='archived' ? 'حفظ التعديلات' : 'حفظ كمسودة'}</button>
        {canPublish && p.id && (p.status==='published'?[['draft','إلغاء النشر'],['archived','أرشفة']]:p.status==='archived'?[['draft','استرجاع كمسودة']]:[]).map(([status,label])=><button key={status} disabled={!!flow&&!flow.done} className="btn btn-soft" onClick={async()=>{if(dirty&&!window.confirm('تغيير الحالة سيحفظ حالة المنتج فقط. ستبقى تعديلاتك غير المحفوظة؛ متابعة؟'))return;try{await productsApi.update(p.id,{status});setP({...p,status});setSaved(JSON.stringify({...JSON.parse(saved),status}));}catch(e){vtoast(normalizeError(e).message,'error');}}}>{label}</button>)}
        {canPublish ? (
          p.status !== 'published' && writable && <button className="btn btn-acc" onClick={() => {
            if (readyCount < ready.length) { setSummary({ title: 'المنتج غير جاهز للنشر', subtitle: 'أكمل البنود التالية، أو احفظه كمسودة لحين الانتهاء.', items: ready.filter((r) => !r.ok && r.tab).map((r) => ({ text: `${r.label} — ${r.detail}`, tab: tabs.find((t) => t.value === r.tab).label, go: r.tab })) }); return; }
            run(true);
          }} disabled={!!flow && !flow.done}><Icon name="send" size={14} />{p.status==='archived'?'إعادة النشر':'حفظ ونشر'}</button>
        ) : <span className="h-10 px-4 rounded-full v-well text-[12px] font-medium flex items-center gap-1.5" title="يتطلب products.publish"><Icon name="lock" size={13} />النشر يتطلب صلاحية</span>}
      </StickyBar>}

      <PublishFlow flow={flow} onClose={() => setFlow(null)} onRetryUpload={() => { setP({ ...p, media: { ...p.media, general: p.media.general.map((f) => f.status === 'failed' ? { ...f, status: 'queued', progress: 0, error: null } : f) } }); setFlow(null); setTabQ({ tab: 'media' }); }}
        onGoto={(t) => { setFlow(null); setTabQ({ tab: t }); }} />
      <Modal open={!!blockModal} onClose={() => setBlockModal(null)} icon="ban" iconTone="red"
        title={blockModal === 'last' ? 'لا يمكن تعطيل آخر متغير نشط' : 'لا يمكن إزالة سعر مطلوب'}
        subtitle={blockModal === 'last' ? 'المنتج منشور حاليًا، والمنتج المنشور لازم يكون فيه متغير نشط واحد على الأقل.' : `المنتج منشور على قناة «${CHANNEL_LABEL[B.channel]}»، والسعر ده مطلوب لكل متغير نشط. الخادم هيرفض الحفظ.`}>
        <div className="v-subcard p-4 text-[12.5px] t-2 leading-relaxed">
          {canPublish ? 'لو محتاج توقف بيع المنتج مؤقتًا، حوّله لمسودة الأول — هيختفي من المتجر ونقاط البيع، وبعدها تقدر تعدل بحرية.' : 'تحويل المنتج لمسودة يتطلب صلاحية النشر. تواصل مع مسؤول الكتالوج.'}
        </div>
        <div className="flex justify-end gap-2 mt-5">
          <button className="btn btn-soft" onClick={() => setBlockModal(null)}>تراجع عن التغيير</button>
          {canPublish && <button className="btn btn-ink" onClick={() => { productsApi.update(p.id,{status:'draft'}).then(()=>{setP({...p,status:'draft'});setBlockModal(null);}).catch(e=>vtoast(normalizeError(e).message,'error')); }}>تحويل لمسودة</button>}
        </div>
      </Modal>
      <LeaveDialog open={leave} onStay={() => setLeave(false)} onLeave={() => { setLeave(false); nav('/products'); }} />
    </div>
  );
}

/* ---------- Tab A: Basic ---------- */
function BasicTab({ B, set, errs, code, flat }) {
  const [descLang, setDescLang] = useState('ar');
  return (
    <div className="grid lg:grid-cols-[1fr_340px] gap-5 items-start">
      <div className="space-y-5">
        <SectionCard title="الاسم والتصنيف">
          <div className="grid md:grid-cols-2 gap-4">
            <Field label="اسم المنتج (عربي)" required error={errs.ar} id="ar"><Input value={B.ar} onChange={(e) => set('ar', e.target.value)} error={errs.ar} placeholder="مثال: شنطة سفر الفريد" /></Field>
            <Field label="اسم المنتج (إنجليزي)" required error={errs.en} hint={B.en && !/[a-z]/i.test(B.en) ? 'يُفضّل كتابة الاسم بالإنجليزية لتوليد رابط مناسب' : null} id="en"><Input ltr dir="ltr" value={B.en} onChange={(e) => set('en', e.target.value)} error={errs.en} placeholder="Alfarid Travel Bag" /></Field>
            <Field label="القسم" required error={errs.category}>
              <SearchSelect value={B.category} onChange={(v) => set('category', v)} error={errs.category} placeholder="اختر القسم" emptyText="لا يوجد قسم بهذا الاسم"
                options={flat.map((f) => ({ value: f.id, label: f.ar, depth: f.depth, sub: f.depth ? null : f.en, note: f.status !== 'active' ? 'غير نشط' : null }))} />
            </Field>
          </div>
          <div className="mt-5">
            <Field label="قناة البيع" required error={errs.channel} hint="بتحدد الأسعار المطلوبة لكل متغير قبل النشر.">
              <div className="grid grid-cols-3 gap-2.5">
                {[['retail', 'تجزئة', 'سعر التجزئة مطلوب', 'cart'], ['wholesale', 'جملة', 'سعر الجملة مطلوب', 'warehouse'], ['both', 'الاتنين', 'السعرين مطلوبين', 'layers']].map(([k, l, s, ic]) => (
                  <button key={k} type="button" onClick={() => set('channel', k)} className={cx('rounded-[16px] p-3.5 text-start border transition', B.channel === k ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--border-field)] hover:bg-[var(--bg-subcard)]')}>
                    <span className={cx('w-8 h-8 rounded-full flex items-center justify-center mb-2', B.channel === k ? 'bg-acc' : 'v-well')}><Icon name={ic} size={15} /></span>
                    <span className="block text-[13px] font-semibold t-1">{l}</span><span className="block text-[11px] t-3">{s}</span>
                  </button>
                ))}
              </div>
            </Field>
          </div>
        </SectionCard>

        <SectionCard title="الوصف والخامة" aside={<Seg value={descLang} onChange={setDescLang} options={[{ value: 'ar', label: 'عربي' }, { value: 'en', label: 'English' }]} />}>
          <div className="space-y-4">
            {descLang === 'ar' ? <>
              <Field label="الوصف"><Textarea rows={4} value={B.desc_ar} onChange={(e) => set('desc_ar', e.target.value)} /></Field>
              <Field label="الخامة"><Input value={B.mat_ar} onChange={(e) => set('mat_ar', e.target.value)} placeholder="مثال: بوليستر مقاوم للماء" /></Field>
            </> : <>
              <Field label="Description"><Textarea rows={4} dir="ltr" className="ltr" value={B.desc_en} onChange={(e) => set('desc_en', e.target.value)} /></Field>
              <Field label="Material"><Input ltr value={B.mat_en} onChange={(e) => set('mat_en', e.target.value)} /></Field>
            </>}
          </div>
        </SectionCard>

        <SectionCard title="الأبعاد والضمان">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Field label="الطول"><Input ltr suffix="سم" value={B.l} onChange={(e) => set('l', e.target.value)} /></Field>
            <Field label="العرض"><Input ltr suffix="سم" value={B.w} onChange={(e) => set('w', e.target.value)} /></Field>
            <Field label="الارتفاع"><Input ltr suffix="سم" value={B.h} onChange={(e) => set('h', e.target.value)} /></Field>
            <Field label="الوزن"><Input ltr suffix="كجم" value={B.kg} onChange={(e) => set('kg', e.target.value)} /></Field>
          </div>
          <div className="grid md:grid-cols-[160px_1fr_200px] gap-3 mt-4">
            <Field label="مدة الضمان"><Input ltr suffix="شهر" value={B.warranty} onChange={(e) => set('warranty', e.target.value)} /></Field>
            <Field label="تفاصيل الضمان"><Input dir="auto" value={B.warranty_details} onChange={(e) => set('warranty_details', e.target.value)} /></Field>
            <Field label="بلد المنشأ"><Input dir="auto" value={B.origin} onChange={(e) => set('origin', e.target.value)} /></Field>
          </div>
        </SectionCard>
      </div>

      <div className="space-y-5 lg:sticky lg:top-5">
        <div className="v-card p-5">
          <Field label="كود المنتج" hint="بيتولد من الخادم بعد أول حفظ ولا يمكن تعديله.">
            <div className="v-input is-readonly ltr flex items-center justify-between">{code || <span className="t-3">PRD-••••••</span>}<Icon name="lock" size={14} className="t-3" /></div>
          </Field>
          <div className="mt-4"><Field error={errs.slug} label="الرابط (slug)" hint="اختياري — بيتولد من الاسم الإنجليزي">
            <Input ltr value={B.slug} placeholder={(B.en || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-') || 'auto'} onChange={(e) => set('slug', e.target.value)} />
          </Field></div>
        </div>
        <div className={cx('rounded-[22px] p-5 transition cursor-pointer', B.featured ? 'v-orange' : 'v-card')} onClick={() => set('featured', !B.featured)}>
          <div className="flex items-center justify-between">
            <span className={cx('w-10 h-10 rounded-full flex items-center justify-center', B.featured ? 'bg-white/15' : 'v-well')}><Icon name="star" size={18} /></span>
            <Toggle on={B.featured} onChange={(x) => set('featured', x)} />
          </div>
          <div className={cx('text-[14px] font-semibold mt-3', !B.featured && 't-1')}>منتج مميز</div>
          <div className={cx('text-[12px] mt-0.5', B.featured ? 'text-white/80' : 't-3')}>يظهر في الواجهة الرئيسية للمتجر وأقسام «المختارات».</div>
        </div>

      </div>
    </div>
  );
}

/* ---------- Tab C: Media ---------- */
function MediaTab({media,setMedia,axes,mode,saved,onRetry}) {
 const [sizeErr,setSizeErr]=useState('');const setError=setSizeErr;
 const inputRef=useRef(null),target=useRef('general');const [dragIdx,setDragIdx]=useState(null);
 const addFiles=(count,color='general')=>{target.current=color;const el=inputRef.current;el.multiple=color!=='video';el.accept=color==='video'?'video/mp4,video/webm,video/quicktime':'image/jpeg,image/png,image/webp';el.click();};
 const reorder=to=>{if(dragIdx==null||dragIdx===to)return;const items=[...media.general];items.splice(to,0,items.splice(dragIdx,1)[0]);setMedia({...media,general:items});setDragIdx(to);};
 const pick=async(files,color='',video=false)=>{
   const pending=[];
   for(const file of files){
     if(!((video?['video/mp4','video/webm','video/quicktime']:['image/jpeg','image/png','image/webp']).includes(file.type)) || file.size>(video?50:5)*1024*1024){setError('صيغة أو حجم غير مسموح: الصور حتى 5MB والفيديو حتى 50MB');return;}
     if(!video){try{const bitmap=await createImageBitmap(file);const valid=bitmap.width<=6000&&bitmap.height<=6000;bitmap.close();if(!valid)throw Error();}catch{setError('صورة غير صالحة أو أبعادها أكبر من 6000×6000');return;}}
     pending.push({id:'pending-'+Math.random(),file,name:file.name,size:(file.size/1048576).toFixed(2)+'MB',status:'queued'});
   }
   setError('');
   if(!pending.length)return;
   if(video){if(media.video&&!window.confirm('استبدال الفيديو الحالي؟ سيتم حذفه عند الحفظ.'))return;setMedia({...media,video:pending[0]});}
   else if(color)setMedia({...media,byVariant:{...media.byVariant,[color]:[...(media.byVariant[color]||[]),...pending]}});
   else setMedia({...media,general:[...media.general,...pending]});
 };
  const failed = [...media.general,...Object.values(media.byVariant).flat(),...(media.video?[media.video]:[])].filter((f) => f.status === 'failed').length;
  const queued = media.general.filter((f) => f.status === 'queued').length;
  return (
    <div className="grid lg:grid-cols-[1fr_340px] gap-5 items-start">
      <div className="space-y-5">
        <input ref={inputRef} className="sr-only" type="file" multiple accept="image/jpeg,image/png,image/webp" onChange={e=>{void pick([...e.target.files],target.current==='general'||target.current==='video'?'':target.current,target.current==='video');e.target.value='';}}/>
        {!saved && <Banner tone="blue" icon="info" title="الصور هتترفع بعد حفظ المسودة">تقدر تختار الملفات دلوقتي. الرفع الفعلي بيبدأ تلقائيًا بعد ما المنتج والمتغيرات يتحفظوا.</Banner>}
        {failed > 0 && <Banner tone="red" icon="alertCircle" title={`${failed} صورة فشل رفعها`} action={<button className="btn btn-sm btn-ink" onClick={onRetry}><Icon name="refresh" size={13} />إعادة رفع الكل</button>}>المسودة محفوظة. أعد رفع الصور الفاشلة بدون إنشاء منتج جديد.</Banner>}
        <SectionCard title="صور المنتج" subtitle="اسحب لتغيير الترتيب. الصورة الأولى هي الرئيسية في المتجر." aside={<span className="text-[12px] t-3 num">{media.general.length}</span>}>
          {sizeErr && <Banner tone="red" icon="alertCircle" className="mb-4 pop" onClose={() => setSizeErr(null)}>{sizeErr}</Banner>}
          <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3">
            {media.general.map((f, i) => (
              <FileTile key={f.id} file={f} index={i + 1} primary={i === 0 && f.status !== 'failed'} draggable
                onDragStart={() => setDragIdx(i)} onDragOver={(e) => { e.preventDefault(); reorder(i); }} onDrop={() => setDragIdx(null)}
                onRemove={() => setMedia({ ...media, general: media.general.filter((x) => x.id !== f.id) })} onRetry={onRetry} />
            ))}
            {true && (
              <button onClick={() => addFiles(2)} className="dropzone aspect-[4/3] sm:aspect-auto min-h-[150px] flex flex-col items-center justify-center gap-2 t-2">
                <span className="w-10 h-10 rounded-full b-acc flex items-center justify-center"><Icon name="plus" size={18} /></span>
                <span className="text-[12.5px] font-semibold t-1">إضافة صور</span>
              </button>
            )}
          </div>
          {media.general.length === 0 && <div className="mt-3"><DropZone title="اسحب صور المنتج هنا" rules={['JPG · PNG · WebP', 'حتى 5MB للصورة', 'حتى 6000×6000', 'صورتان بالتوازي']} onPick={() => addFiles(3)} onFiles={files=>pick(files)} /></div>}
          {media.general.length > 0 && <div className="flex flex-wrap gap-1.5 mt-4">{['JPG · PNG · WebP', 'حتى 5MB للصورة', 'حتى 6000×6000', 'صورتان بالتوازي'].map((r) => <span key={r} className="text-[10.5px] font-medium px-2 py-0.5 rounded-full v-well">{r}</span>)}{queued > 0 && <Badge tone="amber" className="ms-auto">{queued} بانتظار الحفظ</Badge>}</div>}
        </SectionCard>

        {mode === 'matrix' && axes.colors.length > 0 && (
          <SectionCard title="صور حسب اللون" subtitle="بتظهر لما العميل يختار اللون ده. اختيارية.">
            <div className="space-y-3">
              {axes.colors.map((c) => {
                const list = media.byVariant[c.en] || [];
                return (
                  <div key={c.en} className="v-subcard p-3.5 flex items-center gap-4">
                    <div className="flex items-center gap-2.5 w-[150px] shrink-0"><Swatch c={c.hex} size={28} /><div><div className="text-[12.5px] font-semibold t-1">{c.ar}</div><div className="text-[10.5px] t-3 ltr">{c.en}</div></div></div>
                    <div className="flex-1 flex items-center gap-2 overflow-x-auto">
                      {list.map((f) => <div key={f.id} className="w-16 shrink-0"><FileTile compact file={f} onRetry={onRetry} onRemove={() => setMedia({ ...media, byVariant: { ...media.byVariant, [c.en]: list.filter((x) => x.id !== f.id) } })} /></div>)}
                      <button onClick={() => addFiles(1, c.en)} className="dropzone w-16 h-16 shrink-0 flex items-center justify-center t-3"><Icon name="plus" size={16} /></button>
                    </div>
                  </div>
                );
              })}
            </div>
          </SectionCard>
        )}

        <SectionCard title="فيديو المنتج" subtitle="فيديو واحد للمنتج كله — مش مرتبط بمتغير.">
          {media.video ? (
            <div className="flex items-center gap-4 v-subcard p-3.5">
              <div className="w-28 aspect-video rounded-[12px] flex items-center justify-center" style={{ background: IMG[6], color: 'rgba(255,255,255,.7)' }}><Icon name="video" size={22} /></div>
              <div className="flex-1"><div className="text-[12.5px] font-semibold t-1 ltr text-end">{media.video.name}</div><div className="text-[11px] t-3 num">{media.video.size}</div>
                {media.video.status === 'queued' && <Badge tone="amber" className="mt-1.5">بانتظار الحفظ</Badge>}{media.video.status==='uploading'&&<span>{media.video.progress}%</span>}{media.video.status==='failed'&&<div className="t-acc">{media.video.message}<button className="btn btn-sm" onClick={onRetry}>إعادة المحاولة</button></div>}</div>
              <button className="btn btn-soft btn-sm" onClick={()=>addFiles(1,'video')}>استبدال الفيديو</button>
              <button className="icon-btn" onClick={() => setMedia({ ...media, video: null })}><Icon name="trash" size={15} /></button>
            </div>
          ) : <DropZone icon="video" title="اختر فيديو" rules={['MP4 · MOV', 'حتى 50MB', 'فيديو واحد فقط']} onPick={() => addFiles(1,'video')} onFiles={files=>pick(files,'',true)} />}
        </SectionCard>
      </div>

      <div className="v-card p-5 lg:sticky lg:top-5">
        <div className="text-[13px] font-semibold t-1 mb-1">معاينة ترتيب العرض</div>
        <div className="text-[11.5px] t-3 mb-4">كده هتظهر في صفحة المنتج.</div>
        <div className="rounded-[18px] aspect-square relative overflow-hidden" style={{ background: (media.general[0]?.url ? 'url("'+media.general[0].url+'") center / cover' : media.general[0]?.bg) || 'var(--bg-subcard)' }}>
          {!media.general[0] && <div className="absolute inset-0 flex items-center justify-center t-3"><Icon name="image" size={30} /></div>}
          {media.video && <span className="absolute bottom-3 end-3 h-7 px-2.5 rounded-full bg-black/55 text-white text-[11px] font-semibold flex items-center gap-1"><Icon name="video" size={12} />فيديو</span>}
        </div>
        <div className="grid grid-cols-5 gap-1.5 mt-2">
          {media.general.slice(0, 5).map((f, i) => <div key={f.id} className={cx('aspect-square rounded-[10px] relative', i === 0 && 'ring-2 ring-[var(--accent)] ring-offset-2 ring-offset-[var(--bg-card)]')} style={{ background: f.url ? 'url("'+f.url+'") center / cover' : f.bg, opacity: f.status === 'failed' ? .35 : 1 }} />)}
          {Array.from({ length: Math.max(0, 5 - media.general.length) }).map((_, i) => <div key={i} className="aspect-square rounded-[10px] v-subcard" />)}
        </div>
      </div>
    </div>
  );
}


function ReviewTab({ p, ready, canCost, canPublish, goto, catNode }) {
  const B = p.basic;
  const prices = p.rows.map((r) => +r.retail).filter(Boolean);
  const okCount = ready.filter((r) => r.ok).length;
  return (
    <div className="grid lg:grid-cols-[1fr_380px] gap-5 items-start">
      <div className="v-card p-6">
        <div className="flex items-start justify-between gap-3">
          <div><div className="font-display text-[17px] font-semibold t-1">قائمة الجاهزية للنشر</div><div className="text-[12.5px] t-2 mt-0.5">كل البنود لازم تكون مكتملة قبل النشر. الحفظ كمسودة متاح دائمًا.</div></div>
          <div className="text-end"><div className="font-display text-[28px] font-bold t-1 num leading-none">{okCount}<span className="t-3 text-[16px]">/{ready.length}</span></div></div>
        </div>
        <div className="mt-5 space-y-2">
          {ready.map((r) => (
            <div key={r.k} className={cx('flex items-center gap-3.5 p-4 rounded-[16px]', r.ok ? 'v-subcard' : 'border border-dashed')} style={!r.ok ? { borderColor: 'var(--badge-red-text)', background: 'var(--badge-red-bg)' } : null}>
              <span className={cx('w-9 h-9 rounded-full flex items-center justify-center shrink-0', r.ok ? 'b-green' : 'bg-[var(--bg-card)]')} style={!r.ok ? { color: 'var(--badge-red-text)' } : null}><Icon name={r.ok ? 'check' : r.k === 'perm' ? 'lock' : 'x'} size={16} sw={2.5} /></span>
              <div className="flex-1 min-w-0"><div className="text-[13px] font-semibold t-1">{r.label}</div><div className="text-[11.5px] t-2 mt-0.5">{r.detail}</div></div>
              {!r.ok && r.tab && <button className="btn btn-sm btn-outline" onClick={() => goto(r.tab)}>إصلاح<Icon name="chevLeft" size={13} /></button>}
            </div>
          ))}
        </div>
        <div className="mt-5 v-subcard p-4">
          <div className="text-[12px] font-semibold t-2 mb-3">تسلسل التنفيذ عند النشر</div>
          <div className="flex items-center gap-2 text-[12px]">
            {['حفظ المسودة والمتغيرات', 'رفع الصور', 'تحديث الحالة إلى منشور'].map((s, i) => (
              <React.Fragment key={s}>
                {i > 0 && <span className="flex-1 h-[2px] min-w-[16px] hatched-track rounded-full" />}
                <span className="flex items-center gap-2 shrink-0"><span className="w-6 h-6 rounded-full bg-ink text-[11px] font-bold flex items-center justify-center num">{i + 1}</span><span className="t-1 font-medium">{s}</span></span>
              </React.Fragment>
            ))}
          </div>
        </div>
      </div>

      <div className="v-card overflow-hidden">
        <div className="aspect-[4/3] relative" style={{ background: (p.media.general[0] && p.media.general[0].bg) || 'var(--bg-subcard)' }}>
          {B.featured && <span className="absolute top-3 start-3 h-7 px-2.5 rounded-full bg-acc text-[11px] font-semibold flex items-center gap-1"><Icon name="star" size={11} sw={2.5} />مميز</span>}
          <span className="absolute top-3 end-3">{p.status ? <StatusBadge s={p.status} /> : <Badge>جديد</Badge>}</span>
        </div>
        <div className="p-5">
          <div className="text-[11.5px] t-3">{catNode ? catNode.path.join(' › ') : 'بدون قسم'}</div>
          <div className="font-display text-[18px] font-semibold t-1 mt-1">{B.ar || 'بدون اسم'}</div>
          <div className="text-[12px] t-3 ltr text-end">{B.en}</div>
          <div className="font-display text-[22px] font-bold t-1 mt-3 num">{prices.length ? (Math.min(...prices) === Math.max(...prices) ? fmtMoney(prices[0]) : `${fmtMoney(Math.min(...prices))} – ${fmtMoney(Math.max(...prices))}`) : '—'} <span className="text-[12px] t-3 font-medium">ج.م</span></div>
          {p.mode === 'matrix' && p.axes.colors.length > 0 && <div className="flex gap-1.5 mt-3">{p.axes.colors.map((c) => <Swatch key={c.en} c={c.hex} size={22} />)}</div>}
          <div className="v-divide mt-4">
            <KV k="قناة البيع" v={CHANNEL_LABEL[B.channel]} />
            <KV k="المتغيرات" v={<span className="num">{p.rows.length}</span>} />
            <KV k="الصور" v={<span className="num">{p.media.general.length}</span>} />
            {canCost && prices.length > 0 && <KV k="متوسط الهامش" v={<span className="num" style={{ color: 'var(--badge-green-text)' }}>{Math.round(100 - (p.rows.reduce((a, r) => a + (+r.cost || 0), 0) / p.rows.reduce((a, r) => a + (+r.retail || 1), 0)) * 100)}%</span>} />}
          </div>
        </div>
      </div>
    </div>
  );
}

/* ---------- Publish flow modal ---------- */
function PublishFlow({ flow, onClose, onRetryUpload, onGoto }) {
  if (!flow) return null;
  const icon = (s) => s === 'done' ? <span className="w-8 h-8 rounded-full b-green flex items-center justify-center"><Icon name="check" size={15} sw={2.6} /></span>
    : s === 'run' ? <span className="w-8 h-8 rounded-full b-acc flex items-center justify-center"><Spinner size={15} /></span>
    : s === 'fail' ? <span className="w-8 h-8 rounded-full b-red flex items-center justify-center"><Icon name="x" size={15} sw={2.6} /></span>
    : s === 'skip' ? <span className="w-8 h-8 rounded-full v-well flex items-center justify-center"><Icon name="minus" size={15} /></span>
    : <span className="w-8 h-8 rounded-full v-well flex items-center justify-center"><span className="w-2 h-2 rounded-full bg-current opacity-40" /></span>;
  const d = flow.done;
  return (
    <Modal open onClose={d ? onClose : null} width={500}
      title={!d ? (flow.publish ? 'جارٍ النشر…' : 'جارٍ الحفظ…') : d === 'ok' ? (flow.publish ? 'تم نشر المنتج' : 'تم حفظ المسودة') : d === 'upload_fail' ? 'المسودة اتحفظت، لكن بعض الصور فشلت' : 'المسودة اتحفظت، لكن النشر اترفض'}
      subtitle={d === 'ok' ? <>كود المنتج <span className="ltr inline-block font-semibold t-1">{flow.code}</span></> : d === 'upload_fail' ? 'ما اتعملش منتج جديد. أعد رفع الصور الفاشلة من تبويب الصور.' : d === 'blocked' ? 'الخادم رفض تحديث الحالة. صلّح البنود دي وجرّب تاني.' : 'ما تقفلش الصفحة لحد ما تخلص الخطوات.'}
      icon={!d ? 'send' : d === 'ok' ? 'checkCircle' : 'alert'} iconTone={!d ? 'acc' : d === 'ok' ? 'green' : 'amber'}>
      <div className="v-subcard p-2">
        {flow.steps.map((s, i) => (
          <div key={s.k} className="flex items-center gap-3 p-2.5">
            {icon(s.state)}
            <div className="flex-1"><div className={cx('text-[13px] font-medium', s.state === 'wait' || s.state === 'skip' ? 't-3' : 't-1')}>{s.label}</div>{s.note && <div className="text-[11.5px] mt-0.5" style={{ color: s.state === 'fail' ? 'var(--badge-red-text)' : undefined }}>{s.note}</div>}</div>
            <span className="text-[10.5px] t-3 num">{i + 1}/{flow.steps.length}</span>
          </div>
        ))}
      </div>
      {d === 'upload_fail' && <Banner tone="red" className="mt-4">{(flow.reasons || []).join(' — ')}</Banner>}
      {d === 'blocked' && (
        <div className="mt-4 pop">
          <div className="text-[11.5px] t-3 mb-2 ltr text-end">422 · errors.status</div>
          <div className="space-y-1.5">
            {(flow.reasons || []).map(t=>({t,tab:/سعر|متغير/.test(t)?'variants':/صور|صورة/.test(t)?'media':'basic',tl:'اذهب'})).map((x) => (
              <button key={x.t} onClick={() => onGoto(x.tab)} className="w-full flex items-center gap-3 p-3 rounded-[14px] b-red text-start hover:opacity-90"><Icon name="alertCircle" size={15} /><span className="flex-1 text-[12.5px] font-medium">{x.t}</span><span className="text-[11px] opacity-80 flex items-center gap-1">{x.tl}<Icon name="chevLeft" size={12} /></span></button>
            ))}
          </div>
        </div>
      )}
      {d && (
        <div className="flex justify-end gap-2 mt-5">
          {d === 'ok' ? <><button className="btn btn-soft" onClick={() => { onClose(); nav('/products'); }}>كل المنتجات</button><button className="btn btn-ink" onClick={onClose}>متابعة التعديل</button></>
            : d === 'upload_fail' ? <><button className="btn btn-soft" onClick={onClose}>لاحقًا</button><button className="btn btn-ink" onClick={onRetryUpload}><Icon name="refresh" size={14} />إعادة رفع الصور الفاشلة</button></>
            : <button className="btn btn-ink" onClick={onClose}>فهمت</button>}
        </div>
      )}
    </Modal>
  );
}


const AppCtx = createContext(null);
const useApp = () => useContext(AppCtx);

/* ---------- Hash router with query (URL-persisted page/search/filters) ---------- */
function parseHash() {
  const h = location.hash.replace(/^#/, '') || (/^\/(products|dealers|production)(\/|$)/.test(location.pathname) ? location.pathname : '/products');
  const [path, qs] = h.split('?');
  const q = Object.fromEntries(new URLSearchParams(qs || ''));
  return { path, q, parts: path.split('/').filter(Boolean) };
}
function useRoute() {
  const [r, setR] = useState(parseHash());
  useEffect(() => { const h = () => setR(parseHash()); window.addEventListener('hashchange', h); return () => window.removeEventListener('hashchange', h); }, []);
  return r;
}
function nav(path, q) {
  const qs = q ? new URLSearchParams(Object.entries(q).filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== false)).toString() : '';
  location.hash = path + (qs ? '?' + qs : '');
}
// Update query in place (replace), keeps URL as source of truth for list state
function useQueryState(defaults) {
  const [r,setR] = useState(parseHash);
  useEffect(()=>{const changed=()=>setR(parseHash());window.addEventListener('hashchange',changed);return()=>window.removeEventListener('hashchange',changed);},[]);
  const state = { ...defaults, ...r.q };
  const set = (patch) => {
    const next = { ...state, ...patch };
    Object.keys(defaults).forEach((k) => { if (String(next[k]) === String(defaults[k])) delete next[k]; });
    const qs = new URLSearchParams(Object.entries(next).filter(([, v]) => v !== null && v !== undefined && v !== '')).toString();
    history.replaceState(null, '', '#' + parseHash().path + (qs ? '?' + qs : ''));
    setR(parseHash());
  };
  return [state, set];
}

function useUnsavedGuard(dirty) {
  useEffect(() => {
    const h = (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);
}
function LeaveDialog({ open, onStay, onLeave }) {
  return (
    <Modal open={open} onClose={onStay} title="لديك تغييرات غير محفوظة" subtitle="لو غادرت الصفحة الآن ستفقد التعديلات اللي عملتها. تحب تحفظ الأول؟" icon="alert" iconTone="amber"
      footer={<><button className="btn btn-soft" onClick={onLeave}>مغادرة بدون حفظ</button><button className="btn btn-ink" onClick={onStay}>البقاء والمتابعة</button></>} />
  );
}


const cx = (...a) => a.filter(Boolean).join(' ');
const fmtMoney = (n) => n == null || n === '' ? '—' : Number(n).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const toArDigits = (s) => String(s);

/* ---------------- Toasts ---------------- */
function vtoast(text, kind = 'success') {
  window.dispatchEvent(new CustomEvent('vtoast', { detail: { text, kind, id: Math.random() } }));
}
function ToastHost() {
  const [items, setItems] = useState([]);
  useEffect(() => {
    const h = (e) => {
      const t = e.detail;
      setItems((x) => [...x, t]);
      setTimeout(() => setItems((x) => x.filter((i) => i.id !== t.id)), 2800);
    };
    window.addEventListener('vtoast', h);
    return () => window.removeEventListener('vtoast', h);
  }, []);
  return (
    <div className="fixed bottom-6 left-6 z-[80] flex flex-col gap-2 items-start pointer-events-none">
      {items.map((t) => (
        <div key={t.id} className="pop px-4 py-2.5 rounded-full shadow-lg text-[12.5px] font-medium flex items-center gap-2 bg-ink">
          <span className={cx('w-2 h-2 rounded-full', t.kind === 'error' ? 'bg-rose-500' : t.kind === 'info' ? 'bg-sky-400' : 'bg-[var(--accent)]')} />
          {t.text}
        </div>
      ))}
    </div>
  );
}

/* ---------------- Basic atoms ---------------- */
function Badge({ tone = 'gray', children, dot, className = '' }) {
  return (
    <span className={cx('inline-flex items-center gap-1.5 px-2.5 py-[3px] rounded-full text-[11px] font-semibold whitespace-nowrap', 'b-' + tone, className)}>
      {dot && <span className="w-1.5 h-1.5 rounded-full bg-current" />}
      {children}
    </span>
  );
}
const STATUS_MAP = {
  active: ['green', 'نشط'], inactive: ['gray', 'غير نشط'],
  draft: ['amber', 'مسودة'], published: ['green', 'منشور'], archived: ['gray', 'مؤرشف'],
};
function StatusBadge({ s }) { const [t, l] = STATUS_MAP[s] || ['gray', s]; return <Badge tone={t} dot>{l}</Badge>; }

function Spinner({ size = 16, className = '' }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" className={cx('spin', className)}><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" strokeOpacity=".2" strokeWidth="3"/><path d="M21 12a9 9 0 00-9-9" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/></svg>;
}

function Toggle({ on, onChange, disabled }) {
  return <button type="button" role="switch" aria-checked={on} disabled={disabled} onClick={() => !disabled && onChange && onChange(!on)} className={cx('tgl', on && 'on', disabled && 'dis')}><span /></button>;
}
function Check({ on, mixed, onChange, disabled }) {
  return (
    <button type="button" role="checkbox" aria-checked={mixed ? 'mixed' : on} disabled={disabled} onClick={(e) => { e.stopPropagation(); onChange && onChange(!on); }} className={cx('chk', (on || mixed) && 'on', disabled && 'opacity-40')}>
      {mixed ? <Icon name="minus" size={12} sw={3} /> : on ? <Icon name="check" size={12} sw={3} /> : null}
    </button>
  );
}
function Seg({ value, options, onChange, size }) {
  return (
    <div className="seg">
      {options.map((o) => {
        const v = typeof o === 'string' ? o : o.value; const l = typeof o === 'string' ? o : o.label;
        return <button key={v} type="button" className={cx(value === v && 'on', size === 'lg' && '!px-5 !py-2 !text-[13px]')} onClick={() => onChange(v)}>{l}</button>;
      })}
    </div>
  );
}
function Skel({ w = '100%', h = 12, r, className = '' }) { return <div className={cx('skel', className)} style={{ width: w, height: h, borderRadius: r }} />; }

/* ---------------- Field wrapper ---------------- */
function Field({ label, required, hint, error, children, className = '', aside, id }) {
  return (
    <div className={cx('flex flex-col gap-1.5', className)} data-field={id}>
      {label && (
        <div className="flex items-center justify-between gap-2">
          <label className="text-[12.5px] font-semibold t-1 flex items-center gap-1">
            {label}
            {required && <span className="t-acc text-[13px] leading-none" title="مطلوب">*</span>}
          </label>
          {aside}
        </div>
      )}
      {children}
      {error ? (
        <div className="flex items-start gap-1.5 text-[11.5px] font-medium" style={{ color: 'var(--badge-red-text)' }}>
          <Icon name="alertCircle" size={13} className="mt-[1px]" />
          <span>{error}</span>
        </div>
      ) : hint ? <div className="text-[11.5px] t-3 leading-relaxed text-pretty">{hint}</div> : null}
    </div>
  );
}
function Input({ error, ltr, icon, suffix, className = '', readOnly, ...p }) {
  if (icon || suffix) {
    return (
      <div className="relative">
        {icon && <Icon name={icon} size={15} className="absolute top-1/2 -translate-y-1/2 start-3.5 t-3 pointer-events-none" />}
        <input {...p} readOnly={readOnly} className={cx('v-input', error && 'is-error', ltr && 'ltr', icon && 'ps-10', suffix && 'pe-14', className)} />
        {suffix && <span className="absolute top-1/2 -translate-y-1/2 end-3.5 text-[11.5px] font-semibold t-3">{suffix}</span>}
      </div>
    );
  }
  return <input {...p} readOnly={readOnly} className={cx('v-input', error && 'is-error', ltr && 'ltr', className)} />;
}
function Textarea({ error, className = '', ...p }) { return <textarea rows={3} {...p} className={cx('v-input', error && 'is-error', className)} />; }

/* ---------------- Popover helper ---------------- */
function useOutside(ref, cb, active) {
  useEffect(() => {
    if (!active) return;
    const h = (e) => { if (ref.current && !e.composedPath().includes(ref.current)) cb(); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [active]);
}

/* ---------------- Searchable Select ---------------- */
function SearchSelect({ value, onChange, options = [], placeholder = 'اختر…', error, disabled, loading: forceLoading, emptyText, multi, clearable = true, renderOption, asyncSim = false, searchPlaceholder = 'ابحث…' }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [loading, setLoading] = useState(false);
  const [hi, setHi] = useState(0);
  const ref = useRef(null);
  useOutside(ref, () => setOpen(false), open);
  useEffect(() => {
    if (!open || !asyncSim) return;
    setLoading(true);
    const t = setTimeout(() => setLoading(false), q ? 420 : 300);
    return () => clearTimeout(t);
  }, [q, open]);
  const isLoading = forceLoading || loading;
  const list = options.filter((o) => !q || (o.label + ' ' + (o.sub || '')).toLowerCase().includes(q.toLowerCase()));
  const selected = multi ? options.filter((o) => (value || []).includes(o.value)) : options.find((o) => o.value === value);
  const pick = (o) => {
    if (multi) {
      const cur = value || [];
      onChange(cur.includes(o.value) ? cur.filter((v) => v !== o.value) : [...cur, o.value]);
    } else { onChange(o.value); setOpen(false); setQ(''); }
  };
  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setHi((h) => Math.min(h + 1, list.length - 1)); }
    if (e.key === 'ArrowUp') { e.preventDefault(); setHi((h) => Math.max(h - 1, 0)); }
    if (e.key === 'Enter' && list[hi]) { e.preventDefault(); pick(list[hi]); }
    if (e.key === 'Escape') setOpen(false);
  };
  return (
    <div className="relative" ref={ref}>
      <button type="button" disabled={disabled} onClick={() => setOpen(!open)}
        className={cx('v-input flex items-center justify-between gap-2 text-start', open && 'is-focus', error && 'is-error', disabled && 'is-disabled', multi && selected.length && '!h-auto min-h-[42px] py-1.5')}>
        <span className="flex-1 min-w-0 flex items-center gap-1.5 flex-wrap">
          {multi ? (selected.length ? selected.map((s) => (
            <span key={s.value} className="inline-flex items-center gap-1 ps-2.5 pe-1 h-7 rounded-full text-[12px] font-medium" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-field)' }}>
              {s.label}
              <span role="button" onClick={(e) => { e.stopPropagation(); pick(s); }} className="w-5 h-5 rounded-full inline-flex items-center justify-center hover:bg-[var(--bg-subcard-hover)] t-3"><Icon name="x" size={11} /></span>
            </span>
          )) : <span className="t-3">{placeholder}</span>)
            : selected ? <span className="truncate flex items-center gap-2">{selected.icon}{selected.label}{selected.sub && <span className="t-3 text-[11.5px]">· {selected.sub}</span>}</span>
            : <span className="t-3">{placeholder}</span>}
        </span>
        {!multi && clearable && selected && !disabled && (
          <span role="button" onClick={(e) => { e.stopPropagation(); onChange(null); }} className="w-6 h-6 rounded-full inline-flex items-center justify-center t-3 hover:bg-[var(--bg-subcard-hover)]"><Icon name="x" size={12} /></span>
        )}
        <Icon name="chevDown" size={14} className={cx('t-3 transition-transform', open && 'rotate-180')} />
      </button>
      {open && (
        <div className="pop absolute z-40 mt-2 w-full v-card p-2" style={{ boxShadow: 'var(--shadow-pop)', borderRadius: 18 }}>
          <div className="relative mb-1.5">
            <Icon name="search" size={14} className="absolute top-1/2 -translate-y-1/2 start-3 t-3" />
            <input autoFocus value={q} onChange={(e) => { setQ(e.target.value); setHi(0); }} onKeyDown={onKey} placeholder={searchPlaceholder}
              className="w-full h-9 rounded-xl ps-9 pe-8 text-[12.5px] outline-none" style={{ background: 'var(--bg-subcard)', color: 'var(--text-primary)' }} />
            {isLoading && <Spinner size={14} className="absolute top-1/2 -translate-y-1/2 end-3 t-acc" />}
          </div>
          <div className="max-h-[248px] overflow-y-auto">
            {isLoading ? (
              <div className="p-2 space-y-3">{[0, 1, 2].map((i) => <div key={i} className="flex items-center gap-2.5"><Skel w={22} h={22} r={8} /><Skel w={`${70 - i * 15}%`} h={10} /></div>)}</div>
            ) : list.length === 0 ? (
              <div className="py-6 px-3 text-center">
                <div className="w-10 h-10 rounded-full v-well mx-auto flex items-center justify-center mb-2"><Icon name="search" size={16} /></div>
                <div className="text-[12.5px] font-semibold t-1">{emptyText || 'لا توجد نتائج'}</div>
                {q && <div className="text-[11.5px] t-3 mt-0.5">لا شيء يطابق «{q}»</div>}
              </div>
            ) : list.map((o, i) => {
              const sel = multi ? (value || []).includes(o.value) : o.value === value;
              return (
                <button type="button" key={o.value} onMouseEnter={() => setHi(i)} onClick={() => !o.disabled && pick(o)} disabled={o.disabled}
                  className={cx('w-full flex items-center gap-2.5 px-2.5 py-2 rounded-xl text-start text-[12.5px] transition', hi === i && 'bg-[var(--bg-subcard)]', o.disabled && 'opacity-40 cursor-not-allowed')}
                  style={{ paddingInlineStart: o.depth ? 10 + o.depth * 16 : undefined }}>
                  {multi && <Check on={sel} />}
                  {o.icon}
                  <span className="flex-1 min-w-0">
                    <span className={cx('block truncate', sel ? 'font-semibold t-1' : 't-1')}>{o.label}</span>
                    {o.sub && <span className="block text-[11px] t-3 truncate">{o.sub}</span>}
                  </span>
                  {o.note && <span className="text-[10.5px] t-3">{o.note}</span>}
                  {!multi && sel && <Icon name="check" size={14} className="t-acc" sw={2.4} />}
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

/* ---------------- Modal / Confirm ---------------- */
function Modal({ open, onClose, children, width = 460, title, subtitle, icon, iconTone = 'acc', footer }) {
  useEffect(() => {
    if (!open) return;
    const h = (e) => e.key === 'Escape' && onClose && onClose();
    window.addEventListener('keydown', h); return () => window.removeEventListener('keydown', h);
  }, [open]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center p-4 fade" style={{ background: 'var(--overlay)', backdropFilter: 'blur(3px)' }} onMouseDown={(e) => e.target === e.currentTarget && onClose && onClose()}>
      <div className="pop v-card w-full overflow-hidden" style={{ maxWidth: width, boxShadow: 'var(--shadow-pop)', borderRadius: 26 }}>
        {(title || icon) && (
          <div className="p-6 pb-0 flex items-start gap-3.5">
            {icon && <span className={cx('w-11 h-11 rounded-full flex items-center justify-center shrink-0', 'b-' + iconTone)}><Icon name={icon} size={19} /></span>}
            <div className="flex-1 min-w-0 pt-0.5">
              <h3 className="font-display text-[17px] font-semibold t-1">{title}</h3>
              {subtitle && <p className="text-[13px] t-2 mt-1 leading-relaxed text-pretty">{subtitle}</p>}
            </div>
            {onClose && <button className="icon-btn -mt-1 -me-1" onClick={onClose}><Icon name="x" size={16} /></button>}
          </div>
        )}
        <div className="p-6">{children}</div>
        {footer && <div className="px-6 pb-6 -mt-2 flex items-center justify-end gap-2">{footer}</div>}
      </div>
    </div>
  );
}

// Confirm with server-rejection state
function ConfirmDialog({ open, onClose, title, message, confirmLabel = 'تأكيد', danger, onConfirm, serverError, icon }) {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  useEffect(() => { if (open) { setErr(null); setBusy(false); } }, [open]);
  const go = async () => {
    setBusy(true); setErr(null);
    if (serverError) { setErr(serverError); setBusy(false); return; }
    try { await onConfirm?.(); onClose(); }
    catch(e){setErr({title:'تعذّر إتمام العملية',body:normalizeError(e).message});}
    finally{setBusy(false);}
  };
  return (
    <Modal open={open} onClose={busy ? null : onClose} title={title} subtitle={message} icon={icon || (danger ? 'trash' : 'alert')} iconTone={danger ? 'red' : 'amber'}>
      {err && (
        <div className="pop rounded-2xl p-3.5 mb-1 flex gap-3 b-red">
          <Icon name="ban" size={17} className="mt-0.5" />
          <div className="text-[12.5px] leading-relaxed">
            <div className="font-semibold">{err.title}</div>
            <div className="opacity-90 mt-0.5">{err.body}</div>
            {err.items && <ul className="mt-2 space-y-1">{err.items.map((i) => <li key={i} className="flex items-center gap-1.5"><span className="w-1 h-1 rounded-full bg-current" />{i}</li>)}</ul>}
          </div>
        </div>
      )}
      <div className="flex items-center justify-end gap-2 mt-3">
        <button className="btn btn-soft" onClick={onClose} disabled={busy}>{err ? 'إغلاق' : 'إلغاء'}</button>
        {!err && <button className={cx('btn', danger ? 'btn-acc' : 'btn-ink')} style={danger ? { background: 'var(--badge-red-text)', boxShadow: 'none' } : null} onClick={go} disabled={busy}>
          {busy && <Spinner size={14} />}{confirmLabel}
        </button>}
      </div>
    </Modal>
  );
}

/* ---------------- Alerts / summaries ---------------- */
function Banner({ tone = 'amber', icon = 'alert', title, children, action, onClose, className = '' }) {
  return (
    <div className={cx('rounded-[18px] p-4 flex items-start gap-3', 'b-' + tone, className)}>
      <span className="w-8 h-8 rounded-full flex items-center justify-center shrink-0" style={{ background: 'color-mix(in oklab, currentColor 14%, transparent)' }}><Icon name={icon} size={16} /></span>
      <div className="flex-1 min-w-0 text-[12.5px] leading-relaxed pt-1">
        {title && <div className="font-semibold text-[13px]">{title}</div>}
        {children && <div className="opacity-90">{children}</div>}
      </div>
      {action}
      {onClose && <button className="w-7 h-7 rounded-full inline-flex items-center justify-center hover:opacity-70" onClick={onClose}><Icon name="x" size={14} /></button>}
    </div>
  );
}

// errors.status style summary (e.g., publish blocked)
function ErrorSummary({ title, subtitle, items = [], onJump, onClose }) {
  return (
    <div className="pop v-card p-5" style={{ borderColor: 'var(--badge-red-bg)' }}>
      <div className="flex items-start gap-3">
        <span className="w-10 h-10 rounded-full b-red flex items-center justify-center shrink-0"><Icon name="ban" size={18} /></span>
        <div className="flex-1">
          <div className="font-display text-[15px] font-semibold t-1">{title}</div>
          {subtitle && <div className="text-[12.5px] t-2 mt-0.5">{subtitle}</div>}
        </div>
        {onClose && <button className="icon-btn" onClick={onClose}><Icon name="x" size={15} /></button>}
      </div>
      <div className="mt-4 v-subcard p-1.5 space-y-1">
        {items.map((it, i) => (
          <button key={i} onClick={() => onJump && onJump(it)} className="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-start hover:bg-[var(--bg-card)] transition group">
            <span className="w-1.5 h-1.5 rounded-full" style={{ background: 'var(--badge-red-text)' }} />
            <span className="flex-1 text-[12.5px] t-1">{it.text}</span>
            {it.tab && <span className="text-[11px] t-3 flex items-center gap-1 group-hover:t-acc">{it.tab}<Icon name="chevLeft" size={13} /></span>}
          </button>
        ))}
      </div>
    </div>
  );
}

/* ---------------- Empty / Error / Forbidden ---------------- */
function StateBlock({ icon = 'folder', title, body, action, tone = 'gray', compact }) {
  return (
    <div className={cx('flex flex-col items-center text-center', compact ? 'py-10' : 'py-16')}>
      <div className="relative mb-4">
        <div className="w-16 h-16 rounded-full hatched-track opacity-70 absolute -inset-2 m-auto" style={{ width: 80, height: 80, top: -8, left: -8 }} />
        <div className={cx('relative w-16 h-16 rounded-full flex items-center justify-center', 'b-' + tone)} style={tone === 'gray' ? { background: 'var(--bg-card)', border: '1px solid var(--border-subtle)', color: 'var(--text-secondary)' } : null}>
          <Icon name={icon} size={24} />
        </div>
      </div>
      <div className="font-display text-[16px] font-semibold t-1">{title}</div>
      {body && <div className="text-[13px] t-2 mt-1 max-w-[380px] leading-relaxed text-pretty">{body}</div>}
      {action && <div className="mt-5 flex items-center gap-2">{action}</div>}
    </div>
  );
}
function EmptyState(p) { return <StateBlock icon="folder" {...p} />; }
function ErrorState({ onRetry, title = 'تعذّر تحميل البيانات', body = 'حدث خطأ في الاتصال بالخادم. بياناتك لم تتأثر — جرّب مرة أخرى.', code }) {
  return <StateBlock icon="wifiOff" tone="red" title={title} body={<>{body}{code && <span className="block mt-1.5 text-[11px] t-3 ltr inline-block">{code}</span>}</>} action={<button className="btn btn-ink" onClick={onRetry}><Icon name="refresh" size={14} />إعادة المحاولة</button>} />;
}
function Forbidden({ perm }) {
  return (
    <div className="v-card stagger s2">
      <StateBlock icon="lock" tone="amber" title="ليس لديك صلاحية لعرض هذه الصفحة"
        body={<>هذه الصفحة تتطلب صلاحية <span className="ltr inline-block font-semibold t-1 px-1.5 rounded-md v-well">{perm}</span>. تواصل مع مدير النظام لإضافتها لدورك.</>}
        action={<button className="btn btn-soft" onClick={() => (location.hash = '#/products')}><Icon name="arrowRight" size={14} />العودة</button>} />
    </div>
  );
}

/* ---------------- Pagination (server meta) ---------------- */
function Pagination({ meta, onPage, onPerPage }) {
  const { current_page: p, last_page: last, per_page, total, from, to } = meta;
  const pages = [];
  for (let i = 1; i <= last; i++) if (i === 1 || i === last || Math.abs(i - p) <= 1) pages.push(i); else if (pages[pages.length - 1] !== '…') pages.push('…');
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5 v-border-t">
      <div className="flex items-center gap-3 text-[12px] t-2">
        <span>عرض <b className="t-1 num">{from}–{to}</b> من <b className="t-1 num">{total}</b></span>
        <span className="w-px h-4" style={{ background: 'var(--divider-color)' }} />
        <span className="flex items-center gap-1.5">لكل صفحة
          <select value={per_page} onChange={(e) => onPerPage(+e.target.value)} className="h-8 rounded-full px-2.5 text-[12px] font-semibold outline-none" style={{ background: 'var(--bg-subcard)', color: 'var(--text-primary)' }}>
            {[10, 15, 25, 50].map((n) => <option key={n} value={n}>{n}</option>)}
          </select>
        </span>
      </div>
      <div className="flex items-center gap-1">
        <button className="icon-btn" disabled={p <= 1} onClick={() => onPage(p - 1)} style={{ opacity: p <= 1 ? .35 : 1 }}><Icon name="chevRight" size={15} /></button>
        {pages.map((n, i) => n === '…' ? <span key={i} className="w-8 text-center t-3 text-[12px]">…</span> : (
          <button key={i} onClick={() => onPage(n)} className={cx('min-w-[32px] h-8 px-2 rounded-full text-[12px] font-semibold num transition', n === p ? 'bg-ink' : 't-2 hover:bg-[var(--bg-subcard)]')}>{n}</button>
        ))}
        <button className="icon-btn" disabled={p >= last} onClick={() => onPage(p + 1)} style={{ opacity: p >= last ? .35 : 1 }}><Icon name="chevLeft" size={15} /></button>
      </div>
    </div>
  );
}

/* ---------------- DataTable ---------------- */
function DataTable({ error, columns, rows, state = 'ready', meta, onPage, onPerPage, onRetry, empty, toolbar, rowKey = 'id', onRowClick, skeletonRows = 6, filtered }) {
  return (
    <div className="v-card overflow-hidden">
      {toolbar && <div className="p-4 v-border-b">{toolbar}</div>}
      {state === 'error' ? <ErrorState onRetry={onRetry} title={error?.message} /> :
       state === 'empty' || (state === 'ready' && rows.length === 0) ? (empty || <EmptyState title={filtered ? 'لا نتائج مطابقة للفلاتر' : 'لا توجد بيانات بعد'} body={filtered ? 'جرّب تعديل البحث أو إزالة بعض الفلاتر.' : null} />) : (
        <div className="overflow-x-auto">
          <table className="v-table">
            <thead><tr>{columns.map((c) => <th key={c.key} style={{ width: c.width, textAlign: c.align }}>{c.label}</th>)}</tr></thead>
            <tbody>
              {state === 'loading' ? Array.from({ length: skeletonRows }).map((_, i) => (
                <tr key={i}>{columns.map((c, j) => <td key={c.key}>{c.skel ? c.skel(i) : <Skel w={j === 0 ? '70%' : `${40 + ((i * 7 + j * 13) % 40)}%`} h={10} />}</td>)}</tr>
              )) : rows.map((r, i) => (
                <tr key={r[rowKey]} onClick={() => onRowClick && onRowClick(r)} className={cx(onRowClick && 'cursor-pointer')}>
                  {columns.map((c) => <td key={c.key} style={{ textAlign: c.align }}>{c.render ? c.render(r, i) : r[c.key]}</td>)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {meta && state !== 'error' && state !== 'empty' && rows.length > 0 && <Pagination meta={meta} onPage={onPage} onPerPage={onPerPage} />}
      {meta && state === 'loading' && (
        <div className="flex items-center justify-between px-5 py-3.5 v-border-t"><Skel w={140} h={10} /><Skel w={160} h={26} r={999} /></div>
      )}
    </div>
  );
}

function SearchBox({ value, onChange, placeholder = 'بحث…', className = '', width = 280 }) {
  const [draft,setDraft]=useState(value);const change=useRef(onChange);change.current=onChange;
  useEffect(()=>setDraft(value),[value]);
  useEffect(()=>{if(draft===value)return;const timer=setTimeout(()=>change.current(draft),300);return()=>clearTimeout(timer);},[draft,value]);
  return (
    <div className={cx('relative', className)} style={{ width }}>
      <Icon name="search" size={15} className="absolute top-1/2 -translate-y-1/2 start-3.5 t-3 pointer-events-none" />
      <input value={draft} onChange={(e) => setDraft(e.target.value)} placeholder={placeholder} className="v-input !h-10 !rounded-full ps-10 pe-9" />
      {draft && <button onClick={() => setDraft('')} className="absolute top-1/2 -translate-y-1/2 end-2 w-6 h-6 rounded-full inline-flex items-center justify-center t-3 hover:bg-[var(--bg-subcard-hover)]"><Icon name="x" size={12} /></button>}
    </div>
  );
}
function FilterChip({ label, value, onClear, onClick, active }) {
  return (
    <button onClick={onClick} className={cx('h-10 ps-3.5 pe-3 rounded-full inline-flex items-center gap-2 text-[12.5px] font-medium transition border', active ? 'bg-ink border-transparent' : 'btn-outline')}>
      <span className={active ? 'opacity-70' : 't-2'}>{label}</span>
      {value && <span className="font-semibold">{value}</span>}
      {active && onClear ? <span role="button" onClick={(e) => { e.stopPropagation(); onClear(); }} className="w-5 h-5 rounded-full inline-flex items-center justify-center hover:bg-white/15"><Icon name="x" size={11} /></span> : <Icon name="chevDown" size={13} className="opacity-60" />}
    </button>
  );
}
// Generic dropdown filter
function FilterMenu({ label, value, options, onChange, multi }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useOutside(ref, () => setOpen(false), open);
  const cur = options.find((o) => o.value === value);
  return (
    <div className="relative" ref={ref}>
      <FilterChip label={label} value={cur && cur.label} active={!!cur} onClick={() => setOpen(!open)} onClear={() => onChange(null)} />
      {open && (
        <div className="pop absolute z-40 mt-2 min-w-[200px] v-card p-1.5" style={{ boxShadow: 'var(--shadow-pop)', borderRadius: 16 }}>
          {options.map((o) => (
            <button key={o.value} onClick={() => { onChange(o.value === value ? null : o.value); setOpen(false); }} className="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-[12.5px] text-start hover:bg-[var(--bg-subcard)]">
              {o.dot && <span className="w-2 h-2 rounded-full" style={{ background: o.dot }} />}
              <span className="flex-1 t-1">{o.label}</span>
              {o.value === value && <Icon name="check" size={14} className="t-acc" sw={2.4} />}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

/* ---------------- Tabs ---------------- */
function Tabs({ value, onChange, tabs }) {
  return (
    <div className="seg !p-1.5 min-w-0 max-w-full overflow-x-auto">
      {tabs.map((t) => (
        <button key={t.value} type="button" onClick={() => onChange(t.value)} className={cx('!px-4 !py-2 !text-[12.5px] flex items-center gap-2', value === t.value && 'on')}>
          {t.num != null && <span className={cx('w-5 h-5 rounded-full text-[10.5px] font-bold inline-flex items-center justify-center num', value === t.value ? 'bg-acc' : 'v-well')}>{t.num}</span>}
          {t.label}
          {t.state === 'error' && <span className="w-2 h-2 rounded-full" style={{ background: 'var(--badge-red-text)' }} />}
          {t.state === 'done' && <Icon name="checkCircle" size={14} style={{ color: 'var(--badge-green-text)' }} />}
          {t.count != null && <span className="text-[10.5px] t-3 num">{t.count}</span>}
        </button>
      ))}
    </div>
  );
}

/* ---------------- Page header ---------------- */
function PageHeader({ crumbs = [], title, subtitle, actions, badge }) {
  return (
    <div className="stagger s1 flex flex-wrap items-end justify-between gap-4 pt-1">
      <div className="min-w-0 max-w-full">
        {crumbs.length > 0 && (
          <nav className="flex items-center gap-1.5 text-[12px] t-3 mb-2">
            {crumbs.map((c, i) => (
              <React.Fragment key={i}>
                {i > 0 && <Icon name="chevLeft" size={12} />}
                {c.href ? <a href={c.href} className="hover:t-1 hover:underline underline-offset-4">{c.label}</a> : <span className="t-2 font-medium">{c.label}</span>}
              </React.Fragment>
            ))}
          </nav>
        )}
        <div className="flex items-center gap-3 flex-wrap">
          <h1 className="font-display text-[30px] sm:text-[34px] font-semibold tracking-[-0.02em] leading-tight t-1">{title}</h1>
          {badge}
        </div>
        {subtitle && <p className="text-[13.5px] mt-1 t-2 text-pretty break-words max-w-full">{subtitle}</p>}
      </div>
      {actions && <div className="flex items-center gap-2 flex-wrap">{actions}</div>}
    </div>
  );
}

function SectionCard({ title, subtitle, children, aside, className = '', pad = 'p-6' }) {
  return (
    <div className={cx('v-card', pad, className)}>
      {(title || aside) && (
        <div className="flex items-start justify-between gap-3 mb-5">
          <div>
            <h2 className="font-display text-[16px] font-semibold t-1">{title}</h2>
            {subtitle && <p className="text-[12.5px] t-2 mt-0.5 text-pretty">{subtitle}</p>}
          </div>
          {aside}
        </div>
      )}
      {children}
    </div>
  );
}

/* ---------------- File upload tile ---------------- */
// status: queued | uploading | done | failed
function FileTile({ file, onRemove, onRetry, draggable, onDragStart, onDragOver, onDrop, index, primary, compact, children }) {
  const st = file.status;
  const [preview,setPreview]=useState(null);
  useEffect(()=>{if(!file.file)return;const url=URL.createObjectURL(file.file);setPreview(url);return ()=>URL.revokeObjectURL(url);},[file.file]);
  return (
    <div draggable={draggable} onDragStart={onDragStart} onDragOver={onDragOver} onDrop={onDrop}
      className={cx('group relative rounded-[16px] overflow-hidden transition', draggable && 'cursor-grab active:cursor-grabbing', st === 'failed' && 'ring-2 ring-[var(--badge-red-text)]')}
      style={{ background: 'var(--bg-subcard)' }}>
      <div className={cx('relative', compact ? 'aspect-square' : 'aspect-[4/3]')}>
        <div className="absolute inset-0" style={{ background: (file.url || preview) ? 'url("'+(file.url || preview)+'") center / cover' : (file.bg || 'var(--bg-subcard)') }} />
        <div className="absolute inset-0 flex items-center justify-center" style={{ color: 'rgba(24,21,28,.28)' }}><Icon name={file.kind === 'video' ? 'video' : 'image'} size={compact ? 20 : 26} /></div>
        {st === 'uploading' && <div className="absolute inset-0 bg-black/25 flex items-center justify-center"><span className="text-white text-[13px] font-bold num">{file.progress}%</span></div>}
        {st === 'failed' && <div className="absolute inset-0 flex items-center justify-center" style={{ background: 'rgba(225,29,72,.18)' }}><Icon name="alertCircle" size={24} style={{ color: '#e11d48' }} /></div>}
        {st === 'queued' && <span className="absolute top-2 start-2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-white/90 text-[#78737e]">بانتظار الحفظ</span>}
        {primary && <span className="absolute top-2 start-2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-acc">الرئيسية</span>}
        {index != null && <span className="absolute bottom-2 start-2 w-6 h-6 rounded-full text-[10.5px] font-bold flex items-center justify-center bg-white/90 text-[#18151c] num">{index}</span>}
        <div className="absolute top-2 end-2 flex gap-1 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 focus-within:opacity-100 transition">
          {draggable && <span className="w-7 h-7 rounded-full bg-white/90 text-[#18151c] flex items-center justify-center"><Icon name="drag" size={13} /></span>}
          {onRemove && <button onClick={onRemove} className="w-7 h-7 rounded-full bg-white/90 text-[#e11d48] flex items-center justify-center hover:bg-white"><Icon name="trash" size={13} /></button>}
        </div>
      </div>
      {!compact && (
        <div className="px-3 py-2.5">
          <div className="flex items-center justify-between gap-2">
            <span className="text-[11.5px] font-medium t-1 truncate ltr">{file.name}</span>
            <span className="text-[10.5px] t-3 num shrink-0">{file.size}</span>
          </div>
          {st === 'uploading' && <div className="mt-2 h-1.5 rounded-full hatched-track overflow-hidden"><div className="h-full rounded-full progress-live transition-all" style={{ width: file.progress + '%' }} /></div>}
          {st === 'failed' && (
            <div className="mt-1.5 flex items-center justify-between gap-2">
              <span className="text-[10.5px] font-medium truncate" style={{ color: 'var(--badge-red-text)' }}>{file.message || file.error || 'فشل الرفع'}</span>
              {onRetry && <button onClick={onRetry} className="text-[11px] font-semibold t-acc flex items-center gap-1 shrink-0"><Icon name="refresh" size={11} />إعادة</button>}
            </div>
          )}
          {st === 'done' && <div className="mt-1 text-[10.5px] font-medium flex items-center gap-1" style={{ color: 'var(--badge-green-text)' }}><Icon name="checkCircle" size={11} />تم الرفع</div>}
          {children}
        </div>
      )}
    </div>
  );
}

function DropZone({ onFiles, title = 'اسحب الملفات هنا أو اضغط للاختيار', rules = [], onPick, icon = 'upload', small, accept }) {
  const [over, setOver] = useState(false);
  return (
    <button type="button" onClick={onPick} onDragOver={(e) => { e.preventDefault(); setOver(true); }} onDragLeave={() => setOver(false)} onDrop={(e) => { e.preventDefault(); setOver(false); if(e.dataTransfer.files.length && onFiles)onFiles([...e.dataTransfer.files]);else onPick && onPick(); }}
      className={cx('dropzone w-full flex flex-col items-center justify-center text-center', small ? 'p-4' : 'p-7', over && 'over')}>
      <span className="w-11 h-11 rounded-full b-acc flex items-center justify-center mb-2.5"><Icon name={icon} size={18} /></span>
      <span className="text-[13px] font-semibold t-1">{title}</span>
      {rules.length > 0 && (
        <span className="flex flex-wrap justify-center gap-1.5 mt-2.5">
          {rules.map((r) => <span key={r} className="text-[10.5px] font-medium px-2 py-0.5 rounded-full v-well">{r}</span>)}
        </span>
      )}
    </button>
  );
}

// Single image field (category/brand logo)
function StickyBar({ children, dirty, note }) {
  return (
    <div className="sticky bottom-4 z-30 mt-6">
      <div className="v-card px-4 py-3 flex flex-wrap items-center justify-between gap-3" style={{ boxShadow: 'var(--shadow-pop)', borderRadius: 999 }}>
        <div className="flex items-center gap-2.5 ps-2 text-[12.5px]">
          {dirty ? <><span className="w-2 h-2 rounded-full bg-[var(--accent)] animate-pulse" /><span className="font-semibold t-1">تغييرات غير محفوظة</span></> : <><Icon name="checkCircle" size={15} style={{ color: 'var(--badge-green-text)' }} /><span className="t-2">كل التغييرات محفوظة</span></>}
          {note && <span className="t-3 hidden md:inline">· {note}</span>}
        </div>
        <div className="flex items-center gap-2">{children}</div>
      </div>
    </div>
  );
}

function KV({ k, v, ltr }) { return <div className="flex items-center justify-between gap-3 py-2.5 text-[12.5px]"><span className="t-2">{k}</span><span className={cx('font-semibold t-1', ltr && 'ltr')}>{v}</span></div>; }

function Swatch({ c, size = 18, ring }) { return <span className="inline-block rounded-full shrink-0" style={{ width: size, height: size, background: c, boxShadow: `inset 0 0 0 1px rgba(0,0,0,.08)${ring ? ', 0 0 0 2px var(--bg-card), 0 0 0 4px var(--accent)' : ''}` }} />; }


// Product form — Variants & pricing tab
const PRESET_COLORS = [
  { ar: 'رمادي فحمي', en: 'Charcoal', hex: '#3d3a40' }, { ar: 'بيج', en: 'Beige', hex: '#d8c6a8' },
  { ar: 'أزرق نيلي', en: 'Navy', hex: '#24324f' }, { ar: 'أخضر زيتي', en: 'Olive', hex: '#6b6b3f' },
  { ar: 'تيراكوتا', en: 'Terracotta', hex: '#c65f3c' }, { ar: 'أبيض عاجي', en: 'Ivory', hex: '#f3ede2' },
];
const vKey = (c, s, cap) => [(c && c.en || '').trim().toLowerCase(), (s || '').trim().toLowerCase(), (cap || '').trim().toLowerCase()].join('|');
const priceOk = (x) => x === '' || x == null || /^\d+(\.\d{1,2})?$/.test(String(x));

function emptyVariant(c, s, cap, i) {
  return { _id: Math.random().toString(36).slice(2), color: c || null, size: s || '', capacity: cap || '', sku: '', barcode: '', retail: '', wholesale: '', cost: '', compare: '', weight: '', reorder: '', order: i + 1, status: 'active',  };
}

function validateVariants(rows, channel, mode, requirePrices = true) {
  const errs = {}; const seen = {}; const skus=new Set(),barcodes=new Set();
  rows.forEach((r, i) => {
    const e = {};
    if (r.color && (r.color.ar || r.color.hex) && !r.color.en) e.color_en = 'الاسم الإنجليزي للون مطلوب';
    ['retail', 'wholesale', 'cost', 'compare'].forEach((k) => { if (!priceOk(r[k]) || Number(r[k])>9999999) e[k] = 'منزلتين عشريتين بحد أقصى'; });
    if (requirePrices && r.status === 'active') {
      if ((channel === 'retail' || channel === 'both') && !r.retail) e.retail = 'سعر التجزئة مطلوب';
      if ((channel === 'wholesale' || channel === 'both') && !r.wholesale) e.wholesale = 'سعر الجملة مطلوب';
    }
    if (r.compare && r.retail && +r.compare <= +r.retail) e.compare = 'لازم يكون أعلى من سعر التجزئة';
    const k = vKey(r.color, r.size, r.capacity);
    if (mode === 'matrix') { if (seen[k] != null) { e.dup = `نفس تركيبة الصف ${seen[k] + 1}`; } else seen[k] = i; }
    const sku=(r.sku||'').trim().toUpperCase(),barcode=(r.barcode||'').trim();
    if(sku && skus.has(sku))e.sku='SKU مكرر';if(sku)skus.add(sku);
    if(barcode && barcodes.has(barcode))e.barcode='باركود مكرر';if(barcode)barcodes.add(barcode);
    if(!r.serverId && /^2\d{12}$/.test(barcode))e.barcode='هذا النطاق محجوز لباركودات النظام؛ اتركه فارغاً للتوليد التلقائي';
    if (Object.keys(e).length) errs[r._id] = e;
  });
  return errs;
}

function ChipInput({ values, onChange, placeholder, suggestions = [], ltr }) {
  const [t, setT] = useState('');
  const add = (x) => { x = x.trim(); if (x && !values.includes(x)) onChange([...values, x]); setT(''); };
  return (
    <div>
      <div className="v-input !h-auto min-h-[42px] flex flex-wrap items-center gap-1.5 py-1.5 !px-2">
        {values.map((v) => (
          <span key={v} className="inline-flex items-center gap-1 ps-2.5 pe-1 h-7 rounded-full text-[12px] font-semibold" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-field)' }}>
            <span className={ltr ? 'ltr' : ''}>{v}</span>
            <button onClick={() => onChange(values.filter((x) => x !== v))} className="w-5 h-5 rounded-full inline-flex items-center justify-center t-3 hover:bg-[var(--bg-subcard-hover)]"><Icon name="x" size={11} /></button>
          </span>
        ))}
        <input value={t} onChange={(e) => setT(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(t); } if (e.key === 'Backspace' && !t && values.length) onChange(values.slice(0, -1)); }}
          placeholder={values.length ? '' : placeholder} className={cx('flex-1 min-w-[90px] h-7 bg-transparent outline-none text-[12.5px] px-1.5', ltr && 'ltr')} style={{ color: 'var(--text-primary)' }} />
      </div>
      {suggestions.filter((s) => !values.includes(s)).length > 0 && (
        <div className="flex flex-wrap gap-1 mt-2">{suggestions.filter((s) => !values.includes(s)).map((s) => <button key={s} onClick={() => add(s)} className="h-6 px-2 rounded-full text-[11px] font-medium v-well hover:t-1 flex items-center gap-1"><Icon name="plus" size={10} /><span className={ltr ? 'ltr' : ''}>{s}</span></button>)}</div>
      )}
    </div>
  );
}

function ColorAxis({ colors, onChange }) {
  const [draft, setDraft] = useState({ ar: '', en: '', hex: '#c65f3c' });
  const [err, setErr] = useState(null);
  const add = (c) => {
    if ((c.ar || c.hex) && !c.en) { setErr('الاسم الإنجليزي مطلوب لأنه جزء من التركيبة الفريدة'); return; }
    if (colors.some((x) => x.en.toLowerCase() === c.en.toLowerCase())) { setErr('اللون موجود بالفعل'); return; }
    onChange([...colors, c]); setDraft({ ar: '', en: '', hex: '#c65f3c' }); setErr(null);
  };
  return (
    <div>
      <div className="flex flex-wrap gap-2 mb-2.5">
        {colors.map((c) => (
          <span key={c.en} className="inline-flex items-center gap-2 ps-1.5 pe-1 h-9 rounded-full text-[12px] font-semibold" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-field)' }}>
            <Swatch c={c.hex} size={22} />{c.ar}<span className="t-3 font-medium ltr">{c.en}</span>
            <button onClick={() => onChange(colors.filter((x) => x !== c))} className="w-6 h-6 rounded-full inline-flex items-center justify-center t-3 hover:bg-[var(--bg-subcard)]"><Icon name="x" size={11} /></button>
          </span>
        ))}
      </div>
      <div className="flex items-start gap-2">
        <label className="w-[42px] h-[42px] rounded-[13px] relative overflow-hidden cursor-pointer shrink-0" style={{ background: draft.hex, boxShadow: 'inset 0 0 0 1px rgba(0,0,0,.1)' }}>
          <input type="color" value={draft.hex} onChange={(e) => setDraft({ ...draft, hex: e.target.value })} className="opacity-0 absolute inset-0 cursor-pointer" />
        </label>
        <input className="v-input flex-1" placeholder="اسم اللون (عربي)" value={draft.ar} onChange={(e) => setDraft({ ...draft, ar: e.target.value })} />
        <input className={cx('v-input ltr flex-1', err && 'is-error')} placeholder="Color name (EN) *" value={draft.en} onChange={(e) => { setDraft({ ...draft, en: e.target.value }); setErr(null); }} onKeyDown={(e) => e.key === 'Enter' && add(draft)} />
        <button className="btn btn-soft !h-[42px] !rounded-[13px]" onClick={() => add(draft)}><Icon name="plus" size={14} /></button>
      </div>
      {err && <div className="text-[11.5px] font-medium mt-1.5 flex items-center gap-1" style={{ color: 'var(--badge-red-text)' }}><Icon name="alertCircle" size={12} />{err}</div>}
      <div className="flex flex-wrap gap-1 mt-2">{PRESET_COLORS.filter((p) => !colors.some((c) => c.en === p.en)).map((p) => (
        <button key={p.en} onClick={() => add(p)} className="h-7 ps-1 pe-2.5 rounded-full text-[11px] font-medium v-well hover:t-1 flex items-center gap-1.5"><Swatch c={p.hex} size={18} />{p.ar}</button>
      ))}</div>
    </div>
  );
}

function VariantsTab({ channel, mode, setMode, axes, setAxes, rows, setRows, errs, canCost }) {
  const [deleting,setDeleting]=useState(null);
  const [sel, setSel] = useState([]);
  const [open, setOpen] = useState({});
  const [bulk, setBulk] = useState({ field: 'retail', value: '' });
  const [genNote, setGenNote] = useState(null);
  const gridRef = useRef(null);
  const priceCols = [
    { k: 'retail', l: 'سعر التجزئة', req: channel !== 'wholesale' },
    { k: 'wholesale', l: 'سعر الجملة', req: channel !== 'retail' },
    ...(canCost ? [{ k: 'cost', l: 'التكلفة', req: false }] : []),
    { k: 'compare', l: 'سعر المقارنة', req: false },
  ];
  const combos = (axes.colors.length || 1) * (axes.sizes.length || 1) * (axes.capacities.length || 1);
  const anyAxis = axes.colors.length + axes.sizes.length + axes.capacities.length > 0;

  const generate = () => {
    const cs = axes.colors.length ? axes.colors : [null];
    const ss = axes.sizes.length ? axes.sizes : [''];
    const ps = axes.capacities.length ? axes.capacities : [''];
    const existing = Object.fromEntries(rows.map((r) => [vKey(r.color, r.size, r.capacity), r]));
    const next = []; let kept = 0, added = 0;
    cs.forEach((c) => ss.forEach((s) => ps.forEach((p) => {
      const k = vKey(c, s, p);
      if (existing[k]) { next.push({ ...existing[k], color: c }); kept++; } else { next.push(emptyVariant(c, s, p, next.length)); added++; }
    })));
    const removed = rows.length - kept;
    setRows(next.slice(0, 100)); setSel([]);
    setGenNote({ kept, added, removed, capped: next.length > 100 });
  };
  const upd = (id, patch) => setRows(rows.map((r) => r._id === id ? { ...r, ...patch } : r));
  const copyDown = (k) => {
    const src = rows.find((r) => (sel.length ? sel.includes(r._id) : true) && r[k] !== '');
    if (!src) return;
    setRows(rows.map((r) => (!sel.length || sel.includes(r._id)) ? { ...r, [k]: src[k] } : r));
    vtoast(`تم نسخ ${src[k]} ${sel.length ? 'للصفوف المحددة' : 'لكل الصفوف'}`);
  };
  const applyBulk = () => {
    if (!bulk.value || !priceOk(bulk.value)) { vtoast('أدخل سعر صالح (منزلتين عشريتين)', 'error'); return; }
    setRows(rows.map((r) => sel.includes(r._id) ? { ...r, [bulk.field]: bulk.value } : r));
    vtoast(`تم تطبيق السعر على ${sel.length} صف`);
  };
  const onKey = (e, ri, ci) => {
    const move = (dr, dc) => { const el = gridRef.current.querySelector(`[data-cell="${ri + dr}-${ci + dc}"]`); if (el) { e.preventDefault(); el.focus(); el.select && el.select(); } };
    if (e.key === 'ArrowDown' || (e.key === 'Enter' && !e.shiftKey)) move(1, 0);
    if (e.key === 'ArrowUp' || (e.key === 'Enter' && e.shiftKey)) move(-1, 0);
    if (e.key === 'ArrowLeft' && e.target.selectionStart === e.target.value.length) move(0, 1);
    if (e.key === 'ArrowRight' && e.target.selectionStart === 0) move(0, -1);
  };

  const allSel = rows.length > 0 && sel.length === rows.length;

  return (
    <div className="space-y-5">
      <ConfirmDialog open={!!deleting} onClose={()=>setDeleting(null)} title="حذف المتغير؟" message="سيتم حذفه عند الحفظ، وستحذف الصور المرتبطة بهذا المتغير أيضاً." danger onConfirm={()=>setRows(rows.filter(r=>r._id!==deleting._id))}/>
      {/* Mode question */}
      <div className="v-card p-6">
        <div className="font-display text-[16px] font-semibold t-1">المنتج ده بمتغير واحد، ولا ألوان ومقاسات متعددة؟</div>
        <div className="grid md:grid-cols-2 gap-3 mt-4">
          {[['single', 'متغير واحد', 'سعر وSKU واحد للمنتج كله', 'box'], ['matrix', 'متغيرات متعددة', 'تركيبات من اللون × المقاس × السعة', 'grid']].map(([k, l, s, ic]) => (
            <button key={k} onClick={() => setMode(k)} className={cx('rounded-[18px] p-4 text-start border transition flex items-center gap-3.5', mode === k ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--border-field)] hover:bg-[var(--bg-subcard)]')}>
              <span className={cx('w-11 h-11 rounded-full flex items-center justify-center', mode === k ? 'bg-acc' : 'v-well')}><Icon name={ic} size={18} /></span>
              <span className="flex-1"><span className="block text-[14px] font-semibold t-1">{l}</span><span className="block text-[12px] t-3">{s}</span></span>
              <span className={cx('w-5 h-5 rounded-full border-2 flex items-center justify-center', mode === k ? 'border-[var(--accent)]' : 'border-[var(--border-field)]')}>{mode === k && <span className="w-2.5 h-2.5 rounded-full bg-[var(--accent)]" />}</span>
            </button>
          ))}
        </div>
      </div>

      {mode === 'matrix' && (
        <div className="v-card p-6">
          <div className="flex items-start justify-between gap-3 mb-5">
            <div><div className="font-display text-[16px] font-semibold t-1 flex items-center gap-2"><Icon name="wand" size={17} className="t-acc" />مولّد المتغيرات</div><div className="text-[12.5px] t-2 mt-0.5">اختار المحاور اللي محتاجها — إعادة التوليد بتحافظ على القيم اللي كتبتها.</div></div>
          </div>
          <div className="grid lg:grid-cols-3 gap-4">
            <div className="v-subcard p-4"><div className="flex items-center gap-2 text-[12.5px] font-semibold t-1 mb-3"><Icon name="palette" size={15} className="t-2" />اللون<span className="text-[11px] t-3 font-medium ms-auto num">{axes.colors.length}</span></div><ColorAxis colors={axes.colors} onChange={(c) => setAxes({ ...axes, colors: c })} /></div>
            <div className="v-subcard p-4"><div className="flex items-center gap-2 text-[12.5px] font-semibold t-1 mb-3"><Icon name="ruler" size={15} className="t-2" />المقاس<span className="text-[11px] t-3 font-medium ms-auto num">{axes.sizes.length}</span></div><ChipInput ltr values={axes.sizes} onChange={(s) => setAxes({ ...axes, sizes: s })} placeholder="اكتب واضغط Enter" suggestions={['200×90', '240×160', '280×180', 'Twin', 'Queen', 'King']} /></div>
            <div className="v-subcard p-4"><div className="flex items-center gap-2 text-[12.5px] font-semibold t-1 mb-3"><Icon name="layers" size={15} className="t-2" />السعة<span className="text-[11px] t-3 font-medium ms-auto num">{axes.capacities.length}</span></div><ChipInput values={axes.capacities} onChange={(s) => setAxes({ ...axes, capacities: s })} placeholder="مثال: ٣ مقاعد" suggestions={['٢ مقعد', '٣ مقاعد', '٥ مقاعد']} /></div>
          </div>
          <div className="flex flex-wrap items-center justify-between gap-3 mt-5 pt-5 v-border-t">
            <div className="flex items-center gap-2 text-[13px]">
              <span className="font-display text-[22px] font-bold t-1 num">{anyAxis ? combos : 0}</span><span className="t-2">تركيبة</span>
              <span className="t-3 text-[12px] num">({axes.colors.length || 1} × {axes.sizes.length || 1} × {axes.capacities.length || 1})</span>
              {combos > 100 && <Badge tone="red">الحد الأقصى ١٠٠ متغير في الطلب</Badge>}
            </div>
            <button className="btn btn-ink" disabled={!anyAxis} onClick={generate}><Icon name="sparkle" size={14} />{rows.length ? 'إعادة توليد التركيبات' : 'توليد التركيبات'}</button>
          </div>
          {genNote && <div className="pop mt-3 v-subcard p-3 flex flex-wrap items-center gap-3 text-[12px]"><Icon name="checkCircle" size={15} style={{ color: 'var(--badge-green-text)' }} /><span className="t-1">تم التوليد:</span><Badge tone="green">{genNote.added} جديد</Badge><Badge tone="blue">{genNote.kept} محفوظ بقيمه</Badge>{genNote.removed > 0 && <Badge tone="red">{genNote.removed} اتشال</Badge>}<button className="ms-auto t-3 hover:t-1" onClick={() => setGenNote(null)}><Icon name="x" size={13} /></button></div>}
        </div>
      )}

      {/* Grid */}
      <div className="v-card overflow-hidden">
        <div className="p-4 flex flex-wrap items-center justify-between gap-3 v-border-b">
          <div className="flex items-center gap-2.5 ps-1">
            <div className="font-display text-[15px] font-semibold t-1">{mode === 'single' ? 'السعر والتعريف' : 'المتغيرات'}</div>
            {mode === 'matrix' && <span className="text-[12px] t-3 num">{rows.length} / 100</span>}
            {!canCost && <Badge tone="amber"><Icon name="eyeOff" size={11} />التكلفة مخفية</Badge>}
          </div>
          {mode === 'matrix' && rows.length > 0 && (
            <div className="flex flex-wrap items-center gap-2">
              {sel.length > 0 ? (
                <div className="pop flex items-center gap-1.5 h-10 ps-3 pe-1 rounded-full bg-ink">
                  <span className="text-[12px] font-semibold num">{sel.length} محدد</span>
                  <select value={bulk.field} onChange={(e) => setBulk({ ...bulk, field: e.target.value })} className="h-8 rounded-full px-2 text-[12px] bg-white/10 outline-none">{priceCols.map((c) => <option key={c.k} value={c.k} className="text-black">{c.l}</option>)}</select>
                  <input value={bulk.value} onChange={(e) => setBulk({ ...bulk, value: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && applyBulk()} placeholder="0.00" className="w-24 h-8 rounded-full px-3 text-[12px] bg-white/10 outline-none ltr num" />
                  <button onClick={applyBulk} className="h-8 px-3 rounded-full bg-acc text-[12px] font-semibold">تطبيق</button>
                  <button onClick={() => setSel([])} className="w-8 h-8 rounded-full inline-flex items-center justify-center hover:bg-white/10"><Icon name="x" size={13} /></button>
                </div>
              ) : <span className="text-[11.5px] t-3 hidden lg:flex items-center gap-1.5"><Icon name="keyboard" size={13} /><kbd>↑</kbd><kbd>↓</kbd><kbd>Enter</kbd> للتنقل بين الخلايا</span>}

            </div>
          )}
        </div>

        {mode === 'matrix' && rows.length === 0 ? (
          <EmptyState compact icon="grid" title="لا توجد تركيبات بعد" body="أضف ألوان أو مقاسات أو سعات فوق واضغط «توليد التركيبات»." />
        ) : (
          <>
          {/* Desktop grid */}
          <div className="overflow-x-auto hidden md:block" ref={gridRef}>
            <table className="v-table" style={{ minWidth: mode === 'single' ? 760 : 1080 }}>
              <thead>
                <tr>
                  {mode === 'matrix' && <th style={{ width: 40 }}><Check on={allSel} mixed={sel.length > 0 && !allSel} onChange={() => setSel(allSel ? [] : rows.map((r) => r._id))} /></th>}
                  {mode === 'matrix' && <th>التركيبة</th>}
                  <th>SKU</th>
                  <th>الباركود</th>
                  {priceCols.map((c) => (
                    <th key={c.k}>
                      <span className="flex items-center gap-1">{c.l}{c.req && <span className="t-acc">*</span>}
                        {mode === 'matrix' && <button title="نسخ القيمة لأسفل" onClick={() => copyDown(c.k)} className="w-6 h-6 rounded-full inline-flex items-center justify-center hover:bg-[var(--bg-subcard)] t-3 hover:t-acc"><Icon name="copyDown" size={12} /></button>}
                      </span>
                    </th>
                  ))}
                  <th style={{ width: 90 }}></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, ri) => {
                  const e = errs[r._id] || {};
                  const isOpen = open[r._id];
                  let ci = 0;
                  const cell = (k, ph, ltr = true) => {
                    const idx = ci++;
                    return <input data-cell={`${ri}-${idx}`} onKeyDown={(ev) => onKey(ev, ri, idx)} value={r[k]} placeholder={ph} onChange={(ev) => upd(r._id, { [k]: ev.target.value })} className={cx('cell-in', ltr && 'ltr num', e[k] && 'is-error')} title={e[k] || ''} />;
                  };
                  return (
                    <React.Fragment key={r._id}>
                      <tr className={cx(r.status==='inactive' && 'opacity-45', e.dup && '!bg-[var(--badge-red-bg)]')}>
                        {mode === 'matrix' && <td><Check on={sel.includes(r._id)} onChange={() => setSel(sel.includes(r._id) ? sel.filter((x) => x !== r._id) : [...sel, r._id])} /></td>}
                        {mode === 'matrix' && (
                          <td style={{ minWidth: 220 }}>
                            <div className="flex items-center gap-2.5">
                              {r.color ? <Swatch c={r.color.hex} size={26} /> : <span className="w-[26px] h-[26px] rounded-full border border-dashed" style={{ borderColor: 'var(--border-field)' }} />}
                              <div className="min-w-0">
                                <div className="text-[12.5px] font-semibold t-1 truncate">{[r.color && r.color.ar, r.size, r.capacity].filter(Boolean).join(' · ') || '—'}</div>
                                <div className="text-[10.5px] t-3 ltr text-end">{[r.color && r.color.en, r.size, r.capacity].filter(Boolean).join(' / ')}</div>
                                {(e.dup || e.color_en) && <div className="text-[10.5px] font-semibold flex items-center gap-1" style={{ color: 'var(--badge-red-text)' }}><Icon name="alertCircle" size={11} />{e.dup ? `تركيبة مكررة — ${e.dup}` : e.color_en}</div>}
                              </div>
                            </div>
                          </td>
                        )}
                        <td style={{ minWidth: 150 }}>{cell('sku', 'تلقائي')}</td>
                        <td style={{ minWidth: 150 }}>{cell('barcode', 'من السيرفر')}</td>
                        {priceCols.map((c) => <td key={c.k} style={{ minWidth: 112 }}>{cell(c.k, c.req ? '0.00 *' : '0.00')}{e[c.k] && <div className="text-[10px] font-semibold mt-0.5 px-1" style={{ color: 'var(--badge-red-text)' }}>{e[c.k]}</div>}</td>)}
                        <td>
                          <div className="flex items-center justify-end gap-0.5">
                            {mode === 'matrix' && <button title="حذف المتغير" onClick={() => setDeleting(r)} className="icon-btn !w-8 !h-8"><Icon name="trash" size={14} /></button>}
                            <button title="تفاصيل إضافية" onClick={() => setOpen({ ...open, [r._id]: !isOpen })} className={cx('icon-btn !w-8 !h-8', isOpen && '!bg-[var(--bg-subcard)] t-1')}><Icon name="chevDown" size={14} className={cx('transition', isOpen && 'rotate-180')} /></button>
                          </div>
                        </td>
                      </tr>
                      {isOpen && (
                        <tr><td colSpan={20} className="!bg-[var(--bg-subcard)] !py-4">
                          <div className="pop grid grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                            {mode === 'matrix' && r.color && <Field label="اسم اللون (EN)" required><input className={cx('v-input ltr !h-9', e.color_en && 'is-error')} value={r.color.en} onChange={(ev) => upd(r._id, { color: { ...r.color, en: ev.target.value } })} /></Field>}
                            <Field label="وزن المتغير"><Input ltr suffix="كجم" className="!h-9" value={r.weight} onChange={(ev) => upd(r._id, { weight: ev.target.value })} placeholder="0.0" /></Field>
                            <Field label="حد تنبيه المخزون" hint="تنبيه عند الوصول له"><Input ltr className="!h-9" value={r.reorder} onChange={(ev) => upd(r._id, { reorder: ev.target.value })} placeholder="0" /></Field>
                            <Field label="الترتيب"><Input ltr className="!h-9" value={r.order} onChange={(ev) => upd(r._id, { order: ev.target.value })} /></Field>
                            <Field label="الحالة"><div className="h-9 flex items-center gap-2.5"><Toggle on={r.status === 'active'} onChange={(on) => upd(r._id, { status: on ? 'active' : 'inactive' })} /><span className="text-[12px] t-2">{r.status === 'active' ? 'نشط' : 'غير نشط'}</span></div></Field>
                          </div>
                          <div className="text-[11px] t-3 mt-3 flex items-center gap-1.5"><Icon name="info" size={12} />إنتاج المصنع بيتسجل من شاشة استلام الإنتاج بعد الحفظ — مش جزء من بيانات المتغير.</div>
                        </td></tr>
                      )}
                    </React.Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>
          {/* Mobile: summary rows with expandable details */}
          <div className="md:hidden v-divide">
            {rows.map((r) => {
              const e = errs[r._id] || {}; const isOpen = open[r._id];
              return (
                <div key={r._id} className="p-4">
                  <button className="w-full flex items-center gap-3 text-start" onClick={() => setOpen({ ...open, [r._id]: !isOpen })}>
                    {r.color ? <Swatch c={r.color.hex} size={30} /> : <span className="w-[30px] h-[30px] rounded-full v-well" />}
                    <div className="flex-1 min-w-0"><div className="text-[13px] font-semibold t-1 truncate">{[r.color && r.color.ar, r.size, r.capacity].filter(Boolean).join(' · ') || 'المتغير الأساسي'}</div><div className="text-[11.5px] t-3 num">{r.retail ? fmtMoney(r.retail) + ' ج.م' : 'بدون سعر'}</div></div>
                    {Object.keys(e).length > 0 && <span className="w-2 h-2 rounded-full" style={{ background: 'var(--badge-red-text)' }} />}
                    <Icon name="chevDown" size={15} className={cx('t-3 transition', isOpen && 'rotate-180')} />
                  </button>
                  {isOpen && <div className="pop grid grid-cols-2 gap-3 mt-4">
                    <Field label="SKU" error={e.sku}><input className="v-input ltr" value={r.sku} placeholder="تلقائي" onChange={(ev) => upd(r._id, { sku: ev.target.value })} /></Field>
                    <Field label="الباركود" error={e.barcode}><input className="v-input ltr" value={r.barcode} placeholder="من السيرفر" onChange={(ev) => upd(r._id, { barcode: ev.target.value })} /></Field>
                    {priceCols.map((c) => <Field key={c.k} label={c.l} required={c.req} error={e[c.k]}><input className={cx('v-input ltr', e[c.k] && 'is-error')} value={r[c.k]} onChange={(ev) => upd(r._id, { [c.k]: ev.target.value })} /></Field>)}
                  </div>}
                </div>
              );
            })}
          </div>
          </>
        )}
        <div className="px-5 py-3 v-border-t flex flex-wrap items-center gap-x-5 gap-y-1.5 text-[11.5px] t-3">
          <span className="flex items-center gap-1.5"><Icon name="barcode" size={13} />SKU والباركود الفارغين بيتولدوا من السيرفر وقت الإنشاء</span>
          <span className="flex items-center gap-1.5"><Icon name="hash" size={13} />الأسعار بحد أقصى منزلتين عشريتين</span>
          {mode === 'matrix' && <span className="flex items-center gap-1.5"><Icon name="palette" size={13} />التركيبة الفريدة = اللون (EN) + المقاس + السعة</span>}
        </div>
      </div>
    </div>
  );
}


function LoadedProduct({id,can}) {
 const query=useApi(sig=>Promise.all([productsApi.show(Number(id),sig),productMediaApi.list(Number(id))]),[id]);
 if(query.loading) return <Spinner />;
 if(query.error?.status===404)return <EmptyState title="المنتج غير موجود" action={<button className="btn btn-ink" onClick={()=>nav('/products')}>العودة للمنتجات</button>}/>;
 if(query.error?.status===403)return <Forbidden perm="products.view"/>;
 if(query.error) return <ErrorState title={query.error.message} onRetry={query.reload} />;
 const initial={...productFromApi(query.data[0],query.data[1],{canViewCost:can('products.view_cost')}),id:Number(id)};
 return <ProductForm id={id} initial={initial}/>;
}
export function CatalogReference({can,page,isDark = false}) {
 const route=useRoute();
 const lookups=useApi(async sig=>{const categories=page==='products' && can('categories.view')?await categoriesApi.tree(sig):[];return {categories};},[can,page]);
 const categories=(lookups.data?.categories||[]).map(function map(c){return {...c,ar:c.name_ar,en:c.name_en,status:c.is_active?'active':'inactive',children:(c.children||[]).map(map)};});
 const permission=page==='dealers'?'customers.view':page==='production'?'inventory.view':'products.view';
 return <AppCtx.Provider value={{user:{can},screenState:'ready',categories}}><div className={"catalog-surface " + (isDark ? "theme-dark" : "theme-light")} dir="rtl" lang="ar"><div className="p-4 sm:p-6">{!can(permission)?<Forbidden perm={permission}/>:lookups.error?<ErrorState onRetry={lookups.reload}/>:lookups.loading?<Spinner/>:page==='dealers'?<DealersPage/>:page==='production'?<ProductionPage/>:route.parts[1]==='new'?(can('products.create')?<ProductForm id="new"/>:<Forbidden perm="products.create"/>):route.parts[1]?<LoadedProduct id={route.parts[1]} can={can}/>:<ProductsPage/>}</div><ToastHost/></div></AppCtx.Provider>;
}

export { useApp, useQueryState, PageHeader, DataTable, SearchBox, FilterMenu, EmptyState, Drawer, Field, Input, SectionCard, Banner, SearchSelect, ErrorState, Spinner, Badge, vtoast, ConfirmDialog };

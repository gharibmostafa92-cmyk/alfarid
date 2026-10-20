import React, { useState, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '../../components/admin/PageHeader';
import { SearchSelect } from '../../components/admin/SearchSelect';
import { ConfirmModal } from '../../components/admin/ConfirmModal';
import { EmptyState } from '../../components/admin/States';
import { Button, Badge } from '@alfarid/ui';
import { formatCurrency } from '@alfarid/utils';
import { useAdminDataStore, ProductItem, catPath, isDescendant } from '../../stores/admin-data.store';
import { Plus, Search, Star, Edit, Trash2, SlidersHorizontal, Image as ImageIcon } from 'lucide-react';

export function ProductsPage() {
  const navigate = useNavigate();
  const { products, categories, brands, deleteProduct } = useAdminDataStore();

  const [query, setQuery] = useState('');
  const [selectedCat, setSelectedCat] = useState<number | null>(null);
  const [includeSub, setIncludeSub] = useState(true);
  const [selectedBrand, setSelectedBrand] = useState<number | null>(null);
  const [selectedStatus, setSelectedStatus] = useState<string>('');
  const [selectedChannel, setSelectedChannel] = useState<string>('');
  const [featuredOnly, setFeaturedOnly] = useState(false);
  const [sortBy, setSortBy] = useState<'newest' | 'oldest' | 'name'>('newest');

  const [deleteTarget, setDeleteTarget] = useState<ProductItem | null>(null);

  const filteredProducts = useMemo(() => {
    let result = products.filter((p) => {
      if (
        query &&
        !`${p.ar} ${p.en} ${p.code}`.toLowerCase().includes(query.toLowerCase())
      ) {
        return false;
      }
      if (selectedCat) {
        if (includeSub) {
          if (p.cat !== selectedCat && !isDescendant(categories, selectedCat, p.cat || 0)) {
            return false;
          }
        } else if (p.cat !== selectedCat) {
          return false;
        }
      }
      if (selectedBrand && p.brand !== selectedBrand) return false;
      if (selectedStatus && p.status !== selectedStatus) return false;
      if (selectedChannel && p.channel !== selectedChannel) return false;
      if (featuredOnly && !p.featured) return false;
      return true;
    });

    if (sortBy === 'oldest') {
      result = [...result].sort((a, b) => a.created - b.created);
    } else if (sortBy === 'name') {
      result = [...result].sort((a, b) => a.ar.localeCompare(b.ar, 'ar'));
    } else {
      result = [...result].sort((a, b) => b.created - a.created);
    }

    return result;
  }, [products, query, selectedCat, includeSub, selectedBrand, selectedStatus, selectedChannel, featuredOnly, sortBy, categories]);

  const channelLabels: Record<string, string> = {
    retail: 'تجزئة',
    wholesale: 'جملة',
    both: 'تجزئة وجملة',
  };

  const statusTone: Record<string, 'ok' | 'warn' | 'default'> = {
    published: 'ok',
    draft: 'warn',
    archived: 'default',
  };

  const statusLabels: Record<string, string> = {
    published: 'منشور',
    draft: 'مسودة',
    archived: 'مؤرشف',
  };

  const clearAllFilters = () => {
    setQuery('');
    setSelectedCat(null);
    setSelectedBrand(null);
    setSelectedStatus('');
    setSelectedChannel('');
    setFeaturedOnly(false);
  };

  const hasActiveFilters =
    query || selectedCat || selectedBrand || selectedStatus || selectedChannel || featuredOnly;

  return (
    <div>
      <PageHeader
        crumbs={[['الرئيسية', '/admin'], ['الكتالوج'], ['المنتجات']]}
        light="كتالوج"
        bold="المنتجات"
        sub="الشنط والمحافظ بكل ألوانها ومقاساتها وسعاتها. البحث يشمل الاسم والكود والباركود."
        actions={
          <Button
            size="sm"
            className="gap-2"
            onClick={() => navigate('/admin/products/new')}
          >
            <Plus className="w-4 h-4" />
            <span>منتج جديد</span>
          </Button>
        }
      />

      {/* Filters Bar */}
      <div className="bg-white border border-zinc-200 rounded-2xl p-4 shadow-sm mb-6 space-y-3">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
          {/* Search box */}
          <div className="relative">
            <Search className="w-4 h-4 text-zinc-400 absolute right-3 top-3 pointer-events-none" />
            <input
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="الاسم، الكود أو SKU..."
              className="w-full h-10 pr-9 pl-3 rounded-xl border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
            />
          </div>

          {/* Category filter */}
          <SearchSelect
            value={selectedCat}
            onChange={setSelectedCat}
            placeholder="كل الأقسام"
            clearable
            options={categories.map((c) => ({
              value: c.id,
              label: catPath(categories, c.id)
                .map((x) => x.ar)
                .join(' / '),
            }))}
          />

          {/* Brand filter */}
          <SearchSelect
            value={selectedBrand}
            onChange={setSelectedBrand}
            placeholder="كل البراندات"
            clearable
            options={brands.map((b) => ({
              value: b.id,
              label: b.en,
              sub: b.ar,
            }))}
          />

          {/* Sales Channel */}
          <select
            value={selectedChannel}
            onChange={(e) => setSelectedChannel(e.target.value)}
            className="h-10 px-3 rounded-xl border border-zinc-300 bg-white text-xs text-zinc-700 focus:border-brand-500 focus:outline-none"
          >
            <option value="">كل قنوات البيع</option>
            <option value="retail">تجزئة</option>
            <option value="wholesale">جملة</option>
            <option value="both">تجزئة وجملة</option>
          </select>
        </div>

        {/* Sub-bar with chips & sort */}
        <div className="flex items-center justify-between flex-wrap gap-2 pt-2 border-t border-zinc-100 text-xs">
          <div className="flex items-center gap-2 flex-wrap">
            {selectedCat && (
              <label className="inline-flex items-center gap-1.5 px-2.5 py-1 bg-zinc-100 rounded-lg text-zinc-700 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={includeSub}
                  onChange={(e) => setIncludeSub(e.target.checked)}
                  className="rounded text-brand-600 focus:ring-brand-500"
                />
                <span>تضمين الأقسام الفرعية</span>
              </label>
            )}

            <button
              onClick={() => setFeaturedOnly(!featuredOnly)}
              className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-lg border transition-all ${
                featuredOnly
                  ? 'border-amber-400 bg-amber-50 text-amber-800 font-medium'
                  : 'border-zinc-200 text-zinc-600 hover:bg-zinc-50'
              }`}
            >
              <Star className={`w-3.5 h-3.5 ${featuredOnly ? 'fill-amber-400 text-amber-500' : ''}`} />
              <span>مميز فقط</span>
            </button>

            {hasActiveFilters && (
              <button
                onClick={clearAllFilters}
                className="text-xs text-brand-600 hover:text-brand-700 font-medium mr-2"
              >
                مسح كل الفلاتر
              </button>
            )}
          </div>

          <div className="flex items-center gap-2">
            <span className="text-zinc-400">ترتيب:</span>
            <select
              value={sortBy}
              onChange={(e) => setSortBy(e.target.value as any)}
              className="bg-transparent border-none text-zinc-700 font-medium focus:outline-none cursor-pointer"
            >
              <option value="newest">الأحدث أولاً</option>
              <option value="oldest">الأقدم أولاً</option>
              <option value="name">أبجديًا بالاسم</option>
            </select>
            <span className="text-zinc-400 mr-2">({filteredProducts.length} منتج)</span>
          </div>
        </div>
      </div>

      {/* Table / Results */}
      <div className="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
        {filteredProducts.length === 0 ? (
          <EmptyState
            title="لا توجد منتجات مطابقة"
            text={
              hasActiveFilters
                ? 'جرّب كلمة بحث أخرى أو أزل بعض الفلاتر المحددة.'
                : 'الكتالوج فارغ. أضف أول شنطة أو محفظة لتبدأ.'
            }
            action={
              hasActiveFilters ? (
                <Button variant="outline" size="sm" onClick={clearAllFilters}>
                  مسح البحث والفلاتر
                </Button>
              ) : (
                <Button
                  size="sm"
                  className="gap-2"
                  onClick={() => navigate('/admin/products/new')}
                >
                  <Plus className="w-4 h-4" />
                  <span>منتج جديد</span>
                </Button>
              )
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-right text-sm">
              <thead className="bg-zinc-50 border-b border-zinc-200 text-xs font-semibold text-zinc-600">
                <tr>
                  <th className="px-5 py-3.5">المنتج</th>
                  <th className="px-4 py-3.5">القسم / البراند</th>
                  <th className="px-4 py-3.5">الحالة</th>
                  <th className="px-4 py-3.5">قناة البيع</th>
                  <th className="px-4 py-3.5 text-center">المتغيرات</th>
                  <th className="px-4 py-3.5">سعر التجزئة</th>
                  <th className="px-4 py-3.5 text-center"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-200">
                {filteredProducts.map((p) => {
                  const cat = categories.find((c) => c.id === p.cat);
                  const brand = brands.find((b) => b.id === p.brand);
                  return (
                    <tr
                      key={p.id}
                      onClick={() => navigate(`/admin/products/${p.id}`)}
                      className="hover:bg-zinc-50/80 cursor-pointer transition-colors"
                    >
                      <td className="px-5 py-3.5">
                        <div className="flex items-center gap-3">
                          <div className="w-10 h-10 rounded-xl bg-zinc-100 border border-zinc-200 flex items-center justify-center shrink-0 overflow-hidden">
                            {p.img ? (
                              <img src={p.img} alt="" className="w-full h-full object-cover" />
                            ) : (
                              <ImageIcon className="w-5 h-5 text-zinc-400" />
                            )}
                          </div>
                          <div>
                            <div className="font-bold text-zinc-900 flex items-center gap-1.5">
                              <span>{p.ar}</span>
                              {p.featured && (
                                <Star className="w-3.5 h-3.5 fill-amber-400 text-amber-500" />
                              )}
                            </div>
                            <span className="text-xs font-mono text-zinc-400 ltr">{p.code}</span>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3.5">
                        <div className="text-xs">
                          <span className="font-medium text-zinc-800">{cat?.ar || '—'}</span>
                          <span className="text-zinc-400 block text-[11px]">{brand?.en || '—'}</span>
                        </div>
                      </td>
                      <td className="px-4 py-3.5">
                        <span
                          className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                            statusTone[p.status] === 'ok'
                              ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                              : statusTone[p.status] === 'warn'
                              ? 'bg-amber-50 text-amber-700 border border-amber-200'
                              : 'bg-zinc-100 text-zinc-600'
                          }`}
                        >
                          {statusLabels[p.status]}
                        </span>
                      </td>
                      <td className="px-4 py-3.5 text-xs text-zinc-700 font-medium">
                        {channelLabels[p.channel] || p.channel}
                      </td>
                      <td className="px-4 py-3.5 text-center text-xs font-mono font-medium text-zinc-700">
                        {p.variantsCount}
                      </td>
                      <td className="px-4 py-3.5">
                        {p.price && p.channel !== 'wholesale' ? (
                          <span className="font-semibold text-zinc-900 text-xs">
                            {p.price[0] === p.price[1]
                              ? formatCurrency(p.price[0])
                              : `${formatCurrency(p.price[0])} – ${formatCurrency(p.price[1])}`}
                          </span>
                        ) : (
                          <span className="text-zinc-400 text-xs">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3.5 text-center" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            onClick={() => navigate(`/admin/products/${p.id}`)}
                            className="p-1.5 rounded-lg text-zinc-400 hover:text-brand-600 hover:bg-zinc-100 transition-colors"
                            title="تعديل المنتج"
                          >
                            <Edit className="w-4 h-4" />
                          </button>
                          <button
                            onClick={() => setDeleteTarget(p)}
                            className="p-1.5 rounded-lg text-zinc-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                            title="حذف المنتج"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Delete Confirmation Modal */}
      <ConfirmModal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title={`حذف منتج «${deleteTarget?.ar}»؟`}
        text="سيتم حذف المنتج وجميع متغيراته نهائيًا. لا يمكن التراجع عن هذا الإجراء."
        confirmLabel="حذف نهائي"
        onConfirm={() => {
          if (deleteTarget) {
            deleteProduct(deleteTarget.id);
            setDeleteTarget(null);
          }
        }}
      />
    </div>
  );
}

import React, { useState, useMemo } from 'react';
import { PageHeader } from '../../components/admin/PageHeader';
import { Drawer } from '../../components/admin/Drawer';
import { ConfirmModal } from '../../components/admin/ConfirmModal';
import { SearchSelect } from '../../components/admin/SearchSelect';
import { Field, Toggle } from '../../components/admin/FormFields';
import { Button, Badge } from '@alfarid/ui';
import {
  useAdminDataStore,
  Category,
  catPath,
  depthOf,
  subtreeHeight,
  isDescendant,
  totalCategoryProducts,
  slugify,
} from '../../stores/admin-data.store';
import {
  Folder,
  FolderTree,
  ChevronDown,
  ChevronLeft,
  Plus,
  Edit,
  Trash2,
  GripVertical,
  Search,
  MoveRight,
} from 'lucide-react';

const MAX_DEPTH = 3;

export function CategoriesPage() {
  const { categories, addCategory, updateCategory, deleteCategory } = useAdminDataStore();

  const [query, setQuery] = useState('');
  const [openNodes, setOpenNodes] = useState<Record<number, boolean>>({
    1: true,
    3: true,
    7: true,
    8: true,
  });
  const [selectedId, setSelectedId] = useState<number | null>(2);

  // Drawer form state
  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const [editingCategory, setEditingCategory] = useState<Category | null>(null);
  const [formParent, setFormParent] = useState<number | null>(null);
  const [formData, setFormData] = useState({
    ar: '',
    en: '',
    slug: '',
    dar: '',
    den: '',
    order: 1,
    active: true,
  });

  // Delete modal state
  const [deleteTarget, setDeleteTarget] = useState<Category | null>(null);

  const selectedCategory = categories.find((c) => c.id === selectedId);

  const toggleNode = (id: number) => {
    setOpenNodes((prev) => ({ ...prev, [id]: !prev[id] }));
  };

  const openDrawerForNew = (parentId: number | null = null) => {
    setEditingCategory(null);
    setFormParent(parentId);
    setFormData({
      ar: '',
      en: '',
      slug: '',
      dar: '',
      den: '',
      order: 1,
      active: true,
    });
    setIsDrawerOpen(true);
  };

  const openDrawerForEdit = (cat: Category) => {
    setEditingCategory(cat);
    setFormParent(cat.parent);
    setFormData({
      ar: cat.ar,
      en: cat.en,
      slug: cat.slug,
      dar: cat.dar || '',
      den: cat.den || '',
      order: cat.order,
      active: cat.active,
    });
    setIsDrawerOpen(true);
  };

  const handleSaveCategory = () => {
    if (!formData.ar || !formData.en) return;
    const slug = formData.slug || slugify(formData.en);

    if (editingCategory) {
      updateCategory(editingCategory.id, {
        ar: formData.ar,
        en: formData.en,
        slug,
        parent: formParent,
        order: Number(formData.order),
        active: formData.active,
        dar: formData.dar,
        den: formData.den,
      });
    } else {
      const created = addCategory({
        ar: formData.ar,
        en: formData.en,
        slug,
        parent: formParent,
        order: Number(formData.order),
        active: formData.active,
        dar: formData.dar,
        den: formData.den,
      });
      setSelectedId(created.id);
    }
    setIsDrawerOpen(false);
  };

  // Move Category helper
  const handleMoveCategory = (targetParentId: number | null) => {
    if (!selectedCategory) return;
    updateCategory(selectedCategory.id, { parent: targetParentId });
    if (targetParentId) {
      setOpenNodes((prev) => ({ ...prev, [targetParentId]: true }));
    }
  };

  // Render tree recursively
  const renderTree = (parentId: number | null, level: number) => {
    const nodes = categories
      .filter((c) => c.parent === parentId)
      .sort((a, b) => a.order - b.order);

    return nodes.map((node) => {
      const kids = categories.filter((k) => k.parent === node.id);
      const hasKids = kids.length > 0;
      const isOpen = openNodes[node.id];
      const isSelected = node.id === selectedId;
      const matches =
        !query || `${node.ar} ${node.en}`.toLowerCase().includes(query.toLowerCase());

      return (
        <React.Fragment key={node.id}>
          {(matches || hasKids) && (
            <div
              onClick={() => setSelectedId(node.id)}
              style={{ paddingRight: `${(level - 1) * 24}px` }}
              className={`group flex items-center justify-between p-2.5 rounded-xl cursor-pointer transition-all ${
                isSelected
                  ? 'bg-brand-50 border border-brand-300 text-brand-900 shadow-sm'
                  : 'hover:bg-zinc-50 border border-transparent'
              } ${!node.active ? 'opacity-50' : ''}`}
            >
              <div className="flex items-center gap-2.5 min-w-0">
                <GripVertical className="w-3.5 h-3.5 text-zinc-300 group-hover:text-zinc-500 cursor-grab" />
                {hasKids ? (
                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      toggleNode(node.id);
                    }}
                    className="p-1 rounded hover:bg-zinc-200 text-zinc-500 transition-colors"
                  >
                    <ChevronDown
                      className={`w-3.5 h-3.5 transition-transform ${isOpen ? '' : 'rotate-90'}`}
                    />
                  </button>
                ) : (
                  <span className="w-5" />
                )}

                <div className="w-7 h-7 rounded-lg bg-zinc-100 flex items-center justify-center text-zinc-500 shrink-0">
                  {level === 1 ? <Folder className="w-4 h-4" /> : <FolderTree className="w-3.5 h-3.5" />}
                </div>

                <div className="truncate">
                  <span className="font-bold text-xs text-zinc-900">{node.ar}</span>
                  <span className="text-[11px] text-zinc-400 mr-1.5 ltr font-sans">{node.en}</span>
                </div>
              </div>

              <div className="flex items-center gap-2 shrink-0">
                <span className="text-[10px] font-mono bg-zinc-100 text-zinc-600 px-1.5 py-0.5 rounded">
                  L{level}
                </span>
                {!node.active && <Badge variant="warning">معطل</Badge>}
                <span className="text-xs text-zinc-400 font-medium">
                  {totalCategoryProducts(categories, node.id)} منتج
                </span>

                <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                  {level < MAX_DEPTH && (
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        openDrawerForNew(node.id);
                      }}
                      className="p-1 rounded text-zinc-400 hover:text-brand-600 hover:bg-zinc-100"
                      title="إضافة قسم فرعي"
                    >
                      <Plus className="w-3.5 h-3.5" />
                    </button>
                  )}
                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      openDrawerForEdit(node);
                    }}
                    className="p-1 rounded text-zinc-400 hover:text-brand-600 hover:bg-zinc-100"
                    title="تعديل"
                  >
                    <Edit className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      setDeleteTarget(node);
                    }}
                    className="p-1 rounded text-zinc-400 hover:text-red-600 hover:bg-red-50"
                    title="حذف"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            </div>
          )}

          {(isOpen || query) && renderTree(node.id, level + 1)}
        </React.Fragment>
      );
    });
  };

  // Rejection check for delete
  const hasSubCategories = deleteTarget
    ? categories.some((c) => c.parent === deleteTarget.id)
    : false;
  const hasProducts = deleteTarget
    ? totalCategoryProducts(categories, deleteTarget.id) > 0
    : false;

  return (
    <div>
      <PageHeader
        crumbs={[['الرئيسية', '/admin'], ['الكتالوج'], ['الأقسام']]}
        light="شجرة"
        bold="الأقسام والتصنيفات"
        sub="حتى ٣ مستويات هرمية. اسحب لإعادة الترتيب أو استخدم خيار «نقل إلى» لنقل القسم بالكامل."
        actions={
          <Button
            size="sm"
            className="gap-2"
            onClick={() => openDrawerForNew(null)}
          >
            <Plus className="w-4 h-4" />
            <span>قسم رئيسي جديد</span>
          </Button>
        }
      />

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
        {/* Left Column: Tree */}
        <div className="md:col-span-2 bg-white border border-zinc-200 rounded-2xl p-5 shadow-sm space-y-4">
          <div className="flex items-center justify-between gap-3 border-b border-zinc-100 pb-3">
            <div className="relative flex-1">
              <Search className="w-4 h-4 text-zinc-400 absolute right-3 top-2.5 pointer-events-none" />
              <input
                type="text"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="ابحث في الأقسام..."
                className="w-full h-9 pr-9 pl-3 rounded-xl border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
              />
            </div>
            <div className="flex items-center gap-1.5 text-xs">
              <button
                onClick={() =>
                  setOpenNodes(Object.fromEntries(categories.map((c) => [c.id, true])))
                }
                className="px-2.5 py-1 text-zinc-600 hover:text-zinc-900 font-medium"
              >
                توسيع الكل
              </button>
              <button
                onClick={() => setOpenNodes({})}
                className="px-2.5 py-1 text-zinc-600 hover:text-zinc-900 font-medium"
              >
                طي الكل
              </button>
            </div>
          </div>

          <div className="space-y-1">{renderTree(null, 1)}</div>
        </div>

        {/* Right Column: Selected Category Inspector */}
        <div className="bg-white border border-zinc-200 rounded-2xl p-5 shadow-sm space-y-4 sticky top-24">
          {selectedCategory ? (
            <>
              <div className="border-b border-zinc-100 pb-3">
                <div className="text-xs text-zinc-400 font-medium">القسم المحدد</div>
                <h3 className="text-base font-bold text-zinc-900 mt-0.5">{selectedCategory.ar}</h3>
                <span className="text-xs text-zinc-400 font-sans ltr block">
                  {selectedCategory.en}
                </span>
              </div>

              <div className="space-y-2 text-xs">
                <div className="flex justify-between py-1 border-b border-zinc-50">
                  <span className="text-zinc-400">المسار الكامل:</span>
                  <span className="font-medium text-zinc-800">
                    {catPath(categories, selectedCategory.id)
                      .map((c) => c.ar)
                      .join(' / ')}
                  </span>
                </div>
                <div className="flex justify-between py-1 border-b border-zinc-50">
                  <span className="text-zinc-400">المستوى الهرمي:</span>
                  <span className="font-bold text-zinc-800">
                    L{depthOf(categories, selectedCategory.id)} من {MAX_DEPTH}
                  </span>
                </div>
                <div className="flex justify-between py-1 border-b border-zinc-50">
                  <span className="text-zinc-400">المنتجات المرتبطة:</span>
                  <span className="font-bold text-brand-600">
                    {totalCategoryProducts(categories, selectedCategory.id)} منتج
                  </span>
                </div>
              </div>

              {/* Move to parent option */}
              <div className="pt-2">
                <Field label="نقل إلى قسم أب آخر:" help="الخيارات غير المسموحة معطلة تلقائياً.">
                  <SearchSelect
                    value={selectedCategory.parent}
                    onChange={handleMoveCategory}
                    options={[
                      {
                        value: null,
                        label: '— قسم رئيسي (Root) —',
                        disabled: subtreeHeight(categories, selectedCategory.id) > MAX_DEPTH,
                        why:
                          subtreeHeight(categories, selectedCategory.id) > MAX_DEPTH
                            ? 'أقسامه الفرعية تتجاوز ٣ مستويات'
                            : null,
                      },
                      ...categories
                        .filter((c) => c.id !== selectedCategory.id)
                        .map((c) => {
                          const isChild = isDescendant(categories, selectedCategory.id, c.id);
                          const depthExceeded =
                            depthOf(categories, c.id) +
                              subtreeHeight(categories, selectedCategory.id) >
                            MAX_DEPTH;
                          const why = isChild
                            ? 'قسم فرعي منه'
                            : depthExceeded
                            ? 'يتجاوز ٣ مستويات'
                            : null;
                          return {
                            value: c.id,
                            label: catPath(categories, c.id)
                              .map((x) => x.ar)
                              .join(' / '),
                            disabled: Boolean(why),
                            why,
                          };
                        }),
                    ]}
                  />
                </Field>
              </div>

              <div className="pt-2">
                <Toggle
                  checked={selectedCategory.active}
                  onChange={(active) => updateCategory(selectedCategory.id, { active })}
                  label="القسم نشط في المتجر"
                />
              </div>

              <div className="pt-4 border-t border-zinc-100 flex items-center gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  className="flex-1 gap-1"
                  onClick={() => openDrawerForEdit(selectedCategory)}
                >
                  <Edit className="w-3.5 h-3.5" />
                  <span>تعديل</span>
                </Button>
                {depthOf(categories, selectedCategory.id) < MAX_DEPTH && (
                  <Button
                    size="sm"
                    className="flex-1 gap-1"
                    onClick={() => openDrawerForNew(selectedCategory.id)}
                  >
                    <Plus className="w-3.5 h-3.5" />
                    <span>قسم فرعي</span>
                  </Button>
                )}
              </div>
            </>
          ) : (
            <div className="text-center py-12 text-zinc-400 text-xs">
              اختر قسماً من الشجرة لعرض تفاصيله أو نقله
            </div>
          )}
        </div>
      </div>

      {/* Category Create/Edit Drawer */}
      <Drawer
        open={isDrawerOpen}
        onClose={() => setIsDrawerOpen(false)}
        title={editingCategory ? 'تعديل قسم' : 'إضافة قسم جديد'}
        crumbs={
          formParent ? (
            <span>
              داخل {categories.find((c) => c.id === formParent)?.ar}
            </span>
          ) : (
            <span>قسم رئيسي</span>
          )
        }
        footer={
          <div className="flex items-center gap-2 w-full justify-end">
            <Button variant="outline" size="sm" onClick={() => setIsDrawerOpen(false)}>
              إلغاء
            </Button>
            <Button size="sm" onClick={handleSaveCategory}>
              حفظ القسم
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <Field label="الاسم العربي" req>
            <input
              type="text"
              value={formData.ar}
              onChange={(e) => setFormData({ ...formData, ar: e.target.value })}
              placeholder="مثال: حقائب كروس"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <Field label="الاسم الإنجليزي" req>
            <input
              type="text"
              dir="ltr"
              value={formData.en}
              onChange={(e) => setFormData({ ...formData, en: e.target.value })}
              placeholder="Crossbody Bags"
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <Field label="القسم الأب" opt help="الحد الأقصى هو ٣ مستويات">
            <SearchSelect
              value={formParent}
              onChange={setFormParent}
              options={[
                { value: null, label: '— قسم رئيسي (Root) —' },
                ...categories
                  .filter((c) => !editingCategory || c.id !== editingCategory.id)
                  .map((c) => ({
                    value: c.id,
                    label: catPath(categories, c.id)
                      .map((x) => x.ar)
                      .join(' / '),
                    disabled: depthOf(categories, c.id) >= MAX_DEPTH,
                    why: depthOf(categories, c.id) >= MAX_DEPTH ? 'المستوى الثالث' : null,
                  })),
              ]}
            />
          </Field>

          <Field label="الرابط (Slug)" opt help="يتولد تلقائياً من الاسم الإنجليزي عند تركه فارغاً.">
            <input
              type="text"
              dir="ltr"
              value={formData.slug}
              onChange={(e) => setFormData({ ...formData, slug: e.target.value })}
              placeholder={slugify(formData.en || 'category-slug')}
              className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs font-mono focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <Field label="الوصف بالعربية" opt>
            <textarea
              rows={2}
              value={formData.dar}
              onChange={(e) => setFormData({ ...formData, dar: e.target.value })}
              className="w-full p-2.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
            />
          </Field>

          <div className="grid grid-cols-2 gap-4">
            <Field label="ترتيب الظهور">
              <input
                type="number"
                value={formData.order}
                onChange={(e) => setFormData({ ...formData, order: Number(e.target.value) })}
                className="w-full h-9 px-3 rounded-lg border border-zinc-300 text-xs font-mono"
              />
            </Field>
            <div className="pt-6">
              <Toggle
                checked={formData.active}
                onChange={(active) => setFormData({ ...formData, active })}
                label="القسم نشط"
              />
            </div>
          </div>
        </div>
      </Drawer>

      {/* Delete Confirmation Modal */}
      <ConfirmModal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title={`حذف قسم «${deleteTarget?.ar}»؟`}
        text="سيتم حذف القسم نهائياً."
        rejectWith={
          hasSubCategories ? (
            <span>
              القسم يحتوي على أقسام فرعية مرتبطة به. برجاء نقلها أو حذفها أولاً.
            </span>
          ) : hasProducts ? (
            <span>
              يوجد منتجات مرتبطة بهذا القسم. برجاء نقل المنتجات لقسم آخر أولاً قبل الحذف.
            </span>
          ) : null
        }
        confirmLabel="حذف القسم"
        onConfirm={() => {
          if (deleteTarget) {
            deleteCategory(deleteTarget.id);
            setDeleteTarget(null);
            if (selectedId === deleteTarget.id) setSelectedId(null);
          }
        }}
      />
    </div>
  );
}

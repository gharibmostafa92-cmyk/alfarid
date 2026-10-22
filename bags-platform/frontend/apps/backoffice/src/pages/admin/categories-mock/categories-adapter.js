import { IMG } from './categories.mock';
import { toLatinDigits } from './i18n-utils';

// API → شكل الـ UI (نفس أسماء الـ mock عشان الكومبوننتس متتغيرش)
export function toUiNode(c, rootIndex = 0) {
  return {
    id: c.id,
    parentId: c.parent_id,
    ar: c.name_ar ?? '',
    en: c.name_en ?? '',
    slug: c.slug,
    desc_ar: c.description_ar ?? '',
    desc_en: c.description_en ?? '',
    order: c.sort_order ?? 0,
    status: c.is_active ? 'active' : 'inactive',
    products: c.products_count ?? 0,
    imageUrl: c.image_url,
    // الـ mock بيستخدم img كـ CSS background → نحافظ على نفس الشكل
    img: c.image_url
      ? `url("${c.image_url}") center / cover no-repeat`
      : (c.parent_id === null ? IMG[rootIndex % IMG.length] : undefined),
    createdAt: c.created_at,
    children: (c.children ?? []).map((ch) => toUiNode(ch)),
  };
}

export const toUiTree = (list) => list.map((c, i) => toUiNode(c, i));

// UI form → payload للـ API
export function toPayload(v, { isEdit } = {}) {
  const p = {
    name_ar: v.ar.trim(),
    name_en: v.en.trim(),
    parent_id: v.parentId ?? null,
    slug: v.slug?.trim() ? v.slug.trim().toLowerCase() : null, // فاضي → الباك يولّده
    description_ar: v.desc_ar?.trim() || null,
    description_en: v.desc_en?.trim() || null,
    sort_order: Number(toLatinDigits(v.order ?? 0)) || 0,
    is_active: v.status === 'active',
  };
  if (v.imageFile) p.image = v.imageFile;
  if (isEdit && v.imageRemoved && !v.imageFile) p.remove_image = true;
  return p;
}

// Normalize positions only in sibling groups that actually moved. Existing sort
// values may be sparse; comparing indexes alone can insert a moved node first.
export function buildReorderItems(tree, savedTree) {
  const groups = (nodes, out = new Map(), parentId = null) => {
    out.set(parentId, nodes);
    nodes.forEach((n) => groups(n.children || [], out, n.id));
    return out;
  };
  const before = groups(savedTree);
  const original = new Map();
  before.forEach((nodes, parentId) => nodes.forEach((n) => original.set(n.id, { parentId, order: n.order })));
  const items = [];
  groups(tree).forEach((nodes, parentId) => {
    const previous = before.get(parentId) || [];
    if (nodes.length === previous.length && nodes.every((n, i) => n.id === previous[i].id)) return;
    nodes.forEach((n, index) => {
      const old = original.get(n.id);
      if (!old || old.parentId !== parentId || old.order !== index) {
        items.push({ id: n.id, parent_id: parentId, sort_order: index });
      }
    });
  });
  return items;
}

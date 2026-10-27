import type { StoreCategory, StoreProductCard } from '@alfarid/api-client';
import type { SophieProduct } from '@/components/sophie/SophieProductCard';
import { pick } from './catalog-mappers';
import { STORE_ROUTES } from './store-routes';

export function toProfileVM(user: Record<string, any> | null, destination: string | null) {
  const name = (user?.name as string) || 'My account';
  const initials = name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
  return {
    name, initials,
    email: (user?.email as string) || (user?.phone as string) || '',
    role: destination === 'wholesale' ? 'Wholesale account' : 'Member',
    avatar: null,
    walletBalance: user?.wallet_balance ?? null,
    menuItems: [
      { id: 'profile', label: 'My profile', icon: 'fa-regular fa-user', type: 'item' },
      { id: 'orders', label: 'My orders', icon: 'fa-solid fa-box', type: 'item' },
      { id: 'wallet', label: 'Wallet', icon: 'fa-solid fa-wallet', type: 'item' },
      { id: 'returns', label: 'Returns', icon: 'fa-solid fa-rotate-left', type: 'item' },
      { id: 'div-1', type: 'divider' },
      { id: 'signout', label: 'Sign out', icon: 'fa-solid fa-arrow-from-bracket', isSignOut: true, type: 'item' },
    ],
  };
}

const num = (value: unknown) => value === null || value === undefined || value === '' ? NaN : Number(value);

export function cardToSophie(card: StoreProductCard, mode: 'new' | 'sale' | 'auto'): SophieProduct | null {
  const price = num(card.price);
  if (!Number.isFinite(price)) return null;
  const oldPrice = num(card.old_price);
  let badge: string | undefined;
  let badgeTone: SophieProduct['badgeTone'];
  if (!card.in_stock) { badge = 'SOLD OUT'; badgeTone = 'muted'; }
  else if (card.discount_percentage) { badge = `-${Math.round(card.discount_percentage)}%`; badgeTone = 'sale'; }
  else if (mode === 'new') { badge = 'NEW'; badgeTone = 'default'; }
  return {
    id: card.id, variantId: card.default_variant_id ?? undefined, slug: card.slug, name: card.name_en?.trim() || card.name_ar,
    price, oldPrice: Number.isFinite(oldPrice) && oldPrice > price ? oldPrice : undefined,
    badge, badgeTone, imgUrl: card.image || null,
    category: card.category?.name_en || card.category?.name_ar,
    href: STORE_ROUTES.product(card.slug), inStock: card.in_stock,
  };
}

export const mapCards = (cards: StoreProductCard[] | undefined, mode: 'new' | 'sale' | 'auto') =>
  (cards ?? []).map((card) => cardToSophie(card, mode)).filter((card): card is SophieProduct => card !== null);

export type MegaLink = { label: string; slug: string; count: number };
export type MegaGroup = { title: string; slug: string; links: MegaLink[] };
export type MegaDepartment = {
  name: string; slug: string; image: string | null;
  col1: MegaGroup[]; col2: MegaGroup[]; col3: MegaGroup[];
  href: string;
};

function splitBalanced(groups: MegaGroup[], n = 3): MegaGroup[][] {
  const cols: MegaGroup[][] = Array.from({ length: n }, () => []);
  const weight = Array(n).fill(0);
  groups.forEach((g) => {
    const i = weight.indexOf(Math.min(...weight));
    cols[i].push(g);
    weight[i] += 1 + g.links.length;
  });
  return cols;
}

/** Backend category tree -> mega-menu departments. Empty (zero-product) branches are hidden. */
export function toMegaDepartments(tree: StoreCategory[]) {
  const out: Record<string, MegaDepartment> = {};
  tree.filter((r) => (r.products_count ?? 0) > 0).forEach((root) => {
    const groups: MegaGroup[] = (root.children ?? [])
      .filter((c) => (c.products_count ?? 0) > 0)
      .map((c) => {
        const kids = (c.children ?? []).filter((g) => (g.products_count ?? 0) > 0);
        return {
          title: pick(c.name_en, c.name_ar), slug: c.slug,
          links: kids.length
            ? kids.map((g) => ({ label: pick(g.name_en, g.name_ar), slug: g.slug, count: g.products_count ?? 0 }))
            : [{ label: `All ${pick(c.name_en, c.name_ar)}`, slug: c.slug, count: c.products_count ?? 0 }],
        };
      });
    if (!groups.length) groups.push({ title: pick(root.name_en, root.name_ar), slug: root.slug,
      links: [{ label: `All ${pick(root.name_en, root.name_ar)}`, slug: root.slug, count: root.products_count ?? 0 }] });
    const [col1, col2, col3] = splitBalanced(groups);
    out[root.slug] = {
      name: pick(root.name_en, root.name_ar), slug: root.slug, image: root.image,
      col1, col2, col3,
      href: STORE_ROUTES.shop({ category: root.slug }),
    };
  });
  return out;
}

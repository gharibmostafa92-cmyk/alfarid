import type { StoreCategory, StoreProductCard } from '@alfarid/api-client';
import type { SophieProduct } from '@/components/sophie/SophieProductCard';
import { pick } from './catalog-mappers';
import { STORE_ROUTES } from './store-routes';

/**
 * Builds user profile view model for storefront account drawer and navigation header.
 *
 * @param user Authenticated user record or nullish guest state.
 * @param destination User intent/channel context ('wholesale' or standard storefront).
 * @return View model with initials, display role, and contextual profile navigation links.
 */
export function toProfileVM(user: Record<string, any> | null, destination: string | null) {
  const name = (user?.name as string) || 'My account';
  // Extract up to 2 initials for the circular avatar badge when no profile picture exists.
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

/**
 * Maps API product card payload into storefront theme presentation model.
 *
 * @param card API product card data.
 * @param mode Display mode badge behavior ('new', 'sale', or 'auto').
 * @return Normalized SophieProduct card, or null if price data is invalid.
 */
export function cardToSophie(card: StoreProductCard, mode: 'new' | 'sale' | 'auto'): SophieProduct | null {
  const price = num(card.price);
  if (!Number.isFinite(price)) return null;
  const oldPrice = num(card.old_price);
  let badge: string | undefined;
  let badgeTone: SophieProduct['badgeTone'];
  // Stock depletion takes precedence over promotional discount and new-arrival badges.
  if (!card.in_stock) { badge = 'SOLD OUT'; badgeTone = 'muted'; }
  else if (card.discount_percentage) { badge = `-${Math.round(card.discount_percentage)}%`; badgeTone = 'sale'; }
  else if (mode === 'new') { badge = 'NEW'; badgeTone = 'default'; }
  return {
    id: card.id, variantId: card.default_variant_id ?? undefined, slug: card.slug, name: pick(card.name_en, card.name_ar),
    price, oldPrice: Number.isFinite(oldPrice) && oldPrice > price ? oldPrice : undefined,
    badge, badgeTone, imgUrl: card.image || null,
    category: pick(card.category?.name_en, card.category?.name_ar),
    href: STORE_ROUTES.product(card.slug), inStock: card.in_stock,
  };
}

/**
 * Batch maps product cards, filtering out unpriceable or invalid items.
 *
 * @param cards List of API product cards.
 * @param mode Display mode badge behavior.
 * @return List of valid SophieProduct presentation items.
 */
export const mapCards = (cards: StoreProductCard[] | undefined, mode: 'new' | 'sale' | 'auto') =>
  (cards ?? []).map((card) => cardToSophie(card, mode)).filter((card): card is SophieProduct => card !== null);

export type MegaLink = { label: string; slug: string; count: number };
export type MegaGroup = { title: string; slug: string; links: MegaLink[] };
export type MegaDepartment = {
  name: string; slug: string; image: string | null;
  col1: MegaGroup[]; col2: MegaGroup[]; col3: MegaGroup[];
  href: string;
};

/**
 * Greedily distributes link groups across columns to minimize visual height disparity in mega menu dropdowns.
 */
function splitBalanced(groups: MegaGroup[], n = 3): MegaGroup[][] {
  const cols: MegaGroup[][] = Array.from({ length: n }, () => []);
  const weight = Array(n).fill(0);
  groups.forEach((g) => {
    // Greedily append group to the shortest column by cumulative link count + title weight.
    const i = weight.indexOf(Math.min(...weight));
    cols[i].push(g);
    weight[i] += 1 + g.links.length;
  });
  return cols;
}

/**
 * Transforms backend category hierarchy into 3-column mega-menu departments, omitting empty branches.
 *
 * @param tree Category hierarchy from API.
 * @return Department dictionary keyed by root category slug.
 */
export function toMegaDepartments(tree: StoreCategory[]) {
  const out: Record<string, MegaDepartment> = {};
  // Exclude empty categories to prevent dead-end navigation links on the storefront.
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

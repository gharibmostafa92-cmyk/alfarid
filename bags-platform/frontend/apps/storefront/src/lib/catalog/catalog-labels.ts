export const OCCASION_LABELS = { casual: 'Everyday Casual', work: 'Work & Commute', weekend: 'Weekend Getaways', evening: 'Evening Glamour' } as const;
export const SORT_OPTIONS = [
  { value: 'featured', label: 'Featured' }, { value: 'newest', label: 'New Arrivals' },
  { value: 'best_selling', label: 'Best Selling' }, { value: 'price_asc', label: 'Price: Low to High' },
  { value: 'price_desc', label: 'Price: High to Low' }, { value: 'name', label: 'Name A–Z' },
] as const;
export const DEFAULT_HERO = { light: 'CURATED', bold: 'COLLECTIONS',
  desc: 'Explore our signature assortment of premium backpacks, totes, crossbodies, and artisanal leather goods tailored for modern elegance.',
  img: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=500&q=80' };

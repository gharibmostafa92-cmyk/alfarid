export interface ShowcaseProduct {
  brand: string;
  subtitle: string;
  price: string;
  sku: string;
  img: string;
  tint: string;
  desc: string;
  secondaryImg: string;
  secondaryTint: string;
  gallery: Array<{ url: string; tint: string }>;
}

export interface GridCatalogItem {
  brand: string;
  subtitle: string;
  price: string;
  badge: string;
  img: string;
  tint: string;
  slideIndex: number;
}

export const SHOWCASE_PRODUCTS: ShowcaseProduct[] = [
  {
    brand: 'SOPHIE ATELIER',
    subtitle: "Vogue Elegance Women's Backpack",
    price: '£85.00',
    sku: 'SP-2026-VG',
    img: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=900&q=85',
    tint: 'tint-pink',
    desc: 'Exquisitely tailored from supple grain faux calfskin, the Vogue Elegance seamlessly balances poised aesthetics with modern practicality. Features padded ergonomic shoulder straps, a protective padded tablet sleeve, and rose-gold signature hardware.',
    secondaryImg: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=900&q=85',
    secondaryTint: 'tint-black',
    gallery: [
      { url: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=900&q=85', tint: 'tint-pink' },
      { url: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=900&q=85', tint: 'tint-beige' },
      { url: 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?auto=format&fit=crop&w=900&q=85', tint: 'tint-black' },
    ],
  },
  {
    brand: 'SOPHIE MAISON',
    subtitle: 'Saffiano Structured Handbag',
    price: '£145.00',
    sku: 'SP-2026-SF',
    img: 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?auto=format&fit=crop&w=900&q=85',
    tint: 'tint-black',
    desc: 'An emblem of executive poise. Built from scratch-resistant Saffiano finish leather with protective base studs, reinforced dual carry handles, and an organized triple-compartment interior designed for boardroom to evening transitions.',
    secondaryImg: 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?auto=format&fit=crop&w=900&q=85',
    secondaryTint: 'tint-pink',
    gallery: [
      { url: 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?auto=format&fit=crop&w=900&q=85', tint: 'tint-black' },
      { url: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=900&q=85', tint: 'tint-cream' },
      { url: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=900&q=85', tint: 'tint-pink' },
    ],
  },
  {
    brand: 'SOPHIE STUDIO',
    subtitle: 'Travel Lite Anti-Theft Backpack',
    price: '£80.00',
    sku: 'SP-2026-TL',
    img: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=900&q=85',
    tint: 'tint-beige',
    desc: 'Engineered for mindful globetrotters. Featuring slash-proof reinforced weave canvas, concealed RFID security pockets, interlocking zipper pulls, and a built-in trolley pass-through strap for effortless airport navigation.',
    secondaryImg: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=900&q=85',
    secondaryTint: 'tint-cream',
    gallery: [
      { url: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=900&q=85', tint: 'tint-beige' },
      { url: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=900&q=85', tint: 'tint-blue' },
      { url: 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?auto=format&fit=crop&w=900&q=85', tint: 'tint-cream' },
    ],
  },
];

export const GRID_CATALOG: GridCatalogItem[] = [
  {
    brand: 'SOPHIE ATELIER',
    subtitle: "Vogue Elegance Women's Backpack",
    price: '£85.00',
    badge: 'NEW',
    img: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=700&q=80',
    tint: 'tint-pink',
    slideIndex: 0,
  },
  {
    brand: 'SOPHIE MAISON',
    subtitle: 'Saffiano Structured Handbag',
    price: '£145.00',
    badge: 'LUXE',
    img: 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?auto=format&fit=crop&w=700&q=80',
    tint: 'tint-black',
    slideIndex: 1,
  },
  {
    brand: 'SOPHIE STUDIO',
    subtitle: 'Travel Lite Anti-Theft Backpack',
    price: '£80.00',
    badge: '-20%',
    img: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=700&q=80',
    tint: 'tint-beige',
    slideIndex: 2,
  },
  {
    brand: 'SOPHIE HERITAGE',
    subtitle: 'Classic Fashion Backpack',
    price: '£100.00',
    badge: 'NEW',
    img: 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?auto=format&fit=crop&w=700&q=80',
    tint: 'tint-pink',
    slideIndex: 0,
  },
  {
    brand: 'SOPHIE LUXE',
    subtitle: 'Parisian Leather Crossbody Bag',
    price: '£115.00',
    badge: 'TRENDING',
    img: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=700&q=80',
    tint: 'tint-cream',
    slideIndex: 2,
  },
  {
    brand: 'SOPHIE VOYAGE',
    subtitle: 'Executive Weekender Duffel Tote',
    price: '£160.00',
    badge: 'EXCLUSIVE',
    img: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=700&q=80',
    tint: 'tint-blue',
    slideIndex: 1,
  },
];

export const SIDEBAR_CATEGORIES = ['BACKPACKS', 'HANDBAGS', 'CROSSBODY', 'TOTES', 'CLUTCHES'] as const;

export const SIDEBAR_SUBCATEGORIES = [
  'Signature Leather',
  'Urban Laptop Commute',
  'Anti-Theft Travel',
  'Mini Saffiano Satchel',
  'Velvet Evening Series',
] as const;

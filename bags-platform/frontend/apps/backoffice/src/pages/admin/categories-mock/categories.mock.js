// Mock fixtures copied from the supplied categories design.
export const IMG = [
  'linear-gradient(135deg,#f6d5c8 0%,#e7825f 100%)',
  'linear-gradient(135deg,#e9e4ef 0%,#a99cb8 100%)',
  'linear-gradient(135deg,#ece6dc 0%,#c2ae8c 100%)',
  'linear-gradient(135deg,#dfe8e3 0%,#89a698 100%)',
  'linear-gradient(135deg,#f3e3d3 0%,#d49c6b 100%)',
  'linear-gradient(135deg,#e4e7ee 0%,#8b95ab 100%)',
  'linear-gradient(135deg,#2a2530 0%,#5c5363 100%)',
  'linear-gradient(135deg,#f7ece6 0%,#efc0aa 100%)',
];

export const CATEGORIES = [
  { id: 1, ar: 'غرف المعيشة', en: 'Living Room', slug: 'living-room', status: 'active', products: 0, img: IMG[0], children: [
    { id: 11, ar: 'كنب وأنتريهات', en: 'Sofas', slug: 'sofas', status: 'active', products: 48, children: [
      { id: 111, ar: 'كنب زاوية', en: 'Corner Sofas', slug: 'corner-sofas', status: 'active', products: 17, children: [] },
      { id: 112, ar: 'كنب سرير', en: 'Sofa Beds', slug: 'sofa-beds', status: 'active', products: 9, children: [] },
    ] },
    { id: 12, ar: 'طاولات قهوة', en: 'Coffee Tables', slug: 'coffee-tables', status: 'active', products: 22, children: [] },
    { id: 13, ar: 'وحدات تلفزيون', en: 'TV Units', slug: 'tv-units', status: 'inactive', products: 0, children: [] },
  ] },
  { id: 2, ar: 'غرف النوم', en: 'Bedroom', slug: 'bedroom', status: 'active', products: 0, img: IMG[1], children: [
    { id: 21, ar: 'أسرّة', en: 'Beds', slug: 'beds', status: 'active', products: 31, children: [] },
    { id: 22, ar: 'دواليب', en: 'Wardrobes', slug: 'wardrobes', status: 'active', products: 14, children: [] },
    { id: 23, ar: 'مراتب', en: 'Mattresses', slug: 'mattresses', status: 'active', products: 12, children: [
      { id: 231, ar: 'مراتب طبية', en: 'Orthopedic', slug: 'orthopedic', status: 'active', products: 6, children: [] },
    ] },
  ] },
  { id: 3, ar: 'الإضاءة', en: 'Lighting', slug: 'lighting', status: 'active', products: 0, img: IMG[2], children: [
    { id: 31, ar: 'نجف', en: 'Chandeliers', slug: 'chandeliers', status: 'active', products: 19, children: [] },
    { id: 32, ar: 'أباجورات', en: 'Table Lamps', slug: 'table-lamps', status: 'active', products: 27, children: [] },
  ] },
  { id: 4, ar: 'المطبخ والسفرة', en: 'Kitchen & Dining', slug: 'kitchen-dining', status: 'active', products: 0, img: IMG[3], children: [
    { id: 41, ar: 'سفرة', en: 'Dining Sets', slug: 'dining-sets', status: 'active', products: 11, children: [] },
  ] },
  { id: 5, ar: 'ديكورات', en: 'Decor', slug: 'decor', status: 'inactive', products: 0, img: IMG[4], children: [] },
];


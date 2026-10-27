export * from './inventory';

export interface ApiResponse<T = any> {
  success?: boolean;
  message?: string;
  data: T;
  meta?: {
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
    from?: number;
    to?: number;
  };
}

export interface PaginatedResponse<T = any> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number;
  to?: number;
}

export interface ApiErrorResponse {
  message: string;
  errors?: Record<string, string[]>;
  status_code?: number;
}

export interface UserPermissions {
  id: number;
  name: string;
  email: string;
  roles: string[];
  permissions: string[];
}

export interface AdminCategory {
  id: number;
  parent_id: number | null;
  name_ar: string;
  name_en: string;
  slug: string;
  description_ar: string | null;
  description_en: string | null;
  sort_order: number;
  is_active: boolean;
  image_url: string | null;
  products_count?: number;      // مباشر فقط (مش شامل الأقسام الفرعية)
  children?: AdminCategory[];
  parent?: AdminCategory;
  created_at: string;
}

export interface CategoryPayload {
  parent_id?: number | null;
  name_ar?: string;
  name_en?: string;
  slug?: string | null;
  description_ar?: string | null;
  description_en?: string | null;
  sort_order?: number;
  is_active?: boolean;
  image?: File | null;          // ملف جديد فقط
  remove_image?: boolean;       // في التعديل فقط
}

export interface CategoryReorderItem {
  id: number;
  parent_id: number | null;
  sort_order: number;
}

// Laravel Resource::collection(paginate()) shape: { data, links, meta }
export interface ResourceCollection<T> {
  data: T[];
  links?: Record<string, string | null>;
  meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
}

export type ProductStatus = 'draft' | 'published' | 'archived';
export type SalesChannel = 'retail' | 'wholesale' | 'both';

export interface AdminBrand {
  id: number; name_ar: string; name_en: string; slug: string;
  description_ar: string | null; description_en: string | null;
  sort_order: number; is_active: boolean; logo_url: string | null;
  products_count?: number; created_at: string;
}

export interface AdminMedia {
  id: number; collection: 'images' | 'video' | 'logo'; url: string;
  thumb_url: string | null; medium_url: string | null;
  mime_type: string; size: number; order: number; variant_id: number | null;
}

export interface AdminVariant {
  lock_version?: number;
  id: number; sku: string; barcode: string | null;
  color: { name_ar: string | null; name_en: string | null; hex: string | null };
  color_name_ar?: string | null; color_name_en?: string | null; color_hex?: string | null;
  size: string | null; capacity: string | null;
  cost_price?: string | null;          // بيختفي من غير products.view_cost
  retail_price: string | null; wholesale_price: string | null; compare_at_price: string | null;
  reorder_level: number | null; weight_kg: string | null; sort_order: number; is_active: boolean;
  media?: AdminMedia[];
  images?: AdminMedia[];
}

export interface AdminProduct {
  lock_version?: number;
  id: number; code: string; name_ar: string; name_en: string; slug: string;
  occasions: StoreOccasion[] | null; badge_en: string | null; badge_ar: string | null;
  description_ar: string | null; description_en: string | null;
  material_ar: string | null; material_en: string | null;
  dimensions: { length_cm: string | null; width_cm: string | null; height_cm: string | null };
  weight_kg: string | null; warranty_months: number | null; warranty_details: string | null;
  country_of_origin: string | null; status: ProductStatus; sales_channel: SalesChannel;
  is_featured: boolean; published_at: string | null;
  category?: AdminCategory; brand?: AdminBrand | null; supplier_id: number | null;
  main_image: string | null; images?: AdminMedia[]; video?: AdminMedia | null;
  variants_count?: number; min_retail_price?: string | null; max_retail_price?: string | null;
  variants?: AdminVariant[]; created_at: string; updated_at: string;
}

// نفس حقول الكتابة اللي في Store/Update Requests
export interface BrandPayload {
  name_ar?: string; name_en?: string; slug?: string | null;
  description_ar?: string | null; description_en?: string | null;
  sort_order?: number; is_active?: boolean;
  logo?: File | null;                 // ملف جديد فقط
  remove_logo?: boolean;              // في التعديل فقط
}

export interface ProductPayload {
  expected_version?: number;
  expected_updated_at?: string;
  category_id?: number; brand_id?: number | null; supplier_id?: number | null;
  create_key?: string; // F9: idempotency key for product creation
  name_ar?: string; name_en?: string; slug?: string | null;
  description_ar?: string | null; description_en?: string | null;
  material_ar?: string | null; material_en?: string | null;
  length_cm?: string | number | null; width_cm?: string | number | null; height_cm?: string | number | null;
  weight_kg?: string | number | null; warranty_months?: number | null; warranty_details?: string | null;
  country_of_origin?: string | null; sales_channel?: SalesChannel; is_featured?: boolean;
}

export type StoreBannerPosition = 'main_slider' | 'middle_banner' | 'popup';
export interface PublicBranding {
  name: string;
  logo: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
}

export interface StoreCart {
  id: number | null;
  cart_token: string | null;
  customer_id: number | null;
  items: Array<{
    id: number; product_variant_id: number; product_id: number; product_name: string | null; sku: string;
    qty: number; unit_price: number | null; base_price: number | null; line_discount: number; line_total: number;
    available: number; max_qty: number; priced: boolean;
    product_slug: string | null; product_name_en: string | null; image: string | null;
    color: string | null; size: string | null;
  }>;
  totals: { subtotal: number; pricing_discount: number; items_total: number; coupon_discount: number; total: number; coupon_code: string | null };
}

export interface StoreCity { id: number; name_ar: string; name_en: string | null; shipping_cost: string | number }

export type CheckoutPaymentMethod = 'cod' | 'wallet' | 'credit';

export interface CheckoutPayload {
  recipient_name?: string; recipient_phone?: string; recipient_email?: string;
  city_id: number; shipping_address_line: string; shipping_notes?: string;
  expected_total?: number; payment_method: CheckoutPaymentMethod;
}

export interface StoreOrderItem {
  id: number; product_variant_id: number; product_name: string; sku: string;
  qty: number; unit_price: number | string; discount_amount: number | string; line_total: number | string;
}

export interface StoreOrder {
  id: number; order_number: string; channel: string; status: string;
  payment_method: string; payment_status: string;
  recipient_name: string | null; recipient_phone: string | null;
  city_id: number | null; shipping_address_line: string | null;
  subtotal: number | string; discount_amount: number | string; coupon_code: string | null;
  shipping_cost: number | string; cod_fee: number | string; tax_amount: number | string; grand_total: number | string;
  items: StoreOrderItem[]; created_at: string;
}

export interface CheckoutResponse { data: StoreOrder; payment_url: string | null; message: string }

export interface ShippingOption { key: string; label: string; cost: number; cod_fee: number; currency: string; estimated_days: string | null }

export interface ShippingQuotePreview {
  items_total: number; coupon_discount: number; shipping: number;
  cod_fee: number; tax: number; grand_total: number;
}

export interface ShippingOptionsResponse {
  city: { id: number; name_ar: string; name_en: string | null };
  cart_total: number;
  payment_methods: string[];
  preview: ShippingQuotePreview | null;
  options: ShippingOption[];
}

export interface CartCouponResponse { cart: StoreCart; coupon_discount: number }

export interface TrackOrderResponse {
  order_number: string; status: string;
  items: Array<{ product_name: string; qty: number }>;
  status_history: Array<{ status: string; at: string }>;
  created_at: string;
}
export type StoreBannerLinkType = 'product' | 'category' | 'brand' | 'external' | 'none';

export interface StoreBanner {
  id: number;
  title: string;
  image: string;
  position: StoreBannerPosition;
  link_type: StoreBannerLinkType;
  link_value: string | null;
  href: string | null;
  is_external: boolean;
  kicker: string | null;
  headline: string | null;
  headline_bold: string | null;
  description: string | null;
  cta_label: string | null;
  bg_color: string | null;
}

export type StoreOccasion = 'casual' | 'work' | 'weekend' | 'evening';
export type StoreSort = 'featured' | 'newest' | 'best_selling' | 'price_asc' | 'price_desc' | 'name';

export interface StoreImage { id: number; url: string; thumb: string | null }
export interface StoreColor { name_en: string | null; name_ar: string | null; hex: string | null }

export interface StoreCategory {
  id: number; name_ar: string; name_en: string | null; slug: string;
  description_ar: string | null; description_en: string | null;
  image: string | null; direct_products_count?: number; products_count: number;
  children: StoreCategory[];
}

export interface StoreProductCard {
  id: number;
  name_ar: string;
  name_en: string | null;
  slug: string;
  image: string | null;
  brand: null;
  category?: { id: number; name_ar: string; name_en: string | null; slug: string } | null;
  price: number | string | null;
  default_variant_id?: number | null;
  variant_count?: number;
  old_price: number | string | null;
  discount_percentage: number | null;
  is_featured: boolean;
  /** F19: removed from the API payload; kept optional for stale caches. */
  sales_count?: number;
  in_stock: boolean;
  is_new?: boolean; badge_en?: string | null; badge_ar?: string | null; colors?: StoreColor[];
}

export interface StoreProductVariant {
  id: number; sku: string;
  color_name_ar: string | null; color_name_en: string | null; color_hex: string | null;
  size: string | null; capacity: string | null;
  images: StoreImage[];
  price: number | string | null; old_price: number | string | null; in_stock: boolean;
  max_qty: number;
}

export interface StoreProduct extends StoreProductCard {
  description_ar: string | null; description_en: string | null;
  material_ar: string | null; material_en: string | null;
  warranty_months: number | null; warranty_details?: string | null; country_of_origin: string | null;
  occasions: StoreOccasion[];
  dimensions?: { length_cm: number | null; width_cm: number | null; height_cm: number | null; weight_kg: number | null };
  images: StoreImage[]; variants: StoreProductVariant[];
}

export interface StoreProductListParams {
  category?: string; color?: string; price_min?: number; price_max?: number;
  occasions?: StoreOccasion[]; in_stock?: 0 | 1; search?: string;
  sort?: StoreSort; page?: number; per_page?: number;
}

export interface StoreCatalogFilters {
  total: number;
  colors: Array<{ id: string; name_en: string; name_ar: string | null; hex: string | null; count: number }>;
  price_ranges: Array<{ id: string; min: number | null; max: number | null; count: number }>;
  occasions: Array<{ id: StoreOccasion; count: number }>;
}

export interface StoreHomeData {
  banners: StoreBanner[];
  latest_products: StoreProductCard[];
  best_sellers: StoreProductCard[];
  discounted_products: StoreProductCard[];
}

export interface VariantPayload {
  expected_version?: number;
  sku?: string; barcode?: string | null;
  color_name_ar?: string | null; color_name_en?: string | null; color_hex?: string | null;
  size?: string | null; capacity?: string | null;
  cost_price?: string | number | null; retail_price?: string | number | null;
  wholesale_price?: string | number | null; compare_at_price?: string | number | null;
  reorder_level?: number | null; weight_kg?: string | number | null;
  sort_order?: number | null; is_active?: boolean;
}

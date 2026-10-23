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
  id: number; code: string; name_ar: string; name_en: string; slug: string;
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
  category_id?: number; brand_id?: number | null; supplier_id?: number | null;
  name_ar?: string; name_en?: string; slug?: string | null;
  description_ar?: string | null; description_en?: string | null;
  material_ar?: string | null; material_en?: string | null;
  length_cm?: string | number | null; width_cm?: string | number | null; height_cm?: string | number | null;
  weight_kg?: string | number | null; warranty_months?: number | null; warranty_details?: string | null;
  country_of_origin?: string | null; sales_channel?: SalesChannel; is_featured?: boolean;
}

export interface VariantPayload {
  sku?: string; barcode?: string | null;
  color_name_ar?: string | null; color_name_en?: string | null; color_hex?: string | null;
  size?: string | null; capacity?: string | null;
  cost_price?: string | number | null; retail_price?: string | number | null;
  wholesale_price?: string | number | null; compare_at_price?: string | number | null;
  reorder_level?: number | null; weight_kg?: string | number | null;
  sort_order?: number | null; is_active?: boolean;
}

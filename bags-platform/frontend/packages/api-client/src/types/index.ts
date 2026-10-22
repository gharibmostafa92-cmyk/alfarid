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

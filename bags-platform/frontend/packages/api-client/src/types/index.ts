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

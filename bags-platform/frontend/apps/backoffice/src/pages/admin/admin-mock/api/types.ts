export interface Paginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
}

export interface ApiRoleRef { id: number; name: string }

export interface ApiUser {
  version?: number;
  id: number;
  name: string;
  email: string;
  phone: string | null;
  branch_id: number | null;
  branch?: { id: number; name: string } | null;
  is_active: boolean;
  roles?: ApiRoleRef[];
  permissions?: string[];
  created_at: string;
  updated_at: string;
}

export interface ApiRole {
  version?: number;
  is_builtin?: boolean;
  id: number;
  name: string;
  guard_name: 'staff';
  permissions?: string[];
  users_count?: number;
  is_system?: boolean;
  created_at: string;
  updated_at: string;
}

export type ApiPermissionGroups = Record<string, { id: number; name: string }[]>;

export interface ApiBranch { id: number; name: string; code: string; is_active: boolean }

export type ApiSettingsGrouped = Record<string, Record<string, string | number | boolean | null>>;

export interface UserListParams {
  page?: number;
  per_page?: number;
  search?: string;
  role?: string;                 // اسم الدور، مش الـ ID
  branch_id?: number | 'none';   // 'none' = من غير فرع
  is_active?: boolean;
}

export interface CreateUserBody {
  name: string; email: string; phone?: string | null; password: string;
  branch_id?: number | null; is_active?: boolean; roles?: string[];
}

export type UpdateUserBody = Partial<Omit<CreateUserBody, 'password'>> & { password?: string; current_password?: string; expected_version?: number };

export interface RoleBody { expected_version?: number; name?: string; permissions?: string[] }
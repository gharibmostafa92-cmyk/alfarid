import { defaultApiClient as api } from '@alfarid/api-client';
import type { ApiBranch, ApiPermissionGroups, ApiRole, ApiSettingsGrouped, ApiUser,
  CreateUserBody, Paginated, RoleBody, UpdateUserBody, UserListParams } from './types';

const BASE = '/api/v1/admin';

function userParams(p: UserListParams): Record<string, string | number> {
  const out: Record<string, string | number> = { page: p.page ?? 1, per_page: p.per_page ?? 8 };
  if (p.search?.trim()) out.search = p.search.trim();
  if (p.role) out.role = p.role;
  // axios بيشيل أي null، فبنبعت '' عشان Laravel يحوّله null ويفلتر على "من غير فرع"
  if (p.branch_id === 'none') out.branch_id = '';
  else if (typeof p.branch_id === 'number') out.branch_id = p.branch_id;
  if (typeof p.is_active === 'boolean') out.is_active = p.is_active ? 1 : 0;
  return out;
}

export const adminApi = {
  // Users
  listUsers: (p: UserListParams, signal?: AbortSignal) =>
    api.get<Paginated<ApiUser>>(`${BASE}/users`, { params: userParams(p), signal }).then(r => r.data),
  getUser: (id: number, signal?: AbortSignal) =>
    api.get<{ data: ApiUser }>(`${BASE}/users/${id}`, { signal }).then(r => r.data.data),
  createUser: (body: CreateUserBody) =>
    api.post<{ data: ApiUser }>(`${BASE}/users`, body).then(r => r.data.data),
  updateUser: (id: number, body: UpdateUserBody) =>
    api.patch<{ data: ApiUser }>(`${BASE}/users/${id}`, body).then(r => r.data.data),
  deactivateUser: (id: number, version: number) =>
    api.delete<{ message?: string }>(`${BASE}/users/${id}`, {data:{expected_version:version}}).then(r => r.data),
  countUsers: (p: Omit<UserListParams, 'page' | 'per_page'>, signal?: AbortSignal) =>
    api.get<Paginated<ApiUser>>(`${BASE}/users`, { params: { ...userParams(p), per_page: 1 }, signal })
      .then(r => r.data.meta.total),

  // Roles (بنلف على كل الصفحات)
  listRoles: async (signal?: AbortSignal): Promise<ApiRole[]> => {
    const all: ApiRole[] = [];
    let page = 1, last = 1;
    do {
      const r = await api.get<Paginated<ApiRole>>(`${BASE}/roles`, { params: { per_page: 100, page }, signal });
      all.push(...r.data.data);
      last = r.data.meta.last_page;
      page++;
    } while (page <= last);
    return all;
  },
  createRole: (body: Required<Pick<RoleBody, 'name'>> & RoleBody) =>
    api.post<{ data: ApiRole }>(`${BASE}/roles`, body).then(r => r.data.data),
  updateRole: (id: number, body: RoleBody) =>
    api.patch<{ data: ApiRole }>(`${BASE}/roles/${id}`, body).then(r => r.data.data),
  deleteRole: (id: number) => api.delete(`${BASE}/roles/${id}`),

  // Permissions
  listPermissions: (signal?: AbortSignal) =>
    api.get<{ data: ApiPermissionGroups }>(`${BASE}/permissions`, { signal }).then(r => r.data.data),

  // Branches
  listBranches: async (signal?: AbortSignal): Promise<ApiBranch[]> => {
    const branches: ApiBranch[] = [];
    let page = 1, last = 1;
    do {
      const response = await api.get<Paginated<ApiBranch>>(`${BASE}/branches`, {params:{per_page:100,page},signal});
      branches.push(...response.data.data); last = response.data.meta.last_page; page++;
    } while (page <= last);
    return branches;
  },

  // Settings
  getSettings: (signal?: AbortSignal) =>
    api.get<{ data: ApiSettingsGrouped }>(`${BASE}/settings`, { signal }).then(r => r.data.data),
  updateSettings: (patch: Record<string, unknown>) =>
    api.put<{ data: ApiSettingsGrouped; warnings?: string[] }>(`${BASE}/settings`, patch).then(r => r.data),
};
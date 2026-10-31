import type { StaffUserResource } from '@alfarid/api-client';
import type { StaffUser } from './auth.store';

export const toStaffUser = (raw: unknown): StaffUser => {
  const u = (raw && typeof raw === 'object' && 'data' in raw) ? (raw as { data: StaffUserResource }).data : (raw as StaffUserResource);
  if (!u || typeof u !== 'object') {
    return { id: 0, name: '', email: '', roles: [], permissions: [] };
  }

  return {
    id: u.id ?? 0,
    name: u.name ?? '',
    email: u.email ?? '',
    roles: Array.isArray(u.roles) ? u.roles.map((r: unknown) => (typeof r === 'string' ? r : (r as { name: string })?.name ?? '')) : [],
    permissions: Array.isArray(u.permissions) ? u.permissions : [],
    branch_id: u.branch_id ?? null,
  };
};

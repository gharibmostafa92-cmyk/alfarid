import { useApi } from '../catalog/useApi';
import { normalizeError, type ApiError } from '../catalog/errors';
import { adminApi } from './api/endpoints';
import { toAdminRole, toAdminSettings } from './api/mappers';
import { buildPermissionGroups } from './api/permission-catalog';
import { useAuthStore } from '../../../stores/auth.store';

export const useRoles = (enabled = true) => useApi(s => enabled ? adminApi.listRoles(s).then(rs => rs.map(toAdminRole)) : Promise.resolve([]), [enabled]);
export const usePermissionGroups = () => useApi(s => adminApi.listPermissions(s).then(buildPermissionGroups), []);
export const useBranches = () => useApi(s => adminApi.listBranches(s).catch(e => {
  if (normalizeError(e).status === 403) return [];   // مفيش صلاحية فروع → قائمة فاضية
  throw e;
}), []);
export const useSettingsQuery = () => useApi(s => adminApi.getSettings(s).then(toAdminSettings), []);

export function useMe() {
  const user = useAuthStore(s => s.user);
  return {
    me: user,
    meId: user?.id,
    centralAccess: !!user?.roles?.includes('Super Admin') || !!user?.permissions?.includes('inventory.all_branches'),
    iAmSuper: !!user?.roles?.includes('Super Admin'),
    myPerms: new Set(user?.permissions ?? []),
  };
}

/** helper لتنفيذ الـ mutations بنفس الشكل في كل مكان */
export async function runMutation<T>(fn: () => Promise<T>): Promise<{ ok: true; data: T } | { ok: false; error: ApiError }> {
  try { return { ok: true, data: await fn() }; }
  catch (e) { return { ok: false, error: normalizeError(e) }; }
}
export { useApi };
import { useEffect, useState } from 'react';
import { toast } from './ui';
export function useDebounced<T>(value: T, delay = 300) {
 const [debounced, setDebounced] = useState(value);
 useEffect(() => { const timer = setTimeout(() => setDebounced(value), delay); return () => clearTimeout(timer); }, [value, delay]);
 return debounced;
}
export function mapFieldErrors(fields: Record<string, string[]>) {
 const out: Record<string,string> = {};
 Object.entries(fields).forEach(([key, errors]) => { out[key.split('.')[0]] ??= errors[0]; });
 return out;
}
export function useQueryError(error?: ApiError) {
 useEffect(() => { if (error && error.status !== 401) toast(error.message, 'err'); }, [error]);
}
